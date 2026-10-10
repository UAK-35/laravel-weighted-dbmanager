<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * Outcome of a PgcatConfigFlipper operation.
 *
 * Variant-style: one of noChange / flipped / skipped / failed / windowClosed.
 * Carries enough metadata for the CLI to render a useful line and for
 * log aggregators to tag the event with `kind`.
 */
final class FlipResult
{
    public const KIND_NO_CHANGE = 'no_change';
    public const KIND_FLIPPED   = 'flipped';
    public const KIND_SKIPPED   = 'skipped';
    public const KIND_FAILED    = 'failed';

    /**
     * The container's boot window closed without the flip reaching a usable mode, so the
     * flipper has stopped trying until the container is replaced. See {@see FlipWindow}.
     *
     * This is a decision rather than a command failure — the run did exactly what it should
     * have — so it exits 0. The failure is the *container*, and `/health/db` is where it is
     * reported; a scheduler that logged a non-zero exit every minute forever would bury the
     * one line that says why.
     */
    public const KIND_WINDOW_CLOSED = 'window_closed';

    /**
     * A running pooler was not answering for a pool it is supposed to serve, so its
     * configuration was re-applied.
     *
     * This is the second self-heal, and the one supervisord cannot see: a program can be
     * RUNNING and still hold a configuration with no pool for a database the application is
     * connected to. It exits 0 for the same reason a flip does: the run did what it was asked
     * to, and the state it leaves behind is `/health/db`'s to report rather than a run's.
     *
     * There are two runs that reach it. One found the mode already applied, where the repair
     * is a re-application rather than a transition — the mode never changed. The other has just
     * *applied* a mode and read the pooler back, and the pooler did not pick it up: there the
     * mode did move, and the repair is a restart on the file that was written, because a signal
     * pgcat did not act on needs a process that reads the file to happen at all.
     */
    public const KIND_REPAIRED = 'repaired';

    private function __construct(
        private readonly string $kind,
        public readonly ?string $mode,
        public readonly ?string $previousMode,
        public readonly string $reason,
        public readonly ?string $error,
    ) {
    }

    public static function noChange(string $mode, string $reason = ''): self
    {
        return new self(self::KIND_NO_CHANGE, $mode, null, $reason, null);
    }

    public static function flipped(string $newMode, ?string $previousMode): self
    {
        return new self(self::KIND_FLIPPED, $newMode, $previousMode, 'state transitioned', null);
    }

    public static function skipped(string $currentMode, string $reason): self
    {
        return new self(self::KIND_SKIPPED, $currentMode, null, $reason, null);
    }

    public static function failed(string $currentMode, string $error): self
    {
        return new self(self::KIND_FAILED, $currentMode, null, 'exception during flip', $error);
    }

    /**
     * The window closed: nothing was read, written, restarted or signalled.
     *
     * `$mode` is the mode that was in place, which is the honest answer to "where is the
     * pooler" — a closed window does not mean the pooler is down, only that this container
     * is no longer allowed to change its mind about it.
     */
    public static function windowClosed(string $currentMode, string $reason): self
    {
        return new self(self::KIND_WINDOW_CLOSED, $currentMode, null, $reason, null);
    }

    /**
     * The pooler was running but not answering for a pool, so its configuration was applied
     * again. The mode is the one in place when the run ends — the mode that was already applied,
     * or the one a flip has just applied and the pooler did not pick up — and `$reason` names the
     * pool the pooler could not answer for and what the run did about it.
     */
    public static function repaired(string $mode, string $reason): self
    {
        return new self(self::KIND_REPAIRED, $mode, null, $reason, null);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function exitCode(): int
    {
        return match ($this->kind) {
            self::KIND_FAILED  => 1,
            self::KIND_FLIPPED,
            self::KIND_NO_CHANGE,
            self::KIND_SKIPPED,
            self::KIND_REPAIRED,
            self::KIND_WINDOW_CLOSED => 0,
            default => 1,
        };
    }

    public function summary(): string
    {
        return match ($this->kind) {
            self::KIND_NO_CHANGE => sprintf('no change (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_FLIPPED   => sprintf(
                'flipped: %s → %s',
                $this->previousMode ?? 'never',
                $this->mode ?? '?',
            ),
            self::KIND_SKIPPED   => sprintf('skipped (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_REPAIRED  => sprintf('repaired (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_WINDOW_CLOSED => sprintf('window closed (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_FAILED    => sprintf(
                'failed (mode=%s): %s — error: %s',
                $this->mode,
                $this->reason,
                $this->error ?? '(none)',
            ),
            default => sprintf('%s (mode=%s): %s', $this->kind, $this->mode, $this->reason),
        };
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'kind'          => $this->kind,
            'mode'          => $this->mode,
            'previous_mode' => $this->previousMode,
            'reason'        => $this->reason,
            'error'         => $this->error,
        ];
    }
}

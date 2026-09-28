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

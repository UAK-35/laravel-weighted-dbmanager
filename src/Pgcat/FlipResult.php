<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * Outcome of a PgcatConfigFlipper operation.
 *
 * Variant-style: one of noChange / flipped / skipped / failed / windowClosed / repaired /
 * readersDeferred. Carries enough metadata for the CLI to render a useful line and for log
 * aggregators to tag the event with `kind`.
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
     * Three runs reach it, and the two after the first are the same question asked of a mode that
     * has just moved. One found the mode already applied, where the repair is a re-application
     * rather than a transition — the mode never changed. One has just *applied* a mode on the
     * scheduled path and read the pooler back, and the pooler did not pick it up. The third is
     * the forced path — the reader-window tasks at a boundary, `db:pgcat-flip --force-mode` by
     * hand — which has applied a mode for the same reason and asked the same read-back question.
     * In the two that moved the mode the repair is a restart on the file that was written, because
     * a signal pgcat did not act on needs a process that reads the file to happen at all.
     */
    public const KIND_REPAIRED = 'repaired';

    /**
     * The readers mode was asked for, its variant declares a dedicated reader this container cannot
     * reach, and the writer-only variant was put in front of pgcat instead.
     *
     * This is the safe-degradation outcome step 4 of the pgcat promotion plan asks for: reader
     * loss preserves service because reads fall back to the writer, and the pooler is never
     * pointed at a source whose server it cannot resolve. It is deliberately distinct from
     * `skipped` - that is a refusal, while this is a *transition that was deferred* and left a
     * validated fallback serving - and from `flipped`, because the readers mode was not applied.
     * `mode` is therefore the mode actually in place (`writer`), never the requested one.
     *
     * It exits 0: the run did what it could safely do, and the standing degradation is reported
     * by `/health/db`'s `reader_window` block (a deferred transition, not a stale flip) rather
     * than by a non-zero code on a schedule that will keep retrying.
     */
    public const KIND_READERS_DEFERRED = 'readers_deferred';

    /**
     * The stable machine codes `cause` carries when the kind alone is not the whole answer. A
     * `VariantShape::PROBLEM_*` code is used verbatim for a target the shape reading refused; these
     * are the causes a run or a rehearsal decides itself.
     *
     * They are part of the JSON contract (`pgcat-flip-json.md`), so they are a closed vocabulary: a
     * gate branches on them, and a new spelling is a new thing a gate has to learn.
     */
    public const CAUSE_LOCK_HELD = 'lock_held';

    public const CAUSE_READER_ENDPOINT_UNRESOLVED = 'reader_endpoint_unresolved';

    public const CAUSE_WINDOW_CLOSED = 'window_closed';

    public const CAUSE_POOL_UNANSWERABLE = 'pool_unanswerable';

    public const CAUSE_EXCEPTION = 'exception';

    private function __construct(
        private readonly string $kind,
        public readonly ?string $mode,
        public readonly ?string $previousMode,
        public readonly string $reason,
        public readonly ?string $error,
        private readonly bool $fatal = false,
        private readonly bool $degraded = false,
        private readonly ?string $cause = null,
        /** @var array<string, mixed> */
        private readonly array $evidence = [],
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

    /**
     * A flip declined or refused. `$fatal` is the contract's line between the two: a lock another
     * instance holds is not fatal, while a target this container cannot serve is - a gate must not
     * promote a candidate whose intended topology the flip will not apply.
     *
     * @param array<string, mixed> $evidence
     */
    public static function skipped(
        string $currentMode,
        string $reason,
        bool $fatal = false,
        ?string $cause = null,
        array $evidence = [],
    ): self {
        return new self(self::KIND_SKIPPED, $currentMode, null, $reason, null, $fatal, false, $cause, $evidence);
    }

    public static function failed(string $currentMode, string $error): self
    {
        return new self(
            self::KIND_FAILED,
            $currentMode,
            null,
            'exception during flip',
            $error,
            true,
            false,
            self::CAUSE_EXCEPTION,
        );
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
        return new self(self::KIND_WINDOW_CLOSED, $currentMode, null, $reason, null, false, false, self::CAUSE_WINDOW_CLOSED);
    }

    /**
     * The pooler was running but not answering for a pool, so its configuration was applied
     * again. The mode is the one in place when the run ends — the mode that was already applied,
     * or the one a flip has just applied and the pooler did not pick up — and `$reason` names the
     * pool the pooler could not answer for and what the run did about it.
     */
    public static function repaired(string $mode, string $reason): self
    {
        return new self(self::KIND_REPAIRED, $mode, null, $reason, null, false, false, self::CAUSE_POOL_UNANSWERABLE);
    }

    /**
     * The readers mode was deferred: `$appliedMode` is the mode the pooler is actually on (the
     * writer-only fallback), never the requested one. See {@see KIND_READERS_DEFERRED}.
     *
     * `$pools` is the evidence a gate reads instead of the sentence: the reader pools that did not
     * resolve, with `fallback` naming the mode that is serving in their place.
     *
     * @param list<string> $pools
     */
    public static function readersDeferred(string $requestedMode, string $appliedMode, string $reason, array $pools = []): self
    {
        return new self(
            self::KIND_READERS_DEFERRED,
            $appliedMode,
            null,
            sprintf('requested "%s", deferred and left on "%s": %s', $requestedMode, $appliedMode, $reason),
            null,
            false,
            true,
            self::CAUSE_READER_ENDPOINT_UNRESOLVED,
            ['pools' => $pools, 'fallback' => $appliedMode],
        );
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
            self::KIND_READERS_DEFERRED,
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
            self::KIND_READERS_DEFERRED => sprintf('readers deferred (mode=%s): %s', $this->mode, $this->reason),
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
     * Whether the run is a refusal a gate must not promote past: `failed`, or a `skipped` whose
     * cause is an unservable target. See `pgcat-flip-json.md`.
     */
    public function fatal(): bool
    {
        return $this->fatal;
    }

    /**
     * Whether the run is a proven safe degradation - the readers mode deferred onto a validated
     * writer fallback, with `evidence['pools']` naming what could not be reached.
     */
    public function degraded(): bool
    {
        return $this->degraded;
    }

    /** The stable machine code behind the kind, or null when the kind is the whole answer. */
    public function cause(): ?string
    {
        return $this->cause;
    }

    /**
     * The structured evidence a gate reads instead of the sentence.
     *
     * @return array<string, mixed>
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind'          => $this->kind,
            'mode'          => $this->mode,
            'previous_mode' => $this->previousMode,
            'reason'        => $this->reason,
            'error'         => $this->error,
            'fatal'         => $this->fatal,
            'degraded'      => $this->degraded,
            'cause'         => $this->cause,
            'evidence'      => $this->evidence,
        ];
    }
}

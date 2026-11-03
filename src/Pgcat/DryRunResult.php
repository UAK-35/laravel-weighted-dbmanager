<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * What a flip would have done, step by step, without doing it.
 *
 * The kinds mirror a flip's — the mode would change, it would not, the lock is held, a step
 * that had to work did not — so a caller can read either result the same way, and
 * `exitCode()` means the same thing in both: non-zero when the flip could not happen, which
 * is what makes a rehearsal usable in a pipeline.
 *
 * What this carries and `FlipResult` does not is the *pipeline*: every step of a flip in the
 * order a flip takes them, whether the rehearsal performed it or would have, and the detail
 * an operator needs to act on it. `would_flip` is a statement about the whole run, and the
 * steps are the evidence for it.
 */
final class DryRunResult
{
    /** The mode would change, and every step a flip needs was proven to work. */
    public const KIND_WOULD_FLIP = 'would_flip';

    /**
     * A flip would ask for the readers mode and defer it: the readers variant declares a reader
     * this container cannot reach, so the writer-only fallback would stay in front of pgcat. See
     * {@see \Uak35\WeightedDbManager\Pgcat\FlipResult::KIND_READERS_DEFERRED}.
     *
     * Separate from `would_flip` because the mode a rehearsal names is the mode that would be
     * *applied*, and this run would not apply readers. It exits 0: deferring is the safe half of
     * the transition, not a failure of the rehearsal.
     */
    public const KIND_WOULD_DEFER_READERS = 'would_defer_readers';

    /** Nothing to do: the mode is unchanged, or the flipper is not armed for this driver. */
    public const KIND_WOULD_NOT_FLIP = 'would_not_flip';

    /** A flip would decline to run: another instance holds the lock. */
    public const KIND_SKIPPED = 'skipped';

    /** A step a flip cannot survive did not work, reported instead of thrown. */
    public const KIND_FAILED = 'failed';

    /** The rehearsal performed this step. */
    public const STEP_DONE = 'done';

    /** A flip would perform this step; the rehearsal did not, which is the point of it. */
    public const STEP_WOULD = 'would';

    /** The rehearsal never reached this step, because something before it decided the run. */
    public const STEP_REFUSED = 'refused';

    /** This step had to work for a flip to happen, and did not. */
    public const STEP_FAILED = 'failed';

    /**
     * The stable machine codes `cause` carries, part of the JSON contract in `pgcat-flip-json.md`.
     * A `VariantShape::PROBLEM_*` code is used verbatim when the refusal came from the shape.
     */
    public const CAUSE_NO_CHANGE = 'no_change';

    public const CAUSE_LOCK_HELD = FlipResult::CAUSE_LOCK_HELD;

    public const CAUSE_READER_ENDPOINT_UNRESOLVED = FlipResult::CAUSE_READER_ENDPOINT_UNRESOLVED;

    public const CAUSE_STEP_FAILED = 'step_failed';

    /**
     * @param string $kind one of the KIND_ constants
     * @param string|null $mode the mode a flip would apply
     * @param string|null $previousMode the mode on record, before it
     * @param string $reason why the rehearsal came to this verdict
     * @param string|null $error the step that failed, when kind is KIND_FAILED
     * @param list<array{step: string, outcome: string, detail: string}> $steps the pipeline, in a flip's order
     */
    private function __construct(
        private readonly string $kind,
        public readonly ?string $mode,
        public readonly ?string $previousMode,
        public readonly string $reason,
        public readonly ?string $error,
        public readonly array $steps,
        private readonly bool $fatal = false,
        private readonly bool $degraded = false,
        private readonly ?string $cause = null,
        /** @var array<string, mixed> */
        private readonly array $evidence = [],
    ) {
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     */
    public static function wouldFlip(string $mode, ?string $previousMode, array $steps): self
    {
        return new self(
            kind: self::KIND_WOULD_FLIP,
            mode: $mode,
            previousMode: $previousMode,
            reason: $previousMode === null
                ? 'the mode has never been applied, so a first flip would run'
                : 'the mode differs from the one on record',
            error: null,
            steps: $steps,
        );
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     * @param list<string> $pools the reader pools a deferred transition could not reach
     */
    public static function wouldDeferReaders(string $mode, ?string $previousMode, string $reason, array $steps, array $pools = []): self
    {
        return new self(
            self::KIND_WOULD_DEFER_READERS,
            $mode,
            $previousMode,
            $reason,
            null,
            $steps,
            false,
            true,
            self::CAUSE_READER_ENDPOINT_UNRESOLVED,
            ['pools' => $pools, 'fallback' => 'writer'],
        );
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     */
    public static function wouldNotFlip(string $mode, string $reason, array $steps): self
    {
        return new self(self::KIND_WOULD_NOT_FLIP, $mode, null, $reason, null, $steps, false, false, self::CAUSE_NO_CHANGE);
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     */
    public static function skipped(string $mode, string $reason, array $steps): self
    {
        return new self(self::KIND_SKIPPED, $mode, null, $reason, null, $steps, false, false, self::CAUSE_LOCK_HELD);
    }

    /**
     * A rehearsal a gate must not promote past. `$cause` is the stable machine code (a
     * `VariantShape::PROBLEM_*` for a target the shape refused, else {@see CAUSE_STEP_FAILED}).
     *
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     * @param array<string, mixed> $evidence
     */
    public static function failed(string $mode, string $error, array $steps, ?string $cause = null, array $evidence = []): self
    {
        return new self(
            self::KIND_FAILED,
            $mode,
            null,
            'a step a flip needs did not work',
            $error,
            $steps,
            true,
            false,
            $cause ?? self::CAUSE_STEP_FAILED,
            $evidence,
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * Whether a flip would happen at all — the question a caller asking "is this safe to
     * schedule" wants answered, and false for every kind but the one that says so.
     */
    public function wouldFlipHappen(): bool
    {
        return $this->kind === self::KIND_WOULD_FLIP;
    }

    /**
     * Non-zero when a flip could not happen: a rehearsal that finds a broken step is a
     * failure, and one that finds nothing to do is not.
     */
    public function exitCode(): int
    {
        return $this->kind === self::KIND_FAILED ? 1 : 0;
    }

    public function summary(): string
    {
        return match ($this->kind) {
            self::KIND_WOULD_FLIP => sprintf(
                'would flip: %s → %s (nothing was renamed, and pgcat was neither signalled nor restarted)',
                $this->previousMode ?? 'never',
                $this->mode ?? '?',
            ),
            self::KIND_WOULD_NOT_FLIP => sprintf('would not flip (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_WOULD_DEFER_READERS => sprintf('would defer readers (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_SKIPPED => sprintf('a flip would be skipped (mode=%s): %s', $this->mode, $this->reason),
            self::KIND_FAILED => sprintf('a flip would fail (mode=%s): %s', $this->mode, $this->error ?? '(none)'),
            default => sprintf('%s (mode=%s): %s', $this->kind, $this->mode, $this->reason),
        };
    }

    /**
     * The step with this name, or null when the rehearsal never got that far — so a caller
     * can ask what happened without walking the list, and a test can pin one step without
     * depending on the others' order.
     *
     * @return array{step: string, outcome: string, detail: string}|null
     */
    public function step(string $step): ?array
    {
        foreach ($this->steps as $entry) {
            if ($entry['step'] === $step) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Whether a gate must not promote past this rehearsal: the target is unservable, or a step a
     * flip needs did not work. `skipped` (a held lock) and `would_defer_readers` are not fatal.
     */
    public function fatal(): bool
    {
        return $this->fatal;
    }

    /**
     * Whether the rehearsal found a proven safe degradation - the readers mode would be deferred
     * onto a validated writer fallback, with `evidence['pools']` naming what could not be reached.
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
            'kind' => $this->kind,
            'mode' => $this->mode,
            'previous_mode' => $this->previousMode,
            'reason' => $this->reason,
            'error' => $this->error,
            'steps' => $this->steps,
            'fatal' => $this->fatal,
            'degraded' => $this->degraded,
            'cause' => $this->cause,
            'evidence' => $this->evidence,
        ];
    }
}

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
     */
    public static function wouldNotFlip(string $mode, string $reason, array $steps): self
    {
        return new self(self::KIND_WOULD_NOT_FLIP, $mode, null, $reason, null, $steps);
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     */
    public static function skipped(string $mode, string $reason, array $steps): self
    {
        return new self(self::KIND_SKIPPED, $mode, null, $reason, null, $steps);
    }

    /**
     * @param list<array{step: string, outcome: string, detail: string}> $steps
     */
    public static function failed(string $mode, string $error, array $steps): self
    {
        return new self(self::KIND_FAILED, $mode, null, 'a step a flip needs did not work', $error, $steps);
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
     * @return array{kind: string, mode: string|null, previous_mode: string|null, reason: string, error: string|null, steps: list<array{step: string, outcome: string, detail: string}>}
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
        ];
    }
}

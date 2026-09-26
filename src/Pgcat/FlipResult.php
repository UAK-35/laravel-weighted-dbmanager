<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * Outcome of a PgcatConfigFlipper operation.
 *
 * Variant-style: one of noChange / flipped / skipped / failed.
 * Carries enough metadata for the CLI to render a useful line and for
 * log aggregators to tag the event with `kind`.
 */
final class FlipResult
{
    public const KIND_NO_CHANGE = 'no_change';
    public const KIND_FLIPPED   = 'flipped';
    public const KIND_SKIPPED   = 'skipped';
    public const KIND_FAILED    = 'failed';

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
            self::KIND_SKIPPED => 0,
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

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * One setting that reads as on but cannot do what it claims.
 *
 * A finding is a claim about the configuration, not about the code: `warning` is
 * the sentence an operator reads at boot while the finding stands, and
 * `resolution` is the sentence the boot that finds it gone logs instead. Pairing
 * them here means the log can carry both halves of the story without either half
 * being reconstructed from the other.
 *
 * `key` is the identity a finding is remembered by, so it must be stable across
 * boots and describe one thing: two settings that can fail independently are two
 * keys, and a key whose meaning changes would resolve the wrong warning.
 *
 * `level` is how loudly the finding is logged. Most findings are warnings: the
 * setting is understood and used, and it simply describes nothing that can happen.
 * A finding with level `error` is a value the package *refuses* — malformed input it
 * will not interpret on the operator's behalf — which is a different claim and
 * deserves a different level. Resolutions are always logged as warnings, because
 * arriving at a working configuration is good news either way.
 */
final class BootAuditFinding
{
    /**
     * @param string $key a stable identifier, safe to grep for in a log
     * @param string $warning the sentence logged while the finding stands
     * @param string $resolution the sentence logged once it no longer applies
     * @param array<string, mixed> $context the specifics, logged with both sentences
     * @param string $level `warning` for a setting that cannot act, `error` for a value
     *        the package refuses to interpret
     */
    public function __construct(
        public readonly string $key,
        public readonly string $warning,
        public readonly string $resolution,
        public readonly array $context = [],
        public readonly string $level = 'warning',
    ) {
    }
}

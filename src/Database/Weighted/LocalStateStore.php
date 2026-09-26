<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

/**
 * In-process SWRR state store — used as a fallback when Redis is unavailable.
 *
 * PROPERTIES
 * ----------
 *   • Zero network dependency.
 *   • Two requests landing on different PHP-FPM workers will each maintain
 *     their own current_weights array. Cross-worker smoothness is lost
 *     (each worker ramps up from zero independently).
 *
 *   • Within a single request this is correct — every step advances the local
 *     state deterministically.
 *
 * Use RedisAtomicStateStore in production; LocalStateStore is for graceful
 * degradation only. Every transition onto it is logged by the manager, and
 * name() says "uncoordinated" out loud so db:replica-status and /health/db can
 * show that this process is no longer sharing a rotation with its siblings.
 */
final class LocalStateStore implements AtomicStateStore
{
    /**
     * Local fallback state keyed by pool key.
     *
     * @var array<string, int[]>
     */
    private array $state = [];

    private bool $healthy = true;

    /**
     * @param string $keyPrefix Matches swrr.key_prefix so local keys line up with
     *                          the primary store's.
     */
    public function __construct(private readonly string $keyPrefix = 'swrr')
    {
    }

    public function next(string $connectionName, array $replicaKeys, array $weights): int
    {
        $key = $this->poolKey($connectionName, $replicaKeys);
        $n = count($weights);

        if (!isset($this->state[$key]) || count($this->state[$key]) !== $n) {
            $this->state[$key] = Algorithm::initialState($n);
        }

        [$best, $newState] = Algorithm::step($this->state[$key], $weights);
        $this->state[$key] = $newState;

        return $best;
    }

    public function reset(string $connectionName, array $replicaKeys): void
    {
        unset($this->state[$this->poolKey($connectionName, $replicaKeys)]);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function name(): string
    {
        return 'local(in-process, uncoordinated)';
    }

    /**
     * Same key format as RedisAtomicStateStore — including the configured key
     * prefix — so reset() is symmetric and a custom SWRR_KEY_PREFIX applies to
     * both stores.
     *
     * @param string[] $replicaKeys
     */
    private function poolKey(string $connectionName, array $replicaKeys): string
    {
        $sorted = $replicaKeys;
        sort($sorted);

        return sprintf(
            '%s:%s:%s',
            $this->keyPrefix,
            $connectionName,
            substr(md5(implode(',', $sorted)), 0, 12),
        );
    }
}

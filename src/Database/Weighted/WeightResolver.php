<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * Resolve integer weights for a replica set and cache the result by signature.
 *
 * Why cache?
 * ----------
 * `getReadConfig()` is invoked by Laravel on every read query (every SELECT
 * that isn't write-sticky). v1 and v2 recomputed the entire pool — weights,
 * keys, sort order — on every call. That's ~O(n log n) per query where n is
 * the replica count (usually 3–10, but the per-query overhead still shows up
 * in profiling under load).
 *
 * The replica set is fixed for the lifetime of a process unless config is
 * hot-reloaded. So we key the cache by a SHA-256 over the serialised
 * `read` array + formula params and only re-resolve when the signature changes.
 *
 * Two formulas
 * ------------
 *
 *   linear       weight = round((cores × cpu_factor) + (ram × ram_factor))
 *
 *   diminishing  weight = round((cores^0.7 × cpu_factor) + (√ram × ram_factor))
 *
 * `diminishing` reflects the fact that doubling CPU or RAM does not double
 * real DB throughput (PostgreSQL has WAL writer, autovacuum and lock manager
 * contention; InnoDB has redo-log bottlenecks). Use it when the replica
 * cluster spans very different hardware tiers.
 *
 * You can also pass an explicit `'weight' => N` per replica to bypass both
 * formulas — useful for DR standbys or ramp-up.
 */
final class WeightResolver
{
    /**
     * Cached pools keyed by "{connection}:{sig}".
     * Each entry is the array returned by resolve().
     *
     * @var array<string, array<int, array{config: array<string, mixed>, weight: int, key: string}>>
     */
    private array $cache = [];

    /**
     * Resolve the weighted pool for a connection.
     *
     * @param string $connectionName Used as part of the cache key only.
     * @param array<int, array<string, mixed>> $replicas The 'read' config array (list of arrays).
     * @param string $formula 'linear' or 'diminishing'.
     * @param (\Closure(array<string, mixed>): bool)|null $healthFilter Optional — exclude replicas it returns false for.
     * @return array<int, array{config: array<string, mixed>, weight: int, key: string}>
     */
    public function resolve(
        string $connectionName,
        array $replicas,
        float $cpuFactor,
        float $ramFactor,
        string $formula = 'linear',
        ?\Closure $healthFilter = null,
    ): array {
        $sig = $this->signature($connectionName, $replicas, $cpuFactor, $ramFactor, $formula);

        if (!isset($this->cache[$sig])) {
            $this->cache[$sig] = $this->buildPool($replicas, $cpuFactor, $ramFactor, $formula);
        }

        $pool = $this->cache[$sig];

        // Apply the health filter at call time so toggling a replica's health
        // doesn't require busting the whole pool cache.
        if ($healthFilter !== null) {
            $pool = array_values(
                array_filter(
                    $pool,
                    fn ($entry) => $healthFilter($entry['config']),
                )
            );
        }

        return $pool;
    }

    /**
     * Clear the resolver cache. Call after config is hot-reloaded.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Diagnostic: number of distinct pool signatures cached.
     */
    public function size(): int
    {
        return count($this->cache);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $replicas
     * @return array<int, array{config: array<string, mixed>, weight: int, key: string}>
     */
    private function buildPool(array $replicas, float $cpuFactor, float $ramFactor, string $formula): array
    {
        $pool = [];

        foreach ($replicas as $replica) {
            $weight = $this->resolveWeight($replica, $cpuFactor, $ramFactor, $formula);

            if ($weight <= 0) {
                continue;   // explicitly disabled
            }

            $pool[] = [
                'config' => $replica,
                'weight' => $weight,
                'key' => $this->replicaKey($replica),
            ];
        }

        // Sort by stable key so SWRR position[i] always refers to the same replica
        // even if the config file order changes between deploys.
        usort($pool, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $pool;
    }

    /**
     * Resolve weight for a single replica.
     *
     * Priority:
     *   1. Explicit 'weight' key in config  →  used as-is (0 = disabled).
     *   2. 'cpu_cores' + 'ram_gb'           →  formula (linear or diminishing).
     *   3. Neither                           →  weight = 1 (equal treatment).
     *
     * @param array<string, mixed> $replica
     */
    private function resolveWeight(array $replica, float $cpuFactor, float $ramFactor, string $formula): int
    {
        if (array_key_exists('weight', $replica)) {
            return max(0, ConfigValue::int($replica['weight'] ?? null));
        }

        $cores = $replica['cpu_cores'] ?? null;
        $ram = $replica['ram_gb'] ?? null;

        if ($cores === null && $ram === null) {
            return 1;
        }

        $cores = max(1, ConfigValue::int($cores, 1));
        $ram = max(0.0, ConfigValue::float($ram));

        $effectiveCores = $formula === 'diminishing'
            ? pow($cores, 0.7)
            : (float) $cores;

        $effectiveRam = $formula === 'diminishing'
            ? sqrt($ram)
            : $ram;

        $weight = (int) round(
            $effectiveCores * $cpuFactor + $effectiveRam * $ramFactor
        );

        return max(1, $weight);
    }

    /**
     * @param array<string, mixed> $replica
     */
    private function replicaKey(array $replica): string
    {
        $host = $replica['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        return ConfigValue::string($host, 'unknown').':'.ConfigValue::int($replica['port'] ?? null, 5432);
    }

    /**
     * @param array<int, array<string, mixed>> $replicas
     */
    private function signature(string $name, array $replicas, float $cpuFactor, float $ramFactor, string $formula): string
    {
        // Sort replicas by host:port for a stable signature independent of config order.
        $sorted = $replicas;
        usort($sorted, fn ($a, $b) => strcmp(
            $this->replicaKey($a),
            $this->replicaKey($b),
        ));

        return sprintf(
            '%s:%s',
            $name,
            hash('sha256', serialize([
                'replicas' => $sorted,
                'cpu_factor' => $cpuFactor,
                'ram_factor' => $ramFactor,
                'formula' => $formula,
            ])),
        );
    }
}

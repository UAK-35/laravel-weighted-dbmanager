<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;

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
 *
 * WHAT IT DOES NOT USE
 * --------------------
 * A pool is a shorter list than the read config that produced it, and there are three reasons a
 * replica is missing from it: `weight: 0`, which the read list means as a disable; a weight the
 * package refuses to read, which `ConfigValue` falls back to `0` and which therefore removes the
 * replica as well; and the caller's own health filter, applied to a pool this class already
 * built. Only the first two are properties of the configuration, and this class is the only thing
 * that knows which replicas it dropped and why — so it reports them rather than leaving every
 * consumer to infer the exclusion from a shorter list. `resolveWithExclusions()` is that report;
 * `resolve()` is the pool alone, for the read path where a per-query drop is not news.
 *
 * @phpstan-type Entry array{config: array<string, mixed>, weight: int, key: string}
 * @phpstan-type Exclusion array{config: array<string, mixed>, key: string, weight: int, reason: string, detail: string}
 */
final class WeightResolver
{
    /**
     * A replica taken out on purpose: `weight: 0`, read as written. Not a fault, and not a
     * refusal — see `ReplicaMetadata::DISABLED`.
     */
    public const EXCLUDED_DISABLED = 'disabled';

    /**
     * A replica whose weight the package will not read, read as the disable value instead. This
     * is the exclusion that used to be silent: the read list described a replica, the pool did
     * not hold it, and nothing said which one went.
     */
    public const EXCLUDED_REFUSED = 'refused';

    /**
     * A replica the caller's health filter rejected. Not a fact about the configuration: the pool
     * it was built from holds this replica, and the drop is per-call and self-healing.
     */
    public const EXCLUDED_FILTERED = 'filtered';

    /**
     * The three reasons, in the order a report that groups them reads.
     */
    public const EXCLUSION_REASONS = [
        self::EXCLUDED_REFUSED,
        self::EXCLUDED_DISABLED,
        self::EXCLUDED_FILTERED,
    ];

    /**
     * Cached pools keyed by "{connection}:{sig}".
     * Each entry is the pair returned by buildPool() — the pool, and what it left out.
     *
     * @var array<string, array{pool: list<Entry>, excluded: list<Exclusion>}>
     */
    private array $cache = [];

    /**
     * Resolve the weighted pool for a connection.
     *
     * @param string $connectionName Used as part of the cache key only.
     * @param array<int, array<string, mixed>> $replicas The 'read' config array (list of arrays).
     * @param string $formula 'linear' or 'diminishing'.
     * @param (\Closure(array<string, mixed>): bool)|null $healthFilter Optional — exclude replicas it returns false for.
     * @return list<Entry>
     */
    public function resolve(
        string $connectionName,
        array $replicas,
        float $cpuFactor,
        float $ramFactor,
        string $formula = 'linear',
        ?\Closure $healthFilter = null,
    ): array {
        return $this->resolveWithExclusions($connectionName, $replicas, $cpuFactor, $ramFactor, $formula, $healthFilter)['pool'];
    }

    /**
     * The same resolution, with the replicas it did not use and the reason for each.
     *
     * `excluded` is a partition with `pool` rather than a supplement to it: every replica the
     * config passed in appears exactly once across the two lists, so a caller can answer "the pool
     * is one shorter than the read list" without re-deriving anything. That is the whole point —
     * the replicas that leave a pool are the ones that are *not* reported by `resolve()`, and
     * before this a report could only show the shorter list and guess.
     *
     * The two configuration reasons (`disabled`, `refused`) are computed with the pool and cached
     * with it; the health filter is applied per call, as it always was, so a filter's exclusion is
     * reported for the call it applied to and never remembered as a property of the installation.
     *
     * @param string $connectionName Used as part of the cache key only.
     * @param array<int, array<string, mixed>> $replicas The 'read' config array (list of arrays).
     * @param (\Closure(array<string, mixed>): bool)|null $healthFilter Optional — exclude replicas it returns false for.
     * @return array{pool: list<Entry>, excluded: list<Exclusion>}
     */
    public function resolveWithExclusions(
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

        $resolved = $this->cache[$sig];
        $excluded = $resolved['excluded'];

        // Apply the health filter at call time so toggling a replica's health doesn't require
        // busting the whole pool cache. The cached exclusion list is copied here rather than
        // appended to, so one caller's filter exclusion is never the next caller's cache entry.
        if ($healthFilter === null) {
            return $resolved;
        }

        $pool = [];

        foreach ($resolved['pool'] as $entry) {
            if ($healthFilter($entry['config'])) {
                $pool[] = $entry;

                continue;
            }

            $excluded[] = [
                'config' => $entry['config'],
                'key' => $entry['key'],
                'weight' => $entry['weight'],
                'reason' => self::EXCLUDED_FILTERED,
                // Says only what this class knows. Whether the filter is about health, and what
                // the replica is doing instead, belongs to whoever supplied it.
                'detail' => 'excluded from this pool by the health filter the caller supplied, so it is not weighted for this read',
            ];
        }

        return ['pool' => $pool, 'excluded' => $excluded];
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
     * Build the pool and the list of what it left out, both sorted by the replica's stable key.
     *
     * The sort is what makes SWRR's `position[i]` refer to the same replica however the config
     * file is ordered, and it is applied to the exclusions for the same reason a report needs it:
     * a list that reorders between calls is a list that reads as if it had changed.
     *
     * @param array<int, array<string, mixed>> $replicas
     * @return array{pool: list<Entry>, excluded: list<Exclusion>}
     */
    private function buildPool(array $replicas, float $cpuFactor, float $ramFactor, string $formula): array
    {
        $pool = [];
        $excluded = [];

        foreach ($replicas as $replica) {
            $weight = $this->resolveWeight($replica, $cpuFactor, $ramFactor, $formula);
            $key = ReplicaMetadata::key($replica);

            if ($weight <= 0) {
                $excluded[] = $this->exclusion($replica, $key, $weight);

                continue;
            }

            $pool[] = [
                'config' => $replica,
                'weight' => $weight,
                'key' => $key,
            ];
        }

        // Sort by stable key so SWRR position[i] always refers to the same replica
        // even if the config file order changes between deploys.
        usort($pool, fn ($a, $b) => strcmp($a['key'], $b['key']));
        usort($excluded, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return ['pool' => $pool, 'excluded' => $excluded];
    }

    /**
     * One replica that is not in the pool, and which of the two configuration reasons it is.
     *
     * A weight that `max(ReplicaMetadata::WEIGHT_FLOOR, ConfigValue::int(…))` reads as that floor
     * is exactly one of two things, and `ReplicaMetadata` is what says which: the value the read list means as a disable, or a value
     * the package will not read and substitutes `0` for. The two cannot both hold of one written
     * value, so finding no weight refusal here *is* the disable — which is why this reads the
     * refusals rather than asking about the disable first, and why the value that falls through is
     * named rather than assumed: `ReplicaMetadataTest` asserts the exclusivity.
     *
     * @param array<string, mixed> $replica
     * @return Exclusion
     */
    private function exclusion(array $replica, string $key, int $weight): array
    {
        foreach (ReplicaMetadata::refusals($replica) as $refusal) {
            if ($refusal['setting'] !== 'weight') {
                continue;
            }

            return [
                'config' => $replica,
                'key' => $key,
                'weight' => $weight,
                'reason' => self::EXCLUDED_REFUSED,
                'detail' => $refusal['sentence'],
            ];
        }

        return [
            'config' => $replica,
            'key' => $key,
            'weight' => $weight,
            'reason' => self::EXCLUDED_DISABLED,
            'detail' => ReplicaMetadata::DISABLED,
        ];
    }

    /**
     * Resolve weight for a single replica.
     *
     * Priority:
     *   1. Explicit 'weight' key in config  →  used as-is (0 = disabled).
     *   2. 'cpu_cores' + 'ram_gb'           →  formula (linear or diminishing).
     *   3. Neither                           →  weight = 1 (equal treatment).
     *
     * The floors are `ReplicaMetadata`'s constants rather than numbers written here, and that is
     * the point of them: this is the arithmetic a report is asserting against, so a floor is one
     * number read in both places. `ReplicaMetadataTest` and the boundary cases in
     * `WeightResolverTest` hold the two to each other — before that, this method's `max()`
     * arguments were a copy of a rule restated in the classifier, and no test could catch them
     * drifting apart without reading this method.
     *
     * The `max(1, $weight)` at the end is *not* one of those floors: it is the formula's own
     * minimum, a replica that declares hardware is never weighted nothing, and nothing outside
     * this method has an opinion about it.
     *
     * @param array<string, mixed> $replica
     */
    private function resolveWeight(array $replica, float $cpuFactor, float $ramFactor, string $formula): int
    {
        if (array_key_exists('weight', $replica)) {
            return max(ReplicaMetadata::WEIGHT_FLOOR, ConfigValue::int($replica['weight'] ?? null));
        }

        $cores = $replica['cpu_cores'] ?? null;
        $ram = $replica['ram_gb'] ?? null;

        if ($cores === null && $ram === null) {
            return 1;
        }

        $cores = max(ReplicaMetadata::CORES_FLOOR, ConfigValue::int($cores, ReplicaMetadata::CORES_FLOOR));
        $ram = max(ReplicaMetadata::RAM_FLOOR, ConfigValue::float($ram));

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
     * @param array<int, array<string, mixed>> $replicas
     */
    private function signature(string $name, array $replicas, float $cpuFactor, float $ramFactor, string $formula): string
    {
        // Sort replicas by host:port for a stable signature independent of config order.
        $sorted = $replicas;
        usort($sorted, fn ($a, $b) => strcmp(
            ReplicaMetadata::key($a),
            ReplicaMetadata::key($b),
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

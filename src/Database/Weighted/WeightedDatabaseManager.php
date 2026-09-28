<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;

/**
 * WeightedDatabaseManager — Laravel DatabaseManager with weighted read replica routing.
 *
 * ROUTING ALGORITHM
 * ------------------
 *   1. Resolve the replica pool via WeightResolver (cached by signature).
 *   2. Filter out replicas marked unhealthy by HealthMonitor.
 *   3. Run one SWRR step via AtomicStateStore to get the winning index.
 *   4. If the primary store is unhealthy, fall back to LocalStateStore and log it.
 *   5. Return the merged read+write config to the connection factory.
 *
 * ENTRY POINT
 * -----------
 *   Laravel picks the read host inside its ConnectionFactory, not inside
 *   DatabaseManager, so routing starts at readConfigFor(): WeightedConnectionFactory
 *   calls it once per read PDO. There is deliberately no getReadConfig() here —
 *   DatabaseManager has no read-selection hook for it to override.
 *
 * UPGRADE FROM v2
 * ---------------
 *   • WeightResolver caches resolved pools — saves O(n log n) per query.
 *   • HealthMonitor uses exponential cool-down instead of fixed 60 s.
 *   • AtomicStateStore interface lets you swap in APCu, MySQL, etc. later.
 *   • Per-replica latency tracking exposed via replicaStatus().
 *   • markFailed() now returns the cool-down duration for observability.
 *
 * @see WeightedConnectionFactory  Calls readConfigFor() for every read PDO
 * @see Algorithm            SWRR step primitive
 * @see WeightResolver       Cached pool with linear/diminishing formulas
 * @see HealthMonitor        Per-replica circuit state
 * @see AtomicStateStore     SWRR state store contract
 * @see RedisAtomicStateStore Production implementation
 */
class WeightedDatabaseManager extends DatabaseManager
{
    private WeightResolver $resolver;

    private HealthMonitor $health;

    private AtomicStateStore $store;

    private ?TimeWindowResolver $timeWindow;

    /** Whether to silently fall back to LocalStateStore when the primary store errors. */
    private bool $allowLocalFallback = true;

    private float $defaultWeightCpuFactor = 3.0;

    private float $defaultWeightRamFactor = 3.375;

    private string $defaultWeightFormula = 'linear';

    /** Store used when the primary store is unhealthy; built lazily. */
    private ?AtomicStateStore $fallbackStore = null;

    /** True once this process has fallen back to the in-process store. */
    private bool $degraded = false;

    /** Key prefix for the in-process fallback store — mirrors swrr.key_prefix. */
    private string $localKeyPrefix = 'swrr';


    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function __construct(
        $app,
        $factory,
        ?WeightResolver $resolver = null,
        ?HealthMonitor $health = null,
        ?AtomicStateStore $store = null,
        ?TimeWindowResolver $timeWindow = null,
    ) {
        parent::__construct($app, $factory);

        $this->resolver = $resolver ?? new WeightResolver();
        $this->health = $health ?? new HealthMonitor();
        $this->store = $store ?? new LocalStateStore();
        $this->timeWindow = $timeWindow;
    }

    public function setStore(AtomicStateStore $store): void
    {
        $this->store = $store;
    }

    public function resolver(): WeightResolver
    {
        return $this->resolver;
    }

    public function health(): HealthMonitor
    {
        return $this->health;
    }

    public function store(): AtomicStateStore
    {
        return $this->store;
    }

    /**
     * The store actually serving requests right now — the primary store, unless
     * this process has degraded to the in-process fallback.
     */
    public function activeStore(): AtomicStateStore
    {
        return $this->degraded ? $this->fallbackStore() : $this->store;
    }

    /**
     * True once this process has fallen back to the in-process store.
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function timeWindow(): ?TimeWindowResolver
    {
        return $this->timeWindow;
    }

    public function setTimeWindow(?TimeWindowResolver $timeWindow): void
    {
        $this->timeWindow = $timeWindow;
    }

    public function setAllowLocalFallback(bool $allow): void
    {
        $this->allowLocalFallback = $allow;
    }

    public function setDefaultWeightCpuFactor(float $defaultWeightCpuFactor): void
    {
        $this->defaultWeightCpuFactor = $defaultWeightCpuFactor;
    }

    public function setDefaultWeightRamFactor(float $defaultWeightRamFactor): void
    {
        $this->defaultWeightRamFactor = $defaultWeightRamFactor;
    }

    public function setDefaultWeightFormula(string $defaultWeightFormula): void
    {
        $this->defaultWeightFormula = $defaultWeightFormula;
    }

    /**
     * Key prefix for the in-process fallback store, so its keys match the
     * primary store's (swrr.key_prefix) instead of a hardcoded "swrr".
     */
    public function setLocalKeyPrefix(string $localKeyPrefix): void
    {
        $this->localKeyPrefix = $localKeyPrefix;
    }

    /**
     * Swap in the store used when the primary store is unhealthy.
     */
    public function setFallbackStore(AtomicStateStore $store): void
    {
        $this->fallbackStore = $store;
    }

    /**
     * Public accessor for a connection's configuration. DatabaseManager's own
     * configuration() is protected, which is what made db:probe-replicas
     * impossible to run.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(string $connectionName): array
    {
        return ConfigValue::assoc($this->configuration($connectionName));
    }

    // -------------------------------------------------------------------------
    // Core: replace Laravel's read picker
    // -------------------------------------------------------------------------

    /**
     * Pick the read configuration for a connection. WeightedConnectionFactory
     * calls this once for every read PDO Laravel creates — it replaces the
     * framework's Arr::random() pick.
     *
     * Routing decision:
     *   1. If a TimeWindowResolver is configured and the current moment is
     *      OUTSIDE a reader window (or on a non-reader day) → serve reads
     *      from the writer instead of the SWRR pool.
     *   2. Otherwise → SWRR over the weighted, health-filtered replica pool.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function readConfigFor(array $config): array
    {
        // 1. Windowed fallback — writer serves reads.
        if ($this->timeWindow !== null && !$this->timeWindow->isReaderWindow()) {
            return $this->mergeReadWriteConfig($config, $this->writeEntry($config));
        }

        $replicas = $config['read'] ?? [];

        // 2. No replicas defined at all — fall through, with the writer as the only
        // address there is to serve reads from. Safe when the connection declares no
        // write config: writeEntry() is [] then, so the merge behaves as it always did.
        if (!is_array($replicas) || $replicas === []) {
            return $this->mergeReadWriteConfig($config, $this->writeEntry($config));
        }

        // 3. Replicas with no weight metadata — fall back to Laravel's random pick
        //    so existing projects are unaffected by upgrading to this manager.
        if (!$this->hasWeightMetadata($replicas)) {
            return $this->mergeReadWriteConfig($config, $this->pickUnweighted($replicas));
        }

        return $this->mergeReadWriteConfig(
            $config,
            $this->pickReplica(
                ConfigValue::string($config['name'] ?? null, 'unknown'),
                ConfigValue::assocList($replicas),
                $config,
            ),
        );
    }

    /**
     * Laravel's own read pick, kept for replica sets that carry no weight
     * metadata: a random entry, or the whole array when `read` is a single
     * config map rather than a list of them.
     *
     * @param array<mixed, mixed> $replicas
     * @return array<string, mixed>
     */
    private function pickUnweighted(array $replicas): array
    {
        $picked = isset($replicas[0]) ? $replicas[array_rand($replicas)] : $replicas;

        return is_array($picked) ? ConfigValue::assoc($picked) : [];
    }

    /**
     * The write side of a connection config, narrowed to the single server entry a
     * merge can use: the entry the framework's own pick would take when `write` is a
     * list, the map itself when one server is declared, and nothing when there is no
     * usable write config at all.
     *
     * Narrowing is the whole point. `write` is a *list* of server entries — Laravel's
     * documented shape for a write connection — so merging the list itself (through
     * ConfigValue::assoc(), which builds a map) puts the entry at key 0 instead of
     * spelling out its host. A connection that declares its address only inside
     * write[]/read[], with no top-level host, is then handed to the connector with no
     * host and no port: Laravel takes its without-hosts path and libpq dials its
     * default address — a local socket, port 5432 — instead of the configured one.
     * Laravel's own getReadWriteConfig() narrows with Arr::random() before merging;
     * this is the same narrowing, and pickUnweighted() already did it for the read
     * pool.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function writeEntry(array $config): array
    {
        $write = $config['write'] ?? null;

        return is_array($write) ? $this->pickUnweighted($write) : [];
    }

    /**
     * The merge Laravel's own ConnectionFactory performs: overlay the chosen
     * host keys onto the base config and drop the read/write lists.
     *
     * `$merge` has to be a single server entry, never a list of them — a caller
     * holding a `read`/`write` list narrows it first (pickUnweighted(), the same
     * random pick the framework makes). Merging the list itself puts the entry at
     * key 0 instead of spelling out its host, and a connection that declares its
     * address only inside those lists then reaches the connector with none:
     * Laravel takes its without-hosts path and libpq dials its default address —
     * a local socket, port 5432 — instead of the configured one. That failure is
     * silent by construction, so a merge that ends up with neither host nor
     * unix_socket is logged here. Logged rather than thrown because omitting both
     * on purpose, to reach a local socket, is a legitimate configuration.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $merge
     * @return array<string, mixed>
     */
    private function mergeReadWriteConfig(array $config, array $merge): array
    {
        $merged = ConfigValue::assoc(Arr::except(array_merge($config, $merge), ['read', 'write']));

        if ($merge !== [] && !isset($merged['host']) && !isset($merged['unix_socket'])) {
            Log::warning(sprintf(
                '[WeightedDB] connection "%s" resolved a read config with neither host nor unix_socket, so the connector will use libpq\'s default address (a local socket, port 5432) rather than the one the connection declares. Merged keys: %s.',
                ConfigValue::string($config['name'] ?? null, 'unknown'),
                implode(', ', array_keys($merged)),
            ));
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // SWRR selection
    // -------------------------------------------------------------------------

    /**
     * Build the weighted, health-filtered pool and run one SWRR step.
     *
     * @param string $connectionName
     * @param array<int, array<string, mixed>> $replicas The 'read' config list.
     * @param array<string, mixed> $connectionConfig The full connection config (formula tunables live here).
     * @return array<string, mixed>
     */
    protected function pickReplica(string $connectionName, array $replicas, array $connectionConfig): array
    {
        [$cpuFactor, $ramFactor, $formula] = $this->weightTunables($connectionConfig);

        // 1. Resolve weights (cached) and apply health filter. The exclusions come back with the
        // pool, so the warning below can name what was dropped: "all replicas in cool-down"
        // without the replicas is a log line about a shorter list, which is exactly the shape
        // this report exists to replace.
        $resolved = $this->resolver->resolveWithExclusions(
            $connectionName,
            $replicas,
            $cpuFactor,
            $ramFactor,
            $formula,
            healthFilter: fn (array $r) => !$this->health->isFailing($this->replicaKey($r)),
        );

        $pool = $resolved['pool'];

        // 2. Every replica failed — reset circuits and retry once.
        if (empty($pool)) {
            Log::warning('[WeightedDB] All replicas in cool-down; resetting health circuits.', [
                'connection' => $connectionName,
                'excluded' => array_map(
                    static fn (array $exclusion): array => [
                        'replica' => $exclusion['key'],
                        'reason' => $exclusion['reason'],
                    ],
                    $resolved['excluded'],
                ),
            ]);

            $this->health->resetAll();
            $this->resolver->flush();

            $pool = $this->resolver->resolve(
                $connectionName,
                $replicas,
                $cpuFactor,
                $ramFactor,
                $formula,
                healthFilter: fn (array $r) => true,
            );

            if (empty($pool)) {
                // All replicas have weight 0 → truly nothing to route to.
                Log::error('[WeightedDB] No usable replicas; falling back to first.', [
                    'connection' => $connectionName,
                ]);

                return $replicas[0];
            }
        }

        // 3. Build SWRR inputs.
        $keys = array_column($pool, 'key');
        $weights = array_column($pool, 'weight');

        // 4. Run the atomic step.
        $idx = $this->runStep($connectionName, $keys, $weights);

        // 5. Clamp (defensive — should always be in-range).
        $idx = max(0, min($idx, count($pool) - 1));

        return $pool[$idx]['config'];
    }

    /**
     * Execute the SWRR step on the configured store, with optional fallback.
     *
     * @param string $connectionName
     * @param string[] $replicaKeys Stable-sorted "host:port" strings.
     * @param int[] $weights
     * @return int 0-based index.
     */
    private function runStep(string $connectionName, array $replicaKeys, array $weights): int
    {
        $failure = null;

        // Fast path: store is healthy.
        if ($this->store->isHealthy()) {
            try {
                $index = $this->store->next($connectionName, $replicaKeys, $weights);

                $this->recoverIfDegraded($connectionName);

                return $index;
            } catch (\Throwable $e) {
                $failure = $e;
                $this->onStoreFailure($connectionName, $e);
            }
        }

        // Fallback path: LocalStateStore. If fallback is disabled, hard-fail
        // rather than quietly losing cross-worker coordination.
        if (!$this->allowLocalFallback) {
            throw new \RuntimeException(
                sprintf(
                    '[WeightedDB] Primary state store [%s] is unavailable for connection [%s] and fallback is disabled.',
                    $this->store->name(),
                    $connectionName,
                ),
                0,
                $failure,
            );
        }

        $this->degrade($connectionName);

        return $this->fallbackStore()->next($connectionName, $replicaKeys, $weights);
    }

    /**
     * Enter the in-process fallback, loudly. Called at most once per process
     * until the primary store recovers — degrading without a trace is exactly
     * what made this a defect.
     */
    private function degrade(string $connectionName): void
    {
        if ($this->degraded) {
            return;
        }

        $this->degraded = true;

        Log::error('[WeightedDB] Degraded to the in-process SWRR store. Read rotation is no longer shared across PHP workers until the primary store recovers.', [
            'connection' => $connectionName,
            'primary_store' => $this->store->name(),
            'primary_store_healthy' => $this->store->isHealthy(),
            'fallback_store' => $this->fallbackStore()->name(),
        ]);
    }

    /**
     * Release the fallback store once the primary store answers again.
     */
    private function recoverIfDegraded(string $connectionName): void
    {
        if (!$this->degraded) {
            return;
        }

        $this->degraded = false;

        Log::warning('[WeightedDB] Primary state store recovered; in-process fallback released.', [
            'connection' => $connectionName,
            'store' => $this->store->name(),
        ]);
    }

    private function fallbackStore(): AtomicStateStore
    {
        return $this->fallbackStore ??= new LocalStateStore($this->localKeyPrefix);
    }

    /**
     * Hook called when the primary store throws — useful for metrics.
     */
    protected function onStoreFailure(string $connectionName, \Throwable $e): void
    {
        Log::warning('[WeightedDB] Primary state store call failed; using fallback.', [
            'connection' => $connectionName,
            'store' => $this->store->name(),
            'error' => $e->getMessage(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Public health API
    // -------------------------------------------------------------------------

    /**
     * Mark a replica as failed. Call from your exception handler:
     *
     *   try {
     *       DB::select('SELECT ...');
     *   } catch (QueryException $e) {
     *       app('db')->markReplicaFailed('10.0.1.11', 5432);
     *   }
     */
    public function markReplicaFailed(string $host, int $port = 5432, ?string $reason = null): int
    {
        return $this->health->markFailed("{$host}:{$port}", $reason);
    }

    public function markReplicaHealthy(string $host, int $port = 5432): void
    {
        $this->health->markHealthy("{$host}:{$port}");
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    /**
     * Snapshot of every replica's resolved weight, share, and current health.
     *
     * @return list<array{host: string, port: int, cpu_cores: int|null, ram_gb: float|null, weight: int, share_pct: float, healthy: bool, failure_count: int}>
     */
    public function replicaStatus(string $connectionName = 'pgsql'): array
    {
        $config = ConfigValue::assoc($this->configuration($connectionName));
        $replicas = ConfigValue::assocList($config['read'] ?? []);

        if ($replicas === []) {
            return [];
        }

        [$cpuFactor, $ramFactor, $formula] = $this->weightTunables($config);

        $pool = $this->resolver->resolve(
            $connectionName,
            $replicas,
            $cpuFactor,
            $ramFactor,
            $formula,
        );

        $totalWeight = (int) array_sum(array_column($pool, 'weight'));

        $rows = [];
        foreach ($pool as $entry) {
            $key = $entry['key'];
            $weight = $entry['weight'];

            $rows[] = [
                'host' => $this->replicaHost($entry['config']),
                'port' => $this->replicaPort($entry['config']),
                'cpu_cores' => isset($entry['config']['cpu_cores'])
                    ? ConfigValue::int($entry['config']['cpu_cores'])
                    : null,
                'ram_gb' => isset($entry['config']['ram_gb'])
                    ? ConfigValue::float($entry['config']['ram_gb'])
                    : null,
                'weight' => $weight,
                'share_pct' => $totalWeight > 0
                    ? round(($weight / $totalWeight) * 100, 1)
                    : 0.0,
                'healthy' => !$this->health->isFailing($key),
                'failure_count' => ConfigValue::int($this->health->snapshot()[$key]['count'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * The replicas a connection's read list declares that its pool does not hold, and why.
     *
     * The other half of `replicaStatus()`, and deliberately the same shape of answer: that method
     * returns the pool, this returns what is missing from it, and the two together are the read
     * list — every configured replica appears exactly once across them. A report can therefore say
     * "the pool holds one of the two replicas, and here is the one it does not hold and why"
     * without re-deriving the reason from a shorter list, which is what `db:doctor`'s
     * `replica metadata` row used to do and what nothing else could do at all.
     *
     * The health filter is not applied, for the reason `replicaStatus()` does not apply it either:
     * which replicas are *out of rotation right now* is a per-read fact about the health monitor,
     * reported per replica by that method's `healthy` flag and by `healthSummary()`'s
     * `failure_count`. Nothing here is inferred from the pool's size, so what is left is exactly
     * the configuration: `weight: 0`, and weights the package refuses to read.
     *
     * @return list<array{config: array<string, mixed>, key: string, weight: int, reason: string, detail: string}>
     */
    public function poolExclusions(string $connectionName): array
    {
        $config = ConfigValue::assoc($this->configuration($connectionName));
        $replicas = ConfigValue::assocList($config['read'] ?? []);

        if ($replicas === []) {
            return [];
        }

        [$cpuFactor, $ramFactor, $formula] = $this->weightTunables($config);

        return $this->resolver
            ->resolveWithExclusions($connectionName, $replicas, $cpuFactor, $ramFactor, $formula)['excluded'];
    }

    /**
     * Top-level health summary for /health/db and db:replica-status.
     *
     * @return array{
     *   store: string,
     *   store_healthy: bool,
     *   primary_store: string,
     *   degraded: bool,
     *   formula: string,
     *   cpu_factor: float,
     *   ram_factor: float,
     *   resolver_cache_size: int,
     *   read_mode: string,
     *   next_mode_change_at: string|null,
     *   replicas: list<array{
     *     host: string,
     *     port: int,
     *     cpu_cores: int|null,
     *     ram_gb: float|null,
     *     weight: int,
     *     share_pct: float,
     *     healthy: bool,
     *     failure_count: int,
     *   }>,
     * }
     */
    public function healthSummary(string $connectionName = 'pgsql'): array
    {
        $config = ConfigValue::assoc($this->configuration($connectionName));
        [$cpuFactor, $ramFactor, $formula] = $this->weightTunables($config);

        // -- Windowed fallback state --
        $readMode = 'readers';
        $nextChange = null;

        if ($this->timeWindow !== null) {
            $readMode = $this->timeWindow->currentModeName();
            $nextChangeAt = $this->timeWindow->nextTransitionAt();
            $nextChange = $nextChangeAt?->format(\DateTimeInterface::ATOM);
        }

        return [
            'store' => $this->activeStore()->name(),
            'store_healthy' => $this->store->isHealthy(),
            'primary_store' => $this->store->name(),
            'degraded' => $this->degraded,
            'formula' => $formula,
            'cpu_factor' => $cpuFactor,
            'ram_factor' => $ramFactor,
            'resolver_cache_size' => $this->resolver->size(),
            'read_mode' => $readMode,
            'next_mode_change_at' => $nextChange,
            'replicas' => $this->replicaStatus($connectionName),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * "host:port" a replica is keyed by. Laravel allows `host` to be a list of
     * hosts, so take the first one — the same normalisation WeightResolver uses.
     *
     * Public so a report can name a replica that is not in the pool. Every other reader of a
     * replica's identity goes through this pair of methods — the health monitor keys failures by
     * it, `replicaStatus()` prints its two halves, and `db:doctor`'s replica-metadata row names a
     * replica with it precisely when the pool has already dropped that replica, which is the one
     * case where the status table cannot be asked. A second spelling of "which replica" would be
     * a second thing to keep in step with the health record.
     *
     * The spelling itself is `ReplicaMetadata::key()` — the same one the resolver builds its pool
     * keys with, and the one the boot audit names a refused replica by before this manager is
     * ever resolved.
     *
     * @param array<string, mixed> $replica
     */
    public function replicaKey(array $replica): string
    {
        return ReplicaMetadata::key($replica);
    }

    /**
     * @param array<string, mixed> $replica
     */
    private function replicaHost(array $replica): string
    {
        $host = $replica['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        return ConfigValue::string($host, 'unknown');
    }

    /**
     * @param array<string, mixed> $replica
     */
    private function replicaPort(array $replica): int
    {
        return ConfigValue::int($replica['port'] ?? null, 5432);
    }

    /**
     * The formula tunables a connection inherits: `weight_cpu_factor`,
     * `weight_ram_factor` and `weight_formula` from the connection config,
     * falling back to this manager's defaults. One place to read them, so
     * db:replica-status, pickReplica() and healthSummary() cannot disagree.
     *
     * @param array<string, mixed> $config
     * @return array{0: float, 1: float, 2: string}
     */
    private function weightTunables(array $config): array
    {
        return [
            ConfigValue::float($config['weight_cpu_factor'] ?? null, $this->defaultWeightCpuFactor),
            ConfigValue::float($config['weight_ram_factor'] ?? null, $this->defaultWeightRamFactor),
            ConfigValue::string($config['weight_formula'] ?? null, $this->defaultWeightFormula),
        ];
    }

    /**
     * @param array<mixed, mixed> $replicas
     */
    private function hasWeightMetadata(array $replicas): bool
    {
        foreach ($replicas as $r) {
            if (is_array($r) && (isset($r['cpu_cores']) || isset($r['ram_gb']) || isset($r['weight']))) {
                return true;
            }
        }

        return false;
    }
}

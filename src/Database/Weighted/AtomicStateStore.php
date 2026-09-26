<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

/**
 * Contract for any atomic SWRR state store.
 *
 * A state store must guarantee that exactly ONE caller per pool can mutate the
 * current_weights array between read and write. Redis+`EVAL` does this with a
 * single Lua script execution; APCu shared memory could do it with CAS; an
 * in-process array doesn't need locking but loses cross-worker coordination.
 *
 * Implementations:
 *   - RedisAtomicStateStore  — production, cross-worker
 *   - LocalStateStore        — fallback when Redis is unavailable
 */
interface AtomicStateStore
{
    /**
     * Run one atomic SWRR step and return the 0-based index of the winner.
     *
     * @param string $connectionName E.g. 'pgsql', 'mysql' — namespaces the state key.
     * @param string[] $replicaKeys Stable-sorted "host:port" strings for the pool signature.
     * @param int[] $weights Static weight per replica, index-aligned with $replicaKeys.
     * @return int 0-based index into the replica pool.
     */
    public function next(string $connectionName, array $replicaKeys, array $weights): int;

    /**
     * Reset state for a given pool (useful after config change or in tests).
     *
     * @param string $connectionName E.g. 'pgsql', 'mysql' — namespaces the state key.
     * @param string[] $replicaKeys The same pool signature passed to next().
     */
    public function reset(string $connectionName, array $replicaKeys): void;

    /**
     * True when this store is healthy and being used for real calls.
     * False after repeated errors — the manager should switch to a fallback.
     */
    public function isHealthy(): bool;

    /**
     * Diagnostic label for logs / /health/db output.
     * E.g. "redis(default)", "local(in-process, uncoordinated)".
     */
    public function name(): string;
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Uak35\WeightedDbManager\Exceptions\RedisUnavailable;
use Uak35\WeightedDbManager\Support\RedisAccess;

/**
 * Atomic SWRR state store backed by Redis.
 *
 * Why Redis + Lua?
 * -----------------
 * PHP-FPM spawns many worker processes — each has its own memory. Without a
 * shared store, every worker would run its own SWRR state and two workers
 * might both pick the same replica in the same millisecond. Redis is the
 * canonical solution; Lua `EVAL` makes the read-modify-write atomic so no
 * two requests ever corrupt the shared current_weight array.
 *
 * Pool key design
 * ---------------
 *   Key format:  swrr:{connection}:{pool_sig}
 *   pool_sig     = first 12 chars of md5("host1:port1,host2:port2,…")
 *                  (replicas sorted lexicographically — order-independent)
 *
 * When the replica set changes, pool_sig changes → new key → fresh state.
 * Old keys expire via TTL so Redis doesn't fill up.
 *
 * Failure handling
 * -----------------
 * Any Redis exception is caught and recorded. After `$unhealthyAfterFailures`
 * consecutive failures we mark ourselves unhealthy and stop trying — the
 * WeightedDatabaseManager then falls back to LocalStateStore. After a success
 * the failure counter resets.
 *
 * A store that cannot be resolved at all (no `redis` binding in the container) is
 * not a failure of that kind, and does not count toward the threshold: connection
 * configs are built while providers are still registering, so the first pick of a
 * process can legitimately happen before Redis exists. Those picks fall back too,
 * and the store keeps serving once Redis is bound.
 */
final class RedisAtomicStateStore implements AtomicStateStore
{
    /**
     * Atomic SWRR step stored and returned.
     *
     *   KEYS[1]      = pool state key
     *   ARGV[1]      = n (number of replicas)
     *   ARGV[2..n+1] = static weights (integers)
     *   ARGV[n+2]    = TTL in seconds
     *
     * Returns: 0-based index of the selected replica.
     */
    private const LUA_CODE = <<<'LUA'
local n   = tonumber(ARGV[1])
local ttl = tonumber(ARGV[n + 2])

-- Build static weight array from ARGV.
local w = {}
for i = 1, n do
    w[i] = tonumber(ARGV[i + 1])
end

-- Load persisted current_weights; init to zeros if absent or stale size.
local raw = redis.call('GET', KEYS[1])
local cw  = {}
if raw then
    local idx = 0
    for part in string.gmatch(raw, '[^,]+') do
        idx     = idx + 1
        cw[idx] = tonumber(part)
    end
    if idx ~= n then cw = {} end   -- pool size changed → reset
end
if #cw ~= n then
    for i = 1, n do cw[i] = 0 end
end

-- Compute total static weight.
local total = 0
for i = 1, n do total = total + w[i] end

-- SWRR step 1: add static weight to every current_weight.
for i = 1, n do cw[i] = cw[i] + w[i] end

-- SWRR step 2: find the winner (max current_weight; ties → lowest index).
local best = 1
for i = 2, n do
    if cw[i] > cw[best] then best = i end
end

-- SWRR step 3: subtract total from winner.
cw[best] = cw[best] - total

-- Persist updated state with TTL.
local parts = {}
for i = 1, n do parts[i] = tostring(cw[i]) end
redis.call('SET', KEYS[1], table.concat(parts, ','), 'EX', ttl)

-- Return 0-based index.
return best - 1
LUA;

    /** Consecutive Redis failures before we mark ourselves unhealthy. */
    private int $unhealthyAfterFailures = 3;

    /** Redis connection failures; reset on first success. */
    private int $consecutiveFailures = 0;

    private bool $healthy = true;

    /**
     * One line per process: under PHP-FPM an application boots per request, so an
     * unthrottled report would write the same sentence into the log every request.
     */
    private static bool $unavailableReported = false;

    public function __construct(
        private readonly string $redisConnection = 'default',
        private readonly int $stateTtlSeconds = 86400,
        private readonly string $keyPrefix = 'swrr',
        private readonly ?ContainerContract $app = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // AtomicStateStore
    // -------------------------------------------------------------------------

    public function next(string $connectionName, array $replicaKeys, array $weights): int
    {
        $poolKey = $this->poolKey($connectionName, $replicaKeys);

        try {
            $idx = $this->redisEval($poolKey, $weights);
            $this->recordSuccess();

            return $idx;
        } catch (RedisUnavailable $e) {
            // Not a failing store — see the exception. The caller falls back for this
            // call, and the store stays healthy for the ones that follow.
            $this->reportUnavailable($e, $connectionName);

            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure($e, $connectionName);

            // Caller will see this exception; WeightedDatabaseManager catches it
            // and falls back to LocalStateStore.
            throw $e;
        }
    }

    public function reset(string $connectionName, array $replicaKeys): void
    {
        try {
            $this->connection()->del($this->poolKey($connectionName, $replicaKeys));
        } catch (\Throwable) {
            // Key will expire naturally via TTL.
        }
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function name(): string
    {
        return sprintf('redis(%s)', $this->redisConnection);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Run the Lua eval atomically on Redis.
     *
     * @param string $poolKey
     * @param int[] $weights
     * @return int
     */
    private function redisEval(string $poolKey, array $weights): int
    {
        $n = count($weights);

        /**
         * PhpRedisConnection::eval() is declared as (script, numberOfKeys,
         * ...arguments) — the ext-redis argument order assembled below.
         * PredisConnection declares no eval(), so it forwards the identical call
         * through Connection::__call. The concrete type is named here only so the
         * call resolves to the declared signature instead of @mixin \Redis.
         *
         * @var PhpRedisConnection $conn
         */
        $conn = $this->connection();

        // Build the full argument list explicitly — PHP forbids positional
        // arguments after argument unpacking. Layout:
        //   KEYS[1]      = $poolKey
        //   ARGV[1]      = $n
        //   ARGV[2..n+1] = $weights…
        //   ARGV[n+2]    = $ttl
        $args = array_merge(
            [$poolKey, $n],
            array_values($weights),
            [$this->stateTtlSeconds],
        );

        // Laravel's PhpRedisConnection::eval(script, numKeys, ...keysAndArgs)
        // maps to phpredis eval(script, [keys+args], numKeys).
        $result = $conn->eval(
            self::LUA_CODE,
            1,                 // number of KEYS
            ...$args,
        );

        // A Redis script reply is int|null; anything else means the Lua contract
        // was broken, so fall back to index 0 rather than casting a garbage value.
        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * The connection the store runs Lua on.
     *
     * Throws RedisUnavailable rather than a connection error when there is no store
     * to reach, and reaches it through the container rather than the `Redis` facade —
     * resolving that facade before the application binds `redis` builds the phpredis
     * extension class instead and caches it process-wide. See Support\RedisAccess.
     */
    private function connection(): Connection
    {
        [$factory, $error] = RedisAccess::factory($this->app);

        if ($factory === null) {
            throw new RedisUnavailable(sprintf(
                'The redis(%s) store cannot be used: %s.',
                $this->redisConnection,
                $error,
            ));
        }

        /** @var Connection $connection */
        $connection = $factory->connection($this->redisConnection);

        return $connection;
    }

    /**
     * Say once per process that this worker could not use the store, and why. The
     * boot audit reports the installation-wide view; this covers a single worker
     * that asked before Redis existed.
     */
    private function reportUnavailable(RedisUnavailable $e, string $connectionName): void
    {
        if (self::$unavailableReported) {
            return;
        }

        self::$unavailableReported = true;

        Log::warning('[WeightedDB] This pick fell back to the in-process store.', [
            'store' => $this->name(),
            'connection' => $connectionName,
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * Stable, deterministic Redis key for a given pool.
     *
     * @param string $connectionName
     * @param string[] $replicaKeys
     * @return string
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

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->healthy = true;
    }

    private function recordFailure(\Throwable $e, string $connectionName): void
    {
        $this->consecutiveFailures++;

        if (
            !$this->healthy
            && $this->consecutiveFailures >= $this->unhealthyAfterFailures
        ) {
            return;   // already unhealthy; stay quiet
        }

        Log::warning('[WeightedDB] Redis SWRR step failed.', [
            'connection' => $connectionName,
            'error' => $e->getMessage(),
            'consecutive' => $this->consecutiveFailures,
        ]);

        if ($this->consecutiveFailures >= $this->unhealthyAfterFailures) {
            $this->healthy = false;
            Log::error('[WeightedDB] Redis SWRR marked unhealthy — falling back.', [
                'connection' => $connectionName,
            ]);
        }
    }
}

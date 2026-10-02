<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Illuminate\Support\Arr;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * One `SELECT 1`, to one host, on a connection of its own.
 *
 * WHY THIS IS ITS OWN CLASS
 *   Two different questions are answered by asking a single database whether it is there:
 *   `db:probe-replicas` asks it of every replica so the health monitor knows which to stop
 *   routing to, and the reader-window flip asks it of the *target of the mode it is about to
 *   apply* before it applies it — a pool pointed at replicas that are gone is worse than the
 *   pool it replaced. Both need the same three things to be true, and they are the whole of
 *   this class: the probe is a **single host** (the pooled `read`/`write` lists are dropped so
 *   the connection cannot be routed back through the pool it is measuring), it runs on a
 *   **throwaway connection** that is purged afterwards whatever the query did, and it is a bare
 *   `SELECT 1` so nothing it does depends on the schema being reachable beyond the connection
 *   itself.
 *
 *   They used to be one command's private methods. A second caller is exactly the point at
 *   which that stops being right: a copy of "drop the read and write lists, merge the host,
 *   connect, select, purge" in two files is a copy that can drift in one of them, and the drift
 *   would look like a probe that answered when the other would have failed.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   It does not mark health. A failed probe is a fact with two different consequences — the
 *   sweep records it on the health monitor, the readiness gate only counts it — and putting one
 *   of those in here would make the other caller's behaviour this class's decision. The throw,
 *   and the counting, belong to the caller.
 */
final class SingleHostProbe
{
    /**
     * The throwaway connection every probe is opened as.
     *
     * A name rather than a pool entry: it is registered with `connectUsing(..., force: true)` and
     * purged in the same breath, so it never joins the weighted read pool and never becomes a
     * cached connection a later request could inherit.
     */
    public const CONNECTION = '__weighted_db_probe';

    /**
     * Not instantiable: everything here is a pure function of the manager and a host map.
     */
    private function __construct()
    {
    }

    /**
     * Open one connection straight to `$host` and run `SELECT 1`.
     *
     * Throws whatever the connection or the query threw. The throwaway connection is purged on the
     * way out either way, which is why this is not left to the caller: a probe that failed is
     * exactly when a cached throwaway connection is most likely to be reused by something that
     * expects the pooled one.
     *
     * @param array<string, mixed> $base the connection's own config, read and write lists included
     * @param array<string, mixed> $host the one host to reach — a replica map, or the writer's
     */
    public static function run(WeightedDatabaseManager $manager, array $base, array $host): void
    {
        $manager->connectUsing(self::CONNECTION, self::config($base, $host), true);

        try {
            $manager->connection(self::CONNECTION)->select('SELECT 1');
        } finally {
            $manager->purge(self::CONNECTION);
        }
    }

    /**
     * The config a single-host probe connects with: the pooled lists dropped, the host's own keys
     * merged over what is left.
     *
     * The lists have to go rather than be overwritten. A `read` list left in place is what the
     * weighted factory picks a replica from, so a probe that kept it could answer from a *different*
     * replica than the one it was asked about — the failure this whole class exists to avoid.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    public static function config(array $base, array $host): array
    {
        return ConfigValue::assoc(array_merge(Arr::except($base, ['read', 'write']), $host));
    }

    /**
     * The host:port a host is tracked under, matching `WeightResolver`'s key format so a probe
     * result and a routing decision name the same replica the same way.
     *
     * A `host` that is a list is Laravel's own accepted shape for one host with several
     * addresses; the first is the one the connector would use, so it is the one a report should
     * name.
     *
     * @param array<string, mixed> $host
     * @return array{0: string, 1: int}
     */
    public static function address(array $host, int $defaultPort): array
    {
        $name = $host['host'] ?? null;

        if (is_array($name)) {
            $name = $name[0] ?? 'unknown';
        }

        return [
            ConfigValue::string($name, 'unknown'),
            ConfigValue::int($host['port'] ?? null, $defaultPort),
        ];
    }
}

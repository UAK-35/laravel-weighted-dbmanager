<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Throwable;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * Is the target of a mode change actually there?
 *
 * WHY THIS EXISTS
 *   A flip rewrites what pgcat points at, and the two directions do not have the same cost when
 *   they are wrong. Pointing the pool at replicas that are gone turns every read into a failure;
 *   pointing it back at the writer when the writer is down is not a state a pooler can be in,
 *   because the writer is the database every other path already needs. So the change is gated on
 *   the target answering first, and this is the class that asks — one `SELECT 1` per candidate,
 *   through {@see SingleHostProbe}, so the answer cannot have come from the pooled connection it
 *   is about to be applied to.
 *
 * THE TWO VERDICTS, AND WHY THEY ARE NOT THE SAME RULE
 *   `readers` is available when **at least one** replica answered. The pool needs one to send a
 *   read to, the weight resolver already excludes the ones that are cooling down, and a sweep
 *   that reached one of two is a working installation with a problem rather than a broken one —
 *   the same reading `db:probe-replicas` gives the same situation.
 *
 *   `primary` is available when the writer answered. There is exactly one of it in this
 *   deployment, so "at least one" and "all" are the same question here, and the answer is about
 *   whether reads can fall back at all.
 *
 * WHAT IT WRITES, AND WHAT IT ONLY READS
 *   A replica sweep marks what it found through the manager, exactly as `db:probe-replicas` does:
 *   a probe result is a probe result, and the routing decision that follows from it should not
 *   depend on which command happened to ask. The *primary* is not marked — the health monitor is
 *   a replica registry and has no key for the writer — so its row is evidence in the report and
 *   nothing else.
 *
 * THE NOTE
 *   A verdict carries a `note` when there was nothing to ask rather than a failure to report: a
 *   connection with no `read` list has no replica to point at, which is a configuration fact and
 *   not a probe that went wrong. The two are worth telling apart in a report, and they exit the
 *   same way.
 */
final class ReadinessProbe
{
    /** What a flip to `readers` is gated on. */
    public const TARGET_REPLICAS = 'replicas';

    /** What a flip to `writer` is gated on. */
    public const TARGET_PRIMARY = 'primary';

    /**
     * Why a replica was marked failed by this gate.
     *
     * Named rather than left to the health monitor's default so a cooldown can be told from a
     * request-path one in the log — the two are the same mechanism with very different evidence.
     */
    public const MARK_REASON = 'window-flip readiness';

    private function __construct()
    {
    }

    /**
     * The target a mode's readiness is a question about.
     */
    public static function targetFor(string $mode): string
    {
        return $mode === 'readers' ? self::TARGET_REPLICAS : self::TARGET_PRIMARY;
    }

    /**
     * The verdict for `$mode`: ask its target, and report what was asked and what answered.
     *
     * @return array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}
     */
    public static function forMode(string $mode, WeightedDatabaseManager $manager, string $connection): array
    {
        return self::targetFor($mode) === self::TARGET_REPLICAS
            ? self::replicas($manager, $connection)
            : self::primary($manager, $connection);
    }

    /**
     * Every replica in the connection's read list, asked in order.
     *
     * The list is read the way the connector reads it — a `read` that is not a list is one config
     * map rather than a list of one, and routing already treats it that way, so a gate that did
     * not would fail an installation whose single replica is serving reads perfectly.
     *
     * @return array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}
     */
    public static function replicas(WeightedDatabaseManager $manager, string $connection): array
    {
        $config = $manager->connectionConfig($connection);
        $declared = $config['read'] ?? null;

        if (!is_array($declared) || $declared === []) {
            return self::verdict(self::TARGET_REPLICAS, [], sprintf(
                'the connection [%s] declares no read list, so a pool pointed at replicas would have nothing to point at',
                $connection,
            ));
        }

        $replicas = array_is_list($declared) ? $declared : [$declared];
        $defaultPort = ConfigValue::int($config['port'] ?? null, 5432);

        $hosts = [];

        foreach ($replicas as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $replica = ConfigValue::assoc($entry);
            [$host, $port] = SingleHostProbe::address($replica, $defaultPort);

            try {
                SingleHostProbe::run($manager, $config, $replica);

                $manager->markReplicaHealthy($host, $port);
                $hosts[] = ['host' => $host, 'port' => $port, 'healthy' => true, 'error' => null];
            } catch (Throwable $e) {
                $manager->markReplicaFailed($host, $port, self::MARK_REASON);
                $hosts[] = ['host' => $host, 'port' => $port, 'healthy' => false, 'error' => $e->getMessage()];
            }
        }

        return self::verdict(self::TARGET_REPLICAS, $hosts, null);
    }

    /**
     * The writer, asked directly.
     *
     * A connection declares its writer one of three ways and all three have to be read the same
     * way as the connector reads them: a `write` block that is a **list** of writers (the first is
     * the one Laravel uses), a `write` block that is one **map**, or no `write` block at all — in
     * which case the connection's own host *is* the writer, and it is the config minus the pooled
     * lists that describes it. Passing an empty host map to {@see SingleHostProbe::config()} is
     * what makes the third case the same call as the other two.
     *
     * Only the writer a flip would actually be reached on is probed, and there is one row in the
     * verdict, so `answered` is 0 or 1 and `available` is that number being 1.
     *
     * @return array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}
     */
    public static function primary(WeightedDatabaseManager $manager, string $connection): array
    {
        $config = $manager->connectionConfig($connection);
        $declared = $config['write'] ?? null;

        $writer = [];

        if (is_array($declared) && $declared !== []) {
            $candidate = array_is_list($declared) ? ($declared[0] ?? null) : $declared;
            $writer = is_array($candidate) ? ConfigValue::assoc($candidate) : [];
        }

        $defaultPort = ConfigValue::int($config['port'] ?? null, 5432);
        [$host, $port] = SingleHostProbe::address($writer === [] ? $config : $writer, $defaultPort);

        try {
            SingleHostProbe::run($manager, $config, $writer);

            $hosts = [['host' => $host, 'port' => $port, 'healthy' => true, 'error' => null]];
        } catch (Throwable $e) {
            $hosts = [['host' => $host, 'port' => $port, 'healthy' => false, 'error' => $e->getMessage()]];
        }

        return self::verdict(self::TARGET_PRIMARY, $hosts, null);
    }

    /**
     * The counts and the verdict, from the rows — so the summary cannot disagree with the evidence
     * it is a summary of, and an empty sweep is `available: false` with the reason that made it
     * empty rather than a silent pass.
     *
     * @param list<array{host: string, port: int, healthy: bool, error: string|null}> $hosts
     * @return array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}
     */
    private static function verdict(string $target, array $hosts, ?string $note): array
    {
        $answered = count(array_filter($hosts, static fn (array $row): bool => $row['healthy']));

        return [
            'target' => $target,
            'available' => $answered > 0,
            'probed' => count($hosts),
            'answered' => $answered,
            'failed' => count($hosts) - $answered,
            'hosts' => $hosts,
            'note' => $note,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;

/**
 * The real manager, with the calls a probe's side effects travel through kept instead of
 * made.
 *
 * `db:probe-replicas` has one job beyond the SELECT 1: telling the health monitor what it
 * found, so routing stops choosing a replica that just failed. That is invisible from the
 * outside — the probe itself is a throwaway connection that is purged in a `finally` — so a
 * test that wants to assert the job was done has to watch the call. Everything else is the
 * real manager, which is why the probe still really connects and really runs its query.
 */
final class FakeWeightedManager extends WeightedDatabaseManager
{
    /**
     * Every result the command reported, in the order it reported them.
     *
     * @var list<array{host: string, port: int, healthy: bool, reason: string|null}>
     */
    public array $marks = [];

    /**
     * Every connection the command purged, in the order it purged it.
     *
     * The purge still happens; it is recorded first, because "one probe, one purge" is how a
     * test tells the per-probe `finally` from a single cleanup at the end of the sweep.
     *
     * @var list<string|null>
     */
    public array $purged = [];

    public function purge($name = null)
    {
        parent::purge($name);

        $this->purged[] = $name;
    }

    public function markReplicaHealthy(string $host, int $port = 5432): void
    {
        $this->marks[] = ['host' => $host, 'port' => $port, 'healthy' => true, 'reason' => null];
    }

    public function markReplicaFailed(string $host, int $port = 5432, ?string $reason = null): int
    {
        $this->marks[] = ['host' => $host, 'port' => $port, 'healthy' => false, 'reason' => $reason];

        return 1;
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use InvalidArgumentException;
use Uak35\WeightedDbManager\Database\Weighted\ReadinessProbe;
use Uak35\WeightedDbManager\Tests\Support\FakeWeightedManager;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The gate a window flip applies a mode behind: is the thing it is about to point the pool at
 * actually there?
 *
 * WHY THIS IS A FILE OF ITS OWN
 *   The probe is the only part of a mode change that can answer "not yet" instead of acting, and
 *   what it decides is stated in three numbers — how many candidates were asked, how many answered,
 *   and how many failed — plus one word, `available`. The three ways that word can be wrong all look
 *   the same from the outside: a `read` list written as one map instead of a list of one (an
 *   installation whose single replica is serving perfectly would be judged unavailable, and the pool
 *   would never be pointed at it), a `write` block that is absent (the connection's own host *is* the
 *   writer, and reading `write` as nothing would make every deactivation wait for a writer it never
 *   asked about), and an empty sweep reported as a pass rather than as nothing asked.
 *
 *   So each of those is a row here, and the rows probe SQLite — the transport a test can really
 *   connect to — with the config `SingleHostProbe` merges over each candidate. That is not a fixture
 *   shortcut: the merge is the thing being tested, because the pool's own lists have to be dropped
 *   for the probe to reach the host it was asked about, and a row pointing a replica at a database
 *   that is not there fails in the connector exactly as a host that is not listening fails in the
 *   driver.
 *
 * WHAT IT WRITES, AND WHAT IT ONLY READS
 *   A replica sweep marks what it found through the manager, exactly as `db:probe-replicas` does:
 *   a probe result is a probe result, and the routing decision that follows from it should not depend
 *   on which command happened to ask. The primary is not marked — the health monitor is a replica
 *   registry and has no key for the writer — so a row that asserts the primary's marks asserts that
 *   there are none.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Pgcat\WindowFlipScheduleTest
 */
final class ReadinessProbeTest extends TestCase
{
    /** A candidate that really answers, whatever the connection's driver is. */
    private const REACHABLE = ['driver' => 'sqlite', 'database' => ':memory:'];

    /**
     * A candidate whose connector refuses before a query is sent: an entry pointed at a SQLite file
     * that does not exist fails the way a host that is not listening fails, without a test waiting on
     * a socket.
     */
    private function unreachable(): array
    {
        return [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir().'/swrr-readiness-'.bin2hex(random_bytes(4)).'/dead.sqlite',
        ];
    }

    /**
     * The manager the probe is given, over a connection whose config is the row's question.
     *
     * @param array<string, mixed> $config
     */
    private function manager(array $config): FakeWeightedManager
    {
        config()->set('database.connections.readiness', [
            'driver' => 'pgsql',
            'port' => 5433,
            ...$config,
        ]);

        return new FakeWeightedManager($this->app, $this->app->make('db.factory'));
    }

    /**
     * A partial sweep is a working installation with a problem rather than a broken one, which is the
     * same reading `db:probe-replicas` gives the same situation: the pool needs one replica to send a
     * read to, the resolver already excludes the ones that are cooling down, and the failure is
     * recorded so the next routing decision knows.
     */
    public function test_one_replica_answering_is_available_and_the_failed_one_is_recorded(): void
    {
        $manager = $this->manager(['read' => [
            ['host' => '10.9.0.5', 'port' => 6432, ...self::REACHABLE],
            ['host' => '10.9.0.6', ...$this->unreachable()],
        ]]);

        $verdict = ReadinessProbe::replicas($manager, 'readiness');

        $this->assertSame(ReadinessProbe::TARGET_REPLICAS, $verdict['target']);
        $this->assertTrue($verdict['available'], 'one replica is enough to point reads at');
        $this->assertSame(2, $verdict['probed']);
        $this->assertSame(1, $verdict['answered']);
        $this->assertSame(1, $verdict['failed']);
        $this->assertSame(
            [
                ['host' => '10.9.0.5', 'port' => 6432, 'healthy' => true, 'error' => null],
                ['host' => '10.9.0.6', 'port' => 5433, 'healthy' => false, 'error' => $verdict['hosts'][1]['error']],
            ],
            $verdict['hosts'],
            'one row per candidate, in the order the read list gives them, and the port falls back to the connection\'s own',
        );

        $this->assertIsString($verdict['hosts'][1]['error'], 'a failed row names why, which the counts cannot');
        $this->assertNull($verdict['note'], 'a sweep that reached something has nothing to explain');

        // The marks reached the health monitor, and the reason is this gate's own — a cooldown can be
        // told from a request-path one in the log.
        $this->assertSame([
            ['host' => '10.9.0.5', 'port' => 6432, 'healthy' => true, 'reason' => null],
            ['host' => '10.9.0.6', 'port' => 5433, 'healthy' => false, 'reason' => ReadinessProbe::MARK_REASON],
        ], $manager->marks);
    }

    /**
     * Every replica failing is not available, and that is the whole point of asking: pointing the pool
     * at replicas that are gone turns every read into a failure, and the answer has to be "not yet"
     * rather than a mode change.
     */
    public function test_every_replica_failing_is_not_available(): void
    {
        $manager = $this->manager(['read' => [
            ['host' => '10.9.0.6', ...$this->unreachable()],
            ['host' => '10.9.0.7', ...$this->unreachable()],
        ]]);

        $verdict = ReadinessProbe::replicas($manager, 'readiness');

        $this->assertFalse($verdict['available']);
        $this->assertSame(2, $verdict['probed']);
        $this->assertSame(0, $verdict['answered']);
        $this->assertSame(2, $verdict['failed']);
        $this->assertCount(2, $manager->marks, 'a replica that is down is recorded, which is what the next routing decision needs');
    }

    /**
     * A connection with no `read` list has no replica to point at, and that is a configuration fact
     * rather than a probe that went wrong — so it is a note in the verdict, with nothing asked and
     * nothing marked, and the run that reads it exits the same way either way.
     */
    public function test_a_connection_with_no_read_list_has_nothing_to_ask(): void
    {
        $manager = $this->manager([]);

        $verdict = ReadinessProbe::replicas($manager, 'readiness');

        $this->assertFalse($verdict['available']);
        $this->assertSame(0, $verdict['probed']);
        $this->assertSame(0, $verdict['answered']);
        $this->assertSame([], $verdict['hosts']);
        $this->assertSame([], $manager->marks, 'nothing was asked, so nothing was marked');
        $this->assertNotNull($verdict['note'], 'the reason a verdict is unavailable is carried rather than left to the counts');
        $this->assertStringContainsString('[readiness] declares no read list', $verdict['note']);
    }

    /**
     * `read` written as a single config map rather than a list of them is one replica, which is what
     * routing does with it — reading it as nothing would make an installation whose single replica is
     * serving perfectly permanently unable to move to readers.
     */
    public function test_a_read_list_that_is_one_config_map_is_one_replica(): void
    {
        $manager = $this->manager(['read' => ['host' => '10.9.0.5', 'port' => 6432, ...self::REACHABLE]]);

        $verdict = ReadinessProbe::replicas($manager, 'readiness');

        $this->assertTrue($verdict['available'], 'one map is one replica, not no replica');
        $this->assertSame(1, $verdict['probed']);
        $this->assertSame('10.9.0.5', $verdict['hosts'][0]['host']);
        $this->assertSame(6432, $verdict['hosts'][0]['port']);
    }

    /**
     * An entry that is not a replica map is not a host, so there is nothing to ask it — and a list
     * that holds only those is a sweep that reached nothing, which is a note rather than a silent zero.
     * The list is a configuration shape, and the failure belongs to the installation rather than to a
     * replica that was down.
     */
    public function test_a_read_list_that_holds_no_replica_map_reaches_nothing(): void
    {
        $manager = $this->manager(['read' => ['10.9.0.1', '10.9.0.2']]);

        $verdict = ReadinessProbe::replicas($manager, 'readiness');

        $this->assertFalse($verdict['available']);
        $this->assertSame(0, $verdict['probed'], 'a flat host string is not a replica map, so it was never a candidate');
        $this->assertSame([], $manager->marks);
        $this->assertSame([], $verdict['hosts']);
    }

    /**
     * The writer, asked directly, and **not** recorded: the health monitor is a replica registry with
     * no key for the writer, and a mark that named it would be a replica the resolver could then
     * exclude from routing.
     *
     * There is exactly one writer in this deployment, so `answered` is one or zero and `available` is
     * that number — "at least one" and "all" are the same question here.
     */
    public function test_the_writer_is_asked_directly_and_is_not_recorded_as_a_replica(): void
    {
        $manager = $this->manager(['write' => ['host' => '10.9.0.9', 'port' => 5432, ...self::REACHABLE]]);

        $verdict = ReadinessProbe::primary($manager, 'readiness');

        $this->assertSame(ReadinessProbe::TARGET_PRIMARY, $verdict['target']);
        $this->assertTrue($verdict['available']);
        $this->assertSame(1, $verdict['probed']);
        $this->assertSame(1, $verdict['answered']);
        $this->assertSame(0, $verdict['failed']);
        $this->assertSame([['host' => '10.9.0.9', 'port' => 5432, 'healthy' => true, 'error' => null]], $verdict['hosts']);
        $this->assertNull($verdict['note']);

        $this->assertSame([], $manager->marks, 'the health monitor is a replica registry: marking the writer would exclude it from routing');
    }

    /**
     * A connection declares its writer three ways and all three have to be read the way the connector
     * reads them — this is the second and third: a list of writers (the first is the one Laravel uses)
     * and no `write` block at all, in which case the connection's own host is the writer.
     *
     * The third case is the one that is easy to get wrong, because it is not written down anywhere:
     * passing an empty host map to the probe's config merge is what makes it the same call as the
     * other two, and the row it reports is the connection's own address.
     */
    public function test_the_writer_is_read_as_a_list_and_as_the_connections_own_host(): void
    {
        $listed = $this->manager(['write' => [
            ['host' => '10.9.0.9', 'port' => 5432, ...self::REACHABLE],
            ['host' => '10.9.0.10', 'port' => 5432, ...self::REACHABLE],
        ]]);

        $fromList = ReadinessProbe::primary($listed, 'readiness');

        $this->assertSame('10.9.0.9', $fromList['hosts'][0]['host'], 'the first writer is the one Laravel would use');
        $this->assertSame(1, $fromList['probed'], 'and it is the only one asked');

        $own = $this->manager(['host' => '10.9.0.11', 'port' => 6432, ...self::REACHABLE]);

        $fromOwn = ReadinessProbe::primary($own, 'readiness');

        $this->assertTrue($fromOwn['available'], 'with no write block the connection\'s own host is the writer');
        $this->assertSame([['host' => '10.9.0.11', 'port' => 6432, 'healthy' => true, 'error' => null]], $fromOwn['hosts']);
        $this->assertSame([], $own->marks);
    }

    /**
     * A writer that does not answer is the one case where the mode change is refused *before* anything
     * is swapped: the pool keeps serving reads on the configuration it already had, which is the
     * writer it could not reach — a broken writer is not a state a pooler can repair.
     */
    public function test_a_writer_that_does_not_answer_is_not_available(): void
    {
        $manager = $this->manager(['write' => ['host' => '10.9.0.9', ...$this->unreachable()]]);

        $verdict = ReadinessProbe::primary($manager, 'readiness');

        $this->assertFalse($verdict['available']);
        $this->assertSame(0, $verdict['answered']);
        $this->assertSame(1, $verdict['failed']);
        $this->assertIsString($verdict['hosts'][0]['error']);
        $this->assertSame([], $manager->marks, 'the writer is evidence in the report and nothing else');
    }

    /**
     * The mode decides which target is asked, and `readers` is the only mode that points at replicas:
     * everything else — including a mode this class has never heard of — is a mode change that has to
     * know the writer is there, which is the conservative reading.
     */
    public function test_the_mode_decides_the_target(): void
    {
        $this->assertSame(ReadinessProbe::TARGET_REPLICAS, ReadinessProbe::targetFor('readers'));
        $this->assertSame(ReadinessProbe::TARGET_PRIMARY, ReadinessProbe::targetFor('writer'));
        $this->assertSame(ReadinessProbe::TARGET_PRIMARY, ReadinessProbe::targetFor('sideways'));

        $manager = $this->manager([
            'read' => [['host' => '10.9.0.5', 'port' => 6432, ...self::REACHABLE]],
            'write' => ['host' => '10.9.0.9', 'port' => 5432, ...self::REACHABLE],
        ]);

        $this->assertSame(ReadinessProbe::TARGET_REPLICAS, ReadinessProbe::forMode('readers', $manager, 'readiness')['target']);
        $this->assertSame(ReadinessProbe::TARGET_PRIMARY, ReadinessProbe::forMode('writer', $manager, 'readiness')['target']);
    }

    /**
     * The probe is a throwaway connection per candidate, purged after every attempt whatever the
     * attempt did — so nothing a gate measured can be inherited by a request that follows it.
     *
     * The count is what makes the purge per attempt rather than one at the end: `connectUsing(...,
     * force: true)` drops whatever was under the name (the framework's half), and the probe's own
     * `finally` purges it again after the result has been reported, so two candidates leave four.
     */
    public function test_every_candidate_is_probed_on_a_connection_of_its_own_and_purged(): void
    {
        $manager = $this->manager([
            'read' => [
                ['host' => '10.9.0.5', 'port' => 6432, ...self::REACHABLE],
                ['host' => '10.9.0.6', ...$this->unreachable()],
            ],
        ]);

        ReadinessProbe::replicas($manager, 'readiness');

        $this->assertSame(
            array_fill(0, 4, \Uak35\WeightedDbManager\Database\Weighted\SingleHostProbe::CONNECTION),
            $manager->purged,
            'two candidates, one forced connection and one purge each',
        );

        $this->assertArrayNotHasKey(
            \Uak35\WeightedDbManager\Database\Weighted\SingleHostProbe::CONNECTION,
            $this->app->make('db')->getConnections(),
            'a failed probe is exactly when a cached throwaway connection is most likely to be reused',
        );
    }

    /**
     * A connection the configuration does not describe at all is a mistake in the caller rather than a
     * verdict: the gate is asked about a connection name that was resolved from the same config, and a
     * name that resolves to nothing would otherwise be reported as an unavailable target.
     */
    public function test_a_connection_that_is_not_configured_is_not_a_verdict(): void
    {
        $manager = $this->manager([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        $manager->connectionConfig('never-declared');
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Uak35\WeightedDbManager\Pgcat\PoolProbe;

/**
 * The batched front-door sweep: every declared name at once, under one budget.
 *
 * The interesting fact is not that N names are asked — the single exchange already did that in a
 * loop — but that the loop is gone. A pool set of N names used to cost N timeouts when the pooler
 * was silent, so a large configuration made `/health/db` slower the more pools it declared: the
 * health check grew with the thing it was health-checking.
 *
 * `tests/Support/pool-probe-server.php` is the other process. Its `barrier` mode accepts every
 * connection before answering any, which makes a serial sweep fail rather than merely get slower:
 * the first name would wait for an answer that the server will not send until the connections a
 * serial probe never opens arrive. Its `silent` mode accepts every connection and answers none, so
 * the elapsed time is the budget itself rather than the budget counted once per name.
 *
 * The listener is a process rather than a fixture in this one because the probe blocks inside
 * `stream_select()`: a server sharing the process could not accept while the probe waits, and the
 * test would hang instead of failing.
 */
final class PoolProbeSweepTest extends TestCase
{
    /** @var list<Process> */
    private array $servers = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if ($server->isRunning()) {
                $server->stop(1);
            }
        }

        foreach ($this->tempDirs as $dir) {
            @unlink($dir . '/port');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * The barrier: six names are answered in one wave, in the order they were given.
     */
    public function test_every_declared_pool_is_answered_in_one_wave(): void
    {
        [$port, $server] = $this->startServer('barrier', 6);

        $names = ['writer_db_1', 'reader_db_1', 'reader_db_2', 'reader_db_3', 'reader_db_4', 'reader_db_5'];

        $results = PoolProbe::askMany('127.0.0.1', $port, $names, 'postgresAdmin', 5000);

        $this->assertCount(count($names), $results);
        $this->assertSame($names, array_column($results, 'database'), 'the answers line up with the names asked');

        foreach ($results as $result) {
            $this->assertSame(PoolProbe::STATUS_ANSWERED, $result['status']);
            $this->assertTrue($result['answered']);
            $this->assertSame('tcp://127.0.0.1:' . $port, $result['address']);
        }

        $this->assertTrue($server->isRunning(), 'the fixture answered because every connection arrived together');
    }

    /**
     * The same wave carries a refusal: a missing pool is classified beside the answered ones, with
     * pgcat's own sentence, rather than turning the whole batch into silence.
     */
    public function test_a_missing_pool_is_classified_beside_the_answers(): void
    {
        [$port] = $this->startServer('barrier', 3);

        $results = PoolProbe::askMany(
            '127.0.0.1',
            $port,
            ['reader_db_1', 'missing_reader', 'writer_db_1'],
            'postgresAdmin',
            5000,
        );

        $this->assertSame(
            [PoolProbe::STATUS_ANSWERED, PoolProbe::STATUS_MISSING, PoolProbe::STATUS_ANSWERED],
            array_column($results, 'status'),
        );

        $this->assertStringContainsString('missing_reader', $results[1]['evidence']);
        $this->assertStringContainsString('no pool', strtolower($results[1]['evidence']));
    }

    /**
     * The budget is the sweep's, not each name's: a pooler that accepts every connection and says
     * nothing costs one timeout. Eight names at 400ms are silent in well under the 3.2s a serial
     * sweep would spend, and the bound is loose enough for a slow runner to still be about the
     * budget rather than about the clock.
     */
    public function test_one_budget_covers_a_silent_sweep(): void
    {
        [$port] = $this->startServer('silent', 8);

        $names = [];

        for ($index = 0; $index < 8; $index++) {
            $names[] = 'reader_db_' . $index;
        }

        $started = microtime(true);
        $results = PoolProbe::askMany('127.0.0.1', $port, $names, 'postgresAdmin', 400);
        $elapsed = microtime(true) - $started;

        $this->assertCount(8, $results);

        foreach ($results as $result) {
            $this->assertSame(PoolProbe::STATUS_SILENT, $result['status']);
            $this->assertStringContainsString('did not answer for pool', $result['evidence']);
        }

        $this->assertGreaterThanOrEqual(0.35, $elapsed, 'the sweep waited for its budget rather than answering early');
        $this->assertLessThan(1.6, $elapsed, 'one budget covered the sweep; a per-name budget would be eight times this');
    }

    /**
     * The answers line up with the names, one for one: a repeated name is asked twice, because a
     * caller that passed it twice is reading positionally, and an empty name is not a question at
     * all.
     */
    public function test_an_empty_name_is_not_asked_and_a_repeat_keeps_its_place(): void
    {
        [$port, $server] = $this->startServer('barrier', 2);

        $results = PoolProbe::askMany('127.0.0.1', $port, ['reader_db_1', '', 'reader_db_1'], 'postgresAdmin', 5000);

        $this->assertCount(2, $results);
        $this->assertSame(['reader_db_1', 'reader_db_1'], array_column($results, 'database'));
        $this->assertSame(PoolProbe::STATUS_ANSWERED, $results[0]['status']);
        $this->assertSame(PoolProbe::STATUS_ANSWERED, $results[1]['status']);
        $this->assertTrue($server->isRunning());
    }

    /**
     * An address that refuses the connect is silence with a reason, not a hang and not a batch that
     * loses the other names: every entry still comes back, with the address and the socket's own
     * account of why it never opened.
     */
    public function test_a_refused_connection_is_silent_and_says_so(): void
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($listener, 'a loopback listener to take a free port from');

        $name = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($listener);

        $results = PoolProbe::askMany('127.0.0.1', $port, ['reader_db_1', 'reader_db_2'], 'postgresAdmin', 1000);

        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertSame(PoolProbe::STATUS_SILENT, $result['status']);
            $this->assertStringContainsString('tcp://127.0.0.1:' . $port, $result['evidence']);
        }
    }

    /**
     * Nothing to ask is not a connection to open — the caller may hand the sweep whatever the
     * variant declared, including nothing.
     */
    public function test_an_empty_sweep_opens_no_connection(): void
    {
        $this->assertSame([], PoolProbe::askMany('127.0.0.1', 1, [], 'postgresAdmin', 400));
    }

    /**
     * Start the fixture and wait for the port it chose.
     *
     * The server binds port 0 and writes the port it got to a file, so the test never guesses a
     * free port and never collides with another run. A process that exits before writing it is
     * reported with its own stderr, rather than as a missing file.
     *
     * @return array{0: int, 1: Process}
     */
    private function startServer(string $mode, int $expect): array
    {
        $dir = sys_get_temp_dir() . '/swrr-pool-sweep-' . bin2hex(random_bytes(6));

        if (! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;
        $portFile = $dir . '/port';

        $server = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/tests/Support/pool-probe-server.php',
            $mode,
            (string) $expect,
            $portFile,
        ]);

        $server->setTimeout(30);
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            if (! $server->isRunning()) {
                $this->fail(sprintf(
                    "the pool-probe fixture exited before it listened (mode %s, exit %s): %s",
                    $mode,
                    (string) $server->getExitCode(),
                    trim($server->getErrorOutput() . $server->getOutput()),
                ));
            }

            if (is_file($portFile)) {
                $port = (int) trim((string) file_get_contents($portFile));

                if ($port > 0) {
                    return [$port, $server];
                }
            }

            usleep(20_000);
        }

        $this->fail("the pool-probe fixture never wrote a port (mode {$mode})");
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Tests\Support\FakeWeightedManager;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The exit code of `db:probe-replicas` as a function of what the sweep reached.
 *
 * This is the shape `db:doctor` and `db:pgcat-flip` are tested in, for the reason the
 * command's own docblock gives: the exit code is what a scheduler reports, and "it exits 1
 * when nothing could be reached" is true only of the cases somebody wrote down. A scheduled
 * command that exits 1 for a healthy installation stops being read, so the asymmetry the
 * matrix pins — a connection with no replicas is a success, a sweep that reached no replica
 * is not — is the whole point of writing the rows down.
 *
 * The axes are the four ways the run can end:
 *
 *   • the guard before the sweep: there is no weighted manager to probe with (1);
 *   • nothing to probe: the connection has no read list at all (0 — nothing was asked of it);
 *   • a sweep that reached nothing: no entry is a replica map (1), or every replica failed (1);
 *   • a sweep that reached something: some answered (0), all answered (0).
 *
 * The counts alone cannot tell "every replica failed" from "there was nothing probeable", so
 * each row also asserts the sentence the code follows from the row it is in — and the marks
 * that reached the health monitor, because the real job of this command is invisible: the
 * probe is a throwaway connection, purged in a `finally`, and nothing about it survives in
 * the output. A probe that quietly stopped reporting would still print the same summary.
 *
 * Every row probes a SQLite transport, so the probe really opens a connection and really runs
 * its `SELECT 1`. A host that is not there cannot be used for that — a test would be waiting
 * on a socket — and an entry pointing at a SQLite file that does not exist fails at the same
 * point for the same kind of reason: the connector refuses before a query is sent. The marks
 * still carry PostgreSQL-shaped host:port addresses, because that is the key the resolver
 * routes on and the thing a row has to prove travelled.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbDoctorTest::test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbFlipPgcatCommandTest::test_the_exit_code_is_a_function_of_what_the_flip_would_do
 */
final class DbProbeReplicasCommandTest extends TestCase
{
    /** The throwaway connection the command opens for a probe. */
    private const PROBE = '__weighted_db_probe';

    /** The port a replica is tracked under when its own config does not name one. */
    private const DEFAULT_PORT = 5433;

    /** A replica whose `SELECT 1` really runs — SQLite in memory answers it. */
    private const GOOD = ['host' => '10.9.0.5', 'port' => 6432];

    /**
     * Each row declares the evidence its exit code is supposed to follow from: the sentence
     * the run has to carry, the sentence it must not, and every result that had to reach the
     * health monitor. A row whose fixture drifted fails on its own line instead of leaving
     * the exit code standing as evidence about a question nobody asked.
     *
     * @return array<string, array{
     *     preset: string,
     *     connection: string|null,
     *     exit: int,
     *     line: string,
     *     absent: string,
     *     marks: list<array{host: string, port: int, healthy: bool, reason: string|null}>
     * }>
     */
    public static function exitCodeProvider(): array
    {
        $healthy = static fn (string $host, int $port): array => [
            'host' => $host, 'port' => $port, 'healthy' => true, 'reason' => null,
        ];

        $failed = static fn (string $host, int $port): array => [
            'host' => $host, 'port' => $port, 'healthy' => false, 'reason' => 'active probe',
        ];

        return [
            // ── the guard before the sweep ────────────────────────────────────────────
            'the manager is not the weighted one, so there was no sweep' => [
                'preset' => 'unweighted', 'connection' => 'probe_writer', 'exit' => 1,
                'line' => 'WeightedDatabaseManager is not registered.',
                // The counterfactual: if the guard did not come first, this connection
                // would have been asked for its read list and found none — which exits 0.
                'absent' => 'No replicas configured',
                'marks' => [],
            ],

            // ── nothing to probe ─────────────────────────────────────────────────────
            'the connection asked for has no read list' => [
                'preset' => 'no-read-list', 'connection' => 'probe_writer', 'exit' => 0,
                'line' => 'No replicas configured for [probe_writer].',
                'absent' => 'No replica answered',
                'marks' => [],
            ],
            'the connection the command defaults to has no read list' => [
                'preset' => 'no-read-list', 'connection' => null, 'exit' => 0,
                'line' => 'No replicas configured for [pgsql].',
                'absent' => 'No replica answered',
                'marks' => [],
            ],

            // ── a sweep that reached nothing ──────────────────────────────────────────
            'the read list holds no replica map, so nothing could be probed' => [
                'preset' => 'no-replica-array', 'connection' => 'probe_flat', 'exit' => 1,
                'line' => 'Nothing could be probed: the read list on [probe_flat] holds no replica array, so nothing was marked healthy.',
                // Nothing was probed, so nothing failed: this row is not the all-failed one.
                'absent' => 'No replica answered',
                'marks' => [],
            ],
            'every replica fails, so the sweep reached nothing' => [
                'preset' => 'all-fail', 'connection' => 'probe_dead', 'exit' => 1,
                'line' => 'No replica answered: 2 probed, all failed — nothing was marked healthy.',
                'absent' => 'Nothing could be probed',
                'marks' => [$failed('10.9.0.6', self::DEFAULT_PORT), $failed('10.9.0.7', 6432)],
            ],

            // ── a sweep that reached something ────────────────────────────────────────
            'one replica fails and one answers, which is still a sweep' => [
                'preset' => 'partial', 'connection' => 'probe_partial', 'exit' => 0,
                'line' => 'Probed probe_partial: 1 healthy, 1 failed',
                'absent' => 'No replica answered',
                'marks' => [$healthy('10.9.0.5', 6432), $failed('10.9.0.6', self::DEFAULT_PORT)],
            ],
            'every replica answers' => [
                'preset' => 'all-answer', 'connection' => 'probe_all', 'exit' => 0,
                'line' => 'Probed probe_all: 2 healthy, 0 failed',
                'absent' => 'No replica answered',
                'marks' => [$healthy('10.9.0.5', 6432), $healthy('10.9.0.8', self::DEFAULT_PORT)],
            ],

            // ── shapes of `read` that are not a list of replica maps ─────────────────
            'a read list that is one config map is one replica, and it is probed' => [
                'preset' => 'single-map', 'connection' => 'probe_single', 'exit' => 0,
                'line' => 'Probed probe_single: 1 healthy, 0 failed',
                'absent' => 'Nothing could be probed',
                'marks' => [$healthy('10.9.0.5', 6432)],
            ],
            'an entry that is not a replica map beside one that is' => [
                'preset' => 'mixed-entries', 'connection' => 'probe_mixed', 'exit' => 0,
                'line' => 'Probed probe_mixed: 1 healthy, 0 failed',
                'absent' => 'No replica answered',
                // The counts are of replicas, not of list entries: a flat host string is not
                // a replica map, so it is neither healthy nor failed — it was never a host.
                'marks' => [$healthy('10.9.0.5', 6432)],
            ],
        ];
    }

    /**
     * The provider's keys are this method's parameter names: PHPUnit passes them as named
     * arguments, so a row that says `exit` is a row whose expected code is asserted.
     *
     * @param list<array{host: string, port: int, healthy: bool, reason: string|null}> $marks
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_what_the_sweep_reached(
        string $preset,
        ?string $connection,
        int $exit,
        string $line,
        string $absent,
        array $marks,
    ): void {
        $this->layOut($preset, $connection);
        $manager = $this->bindManager($preset);

        $actual = Artisan::call(
            'db:probe-replicas',
            $connection === null ? [] : ['connection' => $connection],
        );
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "Exit code for the {$preset} row:\n".$output);

        // The row says what happened, and — the other half — does not claim what did not.
        $this->assertStringContainsString($line, $output, "The {$preset} row has to say what its exit code means");
        $this->assertStringNotContainsString($absent, $output, "The {$preset} row must not blame something that did not happen");

        // What the sweep found, asserted where it lands: a probe that stopped reporting would
        // print the same summary and pass everything above.
        $this->assertSame($marks, $manager === null ? [] : $manager->marks, "The {$preset} row reported different results to the health monitor");

        // Nothing left cached: the throwaway connection is what keeps a probe from measuring
        // the pool it is trying to measure, so a sweep must not end holding one.
        $this->assertArrayNotHasKey(self::PROBE, $this->app->make('db')->getConnections(), 'the throwaway connection outlived the probe');

        // Two purges per probe, and the second one is the command's own. Opening the name is
        // `connectUsing(..., force: true)`, which drops any connection already under it — the
        // framework's half — and the command purges it again in a `finally`, after the result
        // has been reported. A sweep of two replicas therefore leaves four and not three: drop
        // the `finally` and a failing probe would be the one connection that stays cached.
        $this->assertSame(
            array_fill(0, 2 * count($marks), self::PROBE),
            $manager === null ? [] : $manager->purged,
            'a probe purges the throwaway connection once per attempt, after it reports',
        );
    }

    /**
     * The README row each cell of the matrix is an instance of.
     *
     * The table is written for an operator and the matrix for the code, so they do not line
     * up one to one: six cells are instances of one documented case (anything where some
     * replica answered), and the read list that is not there is asked about twice. This map
     * is the correspondence between the two, and it is the only thing either side has to
     * keep in step — `test_the_matrix_agrees_with_the_readme_exit_table()` reads the table
     * and fails when the two disagree in either direction.
     *
     * @return array<string, string> key of exitCodeProvider() => the README row it documents
     */
    private static function documentedSituations(): array
    {
        return [
            'the manager is not the weighted one, so there was no sweep' => 'the container has no weighted manager',
            'the connection asked for has no read list' => 'the connection has no read list at all',
            'the connection the command defaults to has no read list' => 'the connection has no read list at all',
            'the read list holds no replica map, so nothing could be probed' => 'no entry in read is a replica map',
            'every replica fails, so the sweep reached nothing' => 'every replica failed',
            'one replica fails and one answers, which is still a sweep' => 'at least one replica answered',
            'every replica answers' => 'at least one replica answered',
            'a read list that is one config map is one replica, and it is probed' => 'at least one replica answered',
            'an entry that is not a replica map beside one that is' => 'at least one replica answered',
        ];
    }

    /**
     * The table an operator reads is the rule the matrix enforces.
     *
     * The matrix pins what the command does, cell by cell; this pins that the README still
     * says so. Two claims are checked, and they cover the table in both directions: every
     * row of the matrix is assigned a documented case (so a new cell cannot arrive without
     * being documented), and the cases the matrix is written as are exactly the rows the
     * table has (so a documented case cannot lose its last cell, and a row cannot be reworded
     * or renumbered without this test being revisited). The codes themselves are compared
     * per cell, because a table that lists a case and a different number is the drift that
     * matters: an operator schedules on the number.
     */
    public function test_the_matrix_agrees_with_the_readme_exit_table(): void
    {
        $rows = Readme::table('### Probing: `db:probe-replicas`', 'exit');
        $situations = self::documentedSituations();

        $this->assertEqualsCanonicalizing(
            array_keys(self::exitCodeProvider()),
            array_keys($situations),
            'every row of the matrix names the README row it is an instance of, and nothing else',
        );

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_values(array_unique($situations))),
            array_map([Readme::class, 'plain'], array_column($rows, 'what the sweep found')),
            'the README documents a case the matrix is not written as, or the matrix is written as one the README does not document',
        );

        foreach (self::exitCodeProvider() as $name => $cell) {
            $label = $situations[$name] ?? $this->fail("No README row is assigned to the [{$name}] cell.");
            $documented = Readme::row($rows, 'what the sweep found', $label);

            $this->assertSame(
                $cell['exit'],
                Readme::code($documented['exit']),
                sprintf(
                    'The README documents [%s] as "%s", while the matrix asserts %d.',
                    $label,
                    $documented['exit'],
                    $cell['exit'],
                ),
            );
        }
    }

    /**
     * The per-replica rows: the half of the output an operator needs when the exit code is 1
     * and the question is which host to go and look at.
     *
     * They are behind `-v` because this command is built to run every thirty seconds, and a
     * line per replica on a schedule is a log nobody reads. So the default run has to be
     * quiet, and the detail has to be there exactly when asked for — including the reason a
     * replica failed, which is the one thing the summary counts but cannot name.
     */
    public function test_the_per_replica_rows_are_printed_only_when_asked_for(): void
    {
        $this->layOut('partial', 'probe_partial');
        $this->bindManager('partial');

        Artisan::call('db:probe-replicas', ['connection' => 'probe_partial']);
        $quiet = Artisan::output();

        Artisan::call('db:probe-replicas', ['connection' => 'probe_partial', '-v' => true]);
        $verbose = Artisan::output();

        $this->assertStringContainsString('Probed probe_partial: 1 healthy, 1 failed', $quiet);
        $this->assertStringNotContainsString('10.9.0.6:5433', $quiet, 'a scheduled run does not write a row per replica');

        $this->assertStringContainsString('✓ 10.9.0.5:6432', $verbose);
        $this->assertStringContainsString('✗ 10.9.0.6:5433', $verbose);
        $this->assertStringContainsString('does not exist', $verbose, 'the row names why the probe failed');
    }

    /**
     * The invariant behind the throwaway connection, asserted where it is decided: the probe
     * config has no read list, so WeightedConnectionFactory's reader-window and SWRR routing
     * never run for it.
     *
     * Without the `read`/`write` keys being dropped the probe would be a pooled connection,
     * and a pooled connection is the one thing a measurement of a single replica cannot be —
     * it would measure whichever host the pool picked, not the host the row named. The
     * factory is the only place that difference is observable, because both configs would
     * open a working connection and report the same mark.
     */
    public function test_a_probe_is_never_routed_through_the_pool_it_is_measuring(): void
    {
        config()->set('database.connections.probe_pool', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'port' => self::DEFAULT_PORT,
            'read' => [
                ['host' => '10.9.0.5', 'port' => 6432, 'cpu_cores' => 8, 'ram_gb' => 32],
                ['host' => '10.9.0.8', 'cpu_cores' => 8, 'ram_gb' => 32],
            ],
            'write' => ['host' => '10.9.0.9'],
        ]);

        $factory = new class ($this->app) extends WeightedConnectionFactory {
            /** How many times the pool was asked to pick a replica. */
            public int $picks = 0;

            protected function getReadConfig($config)
            {
                $this->picks++;

                return parent::getReadConfig($config);
            }
        };

        $this->app->instance('db.factory', $factory);

        $manager = new FakeWeightedManager($this->app, $factory);
        $this->app->instance('db', $manager);

        $this->assertSame(0, Artisan::call('db:probe-replicas', ['connection' => 'probe_pool']), Artisan::output());

        $this->assertSame(0, $factory->picks, 'the probe ran on a connection with no read list, so the pool never picked');
        $this->assertSame([
            ['host' => '10.9.0.5', 'port' => 6432, 'healthy' => true, 'reason' => null],
            ['host' => '10.9.0.8', 'port' => self::DEFAULT_PORT, 'healthy' => true, 'reason' => null],
        ], $manager->marks);
    }

    /**
     * Lay out the connection a row probes: a SQLite transport, so the probe really connects,
     * wrapped around the `read` list that is the row's question.
     *
     * The name is the one the row passes, or `pgsql` when it passes none — the argument's own
     * default, which is the connection `db:probe-replicas` sweeps unless it is told otherwise.
     */
    private function layOut(string $preset, ?string $connection): void
    {
        $base = ['driver' => 'sqlite', 'database' => ':memory:', 'port' => self::DEFAULT_PORT];
        $missing = $this->missingDatabasePath();

        $config = match ($preset) {
            // The guard comes first, so this row's read list is never reached — which is the
            // claim, and the assertion that proves it is the exit code plus `absent`.
            'unweighted',
            'no-read-list' => $base,
            'no-replica-array' => $base + ['read' => ['10.9.0.1', '10.9.0.2']],
            'single-map' => $base + ['read' => self::GOOD],
            'all-fail' => $base + ['read' => [
                ['host' => '10.9.0.6', 'database' => $missing],
                ['host' => '10.9.0.7', 'port' => 6432, 'database' => $missing],
            ]],
            'partial' => $base + ['read' => [
                self::GOOD,
                ['host' => '10.9.0.6', 'database' => $missing],
            ]],
            'all-answer' => $base + ['read' => [self::GOOD, ['host' => '10.9.0.8']]],
            'mixed-entries' => $base + ['read' => [self::GOOD, '10.9.0.9']],
            default => throw new RuntimeException("Unknown probe preset [{$preset}]"),
        };

        config()->set('database.connections.'.($connection ?? 'pgsql'), $config);
    }

    /**
     * A path that is never created, so a replica pointed at it fails in the connector the way
     * a host that is not listening fails in the driver. Nothing is written and nothing has to
     * be cleaned up: the failure happens before a file is opened.
     */
    private function missingDatabasePath(): string
    {
        return sys_get_temp_dir().'/swrr-probe-missing-'.bin2hex(random_bytes(4)).'/dead.sqlite';
    }

    /**
     * Bind the manager the command resolves. Everything but the guard row is the real
     * manager with its two reporting calls recorded — the probe still really connects — and
     * the guard row is a plain manager, because that is the shape the guard is about.
     */
    private function bindManager(string $preset): ?FakeWeightedManager
    {
        if ($preset === 'unweighted') {
            $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));

            return null;
        }

        $manager = new FakeWeightedManager($this->app, $this->app->make('db.factory'));

        $this->app->instance('db', $manager);

        return $manager;
    }
}

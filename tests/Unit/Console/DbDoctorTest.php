<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Uak35\WeightedDbManager\Database\Weighted\AtomicStateStore;
use Uak35\WeightedDbManager\Database\Weighted\LocalStateStore;
use Uak35\WeightedDbManager\Database\Weighted\RedisAtomicStateStore;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleBeat;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigSchema;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\DeploymentContract;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;
use Uak35\WeightedDbManager\Tests\Support\FakeRedis;
use Uak35\WeightedDbManager\Tests\Support\FakeSupervisor;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The doctor earns its keep by being believable, so every way an installation can
 * boot and still misbehave is pinned to the row it produces.
 *
 * The store check gets the most attention because it is the one that was wrong:
 * the store's own health flag is only lowered once an operation has already
 * failed, so on a fresh process it says "nothing has gone wrong yet" — which is
 * not the same claim as "the store is reachable". The difference is a Redis that
 * is unreachable while the installation still looks configured, so the check pings
 * for real, and these tests drive that ping in both directions.
 *
 * Output is read from a real BufferedOutput rather than through the console mocks,
 * because the rows carry colour tags: asserting on what an operator actually sees
 * keeps these tests honest about the rendering too.
 */
class DbDoctorTest extends TestCase
{
    /**
     * The report's published keys, in the order `report()` writes them.
     *
     * A constant rather than a literal at each assertion, because the claim worth pinning is
     * that the key set is *fixed*: a field that is present on some runs and absent on others is
     * a rule a gate can write wrong once and keep.
     *
     * @var list<string>
     */
    private const JSON_KEYS = [
        'command',
        'connection',
        'swrr_connection',
        'default_connection',
        'strict',
        'connection_only',
        'real_test',
        'verdict',
        'exit_code',
        'counts',
        'checks',
    ];

    /** @var list<string> */
    private array $tempDirs = [];

    /** The configuration cache a test claimed the boot read from, deleted in `tearDown()`. */
    private ?string $configCache = null;

    protected function tearDown(): void
    {
        // A test that claimed the configuration was cached wrote a cache file where the framework
        // looks for one, and the next test's boot must not find it there.
        if ($this->configCache !== null) {
            @unlink($this->configCache);

            $this->configCache = null;
        }

        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function test_it_fails_the_store_check_when_the_store_does_not_answer_the_ping(): void
    {
        $redis = $this->bindRedis(reachable: false);
        $this->useRedisConnection('cache');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store reachability');

        $this->assertStringStartsWith('FAIL  store reachability', $row);
        $this->assertStringContainsString('[redis(cache)]', $row);
        $this->assertStringContainsString('could not serve a read', $row);
        $this->assertStringContainsString('Connection refused', $row);
        $this->assertSame(1, $exit);

        // Probed swrr.redis_connection rather than a hardcoded 'default'.
        $this->assertSame(['cache'], $redis->asked);
    }

    public function test_it_passes_the_store_check_when_the_ping_is_answered(): void
    {
        $redis = $this->bindRedis(reachable: true);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'store reachability');

        $this->assertStringStartsWith('PASS  store reachability', $row);
        $this->assertStringContainsString('[redis(default)] answered a PING', $row);

        $this->assertSame(['default'], $redis->asked);
    }

    public function test_a_store_that_already_failed_is_reported_without_being_probed(): void
    {
        $redis = $this->bindRedis(reachable: true);
        $this->app->make('db')->setStore($this->deadStore());

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store reachability');

        $this->assertStringStartsWith('FAIL  store reachability', $row);
        $this->assertStringContainsString('failed in this process', $row);
        $this->assertSame(1, $exit);

        // The store already told us, so there is nothing to ask Redis.
        $this->assertSame([], $redis->asked);
    }

    public function test_an_in_process_primary_store_warns_without_being_probed(): void
    {
        $redis = $this->bindRedis(reachable: true);

        config()->set('db-manager.swrr.primary_store', 'local');
        $this->rebuildManager();

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'store reachability');

        $this->assertStringStartsWith('WARN  store reachability', $row);
        $this->assertStringContainsString('not shared between workers', $row);
        $this->assertSame([], $redis->asked);

        // The warning has to describe the store the installation really built.
        $summary = DB::getFacadeRoot()->healthSummary(self::CONNECTION);

        $this->assertSame('local(in-process, uncoordinated)', $summary['primary_store']);
    }

    public function test_it_fails_the_two_swap_checks_when_weighting_is_not_wired_in(): void
    {
        // The provider is registered, but something else has replaced `db` and
        // `db.factory` — the state where reads are unweighted and nothing says so.
        $this->app->instance('db.factory', new ConnectionFactory($this->app));
        $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        DB::clearResolvedInstance('db');

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  provider swap', $this->rowContaining($output, 'provider swap'));
        $this->assertStringContainsString('db is not the weighted manager', $output);
        $this->assertStringStartsWith('FAIL  weighted factory', $this->rowContaining($output, 'weighted factory'));
        $this->assertStringContainsString('replica selection happens there', $output);
        $this->assertSame(1, $exit);
    }

    /**
     * The class that builds the connection, which configuration cannot show.
     *
     * Laravel takes a `db.connector.<driver>` binding before its own map, so an application
     * that replaces the PostgreSQL connector replaces it for every connection this package
     * builds — and that class is often load-bearing, because it is where an application adds
     * the option path its own connection config is written with. Reading as documented while
     * building through somebody else's class is the state this row makes visible, and the
     * question it answers is the one `weighted factory` stops short of: that row names the
     * class of the binding that builds connections, this one names the connector they are
     * built through.
     */
    public function test_the_connector_row_names_the_class_the_connection_is_built_through(): void
    {
        [$output] = $this->doctor();

        $row = $this->checkNamed($this->report($this->doctorJson()[0]), 'connector');

        $this->assertSame('PASS', $row['verdict']);
        $this->assertSame(
            'connection ['.self::CONNECTION.'] (driver pgsql) is built by '.PostgresConnector::class
            .' — the framework\'s own connector for that driver: nothing is bound at db.connector.pgsql'
            .'; the connection resolves to '.PostgresConnection::class
            .' — no connection resolver is registered for pgsql, so the class is the framework\'s own',
            $row['detail'],
        );
        $this->assertSame([], $row['suggestions'], 'the repair is a binding, and a binding is not a config line');
        $this->assertStringStartsWith('PASS  connector', $this->rowContaining($output, 'connector'));
    }

    /**
     * The replacement itself: a class the installation registered, named with the key that
     * answered it. Deliberately a pass — replacing the connector is the framework's own
     * extension point and the ordinary way an application adds to it, so warning here would
     * warn on the installations doing the right thing. What the row is for is being able to
     * see which class is in use without opening a repository.
     */
    public function test_the_connector_row_names_an_installations_own_connector_and_its_binding(): void
    {
        $replacement = new class () extends PostgresConnector {};
        $this->app->instance('db.connector.pgsql', $replacement);

        [$output] = $this->doctor();

        $row = $this->checkNamed($this->report($this->doctorJson()[0]), 'connector');

        $this->assertSame('PASS', $row['verdict']);
        $this->assertSame(
            'connection ['.self::CONNECTION.'] (driver pgsql) is built by '.$replacement::class
            .' — resolved from the db.connector.pgsql binding, an installation\'s connector in place '
            .'of the framework\'s own'
            .'; the connection resolves to '.PostgresConnection::class
            .' — no connection resolver is registered for pgsql, so the class is the framework\'s own',
            $row['detail'],
        );
        $this->assertStringStartsWith('PASS  connector', $this->rowContaining($output, 'connector'));
    }

    /**
     * The half of a connection the connector cannot answer: the class it is built as.
     *
     * `Connection::resolverFor()` is consulted by `createConnection()` before its own map, and it
     * is a global registry rather than a container binding — the connector makes the PDO, the
     * resolver makes the object around it. A row that stopped at the factory named the connector
     * and left the class to whoever opened a repository; this one names the class, says an
     * installation's resolver is registered and that the connection was built through it.
     *
     * Deliberately a pass, like a replaced connector: the registry is the framework's extension
     * point, and what the row is for is seeing which class is in effect.
     */
    public function test_the_connector_row_names_the_connection_class_an_installations_resolver_built(): void
    {
        // A connection class of the test's own: a replacement is any class at all, and the row
        // is read for the name PHP gives it. Built once to learn that name.
        $class = (new class ('', 'database', '', ['driver' => 'pgsql']) extends PostgresConnection {})::class;

        $resolvers = self::resolvers();

        try {
            Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) => new $class($pdo, $database, $prefix, $config));

            // Both runs happen inside the registry's lifetime: the row is read from the same
            // installation the table was rendered from, not from a second doctor run after the
            // resolver has been put back.
            [$output] = $this->doctor();
            $row = $this->checkNamed($this->report($this->doctorJson()[0]), 'connector');
        } finally {
            self::restoreResolvers($resolvers);
        }

        $this->assertSame('PASS', $row['verdict']);
        $this->assertStringContainsString(
            'the connection resolves to '.$class
            .' — an installation\'s connection resolver for pgsql is registered, and this connection was built through it',
            $row['detail'],
        );
        $this->assertStringContainsString(PostgresConnector::class, $row['detail'], 'the connector is a separate answer and is still named');
        $this->assertStringStartsWith('PASS  connector', $this->rowContaining($output, 'connector'));
    }

    /**
     * A connection the application built before the resolver was registered. The registry says a
     * replacement is in place; the object the application holds is still the framework's class,
     * and only reading the resolved connection can tell the two apart. The row says which it is
     * rather than failing: the replacement is not missing, it has not reached this connection —
     * which is what a boot-time build followed by a later registration produces.
     */
    public function test_the_connector_row_reports_a_connection_built_before_the_resolver(): void
    {
        $this->bindRedis(reachable: true);

        // The connection in use, built while nothing was replaced.
        $inUse = $this->app['db']->connection(self::CONNECTION);

        $this->assertSame(PostgresConnection::class, $inUse::class);

        // A connection class of the test's own: a replacement is any class at all, and the row
        // is read for the name PHP gives it.
        $class = (new class ('', 'database', '', ['driver' => 'pgsql']) extends PostgresConnection {})::class;

        $resolvers = self::resolvers();

        try {
            Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) => new $class($pdo, $database, $prefix, $config));

            [$output] = $this->doctor();
            $row = $this->checkNamed($this->report($this->doctorJson()[0]), 'connector');
        } finally {
            self::restoreResolvers($resolvers);
        }

        $this->assertSame('PASS', $row['verdict']);
        $this->assertStringContainsString(
            'the connection in use resolves to '.PostgresConnection::class
            .' — an installation\'s connection resolver for pgsql is registered, but this connection was not built through it, '
            .'so the class is the framework\'s own',
            $row['detail'],
        );
        $this->assertStringStartsWith('PASS  connector', $this->rowContaining($output, 'connector'));
    }

    /**
     * The failure: a connection nothing can build. A name `swrr.connection` no configuration
     * declares is a typo away, and until this row nothing said so — the gate only asks what
     * pgcat can front, and only while it is armed. The sentence names the setting the name came
     * from, so the repair is a value rather than a search, and carries the framework's own
     * refusal rather than a second way of saying it.
     */
    public function test_the_connector_row_fails_when_nothing_can_build_the_connection(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        config()->set('db-manager.swrr.connection', 'weighted_typo');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $report = $this->report($this->doctorJson()[0]);
        $row = $this->checkNamed($report, 'connector');

        $this->assertSame('FAIL', $row['verdict']);
        $this->assertSame(
            'connection [weighted_typo] (db-manager.swrr.connection) resolves no connector: '
            .'A driver must be specified.',
            $row['detail'],
        );
        $this->assertStringStartsWith('FAIL  connector', $this->rowContaining($output, 'connector'));

        $failed = array_values(array_map(
            static fn (array $check): string => (string) $check['name'],
            array_filter($report['checks'], static fn (array $check): bool => $check['verdict'] === 'FAIL'),
        ));

        $this->assertSame(['connector'], $failed, 'this row is the run\'s only failure, so the code is its');
        $this->assertSame(1, $exit);
    }

    /**
     * A `db.factory` that cannot be asked. The binding is the framework's extension point for
     * the whole class, so a reading that guessed past it would describe a factory this
     * installation does not have — and its own fault is `weighted factory`'s to report.
     * One cause, one failure: the tally is what a deploy reads.
     */
    public function test_the_connector_row_leaves_an_unaskable_factory_to_the_weighted_factory_row(): void
    {
        $this->app->instance('db.factory', new stdClass());

        [$output] = $this->doctor();

        $row = $this->checkNamed($this->report($this->doctorJson()[0]), 'connector');

        $this->assertSame('PASS', $row['verdict'], 'a reading that was not taken is not a second failure');
        $this->assertSame(
            'db.factory stdClass is not a ConnectionFactory, so which connector builds ['
            .self::CONNECTION.'] is not read here — the weighted factory row is where that binding is judged',
            $row['detail'],
        );
        $this->assertStringStartsWith('FAIL  weighted factory', $this->rowContaining($output, 'weighted factory'));
    }

    public function test_it_fails_the_driver_gate_when_pgcat_is_switched_on_where_it_cannot_act(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat gate');

        $this->assertStringStartsWith('FAIL  pgcat gate', $row);
        $this->assertStringContainsString('pgcat will never act', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_gate_row_reports_when_a_recorded_mismatch_was_first_seen(): void
    {
        // The mismatch the row already fails on, plus the record a previous boot left.
        // The verdict does not change and the age is new: it is what tells a release
        // whether it is looking at its own change or one it inherited.
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $this->recordGateMismatch('mysql_app', 'mysql', '2026-09-20T08:15:00+00:00');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat gate');

        $this->assertStringStartsWith('FAIL  pgcat gate', $row);
        $this->assertStringContainsString('pgcat will never act', $row);
        $this->assertStringContainsString(
            'recorded unresolved since 2026-09-20T08:15:00+00:00',
            $row,
        );
        $this->assertSame(1, $exit);
    }

    /**
     * The mismatch sentence already names its repair in prose, because the flip's refusal and
     * `--dry-run` print that same sentence and have no suggestion column. The line is the half
     * of it a report can be acted on without reading English — and a job can select on it, which
     * a clause inside a sentence cannot be.
     */
    public function test_the_gate_row_prints_the_switch_as_the_line_to_paste(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat gate');

        $this->assertStringStartsWith('FAIL  pgcat gate', $row);
        $this->assertStringContainsString('pgcat will never act', $row, 'the sentence is still there, and still names both repairs');
        $this->assertSame(['swrr.pgcat.enabled = false'], $this->repairs($output));
        $this->assertSame(1, $exit, 'a repair is not a verdict: the gate still fails');
    }

    /**
     * The gate fails two ways and only one of them has a value to paste. Arming pgcat without a
     * path a flip needs names keys whose values belong to the installation — this machine's
     * pgcat lives wherever it lives — so the row names the fault and stops, and so does the
     * `pgcat files` row beside it, whose every problem is a path or a permission.
     *
     * This is the line `ReaderWindows::suggestion()` draws for a refused window, applied to a
     * whole row: no line rather than a plausible-looking one, because a guess pasted into
     * configuration replaces the operator's intent with this tool's.
     */
    public function test_the_gate_and_files_rows_print_no_line_for_a_path_only_the_installation_knows(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat(['no_readers_path' => '']);

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  pgcat gate', $this->rowContaining($output, 'pgcat gate'));
        $this->assertStringContainsString(
            'set swrr.pgcat.no_readers_path',
            $this->rowContaining($output, 'pgcat gate'),
            'the row names the key it wants filled in',
        );
        $this->assertStringStartsWith('FAIL  pgcat files', $this->rowContaining($output, 'pgcat files'));
        $this->assertSame([], $this->suggestions($output), 'no row here can name the value to write');
        $this->assertSame(1, $exit);
    }

    public function test_the_gate_row_reports_a_mismatch_a_previous_boot_left_on_record(): void
    {
        // This boot's verdict is clean. The record is not: it names a connection this
        // run does not inspect, and no boot has closed it out. A preflight must not
        // call that clean just because the connection it was pointed at is fine.
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        [$clean, $cleanExit] = $this->doctor();

        $this->assertStringStartsWith('PASS  pgcat gate', $this->rowContaining($clean, 'pgcat gate'));
        $this->assertSame(0, $cleanExit);

        $this->recordGateMismatch('sqlite', 'sqlite', '2026-09-24T21:05:00+00:00');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat gate');

        $this->assertStringStartsWith('WARN  pgcat gate', $row);
        $this->assertStringContainsString('armed and ready', $row);
        $this->assertStringContainsString('the boot record still holds a mismatch', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-24T21:05:00+00:00', $row);
        $this->assertStringContainsString('on connection "sqlite" (driver "sqlite")', $row);
        $this->assertSame(0, $exit, 'a warning fails a preflight only under --strict');

        [, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict turns the recorded mismatch into a failed gate');
    }

    public function test_the_store_probe_row_warns_when_probing_is_switched_off(): void
    {
        // The documented way to keep the audit to configuration checks — and the state
        // that used to be invisible: with the probe off, an unreachable store is
        // reported by nothing at boot, so the reachability row's PASS means "this boot
        // did not check" rather than "the store answered".
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useAudit(storeProbeSeconds: 0);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('WARN  store probe', $row);
        $this->assertStringContainsString('switched off', $row);
        $this->assertStringContainsString('reported by nothing at boot', $row);
        $this->assertSame(0, $exit, 'switching the probe off is a choice, not a failure');
    }

    public function test_the_store_probe_row_passes_with_an_interval_and_a_writable_record(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useAudit(storeProbeSeconds: 60);
        $file = (string) config('db-manager.swrr.audit.file');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('PASS  store probe', $row);
        $this->assertStringContainsString('at most one PING every 60 seconds', $row);
        $this->assertStringContainsString($file . ', which is writable', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_store_probe_row_fails_when_its_record_cannot_be_written(): void
    {
        // The record is the throttle. An installation that cannot write it skips the
        // PING rather than issuing one per request — correct, and completely silent,
        // which is what this row exists to say out loud.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $blocked = $this->blockedRecordPath();
        $this->useAudit(storeProbeSeconds: 60, file: $blocked);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('FAIL  store probe', $row);
        $this->assertStringContainsString($blocked, $row);
        $this->assertStringContainsString('cannot be written', $row);
        $this->assertStringContainsString('the PING is skipped on every boot', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_store_probe_row_warns_when_the_primary_store_is_in_process(): void
    {
        // A configured interval with nothing to reach: the probe is moot rather than
        // missing, and the reachability row has already warned about the store that
        // makes it so.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useAudit(storeProbeSeconds: 60);
        config()->set('db-manager.swrr.primary_store', 'local');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('WARN  store probe', $row);
        $this->assertStringContainsString('switched on every 60 seconds but nothing is probed', $row);
        $this->assertStringContainsString('swrr.primary_store is "local"', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * Both states at once, which the row used to report as one.
     *
     * Probing switched off and a record that cannot be written are independent, and an
     * installation can be in both: the row used to stop at whichever it reached first, so an
     * operator who switched the probe on to clear the warning heard about the record only on
     * the *next* preflight — a second deploy for a sentence that fits on this row. The verdict
     * is the loudest of the two, so a choice that has been made unwritable reads as the failure
     * it is, and the sentence that talks about --strict is left out because the row has already
     * failed without it.
     */
    public function test_the_store_probe_row_names_a_switched_off_probe_and_an_unwritable_record(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $blocked = $this->blockedRecordPath();
        $this->useAudit(storeProbeSeconds: 0, file: $blocked);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('FAIL  store probe', $row, 'a refusal beside a warning is still a refusal');
        $this->assertStringContainsString('switched off (swrr.audit.store_probe_seconds = 0)', $row);
        $this->assertStringContainsString('reported by nothing at boot', $row);
        $this->assertStringContainsString('the record at ' . $blocked . ' cannot be written', $row);
        $this->assertStringContainsString('the PING is skipped on every boot', $row);
        $this->assertStringNotContainsString(
            '--strict would fail this warning',
            $row,
            'the row is already a failure, so the gate flag has nothing left to add',
        );
        $this->assertSame(1, $exit);
    }

    /**
     * The store that makes the probe moot, named beside the record failure rather than instead
     * of it.
     *
     * With an interval set and an in-process store there is nothing for the probe to reach — but
     * the record is still unwritable, which is a fact about the installation somebody has to fix.
     * The row names both and takes the failure as its verdict.
     */
    public function test_the_store_probe_row_names_the_moot_store_beside_an_unwritable_record(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $blocked = $this->blockedRecordPath();
        $this->useAudit(storeProbeSeconds: 60, file: $blocked);
        config()->set('db-manager.swrr.primary_store', 'local');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('FAIL  store probe', $row);
        $this->assertStringContainsString('switched on every 60 seconds but nothing is probed', $row);
        $this->assertStringContainsString('swrr.primary_store is "local"', $row);
        $this->assertStringContainsString('the record at ' . $blocked . ' cannot be written', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * Each state of the probe is dated from its own finding, so the row answers "since when".
     *
     * The two states come and go independently — a probe can be switched off for weeks and the
     * record become unwritable this morning — so one date on both would claim the installation
     * started being unable to check its store at one moment, which is a claim about the wrong state.
     *
     * The record is written and *then* made read-only, because that is the only way an installation
     * can be in the second state and still have a record to read: the file that holds the date is the
     * file the state is about, and an installation with nowhere to write it has no record at all.
     */
    public function test_the_store_probe_row_dates_each_state_from_its_own_finding(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        $record = $this->tempDir() . '/audit.json';

        $this->useAudit(storeProbeSeconds: 0, file: $record);
        $this->recordFindings([
            WeightedDatabaseServiceProvider::KEY_STORE_PROBE_OFF => '2026-09-20T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_STORE_PROBE_FILE_UNWRITABLE => '2026-09-24T08:15:00+00:00',
        ]);
        chmod($record, 0o444);

        if (is_writable($record)) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'store probe');

        $this->assertStringStartsWith('FAIL  store probe', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-20T08:15:00+00:00', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-24T08:15:00+00:00', $row);
        $this->assertSame(2, substr_count($row, 'recorded unresolved since'), 'one date per state, from its own key');
        $this->assertSame(1, $exit);
    }

    public function test_an_armed_flipper_with_every_pgcat_file_in_place_passes(): void
    {
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('PASS  pgcat files', $row);
        $this->assertStringContainsString('a flip could run', $row);
        $this->assertStringContainsString($paths['config_path'] . ' is writable', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * The paths a flip *cycle* writes are reported by the row that already lists the paths a flip
     * *step* reads — the boot stamp the container's entrypoint writes and the `.lock` companion the
     * audit record is locked on — each with the mode and owner it was found with and the sentence
     * that says what an unwritable one costs.
     *
     * The reading is the flipper's own list, so `pgcat files` is one answer about every path this
     * package has to be able to write while a flip is armed rather than two rows that can disagree.
     * The stamp's directory is a regular file where a directory is wanted, which holds on every
     * filesystem — no mode bits are involved — and the lock needs a read-only file, so the case
     * skips where the platform ignores the bit.
     */
    /**
     * The image build catches it before the deploy's rehearsal does: a container whose PHP has
     * `proc_open` in `disable_functions` passes every other gate and cannot flip, because the
     * Process class spawns the supervisor command with it. The row is the same reading a flip
     * gets — the step's own verdict — so a row that fails and a flip that refuses are impossible
     * to disagree, and the stub is the step's answer rather than the machine's: a suite cannot
     * make `function_exists('proc_open')` lie, and the defect has to be reproducible on a machine
     * that does not have it.
     */
    public function test_a_platform_that_cannot_spawn_a_process_is_reported_without_a_flip(): void
    {
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();

        $this->app->instance(
            SupervisorStep::class,
            new SupervisorStep((new FakeSupervisor())->runner(), 5, procOpenAvailable: false),
        );

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('proc_open is not available', $row);
        $this->assertStringContainsString('disable_functions', $row);
        $this->assertStringContainsString('cannot be run at all', $row);
        $this->assertStringContainsString('supervisorctl', $row, 'the row names the command it could not run');
        $this->assertSame(1, $exit);
    }

    public function test_the_paths_a_flip_cycle_writes_are_reported_by_the_row_that_lists_the_rest(): void
    {
        $blocked = $this->tempDir().'/blocked';
        file_put_contents($blocked, '');

        // A record that can be written — so its lock is judged rather than moot — with a lock beside
        // it that cannot be.
        $record = $this->tempDir().'/swrr-audit.json';
        file_put_contents($record, '{}');
        file_put_contents($record.'.lock', '');
        @chmod($record.'.lock', 0o444);

        if (is_writable($record.'.lock')) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        $this->bindRedis(reachable: true);
        $this->armPgcat(['boot_file' => $blocked.'/container-booted-at']);

        // The record the audit locks is `swrr.audit.file`, and the flipper judges its lock beside
        // its own paths — so the path is set the way an installation sets it and both singletons
        // are dropped, which is what makes the resolved record path the one the row reports.
        config()->set('db-manager.swrr.audit.file', $record);
        $this->app->forgetInstance(BootAudit::class);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('boot stamp', $row);
        $this->assertStringContainsString('boot window never closes', $row);
        $this->assertStringContainsString('audit lock', $row);
        $this->assertStringContainsString('two boots can write the record at once', $row);
        $this->assertStringContainsString('mode ', $row, 'the mode and owner found travel with each sentence');
        $this->assertSame(1, $exit);
    }

    public function test_a_missing_source_file_is_named_before_any_flip_happens(): void
    {
        $paths = $this->armPgcat();
        unlink($paths['no_readers_path']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('writer source', $row);
        $this->assertStringContainsString($paths['no_readers_path'], $row);
        $this->assertStringContainsString('does not exist', $row);

        // The reader source is intact, so the row must not blame it too.
        $this->assertStringNotContainsString('reader source', $row);
        $this->assertSame(1, $exit);
    }

    public function test_a_missing_flip_target_is_named(): void
    {
        $paths = $this->armPgcat();
        unlink($paths['config_path']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('flip target', $row);
        $this->assertStringContainsString($paths['config_path'], $row);
        $this->assertSame(1, $exit);
    }

    public function test_a_read_only_flip_target_is_named(): void
    {
        $paths = $this->armPgcat();
        chmod($paths['config_path'], 0o444);

        if (is_writable($paths['config_path'])) {
            // Running as root, or on a filesystem that ignores the read-only bit:
            // this environment cannot make the target unwritable, so there is
            // nothing to assert about a flip's copy failing.
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString("flip target [{$paths['config_path']}] is not writable", $row);
        $this->assertSame(1, $exit);
    }

    public function test_a_flip_target_in_a_directory_that_cannot_be_written_is_named(): void
    {
        // The atomic swap writes {target}.tmp.{pid} beside the target, so a target
        // whose directory is not usable fails even though the file itself is fine.
        // A path already occupied by a regular file can never be that directory.
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        $this->armPgcat(['config_path' => $dir . '/blocked/pgcat.toml']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString("directory [{$dir}/blocked] is not writable", $row);
        $this->assertStringContainsString('atomic swap cannot write its temp file', $row);
        $this->assertSame(1, $exit);
    }

    public function test_a_state_directory_that_cannot_be_written_is_named(): void
    {
        // Both state writes are silenced with @, so an unwritable state directory
        // leaves a flip reporting success while restarting pgcat on every poll.
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        $this->armPgcat(['state_file' => $dir . '/blocked/state.json']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('flip state directory', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * The repairs the row can state, one per problem — and the row prints them as a list rather
     * than one line for whichever problem it noticed first, which is the shape the reader rows
     * have had since the first refused window.
     *
     * A path already occupied by a regular file can never be the directory beside it, so this
     * asserts on a directory problem without depending on mode bits: the line names the directory
     * and adds both the write bit the check found missing and the traverse bit the rename after it
     * needs. The two paths share a directory, so the same line appears twice — once per problem,
     * which is the claim.
     */
    public function test_the_files_row_prints_one_repair_line_per_problem(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        $this->armPgcat([
            'state_file' => $dir . '/blocked/state.json',
            'lock_file' => $dir . '/blocked/flip.lock',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('flip state directory', $row);
        $this->assertStringContainsString('flip lock directory', $row);
        $this->assertSame(
            [
                'chmod +wx ' . escapeshellarg($dir . '/blocked'),
                'chmod +wx ' . escapeshellarg($dir . '/blocked'),
            ],
            $this->repairs($output),
            'one line per problem, each naming the path the check failed on',
        );
        $this->assertSame(1, $exit, 'a repair is not a verdict: the row still fails');
    }

    /**
     * An empty key is a problem, and only the keys the published config ships a value for are
     * repairable from here.
     *
     * `config_path` has a documented value — the file the sample config points at — so the repair
     * is that setting line, drawn from the flipper so it cannot drift from the default it tells the
     * operator to write. `readers_path` and `no_readers_path` are documented as `—`, and the row
     * names them without inventing a path: a wrong guess pasted into configuration points a flip at
     * a file that does not exist, which is worse than an empty column.
     */
    public function test_the_files_row_repairs_an_empty_key_only_where_the_published_config_ships_a_value(): void
    {
        $this->armPgcat([
            'config_path' => '',
            'readers_path' => '',
            'no_readers_path' => '',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('swrr.pgcat.config_path is not set', $row);
        $this->assertStringContainsString('swrr.pgcat.readers_path is not set', $row);
        $this->assertStringContainsString('swrr.pgcat.no_readers_path is not set', $row);
        $this->assertSame(
            ["swrr.pgcat.config_path = '/etc/pgcat/pgcat.toml'"],
            $this->repairs($output),
            'the key with a published value is repairable; the two the README documents as `—` are not',
        );
        $this->assertSame(1, $exit);
    }

    /**
     * Each problem is dated from *its own* finding, which is what one key per setting buys: a record
     * holding two file findings dates two problems, and a problem the record has nothing about — a
     * key this installation never set, which the audit leaves to this row — prints no date rather
     * than one belonging to another setting.
     */
    public function test_each_file_problem_is_dated_from_its_own_finding(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        $this->armPgcat([
            'state_file' => $dir . '/blocked/state.json',
            'config_path' => $dir . '/gone.toml',
        ]);

        $this->recordFindings([
            WeightedDatabaseServiceProvider::KEY_PGCAT_STATE_FILE_UNUSABLE => '2026-09-20T08:00:00+00:00',
            WeightedDatabaseServiceProvider::KEY_PGCAT_CONFIG_PATH_UNUSABLE => '2026-09-24T21:05:00+00:00',
        ]);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringContainsString('flip state directory', $row);
        $this->assertStringContainsString('flip target', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-20T08:00:00+00:00', $row);
        $this->assertStringContainsString(
            'recorded unresolved since 2026-09-24T21:05:00+00:00',
            $row,
            'two problems, two findings, two dates: quoting the first key on file would date the wrong one',
        );
    }

    public function test_an_armed_flipper_with_unset_paths_is_reported_as_incomplete(): void
    {
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('FAIL  pgcat files', $row);
        $this->assertStringContainsString('swrr.pgcat.config_path is not set', $row);
        $this->assertStringContainsString('swrr.pgcat.readers_path is not set', $row);
        $this->assertSame(1, $exit);
    }

    public function test_an_unarmed_flipper_requires_no_pgcat_files(): void
    {
        // Off is off: an installation that never flips must not be failed for files
        // it has deliberately not configured.
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat files');

        $this->assertStringStartsWith('PASS  pgcat files', $row);
        $this->assertStringContainsString('not armed', $row);
    }

    /**
     * The inventory of what this package writes, in one row.
     *
     * `pgcat files` only speaks of a path when the path is unusable, so an installation cannot
     * see the rest of the set — and a deployment that moves its state directory has nothing to
     * confirm the move against. This row names every path the flipper lists, with the mode and
     * owner each was found with, whether or not any of them is a fault; the verdict follows the
     * unwritable ones rather than the label list.
     */
    public function test_the_write_paths_row_names_every_path_a_flip_writes_beside_the_mode_found(): void
    {
        // The store is bound reachable rather than left to the machine: this test is about the
        // write paths, and a runner with no Redis would make the reachability row fail and take
        // the exit code with it — the write paths row would be right and the test red.
        $this->bindRedis(reachable: true);

        $paths = $this->armPgcat([
            'boot_file' => $this->tempDir().'/container-booted-at',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'write paths');

        $this->assertStringStartsWith('PASS  write paths', $row);

        foreach (['flip target', 'flip target directory', 'reader source', 'writer source', 'flip state', 'flip lock', 'boot stamp'] as $label) {
            $this->assertStringContainsString($label, $row, "the row names the {$label}");
        }

        $this->assertStringContainsString($paths['config_path'], $row);
        $this->assertStringContainsString($paths['readers_path'], $row);
        // The state file does not exist yet, so the path whose mode decides the write is the
        // directory that will take it — the same rule `fileProblems()` judges by.
        $this->assertStringContainsString('flip state [' . dirname($paths['state_file']) . ']', $row);
        $this->assertStringContainsString('mode ', $row, 'the mode and owner found travel with every path');

        $this->assertSame(0, $exit);
    }

    /**
     * A read-only variant is the plan's fixture: the row reports the path, the mode it was found
     * with, and what an unwritable one costs — and it still names the paths that are fine beside
     * it, because the reading is the whole inventory rather than only the fault.
     */
    public function test_a_read_only_path_is_named_with_its_mode_and_what_it_costs(): void
    {
        $paths = $this->armPgcat();
        chmod($paths['readers_path'], 0o444);

        if (is_writable($paths['readers_path'])) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'write paths');

        $this->assertStringStartsWith('FAIL  write paths', $row);
        $this->assertStringContainsString("reader source [{$paths['readers_path']}]", $row);
        $this->assertStringContainsString('mode 0444', $row);
        $this->assertStringContainsString('nothing to read for that direction', $row);
        $this->assertStringContainsString($paths['config_path'], $row, 'the writable paths are named beside the blocked one');
        $this->assertSame(1, $exit);
    }

    /**
     * An inert flipper writes nothing, so there is no path to name — the line both it and
     * `pgcat files` draw.
     */
    public function test_the_write_paths_row_is_moot_while_the_flipper_is_not_armed(): void
    {
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'write paths');

        $this->assertStringStartsWith('PASS  write paths', $row);
        $this->assertStringContainsString('not armed', $row);
    }


    public function test_the_supervisor_row_passes_when_supervisor_knows_the_program(): void
    {
        // End to end: the flip's own command resolves, the program name survives the
        // shell because it is quoted, and supervisor answers for it.
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];
        $supervisor = $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('PASS  pgcat supervisor', $row);
        $this->assertStringContainsString($binary, $row);
        $this->assertStringContainsString('knows "pgcat:*"', $row);
        $this->assertStringContainsString('RUNNING pid 4242', $row);
        $this->assertStringContainsString('a flip would run ' . $binary . ' signal HUP "pgcat:*"', $row);
        $this->assertSame(0, $exit);

        // What the row claims is that it inspects without taking the step: the one
        // command that reached a runner asked for status, and for nothing else.
        $this->assertSame([$binary . ' status "pgcat:*"'], $supervisor->ran);
    }

    public function test_the_supervisor_row_fails_an_unquoted_program_name(): void
    {
        // The name is the whole point: an unquoted `pgcat:*` is a glob, and what
        // supervisorctl receives is then whatever the working directory happens to
        // hold, decided at the moment of the flip.
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];

        config()->set('db-manager.swrr.pgcat.reload_command', $binary . ' signal HUP pgcat:*');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $supervisor = $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString($binary . ' signal HUP pgcat:*', $row);
        $this->assertStringContainsString('pgcat:* is unquoted', $row);
        $this->assertStringContainsString('Write ' . $binary . ' signal HUP "pgcat:*"', $row);
        $this->assertSame(1, $exit);
        $this->assertSame([], $supervisor->ran, 'a command that cannot work is not run');
    }

    public function test_the_supervisor_row_fails_a_binary_that_does_not_resolve(): void
    {
        $missing = $this->tempDir() . '/missing/supervisorctl';

        $this->armPgcat([
            'restart_command' => $missing . ' restart "pgcat:*"',
            'reload_command' => $missing . ' signal HUP "pgcat:*"',
        ]);
        $supervisor = $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('does not resolve to an executable', $row);
        $this->assertStringContainsString('tried ' . $missing, $row);
        // The row says what the flip does about it, which is the whole point of the
        // refusal: none of these faults can leave a new file in place now.
        $this->assertStringContainsString('a flip refuses before it swaps the file', $row);
        $this->assertStringContainsString('nothing is replaced and no mode is recorded', $row);
        $this->assertSame(1, $exit);
        $this->assertSame([], $supervisor->ran);
    }

    public function test_the_supervisor_row_fails_a_binary_that_is_not_on_the_path(): void
    {
        $this->armPgcat([
            'restart_command' => 'weighted-db-manager-no-such-binary restart "pgcat:*"',
            'reload_command' => 'weighted-db-manager-no-such-binary signal HUP "pgcat:*"',
        ]);
        $supervisor = $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString(
            'weighted-db-manager-no-such-binary does not resolve to an executable',
            $row,
        );
        $this->assertSame(1, $exit);
        $this->assertSame([], $supervisor->ran);
    }

    public function test_the_supervisor_row_fails_when_supervisor_does_not_know_the_program(): void
    {
        // supervisorctl exited non-zero for the reason this row exists: the file swap
        // would have succeeded and the flip would still report a failure.
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];
        $supervisor = $this->bindSupervisor($this->supervisorThatRuns(
            'pgcat:*: ERROR (no such group)',
            "pgcat:pgcat_00   RUNNING   pid 4242, uptime 0:12:34\n",
        ));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('supervisor does not know "pgcat:*"', $row);
        $this->assertStringContainsString('ERROR (no such group)', $row);
        $this->assertStringContainsString('a group called pgcat is addressed as "pgcat:*"', $row);
        $this->assertStringContainsString('a flip refuses before it swaps the file', $row);

        // The name is the repair, so the row asks the one authority on it: the same binary
        // without a program, which lists what supervisord is running.
        $this->assertSame([$binary . ' status "pgcat:*"', $binary . ' status'], $supervisor->ran);
        $this->assertStringContainsString('says supervisord runs 1 program(s): pgcat:pgcat_00', $row);
        $this->assertStringContainsString('the closest is "pgcat:pgcat_00", which is the name to write', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * The row names the running program it thinks the configuration meant — and still prints no
     * repair line for it, which is the difference between the two columns. A sentence can carry
     * a ranked answer ("the closest is …"), and a suggestion is a value to paste: one that a
     * gate may apply without reading. `pgcat_x:pgcat_00` is the nearest *name*, not a statement
     * that this flip belongs to that pool.
     */
    public function test_the_supervisor_row_names_the_running_program_without_printing_a_repair_line(): void
    {
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];
        $this->bindSupervisor($this->supervisorThatRuns(
            'pgcat:*: ERROR (no such group)',
            "pgcat_x:pgcat_01   RUNNING   pid 2\npgcat_x:pgcat_00   RUNNING   pid 1\n",
        ));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringContainsString('the closest are "pgcat_x:pgcat_00", "pgcat_x:pgcat_01"', $row);
        $this->assertStringContainsString('or the whole group "pgcat_x:*"', $row);
        $this->assertSame([], $this->suggestions($output), 'a near miss is a name to consider, not a value to apply');
        $this->assertSame(1, $exit);
    }

    /**
     * A row and a flip that disagreed about whether a command works would be worse than
     * either being wrong alone — that is the failure this area keeps producing. Both read
     * one verdict, so the sentence the row prints is the sentence the flipper refuses with.
     */
    public function test_the_supervisor_row_quotes_the_verdict_a_flip_refuses_on(): void
    {
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];
        $supervisor = $this->bindSupervisor(new FakeSupervisor(
            exit: 2,
            stdout: '',
            stderr: 'pgcat:*: ERROR (no such group)',
        ));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        // The flip's own command (use_reload is on, so it is the reload one), judged again
        // by the class the row and the flipper both use.
        $verdict = (new SupervisorStep($supervisor->runner()))->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertFalse($verdict['usable'], 'this is a command a flip refuses');
        $this->assertStringContainsString($verdict['detail'], $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_supervisor_row_fails_when_supervisorctl_cannot_answer(): void
    {
        // A socket that is not there is not a program that is not there. The two are
        // repaired in different places, so the row must not put the same sentence on both.
        $this->armPgcat();
        $this->bindSupervisor(new FakeSupervisor(
            exit: 2,
            stdout: '',
            stderr: 'unix:///var/run/supervisor.sock no such file',
        ));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('could not reach supervisord', $row);
        $this->assertStringContainsString('unix:///var/run/supervisor.sock no such file', $row);
        $this->assertStringContainsString('chmod=0760', $row, 'the row names the repair a preflight can act on');
        $this->assertStringNotContainsString('does not know', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_supervisor_row_fails_a_command_that_never_answered(): void
    {
        $paths = $this->armPgcat();
        $this->bindSupervisor(new FakeSupervisor(
            exit: -1,
            stdout: '',
            stderr: 'Process exceeded the timeout of 5 seconds',
        ));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('did not answer in time', $row);
        $this->assertStringContainsString('Process exceeded the timeout of 5 seconds', $row);
        $this->assertStringContainsString($paths['supervisorctl'], $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_supervisor_row_is_moot_while_the_flipper_is_not_armed(): void
    {
        // Nothing swaps a file, so nothing restarts supervisor — the same rule as
        // `pgcat files`, and the row says which of the two it is.
        $supervisor = $this->bindSupervisor();
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('PASS  pgcat supervisor', $row);
        $this->assertStringContainsString('flipper is not armed', $row);
        $this->assertSame([], $supervisor->ran);
    }

    public function test_the_supervisor_row_checks_resolution_for_a_command_that_is_not_supervisorctl(): void
    {
        // A flip driven by something else — systemd, a wrapper script — still has to
        // resolve, and there is no program name supervisor could be asked about.
        $this->armPgcat(['reload_command' => PHP_BINARY . ' -r "echo ok;"']);
        $supervisor = $this->bindSupervisor();

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('PASS  pgcat supervisor', $row);
        $this->assertStringContainsString(PHP_BINARY, $row);
        $this->assertStringContainsString('does not invoke supervisorctl', $row);
        $this->assertSame([], $supervisor->ran);
    }

    public function test_the_supervisor_row_says_when_a_supervisor_command_names_no_program(): void
    {
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];

        config()->set('db-manager.swrr.pgcat.reload_command', $binary . ' reread && ' . $binary . ' update');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $supervisor = $this->bindSupervisor();

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('PASS  pgcat supervisor', $row);
        $this->assertStringContainsString('names no program', $row);
        $this->assertStringContainsString("act on supervisor's own configuration", $row);
        $this->assertSame([], $supervisor->ran);
    }

    public function test_the_supervisor_row_fails_when_the_command_is_empty(): void
    {
        $this->armPgcat(['reload_command' => '', 'restart_command' => '']);
        $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('supervisor command is empty', $row);

        // The fault's own sentence names the keys and not the values — `SupervisorStep` does not
        // know which of the two a flip reads — so the line supplies what a sentence could not:
        // the key the flip actually reads (use_reload is on here, so it is the reload one) and
        // the command that setting documents.
        $this->assertSame(
            ['swrr.pgcat.reload_command = ' . var_export('supervisorctl signal HUP "pgcat:*"', true)],
            $this->repairs($output),
        );
        $this->assertSame(1, $exit, 'a repair is not a verdict: the row still fails');
    }

    /**
     * The one repair this area has always named in prose — "Write <command>, the quoted name is
     * the one supervisorctl receives unchanged" — printed as the config line that writes it.
     */
    public function test_the_supervisor_row_prints_the_quoted_command_to_write(): void
    {
        $paths = $this->armPgcat();
        $binary = $paths['supervisorctl'];

        config()->set('db-manager.swrr.pgcat.reload_command', $binary . ' signal HUP pgcat:*');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $this->bindSupervisor();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat supervisor');

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $row);
        $this->assertStringContainsString('is unquoted', $row);
        $this->assertSame(
            ['swrr.pgcat.reload_command = ' . var_export($binary . ' signal HUP "pgcat:*"', true)],
            $this->repairs($output),
        );
        $this->assertSame(1, $exit);
    }

    /**
     * The rest of the step's faults are repaired in a PATH, a running supervisord or a
     * `[program:]` section, so the row prints the fault and stops. The exhaustive fault-to-line
     * mapping is `PgcatConfigFlipperTest`'s; this is one of them, end to end.
     */
    public function test_the_supervisor_row_prints_no_line_for_a_fault_a_config_line_cannot_reach(): void
    {
        $missing = $this->tempDir() . '/missing/supervisorctl';

        $this->armPgcat([
            'restart_command' => $missing . ' restart "pgcat:*"',
            'reload_command' => $missing . ' signal HUP "pgcat:*"',
        ]);
        $this->bindSupervisor();

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  pgcat supervisor', $this->rowContaining($output, 'pgcat supervisor'));
        $this->assertSame([], $this->suggestions($output), 'the repair is an install, not a value');
        $this->assertSame(1, $exit);
    }

    public function test_it_warns_when_the_replicas_carry_no_weight_metadata(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1'],
            ['host' => '10.1.0.2'],
        ]);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('WARN  replica metadata', $row);
        $this->assertStringContainsString('carry no weight metadata', $row);
    }

    /**
     * A weight the resolver cannot read is read as 0, and 0 is how a replica is disabled — so the
     * replica leaves the pool. The row used to report the pool that was left: a count short by one,
     * with nothing to say which replica went or why. A pool that quietly lost a member is exactly
     * the installation this row exists to find, so it fails and names the replica instead.
     */
    public function test_the_metadata_row_fails_and_names_a_replica_whose_weight_cannot_be_read(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1'],
            ['host' => '10.1.0.2', 'weight' => 'heavy'],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] weight is "heavy", which the resolver reads as 0', $row);
        $this->assertStringContainsString('a replica weighted 0 leaves the pool', $row);
        $this->assertStringContainsString('reads are routed over a smaller pool than the read list describes', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * A memory the resolver cannot read weights its replica as if it had none, and the replica stays
     * in the pool — the other lie this row has to tell apart from a pool that lost a member, so the
     * sentence beside it says which of the two happened.
     */
    public function test_the_metadata_row_fails_and_names_a_replica_whose_memory_cannot_be_read(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'cpu_cores' => 8, 'ram_gb' => 32],
            ['host' => '10.1.0.2', 'cpu_cores' => 8, 'ram_gb' => ['32']],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] ram_gb is an array of one entry, which the resolver reads as 0 GB', $row);
        $this->assertStringContainsString('the replica stays in the pool', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * The core count is read through `max(1, …)`, so the substitution for a value that is not a
     * number is one core rather than none — the number the sentence quotes, because "cores could not
     * be read" is not actionable without it.
     */
    public function test_the_metadata_row_fails_and_names_a_replica_whose_cores_cannot_be_read(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'cpu_cores' => 8, 'ram_gb' => 32],
            ['host' => '10.1.0.2', 'cpu_cores' => 'eight'],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] cpu_cores is "eight", which the resolver reads as 1 core', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * A negative weight is a number, so nothing is substituted for it by type — the resolver clamps
     * it with `max(0, …)` instead, and 0 is how a replica is disabled. The replica leaves the pool
     * over a value its author never wrote as an off switch.
     */
    public function test_the_metadata_row_fails_on_a_negative_weight_it_would_clamp(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 10],
            ['host' => '10.1.0.2', 'weight' => -5],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] weight is -5, which the resolver reads as 0', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * The narrowest case in the rule: a fraction is a number, so no substitution happens — but the
     * resolver reads it through `ConfigValue::int()`, and 0.5 truncates to 0, which is the value
     * that disables the replica. `weight: 0` is how that is written on purpose, so a weight that
     * reads as 0 without being 0 is a replica that left the pool without anybody saying so.
     */
    public function test_the_metadata_row_fails_on_a_weight_that_truncates_to_zero(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 10],
            ['host' => '10.1.0.2', 'weight' => 0.5],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] weight is 0.5, which the resolver reads as 0', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * With every replica's weight unreadable the old sentence still applied — "every replica on [x]
     * resolves to weight 0" — and it described the symptom: a pool with nothing in it. It is true,
     * and useless, because the value that caused it is the repair, and "every replica is drained"
     * and "every replica's weight is a typo" are the same picture without it.
     */
    public function test_the_metadata_row_blames_the_unreadable_value_rather_than_the_weight_it_reads(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 'heavy'],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.1:5432] weight is "heavy", which the resolver reads as 0', $row);
        $this->assertStringNotContainsString('resolves to weight 0', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * Every value it cannot read is named, in the row rather than the first one: two replicas with
     * three bad values between them are three repairs, and an operator who fixes one only to hear
     * about the next has paid a second deploy for a sentence that fitted on this run.
     */
    public function test_the_metadata_row_names_every_replica_and_every_value_it_cannot_read(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => null],
            ['host' => '10.1.0.2', 'cpu_cores' => 'eight', 'ram_gb' => 'lots'],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        // A key that is present with nothing in it, which is not the same as a key that is absent:
        // this one the resolver reads as 0 and drops the replica over.
        $this->assertStringContainsString('[10.1.0.1:5432] weight is null, which the resolver reads as 0', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] cpu_cores is "eight", which the resolver reads as 1 core', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] ram_gb is "lots", which the resolver reads as 0 GB', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * `weight: 0` is the documented disable, so the replica is supposed to be missing from the pool —
     * the row passes. What it no longer does is report the pool as if it were the installation: the
     * count names the read list it came from and the replica that is not in it, because "1 replica,
     * total weight 10" on an installation with two is the same quiet shrink one key over.
     */
    public function test_the_metadata_row_names_a_disabled_replica_without_failing(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 10],
            ['host' => '10.1.0.2', 'weight' => 0],
        ]);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('PASS  replica metadata', $row);
        $this->assertStringContainsString('holds 1 of the 2 configured replicas (total weight 10)', $row);
        // The name comes from the resolver's own exclusion report, and the sentence from the
        // classifier — so the row says which replica left and what the value meant, and neither is
        // this row's opinion of the read list.
        $this->assertStringContainsString('10.1.0.2:5432', $row);
        $this->assertStringContainsString(ReplicaMetadata::DISABLED, $row);
    }

    /**
     * The other half of naming a drain: a replica switched off on purpose is named even when the
     * row has a refusal to report, because a refusal is about *values* and the row returns there.
     * Fixing the typo this run is about used to be the only repair an operator could read, and the
     * pool was still one short afterwards — a drained replica hidden behind a refusal, which is the
     * same quiet shrink the count-with-a-denominator branch was written for, one branch over.
     */
    public function test_the_metadata_row_names_a_drained_replica_beside_the_value_it_cannot_read(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 10],
            ['host' => '10.1.0.2', 'weight' => 0],
            ['host' => '10.1.0.3', 'weight' => 'heavy'],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.3:5432] weight is "heavy", which the resolver reads as 0', $row);
        $this->assertStringContainsString('Also drained on purpose: 10.1.0.2:5432', $row);
        $this->assertStringContainsString(ReplicaMetadata::DISABLED, $row);
        $this->assertSame(1, $exit);
    }

    /**
     * And when every replica is switched off, the sentence that says reads cannot be routed names
     * them. "Every replica on [pgsql] resolves to weight 0" without a list leaves the operator to
     * work out which replicas they drained — and this row is the only place that knows what the
     * pool did not take. The unreadable half of that sentence is reported before it can be reached,
     * so what is named here is a pool somebody switched off on purpose.
     */
    public function test_the_metadata_row_names_every_replica_when_every_one_is_drained(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', [
            ['host' => '10.1.0.1', 'weight' => 0],
            ['host' => '10.1.0.2', 'weight' => 0],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('every replica on ['.self::CONNECTION.'] resolves to weight 0', $row);
        $this->assertStringContainsString('10.1.0.1:5432', $row);
        $this->assertStringContainsString('10.1.0.2:5432', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * The rule under all of it, over the read lists that drain a replica rather than one case at a
     * time: **every replica the pool does not hold is named in the row**, whatever it left over and
     * whatever verdict the row reaches for it. That is the claim the naming exists for — a drained
     * replica that is only a smaller total is the shrink this row was written to catch — and it is
     * asserted against the resolver's own exclusion list rather than re-derived here, so a reason
     * this row has not been taught about fails rather than passing quietly.
     *
     * @param list<array<string, mixed>> $read
     */
    #[DataProvider('readListsThatDrain')]
    public function test_the_metadata_row_names_every_replica_the_pool_does_not_hold(array $read): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.read', $read);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'replica metadata');

        $manager = DB::getFacadeRoot();
        $this->assertInstanceOf(WeightedDatabaseManager::class, $manager);

        $excluded = $manager->poolExclusions(self::CONNECTION);

        $this->assertNotSame(
            [],
            $excluded,
            'this read list is meant to drain something, so a row that names nothing would not be tested by it',
        );

        foreach ($excluded as $exclusion) {
            $this->assertStringContainsString(
                $exclusion['key'],
                $row,
                "the row does not name {$exclusion['key']}, which the pool does not hold ({$exclusion['reason']})",
            );
        }
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>}>
     */
    public static function readListsThatDrain(): array
    {
        return [
            'a drain in an otherwise healthy pool' => [[
                ['host' => '10.1.0.1', 'weight' => 10],
                ['host' => '10.1.0.2', 'weight' => 0],
            ]],
            'a drain beside a weight that cannot be read' => [[
                ['host' => '10.1.0.1', 'weight' => 10],
                ['host' => '10.1.0.2', 'weight' => 0],
                ['host' => '10.1.0.3', 'weight' => 'heavy'],
            ]],
            'a drain beside a weight that truncates to 0' => [[
                ['host' => '10.1.0.1', 'weight' => 0],
                ['host' => '10.1.0.2', 'weight' => 0.5],
            ]],
            'a drain beside a core count that cannot be read' => [[
                ['host' => '10.1.0.1', 'weight' => 0],
                ['host' => '10.1.0.2', 'cpu_cores' => 'eight'],
            ]],
            'every replica drained on purpose' => [[
                ['host' => '10.1.0.1', 'weight' => 0],
                ['host' => '10.1.0.2', 'weight' => 0],
            ]],
        ];
    }

    /**
     * The two probing rows with the switch armed, over the real probe: each endpoint is asked, and an
     * endpoint that is not there is a reading rather than a failure.
     *
     * The rows are asked through `SingleHostProbe`, so this is the one test here that opens a
     * connection — and it is pointed at a closed port on the loopback address, which refuses in the
     * driver the way a host that is not listening does, without a test waiting on a socket. What it
     * asserts is the claim the rows make about such an endpoint: it is named with what it said, the
     * verdict does not move, and the reading that asks nothing is answered anyway.
     *
     * The switch is the documented one (`swrr.read_path.enabled`), off in the shipped config, so
     * arming it here is a decision this row makes rather than the fixture's state.
     */
    public function test_the_read_path_rows_ask_each_endpoint_and_report_a_refusal_as_a_silence(): void
    {
        $this->bindRedis(reachable: true);

        config()->set('db-manager.swrr.read_path', ['enabled' => true, 'timeout_ms' => 500]);
        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'pgsql',
            'database' => 'app',
            'host' => '127.0.0.1',
            'port' => 1,
            'read' => [['host' => '127.0.0.1', 'port' => 1]],
            'write' => ['host' => '127.0.0.1', 'port' => 1],
        ]);

        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$json] = $this->doctorJson();
        $report = $this->report($json);

        $caps = $this->checkNamed($report, 'read caps');

        $this->assertSame('PASS', $caps['verdict'], 'an endpoint that refused is a reading, not a failure');
        $this->assertStringContainsString('no read endpoint on [weighted] could be asked what it is capped at', $caps['detail']);
        $this->assertStringContainsString('127.0.0.1:1 did not answer', $caps['detail']);
        $this->assertStringContainsString('neither did the writer', $caps['detail']);

        $feedback = $this->checkNamed($report, 'standby feedback');

        $this->assertSame('PASS', $feedback['verdict']);
        $this->assertStringContainsString('no read endpoint on [weighted] could be asked about `hot_standby_feedback`', $feedback['detail']);
        $this->assertStringContainsString('127.0.0.1:1 did not answer', $feedback['detail']);

        // The one of the three that asks nothing is answered whatever the endpoints did.
        $pair = $this->checkNamed($report, 'read/write pair');

        $this->assertSame('PASS', $pair['verdict']);
        $this->assertStringContainsString('declares both halves of the pair', $pair['detail']);
        $this->assertStringContainsString('writes reach 127.0.0.1:1', $pair['detail']);
    }

    /**
     * The switch is read the way every other switch in this package is: a value that is not on or off
     * is refused, with the spellings, and resolves to the documented default — here *off*, so a typo
     * cannot open a connection per endpoint on a preflight somebody runs in a deploy.
     */
    public function test_a_read_path_switch_that_is_not_a_switch_is_refused_and_reads_as_off(): void
    {
        $this->bindRedis(reachable: true);

        config()->set('db-manager.swrr.read_path', ['enabled' => 'maybe']);

        [$json] = $this->doctorJson();
        $report = $this->report($json);

        foreach (['read caps', 'standby feedback'] as $name) {
            $row = $this->checkNamed($report, $name);

            $this->assertSame('PASS', $row['verdict'], 'a refused switch is not a fault: it is read as the default');
            $this->assertStringContainsString('the probe is not armed', $row['detail']);
            $this->assertStringContainsString('db-manager.swrr.read_path.enabled is "maybe"', $row['detail']);
            $this->assertStringContainsString('a switch is on or off', $row['detail']);
            $this->assertStringContainsString('neither row asks an endpoint', $row['detail']);
        }

        // The reading that does not depend on the switch says nothing about a refusal.
        $this->assertStringNotContainsString(
            'swrr.read_path.enabled',
            $this->checkNamed($report, 'read/write pair')['detail'],
            'the pair reading is a fact about the configuration, and the switch does not decide it',
        );
    }

    public function test_the_reader_windows_row_fails_a_flat_string_and_names_the_entry(): void
    {
        // The way a person naturally writes a window, and not a window. Read as "no
        // window at all" it left every read on the replica pool, silently — the opposite
        // of the fallback the setting describes.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_windows[0] is "10:00-14:20"', $row);
        $this->assertStringContainsString("a string such as '10:00-14:20' is not a window", $row);
        $this->assertStringContainsString('nothing is left to apply', $row);
        $this->assertSame(1, $exit, 'a refused value fails a deploy gate');
    }

    public function test_the_reader_windows_row_fails_the_setting_written_as_one_flat_string(): void
    {
        // The same mistake one level up: the whole value is the string, so there is no
        // entry to blame and the row blames the shape.
        $this->useReaderFallback('10:00-14:20', [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('swrr.reader_windows is "10:00-14:20", not a list of windows', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_reader_windows_row_fails_even_when_a_well_formed_window_survives(): void
    {
        // One refused entry is enough to fail, and the row says how much of the setting
        // is still in force rather than implying the whole fallback is gone.
        $this->useReaderFallback([
            ['start' => '10:00:00', 'end' => '14:20:00'],
            '17:00-20:30',
        ], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_windows[1] is "17:00-20:30"', $row);
        $this->assertStringContainsString('Refused: 1 well-formed window(s) still apply', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_reader_windows_row_dates_a_refusal_the_record_still_holds(): void
    {
        // The row fails on what it can see; the record adds since when, which is what
        // tells a release whether it is looking at its own change or an inherited one.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);
        $this->recordReaderFindings([
            WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED => '2026-09-24T21:05:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_windows[0] is "10:00-14:20"', $row);
        $this->assertStringContainsString('at every hour — recorded unresolved since 2026-09-24T21:05:00+00:00', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * Both keys refused in the same run, named in the same row.
     *
     * The row used to report whichever problem it reached first, so an operator who fixed
     * the windows and redeployed heard about the days on the *next* preflight — a second
     * deploy for a sentence that fits on this row, and a report that disagreed with the boot
     * audit, which has always logged every finding. The verdict is the loudest problem in the
     * row and each refused setting keeps its own repair line, so the two fixes are two pastes
     * rather than an interpretation of which sentence to apply to which key.
     */
    public function test_the_reader_windows_row_names_every_refused_setting(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback('10:00-14:20', ['1', '1,2,3']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('swrr.reader_windows is "10:00-14:20", not a list of windows', $row);
        $this->assertStringContainsString('reader_days[1] is "1,2,3"', $row);
        $this->assertStringContainsString(
            'no window can be used either — see the reader_windows problem above',
            $row,
            'the days problem says what the refused windows leave it, rather than claiming none were configured',
        );
        $this->assertSame(
            [
                "suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]",
                'suggestion          swrr.reader_days = [1, 2, 3]',
            ],
            $this->suggestions($output),
            'two refused settings have two repairs',
        );
        $this->assertSame(1, $exit);
    }

    /**
     * A refusal beside the warning it leaves behind, in one row.
     *
     * One entry of `reader_windows` is a flat string and one window survives, and the day
     * list names no day in 1…7 — so the typo costs an entry *and* what is left of the
     * fallback can never be entered. Those are two different repairs, and the row is the
     * place they can be read together.
     */
    public function test_the_reader_windows_row_names_a_refusal_beside_the_warning_it_leaves_behind(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([
            ['start' => '10:00:00', 'end' => '14:20:00'],
            '17:00-20:30',
        ], [0, 8]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row, 'a refusal beside a warning is still a refusal');
        $this->assertStringContainsString('reader_windows[1] is "17:00-20:30"', $row);
        $this->assertStringContainsString('1 well-formed window(s) still apply', $row);
        $this->assertStringContainsString('no day in 1…7 (read as 0, 8)', $row);
        $this->assertStringContainsString('the replica pool is never used', $row);
        $this->assertSame(1, $exit);
    }

    /**
     * Each problem is dated from its own finding.
     *
     * The record holds one entry per finding key, and the reader fallback has several: a row
     * that quotes the first key the record happens to hold puts a date on a problem it is not
     * reporting, which is a claim about when this installation started being wrong. Here both
     * keys are on record with different timestamps, and each date has to sit with its own
     * sentence.
     */
    public function test_the_reader_windows_row_dates_each_problem_from_its_own_finding(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback('10:00-14:20', ['1', '1,2,3']);
        $this->recordReaderFindings([
            WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED => '2026-09-20T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_READER_DAYS_REFUSED => '2026-09-24T21:05:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);

        $at = static function (string $needle) use ($row): int {
            $position = strpos($row, $needle);

            return $position === false ? -1 : $position;
        };

        $windows = $at('swrr.reader_windows is');
        $windowsAge = $at('recorded unresolved since 2026-09-20T08:15:00+00:00');
        $days = $at('reader_days[1] is');
        $daysAge = $at('recorded unresolved since 2026-09-24T21:05:00+00:00');

        $this->assertNotSame(-1, $windowsAge, 'the windows problem is dated from its own finding');
        $this->assertNotSame(-1, $daysAge, 'the days problem is dated from its own finding');
        $this->assertTrue($windows < $windowsAge, 'the windows date follows the windows problem');
        $this->assertTrue($windowsAge < $days, 'and the days problem is the next thing in the row');
        $this->assertTrue($days < $daysAge, 'the days date follows the days problem');
        $this->assertSame(1, $exit);
    }

    /**
     * A problem the row does not report is not dated — even when the record is holding it.
     *
     * The record is per installation and outlives the boot that wrote it, so it can hold a
     * finding about a different reader problem than the one in front of the operator. Citing
     * it would answer "since when" with another question's date.
     */
    public function test_the_reader_windows_row_does_not_date_a_problem_it_is_not_reporting(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([
            ['start' => '10:00:00', 'end' => '14:20:00'],
            ['start' => '17:00:00', 'end' => '09:00:00'],
        ], [1, 2, 3, 4, 5]);

        $this->recordReaderFindings([
            WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED => '2026-09-20T08:15:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('WARN  reader windows', $row);
        $this->assertStringContainsString('window 2 has a start that is not before its end', $row);
        $this->assertStringNotContainsString('recorded unresolved', $row, 'this row is about a window, not about a refusal');
        $this->assertSame(0, $exit);

        // The same run, with the finding that *is* being reported on record: now the row dates
        // it, because the key it reads is the key of the problem it names.
        $this->recordReaderFindings([
            WeightedDatabaseServiceProvider::KEY_READER_UNMATCHABLE_WINDOWS => '2026-09-24T21:05:00+00:00',
        ]);

        [$output] = $this->doctor();

        $this->assertStringContainsString(
            'recorded unresolved since 2026-09-24T21:05:00+00:00',
            $this->rowContaining($output, 'reader windows'),
        );
    }

    /**
     * The repair, printed under the row: the refused value re-spelled as the setting reads
     * it, so the fix is a copy rather than an interpretation of the sentence above it.
     *
     * The row itself is unchanged — same FAIL, same exit code — because a suggestion is
     * not a verdict: the value is still refused, the boot still logs it and a pipeline
     * still stops on it.
     */
    public function test_the_reader_windows_row_prints_the_repair_for_a_flat_string(): void
    {
        $this->armPgcat();
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  reader windows', $this->rowContaining($output, 'reader windows'));
        $this->assertSame(
            "suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]",
            $this->suggestion($output),
        );
        $this->assertStringContainsString(
            count($this->documentedChecks()) . ' checks',
            $output,
            'a suggestion belongs to a row, it is not a check of its own',
        );
        $this->assertSame(1, $exit);
    }

    /**
     * The pooler's own configuration against the reader windows, at the moment of the run: the one
     * question the boot window cannot answer, because a task that boots outside a reader window
     * converges to the writer-only config and looks perfect until a window opens and nothing moves
     * pgcat with it.
     *
     * The row leads with which end of the last window was missed, and this is the end an operator
     * meets late in the day: the readers config still in place after the last window closed, so
     * reads keep reaching a replica through the hours the fallback holds them on the writer. Today
     * is excluded from the day list, so no window is open at whichever hour the suite runs at.
     */
    public function test_the_pgcat_mode_row_names_the_readers_config_left_outside_a_reader_window(): void
    {
        $stamp = $this->tempDir().'/container-booted-at';
        file_put_contents($stamp, (string) (time() - 3600));

        $paths = $this->armPgcat(['boot_file' => $stamp, 'flip_window_seconds' => 480]);

        $this->useReaderFallback(
            [['start' => '00:00:00', 'end' => '23:59:59']],
            [((int) date('N') % 7) + 1],
        );

        // The swap at the window's end that never happened.
        copy($paths['readers_path'], $paths['config_path']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pgcat mode');

        $this->assertStringStartsWith('FAIL  pgcat mode', $row);
        $this->assertStringContainsString('outside the reader window', $output);
        $this->assertStringNotContainsString('inside the reader window', $output);

        // The flipper's own sentence travels rather than a second copy of it: the same words
        // `/health/db` reports and logs, so the endpoint, the log line and the preflight cannot
        // drift apart — and it names the file in place and the command that moves it.
        $this->assertStringContainsString('no reader window is open', $output);
        $this->assertStringContainsString('db:pgcat-flip', $output);

        $this->assertSame(1, $exit, 'a pooler on the wrong configuration is a failure, and `--strict` is not what decides it');
    }

    /**
     * The other direction, and the one a boot stamp cannot excuse: a reader window is open, so the
     * resolver is asking for the replica pool, and the writer-only config is still in place — every
     * read meant for the pool reaches the writer while `/health/db` answers `ok` to a monitor that
     * only reads the payload's verdict. Same row, different words, and that is the whole point of
     * naming the direction rather than leaving it to the sentence under it.
     */
    public function test_the_pgcat_mode_row_names_a_writer_only_config_inside_a_reader_window(): void
    {
        $stamp = $this->tempDir().'/container-booted-at';
        file_put_contents($stamp, (string) (time() - 3600));

        $paths = $this->armPgcat(['boot_file' => $stamp, 'flip_window_seconds' => 480]);

        // A window covering the whole day, on every day of the week: open at whatever hour the
        // suite runs at.
        $this->useReaderFallback([['start' => '00:00:00', 'end' => '23:59:59']], [1, 2, 3, 4, 5, 6, 7]);

        copy($paths['no_readers_path'], $paths['config_path']);

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  pgcat mode', $this->rowContaining($output, 'pgcat mode'));
        $this->assertStringContainsString('inside the reader window', $output);
        $this->assertStringNotContainsString('outside the reader window', $output);
        $this->assertStringContainsString('a reader window is open', $output);

        $this->assertSame(1, $exit);
    }

    /**
     * The running pooler, asked directly: the one row that does not read a file, a program list or
     * a state store for its answer, and the read-only half of the self-heal the flip performs.
     */
    public function test_the_pool_probe_row_passes_when_the_pooler_answers_for_every_pool(): void
    {
        $this->armPgcatWithPoolProbe(['writer_db_1', 'reader_db_2']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('PASS  pool probe', $row);
        $this->assertStringContainsString('answered for every pool the configuration declares (writer_db_1, reader_db_2)', $row);
        $this->assertStringContainsString('refused a control name it cannot hold', $row, 'the control is what makes the answers proof');
        $this->assertStringContainsString('each answer is proof the pool exists', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * The pooler this row was measured against authenticates before it resolves a pool and answers
     * every name, and this installation has no console for the row to fall back on. The row warns
     * rather than passing: nothing is wrong with the installation, but nothing established that its
     * pools exist either — and a `PASS` would be read as the answer the reading never gave.
     */
    public function test_the_pool_probe_row_warns_when_the_poolers_answers_are_not_proof(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], control: 'answered');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('WARN  pool probe', $row);
        $this->assertStringContainsString('answered the sentinel name "weighted-dbmanager-control-', $row);
        $this->assertStringContainsString('does not show that the pool exists', $row);
        $this->assertStringContainsString('indeterminate rather than a pass', $row);
        $this->assertStringContainsString('— probed tcp://127.0.0.1:7432', $row);
        $this->assertSame(0, $exit, 'a reading that could not be established is not a fault in the installation');
    }

    /**
     * The reading the row prefers: the pooler's own list names the pool. The row passes because the
     * question was answered, not because nothing was asked — and it names the console as the
     * reading, so a green row says which credential was exercised to get it.
     */
    public function test_the_pool_probe_row_passes_when_the_console_lists_every_pool(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], control: 'answered', admin: [
            'status' => 'read',
            'pools' => ['reader_db_2'],
            'evidence' => 'the admin console at tcp://127.0.0.1:7432 listed 1 pool(s)',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('PASS  pool probe', $row);
        $this->assertStringContainsString('own admin console lists every pool the configuration declares', $row);
        $this->assertStringContainsString('listed 1 pool(s)', $row, 'the console\'s own sentence, not a paraphrase of it');
        $this->assertSame(0, $exit);
    }

    /**
     * The fault this row exists for, found by the reading that cannot be fooled by a pooler's
     * ordering: the pooler's own console says it holds no such pool. That is a `FAIL` past the boot
     * window, with the reading that found it named in the sentence.
     */
    public function test_the_pool_probe_row_fails_when_only_the_console_knows_the_pool_is_missing(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], control: 'answered', admin: [
            'status' => 'read',
            'pools' => [],
            'evidence' => 'the admin console at tcp://127.0.0.1:7432 listed 0 pool(s)',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('FAIL  pool probe', $row);
        $this->assertStringContainsString('holds no pool "reader_db_2"', $row);
        $this->assertStringContainsString('admin console lists no pool "reader_db_2"', $row, 'the console is the reading, so it is what the row names');
        $this->assertSame(1, $exit, 'a pool the pooler says it does not hold is a failure');
    }

    /**
     * A console that refused the reading — no credentials in the pooler's file, a password it would
     * not take, a challenge this probe cannot meet — leaves the row warning rather than failing:
     * the console's own sentence is reported, and the installation is not failed for a credential
     * this package could not read.
     */
    public function test_the_pool_probe_row_warns_when_the_console_refuses_the_reading(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], control: 'answered', admin: [
            'status' => 'refused',
            'pools' => [],
            'evidence' => 'the admin console at tcp://127.0.0.1:7432 refused the pooler\'s own credentials: password authentication failed',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('WARN  pool probe', $row);
        $this->assertStringContainsString('admin console could not list them', $row);
        $this->assertStringContainsString('password authentication failed', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * The same unverified reading while the container is still starting is not judged: a pooler a
     * second old may not have read its file yet, so the row passes with the sentence rather than
     * warning about a state the boot window exists to allow.
     */
    public function test_an_unverified_reading_is_not_warned_about_inside_the_boot_window(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], control: 'answered', windowClosed: false);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('PASS  pool probe', $row);
        $this->assertStringContainsString('The boot window is still open', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * The fault this row exists for, in the pooler's own words: pgcat is RUNNING, supervisord says
     * so, and it holds no pool for a database the application is connected to — so every read to
     * that pool fails while every other row of this run passes.
     */
    public function test_the_pool_probe_row_fails_when_the_pooler_holds_no_pool(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], ['reader_db_2' => 'missing']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('FAIL  pool probe', $row);
        $this->assertStringContainsString('holds no pool "reader_db_2"', $row);
        $this->assertStringContainsString('— probed tcp://127.0.0.1:7432', $row, 'the row names the endpoint it asked as well as what the pooler said');
        $this->assertStringContainsString('No pool configured for database', $row, 'the pooler\'s admission rather than a paraphrase of it');
        $this->assertStringStartsWith('PASS  pgcat supervisor', $this->rowContaining($output, 'pgcat supervisor'), 'the program supervisord can see is up, which is exactly why this row exists');
        $this->assertSame(1, $exit, 'a pool the pooler cannot serve is a failure, and `--strict` is not what decides it');
        $this->assertNull($this->suggestion($output), 'the repair is a restart of the pooler, not a value a line can name');
    }

    /**
     * A pooler that answered nothing at all is warned about rather than failed: the address may be
     * the wrong one, and a probe that could fail an installation for that would be worse than the
     * silence it replaced.
     */
    public function test_the_pool_probe_row_warns_for_a_pooler_that_answered_nothing(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], ['reader_db_2' => 'silent']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('WARN  pool probe', $row);
        $this->assertStringContainsString('did not answer for pool "reader_db_2"', $row);
        $this->assertStringContainsString('— probed tcp://127.0.0.1:7432', $row, 'the row names where the packets went, which is what tells a wrong port from a stopped pooler');
        $this->assertSame(0, $exit, 'a pooler that answered nothing is not a failure');
    }

    /**
     * The same answer while the container is still starting is not judged — the flip still has the
     * boot window to re-apply the configuration in — and the row says so rather than passing
     * silently, because "nothing was judged" and "the pooler answered" are different statements.
     */
    public function test_the_pool_probe_row_is_not_judged_while_the_boot_window_is_open(): void
    {
        $this->armPgcatWithPoolProbe(['reader_db_2'], ['reader_db_2' => 'missing'], windowClosed: false);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('PASS  pool probe', $row);
        $this->assertStringContainsString('boot window is still open', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * The documented default, and the reason the row costs nothing on an installation that has not
     * opted in: the pooler is taken at supervisord's word, which cannot tell a pool it is not
     * serving from one it is.
     */
    public function test_the_pool_probe_row_says_when_the_probe_is_not_armed(): void
    {
        // The installation the note describes as working, store included: this test reads the exit
        // code, so every other row has to be a pass rather than whatever the machine answers.
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool probe');

        $this->assertStringStartsWith('PASS  pool probe', $row);
        $this->assertStringContainsString('the probe is not armed (swrr.pgcat.pool_probe.enabled', $row, 'the row names the switch and the port source, because either one alone arms nothing');
        $this->assertSame(0, $exit);
    }

    /**
     * The count and the time a repair leaves behind, on the preflight rather than only in the
     * status table: `--status` is a table an operator runs when they already suspect the pooler,
     * and this row is read whether or not anybody does.
     */
    public function test_the_pool_repairs_row_reports_the_count_and_time_the_flip_wrote(): void
    {
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();

        file_put_contents($paths['state_file'], (string) json_encode([
            'pool_repairs' => 2,
            'last_pool_repair_at' => '2026-10-10T12:00:00+00:00',
        ]));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool repairs');

        $this->assertStringStartsWith('PASS  pool repairs', $row);
        $this->assertStringContainsString('the pooler has been repaired 2 time(s)', $row);
        $this->assertStringContainsString('the last at 2026-10-10T12:00:00+00:00', $row);
        $this->assertStringContainsString('convergence history', $row, 'a count that is not moving is not a pooler that stopped needing repair');
        $this->assertSame(0, $exit, 'a repair is the self-heal working, not a failure of the installation');
    }

    /**
     * A container that has never repaired anything reads none, and says so rather than printing an
     * empty count — and a state file an operator edited into a counter that is not a number reads
     * the same way, because the file is theirs to edit and one field must not have two shapes.
     */
    public function test_the_pool_repairs_row_reads_no_repairs_as_none(): void
    {
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'pool repairs');

        $this->assertStringStartsWith('PASS  pool repairs', $row);
        $this->assertStringContainsString('no pool repair is recorded in this container', $row);
        $this->assertSame(0, $exit);

        file_put_contents($paths['state_file'], (string) json_encode([
            'pool_repairs' => 'two',
            'last_pool_repair_at' => ['2026-10-10'],
        ]));

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'pool repairs');

        $this->assertStringStartsWith('PASS  pool repairs', $row);
        $this->assertStringContainsString('no pool repair is recorded in this container', $row);
    }

    /**
     * The two surfaces are one reading: the row prints what the flipper's `--status` block is built
     * from, so a count that moved in one moves in the other. A row that parsed the state file for
     * itself would be the way the two start disagreeing, silently, about a file only the flip
     * writes.
     */
    public function test_the_pool_repairs_row_is_the_reading_the_status_table_prints(): void
    {
        $this->bindRedis(reachable: true);
        $paths = $this->armPgcat();

        file_put_contents($paths['state_file'], (string) json_encode([
            'pool_repairs' => 7,
            'last_pool_repair_at' => '2026-10-11T05:30:00+00:00',
        ]));

        [$output] = $this->doctor();

        $flipper = $this->app->make(PgcatConfigFlipper::class);
        $reading = $flipper->poolRepairStatus();

        $this->assertSame(7, $reading['count']);
        $this->assertStringContainsString(
            sprintf('the pooler has been repaired %d time(s), the last at %s', $reading['count'], $reading['last_at']),
            $this->rowContaining($output, 'pool repairs'),
            'the row is built from the reading `--status` prints, not from a second parse of the file',
        );
        $this->assertSame($reading['count'], $flipper->windowStatus()['pool_repairs']);
        $this->assertSame($reading['last_at'], $flipper->windowStatus()['last_pool_repair_at']);
    }

    /**
     * The days key is refused inside the same row, so its repair is the same line naming
     * the other setting — the one spelling of the mistake that reduces to an exact value.
     */
    public function test_the_reader_windows_row_prints_the_repair_for_a_day_list_written_as_one_string(): void
    {
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], '1,2,3');

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  reader windows', $this->rowContaining($output, 'reader windows'));
        $this->assertSame('suggestion          swrr.reader_days = [1, 2, 3]', $this->suggestion($output));
        $this->assertSame(1, $exit);
    }

    /**
     * The one-character mistake the record's own tests pin as the most common: a window
     * written without its list. The row blames the entries by name, and the repair puts the
     * list back.
     */
    public function test_the_reader_windows_row_prints_the_repair_for_a_window_written_without_its_list(): void
    {
        $this->armPgcat();
        $this->useReaderFallback(['start' => '10:00:00', 'end' => '14:20:00'], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_windows["start"] is "10:00:00"', $row);
        $this->assertSame(
            "suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]",
            $this->suggestion($output),
        );
        $this->assertSame(1, $exit);
    }

    /**
     * A refused value that does not reduce to one gets no suggestion, which is a decision
     * and not an omission.
     *
     * `'22:00-06:00'` names an overnight range, and this resolver cannot express one — its
     * end is exclusive and within the same day. Expanding it would hand back a window that
     * can never be entered, turning this FAIL into a warning about a window nobody can use;
     * `'10:00 to 14:20'` is a string the package would have to guess at, and a guess pasted
     * into configuration routes reads somewhere the operator did not choose. Both keep the
     * sentence that says what a window is, and get no line pretending to know the intent.
     */
    public function test_the_reader_windows_row_prints_no_repair_it_would_have_to_guess_at(): void
    {
        $this->armPgcat();
        $this->useReaderFallback(['22:00-06:00'], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('FAIL  reader windows', $this->rowContaining($output, 'reader windows'));
        $this->assertNull($this->suggestion($output), 'a window that can never be entered is not a repair');
        $this->assertSame(1, $exit);

        $this->useReaderFallback(['10:00 to 14:20'], [1, 2, 3, 4, 5]);

        [$output] = $this->doctor();

        $this->assertStringContainsString('reader_windows[0] is "10:00 to 14:20"', $output);
        $this->assertNull($this->suggestion($output), 'the package does not guess at what a hand-written range meant');
    }

    public function test_the_reader_windows_row_warns_when_the_day_list_decides_nothing(): void
    {
        // Windows that are fine and no days at all: the resolver is permissive, so reads
        // use the replica pool on every day instead of stopping outside the windows. An
        // empty list is a choice; a list of things that are not days is refused instead,
        // in the test below.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], []);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('WARN  reader windows', $row);
        $this->assertStringContainsString('swrr.reader_days holds no usable day', $row);
        $this->assertStringContainsString('the resolver is permissive', $row);
        $this->assertSame(0, $exit, 'a warning fails a preflight only under --strict');

        [, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict fails the deploy for a fallback that cannot apply');
    }

    public function test_the_reader_windows_row_fails_a_day_list_written_as_one_string(): void
    {
        // The `.env` spelling of a list, and the one thing this setting cannot read. It
        // is refused the way a flat reader_windows string is — as input, at the same
        // level, naming the value and the shape it should have had — because dropping it
        // leaves no day, and no day makes the resolver permissive: a day list meant to
        // restrict reads runs as the opposite.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], '1,2,3');

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('swrr.reader_days is "1,2,3", not a list of days', $row);
        $this->assertStringContainsString("a list written as one string such as '1,2,3' is not one", $row);
        $this->assertStringContainsString('nothing is left to apply, so reads use the replica pool on every day', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_reader_windows_row_fails_a_day_entry_that_is_not_a_day(): void
    {
        // One bad entry, one good day: the row names the entry at its position and says
        // that the rest still apply, so the operator can see which half of the list the
        // resolver is using.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], ['1', 'monday']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_days[1] is "monday"', $row);
        $this->assertStringContainsString('1 well-formed day(s) still apply', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_reader_windows_row_passes_the_accepted_spellings_of_a_day_list(): void
    {
        // An array of day numbers, whatever type the values arrive as, is not refused:
        // this is the shape the setting documents, and the one a caller should write.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], ['1', 2, '3']);

        [$output, $exit] = $this->doctor();

        $this->assertStringStartsWith('PASS  reader windows', $this->rowContaining($output, 'reader windows'));
        $this->assertSame(0, $exit);
    }

    public function test_the_reader_windows_row_warns_when_no_day_can_be_a_reader_day(): void
    {
        // 0 and 8 are not an empty list, so the resolver is not permissive either: no day
        // matches, the pool is never used, and the writer takes every read.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [0, 8]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('WARN  reader windows', $row);
        $this->assertStringContainsString('no day in 1…7 (read as 0, 8)', $row);
        $this->assertStringContainsString('the replica pool is never used', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_reader_windows_row_warns_when_every_window_is_unreachable(): void
    {
        // Start inclusive, end exclusive: an overnight window is never entered, so an
        // installation whose windows all look like this never uses the pool at all.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([
            ['start' => '22:00:00', 'end' => '06:00:00'],
            ['start' => '23:00:00', 'end' => '06:00:00'],
        ], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('WARN  reader windows', $row);
        $this->assertStringContainsString('every window (window 1, window 2)', $row);
        $this->assertStringContainsString('none can be entered', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_reader_windows_row_warns_about_the_one_window_that_can_never_be_entered(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([
            ['start' => '10:00:00', 'end' => '14:20:00'],
            ['start' => '17:00:00', 'end' => '09:00:00'],
        ], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('WARN  reader windows', $row);
        $this->assertStringContainsString('window 2 has a start that is not before its end', $row);
        $this->assertStringContainsString('it can never be entered', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_reader_windows_row_passes_a_fallback_that_can_apply(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('PASS  reader windows', $row);
        $this->assertStringContainsString('1 window(s) on 5 reader day(s)', $row);
        $this->assertStringContainsString('reads fall back to the writer outside them', $row);
        $this->assertStringNotContainsString('recorded unresolved', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_reader_windows_row_passes_the_documented_opt_out(): void
    {
        // No windows at all: reads use the pool by design, and a day list left behind
        // cannot make them stop. Nothing here is worth a warning, let alone a failure.
        $this->bindRedis(reachable: true);
        $this->armPgcat();
        $this->useReaderFallback(null, [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('PASS  reader windows', $row);
        $this->assertStringContainsString('no reader_windows set', $row);
        $this->assertStringContainsString('the documented opt-out', $row);
        $this->assertSame(0, $exit);
    }
    // ─────────────────────────────────────────────────────────────────────────
    // flip schedule — which mechanism moves the pooler, and at what
    //
    // The state this row is here for is silent by construction: the per-minute entry bounds
    // *converging at boot*, so a container that came up outside a window stops following the
    // day the moment its window closes. Every other row can read well in that installation —
    // the flipper is armed, the files are writable, the windows are well formed — and this is
    // the one that says nothing will move pgcat when 10:00 arrives.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_flip_schedule_row_reports_a_pooler_converged_at_boot_only(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('PASS  flip schedule', $row);
        $this->assertStringContainsString('converged at boot only', $row);
        $this->assertStringContainsString('the reader-window tasks are off', $row);
        $this->assertStringContainsString('nothing moves it as a reader window opens or closes', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.windows.enabled is the setting that does', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_flip_schedule_row_passes_the_window_tasks_and_names_their_boundaries(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        $this->useReaderFallback(
            [['start' => '10:00:00', 'end' => '14:20:00'], ['start' => '17:00:00', 'end' => '20:30:00']],
            [1, 2, 3, 4, 5],
        );
        // And this entry disarmed on purpose: with both armed the row warns that two mechanisms own
        // the mode, which is the test below this one. One mechanism is the clean reading here, and
        // the two switches are written in one call because they are read out of one block.
        config()->set('db-manager.swrr.pgcat.schedule', [
            'enabled' => false,
            'windows' => ['enabled' => true],
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('PASS  flip schedule', $row);
        $this->assertStringContainsString('the pooler follows the day: 4 boundary task(s)', $row);
        $this->assertStringContainsString('activate_readers:10:00', $row);
        $this->assertStringContainsString('deactivate_readers:14:20', $row);
        $this->assertStringContainsString('activate_readers:17:00', $row);
        $this->assertStringContainsString('deactivate_readers:20:30', $row);
        $this->assertStringContainsString('probing from 480s before its boundary to 480s after it', $row);
        // The per-minute entry is off here, and the row says so rather than leaving the operator to
        // work out from two switches which mechanism answers a boundary.
        $this->assertStringContainsString('the per-minute entry is off, so one mechanism decides the mode', $row);
        $this->assertStringNotContainsString('two mechanisms own the pooler', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * Both mechanisms armed: reported by name, as a warning, and dated from its own finding.
     *
     * Neither arrangement is malformed — an installation may be halfway through the documented
     * migration, which is to disarm this entry in the same change that arms the tasks — so this is
     * not a failure. What it must not be is silent: the two keys are named, and what they share is
     * the part neither setting shows on its own, because a mode the tasks apply goes through the
     * flipper's forced path and is not recorded as a boot convergence. The record is what the key
     * adds over the row: this row describes this boot, and `recorded unresolved since` says how long
     * the pair has been armed.
     */
    public function test_the_flip_schedule_row_warns_when_both_mechanisms_are_armed(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);
        // `swrr.pgcat.schedule.enabled` is not written here: `armPgcat()` arms `pgcat.enabled` and
        // the entry follows it, which is the state an installation that turned the tasks on without
        // disarming this one actually lands in.
        config()->set('db-manager.swrr.pgcat.schedule.windows.enabled', true);

        $this->recordFindings([
            WeightedDatabaseServiceProvider::KEY_FLIP_MECHANISMS_CONTENDED => '2026-09-20T08:15:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('WARN  flip schedule', $row);
        $this->assertStringContainsString('the pooler follows the day: 2 boundary task(s)', $row);
        $this->assertStringContainsString('activate_readers:10:00', $row);
        $this->assertStringContainsString('deactivate_readers:14:20', $row);
        $this->assertStringContainsString('two mechanisms own the pooler\'s mode', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.enabled', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.windows.enabled are both on', $row);
        $this->assertStringContainsString('no boundary is contended', $row);
        $this->assertStringContainsString('not written as a boot convergence', $row);
        $this->assertStringContainsString('Arm one and disarm the other in the same change', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-20T08:15:00+00:00', $row);
        $this->assertSame(0, $exit, 'a warning fails a preflight only under --strict');

        [, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict fails a release for two mechanisms owning one mode');
    }

    public function test_the_flip_schedule_row_warns_when_nothing_is_scheduled_to_move_the_pooler(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        config()->set('db-manager.swrr.pgcat.schedule', ['enabled' => false]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('WARN  flip schedule', $row);
        $this->assertStringContainsString('nothing is scheduled to move the pooler', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.enabled', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.windows.enabled', $row);
        $this->assertStringContainsString('stays where the last write left it', $row);
        $this->assertSame(0, $exit, 'a warning fails a preflight only under --strict');

        [, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict fails the deploy for a pooler nothing moves');
    }

    public function test_the_flip_schedule_row_warns_when_the_tasks_are_on_and_no_boundary_is_scheduled(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        // Ten minutes long, where the lead and the grace ask for sixteen: the deactivation would
        // begin probing before the activation could have acted, so the pair is skipped rather than
        // approximated — the state a setting that reads on and does nothing leaves behind.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '10:10:00']], [1, 2, 3, 4, 5]);
        config()->set('db-manager.swrr.pgcat.schedule.windows.enabled', true);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('WARN  flip schedule', $row);
        $this->assertStringContainsString('is on and no boundary is scheduled', $row);
        $this->assertStringContainsString('10:00:00–10:10:00', $row);
        $this->assertStringContainsString('the window is 10 minute(s) long', $row);
        $this->assertSame(0, $exit, 'a warning fails a preflight only under --strict');

        [, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict fails the deploy for tasks that are on and scheduled to nothing');
    }

    public function test_the_flip_schedule_row_fails_a_schedule_switch_that_is_not_on_or_off(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        config()->set('db-manager.swrr.pgcat.schedule', ['enabled' => 'maybe']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('FAIL  flip schedule', $row);
        $this->assertStringContainsString('swrr.pgcat.schedule.enabled is "maybe"', $row);
        $this->assertStringContainsString('a switch is on or off', $row);
        // The switch's own fallback, named rather than guessed at: the per-minute entry follows
        // `swrr.pgcat.enabled`, so a typo does not disarm the flip.
        $this->assertStringContainsString('the per-minute entry is registered as on', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_flip_schedule_row_is_moot_while_the_flipper_is_not_armed(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'flip schedule');

        $this->assertStringStartsWith('PASS  flip schedule', $row);
        $this->assertStringContainsString('flipper is not armed', $row);
        $this->assertSame(0, $exit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // schedule beat — whether the mechanism has been running, and the three causes a
    // verdict cannot see
    //
    // `flip schedule` reads what is *configured* to move the pooler; this row reads whether
    // that mechanism has been *running*, which is a different failure at a different moment.
    // Three of the causes of a silence are invisible in the state file the verdict reads — a
    // store the runs' mutexes cannot be taken in, two entries running one command, a boot
    // stamp nothing can read — so they are read beside the verdict and named in the row.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An armed, running installation: the verdict passes and the row *also* names what it read.
     *
     * The readings are attached rather than judged, which is why the row still passes: the status
     * and the exit code stay the beat's, and none of these is a state a release should be refused
     * over on its own. The store is asked here, because a preflight may reach out — the mode that may
     * not is the bounded one, and it says so instead (see the test below).
     */
    public function test_the_schedule_beat_row_names_the_readings_beside_the_verdict(): void
    {
        $stamp = $this->tempDir().'/container-booted-at';
        file_put_contents($stamp, (string) (time() - 3600));

        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $paths = $this->armPgcat(['boot_file' => $stamp, 'flip_window_seconds' => 480]);

        // The beat a run of the entry leaves behind, a moment ago, judged against a window that
        // closed an hour ago: the state the row is written for when nothing is wrong.
        file_put_contents($paths['state_file'], (string) json_encode([
            ScheduleBeat::STATE_KEY => gmdate(DATE_ATOM),
        ]));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'schedule beat');

        $this->assertStringStartsWith('PASS  schedule beat', $row);
        $this->assertStringContainsString('the per-minute entry is running', $row);
        $this->assertStringContainsString('The schedule\'s own readings:', $row);
        $this->assertStringContainsString('1 entry carries `'.FlipSchedule::DEFAULT_NAME.'`', $row);
        $this->assertStringContainsString($stamp, $row, 'the stamp the window was judged against');
        $this->assertStringContainsString('readable and writable', $row);
        $this->assertStringContainsString('mutexes live in', $row);
        $this->assertStringContainsString('answered a read', $row, 'a preflight asks the store; the bounded mode is what does not');
        $this->assertSame(0, $exit, 'a reading is not a verdict: the row still passes on the beat');
    }

    /**
     * An installation that has not armed the entry has nothing for the readings to explain, so the
     * row says what the flipper found and nothing else — the same rule the row already follows for
     * the switch that turns the watch off.
     */
    public function test_the_schedule_beat_row_reads_nothing_when_the_entry_is_not_scheduled(): void
    {
        $this->bindRedis(reachable: true);
        $this->usePublishedConfig();
        $this->armPgcat();
        config()->set('db-manager.swrr.pgcat.schedule', ['enabled' => false]);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'schedule beat');

        $this->assertStringContainsString('is not scheduled', $row);
        $this->assertStringNotContainsString('The schedule\'s own readings', $row);
    }

    /**
     * The bounded run is the one that may not reach the store, and its row says which reading it did
     * not take rather than leaving the store's reachability looking unremarkable: a report that cannot
     * ask must not read as one that asked and was answered.
     */
    public function test_the_bounded_run_says_which_reading_it_did_not_take(): void
    {
        $this->usePublishedConfig();
        $this->armPgcat();

        [$output] = $this->doctor(connectionOnly: true);
        $row = $this->rowContaining($output, 'schedule beat');

        $this->assertStringContainsString('The schedule\'s own readings:', $row);
        $this->assertStringContainsString('was not asked', $row);
        $this->assertStringContainsString('bounded to what a build container can answer', $row);
        $this->assertStringNotContainsString('answered a read', $row);
        $this->assertStringContainsString('1 entry carries', $row, 'the other two readings are taken either way');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // switch values — the three on/off settings, read as they were written
    //
    // `(bool) 'false'` is true, so the three commonest ways of writing *off* used to read as
    // *on*, and on `swrr.pgcat.enabled` that arms a file swap. The row is the preflight half
    // of the refusal; the boot audit is the other, and both read the same classifier.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_switch_values_row_passes_when_every_switch_reads_as_written(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('PASS  switch values', $row);
        $this->assertStringContainsString(
            'swrr.pgcat.enabled on, swrr.pgcat.use_reload on, swrr.allow_local_fallback on',
            $row,
            'the row names the value of each switch, so a pass is information rather than silence',
        );
        $this->assertStringContainsString('each reads as on or off', $row);
        $this->assertStringNotContainsString('recorded unresolved', $row);
        $this->assertSame([], $this->suggestions($output), 'a readable switch needs no repair');
        $this->assertSame(0, $exit);
    }

    public function test_the_switch_values_row_fails_a_value_that_is_not_on_or_off(): void
    {
        // The typo this whole rule exists for. Nothing is guessed at and nothing is repaired:
        // the row fails, quotes the value, quotes the accepted spellings and says what the
        // setting falls back to.
        $this->bindRedis(reachable: true);
        $this->armPgcat(['enabled' => 'flase']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('FAIL  switch values', $row);
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $row);
        $this->assertStringContainsString('a switch is on or off', $row);
        $this->assertStringContainsString("'on'/'off'", $row);
        $this->assertStringContainsString('falls back to off', $row, 'the row names the value the setting holds instead');
        $this->assertSame([], $this->suggestions($output), 're-spelling "flase" would be guessing at what was meant');
        $this->assertSame(1, $exit, 'a refused value fails a deploy gate');
    }

    public function test_the_switch_values_row_reads_off_written_as_the_string_false(): void
    {
        // The exact inversion the cast produced. `'false'` is off, so there is nothing to
        // refuse and nothing to arm — and the row says `off` rather than `on`.
        $this->bindRedis(reachable: true);
        $this->armPgcat(['enabled' => 'false']);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('PASS  switch values', $row);
        $this->assertStringContainsString('swrr.pgcat.enabled off', $row);
        $this->assertSame(0, $exit);
    }

    public function test_the_switch_values_row_names_every_switch_it_refuses(): void
    {
        // Three settings, three typos, one row: they are one mistake repeated, and a row each
        // would make them look like three unrelated faults. Each keeps its own sentence, so an
        // operator who fixes one is still told about the other two.
        $this->bindRedis(reachable: true);
        $this->armPgcat(['enabled' => 'flase', 'use_reload' => 'maybe']);
        config()->set('db-manager.swrr.allow_local_fallback', 2);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('FAIL  switch values', $row);
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $row);
        $this->assertStringContainsString('swrr.pgcat.use_reload is "maybe"', $row);
        $this->assertStringContainsString('swrr.allow_local_fallback is int', $row);
        $this->assertSame(1, $exit, 'the row is one check however many switches it refuses');
        $this->assertSame([], $this->suggestions($output));
    }

    public function test_the_switch_values_row_dates_each_refusal_from_its_own_finding(): void
    {
        // One date on both would claim the installation started being wrong about both at
        // once, which is the claim the per-setting keys exist to avoid making.
        $this->bindRedis(reachable: true);
        $this->armPgcat(['enabled' => 'flase']);
        config()->set('db-manager.swrr.allow_local_fallback', 'nope');
        $this->recordSwitchFindings([
            WeightedDatabaseServiceProvider::KEY_PGCAT_ENABLED_REFUSED => '2026-09-20T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_FALLBACK_REFUSED => '2026-09-24T21:05:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('FAIL  switch values', $row);
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-20T08:15:00+00:00', $row);
        $this->assertStringContainsString('swrr.allow_local_fallback is "nope"', $row);
        $this->assertStringContainsString('recorded unresolved since 2026-09-24T21:05:00+00:00', $row);
        $this->assertSame(2, substr_count($row, 'recorded unresolved since'), 'one date per refused setting');
        $this->assertSame(1, $exit);
    }

    public function test_the_switch_values_row_does_not_date_a_switch_it_is_not_reporting(): void
    {
        // A switch on record that reads fine now: the boot that saw it fixed closed it out,
        // or never ran. Either way the row is not saying anything about it, and a date would
        // be a claim about the wrong setting.
        $this->bindRedis(reachable: true);
        $this->armPgcat(['enabled' => 'flase']);
        $this->recordSwitchFindings([
            WeightedDatabaseServiceProvider::KEY_PGCAT_RELOAD_REFUSED => '2026-09-20T08:15:00+00:00',
        ]);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('FAIL  switch values', $row);
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $row);
        $this->assertStringNotContainsString('recorded unresolved since', $row);
        $this->assertSame(1, $exit);
    }

    public function test_the_switch_values_row_reports_nothing_when_the_flipper_cannot_be_built(): void
    {
        // A duplicate of the failure `pgcat gate` already reports, and deliberately so: the
        // two pgcat switches are read *through* the flipper, and a row that claimed they were
        // fine because it could not ask would be the silent pass this row exists to remove.
        $this->bindRedis(reachable: true);
        $this->app->bind(PgcatConfigFlipper::class, static fn () => throw new RuntimeException('not today'));

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'switch values');

        $this->assertStringStartsWith('FAIL  switch values', $row);
        $this->assertStringContainsString('the flipper could not be built', $row);
        $this->assertSame(1, $exit);
    }

    public function test_it_warns_when_the_config_has_never_been_published(): void
    {
        // Testbench's skeleton has no config/db-manager.php, which is exactly the
        // fresh-install case: the package sample is running the installation.
        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'published config');

        $this->assertStringStartsWith('WARN  published config', $row);
        $this->assertStringContainsString('vendor:publish --tag=db-manager-config', $row);
    }

    /**
     * What a published file's own *key set* is worth saying — the half a published copy cannot keep
     * in step on its own, because nothing writes over it again unless somebody asks for `--force`.
     *
     * Each case is one state of the reading: the sample's own number (silent), a key set behind it,
     * a file published before the key existed — which is every copy published before this release —
     * a value nobody can read as a whole number, and a file from a newer release than the package
     * running. The row is asserted by its verdict and by one phrase the reading is about rather than
     * word for word: `Support\ConfigSchemaTest` holds the sentences, and copying them here as well
     * would be a second spelling of one rule.
     *
     * @param string $literal what the sample's `generation` line is replaced with — empty, for a file
     *                        that predates the key rather than carrying a number
     * @param string $verdict `PASS` or `WARN`, the verdict the row prints
     * @param string $phrase  something the detail has to carry for the row to be about this case
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function publishedGenerations(): array
    {
        return [
            'the key set this package ships' => ["'generation' => ".ConfigSchema::GENERATION.',', 'PASS', 'current key set'],
            'a key set behind this one' => ["'generation' => ".(ConfigSchema::GENERATION - 1).',', 'WARN', 'republish'],
            'published before the key existed' => ['', 'WARN', 'before the key existed'],
            'a value that is not a whole number' => ["'generation' => 'five',", 'WARN', "'five'"],
            'a file from a newer release' => ["'generation' => ".(ConfigSchema::GENERATION + 1).',', 'WARN', 'ignored'],
        ];
    }

    #[DataProvider('publishedGenerations')]
    public function test_the_published_config_row_reads_the_key_set_the_file_itself_declares(string $literal, string $verdict, string $phrase): void
    {
        $this->publishConfigDeclaring($literal);

        [$output] = $this->doctor();

        $row = $this->rowContaining($output, 'published config');

        $this->assertStringStartsWith($verdict.'  published config', $row);
        $this->assertStringContainsString($phrase, $row);
    }

    public function test_a_clean_installation_says_so(): void
    {
        // Reads go through the writer, the store answers, pgcat is off and the
        // config is published: nothing to warn about, so the doctor has to say so
        // rather than leave the operator counting rows.
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        [$output, $exit] = $this->doctor();

        $this->assertStringContainsString('0 failed', $output);
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertSame(0, $exit);
    }

    /**
     * The two facts no other row can state, in the state a deploy normally leaves them: a settings
     * file this boot read, and no configuration cache — so the values every other row judged are the
     * ones the files spell.
     */
    public function test_the_boot_environment_row_names_the_file_this_boot_read(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        $file = $this->useEnvironmentFile();

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'boot environment');

        $this->assertStringStartsWith('PASS  boot environment', $row);
        $this->assertStringContainsString($file, $row, 'the row names the file it read rather than the convention');
        $this->assertStringContainsString('the configuration is not cached', $row);
        $this->assertSame(0, $exit);
    }

    /**
     * A cache built at or after the settings file is the order `config:cache` documents, so the
     * values the rows above read are the ones the files spell and the row is a fact, not a warning.
     */
    public function test_the_boot_environment_row_passes_a_cache_that_is_not_older(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        $this->useEnvironmentFile();
        $cache = $this->useConfigCache();

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'boot environment');

        $this->assertStringStartsWith('PASS  boot environment', $row);
        $this->assertStringContainsString($cache, $row);
        $this->assertStringContainsString('not older than that file', $row);
    }

    /**
     * The trap the row exists for, and the one state it fails a release on: an image cached its
     * configuration at build time and the `.env` beside it was written afterwards, so the deploy's
     * setting reaches nothing — the rows above are judging the cached values.
     */
    public function test_the_boot_environment_row_warns_when_the_settings_file_is_newer_than_the_cache(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        $file = $this->useEnvironmentFile();
        $cache = $this->useConfigCache();

        // Written after the cache, which is what the write order of a cache-then-mount deploy is.
        touch($file, time() + 60);

        [$output, $exit] = $this->doctor();
        $row = $this->rowContaining($output, 'boot environment');

        $this->assertStringStartsWith('WARN  boot environment', $row);
        $this->assertStringContainsString($cache, $row);
        $this->assertStringContainsString('config:clear', $row, 'the warning names the one command that repairs it');
        $this->assertSame(0, $exit, 'a warning is a fact until the run is a gate');

        [$strict, $strictExit] = $this->doctor(strict: true);

        $this->assertSame(1, $strictExit, '--strict is how a deploy refuses to ship a configuration nothing obeys');
        $this->assertStringContainsString('--strict counts the', $strict);
    }

    /**
     * The mode's whole claim, asserted from the outside rather than from the row list: a run whose
     * store would refuse and whose supervisor records every command still exits 1 for a pgcat switch
     * armed over a connection pgcat cannot front — and neither the store nor the supervisor was ever
     * reached, because the six rows whose subject is outside the process were left out rather than
     * judged.
     *
     * The rows it does ask are compared with a full run's, by name and in order, which is the half a
     * count cannot state: `checks` is the full list less those six, so nothing else moved.
     */
    public function test_the_connection_only_mode_asks_nothing_outside_the_container(): void
    {
        $redis = $this->bindRedis(reachable: false);
        $this->armPgcat();
        $supervisor = $this->bindSupervisor();

        // A connection pgcat can never front: the state a build container is most likely to be in,
        // and the one this mode still has to fail on.
        config()->set('database.connections.' . self::CONNECTION . '.driver', 'sqlite');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor(connectionOnly: true);
        $gate = $this->rowContaining($output, 'pgcat gate');

        $this->assertStringStartsWith('FAIL  pgcat gate', $gate);
        $this->assertStringContainsString('pgcat will never act', $gate);
        $this->assertSame(1, $exit, 'the mode is bounded in what it asks, not in what it reports');

        $this->assertSame([], $redis->asked, 'a bounded run opens no connection to the store');
        $this->assertSame([], $supervisor->ran, 'a bounded run spawns nothing, so supervisor is never asked');
        $this->assertStringContainsString('--connection-only', $output, 'the rendered run says it was bounded');

        $leftOut = ['pgcat supervisor', 'pool probe', 'read caps', 'standby feedback', 'store probe', 'store reachability'];

        [$json, $jsonExit] = $this->doctorJson(connectionOnly: true);
        $report = $this->report($json);

        $this->assertSame($exit, $jsonExit, 'the report and the table are one run');
        $this->assertTrue($report['connection_only'], 'the object says the run was bounded, so a gate can see a row it wants was never asked');

        $names = array_column($report['checks'], 'name');

        $this->assertSame([], array_values(array_intersect($leftOut, $names)), 'the six rows that reach outside the process are not asked at all');

        // Nothing else moved: the full run's rows, less those four, in the order the full run asks
        // them. The full run is asked last, because it is the one that spends the store and the
        // supervisor the assertions above hold to nothing.
        [$full] = $this->doctorJson();
        $fullNames = array_column($this->report($full)['checks'], 'name');

        $this->assertSame($leftOut, array_values(array_intersect($leftOut, $fullNames)), 'the full run asks the six rows this test is about');
        $this->assertSame(
            array_values(array_diff($fullNames, $leftOut)),
            $names,
            'a bounded run asks the same rows, in the same order, less the ones that reach out',
        );
        $this->assertFalse($this->report($full)['connection_only']);
    }

    /**
     * The same rule where there is no weighted manager: of the rows that do not need one, the
     * supervisor row is the only one whose subject is outside the process, and it is the one that
     * goes.
     */
    public function test_the_connection_only_mode_leaves_the_supervisor_out_without_the_weighted_manager(): void
    {
        $this->app->instance('db.factory', new ConnectionFactory($this->app));
        $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        DB::clearResolvedInstance('db');

        $supervisor = $this->bindSupervisor();

        [$json] = $this->doctorJson(connectionOnly: true);

        $this->assertSame(
            ['boot environment', 'provider swap', 'weighted factory', 'connector', 'published config', 'pgcat gate', 'pgcat files', 'write paths', 'variant shape'],
            array_column($this->report($json)['checks'], 'name'),
        );
        $this->assertSame([], $supervisor->ran);
    }

    // ──────────────────────────────────────────────────────────────
    // --contract: the deployment's requirements as rows
    // ──────────────────────────────────────────────────────────────

    /**
     * The mode's whole shape: the rows are the record's keys, in the record's order, and the tally
     * counts requirements rather than the readings behind them.
     *
     * By name and order rather than by count, because the count can be right while the mapping is
     * wrong — and it is the names a gate selects on. The keys come from `DeploymentContract`
     * rather than from a literal here, so the table the record states and the table this mode
     * builds are the same table, which `DeploymentContractTest` holds to the record.
     */
    public function test_the_contract_flag_reports_one_row_per_requirement(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        [$json, $exit] = $this->doctorJson(contract: true);
        $report = $this->report($json);

        $this->assertSame(
            DeploymentContract::keys(),
            array_column($report['checks'], 'name'),
            'a contract run names its rows with the keys the record states them with, in its order',
        );

        $this->assertSame(
            count(DeploymentContract::keys()),
            $report['counts']['checks'],
            'the tally counts requirements, not the readings behind them',
        );

        $this->assertSame($exit, $report['exit_code'], 'the object and the process are one run');

        $variants = $this->checkNamed($report, 'variants');

        $this->assertStringContainsString("'variant shape'", $variants['detail']);
        $this->assertStringContainsString("'pgcat files'", $variants['detail'], 'the detail names every reading behind the requirement');
    }

    /**
     * A reading that fails fails the requirement that asks it, and the requirement brings the
     * reading's own sentence and its repair one level up.
     *
     * This is the point of the mode: the deploy fails on `configuration`, and an operator who has
     * never read this package's row list is still told it was `pgcat gate`, why, and the line that
     * repairs it. Nothing is re-worded — the detail and the suggestion are the row's own strings.
     */
    public function test_a_failing_reading_fails_the_requirement_that_asks_it(): void
    {
        $this->bindRedis(reachable: true);
        $this->armPgcat();

        // Armed over a connection pgcat can never front: the gate's own failure, reached in a
        // bounded run, so the requirement is the only thing under test.
        config()->set('database.connections.' . self::CONNECTION . '.driver', 'sqlite');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$json, $exit] = $this->doctorJson(connectionOnly: true, contract: true);
        $row = $this->checkNamed($this->report($json), 'configuration');

        $this->assertSame('FAIL', $row['verdict']);
        $this->assertStringContainsString("'pgcat gate' FAIL", $row['detail']);
        $this->assertStringContainsString('pgcat will never act', $row['detail'], 'the reading\'s own sentence is carried, not paraphrased');
        $this->assertContains('swrr.pgcat.enabled = false', $row['suggestions']);
        $this->assertSame(1, $exit);

        [$output] = $this->doctor(connectionOnly: true, contract: true);

        $this->assertStringContainsString('FAIL  configuration', $output);
        $this->assertStringContainsString('swrr.pgcat.enabled = false', $output, 'the repair the reading named is printed under the requirement');
        $this->assertStringContainsString(
            'A requirement of the deployment contract is not met',
            $output,
            'the closing sentence names the subject rather than calling the installation broken',
        );
    }

    /**
     * The requirement no reading can answer. It passes and says it was not judged, naming what
     * would have to be read — the package cannot fail a deployment over a fact it never saw, and a
     * `PASS` that did not say so would read as a check that ran.
     */
    public function test_a_requirement_no_reading_answers_passes_and_says_it_was_not_judged(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$json] = $this->doctorJson(contract: true);
        $row = $this->checkNamed($this->report($json), 'health-endpoint');

        $this->assertSame('PASS', $row['verdict']);
        $this->assertStringContainsString('not judged here', $row['detail']);
        $this->assertStringContainsString('/health/db', $row['detail'], 'it names the surface it could not read');

        [$output] = $this->doctor(contract: true);

        $this->assertStringContainsString('PASS  health-endpoint', $output);
    }

    /**
     * A bounded contract run: the requirements whose readings reach outside the process are short
     * by design, and each one says which readings it did not ask instead of passing silently.
     *
     * The store is asked nothing — that is what bounds the mode — and the two shapes of "not
     * asked" are told apart: a requirement with no reading asked at all, and one whose readings
     * would have been asked if this were a full run.
     */
    public function test_a_bounded_contract_run_names_the_readings_it_did_not_ask(): void
    {
        $redis = $this->bindRedis(reachable: false);
        $this->armPgcat();

        [$json] = $this->doctorJson(connectionOnly: true, contract: true);
        $report = $this->report($json);

        $this->assertTrue($report['connection_only'], 'the object still says the run was bounded');
        $this->assertSame([], $redis->asked, 'a bounded contract run opens no connection to the store');

        $caps = $this->checkNamed($report, 'read-caps');
        $this->assertSame('PASS', $caps['verdict']);
        $this->assertStringContainsString(
            'not asked: read caps, standby feedback left out by --connection-only',
            $caps['detail'],
            'a requirement nothing answered says so, and names what went unasked',
        );

        $check = $this->checkNamed($report, 'health-check');
        $this->assertSame('PASS', $check['verdict']);
        $this->assertStringContainsString(
            'not asked: pool probe left out by --connection-only; real query was built and not put',
            $check['detail'],
            'the query row is present and says it was not put, so the requirement counts it as unasked rather than as a pass',
        );
        $this->assertStringNotContainsString(
            'none of the readings asked failed',
            $check['detail'],
            'nothing behind this requirement was asked, so it cannot report on what was',
        );
    }

    /**
     * The same requirement in the other direction: with `--real-test` the query is a reading that
     * was taken, and the pool probe is still the one the mode left out — so the sentence names one
     * of each rather than treating the run as all-asked or all-unasked.
     */
    public function test_a_bounded_contract_run_counts_the_query_it_asked_beside_the_reading_it_left_out(): void
    {
        $this->bindRedis(reachable: false);
        $this->armPgcat();
        $this->useSqliteConnection();

        [$json] = $this->doctorJson(connectionOnly: true, contract: true, realTest: true);
        $check = $this->checkNamed($this->report($json), 'health-check');

        $this->assertSame('PASS', $check['verdict']);
        $this->assertStringContainsString("Readings: 'real query' PASS", $check['detail']);
        $this->assertStringContainsString('none of the readings asked failed', $check['detail']);
        $this->assertStringContainsString('pool probe left out by --connection-only', $check['detail']);
        $this->assertStringNotContainsString('was built and not put', $check['detail']);
    }

    /**
     * The other reason a reading is not in the run: no weighted manager, so the nine rows that need
     * one were never built.
     *
     * A contract row must not blame `--connection-only` for that — nobody passed it — so the sentence
     * says what it can see instead, and the requirement is still reported rather than dropped. The
     * rows that were built are still judged: `weighted factory` fails `wiring`, which is the fault
     * that produced the shorter list in the first place.
     */
    public function test_a_contract_run_without_the_weighted_manager_names_the_readings_no_row_was_built_for(): void
    {
        $this->app->instance('db.factory', new ConnectionFactory($this->app));
        $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        DB::clearResolvedInstance('db');

        [$json] = $this->doctorJson(contract: true);
        $report = $this->report($json);

        $this->assertSame('FAIL', $this->checkNamed($report, 'wiring')['verdict'], 'the rows that were built are still judged');

        $configuration = $this->checkNamed($report, 'configuration');

        $this->assertStringContainsString(
            'no row was built for replica metadata, read/write pair, switch values, reader windows',
            $configuration['detail'],
            'the requirement names the readings that were never built',
        );
        $this->assertStringNotContainsString(
            '--connection-only',
            $configuration['detail'],
            'a run nobody bounded does not report the bounded mode as the reason',
        );
    }

    /**
     * The pair is refused rather than half-honoured: a config file is not a deployment, and a run
     * that ignored one of the two flags would report the contract of whichever installation it
     * happens to be running in while the operator believes it judged the candidate.
     */
    public function test_the_contract_and_config_file_flags_are_refused_together(): void
    {
        $path = $this->configFile(['pgcat' => ['enabled' => false]]);
        $buffer = new BufferedOutput();

        $exit = $this->app->make(Kernel::class)->call(
            'db:doctor',
            ['connection' => self::CONNECTION, '--config-file' => $path, '--contract' => true],
            $buffer,
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--contract and --config-file are mutually exclusive', $buffer->fetch());
    }

    /**
     * The setting beside the connection it produced. Unset, the package follows `database.default`;
     * set to that same name it follows a connection somebody chose — the same routing and two
     * different things to change — and the followed connection alone cannot say which one this is.
     */
    public function test_the_report_publishes_the_connection_setting_beside_the_connection_it_followed(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$json] = $this->doctorJson();
        $report = $this->report($json);

        $this->assertNull($report['swrr_connection'], 'a setting that says nothing is published as null, not as a name');
        $this->assertSame(self::CONNECTION, $report['default_connection']);

        // The same name, written — indistinguishable from the run above by everything but this key.
        config()->set('db-manager.swrr.connection', self::CONNECTION);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$output, $exit] = $this->doctor();
        [$json] = $this->doctorJson();
        $report = $this->report($json);

        $this->assertSame(self::CONNECTION, $report['swrr_connection']);
        $this->assertSame(self::CONNECTION, $report['default_connection']);
        $this->assertStringContainsString('named by db-manager.swrr.connection', $output);
        $this->assertSame(0, $exit);
    }

    /**
     * The line a container gate greps, in the spelling it greps for.
     *
     * The deployment's smoke gate parses the resolved connection out of the doctor's log with a
     * `sed` that selects a line beginning with two spaces and `db-manager.swrr.connection`, then
     * strips that much, so the shape of this line — two spaces, the setting's own key, then the
     * value — is an interface rather than formatting: a banner reflowed into prose would leave the
     * gate grepping nothing and reading an empty name as a connection. The two spellings are
     * asserted side by side because which key answers is the answer to "was the setting written, or
     * did the package inherit the default".
     */
    public function test_the_rendered_run_prints_the_setting_a_container_gate_greps(): void
    {
        $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$inherited] = $this->doctor();

        $this->assertSame(
            [self::CONNECTION],
            $this->settingLines($inherited, 'database.default'),
            'an installation that names no connection prints the key it followed',
        );
        $this->assertSame([], $this->settingLines($inherited, 'db-manager.swrr.connection'), 'and not the setting it never wrote');

        config()->set('db-manager.swrr.connection', self::CONNECTION);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$written] = $this->doctor();

        $this->assertSame(
            [self::CONNECTION],
            $this->settingLines($written, 'db-manager.swrr.connection'),
            'a setting that names the connection prints under its own key, which is what the gate parses',
        );
        $this->assertSame([], $this->settingLines($written, 'database.default'), 'one connection, so one line');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // --real-test: the one reading that leaves the process
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * The flag's whole point: one real query on the connection this run follows, through the
     * application's own path, and a row that carries the latency it took.
     *
     * A sqlite connection is the smallest thing that can answer `select 1`, which is what makes this
     * a test of the reading rather than of a PostgreSQL server: the query and the path are
     * `Support\PinnedQuery`'s, and the health endpoint asks the same one so the two surfaces cannot
     * prove different things about one installation.
     */
    public function test_the_real_test_flag_asks_the_followed_connection_one_query(): void
    {
        $this->bindRedis(reachable: true);
        $this->useSqliteConnection();

        [$output, $exit] = $this->doctor(realTest: true);
        $row = $this->rowContaining($output, 'real query');

        $this->assertStringStartsWith('PASS  real query', $row);
        $this->assertStringContainsString('answered `select 1` in', $row);
        $this->assertStringContainsString('[sqlite_probe]', $row, 'the row names the connection that answered');
        $this->assertStringContainsString(
            '--real-test: the connection this run follows was asked for one real `select 1`',
            $output,
            'the run says a database was reached, because the row is present whether or not one was',
        );
        $this->assertSame(0, $exit);

        [$json] = $this->doctorJson(realTest: true);
        $report = $this->report($json);

        $this->assertTrue($report['real_test'], 'the object says the query was asked, which is the field a gate selects on');
        $this->assertSame('PASS', $this->checkNamed($report, 'real query')['verdict']);
    }

    /**
     * The other half of the row's contract: it is built in every run so that a gate reading it by
     * name can never take its absence for a pass — and when nobody asked, it says so instead of
     * reporting a success about a database it never touched.
     */
    public function test_the_query_row_says_it_was_not_asked_when_nobody_asked(): void
    {
        $this->bindRedis(reachable: true);

        [$output] = $this->doctor();
        $row = $this->rowContaining($output, 'real query');

        $this->assertStringStartsWith('PASS  real query', $row);
        $this->assertStringContainsString('not asked', $row);
        $this->assertStringContainsString('`--real-test` is what asks for one', $row);
        $this->assertStringNotContainsString('answered `select 1`', $row);
        $this->assertStringNotContainsString('--real-test:', $output, 'nothing was asked, so the banner does not claim a database was reached');

        [$json] = $this->doctorJson();
        $report = $this->report($json);

        $this->assertFalse($report['real_test']);
        $this->assertSame('PASS', $this->checkNamed($report, 'real query')['verdict'], 'the row is present and passing in a run that asked nothing');
    }

    /**
     * The failure is the driver's own words, unedited — the sentence an operator pastes into a
     * search, and for PostgreSQL the one carrying the SQLSTATE a deploy gate matches on. It is a
     * failure of the run, because a connection nothing can answer on is the fact this flag exists to
     * establish.
     */
    public function test_a_query_the_server_refuses_fails_the_row_with_the_drivers_own_words(): void
    {
        $this->bindRedis(reachable: true);
        $this->useRefusedConnection();

        [$output, $exit] = $this->doctor(realTest: true);
        $row = $this->rowContaining($output, 'real query');

        $this->assertStringStartsWith('FAIL  real query', $row);
        $this->assertStringContainsString('could not answer `select 1`', $row);
        $this->assertStringContainsString('SQLSTATE[08006]', $row, 'the driver\'s sentence travels unchanged, SQLSTATE included');
        $this->assertStringContainsString('This installation will not behave as configured.', $output);
        $this->assertSame(1, $exit);

        [$json, $jsonExit] = $this->doctorJson(realTest: true);
        $report = $this->report($json);

        $this->assertSame($exit, $jsonExit);
        $this->assertSame('FAIL', $this->checkNamed($report, 'real query')['verdict']);
    }

    /**
     * The flag and the bounded mode compose, and neither weakens the other: the run leaves the six
     * rows that reach outside the process out, and asks the connection for its query anyway — which
     * is the pair a pooler gate runs, because the query is the thing it exists to prove.
     */
    public function test_the_real_test_flag_composes_with_the_connection_only_mode(): void
    {
        $redis = $this->bindRedis(reachable: true);
        $supervisor = $this->bindSupervisor();
        $this->useSqliteConnection();

        [$json, $exit] = $this->doctorJson(connectionOnly: true, realTest: true);
        $report = $this->report($json);

        $this->assertTrue($report['connection_only']);
        $this->assertTrue($report['real_test']);
        $this->assertSame('PASS', $this->checkNamed($report, 'real query')['verdict'], 'the bounded mode does not leave the one reading the gate needs out');
        $this->assertSame([], $redis->asked, 'nothing was asked of the store');
        $this->assertSame([], $supervisor->ran, 'nothing was spawned');
        $this->assertSame(0, $exit);
    }

    /**
     * Every cell of the exit-code matrix. The rule is a function of three things — whether
     * any row failed, whether any row warned, and whether `--strict` is on — so eight
     * combinations are what has to be pinned, and the two where a warning sits beside a
     * failure are the ones an operator written the wrong way round would get wrong.
     *
     * Multiplicity is part of the matrix rather than a repeat of it. The counts are what
     * prove the exit code is computed from *every* row, not from the first one that happens
     * to be neither PASS nor FAIL, and two-of-a-kind is the smallest fixture that can tell
     * those apart.
     *
     * The two mixed profiles are not each other's duplicate either: the doctor prints its
     * rows in a fixed order, and the warning comes before the failure in one of them and
     * after it in the other. A rule that decided on the first offending row would pass one
     * of those cells and fail the other, which is how the ordering trap gets caught rather
     * than remembered.
     *
     * @return array<string, array{0: string, 1: bool, 2: int}> case name => [profile, strict, expected exit]
     */
    public static function exitCodeProvider(): array
    {
        return [
            'nothing warns and nothing fails, without --strict' => ['healthy', false, 0],
            'nothing warns and nothing fails, with --strict' => ['healthy', true, 0],
            'one check warns, without --strict' => ['warned', false, 0],
            'one check warns, with --strict' => ['warned', true, 1],
            'two checks warn, without --strict' => ['warned-twice', false, 0],
            'two checks warn, with --strict' => ['warned-twice', true, 1],
            'one check fails, without --strict' => ['failed', false, 1],
            'one check fails, with --strict' => ['failed', true, 1],
            'two checks fail, without --strict' => ['failed-twice', false, 1],
            'two checks fail, with --strict' => ['failed-twice', true, 1],
            'a warning beside a failure, without --strict' => ['mixed', false, 1],
            'a warning beside a failure, with --strict' => ['mixed', true, 1],
            'two warnings beside two failures, without --strict' => ['mixed-twice', false, 1],
            'two warnings beside two failures, with --strict' => ['mixed-twice', true, 1],
        ];
    }

    /**
     * The exit code as a function of the rows: nothing wrong passes whatever the flags say,
     * a failure always fails, and a warning is a pass only until the preflight is used as a
     * release gate — with a failure beside a warning still a failure, `--strict` or not.
     *
     * The profile is asserted rather than assumed — how many rows of each verdict were
     * printed — because a fixture that drifted would leave the exit code standing as
     * evidence about a question nobody asked. Each profile runs twice, once per `--strict`,
     * which pins the other half of the rule: the flag moves the exit code and changes
     * nothing else about the run.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag(string $profile, bool $strict, int $expected): void
    {
        [$passed, $warned, $failed] = self::exitCodeProfile($profile);
        $run = $this->runExitCodeProfile($profile, $strict);

        $this->assertSame($passed, $run['passed'], "The {$profile} profile printed a different number of PASS rows:\n" . $run['output']);
        $this->assertSame($warned, $run['warned'], "The {$profile} profile printed a different number of WARN rows:\n" . $run['output']);
        $this->assertSame($failed, $run['failed'], "The {$profile} profile printed a different number of FAIL rows:\n" . $run['output']);

        $this->assertSame($expected, $run['exit']);

        // The operator is told which of the four states the installation is in, so the exit
        // code they are about to act on has a sentence next to it saying the same thing.
        $this->assertStringContainsString(
            self::expectedFooter($warned, $failed, $strict),
            $run['output'],
        );

        // Exit 1 with nothing failed has exactly one cause, and the run says so. This is the
        // cell an operator cannot diagnose from the rows: every one of them is PASS or WARN,
        // so the flag is the only thing that can explain the code.
        if ($expected === 1 && $failed === 0) {
            $this->assertStringContainsString('--strict counts the', $run['output'], 'a warning turned into a failure has to name the flag that did it');
        }

        // ...and when the flag is off, the run must not blame it: the same warnings exit 0,
        // and the sentence has to match the code it is next to.
        if (!$strict) {
            $this->assertStringNotContainsString('--strict counts the', $run['output'], 'the flag is off, so it cannot be the reason');
        }
    }

    /**
     * The same matrix, asked for as data.
     *
     * `--json` is a report and not a mode, so every cell of the matrix has to come out of it
     * unchanged: the counts in the object are the counts the rendered matrix asserts for its
     * rows, the code in the object is the cell's documented code, and the process's own code is
     * that number too. Decoding the *whole* of stdout is deliberate — an object with a rendered
     * banner beside it fails to decode here rather than being read as extra fields a job would
     * have to learn to skip.
     *
     * The fixture comes from the same builder the rendered matrix uses, so the two runs are of
     * one installation rather than of two that happen to be configured alike.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_json_report_is_the_exit_matrix_as_one_object(string $profile, bool $strict, int $expected): void
    {
        [$passed, $warned, $failed] = self::exitCodeProfile($profile);

        [$output, $exit] = $this->runExitCodeProfileAsJson($profile, $strict);
        $report = $this->report($output);

        $this->assertSame(self::JSON_KEYS, array_keys($report), "The object gained or lost a key:\n" . $output);

        $this->assertSame('db:doctor', $report['command']);
        $this->assertSame(self::CONNECTION, $report['connection']);
        $this->assertSame($strict, $report['strict'], 'the flag travels in the object, because it is the field that explains a code the rows do not');

        $this->assertSame($expected, $report['exit_code'], 'the code in the object has to be the code the cell documents');
        $this->assertSame($expected, $exit, '...and the process has to exit with it: one run, one number');

        $this->assertSame(
            ['checks' => $passed + $warned + $failed, 'passed' => $passed, 'warnings' => $warned, 'failed' => $failed],
            $report['counts'],
            "The {$profile} cell reported counts the rendered matrix does not assert for its rows",
        );

        $checks = $report['checks'];

        $this->assertIsArray($checks);
        $this->assertCount($passed + $warned + $failed, $checks);

        foreach ($checks as $index => $check) {
            // The same fixed key set one level in: `suggestions` is an empty list rather than a
            // missing key, so a gate never has to ask whether a row carries repairs.
            $this->assertSame(
                ['name', 'verdict', 'detail', 'suggestions'],
                array_keys($check),
                "check #{$index} does not have the published shape:\n" . $output,
            );
            $this->assertContains($check['verdict'], ['PASS', 'WARN', 'FAIL'], 'a verdict is one of the three the table prints, not a word of its own');
            $this->assertIsString($check['detail']);
            $this->assertNotSame('', $check['detail']);
            $this->assertIsArray($check['suggestions']);
        }

        $this->assertSame(
            ['PASS' => $passed, 'WARN' => $warned, 'FAIL' => $failed],
            $this->verdictTally($checks),
        );

        // How bad the run is, one scope up from the rows — and deliberately not read back out
        // of the exit code, which under --strict is 1 for an installation that answered every
        // check.
        $this->assertSame(
            match (true) {
                $failed > 0 => 'FAIL',
                $warned > 0 => 'WARN',
                default => 'PASS',
            },
            $report['verdict'],
        );

        // The two ways to reach exit 1 are told apart by those same fields, which is what a gate
        // needs them for: a code on its own cannot say whether the installation is broken or the
        // gate is strict.
        if ($expected === 1) {
            $this->assertSame($failed > 0 ? 'FAIL' : 'WARN', $report['verdict'], 'exit 1 has two causes, and the object names this one');
        }
    }

    /**
     * The object is the table as data, not a paraphrase of it.
     *
     * The matrix above asserts the object and the table against the same provider separately, so
     * both could be right about the same wrong run. This reports one installation both ways and
     * reads the two against each other: the row names and verdicts the object carries are the
     * ones an operator saw, in the order they were printed.
     *
     * The richest profile — two warnings and two failures — so the comparison covers every
     * verdict a row can have and both orderings the doctor's row list can produce.
     */
    public function test_the_json_report_is_the_table_as_data(): void
    {
        $this->buildExitCodeProfile('mixed-twice');

        [$rendered, $renderedExit] = $this->doctor();
        [$json, $jsonExit] = $this->doctorJson();

        $report = $this->report($json);

        $this->assertSame($renderedExit, $jsonExit, 'asking for the report must not move the code it reports');
        $this->assertSame($jsonExit, $report['exit_code']);

        $rows = [];

        foreach ($report['checks'] as $check) {
            $rows[] = $check['verdict'] . '  ' . $check['name'];
        }

        $this->assertNotSame([], $rows, 'the fixture has to produce rows for this to assert anything');

        $this->assertSame(
            $this->renderedRows($rendered),
            $rows,
            "The object does not name the rows the table printed:\n" . $rendered,
        );
    }

    /**
     * The rows the README documents are the rows the command builds — by name, not by count.
     *
     * Three numbers in the records describe this list and nothing compared them with it: the
     * README's "Twenty-one things can be wrong", `docs/documented-exit-codes.md`'s "the
     * twenty-one-row description of what each check judges", and `docs/db-doctor-json.md`'s
     * "twenty-one ... and nine where it does not". The exit tables already have a guard for this class of drift one level
     * up; this is the same guard for the row list, and the comparison is by name because a count
     * can be right while the list is wrong — and it is the names a reader selects on.
     *
     * The unweighted half is the same claim from the other side: the nine rows that do not need a
     * weighted manager are what an installation with a broken swap produces, and the record counts
     * them separately for exactly that reason.
     */
    public function test_the_readme_check_table_is_the_rows_the_command_builds(): void
    {
        $documented = $this->documentedChecks();

        $this->assertNotSame([], $documented, 'the README table has to be readable for this to assert anything');

        [$json] = $this->doctorJson();

        $this->assertSame(
            $documented,
            array_column($this->report($json)['checks'], 'name'),
            'the README documents the rows the command builds, in the order it builds them',
        );

        $this->assertSame(
            count($documented),
            $this->numberClaim('README.md', '/([\w-]+) things can be wrong/'),
            'the README counts the rows its own table lists',
        );

        $this->assertSame(
            count($documented),
            $this->numberClaim('docs/db-doctor-json.md', '/That is ([\w-]+) on an installation where `db` resolves/'),
            'db-doctor-json.md counts the weighted run the README documents',
        );

        $unweighted = $this->unweightedChecks();

        $this->assertSame(
            count($unweighted),
            $this->numberClaim('docs/db-doctor-json.md', '/and \*\*([\w-]+)\*\* where it does not/'),
            'db-doctor-json.md counts the rows a run without the weighted manager asks',
        );

        $this->assertSame(
            count($unweighted),
            $this->numberClaim('README.md', '/([\w-]+) configuration rows/'),
            'the README counts the rows a run asks without the weighted manager too',
        );

        $this->assertSame(
            [],
            array_values(array_diff($unweighted, $documented)),
            'the rows a run asks without the weighted manager are a subset of the documented list',
        );
    }

    /**
     * The record's claim about which rows can carry a repair, checked against runs that produce one.
     *
     * `docs/db-doctor-json.md` states "four rows can offer one" and names the classes each line
     * comes from. Three states are walked — a refused reader window, an unquoted supervisor
     * command, and the switch armed where pgcat cannot act — and the union of the rows carrying a
     * non-empty `suggestions` across them is the set that sentence is about. The states accumulate,
     * so a row that stops offering one is still counted from the step that produced it. The limit is
     * the fixtures rather than the code: a row that could offer a line under some state none of
     * these three reaches would not be counted, which `docs/prose-numbers.md` records.
     */
    public function test_the_record_says_which_rows_can_carry_a_repair(): void
    {
        $carrying = [];

        // A refused reader window: the one spelling the package can repair without guessing.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);
        $carrying += $this->rowsCarryingARepair();
        $this->assertArrayHasKey('reader windows', $carrying);

        // An unquoted program name: the flip's own command, re-quoted.
        $paths = $this->armPgcat();
        config()->set('db-manager.swrr.pgcat.reload_command', $paths['supervisorctl'].' signal HUP pgcat:*');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $this->bindSupervisor();
        $carrying += $this->rowsCarryingARepair();
        $this->assertArrayHasKey('pgcat supervisor', $carrying);

        // The switch armed where pgcat cannot act: the one pgcat value a gate may apply.
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $carrying += $this->rowsCarryingARepair();
        $this->assertArrayHasKey('pgcat gate', $carrying);

        $rows = array_keys($carrying);
        sort($rows);

        $this->assertSame(
            ['pgcat files', 'pgcat gate', 'pgcat supervisor', 'reader windows'],
            $rows,
            'the rows that carry a repair are the ones the record says can, and no others: ' . implode(', ', $rows),
        );

        $this->assertSame(
            count($carrying),
            $this->numberClaim('docs/db-doctor-json.md', '/(\w+) rows can offer one/'),
            'the record counts the rows that can carry a suggestion line',
        );
    }

    /**
     * The rows that can name several problems, and one problem from each half they have in the run
     * `everyRowWithSeveralProblems()` builds.
     *
     * This table is the register: a row that can report more than one problem has an entry here, and
     * its entry gives it two of them, so a row that regressed to naming whichever problem it noticed
     * first fails its own case — and, for the rows the record dates, the set test below as well. A
     * fragment is how a case names its problem: each one is distinctive to a single problem, so
     * finding both of them in *one* `detail` is the row naming both, whatever the sentences around
     * them say.
     *
     * The repairs are counted apart from the problems deliberately. `switch values` and `store probe`
     * name several problems and offer no repair at all — re-spelling a value that is not a switch
     * would be guessing at what was meant — so the two halves of a row are not the same number and
     * are not wired to each other here.
     *
     * The fourth element is the case's *run*, and only one entry needs it: `config file` is the
     * vet's row, so its subject is a candidate file rather than an installation, and the run that
     * reports it is `--config-file`. Null means the installation fixture, which is what every other
     * entry is about. The vet's entry is the one row of the pair whose problems are undated by
     * design, so the set test below — which measures dated rows in the installation run — stays
     * about the four it names.
     *
     * @return array<string, array{0: string, 1: list<string>, 2: int, 3?: string}>
     */
    public static function rowsNamingSeveralProblems(): array
    {
        return [
            // The vet's own row, and the only one with two problems in *different* states of the
            // same file: the shape nobody can judge, and the bytes the file printed while it was
            // being read — which `config:cache` would write into the cached file, so both are the
            // operator's to fix and neither is a detail of the other. `not config` is ten bytes.
            'config file — a shape that cannot be judged, and bytes printed while it was read' => [
                'config file',
                ['returned string, not an array', 'printed 10 byte(s) while being read, which config:cache writes into the cached file'],
                0,
                "<?php\n\necho 'not config';\n\nreturn 'swrr';\n",
            ],
            // A window written without its list, and a day list written as the `.env` string: two
            // refused settings, each re-spelled by the rule that refused it, so two repairs.
            'reader windows — both reader settings refused' => [
                'reader windows',
                ['reader_windows[0] is "10:00-14:20"', 'swrr.reader_days is "1,2,3"'],
                2,
            ],
            // Two switches that are not switches, and the one row that shows the halves apart.
            'switch values — two switches that are not switches' => [
                'switch values',
                ['swrr.pgcat.use_reload is "maybe"', 'swrr.allow_local_fallback is "nope"'],
                0,
            ],
            // The flip's own state and lock resolve into a directory a regular file already
            // occupies, so neither can be written: two problems, one mode line each.
            'pgcat files — two paths the flip cannot write' => [
                'pgcat files',
                ['flip state directory', 'flip lock directory'],
                2,
            ],
            // The probe switched off, beside the record that throttles it unable to be written: the
            // second is only a problem because the first is a choice, and the row names both.
            'store probe — off, and a record that cannot be written' => [
                'store probe',
                ['switched off (swrr.audit.store_probe_seconds = 0', 'cannot be written, and it is what throttles the probe'],
                0,
            ],
            // Both schedule switches written as something that is not on or off: the two settings
            // `switch values` does not cover, refused by the row that reads them for the boundary
            // they decide. No repair: which mechanism to point at the boundary is the operator's
            // choice, so the row names both keys and prints no line a gate could apply.
            'flip schedule — both schedule switches refused' => [
                'flip schedule',
                ['swrr.pgcat.schedule.enabled is "flase"', 'swrr.pgcat.schedule.windows.enabled is "flase"'],
                0,
            ],
        ];
    }

    /**
     * A row that can name several problems names every one of them.
     *
     * The shape is `datedRow()`'s: one sentence per problem, separated so an operator can tell
     * where one ends and the next begins, and one `suggestion` per problem that has a repair. A row
     * that stopped at the first problem would pass every other test in this file — the first
     * problem is named, dated and repaired exactly as it was — which is what makes this a rule of
     * its own rather than a detail of the tests above.
     *
     * @param list<string> $fragments
     */
    #[DataProvider('rowsNamingSeveralProblems')]
    public function test_a_row_that_can_name_several_problems_names_every_one_of_them(
        string $row,
        array $fragments,
        int $repairs,
        ?string $candidate = null,
    ): void {
        // The vet's `config file` row is about a file, so its problems are built by a run pointed
        // at one; every other entry is a row of the installation the fixture below describes.
        $record = $candidate === null ? $this->everyRowWithSeveralProblems() : '';

        if ($row === 'store probe' && is_writable($record)) {
            // Running as root, or on a filesystem that ignores the read-only bit: the row's second
            // problem cannot exist here, and the half that can is asserted by the run itself.
            $this->markTestSkipped('This environment cannot make the record read-only.');
        }

        $report = $candidate === null
            ? $this->report($this->doctorJson()[0])
            : $this->report($this->vetConfig($this->rawConfigFile($candidate), json: true)[0]);

        $check = $this->checkNamed($report, $row);

        foreach ($fragments as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $check['detail'],
                "{$row} stopped naming a problem it has ({$fragment}): {$check['detail']}",
            );
        }

        $this->assertSame(
            'FAIL',
            $check['verdict'],
            "{$row} is reporting problems, so it is not passing: {$check['detail']}",
        );

        $this->assertCount(
            $repairs,
            $check['suggestions'],
            "{$row} carries one repair per problem that has one, and no more: " . implode(' | ', $check['suggestions']),
        );
    }

    /**
     * The rows that name several problems are the ones the register declares, read out of the run.
     *
     * The record dates each problem from its own finding — one key per setting is what makes that
     * possible — so a row with two problems carries two dates and a row with one carries one. That
     * makes the count of dates the run's own answer to which rows name several problems, measured
     * rather than restated: a row that regressed to its first problem drops out of the set, and a row
     * that grew a second finding appears in it without being declared.
     *
     * Every row in the register is dated in this fixture, including `store probe` — whose two states
     * became findings of their own (`swrr.audit.store_probe_seconds.off` and `swrr.audit.file.unwritable`)
     * for exactly this reason: a row that reports a state standing for weeks is a row an operator asks
     * "since when" about.
     */
    public function test_the_rows_naming_several_dated_problems_are_the_ones_the_register_declares(): void
    {
        $this->everyRowWithSeveralProblems();

        $several = [];

        foreach ($this->report($this->doctorJson()[0])['checks'] as $check) {
            if (substr_count($check['detail'], 'recorded unresolved since') > 1) {
                $several[] = $check['name'];
            }
        }

        sort($several);

        $this->assertSame(
            ['pgcat files', 'reader windows', 'store probe', 'switch values'],
            $several,
            'the rows that name several dated problems are the ones the register declares: ' . implode(', ', $several),
        );
    }

    /**
     * The repairs travel in the object, on the row that names the problem they repair.
     *
     * A suggestion is the half of a row a gate can act on — the setting line to paste — and it is
     * the half that until now existed only in the rendered table, under a row an operator has to
     * associate it with by column position. Here it is a list on the row it belongs to, and the
     * comparison is against the same lines the table printed rather than against a count of them.
     */
    public function test_the_json_report_carries_the_suggestions_the_table_prints(): void
    {
        // The flat string is the refused window everyone writes first, and the one spelling the
        // package can repair without guessing at what was meant.
        $this->armPgcat();
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);
        $this->usePublishedConfig();
        $this->bindRedis(reachable: true);

        [$rendered, $renderedExit] = $this->doctor();
        [$json, $jsonExit] = $this->doctorJson();

        $report = $this->report($json);
        $printed = $this->repairs($rendered);

        $this->assertNotSame([], $printed, "The fixture printed no repair, so this asserts nothing:\n" . $rendered);

        $windows = $this->checkNamed($report, 'reader windows');

        $this->assertSame('FAIL', $windows['verdict'], 'a repair is not a verdict: the value is still refused');
        $this->assertSame($printed, $windows['suggestions']);
        $this->assertSame($renderedExit, $jsonExit);

        // And a row with nothing to repair says so with an empty list, one level in from the
        // fixed key set.
        $this->assertSame([], $this->checkNamed($report, 'published config')['suggestions']);
        $this->assertSame([], $this->checkNamed($report, 'provider swap')['suggestions']);
    }

    /**
     * A pgcat repair travels the same way the reader one does — the line the table prints is the
     * string the object carries — because a gate asserting on the repair should not have to read
     * it out of a sentence. Here the sentence and the line are side by side, which is the whole
     * reason the line exists: one is for reading, the other for acting on.
     */
    public function test_the_json_report_carries_the_pgcat_repair_line_the_table_prints(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        config()->set('db-manager.swrr.pgcat', ['enabled' => true]);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        [$rendered, $renderedExit] = $this->doctor();
        [$json, $jsonExit] = $this->doctorJson();

        $report = $this->report($json);
        $printed = $this->repairs($rendered);
        $gate = $this->checkNamed($report, 'pgcat gate');

        $this->assertSame(['swrr.pgcat.enabled = false'], $printed);
        $this->assertSame($printed, $gate['suggestions']);
        $this->assertSame('FAIL', $gate['verdict'], 'a repair is not a verdict: the gate still fails');
        $this->assertStringContainsString('Set swrr.pgcat.enabled = false', $gate['detail'], 'the sentence still names the repair too');
        $this->assertSame($renderedExit, $jsonExit);
        $this->assertSame(1, $renderedExit);

        // The pgcat rows that can name nothing say so with a list rather than by leaving the key
        // out: the flipper is inert on this connection, so neither the files nor the command is
        // judged — and both still carry the key.
        $this->assertSame([], $this->checkNamed($report, 'pgcat files')['suggestions']);
        $this->assertSame([], $this->checkNamed($report, 'pgcat supervisor')['suggestions']);
    }

    /**
     * The JSON vocabulary is closed and documented.
     *
     * The same guard the flip's kind table has, one level in: a gate binds to `verdict`, so a
     * verdict the report can carry without being written down — or a documented one nothing
     * produces — is the drift that would break it silently. One table documents both scopes,
     * because `verdict` is the same three strings at both, and the set compared here is derived
     * from the matrix's own profiles rather than listed a second time: the verdicts a row can
     * reach, which are also the verdicts a run can carry, since the run's verdict is the loudest
     * of its rows.
     *
     * There is no code to bind beside each row, which is the one way this table differs from the
     * flip's. A verdict does not decide the exit code — `--strict` does, and only for the two
     * verdicts that are not a failure — so a per-row code here would be a number no cell of the
     * matrix asserts. What each row does determine is the vocabulary, and that is what is checked.
     */
    public function test_every_json_verdict_is_documented(): void
    {
        $rows = Readme::table('### The preflight as data: db:doctor --json', 'verdict');

        $documented = array_map([Readme::class, 'plain'], array_column($rows, 'verdict'));

        $produced = [];

        foreach (self::exitCodeProvider() as [$profile]) {
            [$passed, $warned, $failed] = self::exitCodeProfile($profile);

            foreach (['PASS' => $passed, 'WARN' => $warned, 'FAIL' => $failed] as $verdict => $count) {
                if ($count > 0) {
                    $produced[$verdict] = $verdict;
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            ['PASS', 'WARN', 'FAIL'],
            array_keys($produced),
            'the fixtures have to reach every verdict for this guard to assert anything',
        );

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_keys($produced)),
            $documented,
            'a verdict the report can carry is documented, and every documented verdict is one it carries',
        );
    }

    /**
     * The README exit table's row a run belongs to: which of the three cases its counts are
     * an instance of. The strings are the table's own left-hand labels, and the test below
     * reads them back out of the README rather than trusting them — a table that renames one
     * of these cases fails here instead of going unenforced.
     */
    private static function documentedRow(int $warned, int $failed): string
    {
        return match (true) {
            $failed > 0 => 'any failure',
            $warned > 0 => 'warnings, no failures',
            default => 'no failures and no warnings',
        };
    }

    /**
     * The table an operator reads is the rule the cells enforce.
     *
     * The matrix asserts each cell's code against the run it built; this asserts the same
     * cells against the README, which is what a deploy gate is actually written from. Two
     * claims: the cases the matrix is written as are exactly the rows the table has — in both
     * directions, so a documented case cannot lose its last cell and a new one cannot arrive
     * undocumented — and each cell's code is the number its row names in its column. A table
     * that lists the right cases against the wrong numbers is the drift that matters, because
     * the number is what a scheduler branches on.
     */
    public function test_the_matrix_agrees_with_the_readme_exit_table(): void
    {
        $rows = Readme::table('### Preflight: `db:doctor`', '--strict off');
        $produced = [];

        foreach (self::exitCodeProvider() as [$profile, $strict, $expected]) {
            [, $warned, $failed] = self::exitCodeProfile($profile);
            $label = self::documentedRow($warned, $failed);
            $produced[$label] = true;

            $cell = Readme::row($rows, 'the rows', $label);
            $column = $strict ? '--strict on' : '--strict off';

            $this->assertSame(
                $expected,
                Readme::code($cell[$column]),
                sprintf(
                    'The README documents [%s] as "%s" under %s, while the matrix asserts %d.',
                    $label,
                    $cell[$column],
                    $column,
                    $expected,
                ),
            );
        }

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_keys($produced)),
            array_map([Readme::class, 'plain'], array_column($rows, 'the rows')),
            'the README documents a case the matrix is not written as, or the matrix is written as one the README does not document',
        );
    }

    /**
     * The sentence a run ends on, derived from the same three facts the exit-code rule uses:
     * what failed, what warned, and whether the gate treats a warning as a failure.
     *
     * It is asserted for every cell of the matrix, `--strict` or not, so the two cannot drift:
     * a run that exits 1 for the gate says so, and a run that exits 0 for the same warnings
     * does not claim it.
     */
    private static function expectedFooter(int $warned, int $failed, bool $strict): string
    {
        return match (true) {
            $failed > 0 => 'This installation will not behave as configured.',
            $warned > 0 && $strict => sprintf(
                'No failures — but --strict counts the %d warning%s above as failures: this run exits 1 as a release gate, not because the installation is broken.',
                $warned,
                $warned === 1 ? '' : 's',
            ),
            $warned > 0 => 'No failures — review the warnings above.',
            default => 'Installation looks healthy.',
        };
    }

    /**
     * The row shape each profile is built to produce: how many rows of each verdict, which
     * is the left-hand side of the exit-code rule the test above evaluates.
     *
     * @return array{0: int, 1: int, 2: int} passed, warned, failed
     */
    private static function exitCodeProfile(string $profile): array
    {
        return match ($profile) {
            // Three more PASSes than the row list had before the read path's three rows, on every
            // profile: the fixture arms none of them (`swrr.read_path.enabled` is off, so neither
            // probing row opens a connection) and the pair reading asks a config, so all three
            // are silences these fixtures do not spoil — the +3 is the point rather than a value.
            // `boot environment` is the other one that is always a PASS here, for its own reason,
            // and `variant shape` is a third: the flipper is armed against a file set whose
            // variants declare no pool the fixture's connection writes with, which is the silence
            // that row calls *nothing to compare* — so the +1 is likewise the point.
            // `connector` is a fourth for the same kind of reason: no profile moves the connection
            // or binds `db.connector.pgsql`, so every one of them names the framework's own
            // connector for a pgsql connection and passes.
            // `real query` is a fifth: no profile asks for one, so every one of them reports
            // the row as not asked rather than as a pass — the +1 is the row's presence.
            // `write paths` is a sixth, and it passes in every profile for a reason the fixtures do
            // not spoil: the missing reader source in `failed-twice` and `mixed-twice` is a path
            // that can be created, which this row judges by the directory that would take it.
            // `pool repairs` is a seventh for the same kind of reason: every fixture starts from a
            // state file with no repairs in it, and the row is a reading rather than a verdict, so
            // it is a PASS in every profile.
            'healthy' => [24, 0, 0],
            'warned' => [23, 1, 0],
            'warned-twice' => [22, 2, 0],
            'failed' => [23, 0, 1],
            'failed-twice' => [22, 0, 2],
            'mixed' => [22, 1, 1],
            'mixed-twice' => [20, 2, 2],
            default => throw new InvalidArgumentException("Unknown exit-code profile [{$profile}]"),
        };
    }

    /**
     * Configure the windowed reader fallback and drop the resolver singleton, which is
     * what the row reads the windows and days through.
     */
    private function useReaderFallback(mixed $windows, mixed $days): void
    {
        config()->set('db-manager.swrr.reader_windows', $windows);
        config()->set('db-manager.swrr.reader_days', $days);

        $this->app->forgetInstance(TimeWindowResolver::class);
    }

    /**
     * Point the boot audit at a record the test controls. The store probe is off by
     * default in this suite, so a test about it switches the interval on explicitly.
     */
    private function useAudit(int $storeProbeSeconds, ?string $file = null): void
    {
        config()->set('db-manager.swrr.audit', [
            'file' => $file ?? (string) config('db-manager.swrr.audit.file'),
            'store_probe_seconds' => $storeProbeSeconds,
        ]);

        $this->app->forgetInstance(BootAudit::class);
    }

    /**
     * A path whose parent is a file, so nothing can be written beside it — without a
     * chmod, which Windows applies to the read-only attribute.
     */
    private function blockedRecordPath(): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        return $dir . '/blocked/audit.json';
    }

    /**
     * Run the doctor over one of the row profiles the exit code can be in.
     *
     * Every profile is the same installation otherwise — flipper armed against a file set
     * this test owns, a store that answers, a config published outside the package, the
     * probe on with a writable record — so only the rows the case is about misbehave, and
     * the exit code has nothing else to react to.
     *
     * One deliberate fault per row the profiles need, and no more:
     *
     *   the store does not answer      → `store reachability` FAILs, on demand, however the
     *                                    probe interval is set. Last row, so in `mixed` the
     *                                    warning is printed before it
     *   the probe is switched off      → `store probe` WARNs (a choice, not a failure)
     *   `reader_days` is an empty list → `reader windows` WARNs (the fallback cannot apply)
     *   a source file the flip needs, gone → `pgcat files` FAILs. Fourth row, so in
     *                                    `mixed-twice` a failure is printed before any warning
     *
     * @return array{passed: int, warned: int, failed: int, exit: int, output: string}
     */
    private function runExitCodeProfile(string $profile, bool $strict): array
    {
        $this->buildExitCodeProfile($profile);

        [$output, $exit] = $this->doctor(strict: $strict);

        return [...$this->countVerdicts($output), 'exit' => $exit, 'output' => $output];
    }

    /**
     * The same profile, asked for as data. One builder and one difference — the flag — so the
     * rendered matrix and the JSON matrix cannot be two different installations.
     *
     * @return array{0: string, 1: int}
     */
    private function runExitCodeProfileAsJson(string $profile, bool $strict): array
    {
        $this->buildExitCodeProfile($profile);

        return $this->doctorJson(strict: $strict);
    }

    /**
     * The installation each exit-code profile is built from.
     *
     * Extracted from the run above so that reporting the matrix twice is reporting *one* run
     * twice: two fixture builders would be two installations, and the claim the JSON half exists
     * to make — that the object describes the run the table describes — would be untested.
     */
    private function buildExitCodeProfile(string $profile): void
    {
        $storeAnswers = ! in_array($profile, ['failed', 'failed-twice', 'mixed', 'mixed-twice'], true);
        $probeOff = in_array($profile, ['warned', 'warned-twice', 'mixed', 'mixed-twice'], true);
        $noDays = in_array($profile, ['warned-twice', 'mixed-twice'], true);
        $fileGone = in_array($profile, ['failed-twice', 'mixed-twice'], true);

        // Which verdict the doctor reaches first is a property of this list, and the two
        // mixed profiles are built to straddle it.

        $this->bindRedis(reachable: $storeAnswers);

        $paths = $this->armPgcat();

        if ($fileGone) {
            @unlink($paths['readers_path']);
        }

        $this->usePublishedConfig();
        $this->useAudit($probeOff ? 0 : 60);

        if ($noDays) {
            $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], []);
        }
    }

    /**
     * The verdicts the doctor printed, counted from the rendered rows rather than from
     * the command's own bookkeeping: the exit code has to agree with what an operator
     * reads, which is the same reason the other tests assert on the rows.
     *
     * @return array{passed: int, warned: int, failed: int}
     */
    private function countVerdicts(string $output): array
    {
        preg_match_all('/^\s*(PASS|WARN|FAIL)\s{2,}/m', $output, $matches);

        $verdicts = array_count_values($matches[1] ?? []);

        return [
            'passed' => $verdicts['PASS'] ?? 0,
            'warned' => $verdicts['WARN'] ?? 0,
            'failed' => $verdicts['FAIL'] ?? 0,
        ];
    }

    /**
     * The report, decoded — from the whole of stdout, because the object is meant to be all of
     * it. A rendered line left beside the object is a failure to parse here rather than a field a
     * gate is expected to skip, and `JSON_THROW_ON_ERROR` is what makes that a message about the
     * output instead of a `null` that reads like a missing key.
     *
     * @return array<string, mixed>
     */
    private function report(string $output): array
    {
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The report's row for one check, by the name the table prints.
     *
     * Selected by name because the row set is not a fixed length: a `db` that does not resolve to
     * the weighted manager leaves the nine configuration rows and no replica rows at all, so a job
     * written against `checks[9]` would read a different check on exactly the installations it
     * exists to catch.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function checkNamed(array $report, string $name): array
    {
        foreach ($report['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("The report has no [{$name}] row. It has: " . implode(', ', array_column($report['checks'], 'name')));
    }

    /**
     * The rows' verdicts, tallied — with all three keys always present, so a cell with no failures
     * compares against a zero rather than against a key that is missing. The same rule the
     * object's own `counts` keeps, applied to the rows the object names.
     *
     * @param list<array<string, mixed>> $checks
     * @return array{PASS: int, WARN: int, FAIL: int}
     */
    private function verdictTally(array $checks): array
    {
        $tally = ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0];

        foreach ($checks as $check) {
            $tally[$check['verdict']]++;
        }

        return $tally;
    }

    /**
     * The rendered table's rows as `VERDICT  name`, read from the output an operator sees rather
     * than from the command's own bookkeeping — which is the only way the two reports can be read
     * against each other.
     *
     * The banner, the closing summary and the `suggestion` lines under a row are not rows. The
     * name column is padded, so the split is on the run of spaces between the column and the
     * detail rather than on a fixed offset: a check name is free to be longer than the column it
     * is printed in.
     *
     * @return list<string>
     */
    private function renderedRows(string $output): array
    {
        preg_match_all('/^(PASS|WARN|FAIL)\s{2,}(.+?)\s{2,}/m', $output, $matches, PREG_SET_ORDER);

        $rows = [];

        foreach ($matches as $match) {
            $rows[] = $match[1] . '  ' . $match[2];
        }

        return $rows;
    }

    /**
     * The repairs the table printed, with the column label taken off — the text a row's suggestion
     * carries, which is what the object carries beside the row's own name.
     *
     * The label is taken off by the width the renderer pads it to, so the two cannot disagree
     * about where the repair starts.
     *
     * @return list<string>
     */
    private function repairs(string $output): array
    {
        $label = str_pad('suggestion', 18) . '  ';

        return array_map(
            static fn (string $line): string => trim(substr($line, strlen($label))),
            $this->suggestions($output),
        );
    }

    /**
     * Point the application's config path at a directory holding a `db-manager.php` that
     * differs from the package sample.
     *
     * The published-config row asks a real question — has anyone reviewed these values —
     * and a Testbench skeleton is always on the wrong side of it, because the file the row
     * looks for is not there. Copying the sample into a directory of the test's own and
     * adding a line is what a published config looks like to the row, and it keeps the
     * fixture out of vendor/.
     */
    private function usePublishedConfig(): void
    {
        $dir = $this->tempDir();
        $sample = (string) file_get_contents(dirname(__DIR__, 3) . '/config/db-manager.php');

        file_put_contents($dir . '/db-manager.php', $sample . "\n// reviewed for this test\n");

        $this->app->useConfigPath($dir);
    }

    /**
     * Publish the sample with its `generation` line replaced by `$literal`, so the row's reading of
     * the key set a *file* declares is what is under test rather than the sample's own.
     *
     * The copy is made to differ from the sample — one appended comment, the way
     * `usePublishedConfig()` does it — because a byte-identical copy is a different state of the row,
     * asserted by its own test, and a fixture that left the two identical would test that state while
     * reading as though it tested this one.
     */
    private function publishConfigDeclaring(string $literal): void
    {
        $dir = $this->tempDir();
        $sample = (string) file_get_contents(dirname(__DIR__, 3) . '/config/db-manager.php');
        $line = "'".ConfigSchema::KEY."' => ".ConfigSchema::GENERATION.',';

        $this->assertStringContainsString($line, $sample, 'the sample declares the generation line this test replaces');

        file_put_contents($dir . '/db-manager.php', str_replace($line, $literal, $sample)."\n// reviewed for this test\n");

        $this->app->useConfigPath($dir);
    }

    /**
     * Run the doctor through a real console kernel, so the returned text is what an
     * operator reads.
     *
     * @return array{0: string, 1: int}
     */
    private function doctor(bool $strict = false, bool $connectionOnly = false, bool $contract = false, bool $realTest = false): array
    {
        $buffer = new BufferedOutput();

        $exit = $this->app->make(Kernel::class)->call(
            'db:doctor',
            [
                'connection' => self::CONNECTION,
                '--strict' => $strict,
                '--connection-only' => $connectionOnly,
                '--contract' => $contract,
                '--real-test' => $realTest,
            ],
            $buffer,
        );

        return [$buffer->fetch(), $exit];
    }

    /**
     * The two-space `key  value` lines the run prints under its banner, read the way the container's
     * smoke gate reads them: a `sed` for a line that starts with two spaces and the setting's name.
     *
     * @return list<string> the values found under that key, in the order printed
     */
    private function settingLines(string $output, string $key): array
    {
        preg_match_all('/^ {2}' . preg_quote($key, '/') . ' +(.*)$/m', $output, $matches);

        return array_map(static fn (string $value): string => trim($value), $matches[1]);
    }

    /**
     * Follow a sqlite connection, so `select 1` can answer without a server — with pgcat disarmed,
     * because a switch armed over a driver pgcat can never act on is a failure of its own row and
     * this is not the test for it.
     */
    private function useSqliteConnection(): void
    {
        config()->set('database.connections.sqlite_probe', ['driver' => 'sqlite', 'database' => ':memory:']);
        config()->set('db-manager.swrr.connection', 'sqlite_probe');
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        DB::purge('sqlite_probe');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
    }

    /**
     * Follow a pgsql connection whose port nothing listens on, so the query fails at connect time
     * with the driver's own sentence rather than with a stub of the test's.
     */
    private function useRefusedConnection(): void
    {
        config()->set('database.connections.refused_probe', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'app',
            'username' => 'app',
            'password' => 'app',
        ]);
        config()->set('db-manager.swrr.connection', 'refused_probe');
        config()->set('db-manager.swrr.pgcat', ['enabled' => false]);

        DB::purge('refused_probe');
        $this->app->forgetInstance(PgcatConfigFlipper::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --config-file: a config vetted before it is installed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The whole point of the mode: the refusals the package would raise are readable from the
     * file, in the same sentences and with the same repairs the installation row prints — so a
     * pipeline can fail a candidate config before anything is deployed with it.
     */
    public function test_the_config_file_flag_reports_the_refusals_a_candidate_config_holds(): void
    {
        $path = $this->configFile([
            'pgcat' => ['enabled' => 'flase'],
            'allow_local_fallback' => 'nope',
            'reader_windows' => '10:00-14:20',
            'reader_days' => '1,2,3',
        ]);

        [$output, $exit] = $this->vetConfig($path);

        $this->assertStringContainsString('Config file vetted: ' . $path, $output);
        $this->assertStringStartsWith('PASS  config file', $this->rowContaining($output, 'config file'));

        $switches = $this->rowContaining($output, 'switch values');
        $this->assertStringStartsWith('FAIL  switch values', $switches);
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $switches);
        $this->assertStringContainsString('swrr.allow_local_fallback is "nope"', $switches);
        $this->assertStringContainsString('the flipper is inert', $switches, 'the consequence of the refused value travels with the refusal');

        $windows = $this->rowContaining($output, 'reader windows');
        $this->assertStringStartsWith('FAIL  reader windows', $windows);
        $this->assertStringContainsString('swrr.reader_windows is "10:00-14:20", not a list of windows', $windows);
        $this->assertStringContainsString('swrr.reader_days is "1,2,3", not a list of days', $windows);

        // The repairs the installation row names, from the same classes — a refused value that
        // reduces to an exact replacement is re-spelled here too, or the pipeline would have to
        // know which refusals have one.
        $this->assertStringContainsString('swrr.reader_windows = ', $output);
        $this->assertStringContainsString('swrr.reader_days = ', $output);

        $this->assertStringContainsString('A value in this file would be refused', $output);
        $this->assertSame(1, $exit);
    }

    /**
     * The claim that makes this a preflight rather than a second opinion: the file is the subject.
     * Here the *installed* configuration refuses two switches while the candidate is clean, and the
     * vet passes it — the same run judged as an installation fails.
     */
    public function test_the_config_file_flag_judges_the_file_rather_than_the_installation(): void
    {
        $redis = $this->bindRedis(reachable: true);

        config()->set('db-manager.swrr.pgcat', [
            'enabled' => 'off (in the installed config)',
            'use_reload' => 'maybe',
        ]);

        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $this->bindSupervisor();

        $path = $this->configFile([
            'pgcat' => ['enabled' => false, 'use_reload' => true],
            'allow_local_fallback' => 'on',
            'reader_windows' => [['start' => '10:00:00', 'end' => '14:20:00']],
        ]);

        [$vetted, $vtExit] = $this->vetConfig($path);

        $this->assertStringStartsWith('PASS  switch values', $this->rowContaining($vetted, 'switch values'));
        $this->assertStringContainsString('swrr.pgcat.enabled off', $vetted);
        $this->assertStringNotContainsString('in the installed config', $vetted, 'the installed value is not the file');
        $this->assertSame(0, $vtExit);

        // And no part of the installation was consulted: the vet reads a file, so the store is
        // never reached for, and no provider or factory row is invented for a file that has none.
        $this->assertSame([], $redis->asked, 'a config file is not a store to probe');
        $this->assertStringNotContainsString('store reachability', $vetted);
        $this->assertStringNotContainsString('provider swap', $vetted);

        // The same installation, judged as an installation, refuses the two switches it holds —
        // which is what the vet above would have reported had it read the running configuration.
        [$installed, $installedExit] = $this->doctor();
        $this->assertStringStartsWith('FAIL  switch values', $this->rowContaining($installed, 'switch values'));
        $this->assertSame(1, $installedExit);
    }

    public function test_the_config_file_flag_passes_a_config_whose_values_are_all_readable(): void
    {
        $path = $this->configFile([
            'pgcat' => ['enabled' => 'on', 'use_reload' => 'off'],
            'allow_local_fallback' => 1,
            'reader_windows' => [
                ['start' => '10:00:00', 'end' => '14:20:00'],
                ['start' => '17:00:00', 'end' => '20:30:00'],
            ],
            'reader_days' => [1, 2, 3, 4, 5],
        ]);

        [$output, $exit] = $this->vetConfig($path);

        $this->assertStringStartsWith('PASS  switch values', $this->rowContaining($output, 'switch values'));
        $this->assertStringContainsString('swrr.pgcat.use_reload off', $output);

        $windows = $this->rowContaining($output, 'reader windows');
        $this->assertStringStartsWith('PASS  reader windows', $windows);
        $this->assertStringContainsString('2 window(s) and 5 day(s) read as written', $windows);

        $this->assertStringContainsString('Nothing in this file would be refused.', $output);
        $this->assertSame(0, $exit);
    }

    /**
     * The opt-out and the refusal are told apart on the same row: no windows at all is how the
     * fallback is turned off on purpose, and saying "nothing refused" without saying which of the
     * two states that is would leave a reader unable to tell them apart.
     */
    public function test_the_config_file_flag_tells_a_deliberate_opt_out_from_a_refusal(): void
    {
        $path = $this->configFile(['pgcat' => ['enabled' => false]]);

        [$output, $exit] = $this->vetConfig($path);

        $windows = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('PASS  reader windows', $windows);
        $this->assertStringContainsString('no swrr.reader_windows set — the documented opt-out', $windows);
        $this->assertSame(0, $exit, 'a config that asks for no fallback is not a config with a mistake');
    }

    /**
     * A day list that is not written is not a mistake — the `[1, 2, 3, 4, 5]` default is the
     * package's own — so only a refused window is reported when that is all the file got wrong.
     */
    public function test_the_config_file_flag_refuses_only_what_the_file_wrote(): void
    {
        $path = $this->configFile(['reader_windows' => ['10:00-14:20']]);

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'reader windows');

        $this->assertStringStartsWith('FAIL  reader windows', $row);
        $this->assertStringContainsString('reader_windows[0] is "10:00-14:20"', $row);
        $this->assertStringNotContainsString('reader_days', $row, 'the default day list is not the file\'s value to be wrong about');
        $this->assertSame(1, $exit);
    }

    public function test_the_config_file_flag_fails_a_path_it_cannot_read(): void
    {
        $missing = $this->tempDir() . '/not-there.php';

        [$output, $exit] = $this->vetConfig($missing);
        $row = $this->rowContaining($output, 'config file');

        $this->assertStringStartsWith('FAIL  config file', $row);
        $this->assertStringContainsString($missing . ' is not a file that can be read', $row);
        $this->assertSame(1, $exit);

        // Nothing was judged, so nothing claims to have been: the two rows that need a `swrr`
        // block are absent rather than passing on an empty one.
        $this->assertStringNotContainsString('switch values', $output);
        $this->assertStringNotContainsString('reader windows', $output);
    }

    public function test_the_config_file_flag_fails_a_file_that_is_not_a_config(): void
    {
        [$output, $exit] = $this->vetConfig($this->rawConfigFile("<?php\n\nreturn 'swrr';\n"));

        $this->assertStringContainsString('returned string, not an array', $output);
        $this->assertStringContainsString('the vet reads the file the way config:cache does', $output);
        $this->assertSame(1, $exit);
    }

    /**
     * Two problems about one file, both named and neither a detail of the other.
     *
     * A file whose shape nobody can judge can *also* print while it is being read, and the two are
     * repaired differently: the shape is the file's, the printed bytes are what `config:cache`
     * would write into the cached config. Naming the shape alone is what the row used to do, and it
     * sent an operator back through the pipeline to hear the second — the same shape the `reader
     * windows`, `pgcat files` and `store probe` rows lost, reached through the vet instead of
     * through an installation.
     */
    public function test_the_config_file_flag_names_a_shape_it_cannot_judge_beside_the_bytes_printed(): void
    {
        $path = $this->rawConfigFile("<?php\n\necho 'not config';\n\nreturn 'swrr';\n");

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'config file');

        $this->assertStringStartsWith('FAIL  config file', $row, 'the shape is a failure, and a warning beside it cannot lower that');
        $this->assertStringContainsString('returned string, not an array', $row);
        $this->assertStringContainsString('printed 10 byte(s) while being read, which config:cache writes into the cached file', $row);

        // The file's own order, so the row reads as the file was met: the shape first, then what it
        // printed on the way to being that shape.
        $this->assertLessThan(
            strpos($row, 'printed 10 byte(s)'),
            strpos($row, 'returned string'),
            'the row names the problems in the order it reached them: ' . $row,
        );

        $this->assertSame(1, $exit);

        // Nothing was judged and nothing claims to have been: the two rows that need a `swrr` block
        // are absent rather than passing on the output of a file nobody could read.
        $this->assertStringNotContainsString('switch values', $output);
        $this->assertStringNotContainsString('reader windows', $output);
    }

    /**
     * A file that printed and then threw: the bytes are still the file's own problem, and they are
     * still readable — the buffer this command opened holds them, and the row is built before the
     * flush that drops the buffers a throwing file may have left open.
     *
     * The bytes are reported rather than printed, which is the same reason the buffer exists: a
     * candidate that echoes must not be able to write into the report it is being judged in.
     */
    public function test_the_config_file_flag_names_the_bytes_printed_before_a_file_threw(): void
    {
        $path = $this->rawConfigFile("<?php\n\necho 'before the throw';\n\nthrow new RuntimeException('no config here');\n");

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'config file');

        $this->assertStringStartsWith('FAIL  config file', $row);
        $this->assertStringContainsString('could not be read: no config here', $row);
        $this->assertStringContainsString('printed 16 byte(s) while being read', $row);
        $this->assertStringNotContainsString('before the throw', $output, 'what the file printed is the row\'s problem, not the report');
        $this->assertSame(1, $exit);
    }

    /**
     * The mistake this mode has to name for itself: the docs publish the `swrr` block, so the
     * most likely wrong path is the block rather than the file that returns it — and a refusal
     * that only said "no `swrr` key" would send the reader looking for the wrong problem.
     */
    public function test_the_config_file_flag_names_the_swrr_block_passed_as_the_file(): void
    {
        $path = $this->configFile([
            'pgcat' => ['enabled' => 'flase'],
            'reader_windows' => ['10:00-14:20'],
        ], wrap: false);

        [$output, $exit] = $this->vetConfig($path);

        $this->assertStringContainsString('holds pgcat, reader_windows at the top level, not under a "swrr" key', $output);
        $this->assertStringContainsString('not the block inside it', $output);
        $this->assertSame(1, $exit);
    }

    public function test_the_config_file_flag_reports_a_file_that_printed_and_still_judges_it(): void
    {
        $path = $this->rawConfigFile("<?php\n\necho 'debug';" . "\n\nreturn ['swrr' => [" . "'pgcat' => ['enabled' => false], " . "'reader_windows' => [['start' => '10:00:00', 'end' => '14:20:00']]]];\n");

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'config file');

        // A warning, not a failure: the file is usable and the output it produced is a fact about
        // it — `config:cache` would write those bytes into the cached file.
        $this->assertStringStartsWith('WARN  config file', $row);
        $this->assertStringContainsString('printed 5 byte(s) while being read', $row);
        $this->assertSame(0, $exit);

        // ...and the bytes are the report's own problem, not the report: the rows below are
        // judged, and the output starts with the banner rather than with 'debug'.
        $this->assertStringStartsWith('Config file vetted:', $output);
        $this->assertStringStartsWith('PASS  switch values', $this->rowContaining($output, 'switch values'));
        $this->assertStringStartsWith('PASS  reader windows', $this->rowContaining($output, 'reader windows'));

        // `--strict` is what a pipeline would pass, and it turns that warning into a failure.
        [, $strictExit] = $this->vetConfig($path, strict: true);
        $this->assertSame(1, $strictExit);
    }

    /**
     * The object is the same envelope with the subject named: `config_file` where an installation
     * run carries `connection` and `default_connection`, and every key a gate selects on unchanged.
     */
    public function test_the_config_file_flag_reports_through_the_json_envelope(): void
    {
        $path = $this->configFile(['pgcat' => ['enabled' => 'flase']]);

        [$output, $exit] = $this->vetConfig($path, json: true);
        $report = json_decode($output, true);

        $this->assertIsArray($report, "The object did not parse:\n" . $output);
        $this->assertSame(['command', 'config_file', 'strict', 'connection_only', 'real_test', 'verdict', 'exit_code', 'counts', 'checks'], array_keys($report));
        $this->assertSame($path, $report['config_file']);
        $this->assertSame('FAIL', $report['verdict']);
        $this->assertSame(1, $report['exit_code']);
        $this->assertSame(1, $exit);
        $this->assertSame(['checks' => 3, 'passed' => 2, 'warnings' => 0, 'failed' => 1], $report['counts']);
        $this->assertSame(['config file', 'switch values', 'reader windows'], array_column($report['checks'], 'name'));
        $this->assertStringStartsWith('swrr.pgcat.enabled is "flase"', $report['checks'][1]['detail']);
    }

    /**
     * The replica half of the vet, and the reason it reads a second file shape: the read list lives
     * in `config/database.php`, so a candidate that replaces it is judged on the metadata it holds —
     * through `Support\ReplicaMetadata`, the classifier the boot audit refuses on — rather than on
     * the incumbent's pool. A weight the next boot would refuse therefore cannot pass this vet.
     */
    public function test_the_config_file_flag_refuses_the_replica_metadata_a_candidate_read_list_holds(): void
    {
        $path = $this->databaseConfigFile([
            self::CONNECTION => [
                'driver' => 'pgsql',
                'read' => [
                    ['host' => '10.1.0.2', 'port' => 5432, 'weight' => 'heavy'],
                    ['host' => '10.1.0.3', 'port' => 5432, 'weight' => 5, 'cpu_cores' => 0],
                ],
            ],
        ]);

        [$output, $exit] = $this->vetConfig($path);

        $this->assertStringStartsWith('PASS  config file', $this->rowContaining($output, 'config file'));
        $this->assertStringContainsString('holding a "connections" block', $output);

        $row = $this->rowContaining($output, 'replica metadata');
        $this->assertStringStartsWith('FAIL  replica metadata', $row);
        $this->assertStringContainsString('[10.1.0.2:5432] weight is "heavy", which the resolver reads as 0', $row);
        $this->assertStringContainsString('[10.1.0.3:5432] cpu_cores is 0, which the resolver reads as 1 core', $row);

        // The switches and reader settings are not in this file, so their rows are not invented
        // for it: the vet reports what the file holds and nothing about the installation's config.
        $this->assertStringNotContainsString('switch values', $output);
        $this->assertStringNotContainsString('reader windows', $output);

        $this->assertSame(1, $exit);
    }

    /**
     * A candidate read list the resolver reads as written passes, and the one value the package
     * *means* — `weight: 0`, the documented drain — is named on the pass rather than failed, in the
     * classifier's own sentence, exactly as the installation row names it.
     */
    public function test_the_config_file_flag_passes_a_candidate_read_list_it_reads_as_written(): void
    {
        $path = $this->databaseConfigFile([
            self::CONNECTION => [
                'read' => [
                    ['host' => '10.1.0.2', 'port' => 5432, 'weight' => 8, 'cpu_cores' => 4, 'ram_gb' => 16],
                    ['host' => '10.1.0.3', 'port' => 5432, 'weight' => 0],
                ],
            ],
        ]);

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'replica metadata');

        $this->assertStringStartsWith('PASS  replica metadata', $row);
        $this->assertStringContainsString('holds 2 replicas and 1 of them are drained', $row);
        $this->assertStringContainsString('10.1.0.3:5432 — ' . ReplicaMetadata::DISABLED, $row);
        $this->assertStringContainsString('the rest are read as written, so nothing is refused', $row);

        $this->assertSame(0, $exit);
    }

    /**
     * A `config/database.php` that does not define the connection the run named is not a file with
     * nothing to judge — it is the wrong connection, and the row says which ones the file does
     * define rather than passing a read list it never found.
     */
    public function test_the_config_file_flag_names_a_connection_the_candidate_read_list_does_not_hold(): void
    {
        $path = $this->databaseConfigFile([
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'pgsql_proxy' => ['read' => [['host' => '10.1.0.2', 'weight' => 'heavy']]],
        ]);

        [$output, $exit] = $this->vetConfig($path);
        $row = $this->rowContaining($output, 'config file');

        $this->assertStringStartsWith('FAIL  config file', $row);
        $this->assertStringContainsString('holds a "connections" block without [' . self::CONNECTION . ']', $row);
        $this->assertStringContainsString('it defines: sqlite, pgsql_proxy', $row);

        // The read list the file does hold is not judged under a connection it never named one for.
        $this->assertStringNotContainsString('replica metadata', $output);
        $this->assertSame(1, $exit);
    }

    /**
     * The same envelope, for the second file shape: `config_file` names the subject, the key set is
     * unchanged, and the rows are the ones the file's single block earns.
     */
    public function test_the_config_file_flag_reports_a_candidate_read_list_through_the_json_envelope(): void
    {
        $path = $this->databaseConfigFile([
            self::CONNECTION => ['read' => [['host' => '10.1.0.2', 'port' => 5432, 'weight' => 'heavy']]],
        ]);

        [$output, $exit] = $this->vetConfig($path, json: true);
        $report = json_decode($output, true);

        $this->assertIsArray($report, "The object did not parse:\n" . $output);
        $this->assertSame(['command', 'config_file', 'strict', 'connection_only', 'real_test', 'verdict', 'exit_code', 'counts', 'checks'], array_keys($report));
        $this->assertSame($path, $report['config_file']);
        $this->assertSame(['config file', 'replica metadata'], array_column($report['checks'], 'name'));
        $this->assertSame(['checks' => 2, 'passed' => 1, 'warnings' => 0, 'failed' => 1], $report['counts']);
        $this->assertSame(1, $exit);
    }

    /**
     * A config file for the vet: the shape a published `config/db-manager.php` returns, written
     * out with `var_export` so the values are PHP literals rather than a second parser's idea of
     * them.
     *
     * @param array<string, mixed> $swrr
     */
    private function configFile(array $swrr, bool $wrap = true): string
    {
        $body = $wrap ? ['swrr' => $swrr] : $swrr;

        return $this->rawConfigFile("<?php\n\nreturn " . var_export($body, true) . ";\n");
    }

    /**
     * A config file shaped like `config/database.php`: the `connections` block the read list lives
     * under, written out with `var_export` so the values are PHP literals rather than a second
     * parser's idea of them. The connection the run names is the one whose `read` list is judged.
     *
     * @param array<string, mixed> $connections
     */
    private function databaseConfigFile(array $connections): string
    {
        return $this->rawConfigFile("<?php\n\nreturn " . var_export(['connections' => $connections], true) . ";\n");
    }

    /**
     * Any file, verbatim — for the ways a file can be wrong before its values are read at all.
     */
    private function rawConfigFile(string $body): string
    {
        $path = $this->tempDir() . '/db-manager.php';

        file_put_contents($path, $body);

        return $path;
    }

    /**
     * The command in config-file mode, reported the way the two `doctor()` helpers report it —
     * read from a real buffer, because the rows carry colour tags and the point is what an
     * operator or a pipeline would see.
     *
     * @return array{0: string, 1: int}
     */
    private function vetConfig(string $path, bool $strict = false, bool $json = false): array
    {
        $buffer = new BufferedOutput();

        $exit = $this->app->make(Kernel::class)->call(
            'db:doctor',
            ['connection' => self::CONNECTION, '--config-file' => $path, '--strict' => $strict, '--json' => $json],
            $buffer,
        );

        return [$buffer->fetch(), $exit];
    }

    /**
     * The same run, reported as data rather than rendered. Only the flag differs from `doctor()`
     * above, which is what makes one call in each style comparable: `--json` is a report, not a
     * mode, so the checks that ran are the checks the table would have shown.
     *
     * @return array{0: string, 1: int}
     */
    private function doctorJson(bool $strict = false, bool $connectionOnly = false, bool $contract = false, bool $realTest = false): array
    {
        $buffer = new BufferedOutput();

        $exit = $this->app->make(Kernel::class)->call(
            'db:doctor',
            [
                'connection' => self::CONNECTION,
                '--strict' => $strict,
                '--json' => true,
                '--connection-only' => $connectionOnly,
                '--contract' => $contract,
                '--real-test' => $realTest,
            ],
            $buffer,
        );

        return [$buffer->fetch(), $exit];
    }

    /**
     * Leave the record a previous boot would have written: an unresolved pgcat
     * mismatch, in the shape the audit reads back.
     */
    /**
     * A boot record holding the findings a test wants a row to be dated from.
     *
     * Written the way the audit writes it, because the reader is strict about the shape: a test
     * that invented a thinner one would be asserting against a record the package would treat as
     * unreadable rather than as nothing standing.
     *
     * @param array<string, string> $keys finding key => when it was first reported
     */
    private function recordFindings(array $keys): void
    {
        $findings = [];

        foreach ($keys as $key => $since) {
            $findings[$key] = [
                'resolution' => 'This is what closes it out.',
                'warning' => 'This is what it says while it stands.',
                'level' => 'warning',
                'context' => [],
                'first_reported_at' => $since,
            ];
        }

        file_put_contents((string) config('db-manager.swrr.audit.file'), (string) json_encode([
            'findings' => $findings,
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function recordGateMismatch(string $connection, string $driver, string $since): void
    {
        file_put_contents((string) config('db-manager.swrr.audit.file'), (string) json_encode([
            'findings' => [
                WeightedDatabaseServiceProvider::KEY_PGCAT_GATE => [
                    'resolution' => 'The pgcat mismatch no longer applies: swrr.pgcat.enabled and the connection\'s driver no longer disagree.',
                    'context' => ['connection' => $connection, 'driver' => $driver],
                    'first_reported_at' => $since,
                ],
            ],
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Leave the record a previous boot would have written: reader findings still
     * unresolved, in the shape the audit reads back — one entry per finding key, which is
     * what makes a row date the problem it is reporting rather than the first one on file.
     *
     * @param array<string, string> $findings finding key => when it was first reported
     */
    private function recordReaderFindings(array $findings): void
    {
        $entries = [];

        foreach ($findings as $key => $since) {
            $entries[$key] = [
                'resolution' => 'The reader fallback reads again, so the earlier warning no longer applies.',
                'warning' => 'a finding a previous boot left standing',
                'context' => ['reader_windows' => null, 'reader_days' => null],
                'first_reported_at' => $since,
            ];
        }

        file_put_contents((string) config('db-manager.swrr.audit.file'), (string) json_encode([
            'findings' => $entries,
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Leave the record a previous boot would have written for the switch refusals, in the
     * shape the audit reads back — one entry per finding key, which is what makes a row date
     * the refusal it is reporting rather than the first one on file.
     *
     * @param array<string, string> $findings finding key => when it was first reported
     */
    private function recordSwitchFindings(array $findings): void
    {
        $entries = [];

        foreach ($findings as $key => $since) {
            $entries[$key] = [
                'resolution' => 'The switch reads as on or off again, so the value is no longer refused.',
                'warning' => 'a finding a previous boot left standing',
                'context' => ['setting' => 'swrr.pgcat.enabled', 'configured' => '"flase"'],
                'first_reported_at' => $since,
            ];
        }

        file_put_contents((string) config('db-manager.swrr.audit.file'), (string) json_encode([
            'findings' => $entries,
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The README's `db:doctor` table: the rows an operator reads about the preflight.
     *
     * @return list<string>
     */
    private function documentedChecks(): array
    {
        return array_map(
            [Readme::class, 'plain'],
            array_column(Readme::table('### Preflight: `db:doctor`', 'Row'), 'Row'),
        );
    }

    /**
     * The rows a run builds when `db` is not the weighted manager: the nine that do not ask it.
     *
     * @return list<string>
     */
    private function unweightedChecks(): array
    {
        // Something else has replaced `db` and `db.factory` — the state where reads are
        // unweighted — so only the rows that do not need the manager are built.
        $this->app->instance('db.factory', new ConnectionFactory($this->app));
        $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        DB::clearResolvedInstance('db');

        [$json] = $this->doctorJson();

        return array_column($this->report($json)['checks'], 'name');
    }

    /**
     * The rows of this run whose `suggestions` list is not empty, keyed by the row's name.
     *
     * @return array<string, true>
     */
    private function rowsCarryingARepair(): array
    {
        [$json] = $this->doctorJson();

        $carrying = [];

        foreach ($this->report($json)['checks'] as $check) {
            if ($check['suggestions'] !== []) {
                $carrying[$check['name']] = true;
            }
        }

        return $carrying;
    }

    /**
     * One run in which every row that can name several problems has more than one: both reader
     * settings refused, two switches that are not switches, two paths the flip cannot write, and —
     * because the record is made read-only after it is written — a store probe that is off beside a
     * record that cannot be written.
     *
     * One run rather than a fixture per row, because the register is about the row *set*: a fixture
     * built for a single row leaves the others at whatever the default slice happens to be, and the
     * set test would then be reading the slice rather than the claim.
     *
     * The record is written *and then* made read-only, in that order, because the dated rows need
     * the entries and the store probe needs the record unwritable — one file both ways, which is
     * what makes a single run enough for every case. The store probe's two states are findings of
     * their own now, so its problems are dated from this same record like the others'.
     *
     * @return string the record's path, so a case can skip where the platform ignores the bit
     */
    private function everyRowWithSeveralProblems(): string
    {
        $dir = $this->tempDir();

        // A path already occupied by a regular file can never be the directory beside it, so the
        // two state paths fail without depending on mode bits.
        file_put_contents($dir . '/blocked', '');

        $this->armPgcat([
            'use_reload' => 'maybe',
            'state_file' => $dir . '/blocked/state.json',
            'lock_file' => $dir . '/blocked/flip.lock',
        ]);

        config()->set('db-manager.swrr.allow_local_fallback', 'nope');
        // Set after `armPgcat()`, which replaces the whole `swrr.pgcat` block: the two schedule
        // switches are refused values beside the two pgcat ones, so the row that reads them has
        // problems of its own rather than passing over the block it was handed.
        config()->set('db-manager.swrr.pgcat.schedule', [
            'enabled' => 'flase',
            'windows' => ['enabled' => 'flase'],
        ]);
        $this->useReaderFallback(['10:00-14:20'], '1,2,3');

        $record = $this->tempDir() . '/audit.json';

        // One entry per problem the dated rows have, so each row has as many dates as problems and
        // the dates are the run's own count of them.
        $this->useAudit(0, $record);
        $this->recordFindings([
            WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED => '2026-09-20T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_READER_DAYS_REFUSED => '2026-09-21T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_PGCAT_RELOAD_REFUSED => '2026-09-22T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_FALLBACK_REFUSED => '2026-09-23T08:15:00+00:00',
            WeightedDatabaseServiceProvider::PGCAT_FILE_KEYS['state_file'] => '2026-09-24T08:15:00+00:00',
            WeightedDatabaseServiceProvider::PGCAT_FILE_KEYS['lock_file'] => '2026-09-25T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_STORE_PROBE_OFF => '2026-09-26T08:15:00+00:00',
            WeightedDatabaseServiceProvider::KEY_STORE_PROBE_FILE_UNWRITABLE => '2026-09-27T08:15:00+00:00',
        ]);
        chmod($record, 0o444);

        return $record;
    }

    /**
     * The number a record states as a word, read out of the record.
     *
     * A claim's *presence* is asserted first: a sentence that has been reworded away would
     * otherwise compare nothing with nothing and pass, which is how a guard becomes a decoration.
     * The spellings and the words live in `NumberWords`, so both halves of the guard accept the
     * same ones — and the renderer writes the same words back.
     */
    private function numberClaim(string $record, string $pattern): int
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.$record);

        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all($pattern, $source),
            "{$record} no longer states the claim this guard is pinned to ({$pattern})",
        );

        preg_match($pattern, $source, $match);

        return NumberWords::toInt($match[1]);
    }

    /**
     * The framework's connection resolvers as they stand, and the way back to them.
     *
     * `Connection::resolverFor()` is a static registry with no unregister, so a case that
     * registers one has to put back what it found: a resolver left behind would make every later
     * case in this file describe an installation that replaced a class, which is exactly the
     * state the cases are trying to tell apart.
     *
     * @return array<string, \Closure>
     */
    private static function resolvers(): array
    {
        $resolvers = (new ReflectionProperty(Connection::class, 'resolvers'))->getValue();

        return is_array($resolvers) ? $resolvers : [];
    }

    /**
     * @param array<string, \Closure> $resolvers
     */
    private static function restoreResolvers(array $resolvers): void
    {
        (new ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, $resolvers);
    }

    /**
     * The suggestion line the doctor printed, if it printed one — the row's repair, which
     * stands in the row-name column and carries no verdict.
     */
    private function suggestion(string $output): ?string
    {
        return $this->suggestions($output)[0] ?? null;
    }

    /**
     * Every suggestion line the doctor printed, in the order it printed them: a row that
     * names two refused settings has two repairs, one line each.
     *
     * @return list<string>
     */
    private function suggestions(string $output): array
    {
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with(ltrim($line), 'suggestion')) {
                $lines[] = trim($line);
            }
        }

        return $lines;
    }

    private function rowContaining(string $output, string $check): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, $check)) {
                return trim($line);
            }
        }

        $this->fail("The doctor printed no [{$check}] row:\n" . $output);
    }

    /**
     * A throwaway directory to hold a pgcat file set, remembered so tearDown can
     * delete it — including the read-only-shaped entries a failing check implies.
     */
    /**
     * Point the boot at a settings file this test owns, and return its path.
     *
     * The row names the file the boot was *pointed at* rather than the conventional `.env`, so
     * moving the app's environment path is what makes the assertion about the row's reading instead
     * of about whichever file the machine running the suite happens to have beside the skeleton.
     */
    private function useEnvironmentFile(string $name = '.env'): string
    {
        $dir = $this->tempDir();

        // The framework joins the path and the file name with `DIRECTORY_SEPARATOR`, so this test
        // builds the path it will compare the row's sentence against the same way.
        $file = $dir . DIRECTORY_SEPARATOR . $name;

        file_put_contents($file, "APP_ENV=testing\n");

        $this->app->useEnvironmentPath($dir);
        $this->app->loadEnvironmentFrom($name);

        return $file;
    }

    /**
     * Leave a configuration cache where the framework looks for one and tell the container the boot
     * read from it — the two halves `getCachedConfigPath()` and `configurationIsCached()` are
     * answered from, so the row's comparison is driven rather than inferred from whatever is on this
     * machine.
     *
     * The file goes to the path the framework itself names, which is the one `config:cache` writes:
     * `APP_CONFIG_CACHE` is not a lever a test can pull here, because `normalizeCachePath()` treats
     * an absolute path as one that starts with `/`, so a Windows temp path is silently prefixed with
     * the application's base path instead. `tearDown()` deletes it, because the next test's boot
     * would otherwise find a cache nobody claimed.
     */
    private function useConfigCache(): string
    {
        $cache = $this->app->getCachedConfigPath();

        file_put_contents($cache, "<?php\n\nreturn [];\n");

        $this->configCache = $cache;

        // What `LoadConfiguration` binds when it loads the cached arrays instead of the config files.
        $this->app->instance('config_loaded_from_cache', true);

        return $cache;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/db-doctor-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Arm the flipper against a throwaway file set in a writable directory, so a
     * test only has to spoil the one file it is about: the reader source, the
     * writer source, the flip target and the two state files all start in place.
     *
     * The paths the flipper actually received are returned, because a test asserts
     * on the file the row names, not on a path it guessed itself.
     *
     * @param array<string, mixed> $overrides pgcat config keys pointing elsewhere
     * @return array<string, string>
     */
    private function armPgcat(array $overrides = []): array
    {
        $dir = $this->tempDir();

        $paths = [
            'config_path' => $dir . '/pgcat.toml',
            'readers_path' => $dir . '/pgcat-readers.toml',
            'no_readers_path' => $dir . '/pgcat-no-readers.toml',
            'state_file' => $dir . '/flip-state.json',
            'lock_file' => $dir . '/flip.lock',
        ];

        foreach (['config_path', 'readers_path', 'no_readers_path'] as $key) {
            file_put_contents($paths[$key], "# {$key}\n");
        }

        // The flip's own commands, pointed at a binary this test owns, so the
        // `pgcat supervisor` row resolves a real file instead of whatever the machine
        // running the suite happens to have on PATH — and the runner below answers in
        // supervisor's place. A test that is about the command passes its own overrides.
        $binary = $this->fakeSupervisorctl($dir);

        // The flipper is a singleton, so the new slice only takes effect once the
        // resolved instance is dropped.
        config()->set('db-manager.swrr.pgcat', [
            'enabled' => true,
            'restart_command' => $binary . ' restart "pgcat:*"',
            'reload_command' => $binary . ' signal HUP "pgcat:*"',
            'use_reload' => true,
            ...$paths,
            ...$overrides,
        ]);

        $this->app->forgetInstance(PgcatConfigFlipper::class);
        $this->bindSupervisor();

        return $paths + ['supervisorctl' => $binary];
    }

    /**
     * Arm the flipper with a probe that answers in the test's place, and declare the pools the
     * readers variant holds — the file the probe reads the names off.
     *
     * The file set underneath is `armPgcat()`'s, so every other row of the run still judges a
     * working installation, and the flipper is bound as an instance rather than left to the
     * provider's singleton because the probe is a closure: a real one opens a socket, and what is
     * under test is the row's reading of the answers rather than the socket underneath it.
     *
     * `$answers` names the status per database and defaults to every pool answering; the three
     * statuses are `PoolProbe`'s vocabulary. `$windowClosed` writes a boot stamp an hour old — the
     * state the verdict is judged in — and with it false nothing records a boot at all, which is the
     * container still starting up.
     *
     * The store is bound reachable here rather than left to the machine, which is what "every other
     * row of the run still judges a working installation" costs: the reachability row PINGs the
     * store, so an unbound one makes these tests green on a machine that happens to run Redis
     * (127.0.0.1:6379, which is what CI's runner does not have) and red on the runner — four tests
     * reading exit 1 while every row they assert on is right.
     *
     * `$admin` is the answer a pooler's own console gives — null for no console at all, which is the
     * shape an installation that injected its own probe has. It is asked before the front door in
     * the sweep, and the row reads it as `verified`: a console that lists the pools is proof, and one
     * that could not be read leaves the row saying so rather than passing.
     *
     * @param list<string>          $pools   the pools the readers variant declares
     * @param array<string, string> $answers database => status
     * @param array{status: string, pools: list<string>, evidence: string}|null $admin the console's answer
     * @return array<string, string> the paths `armPgcat()` returned
     */
    private function armPgcatWithPoolProbe(array $pools, array $answers = [], bool $windowClosed = true, string $control = 'missing', ?array $admin = null): array
    {
        $this->bindRedis(reachable: true);

        $overrides = [];

        if ($windowClosed) {
            $stamp = $this->tempDir().'/container-booted-at';
            file_put_contents($stamp, (string) (time() - 3600));

            $overrides = ['boot_file' => $stamp, 'flip_window_seconds' => 480];
        }

        $paths = $this->armPgcat($overrides);

        // A container an hour into its life is one whose per-minute entry has been running, and the
        // beat is what says so: a boot stamp with no beat beside it is exactly the fault `schedule
        // beat` exists to report, so a fixture built to leave every other row judging a working
        // installation would otherwise reach exit 1 on the row the test is not about.
        if ($windowClosed) {
            file_put_contents($paths['state_file'], json_encode([
                ScheduleBeat::STATE_KEY => gmdate(DATE_ATOM),
            ]));
        }

        file_put_contents(
            $paths['readers_path'],
            implode('', array_map(static fn (string $pool): string => "[pools.{$pool}]\n", $pools)),
        );

        $probe = static function (string $database) use ($answers, $control): array {
            // The control is the one name no configuration declares: a fixture that had to be
            // right about the random suffix would be testing `random_bytes()`, so the prefix is
            // the fixture's handle on it.
            $status = str_starts_with($database, 'weighted-dbmanager-control-')
                ? $control
                : ($answers[$database] ?? 'answered');

            return [
                'status' => $status,
                'evidence' => match ($status) {
                    'missing' => sprintf('pgcat is running but has no pool "%s": No pool configured for database: "%s"', $database, $database),
                    'silent' => sprintf('tcp://127.0.0.1:7432 did not answer for pool "%s": Connection refused', $database),
                    default => sprintf('the pooler answered for pool "%s"', $database),
                },
                // The endpoint the packets went to, as `PoolProbe::ask()` returns it. 7432 rather
                // than pgcat's 6432 so a row that printed the convention instead of the exchange
                // would be a different string, and so would one that printed nothing.
                'host' => '127.0.0.1',
                'port' => 7432,
                'address' => 'tcp://127.0.0.1:7432',
            ];
        };

        $this->app->instance(PgcatConfigFlipper::class, new PgcatConfigFlipper(
            resolver: $this->app->make(TimeWindowResolver::class),
            config: ConfigValue::assoc(config('db-manager.swrr.pgcat')),
            stateFile: $paths['state_file'],
            lockFile: $paths['lock_file'],
            timezone: 'UTC',
            connectionName: self::CONNECTION,
            driver: 'pgsql',
            supervisorStep: new SupervisorStep(static fn (string $command): array => [0, 'pgcat:pgcat_00 RUNNING pid 1', '']),
            poolProbe: $probe,
            poolAdmin: $admin === null ? null : static fn (): array => $admin,
        ));

        return $paths;
    }

    /**
     * A file that passes for the supervisorctl binary a flip's command names, so the
     * row's resolution check has something real to resolve. Windows resolves a bare
     * name through PATHEXT and only calls a file executable when its extension says so,
     * which is why the stand-in is named for the platform. Nothing executes it: the
     * runner does.
     */
    private function fakeSupervisorctl(string $dir): string
    {
        $path = $dir . '/' . (PHP_OS_FAMILY === 'Windows' ? 'supervisorctl.exe' : 'supervisorctl');

        file_put_contents($path, "# stands in for supervisorctl\n");
        @chmod($path, 0o755);

        return $path;
    }

    /**
     * Bind the supervisor step, so the row's read-only check is answered by the fake
     * rather than by a supervisorctl this machine does not have. Arm first, then bind,
     * when a test is about what supervisor answered.
     */
    private function bindSupervisor(?FakeSupervisor $supervisor = null): FakeSupervisor
    {
        $supervisor ??= new FakeSupervisor();

        $this->app->instance(SupervisorStep::class, new SupervisorStep($supervisor->runner()));

        return $supervisor;
    }

    /**
     * A fake that answers the two questions an unknown program produces: supervisor does not
     * know the program, and then — asked what it is running at all — answers `running`.
     *
     * The distinction is the bare command, which is the derived `status` with nothing after it.
     */
    private function supervisorThatRuns(string $unknown, string $running): FakeSupervisor
    {
        return new FakeSupervisor(answers: static function (string $command) use ($unknown, $running): array {
            return str_ends_with($command, ' status')
                ? [0, $running, '']
                : [2, '', $unknown];
        });
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }

            // A read-only file would survive the unlink on Windows.
            @chmod($path, 0o666);
            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Bind a Redis stand-in, so the doctor's probe talks to it rather than to a
     * real server. The probe goes through the container, so the binding is all it
     * takes — there is no facade root to clear.
     */
    private function bindRedis(bool $reachable): FakeRedis
    {
        $redis = new FakeRedis($reachable);

        $this->app->instance('redis', $redis);

        return $redis;
    }

    /**
     * The store is built by the provider at resolve time, so a test that changes
     * which store is configured has to let both the store and the manager be built
     * again — otherwise the manager is new but still holds the old store.
     */
    private function useRedisConnection(string $name): void
    {
        config()->set('db-manager.swrr.redis_connection', $name);
        $this->rebuildManager();
    }

    private function rebuildManager(): void
    {
        $this->app->forgetInstance(RedisAtomicStateStore::class);
        $this->app->forgetInstance(LocalStateStore::class);
        $this->app->forgetInstance('db');
        DB::clearResolvedInstance('db');
    }

    /**
     * A store that is permanently unhealthy, so the manager has to degrade.
     */
    private function deadStore(): AtomicStateStore
    {
        return new class () implements AtomicStateStore {
            public function next(string $connectionName, array $replicaKeys, array $weights): int
            {
                throw new RuntimeException('An unhealthy store must never serve a step.');
            }

            public function reset(string $connectionName, array $replicaKeys): void
            {
            }

            public function isHealthy(): bool
            {
                return false;
            }

            public function name(): string
            {
                return 'dead-store';
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Uak35\WeightedDbManager\Console\Commands\DbFlipPgcatCommand;
use Uak35\WeightedDbManager\Console\Commands\DbProbeReplicas;
use Uak35\WeightedDbManager\Console\Commands\DbReplicaStatus;
use Uak35\WeightedDbManager\Database\Weighted\AtomicStateStore;
use Uak35\WeightedDbManager\Database\Weighted\HealthMonitor;
use Uak35\WeightedDbManager\Database\Weighted\LocalStateStore;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Tests\Support\FakeSupervisor;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;
use Uak35\WeightedDbManager\Tests\Support\FakeRedis;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * Boots the whole package and checks the wiring end to end: the factory the
 * framework asks for read configs, the formula and factors that actually reach
 * the resolver, the key prefix both stores use, and what happens when the
 * primary store dies.
 *
 * Formula check: with the shipped 3.0 / 3.375 factors,
 *   diminishing → round(16^0.7×3.0 + √64×3.375) = 48   round(8^0.7×3.0 + √32×3.375) = 32
 *   linear      → round(16×3.0 + 64×3.375)     = 264  round(8×3.0 + 32×3.375)     = 132
 */class WeightedDatabaseServiceProviderTest extends TestCase
{
    /**
     * Every finding key the richest misconfiguration produces, at once.
     *
     * Named rather than written into the assertion, because the *count* is a claim two records
     * make in prose — `docs/boot-audit-finding-keys.md` says "eight findings, eight keys" — and a
     * number written twice is a number that can drift. `ProseNumbersTest` reads this list, so the
     * sentence and the set are one source: changing the set without touching the record fails the
     * suite, and neither the record nor this list holds an independent copy of the number.
     *
     * @var list<string>
     */
    public const RICHEST_BOOT_KEYS = [
        'swrr.pgcat.gate',
        'swrr.reader_windows.refused',
        'swrr.reader_days.refused',
        'swrr.primary_store.unknown',
        'swrr.default_weight_formula.unknown',
        'database.read.weight.refused',
        'database.read.cpu_cores.refused',
        'database.read.ram_gb.refused',
    ];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function test_the_framework_binding_is_replaced_by_the_weighted_factory(): void
    {
        $this->assertInstanceOf(WeightedConnectionFactory::class, $this->app->make('db.factory'));
    }

    public function test_the_database_manager_is_the_weighted_one(): void
    {
        $this->assertInstanceOf(WeightedDatabaseManager::class, $this->app->make('db'));
    }

    public function test_the_configured_formula_and_factor_defaults_reach_the_resolver(): void
    {
        $summary = $this->manager()->healthSummary(self::CONNECTION);

        // 'diminishing' comes from db-manager.swrr.default_weight_formula.
        $this->assertSame('diminishing', $summary['formula']);
        $this->assertSame(3.0, $summary['cpu_factor']);
        $this->assertSame(3.375, $summary['ram_factor']);
    }

    public function test_weights_follow_the_configured_formula(): void
    {
        $rows = $this->manager()->replicaStatus(self::CONNECTION);

        $this->assertSame(['10.1.0.1', '10.1.0.2'], array_column($rows, 'host'));
        $this->assertSame([48, 32], array_column($rows, 'weight'));
        $this->assertSame([60.0, 40.0], array_column($rows, 'share_pct'));
        $this->assertSame([true, true], array_column($rows, 'healthy'));
    }

    public function test_a_connection_can_override_the_formula(): void
    {
        $rows = $this->manager()->replicaStatus('weighted_linear');

        $this->assertSame([264, 132], array_column($rows, 'weight'));
    }

    public function test_the_shipped_ram_factor_is_used_when_config_omits_it(): void
    {
        // An installation whose swrr block predates default_weight_ram_factor.
        config()->set('db-manager.swrr', [
            'primary_store' => 'redis',
            'default_weight_cpu_factor' => 3.0,
            'default_weight_formula' => 'linear',
        ]);

        $rows = $this->manager()->replicaStatus('weighted_linear');

        $this->assertSame([264, 132], array_column($rows, 'weight'));
    }

    public function test_the_configured_key_prefix_reaches_both_stores(): void
    {
        $this->assertSame(self::KEY_PREFIX, $this->prefixOf($this->manager()->store()));
        $this->assertSame(self::KEY_PREFIX, $this->prefixOf($this->app->make(LocalStateStore::class)));

        $this->manager()->setStore($this->deadStore());

        $this->assertSame(self::KEY_PREFIX, $this->prefixOf($this->manager()->activeStore()));
    }

    public function test_reads_route_to_a_replica(): void
    {
        $manager = $this->manager();
        $picked = $manager->readConfigFor($manager->connectionConfig(self::CONNECTION));

        $this->assertContains($picked['host'], ['10.1.0.1', '10.1.0.2']);
        $this->assertSame(5432, $picked['port']);
        $this->assertArrayNotHasKey('read', $picked);
    }

    public function test_reads_go_to_the_writer_outside_every_reader_window(): void
    {
        // A window that can never match (start is inclusive, end exclusive), on
        // a day that is a reader day — so the resolver is not in always-reader
        // mode and the writer has to serve.
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '00:00:00']]);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5, 6, 7]);
        $this->app->forgetInstance(TimeWindowResolver::class);

        $manager = $this->manager();
        $picked = $manager->readConfigFor($manager->connectionConfig(self::CONNECTION));

        $this->assertSame('10.1.0.9', $picked['host']);
    }

    /**
     * The same fallback, declared the way a real installation declares it: `write` as a *list*
     * of server entries (Laravel's shape for a write connection, and what config/database.php
     * in the LPR app uses) with **no top-level host**, so the address exists only inside the
     * list the merge has to narrow to one entry.
     *
     * The test above asserts the host of a fixture that declares `write` as a single map — the
     * one shape ConfigValue::assoc() wraps correctly — so it passes whether or not the list
     * branch merges the entry, and the difference between the two declarations is the whole
     * bug: with a list, the entry arrived at key 0, `read`/`write` were dropped, and the config
     * reached the connector with no host at all (libpq then dials its default socket, port 5432,
     * instead of the configured 5433).
     */
    public function test_reads_go_to_the_writer_when_write_is_a_list_and_there_is_no_top_level_host(): void
    {
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '00:00:00']]);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5, 6, 7]);
        $this->app->forgetInstance(TimeWindowResolver::class);

        config()->set('database.connections.weighted_list', [
            'driver' => 'pgsql',
            'write' => [['host' => '10.1.0.9', 'port' => 5433, 'database' => 'writer_db_1']],
            'read' => [[
                'host' => '10.1.0.1',
                'port' => 5433,
                'database' => 'reader_db_2',
                'cpu_cores' => 4,
                'ram_gb' => 16,
                'weight' => 1.0,
            ]],
        ]);

        $manager = $this->manager();
        $picked = $manager->readConfigFor($manager->connectionConfig('weighted_list'));

        $this->assertSame('10.1.0.9', $picked['host']);
        $this->assertSame(5433, $picked['port']);
        $this->assertSame('writer_db_1', $picked['database']);
        $this->assertArrayNotHasKey('read', $picked);
        $this->assertArrayNotHasKey('write', $picked);
    }

    public function test_it_degrades_loudly_and_reports_which_store_is_serving(): void
    {
        $manager = $this->manager();
        $manager->setStore($this->deadStore());

        $picked = $manager->readConfigFor($manager->connectionConfig(self::CONNECTION));

        $this->assertContains($picked['host'], ['10.1.0.1', '10.1.0.2']);
        $this->assertTrue($manager->isDegraded());
        $this->assertSame('local(in-process, uncoordinated)', $manager->activeStore()->name());

        $summary = $manager->healthSummary(self::CONNECTION);

        $this->assertTrue($summary['degraded']);
        $this->assertSame('dead-store', $summary['primary_store']);
        $this->assertFalse($summary['store_healthy']);
        $this->assertSame('local(in-process, uncoordinated)', $summary['store']);
    }

    public function test_the_status_command_surfaces_the_degraded_store(): void
    {
        $manager = $this->manager();
        $manager->setStore($this->deadStore());
        $manager->readConfigFor($manager->connectionConfig(self::CONNECTION));

        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Primary:')
            ->assertExitCode(0);
    }

    public function test_it_hard_fails_when_the_fallback_is_disabled(): void
    {
        $manager = $this->manager();
        $manager->setStore($this->deadStore());
        $manager->setAllowLocalFallback(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fallback is disabled');

        $manager->readConfigFor($manager->connectionConfig(self::CONNECTION));
    }

    public function test_it_registers_its_commands_and_publish_tag(): void
    {
        $commands = $this->app->make(ConsoleKernel::class)->all();

        $this->assertInstanceOf(DbReplicaStatus::class, $commands['db:replica-status']);
        $this->assertInstanceOf(DbProbeReplicas::class, $commands['db:probe-replicas']);
        $this->assertInstanceOf(DbFlipPgcatCommand::class, $commands['db:pgcat-flip']);

        $this->assertNotEmpty(
            ServiceProvider::pathsToPublish(WeightedDatabaseServiceProvider::class, 'db-manager-config'),
        );
    }

    public function test_the_probe_command_runs_instead_of_fataling(): void
    {
        $this->artisan('db:probe-replicas', ['connection' => 'writer_only'])
            ->expectsOutputToContain('No replicas configured')
            ->assertExitCode(0);
    }

    public function test_pgcat_is_enabled_for_the_postgresql_default_connection(): void
    {
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $status = $this->app->make(PgcatConfigFlipper::class)->status();

        $this->assertSame(self::CONNECTION, $status['connection']);
        $this->assertSame('pgsql', $status['driver']);
        $this->assertTrue($status['driver_supported']);
        $this->assertTrue($status['enabled']);
    }

    /**
     * An application whose real data path is a *non-default* connection — the host app's
     * `app.default_api_connection` (env `API_DB_CONNECTION`) — names it under
     * `swrr.connection`, and the flipper follows that rather than `database.default`. The
     * default here is a connection pgcat cannot front, so without the knob this is exactly
     * the mismatch the gate exists to warn about.
     */
    public function test_the_pgcat_surfaces_follow_a_named_connection_over_database_default(): void
    {
        $this->useMysqlDefaultConnection();
        config()->set(ActiveConnection::CONFIGURED_SOURCE, self::CONNECTION);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $status = $this->app->make(PgcatConfigFlipper::class)->status();

        $this->assertSame(self::CONNECTION, $status['connection']);
        $this->assertSame('pgsql', $status['driver']);
        $this->assertTrue($status['driver_supported']);
        $this->assertTrue($status['enabled']);
        $this->assertFalse($status['mismatch'], 'the named connection is PostgreSQL, so nothing is mismatched');
    }

    /**
     * The boot gate judges the connection the installation named, not `database.default`. A
     * named PostgreSQL connection silences the finding even while the default connection is
     * one pgcat cannot front.
     */
    public function test_a_named_non_default_connection_silences_the_pgcat_gate(): void
    {
        $this->useMysqlDefaultConnection();
        config()->set(ActiveConnection::CONFIGURED_SOURCE, self::CONNECTION);
        $this->usePgcat(['enabled' => true]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);

        $this->reportBootAudit();

        $gate = array_values(array_filter(
            $records,
            static fn (array $record): bool => ($record['context']['finding'] ?? null) === 'swrr.pgcat.gate',
        ));

        $this->assertSame([], $gate, 'the gate judged the named connection, not the default');
    }

    public function test_pgcat_is_disabled_when_the_current_connection_is_not_postgresql(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $flipper = $this->app->make(PgcatConfigFlipper::class);
        $status  = $flipper->status();

        $this->assertSame('mysql_app', $status['connection']);
        $this->assertSame('mysql', $status['driver']);
        $this->assertFalse($status['driver_supported']);
        $this->assertFalse($status['enabled']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('no_change', $result->kind());
        $this->assertStringContainsString('mysql', $result->reason);
    }

    public function test_the_pgcat_status_command_names_the_driver(): void
    {
        $this->artisan('db:pgcat-flip', ['--status' => true])
            ->expectsOutputToContain('postgresql')
            ->assertExitCode(0);
    }

    /**
     * Every entry point stops on a non-PostgreSQL connection. --watch matters
     * most: without the guard it would sit in its polling loop printing the same
     * refusal until something killed it.
     */
    public function test_every_flip_mode_exits_without_work_on_a_non_postgresql_connection(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $modes = [
            'one-shot' => [],
            'force-mode' => ['--force-mode' => 'readers'],
            'watch' => ['--watch' => true, '--interval' => 1],
            'dry-run' => ['--dry-run' => true],
        ];

        foreach ($modes as $mode => $options) {
            $this->artisan('db:pgcat-flip', $options)
                ->expectsOutputToContain('PostgreSQL-only')
                ->assertExitCode(0);
        }
    }

    /**
     * The rehearsal as the operator sees it: the summary line, then every step of a flip — and
     * afterwards, nothing changed. The supervisor line is the load-bearing one: the command a
     * flip would run is printed, and it was never run.
     */
    public function test_the_dry_run_reports_every_step_of_a_flip_without_taking_the_last_two(): void
    {
        $paths = $this->armTheFlip();

        $this->artisan('db:pgcat-flip', ['--dry-run' => true])
            ->expectsOutputToContain('[dry run] would flip')
            ->expectsOutputToContain('read source')
            ->expectsOutputToContain('supervisor check')
            ->expectsOutputToContain('restart "pgcat:*"')
            ->assertExitCode(0);

        $this->assertSame("pool = 'unknown'\n", file_get_contents($paths['config_path']));
        $this->assertFileDoesNotExist($paths['state_file'], 'a rehearsal must not record a mode it did not apply');
        $this->assertSame([], glob($paths['config_path'].'.tmp.*') ?: [], 'the probe is removed again');
    }

    public function test_the_dry_run_rehearses_the_file_a_forced_flip_would_apply(): void
    {
        $this->armTheFlip();

        $this->artisan('db:pgcat-flip', ['--dry-run' => true, '--force-mode' => 'writer'])
            ->expectsOutputToContain('pgcat-no-readers.toml')
            ->assertExitCode(0);
    }

    public function test_the_dry_run_validates_force_mode_like_the_flip_it_rehearses(): void
    {
        $this->armTheFlip();

        $this->artisan('db:pgcat-flip', ['--dry-run' => true, '--force-mode' => 'sideways'])
            ->expectsOutputToContain("--force-mode must be 'readers' or 'writer'")
            ->assertExitCode(1);
    }

    /**
     * A watch daemon whose every pass is a rehearsal looks exactly like one that is flipping,
     * so the pair is refused rather than half-honoured.
     */
    public function test_the_dry_run_refuses_to_watch(): void
    {
        $this->armTheFlip();

        $this->artisan('db:pgcat-flip', ['--dry-run' => true, '--watch' => true])
            ->expectsOutputToContain('mutually exclusive')
            ->assertExitCode(1);
    }

    public function test_the_status_flag_wins_when_both_it_and_the_dry_run_are_passed(): void
    {
        $paths = $this->armTheFlip();

        $this->artisan('db:pgcat-flip', ['--status' => true, '--dry-run' => true])
            ->expectsOutputToContain('config_path')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($paths['state_file']);
    }

    public function test_the_health_endpoint_and_the_flipper_carry_the_same_pgcat_snapshot(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // Nothing is listening on the fixture replicas and the controller probes
        // them with getPdo(): drop the read lists so it does not open a socket.
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pgcat.enabled', true)
            ->assertJsonPath('pgcat.driver', 'pgsql')
            ->assertJsonPath('pgcat.driver_supported', true)
            ->assertJsonPath('pgcat.reason', null);

        $this->assertSame(
            $this->app->make(PgcatConfigFlipper::class)->healthSummary(),
            $response->json('pgcat'),
        );
    }

    public function test_the_health_endpoint_stays_ok_when_pgcat_cannot_act(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('database.connections', [
            'mysql_app' => ['driver' => 'mysql', 'database' => 'app'],
        ]);
        config()->set('database.default', 'mysql_app');
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        $response = $this->getJson('/health/db');

        // A flipper that cannot act is not ill health — on MySQL there is simply
        // nothing to flip — so the endpoint still reports ok.
        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('pgcat.enabled', false)
            ->assertJsonPath('pgcat.driver', 'mysql')
            ->assertJsonPath('pgcat.driver_supported', false);

        $this->assertStringContainsString('PostgreSQL-only', (string) $response->json('pgcat.reason'));
    }

    public function test_the_replica_status_command_reports_pgcat(): void
    {
        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Pgcat:')
            ->assertExitCode(0);
    }

    /**
     * What the endpoint does with the audit: puts a finding where a dashboard can see it,
     * carrying the sentence the boot log carried — and does not call it ill health. A
     * configuration that cannot act is not a database that cannot answer, and a load
     * balancer polling this route should keep reading the second claim.
     */
    public function test_the_health_endpoint_reports_a_standing_finding_without_calling_it_ill_health(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // Nothing is listening on the fixture replicas and the controller probes them
        // with getPdo(): drop the read lists so it does not open a socket.
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        // A value the package refuses, which is the loudest finding the audit produces:
        // an error at boot, and still not a reason to fail a reachability check.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records, 'the refusal is logged at boot');
        $this->assertSame('error', $records[0]['level']);

        $response = $this->getJson('/health/db');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.available', true)
            ->assertJsonPath('audit.count', 1)
            // The machine-readable half: what a monitor reads instead of walking the list,
            // and the one field that says a refused value is standing.
            ->assertJsonPath('audit.severity', 'error')
            ->assertJsonPath('audit.counts', ['error' => 1, 'warning' => 0])
            ->assertJsonPath('audit.findings.0.key', 'swrr.reader_windows.refused')
            ->assertJsonPath('audit.findings.0.level', 'error')
            ->assertJsonPath('audit.findings.0.age', 'less than a minute');

        $warning = (string) $response->json('audit.findings.0.warning');

        $this->assertStringContainsString('reader_windows[0]', $warning);
        $this->assertStringContainsString('is not a window', $warning);

        // The same sentence, from the log and from the payload: this is what makes the
        // finding readable without grepping for it.
        $this->assertStringContainsString($warning, $records[0]['message']);

        $this->assertSame(
            $response->json('audit.findings.0.first_reported_at'),
            $response->json('audit.oldest'),
            'the oldest timestamp is the one finding that is standing',
        );
        $this->assertIsInt($response->json('audit.findings.0.age_seconds'));
        $this->assertNotEmpty($response->json('audit.findings.0.context'));
    }

    public function test_the_health_endpoint_reports_nothing_standing_on_a_sound_installation(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        $this->getJson('/health/db')
            ->assertOk()
            ->assertJsonPath('audit.available', true)
            ->assertJsonPath('audit.count', 0)
            ->assertJsonPath('audit.severity', 'none')
            ->assertJsonPath('audit.counts', ['error' => 0, 'warning' => 0])
            ->assertJsonPath('audit.oldest', null)
            ->assertJsonPath('audit.findings', []);
    }

    /**
     * The distinction the summary exists for: a setting that reads as on but cannot act is
     * a warning, not a refused value, so an alert built on `severity` treats it as a ticket
     * rather than a page. Both levels are findings, both are in the payload, and only one of
     * them is input the installation is running without.
     */
    public function test_the_health_endpoint_tells_a_setting_that_cannot_act_apart_from_a_refused_value(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        // Windows that are fine and no day at all: the resolver reads an empty day list as
        // "always readers", which is a warning — the setting cannot act, nothing was
        // refused, and the installation is still doing what it was told, just not what it
        // meant.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], []);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame('warning', $records[0]['level'] ?? null);

        $this->getJson('/health/db')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.count', 1)
            ->assertJsonPath('audit.severity', 'warning')
            ->assertJsonPath('audit.counts', ['error' => 0, 'warning' => 1])
            ->assertJsonPath('audit.findings.0.level', 'warning');
    }

    /**
     * The block with no record behind it. The endpoint is built with the audit optional so
     * an installation without the package's provider still resolves the route, and then the
     * only honest summary is "nothing is known to be standing": `severity` is `none` and
     * the counts are zero, while `available` and `error` are what say the audit is not
     * reporting at all. A consumer that wants to page on *that* has a field for it.
     */
    public function test_the_audit_block_makes_no_claim_when_there_is_no_record_to_read(): void
    {
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        $payload = (new DatabaseHealthController())->index()->getData(true);

        $this->assertSame([
            'available' => false,
            'count' => 0,
            'severity' => 'none',
            'counts' => ['error' => 0, 'warning' => 0],
            'oldest' => null,
            'findings' => [],
            'error' => null,
        ], $payload['audit']);
    }

    public function test_the_replica_status_command_prints_the_standing_findings(): void
    {
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        // Exit code 0 even with an error-level finding standing: the command reports,
        // and `db:doctor --strict` is the gate.
        //
        // The label and the count are one expectation because the console mock consumes a
        // written line for the first expectation it matches: two needles that live on the
        // same line would make the second one unmatchable, not unsatisfied.
        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Audit:      1 finding standing')
            ->expectsOutputToContain('swrr.reader_windows.refused')
            ->expectsOutputToContain('standing less than a minute — first reported')
            ->assertExitCode(0);
    }

    public function test_the_replica_status_command_reports_the_audit_without_replicas(): void
    {
        // A connection with no read list is a fact about that connection. A setting that
        // cannot act somewhere else is not made less true by it, and this is often the
        // run an operator starts with.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->artisan('db:replica-status', ['connection' => 'writer_only'])
            ->expectsOutputToContain('No weighted read replicas found')
            ->expectsOutputToContain('Audit:      1 finding standing')
            ->expectsOutputToContain('swrr.reader_windows.refused')
            ->assertExitCode(0);
    }

    /**
     * The one state the two surfaces used to disagree about: the command printed nothing at
     * all where the payload said `available: false`, so an operator reading the terminal
     * could not tell an installation with no audit from one whose record was empty. Both now
     * render `BootAudit::reported()`, and this is the state it decides between them.
     */
    public function test_both_surfaces_say_when_the_boot_audit_is_not_registered(): void
    {
        // A container that resolves no audit: what a host without the package's provider
        // leaves behind, and the reason the endpoint's dependency is optional at all.
        $this->app->bind(BootAudit::class, static fn () => null);

        // Nothing is listening on the fixture replicas and the controller probes them with
        // getPdo(): drop the read lists so it does not open a socket.
        config()->set('database.connections', [
            self::CONNECTION => ['driver' => 'pgsql', 'database' => 'app'],
        ]);

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        $this->getJson('/health/db')
            ->assertOk()
            ->assertJsonPath('audit.available', false)
            ->assertJsonPath('audit.error', null)
            ->assertJsonPath('audit.severity', 'none');

        // One expectation, because the console mock consumes a written line for the first
        // needle it matches: both halves live on the same line.
        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Audit:      not registered — no record is kept')
            ->assertExitCode(0);
    }

    /**
     * An empty record and no record at all are different facts, and the terminal has to say
     * which: the first is an installation whose every setting can act, the second is one where
     * nothing is watching. Silence was the old answer for the second, and it was
     * indistinguishable from the first.
     */
    public function test_the_replica_status_command_tells_an_empty_record_from_no_record(): void
    {
        // A record file that does not exist: a readable record with nothing on it.
        $this->useAudit(sys_get_temp_dir() . '/swrr-empty-audit-' . bin2hex(random_bytes(6)) . '.json');

        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Audit:      nothing standing')
            ->doesntExpectOutputToContain('not registered')
            ->assertExitCode(0);

        $this->app->bind(BootAudit::class, static fn () => null);

        $this->artisan('db:replica-status', ['connection' => self::CONNECTION])
            ->expectsOutputToContain('Audit:      not registered')
            ->doesntExpectOutputToContain('nothing standing')
            ->assertExitCode(0);
    }

    public function test_a_scalar_reader_day_from_config_still_means_that_day(): void
    {
        // .env values arrive as strings, and `(array) '3'` used to produce ['3']
        // — which never matched the resolver's int comparison, silently pushing
        // every read onto the writer. A bare scalar now means that single day.
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '23:59:59']]);
        config()->set('db-manager.swrr.reader_days', '3');
        $this->app->forgetInstance(TimeWindowResolver::class);

        $resolver = $this->manager()->timeWindow();

        $this->assertNotNull($resolver);
        $this->assertSame('readers', $resolver->currentModeName($this->utc('2026-09-16 12:00:00')));   // Wed, ISO day 3
        $this->assertSame('writer', $resolver->currentModeName($this->utc('2026-09-17 12:00:00')));    // Thu
    }

    public function test_unusable_reader_windows_do_not_push_every_read_to_the_writer(): void
    {
        // Flat strings are not windows. Refusing every entry leaves the resolver in its
        // documented always-readers mode rather than forcing all traffic on the writer
        // because the config was malformed — the refusal is reported, the reads are not
        // moved. See the audit test above for the report itself.
        config()->set('db-manager.swrr.reader_windows', ['10:00-14:20']);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5]);
        $this->app->forgetInstance(TimeWindowResolver::class);

        $resolver = $this->manager()->timeWindow();

        $this->assertNotNull($resolver);
        $this->assertTrue($resolver->isReaderWindow($this->utc('2026-09-19 12:00:00')));   // Sat, and still readers
    }

    /**
     * Weighted routing happens inside the factory, so a host app that replaces
     * `db.factory` gets told rather than quietly served unweighted reads.
     */
    public function test_resolving_db_fails_loudly_when_the_factory_is_not_the_weighted_one(): void
    {
        $this->app->instance('db.factory', new ConnectionFactory($this->app));
        $this->app->forgetInstance('db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db.factory');

        $this->app->make('db');
    }

    public function test_a_switched_on_flipper_that_cannot_act_is_logged_once_per_process(): void
    {
        $this->pgcatSwitchedOnForAMysqlConnection();
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);

        // Once per process, not once per call: the second invocation is exactly what
        // the guard is there to silence.
        $this->reportBootAudit();
        $this->reportBootAudit();

        $this->assertCount(1, $records, 'the mismatch is logged once per process');
        $this->assertSame('warning', $records[0]['level']);
        $this->assertStringContainsString('swrr.pgcat.enabled is true but pgcat will never act', $records[0]['message']);
        $this->assertStringContainsString('pgcat only fronts PostgreSQL', $records[0]['message']);
        $this->assertSame('swrr.pgcat.gate', $records[0]['context']['finding']);
        $this->assertSame('mysql_app', $records[0]['context']['connection']);
        $this->assertSame('mysql', $records[0]['context']['driver']);

        // And the other half of the story now has something to resolve: the warning is
        // on record, keyed by the setting rather than by the sentence.
        $this->assertSame(['swrr.pgcat.gate'], $this->recordedKeys());
    }

    public function test_an_armed_flipper_whose_paths_are_not_configured_is_not_logged(): void
    {
        // Armed, on a driver pgcat can front, but with nothing to swap. A flip reports
        // that as a failure, and the paths arrive when the mount does, so it is not the
        // silent mismatch this audit exists for.
        config()->set('database.default', self::CONNECTION);
        $this->usePgcat(['enabled' => true]);
        self::resetBootAuditGuard();

        $flipper = $this->app->make(PgcatConfigFlipper::class);

        $this->assertTrue($flipper->isEnabled());
        $this->assertNotNull($flipper->armingWarning());   // it has something to say…

        $records = [];
        $this->collectLogs($records);

        $this->reportBootAudit();                           // …the audit does not say it

        $this->assertSame([], $records);
        $this->assertSame([], $this->recordedKeys(), 'a gate that was never mismatched leaves nothing to resolve');
    }

    public function test_switching_pgcat_off_on_a_connection_pgcat_cannot_front_is_not_logged(): void
    {
        $this->useMysqlDefaultConnection();
        $this->usePgcat(['enabled' => false]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);

        $this->reportBootAudit();

        $this->assertSame([], $records);
    }

    /**
     * Collect the records the code under test writes, deliberately unmocked, so
     * the assertions run against what Laravel really emits.
     *
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function collectLogs(array &$records): void
    {
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = [
                'level' => $message->level,
                'message' => $message->message,
                'context' => $message->context,
            ];
        });
    }

    public function test_a_boot_that_finds_the_gate_open_resolves_the_recorded_mismatch(): void
    {
        $this->pgcatSwitchedOnForAMysqlConnection();
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);

        // Boot 1 — the configuration this warning exists for.
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('pgcat will never act', $records[0]['message']);
        $this->assertSame(['swrr.pgcat.gate'], $this->recordedKeys());

        // Boot 2 — the operator pointed database.default at the pgcat-fronted
        // connection. A different process, so only the record on disk can tell it that
        // there is a warning to close out.
        config()->set('database.default', self::CONNECTION);
        $this->usePgcat(['enabled' => true]);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records, 'the resolution is the second line in the same log');
        $this->assertSame('warning', $records[1]['level']);
        $this->assertStringContainsString('The pgcat mismatch no longer applies', $records[1]['message']);
        $this->assertSame('swrr.pgcat.gate', $records[1]['context']['finding']);
        $this->assertTrue($records[1]['context']['resolved']);
        $this->assertNotEmpty($records[1]['context']['first_reported_at']);

        // The resolution carries the context from the record: it names the mismatch it
        // closes, which is what makes it readable next to the warning it answers.
        $this->assertSame('mysql_app', $records[1]['context']['connection']);
        $this->assertSame('mysql', $records[1]['context']['driver']);
        $this->assertSame([], $this->recordedKeys(), 'the resolved warning is off record');

        // Boot 3 — nothing left to say. A resolution is a transition, not a state worth
        // repeating on every boot.
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertCount(2, $records);
    }

    public function test_switching_pgcat_off_closes_the_mismatch_out(): void
    {
        $this->pgcatSwitchedOnForAMysqlConnection();
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        // Off is a way out, not a repair: the setting has stopped claiming anything, so
        // the warning is closed out at the next boot — with a sentence that is true of
        // that transition too, rather than one about pgcat being active.
        $this->usePgcat(['enabled' => false]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('The pgcat mismatch no longer applies', $records[0]['message']);
        $this->assertStringNotContainsString('flipping is active', $records[0]['message']);
        $this->assertSame('mysql_app', $records[0]['context']['connection'], 'it names the mismatch it closes');
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_a_persistently_failing_setting_does_not_rewrite_its_record(): void
    {
        $this->pgcatSwitchedOnForAMysqlConnection();
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        // Mark the record: a second warning must leave what is already there alone, so
        // a resolution can quote when the finding was first seen rather than a
        // timestamp rewritten on every boot.
        $record = json_decode((string) file_get_contents($this->auditFile()), true);
        $record['sentinel'] = 'keep-me';
        file_put_contents($this->auditFile(), (string) json_encode($record));

        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records, 'the warning is still logged every boot');
        $this->assertStringContainsString('keep-me', (string) file_get_contents($this->auditFile()));
    }

    public function test_a_corrupt_record_is_treated_as_nothing_recorded(): void
    {
        // Half-written by a full disk, or hand-edited. A diagnostic that claimed a
        // resolution it cannot substantiate would be worse than silence, and this
        // record is truncated in the middle of a finding that would otherwise resolve.
        file_put_contents($this->auditFile(), '{"findings": {"swrr.pgcat.gate": {"resolution": ');

        $this->usePgcat(['enabled' => true]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([], $records);
    }

    public function test_an_unwritable_record_directory_never_stops_the_boot(): void
    {
        // A read-only deploy: the finding is still logged, but its resolution can never
        // be written down. Losing a pair of log lines must not cost the boot.
        //
        // Where nothing can be written, none of the write is attempted — not the re-read, not
        // the merge, and not the lock, which this filesystem would refuse eight times over. The
        // finding is the whole of what an operator gets, and the boot pays a stat for it.
        $blocked = $this->blockedDirectoryPath();

        $this->useAudit($blocked . '/audit.json');
        $this->pgcatSwitchedOnForAMysqlConnection();
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records, 'the finding, and not a word about a write that has nowhere to go');
        $this->assertStringContainsString('pgcat will never act', $records[0]['message']);
        $this->assertSame([], $this->recordedKeys(), 'nothing could be written, and nothing was claimed');
    }

    public function test_flat_string_reader_windows_are_refused_at_error_level(): void
    {
        // The way a person naturally writes a window, and not a window. The package will
        // not interpret it on the operator's behalf, and it will not drop it either:
        // dropping it left the resolver permissive, so reads used the replica pool at
        // every hour while the setting said the writer covered the margins.
        $this->useReaderFallback(['10:00-14:20'], [1, 2, 3, 4, 5]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level'], 'a refused value is louder than a setting that cannot act');
        $this->assertStringContainsString('reader_windows[0] is "10:00-14:20"', $records[0]['message']);
        $this->assertStringContainsString("a string such as '10:00-14:20' is not a window", $records[0]['message']);
        $this->assertSame('swrr.reader_windows.refused', $records[0]['context']['finding']);
        $this->assertSame(
            'error',
            $records[0]['context']['severity'],
            'the level rides in the payload beside the finding, so a log-based monitor pages on it without the channel envelope',
        );
        $this->assertSame(
            [['at' => '[0]', 'entry' => '"10:00-14:20"']],
            $records[0]['context']['rejected_windows'],
        );
        $this->assertSame(0, $records[0]['context']['windows_in_use']);

        // Nothing was repaired, so the whole list is out of action — which is why the
        // message says what the resolver does next.
        $this->assertStringContainsString('reads use the replica pool on every day, at every hour', $records[0]['message']);
        $this->assertSame(['swrr.reader_windows.refused'], $this->recordedKeys());

        // Fixing the entry closes the finding. The boot that sees it gone logs the
        // resolution as a warning instead: arriving at a working configuration is good
        // news whatever the warning was.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertSame('warning', $records[1]['level']);
        $this->assertSame('warning', $records[1]['context']['severity'], 'the clearing is a transition written at its own level');
        $this->assertStringContainsString('swrr.reader_windows is readable again', $records[1]['message']);
        $this->assertSame('swrr.reader_windows.refused', $records[1]['context']['finding']);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_an_empty_day_list_that_cannot_decide_anything_is_reported(): void
    {
        // Windows that are fine and no days at all: the resolver reads an empty day
        // list as "always readers" — the opposite of the fallback the setting describes,
        // and silent, because reads simply never stop using the replica pool.
        //
        // An empty list is a choice; a list of things that are not days is not, and is
        // refused rather than reported here. See the refusal test below.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], []);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level'], 'a setting that cannot act stays a warning');
        $this->assertStringContainsString('swrr.reader_days is set, but none of its entries is a usable day', $records[0]['message']);
        $this->assertSame('swrr.reader_fallback.always_readers', $records[0]['context']['finding']);
        $this->assertSame(['swrr.reader_fallback.always_readers'], $this->recordedKeys());
    }

    /**
     * A switch is the one setting a cast must not be trusted with: `(bool) 'false'` is true, so
     * the three commonest ways of writing *off* used to read as *on* — and on
     * `swrr.pgcat.enabled` that arms a file swap. Every value that is not on or off is refused
     * at `error` level instead, beside the reader refusals and for the same reason: it is input
     * the package will not interpret on the operator's behalf.
     */
    public function test_a_pgcat_switch_written_as_something_that_is_not_a_switch_is_refused_at_error_level(): void
    {
        $this->usePgcat(['enabled' => 'flase']);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level'], 'a refused value is louder than a setting that cannot act');
        $this->assertStringContainsString('swrr.pgcat.enabled is "flase"', $records[0]['message']);
        $this->assertStringContainsString('a switch is on or off', $records[0]['message']);
        $this->assertStringContainsString("'on'/'off'", $records[0]['message'], 'the accepted spellings are quoted, so the repair is not a guess');
        $this->assertStringContainsString('off', $records[0]['message'], 'the value it falls back to is named');
        $this->assertSame('swrr.pgcat.enabled.refused', $records[0]['context']['finding']);
        $this->assertSame('error', $records[0]['context']['severity'], 'a refused switch is selectable by its severity alone');
        $this->assertSame('swrr.pgcat.enabled', $records[0]['context']['setting']);
        $this->assertSame('"flase"', $records[0]['context']['configured'], 'the context quotes the value the way the report does');
        $this->assertSame(['swrr.pgcat.enabled.refused'], $this->recordedKeys());

        // Fixing it closes the finding, and the boot that sees it readable logs the
        // resolution instead: arriving at a working switch is good news whatever it was.
        $this->usePgcat(['enabled' => 'false']);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertSame('warning', $records[1]['level']);
        $this->assertSame('warning', $records[1]['context']['severity']);
        $this->assertStringContainsString('swrr.pgcat.enabled reads as on or off again', $records[1]['message']);
        $this->assertSame('swrr.pgcat.enabled.refused', $records[1]['context']['finding']);
        $this->assertSame([], $this->recordedKeys());
    }

    /**
     * The exact inversion the cast produced, and the reason it is worth a file swap's worth of
     * care: `'false'` is how *off* is written, and reading it as on arms a flip the operator
     * asked for the opposite of. Read as the spelling it is, there is nothing to refuse and
     * nothing for the gate warning to say either — the flipper is simply off.
     */
    public function test_off_written_as_the_string_false_silences_the_gate_where_the_cast_armed_it(): void
    {
        $this->pgcatSwitchedOnForAMysqlConnection();
        $this->usePgcat(['enabled' => 'false']);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([], $records, 'the switch is readable, so there is no refusal and no mismatch');
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_both_pgcat_switches_are_refused_in_one_boot(): void
    {
        // Two typos, two findings, two repairs. A boot that named one of them would leave the
        // other to be found by whoever went looking.
        $this->usePgcat(['enabled' => 2, 'use_reload' => 'maybe']);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertSame(
            ['swrr.pgcat.enabled.refused', 'swrr.pgcat.use_reload.refused'],
            $this->recordedKeys(),
            'one key per setting, so each is dated and closed out on its own',
        );
        $this->assertStringContainsString('swrr.pgcat.enabled is int', $records[0]['message']);
        $this->assertStringContainsString('swrr.pgcat.use_reload is "maybe"', $records[1]['message']);
        $this->assertStringContainsString('reload', $records[1]['message'], 'the value it falls back to is named');
    }

    public function test_the_local_fallback_switch_is_refused_at_error_level(): void
    {
        // The third switch, and the one the provider reads itself. Same rule, same level, its
        // own key — and the same reader the manager obeys, so the warning cannot describe a
        // value the routing does not hold.
        config()->set('db-manager.swrr.allow_local_fallback', 'nope');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertStringContainsString('swrr.allow_local_fallback is "nope"', $records[0]['message']);
        $this->assertStringContainsString('the in-process fallback', $records[0]['message'], 'what the switch falls back to is said in terms of what it does');
        $this->assertSame('swrr.allow_local_fallback.refused', $records[0]['context']['finding']);
        $this->assertSame(['swrr.allow_local_fallback.refused'], $this->recordedKeys());

        // And the manager got a bool, as it always did — the refusal changes the report, not
        // the type the routing receives.
        $manager = $this->app->make('db');

        $this->assertInstanceOf(WeightedDatabaseManager::class, $manager);
    }

    public function test_a_readable_local_fallback_switch_is_not_refused(): void
    {
        // The other half: the spellings an operator writes are read, so the new finding cannot
        // fire on a configuration that is doing exactly what it says.
        foreach (['on', 'off', 'yes', '0', '1', true, false] as $spelling) {
            config()->set('db-manager.swrr.allow_local_fallback', $spelling);
            self::resetBootAuditGuard();

            $records = [];
            $this->collectLogs($records);
            $this->reportBootAudit();

            $this->assertSame([], $records, 'readable: '.var_export($spelling, true));
        }
    }

    public function test_a_day_list_written_as_one_string_is_refused_at_error_level(): void
    {
        // `'1,2,3'` is the `.env` spelling of a list, and the setting does not read it:
        // dropping the entry leaves no day, and no day makes the resolver permissive —
        // a restrictive day list running as its opposite. So it is refused, at the same
        // level and with the same shape rule as a flat reader_windows string.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], '1,2,3');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level'], 'a refused value is an error, not a warning');
        $this->assertStringContainsString('swrr.reader_days is "1,2,3", not a list of days', $records[0]['message']);
        $this->assertStringContainsString("a list written as one string such as '1,2,3' is not one", $records[0]['message']);
        $this->assertStringContainsString('reads use the replica pool on every day, at every hour', $records[0]['message']);
        $this->assertSame('swrr.reader_days.refused', $records[0]['context']['finding']);
        $this->assertSame('"1,2,3"', $records[0]['context']['unreadable_days_value'], 'the context quotes the value the way the report does');
        $this->assertSame(0, $records[0]['context']['days_in_use']);
        $this->assertSame(['swrr.reader_days.refused'], $this->recordedKeys());
    }

    public function test_a_refused_day_entry_leaves_the_days_that_are_days_in_place(): void
    {
        // The refusal names the entry that is not a day, and says that the others still
        // apply: refusing the whole key would send every read to the writer, which is
        // the other way to invert the setting.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], ['1', '1,2,3']);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertStringContainsString('reader_days[1] is "1,2,3"', $records[0]['message']);
        $this->assertStringContainsString('The 1 day(s) that are well formed still apply.', $records[0]['message']);
        $this->assertSame(1, $records[0]['context']['days_in_use']);
    }

    public function test_unusable_reader_days_do_not_push_every_read_to_the_writer(): void
    {
        // The refusal is a report, not a routing decision: what the resolver does with
        // an unreadable day list is unchanged, and it is the permissive side — reads
        // keep using the pool rather than being moved onto the writer by a typo.
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '23:59:59']]);
        config()->set('db-manager.swrr.reader_days', '1,2,3');
        $this->app->forgetInstance(TimeWindowResolver::class);

        $resolver = $this->manager()->timeWindow();

        $this->assertNotNull($resolver);
        $this->assertTrue($resolver->isReaderWindow($this->utc('2026-09-19 12:00:00')));   // Sat, and still readers
    }

    public function test_reader_windows_that_can_never_be_entered_are_reported(): void
    {
        // Start inclusive, end exclusive: an overnight window is never entered, so an
        // installation whose only window looks like this never uses the replica pool
        // while believing the writer covers the margins.
        $this->useReaderFallback([['start' => '22:00:00', 'end' => '06:00:00']], [1, 2, 3, 4, 5]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('window 1', $records[0]['message']);
        $this->assertStringContainsString('no window can ever be entered', $records[0]['message']);
        $this->assertSame('swrr.reader_fallback.always_writer', $records[0]['context']['finding']);
    }

    public function test_reader_days_outside_the_iso_range_are_reported_and_then_resolved(): void
    {
        // 0 and 8 are neither in 1…7 nor empty, so the resolver is not permissive: no
        // day is ever a reader day and the pool is never used.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [0, 8]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('no day in 1…7', $records[0]['message']);
        $this->assertSame('swrr.reader_fallback.always_writer', $records[0]['context']['finding']);

        // The non-pgcat half of the pair works the same way: fix the setting, and the
        // boot that sees it logs the resolution instead of the warning.
        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertStringContainsString('The reader windows and days no longer describe a fallback', $records[1]['message']);
        $this->assertSame('swrr.reader_fallback.always_writer', $records[1]['context']['finding']);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_both_refused_reader_lists_are_reported_in_one_boot(): void
    {
        // Two values the package will not read — a window written flat and a day list
        // written as one string — are two mistakes with two repairs. Naming the first and
        // stopping would send an operator away with half the answer and leave the second
        // for whichever boot follows their fix.
        $this->useReaderFallback('10:00-14:20', '1,2,3');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(2, $records, 'both refusals are logged by the boot that sees them');
        $this->assertSame(['error', 'error'], [$records[0]['level'], $records[1]['level']]);
        $this->assertSame('swrr.reader_windows.refused', $records[0]['context']['finding']);
        $this->assertSame('swrr.reader_days.refused', $records[1]['context']['finding']);
        // The whole value written as one string is a shape problem, so the windows half names
        // the value rather than an entry — and the days half names its own value.
        $this->assertStringContainsString('swrr.reader_windows is "10:00-14:20", not a list of windows', $records[0]['message']);
        $this->assertStringContainsString('swrr.reader_days is "1,2,3", not a list of days', $records[1]['message']);

        // The day half reports what routing does next — nothing usable is left, so the
        // resolver is permissive — and does not claim no windows were configured when they
        // plainly were, and the finding above is about them.
        $this->assertStringContainsString('With nothing usable left, the fallback cannot apply', $records[1]['message']);
        $this->assertStringNotContainsString('No reader_windows are configured', $records[1]['message']);

        // Both are remembered, so each can be closed out by the boot that sees its fix.
        $this->assertSame(
            ['swrr.reader_windows.refused', 'swrr.reader_days.refused'],
            $this->recordedKeys(),
        );
    }

    public function test_the_day_half_of_a_double_refusal_points_at_the_windows_finding(): void
    {
        // Days that survive one bad entry, and no window that can be used: the fallback is
        // off because of the windows rather than the days, and the day half says what the
        // pair leaves behind — pointing at the finding that holds the other half instead of
        // claiming no windows were configured.
        $this->useReaderFallback('10:00-14:20', ['1', '1,2,3']);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertStringContainsString('No window can be used either', $records[1]['message']);
        $this->assertStringContainsString('see the reader_windows finding in this report', $records[1]['message']);
        $this->assertStringNotContainsString('No reader_windows are configured', $records[1]['message']);
        $this->assertSame(1, $records[1]['context']['days_in_use']);
    }

    public function test_both_recorded_refusals_are_closed_out_by_the_boot_that_sees_them_fixed(): void
    {
        // Two findings on record are two resolutions to log, each under its own key: the
        // record is a map of keys, so nothing about one refusal depends on the other.
        $this->useReaderFallback('10:00-14:20', '1,2,3');
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertSame(
            ['swrr.reader_windows.refused', 'swrr.reader_days.refused'],
            $this->recordedKeys(),
        );

        $this->useReaderFallback([['start' => '10:00:00', 'end' => '14:20:00']], [1, 2, 3, 4, 5]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(2, $records, 'one resolution per finding that was closed out');
        $this->assertStringContainsString('swrr.reader_windows is readable again', $records[0]['message']);
        $this->assertStringContainsString('swrr.reader_days is readable again', $records[1]['message']);
        $this->assertSame('swrr.reader_windows.refused', $records[0]['context']['finding']);
        $this->assertSame('swrr.reader_days.refused', $records[1]['context']['finding']);
        $this->assertSame([], $this->recordedKeys(), 'nothing is left standing');
    }

    public function test_no_two_findings_of_one_boot_share_a_key(): void
    {
        // Every auditable problem at once: a pgcat gate that cannot act, both reader lists
        // refused, a primary store that is not the one running, a formula with no
        // implementation, and all three replica settings the resolver cannot read. Eight
        // findings, eight keys — and the point of stacking them is that the assembly is where a
        // shared key would come from: the six methods below are spread into one list, and the
        // record holds one entry per key, so two branches agreeing on a key would cost one of
        // them its entry *and* its log line. The audit now logs every finding it is given and
        // names a collision, so this asserts the stronger thing: for this configuration there is
        // nothing to name.
        $this->pgcatSwitchedOnForAMysqlConnection();
        $this->useReaderFallback('10:00-14:20', '1,2,3');
        $this->useReplicas([
            ['host' => '10.2.0.1', 'port' => 5432, 'weight' => 'heavy'],
            ['host' => '10.2.0.2', 'port' => 5432, 'cpu_cores' => 0],
            ['host' => '10.2.0.3', 'port' => 5432, 'ram_gb' => 'lots'],
        ]);
        config()->set('db-manager.swrr.primary_store', 'Local');
        config()->set('db-manager.swrr.default_weight_formula', 'Diminishing');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $keys = array_map(static fn (array $record): string => (string) $record['context']['finding'], $records);

        $this->assertSame($keys, array_unique($keys), 'one key is one finding: two branches producing it would be a defect');
        $this->assertEqualsCanonicalizing(self::RICHEST_BOOT_KEYS, $keys);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('share the key', $record['message'], 'the collision line is for a defect, and this installation has none');
        }

        // And the record agrees with the log: one entry per key, so a boot that sees one of
        // them fixed closes exactly that one out.
        $this->assertCount(count(self::RICHEST_BOOT_KEYS), $this->recordedKeys());
    }

    /**
     * A weight the resolver does not read as written, refused on the boot that reads it.
     *
     * `ConfigValue` falls back to `0` for a value that is not a number, and `0` is how a replica
     * is disabled — so `replicaStatus()` reports the pool that was left, one member short, and
     * before this refusal nothing said the read list had described another one. The finding names
     * the replica and the value, so the repair is in the sentence rather than in a file nobody
     * opened until after the traffic was already thin.
     */
    public function test_replica_metadata_the_resolver_cannot_read_is_refused_at_boot(): void
    {
        $this->useReplicas([
            ['host' => '10.1.0.1', 'port' => 5432, 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.1.0.2', 'port' => 5432, 'weight' => 'heavy'],
        ]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level'], 'a value the package refuses, like the reader windows');
        $this->assertSame('database.read.weight.refused', $records[0]['context']['finding']);

        // The row's sentence, word for word, under the row's own opening — one classifier, so a
        // boot and a preflight cannot name the same value differently. `DbDoctorTest` asserts
        // this same string on the `replica metadata` row.
        $this->assertStringContainsString('replica metadata the resolver cannot read on [weighted]', $records[0]['message']);
        $this->assertStringContainsString('[10.1.0.2:5432] weight is "heavy", which the resolver reads as 0', $records[0]['message']);
        $this->assertStringContainsString('leaves the pool', $records[0]['message']);
        $this->assertStringContainsString('smaller pool than the read list describes', $records[0]['message']);

        $this->assertSame('weighted', $records[0]['context']['connection']);
        $this->assertSame('weight', $records[0]['context']['setting']);
        $this->assertSame(2, $records[0]['context']['replicas_configured']);
        $this->assertSame(
            [['replica' => '10.1.0.2:5432', 'configured' => 'heavy']],
            $records[0]['context']['refused'],
        );

        $this->assertSame(['database.read.weight.refused'], $this->recordedKeys());

        // And the shrink the refusal is about is real: the read list names two replicas and the
        // pool the resolver builds holds one — the state that used to be reported as the
        // installation.
        $this->assertCount(1, $this->manager()->replicaStatus(self::CONNECTION));
    }

    /**
     * Three settings, three mistakes, three repairs — and one key each, because a record
     * remembers a finding by its key: a single key for a replica's metadata would resolve all
     * three when one of them was fixed, and date the other two from the wrong boot.
     */
    public function test_each_size_setting_is_refused_under_its_own_key(): void
    {
        $this->useReplicas([
            ['host' => '10.1.0.1', 'port' => 5432, 'weight' => -5],
            ['host' => '10.1.0.2', 'port' => 5432, 'cpu_cores' => 0],
            ['host' => '10.1.0.3', 'port' => 5432, 'ram_gb' => 'lots'],
        ]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([
            'database.read.weight.refused',
            'database.read.cpu_cores.refused',
            'database.read.ram_gb.refused',
        ], array_map(static fn (array $record): string => (string) $record['context']['finding'], $records));

        $this->assertStringContainsString('weight is -5, which the resolver reads as 0', $records[0]['message']);
        $this->assertStringContainsString('cpu_cores is 0, which the resolver reads as 1 core', $records[1]['message']);
        $this->assertStringContainsString('ram_gb is "lots", which the resolver reads as 0 GB', $records[2]['message']);

        // A replica that leaves the pool and one that stays in it sized as something else are
        // different consequences, and each sentence says which happened.
        $this->assertStringContainsString('leaves the pool', $records[0]['message']);
        $this->assertStringContainsString('stays in the pool', $records[1]['message']);
        $this->assertStringContainsString('stays in the pool', $records[2]['message']);
        $this->assertStringNotContainsString('leaves the pool', $records[1]['message']);
        $this->assertStringNotContainsString('leaves the pool', $records[2]['message']);

        $this->assertSame([
            'database.read.weight.refused',
            'database.read.cpu_cores.refused',
            'database.read.ram_gb.refused',
        ], $this->recordedKeys());
    }

    public function test_a_replica_disabled_with_weight_zero_is_not_refused(): void
    {
        // `weight: 0` is the documented way to take a replica out of the pool and the package
        // means it, so a drain is not a fault. The refusal is for the values that read as 0
        // *without* having been written as 0.
        $this->useReplicas([
            ['host' => '10.1.0.1', 'port' => 5432, 'weight' => 0],
            ['host' => '10.1.0.2', 'port' => 5432, 'weight' => '0'],
        ]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([], $records);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_replica_metadata_that_reads_as_written_is_not_refused(): void
    {
        // The suite's own replicas: a core count and a memory figure, and nothing substituted for
        // either. The vacuity guard for the three keys above — a rule that refused everything it
        // looked at would pass every test that only checks what it names.
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([], $records);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_a_refused_replica_weight_is_closed_out_when_the_value_is_repaired(): void
    {
        $this->useReplicas([['host' => '10.1.0.1', 'port' => 5432, 'weight' => 'heavy']]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertSame(['database.read.weight.refused'], $this->recordedKeys());

        $records = [];
        $this->collectLogs($records);

        // A different process, so the record on disk is the only thing that can tell it there was
        // a warning to close out — which is why `database.read.*` has to be a key every boot
        // checks and reports on, clean or not.
        $this->useReplicas([['host' => '10.1.0.1', 'port' => 5432, 'weight' => 10]]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('database.read.weight.refused', $records[0]['context']['finding']);
        $this->assertStringContainsString('no replica\'s weight is refused any more', $records[0]['message']);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_removing_the_read_list_closes_a_refused_metadata_finding_out(): void
    {
        // The resolution sentence is true however the finding stops applying — the value repaired,
        // the replica dropped from the read list, or the whole list removed — which is why it says
        // every value written is a number rather than that somebody fixed one.
        $this->useReplicas([['host' => '10.1.0.1', 'port' => 5432, 'ram_gb' => 'lots']]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertSame(['database.read.ram_gb.refused'], $this->recordedKeys());

        $records = [];
        $this->collectLogs($records);
        $this->useReplicas([]);
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('database.read.ram_gb.refused', $records[0]['context']['finding']);
        $this->assertStringContainsString('no replica\'s ram_gb is refused any more', $records[0]['message']);
        $this->assertSame([], $this->recordedKeys());
    }

    /**
     * The pool, and what the pool does not hold — the pair a report needs, because the second half
     * is the one that used to have to be reconstructed by comparing two lists.
     */
    public function test_the_pool_and_the_exclusions_are_the_two_halves_of_the_read_list(): void
    {
        $this->useReplicas([
            ['host' => '10.1.0.1', 'port' => 5432, 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.1.0.2', 'port' => 5432, 'weight' => 0],
            ['host' => '10.1.0.3', 'port' => 5432, 'weight' => 'heavy'],
        ]);

        $manager = $this->manager();

        // The pool: one of the three, which is what used to be the whole report.
        $this->assertSame(['10.1.0.1'], array_column($manager->replicaStatus(self::CONNECTION), 'host'));

        // And the two that are missing from it, each with the reason the resolver reached.
        $excluded = $manager->poolExclusions(self::CONNECTION);

        $this->assertSame(['10.1.0.2:5432', '10.1.0.3:5432'], array_column($excluded, 'key'));
        $this->assertSame(
            [
                '10.1.0.2:5432' => WeightResolver::EXCLUDED_DISABLED,
                '10.1.0.3:5432' => WeightResolver::EXCLUDED_REFUSED,
            ],
            array_column($excluded, 'reason', 'key'),
        );
        $this->assertSame('weight is "heavy", which the resolver reads as 0', $excluded[1]['detail']);
        $this->assertSame(ReplicaMetadata::DISABLED, $excluded[0]['detail']);

        // Two lists, three replicas, no overlap: the partition is the point.
        $this->assertCount(3, [...array_column($manager->replicaStatus(self::CONNECTION), 'host'), ...array_column($excluded, 'key')]);
    }

    /**
     * The boundary, and it is deliberate: a replica in cool-down is out of the pool for *this read*,
     * which `replicaStatus()` reports per replica, while `poolExclusions()` answers the
     * configuration question a deploy gate can act on. Reporting a failing replica as a
     * configuration exclusion would make a preflight fail on a transient state.
     */
    public function test_the_exclusions_are_the_configuration_and_not_a_replica_in_cool_down(): void
    {
        $manager = $this->manager();
        $this->app->make(HealthMonitor::class)->markFailed('10.1.0.2:5432');

        $this->assertSame([true, false], array_column($manager->replicaStatus(self::CONNECTION), 'healthy'));
        $this->assertSame([], $manager->poolExclusions(self::CONNECTION));

        // The read path is filtered all the same, so the two answers really are about two
        // questions: the pool a read uses holds one replica while nothing is excluded from the
        // configuration.
        $this->assertSame(
            '10.1.0.1',
            $manager->readConfigFor($manager->connectionConfig(self::CONNECTION))['host'],
        );
    }

    public function test_the_two_reasons_the_pool_is_never_used_become_one_finding(): void
    {
        // No day in 1…7, and no window that can ever be entered. Both make the pool
        // unusable, and both are recorded under `always_writer` — the audit keeps one
        // entry per key, so two findings sharing it would have the second overwrite the
        // first and disappear. One sentence names both causes instead.
        $this->useReaderFallback([['start' => '22:00:00', 'end' => '06:00:00']], [0, 8]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('swrr.reader_fallback.always_writer', $records[0]['context']['finding']);
        $this->assertStringContainsString('no day in 1…7 (read as 0, 8)', $records[0]['message']);
        $this->assertStringContainsString('every configured reader window (window 1)', $records[0]['message']);
        $this->assertStringContainsString('no day is ever a reader day and no window can ever be entered', $records[0]['message']);
        // Indices, not the 1-based numbers the sentence prints for a reader.
        $this->assertSame([0], $records[0]['context']['unreachable_windows']);
    }

    public function test_unreachable_windows_are_reported_beside_days_that_can_never_match(): void
    {
        // Two facts under two keys, so both survive: the pool is never used (no reader
        // day), and window 1 can never be entered while window 2 can. Fixing the days
        // leaves the window problem for whoever did not hear about it.
        $this->useReaderFallback([
            ['start' => '22:00:00', 'end' => '06:00:00'],
            ['start' => '10:00:00', 'end' => '14:20:00'],
        ], [0, 8]);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertSame('swrr.reader_fallback.always_writer', $records[0]['context']['finding']);
        $this->assertSame('swrr.reader_fallback.unmatchable_windows', $records[1]['context']['finding']);
        $this->assertStringContainsString('no day in 1…7', $records[0]['message']);
        $this->assertStringContainsString('window 1', $records[1]['message']);
        $this->assertStringContainsString('can never be entered and never falls back to the writer', $records[1]['message']);
        $this->assertSame([0], $records[1]['context']['unreachable_windows']);
        $this->assertSame(
            ['swrr.reader_fallback.always_writer', 'swrr.reader_fallback.unmatchable_windows'],
            $this->recordedKeys(),
        );
    }

    public function test_nothing_is_claimed_about_windows_the_resolver_never_reaches(): void
    {
        // An empty day list makes the resolver permissive, and permissive mode answers
        // before a window is looked at — so a window that can never be entered is not a
        // fact about how this installation routes reads. Reporting it would describe a
        // state the resolver never reaches, which is the one thing worse than silence.
        $this->useReaderFallback([['start' => '22:00:00', 'end' => '06:00:00']], []);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertSame('swrr.reader_fallback.always_readers', $records[0]['context']['finding']);
        $this->assertStringContainsString('the resolver is permissive', $records[0]['message']);
        $this->assertStringNotContainsString('window', $records[0]['message']);
        $this->assertSame(['swrr.reader_fallback.always_readers'], $this->recordedKeys());
    }

    public function test_an_unknown_weight_formula_is_reported(): void
    {
        // The resolver weights everything that is not exactly 'diminishing' linearly,
        // so a cluster sized for the diminishing curve is weighted as if every replica
        // were linear — silently.
        config()->set('db-manager.swrr.default_weight_formula', 'Diminishing');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('weighted linearly', $records[0]['message']);
        $this->assertSame('swrr.default_weight_formula.unknown', $records[0]['context']['finding']);
    }

    public function test_a_primary_store_that_is_not_the_one_running_is_reported(): void
    {
        // 'Local' is not 'local': the provider compares exactly, so Redis runs while
        // the setting says the in-process store does.
        config()->set('db-manager.swrr.primary_store', 'Local');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('Redis runs instead', $records[0]['message']);
        $this->assertSame('swrr.primary_store.unknown', $records[0]['context']['finding']);
    }

    public function test_an_unreachable_primary_store_is_probed_reported_and_resolved(): void
    {
        $redis = $this->bindRedis(reachable: false);
        $this->useAudit(storeProbeSeconds: 60);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('could not serve a read', $records[0]['message']);
        $this->assertStringContainsString('not shared between PHP workers', $records[0]['message']);
        $this->assertSame('swrr.primary_store.unreachable', $records[0]['context']['finding']);
        $this->assertSame(['default'], $redis->asked, 'probed the connection swrr.redis_connection names');

        // A later boot, past the interval, on a store that answers: the other half.
        $this->rewindStoreProbe();
        $redisUp = $this->bindRedis(reachable: true);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertStringContainsString('The configured primary store is not unreachable any more', $records[1]['message']);
        $this->assertSame('swrr.primary_store.unreachable', $records[1]['context']['finding']);
        $this->assertSame(['default'], $redisUp->asked);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_a_store_with_no_binding_at_all_is_reported_without_resolving_it(): void
    {
        // The window this covers: a database connection is built while providers
        // register, so the store is asked for a replica before the application has
        // bound `redis`. The failed pick is not the hazard — the container answering
        // an unbound `redis` by building the phpredis extension class of that name
        // is, because the `Redis` facade caches it for the rest of the process.
        $this->useAudit(storeProbeSeconds: 60);
        $this->app->offsetUnset('redis');
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertCount(1, $records);
        $this->assertStringContainsString('could not serve a read', $records[0]['message']);
        $this->assertStringContainsString('no "redis" binding', $records[0]['message']);
        $this->assertSame('swrr.primary_store.unreachable', $records[0]['context']['finding']);

        // Nothing was built in the binding's place.
        $this->assertFalse($this->app->resolved('redis'));

        // One boot later, with the binding in place: the finding closes out.
        $this->rewindStoreProbe();
        $redis = $this->bindRedis(reachable: true);
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertCount(2, $records);
        $this->assertStringContainsString('not unreachable any more', $records[1]['message']);
        $this->assertSame(['default'], $redis->asked);
    }

    public function test_a_deliberate_in_process_store_is_not_probed_and_closes_the_finding(): void
    {
        // Boot 1 — Redis configured and unreachable: the finding is recorded.
        $redis = $this->bindRedis(reachable: false);
        $this->useAudit(storeProbeSeconds: 60);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame(['default'], $redis->asked);
        $this->assertSame('swrr.primary_store.unreachable', $records[0]['context']['finding']);

        // Boot 2 — the installation deliberately stops serving reads from Redis, with
        // a probe due. There is nothing to reach, so none is issued; the recorded
        // finding is still closed out, because an unreachable store is not a finding
        // for an installation that no longer asks it for anything.
        $this->rewindStoreProbe();
        $unasked = $this->bindRedis(reachable: true);
        config()->set('db-manager.swrr.primary_store', 'local');
        self::resetBootAuditGuard();

        $this->reportBootAudit();

        $this->assertSame([], $unasked->asked, 'an in-process primary store is not probed');
        $this->assertCount(2, $records);
        $this->assertStringContainsString('not unreachable any more', $records[1]['message']);
        $this->assertSame('swrr.primary_store.unreachable', $records[1]['context']['finding']);
        $this->assertSame([], $this->recordedKeys());
    }

    public function test_the_store_probe_respects_its_interval_and_is_not_attempted_unrecorded(): void
    {
        // Under PHP-FPM the application boots per request, so "once per process" would
        // mean "once per request". The interval is what bounds the cost: one probe per
        // installation per interval, however many boots happen in between.
        $redis = $this->bindRedis(reachable: true);
        $this->useAudit(storeProbeSeconds: 3600);

        self::resetBootAuditGuard();
        $this->reportBootAudit();
        $this->assertSame(['default'], $redis->asked);

        self::resetBootAuditGuard();
        $this->reportBootAudit();
        self::resetBootAuditGuard();
        $this->reportBootAudit();

        $this->assertSame(['default'], $redis->asked, 'later boots are inside the interval');

        // And a probe whose timestamp has nowhere to go is skipped rather than left
        // unbounded: without the throttle a dead store would cost its connect timeout
        // on every single request.
        $unrecordable = $this->bindRedis(reachable: true);
        $this->useAudit($this->blockedDirectoryPath() . '/audit.json', storeProbeSeconds: 60);
        self::resetBootAuditGuard();

        $records = [];
        $this->collectLogs($records);
        $this->reportBootAudit();

        $this->assertSame([], $unrecordable->asked, 'no probe without somewhere to record it');
        $this->assertSame([], $records);
    }

    /**
     * A default connection pgcat cannot front, so the flipper is inert whatever the
     * switch says.
     */
    private function useMysqlDefaultConnection(): void
    {
        config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
        config()->set('database.default', 'mysql_app');
    }

    /**
     * `swrr.pgcat.enabled = true` on a MySQL connection: the mismatch that would
     * otherwise never be mentioned, because nothing flips and nothing fails.
     */
    private function pgcatSwitchedOnForAMysqlConnection(): void
    {
        $this->useMysqlDefaultConnection();

        $this->usePgcat(['enabled' => true]);
    }

    /**
     * Configure the pgcat block on top of the suite's baseline — the state file and
     * lock file the package's own TestCase provided — and drop the singleton the boot
     * hook already resolved against the old configuration.
     *
     * @param array<string, mixed> $pgcat
     */
    private function usePgcat(array $pgcat): void
    {
        $inherited = config('db-manager.swrr.pgcat');

        config()->set('db-manager.swrr.pgcat', [
            ...(is_array($inherited) ? $inherited : []),
            ...$pgcat,
        ]);

        $this->app->forgetInstance(PgcatConfigFlipper::class);
    }

    /**
     * Replace the read list of the connection the package follows — `database.default`, unless
     * the installation names one in `swrr.connection`. The boot audit reads the same list from
     * the same place, so a test that configures a replica's metadata is configuring what the
     * audit and the resolver both see.
     *
     * @param list<array<string, mixed>> $replicas
     */
    private function useReplicas(array $replicas): void
    {
        config()->set('database.connections.' . config('database.default') . '.read', $replicas);
    }

    /**
     * Configure the windowed reader fallback and drop the resolver singleton, which is
     * what the reader checks read the windows and days through.
     */
    private function useReaderFallback(mixed $windows, mixed $days): void
    {
        config()->set('db-manager.swrr.reader_windows', $windows);
        config()->set('db-manager.swrr.reader_days', $days);

        $this->app->forgetInstance(TimeWindowResolver::class);
    }

    /**
     * Configure the audit itself: the record file, and how often the primary store may
     * be probed. It is a singleton, so the resolved instance is dropped.
     */
    private function useAudit(?string $file = null, int $storeProbeSeconds = 0): void
    {
        config()->set('db-manager.swrr.audit', [
            'file' => $file ?? $this->auditFile(),
            'store_probe_seconds' => $storeProbeSeconds,
        ]);

        $this->app->forgetInstance(BootAudit::class);
    }

    /**
     * The audit record the running boot reads and writes, taken from the configuration
     * the provider was given rather than rebuilt here.
     */
    private function auditFile(): string
    {
        return (string) config('db-manager.swrr.audit.file');
    }

    /**
     * The finding keys on record: exactly what a later boot would log a resolution for.
     *
     * @return list<string>
     */
    private function recordedKeys(): array
    {
        $file = $this->auditFile();

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) && is_array($decoded['findings'] ?? null)
            ? array_values(array_map('strval', array_keys($decoded['findings'])))
            : [];
    }

    /**
     * Point the flipper at temp files, put the resolver in readers mode, and give it a
     * supervisorctl that resolves and answers — so a flip is something that would actually
     * happen, and the check a flip makes first can be answered without spawning anything.
     *
     * Every singleton involved is built once from configuration, so all of them are
     * forgotten; the inspector is bound before the flipper, because the flipper resolves it.
     *
     * @return array{config_path: string, readers_path: string, no_readers_path: string, state_file: string, lock_file: string, supervisor: string}
     */
    private function armTheFlip(): array
    {
        $dir = $this->tempDir();
        $bin = $dir . '/bin';
        mkdir($bin, 0o777, true);

        // A file that resolves as supervisorctl on this platform. It is never executed:
        // the inspector is bound to a fake runner below, because a flip only ever asks it
        // the read-only question.
        $supervisor = $bin . '/supervisorctl' . (PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
        file_put_contents($supervisor, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\n" : "#!/bin/sh\n");
        chmod($supervisor, 0o777);

        $paths = [
            'config_path' => $dir . '/pgcat.toml',
            'readers_path' => $dir . '/pgcat-readers.toml',
            'no_readers_path' => $dir . '/pgcat-no-readers.toml',
            'state_file' => $dir . '/pgcat-flip-state.json',
            'lock_file' => $dir . '/pgcat-flip.lock',
            'restart_command' => $supervisor.' restart "pgcat:*"',
            'reload_command' => $supervisor.' signal HUP "pgcat:*"',
            'supervisor' => $supervisor,
            // Declared rather than left to the defaults, because these tests are about the flip
            // and not about what an omitted switch resolves to: armed on purpose, and
            // `use_reload` off so the restart command is the one a flip runs — the command the
            // rehearsal prints. The shipped config documents the other value for both.
            'enabled' => true,
            'use_reload' => false,
        ];

        file_put_contents($paths['config_path'], "pool = 'unknown'\n");
        file_put_contents($paths['readers_path'], "pool = 'readers'\n");
        file_put_contents($paths['no_readers_path'], "pool = 'writer-only'\n");

        config()->set('db-manager.swrr.pgcat', $paths);
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '23:59:59']]);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5, 6, 7]);

        $this->app->instance(SupervisorStep::class, new SupervisorStep((new FakeSupervisor())->runner()));
        $this->app->forgetInstance(TimeWindowResolver::class);
        $this->app->forgetInstance(PgcatConfigFlipper::class);

        return $paths;
    }

    /**
     * A path whose directory is occupied by a file, so nothing can be written under it.
     */
    private function blockedDirectoryPath(): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/blocked', '');

        return $dir . '/blocked';
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/swrr-provider-test-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Make the recorded store probe look old, so the next boot probes again without
     * waiting out the interval — the only way to exercise both sides of the throttle.
     */
    private function rewindStoreProbe(): void
    {
        $record = json_decode((string) file_get_contents($this->auditFile()), true);
        $record['store_probed_at'] = time() - 3600;

        file_put_contents($this->auditFile(), (string) json_encode($record));
    }

    /**
     * Bind a Redis stand-in, so the audit's probe talks to it rather than to a real
     * server. It records which connection names were asked for, so a test can prove
     * which connection was probed. The probe goes through the container, so the
     * binding is all it takes — there is no facade root to clear.
     */
    private function bindRedis(bool $reachable): FakeRedis
    {
        $redis = new FakeRedis($reachable);

        $this->app->instance('redis', $redis);

        return $redis;
    }

    /**
     * The audit is held by a static, so one process reports once however many
     * applications it boots. Each boot in these tests is that process's first.
     */
    private static function resetBootAuditGuard(): void
    {
        (new ReflectionProperty(WeightedDatabaseServiceProvider::class, 'bootAudited'))
            ->setValue(null, false);
    }

    /**
     * Invoke the audit boot() runs, against the current configuration.
     */
    private function reportBootAudit(): void
    {
        $providers = array_values($this->app->getProviders(WeightedDatabaseServiceProvider::class));

        if ($providers === []) {
            $this->fail('WeightedDatabaseServiceProvider is not registered.');
        }

        (new ReflectionMethod(WeightedDatabaseServiceProvider::class, 'reportBootAudit'))
            ->invoke($providers[0]);
    }

    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function manager(): WeightedDatabaseManager
    {
        $this->app->forgetInstance('db');

        /** @var WeightedDatabaseManager $manager */
        $manager = $this->app->make('db');

        return $manager;
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

    private function prefixOf(AtomicStateStore $store): string
    {
        return (string) (new ReflectionProperty($store, 'keyPrefix'))->getValue($store);
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Http;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * `/health/db`'s decisive field: one real query, on the connection the package follows.
 *
 * The endpoint's other fields are readings — of configuration, of the weighted resolver's own
 * bookkeeping, of the state store — and every one of them can be well while the database is
 * not. That is not hypothetical: the endpoint was observed answering `200 "status":"ok"` with
 * every `replicas` array empty, because the connection it follows declares no `read` list for
 * the replica probe to open, while application queries were failing with `SQLSTATE[08006]`.
 * A deploy gate promoting a revision on a Redis store that answers is promoting on the wrong
 * fact, so the status now rests on a query that ran.
 *
 * These tests pin the connection at SQLite, which is what makes them hermetic without making
 * them vacuous: SQLite is a real driver, so `select 1` really runs and really fails when the
 * file cannot be opened — no socket, no server, no skip. `TestCase` switches the query off for
 * the rest of the suite (its fixture replicas do not exist), so each test here sets the switch
 * to the value it is about, and the first one leaves the key out entirely to exercise the
 * shipped default rather than a value the test chose.
 *
 * The other half of the subject is *which* connection the report is about. The endpoint used to
 * summarise every key of `database.connections`, which is the host application's inventory
 * rather than the package's subject: an installation with eight profiles was probed eight times,
 * and a declared `read` list on any connection the package does not follow — a second PostgreSQL
 * path, a legacy mirror, a per-tenant profile — could turn a healthy followed connection into a
 * `degraded` payload. It now reports the connection `swrr.connection` names (`database.default`
 * when nothing is named), the same resolution the pgcat gate, the flipper and `db:doctor` make,
 * and the two tests at the end of this file are the ones that say so.
 */
class DatabaseHealthControllerTest extends TestCase
{
    /** The connection the probe follows: whatever `database.default` names in this file. */
    private const PINNED = 'pinned_sqlite';

    /** @var list<string> the temp directories the window tests built, cleaned up in tearDown() */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @chmod($file, 0o666);
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The host application's other connection profiles, as the installation this was reported
     * on had them: eight in total, one of them the package's, and the rest with a declared
     * `read` list nothing answers on.
     *
     * They are `sqlite` pointed at a directory that is never created, so a controller that went
     * looking would fail immediately rather than wait for a socket — which is what makes this a
     * test about *scope* rather than about a timeout.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function neighbours(): array
    {
        $missing = sys_get_temp_dir().'/swrr-neighbour-'.bin2hex(random_bytes(4)).'/dead.sqlite';
        $neighbours = [];

        foreach (['app', 'legacy_mirror', 'reporting', 'tenant_auth', 'tenant_billing', 'tenant_routing', 'warehouse'] as $name) {
            $neighbours[$name] = [
                'driver' => 'sqlite',
                'database' => $missing,
                'read' => [['host' => '10.20.0.1', 'port' => 5432]],
            ];
        }

        return $neighbours;
    }

    public function test_the_health_endpoint_runs_one_real_query_on_the_pinned_connection(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // No `health` key at all: the query is the shipped default, not a value this test wrote.
        config()->set('db-manager.swrr.health', []);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.connection', self::PINNED)
            ->assertJsonPath('pinned.driver', 'sqlite')
            ->assertJsonPath('pinned.source', 'database.default')
            ->assertJsonPath('pinned.query', 'select 1')
            ->assertJsonPath('pinned.checked', true)
            ->assertJsonPath('pinned.refused', null)
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('pinned.error', null);

        // The query was timed, so the field is a measurement rather than a placeholder. Numeric
        // rather than float, because JSON has one number type: a latency that rounded to a whole
        // millisecond arrives as `58`, and this assertion used to be a test that failed on a slow
        // machine rather than a fact about the payload.
        $this->assertIsNumeric($response->json('pinned.latency_ms'));
    }

    /**
     * The address the request was answered on. It is read from the host, so the reading is injected
     * rather than taken from the machine running the suite: an assertion on this host's `eth1`
     * would be an assertion about CI.
     */
    public function test_the_pinned_block_reports_the_hosts_eth1_address(): void
    {
        $this->app->instance(
            DatabaseHealthController::class,
            new DatabaseHealthController(hostIp: static fn (string $interface): ?string => '10.0.3.17'),
        );

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.host_ip', '10.0.3.17')
            ->assertJsonPath('pinned.ok', true);
    }

    /**
     * A host with no readable address — no `eth1`, a disabled function, a link-local address —
     * still answers the endpoint, with the key present and `null`. The field is a reading, not a
     * condition: a missing address must not make a healthy database look degraded.
     */
    public function test_the_pinned_block_reports_a_null_host_ip_when_eth1_cannot_be_read(): void
    {
        $this->app->instance(
            DatabaseHealthController::class,
            new DatabaseHealthController(hostIp: static fn (string $interface): ?string => null),
        );

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.checked', true)
            ->assertJsonPath('pinned.ok', true);

        $this->assertArrayHasKey('host_ip', $response->json('pinned'));
        $this->assertNull($response->json('pinned.host_ip'));
    }

    /**
     * The host's address is metadata about where the request was answered, not part of the query,
     * so switching the query off cannot switch it off too. A payload with `checked: false` and no
     * `host_ip` would make a consumer's key list depend on a setting it has nothing to do with.
     */
    public function test_the_host_ip_survives_a_switched_off_pinned_query(): void
    {
        $this->app->instance(
            DatabaseHealthController::class,
            new DatabaseHealthController(hostIp: static fn (string $interface): ?string => '10.0.3.17'),
        );

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', false);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pinned.checked', false)
            ->assertJsonPath('pinned.ok', null)
            ->assertJsonPath('pinned.host_ip', '10.0.3.17');
    }

    /**
     * `swrr.health.host_interface` is the name the reader is given. The closure returns the name
     * it was handed rather than an address, which is how the wiring is asserted without a socket:
     * a payload that reported the configured name has proved the setting reached the reader.
     */
    public function test_the_configured_interface_name_is_the_one_read(): void
    {
        $this->app->instance(
            DatabaseHealthController::class,
            new DatabaseHealthController(hostIp: static fn (string $interface): ?string => $interface),
        );

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health', ['pinned_query' => true, 'host_interface' => 'eth7']);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pinned.host_ip', 'eth7');
    }

    /**
     * The setting is optional and its default is the name the field documented before the key
     * existed. An absent key, a whitespace-only one and one written as something that is not a
     * string all resolve to `eth1`: a half-written setting must not silence a reading, and a
     * published copy that predates the key has to keep behaving as it did.
     */
    public function test_an_unset_or_unusable_interface_name_falls_back_to_eth1(): void
    {
        $this->app->instance(
            DatabaseHealthController::class,
            new DatabaseHealthController(hostIp: static fn (string $interface): ?string => $interface),
        );

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        $this->pinToSQLite(':memory:');

        $cases = [
            'absent' => null,
            'empty' => '',
            'whitespace' => "  \n",
            'not a string' => ['eth2'],
        ];

        foreach ($cases as $case => $value) {
            config()->set('db-manager.swrr.health', array_filter(
                ['pinned_query' => true, 'host_interface' => $value],
                static fn (mixed $item): bool => $item !== null,
            ));

            $response = $this->getJson('/health/db')->assertOk();

            $response->assertJsonPath('pinned.host_ip', 'eth1');
            $this->assertSame('eth1', $response->json('pinned.host_ip'), "the {$case} setting answers the documented default");
        }
    }

    /**
     * The regression this exists for: a state store that answers cannot make the endpoint say
     * a database answered. No `read` list is declared, so nothing else in the payload is a
     * probe result, and the store is healthy — the endpoint is still `degraded`.
     */
    public function test_a_healthy_state_store_cannot_make_the_endpoint_ok_when_the_query_fails(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // The fixture switches the query off so the rest of the suite never opens a socket;
        // this test is about the query, so it turns it back on.
        config()->set('db-manager.swrr.health.pinned_query', true);

        // A path under a directory that is never created: the connector fails before a file is
        // opened, so the failure is immediate and leaves nothing to clean up.
        $this->pinToSQLite(sys_get_temp_dir() . '/swrr-pinned-missing-' . bin2hex(random_bytes(4)) . '/dead.sqlite');

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pinned.connection', self::PINNED)
            ->assertJsonPath('pinned.driver', 'sqlite')
            ->assertJsonPath('pinned.checked', true)
            ->assertJsonPath('pinned.ok', false);

        // The verdict came from the query and from nothing else: no declared replica was
        // probed (so `errors` is empty) and the store answered.
        $response->assertJsonPath('errors', []);
        $this->assertTrue($response->json('replicas.' . self::PINNED . '.store_healthy'));

        // The sentence is the driver's, not a rendering of it: it names the connection that was
        // asked and the statement that was run, which is what makes a gate log actionable. On
        // PostgreSQL the same field carries the SQLSTATE the deploy gate greps for; SQLite's
        // connector refuses a missing file before PDO is reached, so what is asserted here is
        // the shape rather than the one spelling of it.
        $error = (string) $response->json('pinned.error');

        $this->assertNotSame('', $error);
        $this->assertStringContainsString('pinned_sqlite', $error);
        $this->assertStringContainsString('select 1', $error);
        $this->assertIsNumeric($response->json('pinned.latency_ms'), 'a failure is timed too');
    }

    /**
     * `checked: false` is what makes the switch safe to have: a reader can tell "no query was
     * run" from "the query passed", so an installation that turns the probe off cannot be
     * mistaken for a database that answered.
     */
    public function test_the_pinned_query_can_be_switched_off_and_the_payload_says_so(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', false);
        $this->pinToSQLite(sys_get_temp_dir() . '/swrr-pinned-missing-' . bin2hex(random_bytes(4)) . '/dead.sqlite');

        // The same connection that fails above is `ok` here purely because it was never asked —
        // which is why the flag, not the status, is what a signal must read.
        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.checked', false)
            ->assertJsonPath('pinned.ok', null)
            ->assertJsonPath('pinned.latency_ms', null)
            ->assertJsonPath('pinned.error', null)
            ->assertJsonPath('pinned.refused', null);
    }

    /**
     * A switch written as something that is neither on nor off resolves to the value the config
     * documents — on — and is reported. The refusal must not be the thing that silences the
     * check: `'maybe'` is a typo, and a typo that turns a probe off silently is worse than one
     * that leaves it on loudly.
     */
    public function test_a_switch_that_is_not_a_switch_leaves_the_query_on_and_is_reported(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', 'maybe');
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pinned.checked', true)
            ->assertJsonPath('pinned.ok', true);

        $this->assertNotNull($response->json('pinned.refused'));
        $this->assertStringContainsString('maybe', (string) $response->json('pinned.refused'));
    }

    /**
     * No connection is named at all: `db-manager.swrr.connection` is unset and `database.default`
     * is empty. That is a failure rather than an absent check — a name the package cannot resolve
     * is a database nothing can query — so the endpoint says so instead of reporting `ok`.
     */
    public function test_a_connection_that_cannot_be_named_is_a_failure_not_an_absent_check(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        config()->set('database.connections', []);
        config()->set('database.default', '');
        config()->set('db-manager.swrr.connection', null);

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pinned.connection', '')
            ->assertJsonPath('pinned.checked', true)
            ->assertJsonPath('pinned.ok', false);

        $this->assertStringContainsString('no connection is pinned', (string) $response->json('pinned.error'));
    }

    /**
     * The payload's half of the same state, and the reason `error` exists as a field at all.
     *
     * The audit block carries `available` and `error` as a pair so a reader can tell "this
     * installation has nothing standing" from "this reader could not find out" — and until an
     * unreadable record stopped being rounded to an empty one, only the first of those could
     * ever appear: a half-written file arrived as `available: true, count: 0`, an all-clear
     * asserted about bytes nobody had read. A rule built on `available` was therefore unfireable
     * in the case it was written for.
     *
     * The status code is the other half of the claim: findings are a configuration that cannot do
     * what it says, which is not "this database is unreachable", so an unreadable record moves
     * the block and not the endpoint — a load balancer that took a corrupt audit file out of
     * rotation would be it acting on the wrong fact, the same way it would on a Redis store.
     */
    public function test_an_unreadable_audit_record_is_named_in_the_payload_and_leaves_the_status_alone(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // A database that answers, so that the audit block is the only thing under test here.
        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        // The record the application registered, replaced by what a full disk or a hand edit
        // leaves behind: the finding is in there, the JSON around it is not.
        $file = (string) config('db-manager.swrr.audit.file');
        file_put_contents($file, '{"findings": {"swrr.pgcat.gate": {"warning": ');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.available', false)
            ->assertJsonPath('audit.count', 0)
            ->assertJsonPath('audit.severity', 'none')
            ->assertJsonPath('audit.oldest', null)
            ->assertJsonPath('audit.findings', []);

        $error = (string) $response->json('audit.error');

        $this->assertStringContainsString($file, $error, 'the file an operator has to open');
        $this->assertStringContainsString('is not JSON', $error);
    }

    /**
     * The payload is about the connection the package follows, and the host app's other seven
     * profiles are not in it — nor probed, nor allowed to move the status.
     *
     * This is the regression the endpoint was reported for: a site whose `config/database.php`
     * holds eight connection profiles got eight summaries, seven of which belong to no part of
     * this package, and each of those was probed through `getPdo()` — so a replica behind any of
     * them that did not answer made the whole payload `degraded`, on a machine whose PostgreSQL
     * path was answering fine.
     */
    public function test_the_payload_carries_the_followed_connection_and_none_of_the_host_apps_others(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        // The eight-profile installation, the followed connection among them.
        config()->set('database.connections', array_merge(
            self::neighbours(),
            [self::PINNED => ['driver' => 'sqlite', 'database' => ':memory:']],
        ));

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.connection', self::PINNED)
            ->assertJsonPath('errors', []);

        $this->assertSame(
            [self::PINNED],
            array_keys($response->json('replicas')),
            'the payload reports the connection the package follows and no other profile in the host application',
        );
    }

    /**
     * `swrr.connection` decides the subject, not `database.default` — the endpoint reports the
     * connection the package follows even when the application's default is somewhere else.
     *
     * The pair matters on the installation this was written for: its PostgreSQL path is named by
     * `app.default_api_connection` while `database.default` stays on SQLite, so an endpoint that
     * reported the default would summarise and query a database no request ever touches. The
     * `source` field is the payload's half of that answer — it names the config key the choice
     * came from, so an operator can tell "this installation named it" from "the package fell
     * back".
     */
    public function test_the_followed_connection_is_the_one_swrr_connection_names_rather_than_the_default(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        config()->set('database.connections', [
            'the_application_default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'the_package_target' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        config()->set('database.default', 'the_application_default');
        config()->set('db-manager.swrr.connection', 'the_package_target');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pinned.connection', 'the_package_target')
            ->assertJsonPath('pinned.source', 'db-manager.swrr.connection')
            ->assertJsonPath('errors', []);

        $this->assertSame(
            ['the_package_target'],
            array_keys($response->json('replicas')),
            'the default connection is reported about only when it is the one the package follows',
        );
    }

    /**
     * The other half of the endpoint's verdict, and the one a database that answers cannot
     * overrule: a container whose boot window closed without the flip ever converging is not
     * serving reads it can be trusted with, whatever `select 1` says this second.
     *
     * The query is deliberately the healthy case here. If the endpoint were to fail only when the
     * query failed, the state this exists for — the 2026-09-28 pooler outage, where the app's
     * reads hit a pooler that was respawning while the box itself was fine — would still be
     * reported as `ok`.
     */
    public function test_a_container_whose_boot_window_closed_unconverged_is_degraded_and_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600);

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            // The database answered, and every reading in the payload is well: the verdict comes
            // from the window and from nothing else.
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('flip.applicable', true)
            ->assertJsonPath('flip.failed', true)
            ->assertJsonPath('pgcat.window.source', FlipWindow::SOURCE_STAMPED)
            ->assertJsonPath('pgcat.window.closed', true)
            ->assertJsonPath('pgcat.window.window_seconds', 480)
            ->assertJsonPath('pgcat.window.converged', false)
            ->assertJsonPath('pgcat.window.runs', 0);

        // The three reasons are told apart, and this one names the scheduler — the repair differs.
        $this->assertStringContainsString('never ran', (string) $response->json('flip.reason'));
        $this->assertStringContainsString('is not being scheduled', (string) $response->json('flip.reason'));

        // And the failure is in the log, with the window beside it: an endpoint that only moved
        // its status code would leave an operator with a number and no sentence.
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'health/db degraded')
                    && str_contains($message, "never converged inside the container's boot window")
                    && ($context['flip_failed'] ?? null) === true
                    && ($context['pgcat_window']['closed'] ?? null) === true
                    && ($context['pgcat_window']['window_seconds'] ?? null) === 480;
            });
    }

    /**
     * The same container a minute after boot: nothing has failed yet. A window that is still open
     * is a container starting up, and an endpoint that failed it would fail every healthy deploy
     * for as long as the pooler takes to come up — which is the reason the window is measured from
     * boot rather than from "now".
     */
    public function test_a_window_that_is_still_open_is_not_a_failure_and_is_not_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 60);

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('flip.applicable', true)
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('flip.reason', null)
            ->assertJsonPath('pgcat.window.closed', false)
            ->assertJsonPath('pgcat.window.converged', false);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A container nothing recorded a boot time for: pgcat applies, the deck is empty, and the
     * endpoint stays quiet rather than inventing a deadline. This is the shape a local run and an
     * image that predates the stamp arrive in, so it is the case that must never fail — `source`
     * is what lets a reader tell "not judged" from "judged and passed".
     */
    public function test_a_container_with_no_boot_stamp_is_not_judged_by_the_endpoint(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: null);

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('flip.applicable', true)
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('pgcat.window.source', FlipWindow::SOURCE_NO_STAMP)
            ->assertJsonPath('pgcat.window.closed', false)
            ->assertJsonPath('pgcat.window.deadline', null);
    }

    /**
     * The check the boot window cannot make, and the state the 2026-10-02 container was in: the
     * flip converged inside its window (one run, `window.failed: false`), then stopped tracking
     * the windows, so when one opened pgcat was still on the writer-only config and every read
     * reached the writer while this endpoint answered `ok`.
     *
     * The boot verdict is deliberately healthy here — the window closed and the flip converged
     * inside it — so the failure under test can only come from the file the flipper read.
     */
    public function test_a_pooler_left_writer_only_inside_a_reader_window_is_degraded_and_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        // Booted an hour ago, so the boot window has closed and the flip converged inside it —
        // the container came up fine and then stopped tracking the day, which is the state this
        // check exists to separate from "still starting up".
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'writer');

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            // The database answered and the container converged: this verdict comes from the
            // pooler's own file and from nothing else.
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('pgcat.window.converged', true)
            ->assertJsonPath('reader_window.applicable', true)
            ->assertJsonPath('reader_window.failed', true)
            ->assertJsonPath('reader_window.expected', 'readers')
            ->assertJsonPath('reader_window.applied', 'writer');

        $this->assertStringContainsString('reader window is open', (string) $response->json('reader_window.reason'));

        // And it is in the log with the same facts, so an alarm can key on the endpoint and an
        // operator arrives at the sentence rather than at a status code.
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'health/db degraded')
                    && str_contains($message, 'pgcat is out of step with the reader windows')
                    && ($context['reader_window']['failed'] ?? null) === true
                    && ($context['reader_window']['applied'] ?? null) === 'writer';
            });
    }

    /**
     * The other half of the check: a pooler that is on the configuration the window asks for is
     * not a failure, and the endpoint's status must not move for it. Without this the check could
     * be satisfied by failing everything, which is not a check.
     */
    public function test_a_pooler_in_step_with_the_reader_window_is_not_a_failure(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers');

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('reader_window.applicable', true)
            ->assertJsonPath('reader_window.failed', false)
            ->assertJsonPath('reader_window.applied', 'readers')
            ->assertJsonPath('reader_window.reason', null)
            // The third silence, and the other one this fixture has to be healthy about: the
            // entry is scheduled and its beat is a minute old, so nothing here is stale.
            ->assertJsonPath('schedule.applicable', true)
            ->assertJsonPath('schedule.failed', false)
            ->assertJsonPath('schedule.not_judged', null)
            ->assertJsonPath('pgcat.schedule.stale', false);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The failure this endpoint could not previously see: a container that converged, stayed in
     * step, and whose per-minute entry has since stopped running.
     *
     * Every other field of this payload is `ok` — the query answered, the window closed with a
     * convergence inside it, pgcat's file is the variant the day asks for — and the state file
     * still reads like a healthy one, because what it records is what a run *did* and the last run
     * did the right thing. Only the age of the beat is about whether runs are still happening, and
     * it is the field the deployment this was written for kept answering `ok` through, for 85
     * minutes, while the pooler it maintains was nobody's job.
     */
    public function test_a_schedule_that_stopped_producing_runs_degrades_the_payload_and_is_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            // Past five missed minutes, which is the shipped tolerance: `schedule.interval_minutes`
            // is one and `stale_after_missed_runs` defaults to five.
            beatAt: gmdate(DATE_ATOM, time() - 900),
        );

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('reader_window.failed', false)
            ->assertJsonPath('schedule.applicable', true)
            ->assertJsonPath('schedule.failed', true)
            ->assertJsonPath('schedule.not_judged', null)
            ->assertJsonPath('pgcat.schedule.stale', true);

        $this->assertGreaterThanOrEqual(900, $response->json('schedule.age_seconds'));
        $this->assertSame(
            300,
            $response->json('schedule.stale_after_seconds'),
            'five missed one-minute runs, as the setting derives it',
        );
        $this->assertStringContainsString('db:pgcat-flip', (string) $response->json('schedule.reason'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'health/db degraded')
                    && str_contains($message, 'the schedule has stopped producing runs of the per-minute flip')
                    && ($context['schedule']['failed'] ?? null) === true
                    && ($context['schedule']['age_seconds'] ?? null) >= 900;
            });
    }

    /**
     * The same fault one interval earlier, and the reason the verdict is a failure rather than a
     * silence: a beat that was never written is "no run has happened since this container came
     * up", not "nothing to report". A payload that only ages a beat it has would answer `ok` for
     * a container whose cron was never wired up at all — the one state where the repair is not
     * waiting on the next run.
     */
    public function test_a_container_that_has_never_stamped_a_beat_is_degraded_too(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers', stampBeat: false);

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('schedule.applicable', true)
            ->assertJsonPath('schedule.failed', true)
            ->assertJsonPath('schedule.last_beat_at', null)
            ->assertJsonPath('schedule.age_seconds', null)
            ->assertJsonPath('schedule.not_judged', null)
            ->assertJsonPath('flip.failed', false);

        $this->assertStringContainsString('no run of db:pgcat-flip has been recorded', (string) $response->json('schedule.reason'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'the schedule has stopped producing runs of the per-minute flip')
                    // `array_key_exists` rather than a null-coalesce on purpose: the field the
                    // context has to carry here is the *absence* of a stamp, and `?? null` would
                    // answer the same for a key that was dropped.
                    && array_key_exists('last_beat_at', $context['schedule'] ?? [])
                    && $context['schedule']['last_beat_at'] === null;
            });
    }

    /**
     * A container that is still inside its boot window is not a container whose schedule has
     * stopped: the entry has had no time to beat yet, and the same line the boot verdict draws —
     * "starting" is not "broken" — has to be drawn here or every deployment alarms on the way up.
     */
    public function test_a_schedule_inside_the_boot_window_is_not_judged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 60, appliedMode: 'readers', stampBeat: false);

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('schedule.applicable', true)
            ->assertJsonPath('schedule.failed', false)
            ->assertJsonPath('pgcat.schedule.stale', null);

        $this->assertStringContainsString('still inside its boot window', (string) $response->json('schedule.not_judged'));
    }

    /**
     * The readings beside the verdict are the command's readings, not a second rendering of them.
     *
     * A monitor and a healthcheck answering the same question must not explain one silence with
     * different evidence. The endpoint publishes `causes` in the shape the command does, both read
     * through `ScheduleCauses`, and this asserts the two arrays are identical rather than that each
     * is plausible on its own.
     */
    public function test_the_schedule_readings_are_the_ones_the_watchdog_publishes(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers');

        // The entry's log directory is the test's own: resolving the schedule registers the event,
        // and a registration must not write under the testbench skeleton's storage.
        $dir = sys_get_temp_dir().'/swrr-health-causes-'.bin2hex(random_bytes(4));
        config()->set('db-manager.swrr.pgcat.schedule.log', $dir.'/pgcat_flip.log');
        $this->tempDirs[] = $dir;

        $response = $this->getJson('/health/db')->assertOk();
        $causes = $response->json('schedule.causes');

        $this->assertIsArray($causes);
        $this->assertSame(
            [ScheduleCauses::KEY_MUTEX_STORE, ScheduleCauses::KEY_ENTRY, ScheduleCauses::KEY_BOOT_STAMP],
            array_column($causes, 'key'),
            'the endpoint asks the same three questions, in the order the command asks them',
        );
        $this->assertStringContainsString('was not asked', $causes[0]['reading'], 'a polled endpoint does not wait on the store');
        $this->assertStringContainsString('`db:doctor` takes this reading', $causes[0]['reading'], 'and the reading names the surface that does ask');
        $this->assertStringContainsString('1 entry carries `pgcat_flip`', $causes[1]['reading']);
        $this->assertStringContainsString('is readable', $causes[2]['reading']);

        $this->assertSame(0, Artisan::call('db:scheduler-watchdog', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            $report['causes'],
            $causes,
            'the endpoint and the command describe one silent schedule with one set of readings',
        );

        // The two numbers the tolerance is made of travel with the block for the same reason the
        // readings do: a monitor holding `stale_after_seconds` alone can say a beat is late, and
        // cannot say late by how many runs of what cadence. Both are the command's values, not a
        // second reading of the configuration that could drift from it.
        $this->assertSame(5, $response->json('schedule.missed_runs'), 'the shipped tolerance, in runs');
        $this->assertSame(1, $response->json('schedule.interval_minutes'), 'the shipped one-minute cadence');
        $this->assertSame($report['missed_runs'], $response->json('schedule.missed_runs'));
        $this->assertSame($report['interval_minutes'], $response->json('schedule.interval_minutes'));
        $this->assertSame(
            $response->json('schedule.stale_after_seconds'),
            $response->json('schedule.missed_runs') * $response->json('schedule.interval_minutes') * 60,
            'the three numbers in the block agree, so a monitor can derive the bound rather than trust it',
        );
    }

    /**
     * An installation whose entry is not scheduled has nothing for the readings to explain — the
     * command reports an empty list, and the endpoint has to report the same emptiness rather than
     * inventing a store or a stamp for a schedule nobody registered.
     */
    public function test_an_entry_that_is_not_scheduled_publishes_no_readings(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        config()->set('db-manager.swrr.pgcat.schedule.enabled', false);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('schedule.causes', []);

        // A disarmed entry still has the cadence and the count written down, and the command still
        // prints them: the pair says what the installation was asked to tolerate, which is a fact
        // about the configuration rather than about the verdict. Dropping them here would make the
        // block's shape depend on whether somebody switched the entry off.
        $response->assertJsonPath('schedule.missed_runs', 5)
            ->assertJsonPath('schedule.interval_minutes', 1);

        $this->assertSame(0, Artisan::call('db:scheduler-watchdog', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $report['causes']);
        $this->assertSame($report['missed_runs'], $response->json('schedule.missed_runs'));
        $this->assertSame($report['interval_minutes'], $response->json('schedule.interval_minutes'));
    }

    /**
     * A container with no flipper has no schedule to read a cadence from, and says so with `null`
     * rather than with nothing.
     *
     * The keys are the command's absent shape, which reports `null` for both on the `unbound` route
     * for the same reason: a monitor that plots the tolerance cannot tell a value that was never
     * read from one that was zero, and a missing key is the silence this block exists to avoid. The
     * keys are present, `applicable` is false, and no cadence is invented for a flipper that is not
     * there.
     */
    public function test_a_container_with_no_flipper_reports_no_cadence_rather_than_none_at_all(): void
    {
        $this->app->instance(DatabaseHealthController::class, new DatabaseHealthController());

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pgcat', null)
            ->assertJsonPath('schedule.applicable', false)
            ->assertJsonPath('schedule.failed', false)
            ->assertJsonPath('schedule.stale_after_seconds', 0)
            ->assertJsonPath('schedule.missed_runs', null)
            ->assertJsonPath('schedule.interval_minutes', null)
            ->assertJsonPath('schedule.causes', []);
    }

    /**
     * The mirror state, and the one a stale config arrives in outside a window: the container
     * converged, the boot verdict is healthy, and pgcat's file still holds the readers variant
     * after the last window closed — so every read through the pooler reaches a replica during
     * the hours the fallback exists to keep them on the writer, while the payload would answer
     * `ok`. The window is set two hours ahead of now, so "outside a window" holds for any moment
     * the suite runs at.
     */
    public function test_a_pooler_left_on_the_readers_config_outside_a_reader_window_is_degraded_and_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $slot = gmdate('H:00:00', time() + 7200);
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            readerWindows: [['start' => $slot, 'end' => gmdate('H:59:59', time() + 7200)]],
        );

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            // The database answered and the container converged: this verdict comes from the
            // pooler's own file and from nothing else.
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('reader_window.applicable', true)
            ->assertJsonPath('reader_window.failed', true)
            ->assertJsonPath('reader_window.expected', 'writer')
            ->assertJsonPath('reader_window.applied', 'readers');

        $this->assertStringContainsString('no reader window is open', (string) $response->json('reader_window.reason'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'health/db degraded')
                    && str_contains($message, 'pgcat is out of step with the reader windows')
                    && ($context['reader_window']['failed'] ?? null) === true
                    && ($context['reader_window']['applied'] ?? null) === 'readers';
            });
    }

    /**
     * The pooler behind the pool, asked rather than inferred: the one field of this payload whose
     * answer comes from the pooler itself, and the half of the self-heal a query cannot see. An
     * endpoint that reported `ok` while the pooler holds no pool for a database the application is
     * connected to would be reporting on a connection it cannot read through.
     */
    public function test_the_pool_probe_reports_what_the_pooler_answered(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['writer_db_1', 'reader_db_2'],
            poolProbe: static fn (string $database): array => [
                // The pooler this fixture is resolves a pool before it authenticates, so the
                // control name is refused and every accepted pool is one it holds. The endpoint
                // is carried as `PoolProbe::ask()` carries it, because the block publishes where
                // the packets went: 7432 rather than pgcat's 6432, so a payload that printed the
                // convention instead of the exchange would be a different string.
                'status' => str_starts_with($database, 'weighted-dbmanager-control-') ? 'missing' : 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
                'host' => '127.0.0.1',
                'port' => 7432,
                'address' => 'tcp://127.0.0.1:7432',
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.enabled', true)
            ->assertJsonPath('pool_probe.applicable', true)
            ->assertJsonPath('pool_probe.answered', true)
            ->assertJsonPath('pool_probe.discriminates', true)
            ->assertJsonPath('pool_probe.control.status', 'missing')
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.warned', false)
            ->assertJsonPath('pool_probe.reason', null)
            ->assertJsonPath('pool_probe.pools.0.database', 'writer_db_1')
            ->assertJsonPath('pool_probe.pools.1.database', 'reader_db_2')
            ->assertJsonPath('pool_probe.pools.1.status', 'answered')
            ->assertJsonPath('pool_probe.host', '127.0.0.1')
            ->assertJsonPath('pool_probe.port', 7432)
            ->assertJsonPath('pool_probe.address', 'tcp://127.0.0.1:7432');

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A pooler that authenticates before it resolves a pool answers every name, the sentinel
     * included. The payload still says `ok` — that ordering is a property of the pooler rather
     * than a fault in the installation — but `discriminates` is false, every `answered` pool is
     * published as `indeterminate`, and the reading is never `answered`, so a pool that may not
     * exist cannot be read as a pool that does.
     */
    public function test_an_auth_first_pooler_is_reported_without_being_failed(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.answered', false)
            ->assertJsonPath('pool_probe.discriminates', false)
            ->assertJsonPath('pool_probe.control.status', 'answered')
            ->assertJsonPath('pool_probe.failed', false)
            // Past the boot window a reading that proved nothing is reported: the pooler spoke,
            // and what it said is not evidence.
            ->assertJsonPath('pool_probe.warned', true)
            ->assertJsonPath('pool_probe.pools.0.status', 'indeterminate')
            // No console was configured, so nothing made those answers proof: the payload says
            // so rather than leaving a green `answered` to be read as one.
            ->assertJsonPath('pool_probe.verified', false)
            ->assertJsonPath('pool_probe.admin', null)
            ->assertJsonPath('pool_probe.judged', true);

        $this->assertStringStartsWith('weighted-dbmanager-control-', (string) $response->json('pool_probe.control.database'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The reading the block prefers: the pooler's own admin console lists the pools it holds, and it
     * is asked before the front door. An answered pool is then proof, and the payload publishes the
     * console's answer beside it — status, names and sentence — so a reader can see which reading
     * established the fact rather than having to trust the boolean. `discriminates` and `control`
     * are null because no anonymous name had to be asked to say what the list already said.
     */
    public function test_an_auth_first_pooler_is_proved_by_its_own_admin_console(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
            ],
            poolAdmin: static fn (): array => [
                'status' => 'read',
                'pools' => ['reader_db_2'],
                'evidence' => 'the admin console at tcp://127.0.0.1:7432 listed 1 pool(s)',
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.answered', true)
            ->assertJsonPath('pool_probe.discriminates', null)
            ->assertJsonPath('pool_probe.control', null)
            ->assertJsonPath('pool_probe.verified', true)
            ->assertJsonPath('pool_probe.admin.status', 'read')
            ->assertJsonPath('pool_probe.admin.pools.0', 'reader_db_2')
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.warned', false);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The fault this block exists for, found by the one reading that can find it on a pooler that
     * authenticates first: the front door answers every name and the pooler's own console lists no
     * pool for the database every read is routed to. The payload is degraded, in the console's
     * words, and logged like the front-door refusal is.
     */
    public function test_a_pool_only_the_console_knows_is_missing_degrades_the_payload(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
            ],
            poolAdmin: static fn (): array => [
                'status' => 'read',
                'pools' => [],
                'evidence' => 'the admin console at tcp://127.0.0.1:7432 listed 0 pool(s)',
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pool_probe.failed', true)
            ->assertJsonPath('pool_probe.pools.0.status', 'missing');

        $this->assertStringContainsString('admin console lists no pool "reader_db_2"', (string) $response->json('pool_probe.reason'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'health/db degraded')
                && ($context['pool_probe']['failed'] ?? null) === true);
    }

    /**
     * The fault this block exists for: the pooler is RUNNING, supervisord says so, the file is the
     * one the windows ask for, the database answers — and one pool every read is routed to does not
     * exist. The payload is degraded and the log names it, in the pooler's own words.
     */
    public function test_a_pool_the_pooler_holds_no_pool_for_degrades_the_payload_and_is_logged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'missing',
                'evidence' => sprintf('pgcat is running but has no pool "%s": No pool configured for database: "%s"', $database, $database),
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pinned.ok', true)
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('reader_window.failed', false)
            ->assertJsonPath('pool_probe.applicable', true)
            ->assertJsonPath('pool_probe.answered', false)
            ->assertJsonPath('pool_probe.failed', true);

        $this->assertStringContainsString('holds no pool "reader_db_2"', (string) $response->json('pool_probe.reason'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'health/db degraded')
                    && str_contains($message, 'not answerable for a pool the configuration declares')
                    && ($context['pool_probe']['failed'] ?? null) === true
                    && ($context['pool_probe']['pools'][0]['status'] ?? null) === 'missing';
            });
    }

    /**
     * A pooler that answered nothing is reported and never failed, which is the line that keeps a
     * probe pointed at the wrong address from taking a healthy installation down.
     */
    public function test_a_pooler_that_answered_nothing_is_reported_without_degrading_the_payload(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'silent',
                'evidence' => 'tcp://127.0.0.1:5432 did not answer for pool "reader_db_2": Connection refused',
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.answered', false)
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.warned', true);

        $this->assertStringContainsString('did not answer for pool "reader_db_2"', (string) $response->json('pool_probe.reason'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The same answer while the container is still inside its boot window does not degrade the
     * payload: the flip still re-applies the configuration there, so a starting container is not
     * failed by a reading that is about to be repaired — the same line the reader-window verdict
     * draws. The answer itself is still published, so a reader can see it.
     */
    public function test_a_missing_pool_inside_the_boot_window_does_not_degrade_the_payload(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 60,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'missing',
                'evidence' => 'pgcat is running but has no pool "reader_db_2"',
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.answered', false)
            ->assertJsonPath('pool_probe.failed', false);

        $this->assertStringContainsString('boot window is still open', (string) $response->json('pool_probe.reason'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The default, and the reason this field costs nothing on an installation that has not opted
     * in: nothing is asked, and the block says so rather than reporting every pool as fine.
     */
    public function test_the_pool_probe_says_nothing_was_asked_when_it_is_not_armed(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers', pools: ['reader_db_2']);

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('pool_probe.enabled', false)
            ->assertJsonPath('pool_probe.applicable', false)
            ->assertJsonPath('pool_probe.answered', null)
            ->assertJsonPath('pool_probe.pools', [])
            ->assertJsonPath('pool_probe.reason', null);
    }

    /**
     * The repair rate, which is the one reading in this payload that warns rather than fails: a
     * pooler repaired on run after run is a problem for a person, and the repair itself is the
     * self-heal working. So the block says `warned` with the count, the window and the threshold it
     * was judged against, and the status stays `ok` — the difference between a monitor that opens a
     * ticket and a load balancer that evicts an instance.
     */
    public function test_the_pool_repair_rate_warns_without_moving_the_status(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            stateExtra: [
                'pool_repairs' => 5,
                'last_pool_repair_at' => gmdate(DATE_ATOM, time() - 60),
                'pool_repair_times' => [
                    gmdate(DATE_ATOM, time() - 2400),
                    gmdate(DATE_ATOM, time() - 1800),
                    gmdate(DATE_ATOM, time() - 600),
                    gmdate(DATE_ATOM, time() - 120),
                    gmdate(DATE_ATOM, time() - 60),
                ],
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_repairs.applicable', true)
            ->assertJsonPath('pool_repairs.judged', true)
            ->assertJsonPath('pool_repairs.warned', true)
            ->assertJsonPath('pool_repairs.in_window', 5)
            ->assertJsonPath('pool_repairs.count', 5)
            ->assertJsonPath('pool_repairs.window_seconds', 3600)
            ->assertJsonPath('pool_repairs.max_repairs', 3);

        $this->assertStringContainsString('threshold is 3', (string) $response->json('pool_repairs.reason'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * Below the line it is a reading and nothing else: the count is published, `warned` is false
     * and there is no sentence to page on — a container that repaired itself once or twice at boot
     * is the feature working, not a warning.
     */
    public function test_a_container_within_the_threshold_publishes_the_rate_and_no_warning(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            stateExtra: [
                'pool_repairs' => 2,
                'pool_repair_times' => [
                    gmdate(DATE_ATOM, time() - 300),
                    gmdate(DATE_ATOM, time() - 60),
                ],
            ],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_repairs.judged', true)
            ->assertJsonPath('pool_repairs.warned', false)
            ->assertJsonPath('pool_repairs.in_window', 2)
            ->assertJsonPath('pool_repairs.reason', null);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A file written before repairs were dated is the one state this cannot judge, and the payload
     * says so rather than reporting zero recent repairs — the comfortable answer, and the one that
     * would make an installation with four repairs on record look quiet.
     */
    public function test_a_state_file_with_a_count_and_no_times_says_it_could_not_be_judged(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            stateExtra: ['pool_repairs' => 4],
        );

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_repairs.applicable', true)
            ->assertJsonPath('pool_repairs.judged', false)
            ->assertJsonPath('pool_repairs.in_window', null)
            ->assertJsonPath('pool_repairs.warned', false)
            ->assertJsonPath('pool_repairs.count', 4);

        $this->assertStringContainsString('no times', (string) $response->json('pool_repairs.reason'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The window block's key set is the README's, key for key.
     *
     * This is the array a monitor reads: `flip.window` here, `status.window` under
     * `db:pgcat-flip --status --json`, and the same reading behind the status table's rows, the
     * doctor's `pool repairs` row and the `pool_repairs` block. Its shape is therefore a contract
     * with four readers at once, and the only place it is written down is the table under this
     * heading in the README — so the table and the array are compared here rather than left to a
     * reader to notice a key that arrived unannounced. Both directions are checked: a key the
     * array publishes and the table does not name fails, and so does a row the array has stopped
     * publishing.
     *
     * The second assertion is the reason a single table can document the other two surfaces:
     * the endpoint publishes the flipper's own array rather than a copy of it, which is what the
     * reader-window and schedule blocks beside it do as well.
     */
    public function test_every_key_the_window_block_publishes_is_documented(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $flipper = $this->bindWindowFlipper(bootedSecondsAgo: 120);

        $response = $this->getJson('/health/db')->assertOk();
        $published = array_keys($response->json('flip.window'));

        $documented = array_map(
            [Readme::class, 'plain'],
            array_column(Readme::table('### The window block: every key it publishes', 'key'), 'key'),
        );

        $this->assertSame($documented, $published, 'the README table and the published array are the same key set');
        $this->assertSame(
            array_keys($flipper->windowStatus()),
            $published,
            'the block is the flipper\'s own array, which is what lets one table document it',
        );
    }

    /**
     * Point the connection the package follows at SQLite.
     *
     * `$database` is a file, `:memory:` or a path under a directory that does not exist — the
     * last being how a failure is provoked without a network: SQLite's connector fails before a
     * file is opened, so there is nothing to wait for and nothing to clean up.
     *
     * The fixture's other connections go with it. The controller also probes a declared `read`
     * list with `getPdo()`, and this fixture's replicas are addresses nothing answers on, so the
     * payload under test has to contain no declared replica for the query to be the only probe
     * in it — which is exactly the shape the regression was observed in.
     */
    private function pinToSQLite(string $database): void
    {
        config()->set('database.connections', [
            self::PINNED => ['driver' => 'sqlite', 'database' => $database],
        ]);

        config()->set('database.default', self::PINNED);
    }

    /**
     * Bind a flipper whose container booted `$bootedSecondsAgo` seconds ago — or, with `null`, one
     * whose boot nothing recorded — so the endpoint has a window to judge.
     *
     * The driver is pinned to `pgsql` on purpose. The connection this test queries is SQLite, and
     * pgcat cannot front SQLite, so a flipper built from the app's own connection would report
     * itself `enabled: false` and the endpoint would have no verdict to be wrong about. The driver
     * is the only thing that has to be PostgreSQL here; the flipper never runs, because every
     * closure it would need is the default one and `healthSummary()` only reads.
     *
     * `$appliedMode` puts the reader-window verdict under test instead: a file matching the named
     * variant is written for pgcat's own config, so the pooler is provably on it. Left null, no
     * target is written and the verdict is "not judged", which is what the boot-window cases need
     * in order to be about the boot window alone.
     *
     * `$readerWindows` overrides the day's window. The default covers the whole day, so the mode
     * is `readers` at any moment; a window whose hour is still ahead of "now" puts the test
     * outside one, which is the direction where a stale readers config is the fault.
     *
     * `$pools` declares pool sections in the readers variant — the file the pool probe reads the
     * names off — and they are written *before* the target is copied from it, so a pooler in step
     * with the window stays in step. `$poolProbe` is the closure the flipper would open a socket
     * with, injected so the endpoint can be driven without a pooler: a real probe is what
     * `PgcatConfigFlipperTest` and `PoolProbeTest` cover, and what this file is about is the
     * endpoint's reading of the answers.
     *
     * `$beatAt` and `$stampBeat` are the schedule's two levers: the state record carries a beat at
     * "now" by default, which is the healthy container every other case in this file describes, and
     * a case about a stopped schedule passes a stamp old enough to have missed the tolerance — or
     * `$stampBeat: false` for the container the beat has never been written in at all.
     */
    private function bindWindowFlipper(
        ?int $bootedSecondsAgo,
        ?string $appliedMode = null,
        ?array $readerWindows = null,
        array $pools = [],
        ?\Closure $poolProbe = null,
        ?\Closure $poolAdmin = null,
        ?string $beatAt = null,
        bool $stampBeat = true,
        array $stateExtra = [],
    ): PgcatConfigFlipper {
        $dir = sys_get_temp_dir().'/swrr-health-window-'.bin2hex(random_bytes(4));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        $bootFile = $dir.'/container-booted-at';

        if ($bootedSecondsAgo !== null) {
            file_put_contents($bootFile, (string) (time() - $bootedSecondsAgo));
        }

        $config = [
            'enabled' => true,
            'config_path' => $dir.'/pgcat.toml',
            'readers_path' => $dir.'/pgcat-readers.toml',
            'no_readers_path' => $dir.'/pgcat-no-readers.toml',
            'state_file' => $dir.'/pgcat-flip-state.json',
            'lock_file' => $dir.'/pgcat-flip.lock',
            'restart_command' => 'supervisorctl restart "pgcat:*"',
            'reload_command' => 'supervisorctl signal HUP "pgcat:*"',
            'use_reload' => false,
            'boot_file' => $bootFile,
            'flip_window_seconds' => 480,
        ];

        // The three files a reader-window verdict compares, written only when a test asks for a
        // mode: the cases that are about the boot window keep the target absent, so their verdict
        // is "not judged" and the status can only move for the reason they are about.
        //
        // The state record is written with them, and it is what lets the two verdicts be told
        // apart: a run converged *inside* the boot window, so the boot verdict is healthy and the
        // only thing left to fail on is the file the pooler is still sitting on.
        //
        // `beat_at` rides with the convergence record for the same reason it rides with it in the
        // file the flipper writes: the state a run leaves behind is both facts at once, and a
        // container whose schedule had stopped would not have converged at all. Written as "now"
        // because that is what a container whose per-minute entry is still running has — and this
        // fixture is a *healthy* container, so the schedule verdict must be `ok` here and the
        // status can only move for the reason each test is about. `$beatAt` and `$stampBeat` are
        // how the tests that *are* about the schedule say otherwise.
        if ($appliedMode !== null) {
            $sections = implode('', array_map(static fn (string $pool): string => "[pools.{$pool}]\n", $pools));

            file_put_contents($config['readers_path'], "pool = 'readers'\n".$sections);
            file_put_contents($config['no_readers_path'], "pool = 'writer-only'\n");
            file_put_contents(
                $config['config_path'],
                (string) file_get_contents($appliedMode === 'readers' ? $config['readers_path'] : $config['no_readers_path']),
            );

            if ($bootedSecondsAgo !== null) {
                $convergedAt = gmdate(DATE_ATOM, time() - $bootedSecondsAgo + 60);
                $state = [
                    'last_mode' => $appliedMode,
                    'last_run_at' => $convergedAt,
                    'last_kind' => 'flipped',
                    'runs' => 1,
                    'converged_at' => $convergedAt,
                    'converged_mode' => $appliedMode,
                ];

                // The absence is a state of its own rather than a null to fall back from: a
                // container that has never run the entry is exactly the 85-minute incident, and
                // it is the case this endpoint used to answer `ok` to.
                if ($stampBeat) {
                    $state['beat_at'] = $beatAt ?? gmdate(DATE_ATOM);
                }

                // The state a test is *about* travel last, so a repair history can be seeded
                // beside the convergence record a healthy container carries — the two are the
                // same file, written by the same run, and a reading that took one without the
                // other would be a container that never existed.
                $state = array_merge($state, $stateExtra);

                file_put_contents($config['state_file'], (string) json_encode($state));
            }
        }

        $flipper = new PgcatConfigFlipper(
            resolver: new TimeWindowResolver(
                readerWindows: $readerWindows ?? [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            ),
            config: $config,
            stateFile: $config['state_file'],
            lockFile: $config['lock_file'],
            driver: 'pgsql',
            poolProbe: $poolProbe,
            poolAdmin: $poolAdmin,
        );

        $this->app->instance(PgcatConfigFlipper::class, $flipper);

        return $flipper;
    }
}

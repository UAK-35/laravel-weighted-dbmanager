<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Http;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;
use Uak35\WeightedDbManager\Support\BootAudit;
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

    /** The README heading the payload's path table lives under, read by the shape guard. */
    private const PAYLOAD_INDEX = '### Every block, and every key in it';

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
     * The class that builds the followed connection's PDO is published, not left to a preflight.
     *
     * An installation replaces the PostgreSQL connector by binding `db.connector.pgsql`, and
     * `.env` says nothing about it, so the only places the answer lived were a terminal running
     * `db:doctor` and the reading inside it. A dashboard is where an installation is watched, and
     * a block whose shape changes with the answer — keys present only when somebody replaced
     * something — would make a monitor's reading depend on the state it is comparing against. So
     * every key is present and the ones that do not describe this installation are `null`.
     */
    public function test_the_connector_block_names_the_class_that_builds_the_followed_connection(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('connector.connection', self::PINNED)
            ->assertJsonPath('connector.source', 'database.default')
            ->assertJsonPath('connector.driver', 'sqlite')
            ->assertJsonPath('connector.binding', null)
            ->assertJsonPath('connector.factory', WeightedConnectionFactory::class)
            ->assertJsonPath('connector.factory_error', null)
            ->assertJsonPath('connector.connector', SQLiteConnector::class)
            ->assertJsonPath('connector.error', null)
            ->assertJsonPath('connector.connection_class', SQLiteConnection::class)
            ->assertJsonPath('connector.connection_driver', 'sqlite')
            ->assertJsonPath('connector.connection_error', null)
            ->assertJsonPath('connector.resolver', null)
            ->assertJsonPath('connector.resolver_applied', null);

        // The factory named is the one this package binds — the weighted factory, which builds
        // the same connector the framework's own would — so the block describes this installation
        // rather than the framework in general.
        $response->assertJsonPath('status', 'ok');
    }

    /**
     * The state the block exists for: an installation's own connector, named with the binding that
     * answered it — and no movement in the status, because replacing a connector is a documented
     * extension point rather than a fault. A monitor that paged on "somebody bound a connector"
     * would page on a supported configuration.
     */
    public function test_a_replaced_connector_is_published_and_does_not_move_the_status(): void
    {
        $stub = new class () extends SQLiteConnector {};
        $this->app->instance('db.connector.sqlite', $stub);

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('connector.binding', 'db.connector.sqlite')
            ->assertJsonPath('connector.connector', $stub::class)
            ->assertJsonPath('connector.error', null)
            ->assertJsonPath('connector.connection_class', SQLiteConnection::class)
            ->assertJsonPath('status', 'ok');
    }

    /**
     * The other way a class can be somebody else's, and the one no connector binding shows:
     * `Connection::resolverFor()` replaces the class every connection of that driver is built as.
     * It is published with whether it reached the connection in use, which is the answer a
     * registry cannot give — a resolver registered after the connection was built is a
     * replacement that is in the container and not in the connection.
     *
     * The registry is static and has no unregister, so the case puts back what it found: a leaked
     * resolver would make every later connection in this process an anonymous class.
     */
    public function test_a_connection_resolver_is_published_and_does_not_move_the_status(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $class = (new class ('', 'database', '', ['driver' => 'sqlite']) extends SQLiteConnection {})::class;
        $resolvers = (new ReflectionProperty(Connection::class, 'resolvers'))->getValue();

        try {
            Connection::resolverFor('sqlite', static fn ($pdo, $database, $prefix, $config) => new $class($pdo, $database, $prefix, $config));

            $response = $this->getJson('/health/db')->assertOk();
        } finally {
            (new ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, is_array($resolvers) ? $resolvers : []);
        }

        $response->assertJsonPath('connector.resolver', 'sqlite')
            ->assertJsonPath('connector.resolver_applied', true)
            ->assertJsonPath('connector.connection_class', $class)
            ->assertJsonPath('status', 'ok');
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
     * The audit's second question, asked from the surface a monitor already polls: is the record
     * still being written at all?
     *
     * The findings standing in the record describe a configuration, and a record nothing writes
     * goes on describing one: the file prints no date of its own, and `db:doctor`'s `probe stamp`
     * row is a thing somebody has to run. The stamp is the one field a healthy installation moves on
     * its own, so the age published here is the evidence — with the window it is judged against
     * published beside it, decomposed into the two settings the window is made of, so a monitor
     * reading `stale_after_seconds` can say *how* late a stamp is without trusting a product it
     * cannot take apart.
     *
     * Five cadences at the shipped settings: the probe every 60 seconds and the entry every minute
     * are the same cadence, so the window is five minutes.
     */
    public function test_the_probe_stamps_age_is_published_with_the_window_it_is_judged_against(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->recordABoot();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.store_probe.applicable', true)
            ->assertJsonPath('audit.store_probe.stale', false)
            ->assertJsonPath('audit.store_probe.not_judged', null)
            ->assertJsonPath('audit.store_probe.reason', null)
            ->assertJsonPath('audit.store_probe.missed_cadences', 5)
            ->assertJsonPath('audit.store_probe.probe_seconds', 60)
            ->assertJsonPath('audit.store_probe.interval_minutes', 1)
            ->assertJsonPath('audit.store_probe.stale_after_seconds', 300);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            (string) $response->json('audit.store_probe.last_probed_at'),
            'the stamp is published as DATE_ATOM in UTC, which is how the payload publishes every other moment',
        );

        $age = $response->json('audit.store_probe.age_seconds');

        $this->assertIsInt($age);
        $this->assertLessThanOrEqual(300, $age);
    }

    /**
     * The fault the block exists for, and the claim that it leaves the status alone.
     *
     * An audit that stopped being written is a configuration nobody is describing any more, which is
     * not "this database is unreachable" — the same line the findings themselves draw. A load
     * balancer that took an unwritten audit record out of rotation would be acting on the wrong
     * fact, so the reading is published with `stale` and a sentence and the status stays `ok`; the
     * monitor that should act on it has `audit.store_probe.stale` to write a rule against.
     */
    public function test_a_record_nothing_writes_is_published_as_stale_without_moving_the_status(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->recordABoot();

        // A record last written an hour ago, read by a process that has not reported: the file is
        // what a web process sees when the boot it answered on never probed, and it is the state the
        // fault is reported from rather than the one `db:doctor`'s own boot would have refreshed.
        $file = $this->auditRecord(['findings' => [], 'store_probed_at' => time() - 3600]);

        $this->app->instance(BootAudit::class, new BootAudit($file));

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.severity', 'none')
            ->assertJsonPath('audit.store_probe.applicable', true)
            ->assertJsonPath('audit.store_probe.stale', true)
            ->assertJsonPath('audit.store_probe.not_judged', null)
            ->assertJsonPath('audit.store_probe.stale_after_seconds', 300);

        $this->assertGreaterThanOrEqual(3600, $response->json('audit.store_probe.age_seconds'));

        $reason = (string) $response->json('audit.store_probe.reason');

        $this->assertStringContainsString($file, $reason, 'the file an operator has to open');
        $this->assertStringContainsString('1 hour(s)', $reason, 'the age, in the words the command uses for the same reading');
        $this->assertStringContainsString('5 missed cadences (5 minute(s)', $reason, 'the tolerance, and the two settings it is made of');
        $this->assertStringContainsString('state directory this installation does not read', $reason, 'the two causes the sentence names');
    }

    /**
     * A container that recorded a boot, which is what makes an age judgeable at all.
     *
     * The window is read from the stamp and not from the flipper's own state, so a fixture that means
     * the age to be judged has to have one: without it the reading is `no_boot_stamp` whatever the
     * record holds, and a test about the age would be asserting the wrong state.
     */
    private function recordABoot(): void
    {
        $dir = sys_get_temp_dir().'/swrr-health-boot-'.bin2hex(random_bytes(4));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/container-booted-at', (string) time());

        config()->set('db-manager.swrr.pgcat.boot_file', $dir.'/container-booted-at');
    }

    /**
     * Six states in which an age is nobody's fault, each named rather than left to read as a pass.
     *
     * `stale` is `null` in all of them, because *nothing asked it* is not the same claim as *it reads
     * well* — and `not_judged` says which of the five removed the expectation, so a reader is not
     * left to infer a healthy window from a missing verdict. The tolerance is still published: it is
     * what the installation was asked to tolerate, and a monitor plotting an age against a number
     * that vanishes the moment the probe is switched off would have to remember the last value it
     * saw.
     *
     * @param string $state which of the five the fixture is put in
     * @param string $expected the fact the sentence has to name
     * @param int $probeSeconds the interval the block should publish in that state
     */
    #[DataProvider('statesWhereTheAgeIsNotJudged')]
    public function test_the_age_is_not_judged_and_the_sentence_names_the_state_that_removed_the_expectation(
        string $state,
        string $expected,
        int $probeSeconds,
    ): void {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $file = (string) config('db-manager.swrr.audit.file');

        if ($state === 'no_boot_stamp') {
            // A stamp path that is deliberately not there, so the state is the container's rather
            // than a file another run left in the machine's temp directory.
            config()->set('db-manager.swrr.pgcat.boot_file', sys_get_temp_dir().'/swrr-no-boot-'.bin2hex(random_bytes(4)));
        }

        match ($state) {
            'off' => $this->app->instance(BootAudit::class, new BootAudit($file, 0)),
            'unwritable' => $this->app->instance(
                BootAudit::class,
                new BootAudit($file, 60, canWrite: static fn (string $path): bool => false),
            ),
            'no_stamp' => $this->app->instance(
                BootAudit::class,
                new BootAudit($this->auditRecord(['findings' => []])),
            ),
            'no_boot_stamp' => $this->app->instance(
                BootAudit::class,
                new BootAudit($this->auditRecord(['findings' => [], 'store_probed_at' => time()])),
            ),
            'unarmed' => config()->set('db-manager.swrr.pgcat.enabled', false),
            'in_process' => config()->set('db-manager.swrr.primary_store', 'local'),
        };

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('audit.store_probe.stale', null)
            ->assertJsonPath('audit.store_probe.reason', null)
            ->assertJsonPath('audit.store_probe.probe_seconds', $probeSeconds);

        $this->assertStringContainsString(
            $expected,
            (string) $response->json('audit.store_probe.not_judged'),
            'the sentence has to say which state removed the expectation, not only that none was judged',
        );
    }

    /**
     * The six fixtures the test above runs, and what each sentence has to name.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function statesWhereTheAgeIsNotJudged(): array
    {
        return [
            'probing switched off' => ['off', 'switched off (swrr.audit.store_probe_seconds = 0)', 0],
            'the record cannot be written' => ['unwritable', 'frozen rather than late', 60],
            'the record holds no stamp' => ['no_stamp', 'fresh installation rather than a stale one', 60],
            'the container recorded no boot' => ['no_boot_stamp', 'this container recorded no boot time', 60],
            'the flip schedule is not armed' => ['unarmed', 'flip schedule is not armed', 60],
            'the store is in-process' => ['in_process', 'reads are served in-process', 60],
        ];
    }

    /**
     * No audit registered at all — the dependency is optional, so the controller resolves — and the
     * block says so instead of omitting itself.
     *
     * An absent block reads as a pass to every reader that walks the payload, and this is the reading
     * a monitor is being asked to trust; `applicable: false` is the field that says there was nothing
     * to read, and every other key is present so a rule never has to guard for one that might be
     * missing.
     */
    public function test_no_registered_audit_publishes_the_stamp_block_as_not_applicable(): void
    {
        $this->app->instance(DatabaseHealthController::class, new DatabaseHealthController());

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('audit.available', false)
            ->assertJsonPath('audit.store_probe.applicable', false)
            ->assertJsonPath('audit.store_probe.stale', null)
            ->assertJsonPath('audit.store_probe.reason', null)
            ->assertJsonPath('audit.store_probe.stale_after_seconds', 0)
            ->assertJsonPath('audit.store_probe.probe_seconds', 0)
            ->assertJsonPath('audit.store_probe.age_seconds', null)
            ->assertJsonPath('audit.store_probe.last_probed_at', null);

        $this->assertStringContainsString(
            'no boot audit is registered',
            (string) $response->json('audit.store_probe.not_judged'),
        );
    }

    /**
     * Write an audit record where the configuration points, and answer its path.
     *
     * @param array<string, mixed> $record
     */
    private function auditRecord(array $record): string
    {
        $file = (string) config('db-manager.swrr.audit.file');

        file_put_contents($file, (string) json_encode($record, JSON_UNESCAPED_SLASHES));

        return $file;
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

        // The three reasons are told apart, and this one names both mechanisms — the repair
        // differs, and neither one acting is a different state from one that ran and failed.
        $this->assertStringContainsString('nothing applied a mode inside', (string) $response->json('flip.reason'));
        $this->assertStringContainsString('is not being scheduled', (string) $response->json('flip.reason'));
        $this->assertStringContainsString('no reader-window task acted', (string) $response->json('flip.reason'));

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
     * The readiness probe, and the whole of it: `status` and `pool_probe`, on a pod whose database
     * cannot be reached at all.
     *
     * Why the dead connection is the subject: the cheapness of this action is the *absence* of the
     * query `/health/db` rests on, so the fixture is the one connection that cannot open with the
     * pinned query switched on. The full endpoint answers 503 on it and this one answers 200 — the
     * whole difference between the two: a kubelet is asked about the pools this pod serves, not
     * about a database every request it is sent will report for itself. Both readings come from one
     * fixture, so the two actions cannot be described by two different installations.
     */
    public function test_the_readiness_probe_returns_the_pool_probe_and_opens_no_database(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);
        Route::get('/ready', [DatabaseHealthController::class, 'readiness']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(sys_get_temp_dir().'/swrr-never-created-'.bin2hex(random_bytes(4)).'/dead.sqlite');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['writer_db_1', 'reader_db_2'],
            // The control name is refused, as a pooler that resolves a pool before it authenticates
            // answers it: without that the sentinel is `answered` too, every pool is
            // `indeterminate`, and the fixture would be a reading that proves nothing.
            poolProbe: static fn (string $database): array => [
                'status' => str_starts_with($database, 'weighted-dbmanager-control-') ? 'missing' : 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
            ],
        );

        $this->getJson('/health/db')->assertStatus(503);

        // A fresh spy after the full endpoint has already logged its own failure: what is under test
        // here is the silence of a probe that is polled every few seconds, not the other action's.
        Log::spy();

        $response = $this->getJson('/ready')->assertOk();

        // The body in both directions: this endpoint's contract is two keys, and a block that
        // grew one is the change a kubelet's reader would not notice.
        $this->assertSame(['status', 'pool_probe'], array_keys((array) $response->json()));

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.enabled', true)
            ->assertJsonPath('pool_probe.answered', true)
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.pools.1.status', 'answered');

        // Polled every ten seconds, so this action logs nothing at all: the degraded line belongs
        // to `/health/db`, which is read once and by somebody who can act on it.
        Log::shouldNotHaveReceived('error');
    }

    /**
     * The one state that takes the pod out of the load balancer: a pool the pooler refuses. The
     * same call the full payload reads, so the two cannot disagree about the container — and still
     * no log line, for the reason above.
     */
    public function test_the_readiness_probe_fails_on_a_pool_the_pooler_refuses(): void
    {
        Route::get('/ready', [DatabaseHealthController::class, 'readiness']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'missing',
                'evidence' => sprintf('pgcat is running but has no pool "%s"', $database),
            ],
        );

        Log::spy();

        $response = $this->getJson('/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('pool_probe.applicable', true)
            ->assertJsonPath('pool_probe.answered', false)
            ->assertJsonPath('pool_probe.failed', true)
            ->assertJsonPath('pool_probe.pools.0.status', 'missing');

        $this->assertStringContainsString('holds no pool "reader_db_2"', (string) $response->json('pool_probe.reason'));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A pooler that answered nothing is `warned`, never failed — the line that keeps a probe aimed
     * at the wrong address from evicting a pod that serves. A readiness probe is exactly the reader
     * that rule was written for: it takes the pod out of rotation, so only evidence it can trust is
     * allowed to do it.
     */
    public function test_the_readiness_probe_does_not_fail_a_pooler_that_answered_nothing(): void
    {
        Route::get('/ready', [DatabaseHealthController::class, 'readiness']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            poolProbe: static fn (string $database): array => [
                'status' => 'silent',
                'evidence' => sprintf('tcp://127.0.0.1:5432 did not answer for pool "%s": Connection refused', $database),
            ],
        );

        $response = $this->getJson('/ready')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.applicable', true)
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.warned', true)
            ->assertJsonPath('pool_probe.pools.0.status', 'silent');
    }

    /**
     * An installation that has not armed the probe, or one whose provider is not registered: the
     * readiness answer is `ok` with an `applicable: false` block, which is the only answer that
     * lets a load balancer be told about this package without demanding it be configured.
     */
    public function test_the_readiness_probe_answers_ok_when_nothing_was_asked(): void
    {
        $this->app->instance(DatabaseHealthController::class, new DatabaseHealthController());

        Route::get('/ready', [DatabaseHealthController::class, 'readiness']);

        $response = $this->getJson('/ready')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('pool_probe.enabled', false)
            ->assertJsonPath('pool_probe.applicable', false)
            ->assertJsonPath('pool_probe.answered', null)
            ->assertJsonPath('pool_probe.failed', false)
            ->assertJsonPath('pool_probe.pools', []);
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

        // One moment reused rather than recomputed: `time()` at the assertion can be a second past
        // the one the fixture was seeded with, and a test that failed on its own clock would be
        // reading a change nobody made.
        $newestAt = gmdate(DATE_ATOM, time() - 60);

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
                'last_repair_pool' => 'reader_db_2',
                'last_repair_mode' => 'readers',
                'last_repair_cleared' => false,
                'pool_repair_ledger' => [
                    ['at' => gmdate(DATE_ATOM, time() - 600), 'pool' => 'reader_db_2', 'mode' => 'readers', 'cleared' => true],
                    ['at' => $newestAt, 'pool' => 'reader_db_2', 'mode' => 'readers', 'cleared' => false],
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

        // The ledger travels with the warning, and in the order the flip wrote it: a monitor that
        // sees `warned` can say which pool the repairs kept landing on and whether the last one
        // left it answering, instead of guessing from one timestamp.
        $ledger = $response->json('pool_repairs.ledger');

        $this->assertCount(2, $ledger);
        $this->assertSame('reader_db_2', $ledger[0]['pool']);
        $this->assertTrue($ledger[0]['cleared']);
        $this->assertFalse($ledger[1]['cleared'], 'the newest entry is the repair the last-* facts name');
        $this->assertSame($newestAt, $ledger[1]['at']);

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
     * Every block, and every key in every block, pinned to the two tables that document them.
     *
     * WHY THE WHOLE SHAPE AND NOT THE FIELDS A TEST HAPPENS TO READ
     * -------------------------------------------------------------
     *   A payload is a contract with whoever compares JSON: a monitor's rule, a deploy gate's `jq`,
     *   a dashboard's column. Those readers do not fail when a block is renamed — they go on reading
     *   `null` and reporting it as a quiet installation, which is the failure mode every field here
     *   was written to avoid. Each block's own tests assert the values it publishes; this one asserts
     *   the *shape*, in both directions, so a block or a key cannot arrive or leave without the two
     *   tables that document it saying so.
     *
     *   The two tables are the README's — what a consumer reads — and `docs/health-payload.md`'s — the
     *   record of why the shape is a promise. They are compared with each other first, because a
     *   README that has moved on from the record is the same defect one level up: the repair is two
     *   edits, and a guard that read only one of them would let the other rot.
     *
     * WHAT THE TABLE HAS TO SAY
     * -------------------------
     *   One row per path the payload publishes: a block, or a nested object inside one. The `keys`
     *   cell is a backticked list, compared as a set, or an em dash for a path whose value is a
     *   scalar, a list, or an object documented under another heading — `flip.window` and
     *   `pgcat.window` are one array documented key by key under "The window block". A path that is
     *   a map keyed by something the installation names is written with `<connection>` for that key,
     *   which is the one name the payload chooses at runtime.
     *
     *   The richest payload this fixture can build is used deliberately: pgcat armed with a closed
     *   window, a mode applied, pools declared and repairs on record, so every block is an array
     *   rather than the `null` a quiet installation publishes. A shape pinned against the quiet
     *   payload would pass while the armed one had grown a key.
     */
    public function test_every_block_and_key_the_payload_publishes_is_documented(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(
            bootedSecondsAgo: 3600,
            appliedMode: 'readers',
            pools: ['reader_db_2'],
            stateExtra: ['pool_repairs' => 2, 'last_pool_repair_at' => gmdate(DATE_ATOM)],
        );

        $payload = $this->getJson('/health/db')->assertOk()->json();

        $readme = self::documentedPaths(Readme::section(self::PAYLOAD_INDEX), null);
        $record = self::documentedPaths(
            (string) file_get_contents(dirname(__DIR__, 3).'/docs/health-payload.md'),
            '## Every path',
        );

        $this->assertSame(
            $record,
            $readme,
            'the README table and docs/health-payload.md are one list; a path added to one is added to both',
        );

        $published = self::publishedPaths($payload, $readme);

        $unnamed = array_keys(array_diff_key($published, $readme));
        $stale = array_keys(array_diff_key($readme, $published));

        $this->assertSame([], $unnamed, self::repair(
            'the payload publishes a path neither table names',
            $unnamed,
            'Add each row to the README under '.self::PAYLOAD_INDEX.' and to docs/health-payload.md.',
        ));

        $this->assertSame([], $stale, self::repair(
            'the tables name a path the payload does not publish',
            $stale,
            'A block or key was renamed or dropped, so the two tables are the repair.',
        ));

        foreach ($readme as $path => $keys) {
            if ($keys === []) {
                continue;   // the em dash: a scalar, a list, or a shape documented under another heading
            }

            $this->assertNotNull(
                $published[$path],
                "the payload publishes `{$path}` as a scalar or a list where the tables name its keys",
            );

            $this->assertSame(
                $keys,
                $published[$path],
                "the tables and the payload disagree about the keys of `{$path}`; both tables are the repair",
            );
        }
    }

    /**
     * The pair the second mechanism made necessary, at the endpoint that acts on it.
     *
     * The per-minute entry records `converged_at` and stops acting when the boot window closes; a
     * reader-window task moves the mode through the forced path and records no run. So the state
     * here is a boundary task's own: a mode, the moment it moved, and the mechanism — no run, and
     * no convergence. Before the pair was read, this container was `degraded` for the rest of its
     * life, eight minutes after every boot, with a reason that was true and nothing could repair.
     */
    public function test_a_boundary_application_inside_the_window_keeps_the_status_ok(): void
    {
        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        config()->set('db-manager.swrr.health.pinned_query', true);
        $this->pinToSQLite(':memory:');
        $this->bindWindowFlipper(bootedSecondsAgo: 3600, appliedMode: 'readers', converged: false);

        Log::spy();

        $response = $this->getJson('/health/db')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('flip.failed', false)
            ->assertJsonPath('flip.reason', null)
            // The boot window's own evidence is still empty, and the reading says so rather than
            // borrowing the other mechanism's mode for it.
            ->assertJsonPath('pgcat.window.converged', false)
            ->assertJsonPath('pgcat.window.runs', 0)
            ->assertJsonPath('pgcat.window.applied', true)
            ->assertJsonPath('pgcat.window.applied_mode', 'readers')
            ->assertJsonPath('pgcat.window.applied_via', 'window');

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The rows of the first table under `$heading` in `$markdown`, as `path => keys`.
     *
     * One reader for both tables — the README section a consumer reads and the record that says why
     * the shape is a promise — because two readers would be two chances to parse one of them
     * differently and agree with a payload neither table describes.
     *
     * A `keys` cell is a backticked list read as a set, or an em dash for a path whose value is a
     * scalar, a list, or an object documented under another heading. Both are read from the table
     * rather than from a list in this file: a table that cannot be read fails here instead of being
     * silently compared with nothing.
     *
     * @return array<string, list<string>>
     */
    private static function documentedPaths(string $markdown, ?string $heading): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $at = 0;

        if ($heading !== null) {
            $found = array_search($heading, array_map('trim', $lines), true);

            if ($found === false) {
                throw new RuntimeException("the tables no longer have the heading [{$heading}], which a guard reads the payload's paths under");
            }

            $at = $found;
        }

        $header = null;
        $paths = [];

        foreach (array_slice($lines, $at + 1) as $line) {
            $trimmed = trim($line);

            if (!str_starts_with($trimmed, '|')) {
                if ($header !== null) {
                    break;      // the table has ended: the prose under it is not rows
                }

                continue;       // the paragraph between the heading and its table
            }

            $cells = array_map('trim', array_slice(explode('|', $trimmed), 1, -1));

            if ($header === null) {
                $header = $cells;

                continue;
            }

            if (self::isSeparator($cells[0])) {
                continue;
            }

            $row = array_combine($header, $cells);

            // The path is written in backticks because it is code in a sentence; the payload's own
            // keys are not, so the marker is read off here rather than remembered by every row.
            $paths[trim(str_replace('`', '', $row['path']))] = self::keyNames($row['keys']);
        }

        return $paths;
    }

    /** Whether a cell is the `|---|` row under a table's header. */
    private static function isSeparator(string $cell): bool
    {
        return preg_match('/^:?-{3,}:?$/', str_replace(' ', '', $cell)) === 1;
    }

    /**
     * The key names in a `keys` cell, sorted, or an empty list for the em dash.
     *
     * Sorted because a key set is a set: the payload publishes insertion order, which is a
     * property of the array literal rather than of the contract, and a reordering inside `index()`
     * is not a change a monitor sees.
     *
     * @return list<string>
     */
    private static function keyNames(string $cell): array
    {
        if (preg_match_all('/`([^`]+)`/', $cell, $matches) === 0) {
            return [];
        }

        $keys = array_map('strval', $matches[1]);
        sort($keys);

        return $keys;
    }

    /**
     * Every path the payload publishes, each with the keys it has there — `null` where the value is
     * a scalar or a list, which is what the tables' em dash stands for.
     *
     * Descent stops at a path the tables mark as opaque: a path documented with an em dash is one
     * whose interior is documented somewhere else, and a path no table names at all is already the
     * failure, so listing its children as well would bury the first line.
     *
     * @param array<string, mixed>             $payload
     * @param array<string, list<string>> $documented
     *
     * @return array<string, list<string>|null>
     */
    private static function publishedPaths(array $payload, array $documented): array
    {
        $paths = [];

        foreach ($payload as $path => $value) {
            $paths[(string) $path] = self::pathKeys($value, (string) $path);

            if (is_array($value) && !array_is_list($value) && ($documented[$path] ?? []) !== []) {
                self::collectChildren($value, (string) $path, $documented, $paths);
            }
        }

        return $paths;
    }

    /**
     * The keys of one path, or `null` where the value is a scalar or a list — the em dash, read the
     * other way round.
     *
     * @return list<string>|null
     */
    private static function pathKeys(mixed $value, string $path): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        // `replicas` is the one path whose key the installation chooses — the name of the connection
        // the package follows — so the tables write `<connection>` for it and the payload is read
        // with the same placeholder rather than with a name this fixture happens to use.
        $keys = $path === 'replicas'
            ? ['<connection>']
            : array_map('strval', array_keys($value));

        sort($keys);

        return $keys;
    }

    /**
     * The nested objects under one path, each as a path of its own.
     *
     * Only arrays are descended into: a scalar or a list under `$path` is one of `$path`'s keys and
     * is already compared with the table that way, so recording it as a path too would report every
     * leaf of every block as an undocumented block.
     *
     * @param array<string, mixed>             $value
     * @param array<string, list<string>> $documented
     * @param array<string, list<string>|null> $paths
     */
    private static function collectChildren(array $value, string $path, array $documented, array &$paths): void
    {
        foreach ($value as $name => $child) {
            if (!is_array($child) || array_is_list($child)) {
                continue;
            }

            $segment = $path === 'replicas' ? '<connection>' : (string) $name;
            $childPath = $path.'.'.$segment;

            $paths[$childPath] = self::pathKeys($child, $childPath);

            if (($documented[$childPath] ?? []) !== []) {
                self::collectChildren($child, $childPath, $documented, $paths);
            }
        }
    }

    /**
     * One failure message with its paths listed under it, and what the repair is.
     *
     * @param list<string> $paths
     */
    private static function repair(string $problem, array $paths, string $repair): string
    {
        return $problem.":\n  - ".implode("\n  - ", $paths)."\n".$repair;
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
        bool $converged = true,
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

                // The other mechanism's record, and the one that has no run in it: a boundary task
                // applies the mode through the forced path, which writes the mode, the moment and
                // the mechanism and deliberately records no convergence — a mode applied at 10:00
                // must not be able to move the verdict a deploy gate reads.
                if (!$converged) {
                    $state = [
                        'last_mode' => $appliedMode,
                        'last_flipped_at' => $convergedAt,
                        'flipped_by' => 'pgcat-flipper',
                        'flipped_via' => 'window',
                    ];
                }

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

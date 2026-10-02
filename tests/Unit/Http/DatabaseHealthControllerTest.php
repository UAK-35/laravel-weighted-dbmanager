<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Http;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
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
                    && str_contains($message, 'writer-only config while a reader window is open')
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
            ->assertJsonPath('reader_window.reason', null);

        Log::shouldNotHaveReceived('error');
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
     */
    private function bindWindowFlipper(?int $bootedSecondsAgo, ?string $appliedMode = null): PgcatConfigFlipper
    {
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
        if ($appliedMode !== null) {
            file_put_contents($config['readers_path'], "pool = 'readers'\n");
            file_put_contents($config['no_readers_path'], "pool = 'writer-only'\n");
            file_put_contents(
                $config['config_path'],
                (string) file_get_contents($appliedMode === 'readers' ? $config['readers_path'] : $config['no_readers_path']),
            );

            if ($bootedSecondsAgo !== null) {
                $convergedAt = gmdate(DATE_ATOM, time() - $bootedSecondsAgo + 60);

                file_put_contents($config['state_file'], (string) json_encode([
                    'last_mode' => $appliedMode,
                    'last_run_at' => $convergedAt,
                    'last_kind' => 'flipped',
                    'runs' => 1,
                    'converged_at' => $convergedAt,
                    'converged_mode' => $appliedMode,
                ]));
            }
        }

        $flipper = new PgcatConfigFlipper(
            resolver: new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            ),
            config: $config,
            stateFile: $config['state_file'],
            lockFile: $config['lock_file'],
            driver: 'pgsql',
        );

        $this->app->instance(PgcatConfigFlipper::class, $flipper);

        return $flipper;
    }
}

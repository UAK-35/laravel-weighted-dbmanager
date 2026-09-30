<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Http\Controllers;

use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\SwitchValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DatabaseHealthController
 *
 * Exposes /health/db that surfaces:
 *   • One real query — `select 1` — run on the connection the package follows
 *     (db-manager.swrr.connection, else database.default), with its latency and
 *     the error when it failed. `status` is `ok` only when that query answered,
 *     so the endpoint can no longer report a healthy database it never asked.
 *   • That same connection's replica weights, share % and health — and no
 *     other connection's. The payload is a report about the connection the
 *     package follows, not about the host application's connection list
 *   • Active SWRR formula
 *   • State-store health (Redis up/down, fallback in use)
 *   • pgcat applicability — the same snapshot db:replica-status and
 *     db:pgcat-flip --status read, so the three cannot disagree: why flipping
 *     is armed or inactive (driver gate included), plus a warning when it is
 *     switched on but cannot act
 *   • the boot audit's standing findings — the settings that read as on but
 *     cannot act, with the sentence the boot log would have carried, so a
 *     misconfiguration is visible on a dashboard instead of only in a log —
 *     and, beside them, what this process sees *now*: which of those settings
 *     still read as on here, which it cannot answer without a probe, and
 *     whether the boot that recorded the finding was running the same
 *     connection and environment this request is
 *   • the container's flip window — when it booted, whether the per-minute
 *     `db:pgcat-flip` run reached a usable mode inside it, and the sentence for
 *     a container that did not. A closed window that never converged makes the
 *     whole payload `degraded`, which is the one failure this endpoint reports
 *     that a query cannot see: pgcat can be answering while the container is
 *     still the wrong shape, and a scheduler that stopped running the flip is
 *     invisible from every other field here
 *
 * EVERY FAILURE IS ALSO LOGGED
 *   A payload nobody is looking at is not a signal. Each response that is not
 *   `ok` writes one error line — the pinned query's error, the replica errors,
 *   and the flip window's verdict — so the failure is in the log with a
 *   timestamp whether or not anyone happened to poll the endpoint at the time.
 *   The line is deliberately the same facts the payload carries, not a summary
 *   of them: an operator greps for the SQLSTATE or the flip reason, and a log
 *   that paraphrased would not match the response they are holding.
 *
 * WHY THE QUERY IS HERE
 *   Every other field of this payload is a reading of configuration or of a state
 *   store, and both can be well while the database is not. The observed failure is
 *   concrete: an endpoint that answered `200 "status":"ok"` with every `replicas`
 *   array empty, because the connection it follows declares no `read` list for the
 *   replica probe to open, while application queries were failing with
 *   `SQLSTATE[08006]`. A Redis state store that answers is not a database that
 *   answers, and a deploy gate promoting on it is promoting on the wrong fact.
 *
 *   The query is deliberately the application's own path — DB::connection($name),
 *   so a read/write connection is read through the weighted factory exactly as a
 *   request would read it — and deliberately trivial: it asserts reachability and
 *   auth, not schema.
 *
 * Register in routes/api.php or routes/web.php:
 *
 *   Route::get('/health/db', [DatabaseHealthController::class, 'index'])
 *        ->middleware('auth.basic');     // protect it!
 *
 * The package extends Illuminate\Routing\Controller rather than the host
 * application's App\Http\Controllers\Controller — index() needs neither the
 * AuthorizesRequests, DispatchesJobs nor ValidatesRequests traits.
 */
class DatabaseHealthController extends \Illuminate\Routing\Controller
{
    /**
     * The one query this endpoint runs. Trivial on purpose: it proves the connection
     * reaches a server and authenticates, and says nothing about schema, which is the
     * health claim an endpoint a load balancer polls is allowed to make.
     */
    private const PINNED_QUERY = 'select 1';

    /**
     * The flipper and the audit are optional so the controller still resolves on an app
     * whose provider is not registered. They are then null, and the payload carries
     * "pgcat": null and "audit": {"available": false} instead of failing to build the
     * route. Both have a default for that reason: the container falls back to it when a
     * dependency cannot be built.
     */
    public function __construct(
        private readonly ?PgcatConfigFlipper $pgcat = null,
        private readonly ?BootAudit $audit = null,
    ) {
    }

    public function index(): JsonResponse
    {
        $manager = DB::getFacadeRoot();

        if (!$manager instanceof WeightedDatabaseManager) {
            return response()->json([
                'status' => 'misconfigured',
                'error' => 'WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.',
            ], 500);
        }

        // The one connection this endpoint reports, resolved once and then used for both halves
        // of the payload — what is summarised and what is queried cannot be two different names.
        //
        // This is `ActiveConnection::resolve()`, the call the pgcat gate, the flipper's snapshot,
        // `db:replica-status` and `db:doctor` make, so the endpoint reports the connection those
        // surfaces judge. It is deliberately *not* every key of `database.connections`: an
        // installation with eight profiles does not have eight weighted connections, and walking
        // that list made the endpoint's status depend on connections the package neither follows
        // nor was asked about — a declared `read` list on any of them was probed with `getPdo()`,
        // and a replica that did not answer there turned a healthy followed connection into a
        // `degraded` payload.
        $pinned = ActiveConnection::resolve(config());

        $replicas = [];
        $errors = [];

        // Nothing is named at all: there is no connection to summarise, and the `pinned` block
        // below reports that as the failure it is. An empty map is the same fact in the shape a
        // reader walks, rather than a row invented for a connection that is not configured.
        if ($pinned['connection'] !== '') {
            // Probe the followed connection's first replica with getPdo() to surface live errors.
            try {
                $config = config("database.connections.{$pinned['connection']}");

                if (is_array($config) && !empty($config['read'])) {
                    // Test the first replica at minimum.
                    DB::connection($pinned['connection'])->getPdo();
                }
            } catch (Throwable $e) {
                $errors[$pinned['connection']] = $e->getMessage();
                $this->markFirstReplicaFailed($manager, $pinned['connection']);
            }

            $replicas[$pinned['connection']] = $manager->healthSummary($pinned['connection']);
        }

        // One real query, on the same connection. This is the field the status is allowed to rest
        // on: the replica summaries above are readings of a state store and of a declared list,
        // and both are well when the database is not.
        $probe = $this->pinnedProbe($pinned);

        // Read once and reported once: the flip verdict is the same snapshot's `window`, so
        // this endpoint cannot summarise one state and judge another.
        $pgcat = $this->pgcat?->healthSummary();
        $flip = $this->flipVerdict($pgcat);

        // pgcat being off is not ill health — on a MySQL connection there is
        // simply nothing to flip — so it does not feed $allHealthy. Neither does the
        // audit: a configuration that cannot act is not a database that cannot answer.
        // `ok` is null when the probe did not run, which is not a failure.
        //
        // The flip verdict does feed it, and only in one direction: a container whose boot
        // window closed without the flip ever converging is a container that cannot be trusted
        // to serve reads, whatever a query happens to answer right now. A window that is merely
        // still open is not a failure — see flipVerdict().
        $allHealthy = empty($errors)
            && $this->allReplicasHealthy($replicas)
            && $probe['ok'] !== false
            && !$flip['failed'];

        if (!$allHealthy) {
            $this->logDegraded($probe, $replicas, $errors, $flip, $pgcat);
        }

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'pinned' => $probe,
            'replicas' => $replicas,
            'pgcat' => $pgcat,
            'flip' => $flip,
            'audit' => $this->audit(),
            'errors' => $errors,
        ], $allHealthy ? 200 : 503);
    }

    /**
     * What this container's flip window says, as one block.
     *
     * `failed` is true in exactly one situation: pgcat applies here, the window has closed, and
     * no flip run reached a usable mode inside it. That is narrow on purpose.
     *
     * - **While the window is open** nothing fails. A container one minute past boot whose flip
     *   has not converged yet is a container starting up, and an endpoint that failed it would
     *   fail every healthy deploy for as long as it takes to come up.
     * - **When pgcat does not apply** nothing fails, and `applicable` says so. On a connection
     *   pgcat cannot front there is no flip to be late, and reporting a closed window as a
     *   failure would break every installation that does not use the feature.
     * - **When no boot time was recorded** nothing fails either: `window.closed` is false
     *   because there is nothing to compare against, and the endpoint stays quiet rather than
     *   inventing a deadline. `window.source` is the field that tells the two apart.
     *
     * The three ways to be `failed` are spelled out in `reason` because they call for different
     * work: a scheduler that never ran the flip, a flip that ran and kept failing, and a flip
     * that only reached a usable mode after the window had closed.
     *
     * @param array<string, mixed>|null $pgcat the summary `index()` already read
     *
     * @return array{applicable: bool, failed: bool, reason: string|null, window: array<string, mixed>|null}
     */
    private function flipVerdict(?array $pgcat): array
    {
        /** @var array<string, mixed>|null $window */
        $window = is_array($pgcat['window'] ?? null) ? $pgcat['window'] : null;
        $applicable = $pgcat !== null && ($pgcat['enabled'] ?? false) === true && $window !== null;

        if (!$applicable) {
            return [
                'applicable' => false,
                'failed' => false,
                'reason' => null,
                'window' => $window,
            ];
        }

        $failed = ($window['failed'] ?? false) === true;

        return [
            'applicable' => true,
            'failed' => $failed,
            'reason' => $failed
                ? (is_string($window['failed_reason'] ?? null) && $window['failed_reason'] !== ''
                    ? $window['failed_reason']
                    : 'the flip did not converge inside this container\'s window')
                : null,
            'window' => $window,
        ];
    }

    /**
     * One error line per unhealthy response, carrying the same facts as the payload.
     *
     * Logged here rather than in the caller: `index()` is the only place that knows the whole
     * verdict, and a failure reported one field at a time by three helpers would be three lines
     * an operator cannot correlate. The context is deliberately flat and named after the payload
     * keys, so a log entry and a response body can be read side by side.
     *
     * The driver's own error text is included unchanged — it is the sentence carrying the
     * SQLSTATE that a search or a deploy gate matches on — and no credentials are added to it.
     * This endpoint returns 503 only for a real failure and is polled by the deploy gate and by
     * people, not by the load balancer, so one line per failure is a log an operator can follow
     * rather than a flood.
     *
     * @param array<string, mixed>            $probe    the `pinned` block of the payload
     * @param array<string, array<string, mixed>> $replicas the `replicas` block of the payload
     * @param array<string, string>           $errors   the `errors` block of the payload
     * @param array<string, mixed>            $flip     the `flip` block of the payload
     * @param array<string, mixed>|null       $pgcat    the `pgcat` block of the payload
     */
    private function logDegraded(array $probe, array $replicas, array $errors, array $flip, ?array $pgcat): void
    {
        $reasons = [];

        if (($probe['ok'] ?? null) === false) {
            $reasons[] = 'pinned query failed';
        }

        if ($errors !== []) {
            $reasons[] = 'replica probe failed';
        }

        if (!$this->allReplicasHealthy($replicas)) {
            $reasons[] = 'a replica is unhealthy';
        }

        if (($flip['failed'] ?? false) === true) {
            $reasons[] = 'the pgcat flip never converged inside the container\'s boot window';
        }

        Log::error('health/db degraded: '.implode('; ', $reasons ?: ['no reason recorded']), [
            'status' => 'degraded',
            'reasons' => $reasons,
            'connection' => $probe['connection'] ?? null,
            'driver' => $probe['driver'] ?? null,
            'source' => $probe['source'] ?? null,
            'query' => $probe['query'] ?? null,
            'query_error' => $probe['error'] ?? null,
            'latency_ms' => $probe['latency_ms'] ?? null,
            'replica_errors' => $errors,
            'pgcat_window' => $pgcat['window'] ?? null,
            'flip_failed' => $flip['failed'] ?? false,
            'flip_reason' => $flip['reason'] ?? null,
        ]);
    }

    /**
     * One real query on the connection the package follows, with its latency and the
     * error when it failed.
     *
     * The target is handed in rather than resolved here: `index()` resolves it once — the same
     * call the pgcat gate, the flipper's snapshot and `db:doctor` make — and reports on that one
     * name, so the connection this probe queries is the connection the payload summarises.
     * Resolving a second time could not pick a *different* name (nothing reconfigures between the
     * two calls), but it would be a second reading of the same rule, which is the thing this
     * package keeps collapsing. The connection is asked for through the facade,
     * so it is the application's own path (the weighted factory for a read/write
     * connection) rather than a private probe of its own: the query fails for the reasons
     * an application query fails, which is the whole point of running it.
     *
     * `swrr.health.pinned_query` turns the query off, and its documented default is on.
     * It exists for the one environment where the probe is not a question worth asking —
     * a suite or a config-less inspection with no database behind the connection at all —
     * not as a way to make an endpoint green. With it off, `checked` is false and `ok` is
     * null, so a reader can tell "no query was run" from "the query passed"; anything that
     * treats this endpoint as a signal must read the flag rather than the status.
     *
     * A value that is not a switch (`'maybe'`) is refused and reported in `refused`,
     * resolving to the documented default — on — because an unreadable value must not be
     * the thing that silences the check.
     *
     * @param array{connection: string, driver: string, source: string} $pinned the resolution `index()` made
     *
     * @return array{
     *   connection: string,
     *   driver: string,
     *   source: string,
     *   query: string,
     *   checked: bool,
     *   refused: string|null,
     *   ok: bool|null,
     *   latency_ms: float|null,
     *   error: string|null,
     * }
     */
    private function pinnedProbe(array $pinned): array
    {
        $switch = SwitchValue::read(config('db-manager.swrr.health.pinned_query'), true);

        $probe = [
            'connection' => $pinned['connection'],
            'driver' => $pinned['driver'],
            'source' => $pinned['source'],
            'query' => self::PINNED_QUERY,
            'checked' => false,
            'refused' => $switch['refused'],
            'ok' => null,
            'latency_ms' => null,
            'error' => null,
        ];

        if (!$switch['on']) {
            return $probe;
        }

        $probe['checked'] = true;

        // No connection is named at all. Reported as the failure it is rather than as an
        // absent check: a name the package cannot resolve is a database nothing can query.
        if ($probe['connection'] === '') {
            $probe['ok'] = false;
            $probe['error'] = 'no connection is pinned: db-manager.swrr.connection is unset and database.default is empty';

            return $probe;
        }

        $startedAt = hrtime(true);

        try {
            DB::connection($probe['connection'])->select(self::PINNED_QUERY);
            $probe['ok'] = true;
        } catch (Throwable $e) {
            $probe['ok'] = false;

            // The driver's own words, unedited: the sentence an operator pastes into a search,
            // and for PostgreSQL the one carrying the SQLSTATE the deploy gate matches on. It
            // is the only field here that could carry a credential, and it is reported as the
            // driver wrote it rather than reworded.
            $probe['error'] = $e->getMessage();
        }

        $probe['latency_ms'] = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        return $probe;
    }

    /**
     * The settings that read as on but cannot act, as the boot audit remembers them.
     *
     * This is deliberately *not* part of the status code. The findings describe a
     * configuration that cannot do what it claims, which is not the same claim as
     * "this database is unreachable right now", and an endpoint a load balancer polls
     * should keep meaning the second one. Reporting them here is what makes them
     * visible on a dashboard at all: the alternative is a boot log — one line per
     * process — and a warning nobody ever greps for.
     *
     * The block itself is `BootAudit::reported()`, which the status command renders too: it is
     * the one place either surface decides whether there is a record to read, so a payload and
     * a terminal cannot disagree about that — the field meanings, including the two ways
     * `available` can be false, are documented there rather than here. This method is
     * the endpoint's half: it embeds the block and leaves `status` alone.
     *
     * The block carries two readings of the same settings, and both are deliberate. `findings`
     * is the record — what this installation has been claiming, dated from when it first said
     * it — and `current` is the live reading taken while building this response, with each
     * recorded finding annotated by whether it still stands here, whether it could be
     * evaluated here at all, and whether the scope it was recorded in is the scope this
     * process resolved. A finding written by a boot on another connection or another
     * environment is therefore visible as exactly that, instead of reading as a problem the
     * instance answering the request has.
     *
     * @return array{available: bool, count: int, severity: string, counts: array{error: int, warning: int}, oldest: string|null, findings: list<array<string, mixed>>, error: string|null, checked_at: string|null, scope: array<string, mixed>|null, current: array<string, mixed>}
     */
    private function audit(): array
    {
        return BootAudit::reported($this->audit);
    }

    private function markFirstReplicaFailed(WeightedDatabaseManager $manager, string $connection): void
    {
        $config = config("database.connections.{$connection}");

        if (!is_array($config)) {
            return;
        }

        $read = $config['read'] ?? [];
        $first = is_array($read) ? ($read[0] ?? null) : null;

        if (!is_array($first)) {
            return;
        }

        $host = $first['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        if (is_string($host) && $host !== '') {
            $manager->markReplicaFailed($host, ConfigValue::int($first['port'] ?? null, 5432), 'health probe');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $summaries One healthSummary() result per connection.
     */
    private function allReplicasHealthy(array $summaries): bool
    {
        foreach ($summaries as $summary) {
            $replicas = $summary['replicas'] ?? [];

            foreach (is_array($replicas) ? $replicas : [] as $replica) {
                if (is_array($replica) && !($replica['healthy'] ?? false)) {
                    return false;
                }
            }

            if (!($summary['store_healthy'] ?? true)) {
                return false;
            }
        }

        return true;
    }
}

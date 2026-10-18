<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Http\Controllers;

use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\PinnedQuery;
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
 *   • whether the per-minute entry is still *producing runs* — `schedule`, the beat it
 *     leaves in the state file on every run and the age of that beat against the
 *     tolerance `swrr.pgcat.schedule.stale_after_missed_runs` derives from the cadence.
 *     This is the boot window's blind spot, and the reason it has to be asked
 *     separately: the window above answers once, eight minutes after boot, and the
 *     entry is a deliberate no-op afterwards — so a container whose cron died an hour
 *     later reads converged for the rest of its life. A beat that has stopped makes
 *     the payload `degraded`, and a container that has never produced one is reported
 *     the same way rather than as silence — see scheduleVerdict(). Beside the verdict it
 *     carries `causes`, the three readings `db:scheduler-watchdog` reports — the mutex
 *     store (named, not asked), the entry count and the boot stamp — read through
 *     `ScheduleCauses`, so the monitor and the healthcheck explain one silence with the
 *     same sentences — see scheduleCauses()
 *   • whether that pooler is still in step with the reader windows that move the
 *     mode every day — `reader_window`, which holds what the windows ask for right
 *     now beside what pgcat's own file actually is. The boot window above can only
 *     say that a container came up once; a pooler left on the writer-only config
 *     through an open reader window serves every read from the writer, and one
 *     left on the readers config outside a window sends every read through it to
 *     a replica — no passing query says so about either. It makes the payload
 *     `degraded` too — see readerWindowVerdict()
 *   • whether the pooler supervisord calls RUNNING is answerable for the pools the
 *     configuration declares — `pool_probe`, the one field that asks the pooler
 *     itself rather than reading a file: one startup packet per declared pool, no
 *     credentials, and only when `swrr.pgcat.pool_probe.enabled` has armed it. A
 *     pooler that holds no pool for a database the application is connected to
 *     fails every read to it while `supervisorctl status` reports the program
 *     healthy, and no other field here can see the difference. A pool the pooler
 *     refuses makes the payload `degraded`; a pooler that answered nothing at all
 *     is reported (`warned`) and never failed — see poolProbeVerdict(). The block
 *     carries `address` beside the answers: where the packets actually went, read
 *     off the probe's own answer, because a probe aimed at the wrong port reports
 *     the same silence as a pooler that is down and the pool names alone cannot
 *     tell those apart
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

        // The other half of the pgcat verdict, and the half the boot window cannot state: with
        // the mode still moving at every window boundary, a pooler that stopped tracking them
        // is the failure that outlives a successful boot.
        $readerWindow = $this->readerWindowVerdict($pgcat);

        // And the half neither of the two can state: whether the entry that moves the mode at all
        // is still being run. The window stops judging eight minutes after boot and the reader
        // windows speak again only at a boundary, so a cron that died in between is a container
        // that is converged, in step, and has nothing coming.
        $schedule = $this->scheduleVerdict($pgcat);

        // The third reading, and the only one that asks the pooler anything: a program can be
        // RUNNING, hold the right file, and still have no pool for a database the application is
        // connected to — every read to it fails while supervisord calls the pooler healthy. Off by
        // default, so an installation that has not armed the probe pays nothing for this field.
        $poolProbe = $this->poolProbeVerdict($this->pgcat);

        // The one reading in this payload that warns rather than fails: a repair is the self-heal
        // working, so a pooler that keeps needing one is a problem for a person before it is an
        // outage for the installation. The count alone cannot tell the two apart — one repair in a
        // boot window and a pooler repaired on every run read the same — so the rate is the fact
        // this block exists for, and it is deliberately not folded into $allHealthy: the status
        // stays with the blocks that answer "is this installation serving now".
        $poolRepairs = $this->poolRepairVerdict($this->pgcat);

        // pgcat being off is not ill health — on a MySQL connection there is
        // simply nothing to flip — so it does not feed $allHealthy. Neither does the
        // audit: a configuration that cannot act is not a database that cannot answer.
        // `ok` is null when the probe did not run, which is not a failure.
        //
        // The flip verdict does feed it, and only in one direction: a container whose boot
        // window closed without the flip ever converging is a container that cannot be trusted
        // to serve reads, whatever a query happens to answer right now. A window that is merely
        // still open is not a failure — see flipVerdict().
        //
        // So does the reader-window verdict, for the same reason one step later: a pooler on the
        // writer-only config inside an open reader window is answering every read itself, so the
        // database can answer and still not be the one the configuration asked for. Neither
        // verdict is ever set while the container is starting, so a healthy deploy is unaffected.
        // ...and so does the pool-probe verdict, for the reason the repair exists: a pool the
        // pooler does not hold cannot be served, so the database can answer `select 1` and still
        // not be the one the configuration asked for. Only `failed` is folded — a pooler that
        // answered nothing is a warning, never a degraded status, so a probe on the wrong address
        // cannot take a healthy installation down. Neither verdict is ever set while the container
        // is starting (both wait for the boot window to close), so a healthy deploy is unaffected.
        $allHealthy = empty($errors)
            && $this->allReplicasHealthy($replicas)
            && $probe['ok'] !== false
            && !$flip['failed']
            && !$schedule['failed']
            && !$readerWindow['failed']
            && !$poolProbe['failed'];

        if (!$allHealthy) {
            $this->logDegraded($probe, $replicas, $errors, $flip, $schedule, $readerWindow, $poolProbe, $pgcat);
        }

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'pinned' => $probe,
            'replicas' => $replicas,
            'pgcat' => $pgcat,
            'flip' => $flip,
            'schedule' => $schedule,
            'reader_window' => $readerWindow,
            'pool_probe' => $poolProbe,
            'pool_repairs' => $poolRepairs,
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
     * Whether the schedule that runs the per-minute flip is still producing runs.
     *
     * The boot window's blind spot, and the shape of an incident rather than a theory: the window
     * judges a container's first eight minutes and then stops, because the per-minute entry is a
     * deliberate no-op after it, so a container that converged and then lost its cron line reads
     * converged for the rest of its life. That was 85 minutes on the deployment this was written
     * for, and every other field of this payload read `ok` throughout: the query answered, the
     * replicas were healthy, the window had closed with a convergence inside it, and the last mode a
     * flip recorded was the right one because nothing had moved to displace it.
     *
     * `db:pgcat-flip` stamps a beat in the state file on every run — including the runs that decide
     * nothing — and the flipper compares its age with the tolerance the cadence defines. Three states
     * come back and only the third is a failure:
     *
     * - **Not applicable**: the per-minute entry is not scheduled, or the tolerance is switched off.
     *   Nothing writes a beat or ages one, and `not_judged` says which.
     * - **Not judged**: the container is still inside its boot window, or has no boot stamp at all.
     *   Both are the line the window itself draws — a starting container is not a stopped schedule.
     * - **Stale**: the beat is older than the tolerance, *or* there has never been one since the
     *   window closed. The second is the same fault one interval earlier, and reporting it as a
     *   failure is what keeps a health check from passing while nothing has ever run the entry.
     *
     * Deliberately not folded in from the boot window's verdict, which fails in part of that case
     * too: the two answer different questions about one silence — did this container ever converge,
     * and is anything still running — and a container can fail either without the other.
     *
     * `causes` is the other half of the same answer, and the half the state file cannot show: the
     * three readings `db:scheduler-watchdog` publishes beside its verdict, read here through the
     * same object so a monitor and a healthcheck cannot describe one silence two ways. They are
     * readings rather than verdicts — `failed` and the status stay the beat's — and they are empty
     * for an installation whose entry is not scheduled, which is also the one case where reading
     * them resolves no schedule.
     *
     * @param array<string, mixed>|null $pgcat the summary `index()` already read
     *
     * @return array{applicable: bool, failed: bool, reason: string|null, not_judged: string|null, last_beat_at: string|null, age_seconds: int|null, stale_after_seconds: int, causes: list<array{key: string, reading: string}>}
     */
    private function scheduleVerdict(?array $pgcat): array
    {
        /** @var array<string, mixed>|null $schedule */
        $schedule = is_array($pgcat['schedule'] ?? null) ? $pgcat['schedule'] : null;
        $applicable = $pgcat !== null && ($schedule['applicable'] ?? false) === true;

        return [
            'applicable' => $applicable,
            'failed' => $applicable && ($schedule['stale'] ?? null) === true,
            // The flipper's own sentence, not a second copy of it: it names the age, the cadence
            // the beat is late against and the repair, and this endpoint, `db:doctor`'s row and
            // `db:scheduler-watchdog` all print the one string.
            'reason' => $applicable && is_string($schedule['reason'] ?? null) && $schedule['reason'] !== ''
                ? $schedule['reason']
                : null,
            // Why nothing was judged, when nothing was: emitting nothing at all is what this
            // endpoint did before and it reads as a pass, so the sentence is published even though
            // no verdict rests on it.
            'not_judged' => is_string($schedule['not_judged'] ?? null) ? $schedule['not_judged'] : null,
            'last_beat_at' => is_string($schedule['last_beat_at'] ?? null) ? $schedule['last_beat_at'] : null,
            'age_seconds' => is_int($schedule['age_seconds'] ?? null) ? $schedule['age_seconds'] : null,
            'stale_after_seconds' => is_int($schedule['stale_after_seconds'] ?? null)
                ? $schedule['stale_after_seconds']
                : 0,
            'causes' => $this->scheduleCauses(),
        ];
    }

    /**
     * The readings beside the schedule verdict — the same three `db:scheduler-watchdog` publishes.
     *
     * A silent schedule has causes the state file cannot show: the store `withoutOverlapping()`
     * reaches *before* the entry runs, how many entries on the built schedule run the flip, and the
     * boot stamp the window is measured from. The command reports them; until now the endpoint did
     * not, so a monitor reading `schedule.failed` and an operator reading the command's report could
     * explain one silence with different evidence — or the monitor could explain it with none. Both
     * now read the same object, so the sentences are the same ones, not two renderings of one rule.
     *
     * The store is **named and not asked**. This endpoint is polled by a monitor, and a surface that
     * answers on a cadence cannot afford a cache whose connect can hang: the reading says which run
     * was going to ask it (`db:doctor`'s `schedule beat` row), so the omission is a fact in the
     * payload rather than a silence in it. The other two readings cost one count of the schedule
     * this process builds and two filesystem facts.
     *
     * The entry count is why the schedule is resolved at all — and only when the entry is armed.
     * `ScheduleCauses` reads nothing for an installation that never scheduled it, which is also the
     * property that keeps this endpoint from handing such an installation an entry it did not ask
     * for: resolving the schedule is what registers one.
     *
     * @return list<array{key: string, reading: string}>
     */
    private function scheduleCauses(): array
    {
        if ($this->pgcat === null) {
            return [];
        }

        $entry = FlipSchedule::options(config());

        return ScheduleCauses::fromContainer(
            app(),
            $entry['enabled'],
            $entry['name'],
            $this->pgcat->window()->bootFile(),
            ScheduleCauses::UNASKED_WHERE_ANSWERING_MUST_NOT_WAIT,
        )->readings();
    }

    /**
     * Whether the pooler is on the configuration the reader windows ask for right now.
     *
     * This is the check the boot window cannot make, and the one the 2026-10-02 container needed:
     * a task booted outside a reader window converges to the writer-only config and looks perfect
     * — one flip, one record, `window.failed: false` — until 10:00 arrives, the windows move the
     * resolver to `readers`, and nothing moves pgcat with it. Every read then reaches the writer
     * while the payload says `ok`, because a pooler that answers is not a pooler that is right.
     *
     * The verdict comes from the flipper, which reads it off pgcat's own file rather than off its
     * recorded mode — a flip that never got as far as recording anything still left a file, and
     * that is exactly the case this exists for. Three states are told apart here:
     *
     * - **Not applicable**: the flipper is inert (nothing was asked of the pooler) or the resolver
     *   is permissive (no window is configured, so there is no window to be inside of). Nothing
     *   fails, and `applicable` says so — the same line `flipVerdict()` draws for a connection
     *   pgcat cannot front.
     * - **Applicable and in step**: `failed` is false whether the modes agree or the file could
     *   not be compared at all, and `applied` carries which of the two it was (`null` for the
     *   second), so an unreadable file is "not judged" rather than "passed".
     * - **Applicable and out of step**: pgcat's file holds the variant the windows are not asking
     *   for — the writer-only config while a reader window is open, or the readers config outside
     *   one. `reason` carries the sentence for it, naming the file in place and the command that
     *   moves it.
     *
     * Both directions are `failed`, and it is one fault rather than two: the file and the windows
     * are two statements about one routing decision, and a stale one is stale whichever way round
     * the disagreement is. Readers left in the file outside a window are the same evidence as a
     * writer-only file inside one — the boundary flip that was asked for and never landed.
     *
     * @param array<string, mixed>|null $pgcat the summary `index()` already read
     *
     * @return array{applicable: bool, failed: bool, reason: string|null, expected: string|null, applied: string|null}
     */
    private function readerWindowVerdict(?array $pgcat): array
    {
        /** @var array<string, mixed>|null $block */
        $block = is_array($pgcat['reader_window'] ?? null) ? $pgcat['reader_window'] : null;

        $failed = ($block['failed'] ?? false) === true;

        return [
            'applicable' => $block !== null,
            'failed' => $failed,
            'reason' => $failed
                ? (is_string($block['reason'] ?? null) && $block['reason'] !== ''
                    ? $block['reason']
                    : 'pgcat is on a configuration the reader windows are not asking for')
                : null,
            'expected' => is_string($block['expected'] ?? null) ? $block['expected'] : null,
            'applied' => is_string($block['applied'] ?? null) ? $block['applied'] : null,
        ];
    }

    /**
     * Whether the running pooler is answerable for the pools the configuration declares.
     *
     * The reading itself is the flipper's — `PgcatConfigFlipper::poolProbeVerdict()` — so this
     * endpoint and `db:doctor`'s `pool probe` row cannot disagree about what the pooler holds, and
     * the sentence `reason` carries is the same one the row prints. What this helper adds is the
     * one case the flipper does not have to know about: an installation with no pgcat at all, where
     * `enabled` is false and nothing was asked. That is the same line `flipVerdict()` draws for a
     * connection pgcat cannot front — on a MySQL connection there is no pooler to probe, and an
     * endpoint that reported a fault for that would be broken for everyone not using the feature.
     *
     * The block is published whole rather than reduced to a boolean, because the three answers are
     * the pooler's own and they call for different work: `pools` carries one entry per pool the
     * configuration declares, with `answered`/`missing`/`silent` and the sentence the probe
     * produced; `answered` is true when every one of them answered and false when one did not;
     * `failed` marks the pool the pooler refused to serve, which is the one the flip repairs; and
     * `warned` marks a pooler that said nothing at all, which is reported and never repaired. Both
     * judgements wait for the container's boot window to close, so a starting container is not
     * failed by this block, and `reason` names what the pooler said either way.
     *
     * `discriminates` and `control` are the caveat that keeps an `answered` pool from reading as
     * proof on a pooler that authenticates before it resolves a pool. The sweep asks one control
     * name no configuration declares: refused, the pooler reads pools first and an `answered` pool
     * exists; answered, the pooler answers every name and the pools could not be proved; `control`
     * is that exchange, or null when a missing pool already proved it.
     *
     * This is the one field of the payload that opens a socket — one startup packet per declared
     * pool, bounded by `swrr.pgcat.pool_probe.timeout_ms` — and it does so only when the probe has
     * been armed, so the cost of the endpoint is unchanged for every installation that has not.
     *
     * @param PgcatConfigFlipper|null $pgcat the flipper this endpoint was built with
     *
     * @return array{enabled: bool, applicable: bool, answered: bool|null, discriminates: bool|null, control: array{database: string, status: string, evidence: string}|null, failed: bool, warned: bool, pools: list<array{database: string, status: string, evidence: string}>, reason: string|null}
     */
    private function poolProbeVerdict(?PgcatConfigFlipper $pgcat): array
    {
        if ($pgcat === null) {
            return [
                'enabled' => false,
                'applicable' => false,
                'answered' => null,
                'discriminates' => null,
                'control' => null,
                'failed' => false,
                'warned' => false,
                'pools' => [],
                'reason' => null,
            ];
        }

        return $pgcat->poolProbeVerdict();
    }

    /**
     * Whether this container has been repairing its pooler faster than the configured threshold.
     *
     * The count `db:pgcat-flip --status` prints is a fact about the container's life, and the
     * question a monitor has is narrower: is the pooler being repaired again and again right now.
     * One repair inside a boot window is the self-heal doing its job, and the same code path run on
     * every poll is a pooler that keeps refusing the pools the file declares — the difference is a
     * rate, so the block publishes the repairs inside `swrr.pgcat.pool_repairs.window_seconds`
     * beside the threshold and says `warned` when the count is past it. `in_window` is `null` and
     * `judged` is false for a state file written before repairs were dated: it carries a count and
     * no times, and reading that as "none recently" would be the most comfortable answer and the
     * wrong one.
     *
     * The verdict is the flipper's, from the state file the flip writes, so this block and
     * `db:doctor`'s `pool repairs` row count one container's repairs the same way — the row reports
     * the count and the time, this reports the rate, and neither reads the file itself.
     *
     * Warns rather than fails, and moves no status code: a repair is the self-heal working, and the
     * blocks that fail this endpoint are the ones whose fault is standing now — `pool_probe` asks
     * the pooler at this moment, where this reads what happened to it.
     *
     * @param PgcatConfigFlipper|null $pgcat the flipper this endpoint was built with
     *
     * @return array{applicable: bool, judged: bool, warned: bool, window_seconds: int, max_repairs: int, count: int, in_window: int|null, last_at: string|null, reason: string|null}
     */
    private function poolRepairVerdict(?PgcatConfigFlipper $pgcat): array
    {
        if ($pgcat === null) {
            return [
                'applicable' => false,
                'judged' => false,
                'warned' => false,
                'window_seconds' => PgcatConfigFlipper::DEFAULT_REPAIR_WINDOW_SECONDS,
                'max_repairs' => PgcatConfigFlipper::DEFAULT_MAX_REPAIRS_PER_WINDOW,
                'count' => 0,
                'in_window' => null,
                'last_at' => null,
                'reason' => null,
            ];
        }

        return [
            'applicable' => true,
            ...$pgcat->poolRepairRate(),
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
     * @param array<string, mixed>            $schedule the `schedule` block of the payload
     * @param array<string, mixed>            $readerWindow the `reader_window` block of the payload
     * @param array<string, mixed>            $poolProbe the `pool_probe` block of the payload
     * @param array<string, mixed>|null       $pgcat    the `pgcat` block of the payload
     */
    private function logDegraded(array $probe, array $replicas, array $errors, array $flip, array $schedule, array $readerWindow, array $poolProbe, ?array $pgcat): void
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

        if (($schedule['failed'] ?? false) === true) {
            $reasons[] = 'the schedule has stopped producing runs of the per-minute flip';
        }

        if (($readerWindow['failed'] ?? false) === true) {
            $reasons[] = 'pgcat is out of step with the reader windows';
        }

        if (($poolProbe['failed'] ?? false) === true) {
            $reasons[] = 'the pooler is not answerable for a pool the configuration declares';
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
            'schedule' => $schedule,
            'reader_window' => $readerWindow,
            'pool_probe' => $poolProbe,
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
     * The statement itself is `Support\PinnedQuery`'s, which `db:doctor --real-test` asks as well:
     * two surfaces that each claim to have proved the connection run the one query, through the one
     * path, and report the driver's own words when it fails — so an endpoint answering `ok` and a
     * preflight refusing the same installation cannot both be right about their own reading.
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
            'query' => PinnedQuery::QUERY,
            'checked' => false,
            'refused' => $switch['refused'],
            'ok' => null,
            'latency_ms' => null,
            'error' => null,
        ];

        if (!$switch['on']) {
            return $probe;
        }

        $asked = PinnedQuery::run($pinned['connection']);

        $probe['checked'] = $asked['checked'];
        $probe['ok'] = $asked['ok'];
        $probe['latency_ms'] = $asked['latency_ms'];
        $probe['error'] = $asked['error'];

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

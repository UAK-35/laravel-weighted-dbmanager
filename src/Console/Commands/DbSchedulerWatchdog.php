<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleBeat;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;

/**
 * php artisan db:scheduler-watchdog {--strict} {--json}
 *
 * One question, one exit code: has anything run this package's per-minute entry lately?
 *
 *   # docker-compose.yml, Kubernetes, ECS — wherever the container is asked whether it is well
 *   healthcheck:
 *     test: ["CMD", "php", "artisan", "db:scheduler-watchdog", "--strict"]
 *     interval: 1m
 *     retries: 2
 *
 * WHY A COMMAND OF ITS OWN
 * ------------------------
 *   Every other surface that can see this is either too expensive or too indirect to be a
 *   healthcheck. `db:doctor` answers it and answers nine other questions with it — it probes
 *   replicas and reaches for the store, so a container whose schedule is fine and whose store is
 *   unreachable would be restarted for the wrong reason. `/health/db` answers it as well, and it is
 *   the right surface for the machine *outside* the container; it is the wrong one for the
 *   healthcheck *inside* it, which would have to guess its own port, make an HTTP request to the
 *   application, and parse a JSON body to learn a fact an exit code could have carried. And
 *   `db:pgcat-flip` is the entry itself: running it to see whether it runs is a watchdog watching
 *   its own beat, because a hand run stamps the beat like any other — see `ScheduleBeat`.
 *
 *   So this asks the one question, and it does no work to ask it: no query, no probe, no socket, no
 *   lock, no write. It reads the state file the flip already wrote and compares two numbers — plus
 *   the readings below, which cost two filesystem facts and one count of the schedule it built in
 *   memory. The one reading that would leave the process, the store the runs' mutexes live in, is
 *   deliberately left to `db:doctor`: naming a store whose connect can hang is how a container's
 *   healthcheck comes to depend on its cache.
 *
 * WHAT IT EXITS WITH
 * ------------------
 *   `1` when the schedule has stopped, and otherwise `0` — but every route says in words *why* it
 *   is not a failure, because an exit code alone cannot tell "nothing is being watched" from
 *   "watched, and it is well", and only one of those two is reassuring. `--strict` is the one
 *   exception, and it is the same rule `db:doctor --strict` applies to the one route that is a
 *   *setting* rather than a state: an installation that has switched the watch off
 *   (`swrr.pgcat.schedule.stale_after_missed_runs = 0`) is warned about and exits `0` by default,
 *   and a healthcheck that would rather be restarted than run unwatched passes `--strict` and gets
 *   `1`. Without that flag the setting that makes a dead cron invisible would also make the command
 *   that exists to notice one permanently pass.
 *
 * WHY THE VERDICT IS NOT COMPUTED HERE
 * ------------------------------------
 *   The tolerance, the four silences, the age and both sentences come from
 *   {@see PgcatConfigFlipper::scheduleStatus()}, which is the block `/health/db` publishes and the
 *   row `db:doctor` prints. Nothing in this file measures anything but which verdict came back: a
 *   command that aged the beat itself would be a fourth answer to one question, and the four would
 *   disagree the first time one of them was repaired.
 *
 *   The one thing read here that the flipper does not put in its verdict is the two settings the
 *   bound is derived from — the cadence and the count of missed runs — and they are read for the
 *   report rather than for the judgement, the way `db:doctor`'s row reads them: a consumer that has
 *   to explain a `stale` should not have to go and read a configuration file to say how late is
 *   late.
 *
 * WHAT THE VERDICT CANNOT SEE
 * ---------------------------
 *   `stale` says the entry has stopped, and its sentence names the cause that is nearly always the
 *   right one: nothing is running the schedule. Three other causes look identical from the state
 *   file, and they are read beside the verdict rather than inside it — {@see ScheduleCauses}: the
 *   store the runs' overlap mutexes live in, how many entries on the built schedule run this
 *   package's command, and whether the file the boot window is measured from can be read. Each is a
 *   *reading* rather than a fourth verdict, so the exit code is still the beat's alone, and an
 *   installation whose entry is not scheduled reads nothing at all.
 *
 *   Two of the three are answered here outright; the store is named and its reachability is left to
 *   `db:doctor`, for the reason above — the reading says which run was going to ask it, so the
 *   omission is a fact in the report rather than a silence in it.
 *
 *   The readings reach both channels, each in the shape its reader wants: the object carries them
 *   as `causes`, one object per reading, and the rendered line carries them as one sentence after
 *   the verdict's, because a healthcheck's output is what a person reads after the container was
 *   restarted. The `reason` is the flipper's sentence in both channels — the readings are not part
 *   of the verdict, and a consumer that reads one sentence should not have to parse three facts out
 *   of it to reach the one that says what happened.
 *
 * JSON
 * ----
 * `--json` writes one object and nothing else — the envelope `db:pgcat-flip` and
 * `db:probe-replicas` write — so a job that gates on the beat and a job that gates on a flip read
 * their reports the same way. The evidence is the beat, the bound it is judged against, and the two
 * settings the bound came from.
 */
class DbSchedulerWatchdog extends Command
{
    /** The entry is producing runs: the last beat is inside the tolerance. */
    public const KIND_RUNNING = 'running';

    /** The entry has stopped producing runs — the fault this command exists to announce. */
    public const KIND_STALE = 'stale';

    /** The per-minute entry is not scheduled, so nothing stamps a beat and there is nothing to age. */
    public const KIND_NOT_SCHEDULED = 'not_scheduled';

    /** The watch is switched off by `swrr.pgcat.schedule.stale_after_missed_runs`. */
    public const KIND_WATCH_OFF = 'watch_off';

    /** The container is inside its boot window, or was never stamped — too early to judge. */
    public const KIND_NOT_JUDGED = 'not_judged';

    /** The container has no flipper, so the schedule it registers cannot be read. */
    public const KIND_UNBOUND = 'unbound';

    /**
     * The evidence a report carries, and the value an absent one takes.
     *
     * All six are present on every route, including the one that never found a flipper — the rule
     * the envelope states, and the reason the object's shape does not depend on the answer: the
     * beat and the bound it is judged against are what separates "five minutes late against a
     * five-minute tolerance" from "five minutes late against an hour's", and a job that had to
     * check whether they were there would be a job that could be written wrong once and stay wrong.
     *
     * `causes` is the one entry whose absent value is a list rather than `null`: an installation
     * whose entry is not scheduled has no readings, and a job that iterated the key should find it
     * empty rather than missing. See {@see ScheduleCauses}.
     */
    private const EVIDENCE = [
        'last_beat_at' => null,
        'age_seconds' => null,
        'stale_after_seconds' => 0,
        'missed_runs' => null,
        'interval_minutes' => null,
        'causes' => [],
    ];

    protected $signature = 'db:scheduler-watchdog
                            {--strict : Fail on the warning too — an installation that has switched the watch off exits 0 by default and 1 with this flag}
                            {--json : Print one JSON object — the verdict, the exit code and the beat — instead of the rendered line}';

    protected $description = 'Fail when the Laravel scheduler has stopped running the per-minute flip';

    public function handle(): int
    {
        $flipper = $this->laravel->bound(PgcatConfigFlipper::class)
            ? $this->laravel->make(PgcatConfigFlipper::class)
            : null;

        if (! $flipper instanceof PgcatConfigFlipper) {
            return $this->verdict(
                self::KIND_UNBOUND,
                self::FAILURE,
                'PgcatConfigFlipper is not registered, so the schedule cannot be watched — check '
                .'WeightedDatabaseServiceProvider',
                self::EVIDENCE,
            );
        }

        $config = $this->laravel->make(Repository::class);
        $entry = FlipSchedule::options($config);
        $missed = ScheduleBeat::missedRuns($config->get('db-manager.swrr.pgcat.schedule'));
        $verdict = $flipper->scheduleStatus();

        // The three causes the verdict cannot see, read off the entry's own settings and the window
        // the flipper was built with — not off this file's own reading of the configuration, so a
        // container whose flipper follows another connection still gets the readings that belong to
        // the schedule it is watching. Empty when the entry is not scheduled: see ScheduleCauses.
        //
        // The store is named and not asked, which is the one reading this surface deliberately
        // leaves half-taken: a check that runs every minute answers from the state file, asks
        // nothing that can time out, and a store whose connection hangs must not be what a container
        // healthcheck is waiting on. It says so rather than leaving the question silent, and
        // `db:doctor` is where the answer is taken.
        $causes = ScheduleCauses::fromContainer(
            $this->laravel,
            $entry['enabled'],
            $entry['name'],
            $flipper->window()->bootFile(),
            ScheduleCauses::UNASKED_WHERE_ANSWERING_MUST_NOT_WAIT,
        );

        // Gathered once, for every route: a route that reported its verdict without the beat would
        // make the shape of the object depend on which route produced it.
        $evidence = [
            'last_beat_at' => is_string($verdict['last_beat_at'] ?? null) ? $verdict['last_beat_at'] : null,
            'age_seconds' => is_int($verdict['age_seconds'] ?? null) ? $verdict['age_seconds'] : null,
            'stale_after_seconds' => (int) $verdict['stale_after_seconds'],
            'missed_runs' => $missed,
            'interval_minutes' => $entry['interval_minutes'],
            'causes' => $causes->readings(),
        ];

        // The order is the order of the questions, and the first one that answers wins. Staleness is
        // asked first, and not only when the entry is still scheduled: an installation whose entry
        // ran until somebody switched it off has a beat going stale with nothing left to refresh it,
        // which is a schedule that stopped whichever way it stopped.
        if (($verdict['stale'] ?? null) === true) {
            return $this->verdict(
                self::KIND_STALE,
                self::FAILURE,
                (string) $verdict['reason'],
                $evidence,
                $causes,
            );
        }

        if (! $entry['enabled']) {
            return $this->verdict(
                self::KIND_NOT_SCHEDULED,
                self::SUCCESS,
                (string) $verdict['not_judged'],
                $evidence,
                $causes,
            );
        }

        if ($missed <= 0) {
            return $this->verdict(
                self::KIND_WATCH_OFF,
                $this->option('strict') ? self::FAILURE : self::SUCCESS,
                'the beat is written and never aged: the watch is switched off '
                .'(swrr.pgcat.schedule.stale_after_missed_runs = '.$missed.'), so the schedule below '
                .'could stop and this command would keep exiting 0',
                $evidence,
                $causes,
            );
        }

        // The three silences the flipper names: a container still inside its boot window, one whose
        // boot nobody stamped, and an entry that is not scheduled. `not_judged` is the field that
        // carries them — `stale` is `null` there, which is also what a schedule that is *working*
        // leaves, so the sentence rather than the flag is what tells the two apart.
        if (is_string($verdict['not_judged'] ?? null)) {
            return $this->verdict(
                self::KIND_NOT_JUDGED,
                self::SUCCESS,
                (string) $verdict['not_judged'],
                $evidence,
                $causes,
            );
        }

        return $this->verdict(
            self::KIND_RUNNING,
            self::SUCCESS,
            sprintf(
                '%s last ran %s ago, and the tolerance is %d missed run(s) at a cadence of %s',
                FlipSchedule::COMMAND,
                ScheduleBeat::ageText((int) ($evidence['age_seconds'] ?? 0)),
                $missed,
                $entry['interval_minutes'] === 1 ? '1 minute' : $entry['interval_minutes'].' minutes',
            ),
            $evidence,
            $causes,
        );
    }

    /**
     * One verdict, reported: the JSON object when `--json` asked for it, the sentence otherwise, and
     * the same exit code down both channels — which is the whole contract of this command, so it is
     * written once rather than per route.
     *
     * The sentence goes down the channel its verdict deserves, because a healthcheck's output is
     * read by a person after the container was restarted and a reader should be able to tell a fault
     * from a note without knowing which code the command returned. `error` is the fault, `warn` is
     * the setting that turned the watch off, and the three silences that are not failures are plain
     * lines.
     *
     * The causes go on the rendered line and not into `reason`, which is the one place the two
     * channels deliberately differ: the object carries them as their own key, one entry per reading,
     * and a reason that also spelled them out would make every consumer parse three facts out of one
     * sentence to reach the one that says what happened. A reader of the line gets both, because
     * that is the reader with no selector to reach for.
     *
     * @param array{last_beat_at: string|null, age_seconds: int|null, stale_after_seconds: int, missed_runs: int|null, interval_minutes: int|null, causes: list<array{key: string, reading: string}>} $evidence
     * @param ScheduleCauses|null $causes the readings beside the verdict, or null on a route that read none
     */
    private function verdict(string $kind, int $exitCode, string $sentence, array $evidence, ?ScheduleCauses $causes = null): int
    {
        if ($this->option('json')) {
            return JsonEnvelope::write(
                $this->output,
                'db:scheduler-watchdog',
                self::EVIDENCE,
                $evidence + ['kind' => $kind, 'reason' => $sentence],
                $exitCode,
            );
        }

        $rendered = ucfirst(rtrim($sentence, '.')).'.';

        if ($causes !== null && ($readings = $causes->sentence()) !== '') {
            $rendered .= ' '.$readings;
        }

        match ($kind) {
            self::KIND_STALE, self::KIND_UNBOUND => $this->error($rendered),
            self::KIND_WATCH_OFF => $this->warn($rendered),
            self::KIND_RUNNING => $this->info($rendered),
            default => $this->line($rendered),
        };

        return $exitCode;
    }
}

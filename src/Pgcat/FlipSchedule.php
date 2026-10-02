<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * The flip's schedule entry — registered by the provider, not written out by the installation.
 *
 * WHY THIS LIVES IN THE PACKAGE
 *   `db:pgcat-flip` is this package's command, and every choice about when it runs and how its
 *   runs are serialised follows from a fact about the package rather than about the application
 *   that installs it: the file it swaps, the lock it takes and the supervisord it signals are in
 *   the container the command itself is running in. An installation that had to write the entry
 *   out by hand is one that can write it wrongly — `onOneServer()` and the default mutex name are
 *   both wrong here, and both look reasonable — or never write it at all, which is the failure the
 *   README's pgcat section tells: the command existed, nothing called it, and the pooler was one
 *   deploy away from a configuration nobody could repair.
 *
 * WHY EVERY MINUTE BY DEFAULT — AND WHAT AN INTERVAL COSTS
 *   A minute is the granularity the flip is designed around: it is idempotent, it writes nothing
 *   when the mode has not changed, and a minute is shorter than the time a container that needs
 *   repairing has. `swrr.pgcat.schedule.interval_minutes` is what an installation raises when a
 *   run costs more than it is worth, and raising it is a **trade rather than a saving**, because
 *   what bounds the attempts is the boot window below rather than the cadence: eight minutes holds
 *   eight runs at one minute and two at five, and an interval at or past the window's own length
 *   is *one* attempt. An installation that raises the interval past a minute should raise
 *   `flip_window_seconds` with it; one that raises it without that has bought slower recovery
 *   rather than less work.
 *
 *   Apart from the interval there is no conditional on the entry: a schedule that decided whether
 *   to run would need the answer this command exists to establish, and an entry that quietly
 *   stopped existing is the failure mode being fixed here.
 *
 * HOW LONG IT KEEPS TRYING
 *   For the container's boot window and then it stops — `swrr.pgcat.flip_window_seconds`, eight
 *   minutes by default, which is {@see FlipWindow::DEFAULT_SECONDS} and the attempts the reasoning
 *   there is written in (eight of them at the minute this entry runs on by default). After the
 *   window the flip is a no-op that exits `0`, and `/health/db` is the surface that reports a
 *   container that never converged.
 *
 * OVERLAP PROTECTION IS PER CONTAINER, AND THAT IS NOT THE DEFAULT
 *   The resource this command guards is container-local, so two Laravel conveniences are wrong
 *   here and are deliberately absent:
 *
 *   - `onOneServer()` would let exactly one container flip per minute and skip every other,
 *     leaving the rest running a configuration nobody is maintaining.
 *   - the mutex `withoutOverlapping()` names by itself is `sha1(expression + command)` — the
 *     *same name in every container* when the cache store is shared, so one slow container would
 *     hold the lock for all of them.
 *
 *   The mutex name is therefore scoped to the container with `createMutexNameUsing()` and
 *   `gethostname()`, and the authoritative serialisation stays the flipper's own `flock` on
 *   `swrr.pgcat.lock_file` — container-local, needs no shared cache, and already what makes a
 *   concurrent run return `skipped` instead of queueing. The cache mutex is the second layer,
 *   expiring after {@see self::OVERLAP_MINUTES} minutes: a mutex that outlives its run is a flip
 *   that never happens again, and a run is bounded by its own window anyway.
 *
 *   Not `runInBackground()` either: a run is a handful of file operations and one read-only
 *   `supervisorctl status`, and letting `schedule:run` wait for it puts a failure in the schedule's
 *   own log rather than in a detached process nobody reads.
 *
 * WHEN IT IS REGISTERED, AND WHEN IT IS NOT
 *   The provider registers this the first time the container's `Schedule` is resolved — which is
 *   what `schedule:run` and `schedule:list` do — so the configuration is read at the moment the
 *   schedule is built rather than at boot. {@see self::register()} adds nothing when the entry is
 *   already there: a second boot, or an installation that still has the entry the README used to
 *   ask for, must not get a second per-minute run. `swrr.pgcat.schedule.enabled` unset follows
 *   `swrr.pgcat.enabled` — there is nothing to keep in step when the package is not touching
 *   pgcat — and a value that is neither on nor off is refused and reported rather than cast,
 *   because `(bool) 'off'` is true. A refusal resolves to the default the setting documents,
 *   which is again `swrr.pgcat.enabled`: a typo is told about, and it does not disarm the repair
 *   — a switch whose mistake stopped the flip would be a worse failure than the one it guards.
 */
final class FlipSchedule
{
    /** The command this entry runs. */
    public const COMMAND = 'db:pgcat-flip';

    /** The name the entry carries when the installation does not give it one. */
    public const DEFAULT_NAME = 'pgcat_flip';

    /** The cadence, in minutes, when the installation asks for none — see the class docblock. */
    public const DEFAULT_INTERVAL_MINUTES = 1;

    /**
     * The longest interval this setting can express, because the cadence is a *minute* step.
     *
     * A cron minute field holds 0–59, and a step wider than that *wraps* instead of meaning what it
     * says: a step of 90 in the minute field is a valid expression whose next run is at minute
     * **30** — measured rather than assumed, and nobody's reading of 90. Below the cap the
     * arithmetic is cron's: a step that does not divide the hour evenly leaves a shorter gap at the
     * hour (a step of 7 runs at :56 and again at :00). An interval this package cannot express
     * honestly falls back to {@see self::DEFAULT_INTERVAL_MINUTES} rather than being written into
     * an expression that means something else — and an installation that needs a cadence wider than
     * an hour can register its own `db:pgcat-flip` entry instead, which leaves this one alone.
     */
    public const MAX_INTERVAL_MINUTES = 59;

    /** The default log, relative to the application's storage directory. */
    public const DEFAULT_LOG = 'logs/scheduled_tasks/pgcat_flip.log';

    /** What the mutex name starts with; the container's hostname completes it. */
    public const MUTEX_PREFIX = 'framework/schedule-pgcat-flip-';

    /**
     * Minutes the cache mutex outlives a run. Short on purpose — see the class docblock — and the
     * same two the package's README documents.
     */
    public const OVERLAP_MINUTES = 2;

    /** A cadence of one minute, as the minute field of a cron expression writes it. */
    public const EXPRESSION_EVERY_MINUTE = '* * * * *';

    /** How a cadence of `$minutes` is written in the minute field. */
    private const EXPRESSION_STEP = '*/%d * * * *';

    /**
     * Not instantiable: everything here is a pure function of the config and the schedule.
     */
    private function __construct()
    {
    }

    /**
     * Register the flip on `$schedule`, unless it is switched off or already there.
     *
     * The event it added, or `null` for both "this installation does not schedule it" and "it is
     * already scheduled" — which is why a caller that needs the difference asks
     * {@see self::options()} first, and a caller that only wants the entry on the schedule does
     * not have to care.
     */
    public static function register(Schedule $schedule, Repository $config): ?Event
    {
        $options = self::options($config);

        if ($options['refused'] !== null) {
            Log::warning(
                '[WeightedDB] '.SwitchValue::describeRefused([$options['setting'] => $options['refused']])
                .'. Refused: '.SwitchValue::ACCEPTED.'. '
                .'The entry follows swrr.pgcat.enabled instead, so the value written decides nothing.',
                ['setting' => $options['setting'], 'configured' => $options['refused']],
            );
        }

        if (!$options['enabled']) {
            return null;
        }

        if (self::isRegistered($schedule, $options['name'])) {
            return null;
        }

        self::ensureDirectory(dirname($options['log']));

        return $schedule->command(self::COMMAND)
            // The cadence the installation asked for, written as the one cron expression that means
            // it — a minute is `* * * * *`, and the default is the minute the flip is designed
            // around. {@see self::intervalMinutes()} is where a value this cannot express is
            // reduced to the default.
            ->cron(self::expression($options['interval_minutes']))
            ->withoutOverlapping(self::OVERLAP_MINUTES)
            // Per container, not per application — see the class docblock. gethostname() is the
            // container's own name, so two tasks sharing one Redis cache still get one mutex each,
            // which is the point: they flip two different pgcat configs.
            ->createMutexNameUsing(static fn (): string => self::mutexName(gethostname()))
            ->name($options['name'])
            ->appendOutputTo($options['log']);
    }

    /**
     * The interval in minutes, out of `swrr.pgcat.schedule.interval_minutes`.
     *
     * A whole number of minutes, or {@see self::DEFAULT_INTERVAL_MINUTES} for a value that is not
     * one this setting can express — absent, non-numeric, zero, negative, or past
     * {@see self::MAX_INTERVAL_MINUTES}. Falling back rather than refusing to register is the same
     * treatment `swrr.pgcat.flip_window_seconds` gets, and for the same reason: a number the
     * package cannot read degrades to the value its documentation prints, and here that value is
     * also the *safe* one. Every way this setting can be wrong resolves to running **more** often
     * than was asked for, never to running less — an unreadable interval must not be the thing that
     * leaves a pooler un-repaired.
     */
    public static function intervalMinutes(mixed $value): int
    {
        $minutes = ConfigValue::int($value, self::DEFAULT_INTERVAL_MINUTES);

        if ($minutes < self::DEFAULT_INTERVAL_MINUTES || $minutes > self::MAX_INTERVAL_MINUTES) {
            return self::DEFAULT_INTERVAL_MINUTES;
        }

        return $minutes;
    }

    /**
     * The cron expression a cadence of `$minutes` is written as.
     *
     * Public because the shape is part of what a consumer reads: `* * * * *` is the default, and
     * `interval_minutes: 5` is the same entry with a step of five in the minute field. The step is
     * the *minute* field rather than a seconds field, which is what makes the setting an interval
     * in whole minutes and gives it a ceiling — see {@see self::MAX_INTERVAL_MINUTES}.
     */
    public static function expression(int $minutes): string
    {
        if ($minutes <= self::DEFAULT_INTERVAL_MINUTES) {
            return self::EXPRESSION_EVERY_MINUTE;
        }

        return sprintf(self::EXPRESSION_STEP, $minutes);
    }

    /**
     * The mutex name for `$host`, which is the container's own hostname.
     *
     * A hostname that cannot be read must not become the empty string: every container would then
     * share one mutex name, which is the starvation the scoping exists to avoid. A random suffix
     * keeps the name unique per process instead — the run is still serialised by the flipper's own
     * `flock`, so the worst case is a second mutex rather than a second flip.
     *
     * `gethostname()` is taken as an argument rather than called here so that all three answers it
     * can give — a name, an empty string, and `false` — are reachable from a test.
     */
    public static function mutexName(string|false|null $host): string
    {
        if (is_string($host) && $host !== '') {
            return self::MUTEX_PREFIX.$host;
        }

        return self::MUTEX_PREFIX.bin2hex(random_bytes(8));
    }

    /**
     * Whether `$schedule` already carries this entry.
     *
     * Two readings, because two different things put an entry there. A **name** is this class's
     * own entry — the second boot that must not add a second run. A **command** is an entry the
     * installation wrote itself, either deliberately or from the snippet the README used to hand
     * out, and the command is the right answer there because Laravel does not give a
     * `Schedule::command()` event a description of its own: matching on the name alone would
     * register a second `db:pgcat-flip` beside theirs, and two runs a minute of a command that
     * swaps a file is exactly what the overlap protection is not able to prevent across two
     * entries.
     */
    public static function isRegistered(Schedule $schedule, string $name): bool
    {
        foreach ($schedule->events() as $event) {
            if (is_string($event->description) && $event->description === $name) {
                return true;
            }

            if (is_string($event->command) && str_contains($event->command, self::COMMAND)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The settings the entry is built from, read out of `db-manager.swrr.pgcat.schedule`.
     *
     * `enabled` is a switch and is read as one rather than cast: `(bool) 'off'` is true, and this
     * one decides whether a file gets swapped every minute. Left unset it follows
     * `swrr.pgcat.enabled`, which is the honest default — an installation that has not armed the
     * flipper has nothing for a schedule to keep in step. A value that is neither on nor off is
     * refused: it resolves to that same default — the value the setting documents — and `refused`
     * carries it as it was written so the caller can report it. The flip therefore keeps running
     * through a typo, and the typo is named rather than obeyed: a refused switch that stopped the
     * schedule would let one mistyped character disarm the repair that exists for the outage
     * nobody was watching.
     *
     * `interval_minutes` is read by {@see self::intervalMinutes()}, which is where its ceiling and
     * its fallback are written down — a number the package cannot express is not reported the way a
     * refused switch is, because the documented default is also the safe direction.
     *
     * @return array{enabled: bool, name: string, log: string, interval_minutes: int, setting: string, refused: string|null}
     */
    public static function options(Repository $config): array
    {
        $setting = 'swrr.pgcat.schedule.enabled';
        $schedule = ConfigValue::assoc($config->get('db-manager.swrr.pgcat.schedule'));
        $follows = SwitchValue::read($config->get('db-manager.swrr.pgcat.enabled'), false)['on'];

        ['on' => $enabled, 'refused' => $refused] = SwitchValue::read($schedule['enabled'] ?? null, $follows);

        $name = ConfigValue::string($schedule['name'] ?? null, self::DEFAULT_NAME);
        $log = ConfigValue::string($schedule['log'] ?? null, self::defaultLog());

        return [
            'enabled' => $enabled,
            'name' => $name === '' ? self::DEFAULT_NAME : $name,
            'log' => $log === '' ? self::defaultLog() : $log,
            'interval_minutes' => self::intervalMinutes($schedule['interval_minutes'] ?? null),
            'setting' => $setting,
            'refused' => $refused,
        ];
    }

    /**
     * Where the runs' output goes when the installation names no log.
     *
     * `storage_path()`, because that is where Laravel puts the output of a scheduled event it
     * names for itself (`Event::getDefaultOutput()` and `ensureOutputIsBeingCaptured()` both land
     * under `storage/logs`), and because the application's other scheduled tasks already log
     * there.
     */
    public static function defaultLog(): string
    {
        return storage_path(self::DEFAULT_LOG);
    }

    /**
     * Create `$directory` if it is not there.
     *
     * A scheduled *command* event is run through a shell command with its output redirected
     * (`>> <log> 2>&1`), so a log whose directory does not exist is a redirect the shell cannot
     * open — and the command never runs. That is a silent, total failure of the one thing this
     * package promises runs every minute, and it is not diagnosable from the schedule's own
     * output, because the output is the file that could not be opened. Creating it here is
     * therefore part of registering the entry rather than a convenience: the directory must exist
     * by the time `schedule:run` gets there, and nothing else is going to make it.
     */
    private static function ensureDirectory(string $directory): void
    {
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }
    }
}

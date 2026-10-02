<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * The reader windows as scheduled tasks: one activation and one deactivation per window.
 *
 * WHAT THIS IS FOR, AND WHY IT IS SEPARATE FROM THE FLIP'S OWN ENTRY
 *   `db:pgcat-flip` follows the window resolver too, but only inside the container's boot window —
 *   after eight minutes it is a deliberate no-op for the rest of the container's life. That is the
 *   right bound for *converging at boot* and the wrong one for *following the day*: a container
 *   booted at 03:00 converges to writer-only and then has no way to move to readers when the
 *   10:00 window opens, because nothing is allowed to change its mind any more.
 *
 *   These two tasks are the other half: a boundary is approached, its target is asked whether it
 *   is actually there, and the mode is applied through the flipper's forced path — which has no
 *   boot window, because the boot window is a fact about starting up rather than about the time of
 *   day. They are registered only when {@see self::CONFIG_KEY}`.enabled` is on, and an
 *   installation running them should turn the per-minute `db:pgcat-flip` entry off: two mechanisms
 *   deciding the same mode is two mechanisms that can disagree.
 *
 * WHY THE PROBING IS SEVERAL RUNS RATHER THAN ONE LONG ONE
 *   This task is `* * * * *` *bounded by a lead time*, so it fires once a minute across the eight
 *   minutes before the boundary and once more at it. That is what "probe for eight minutes"
 *   reduces to when the scheduler is cron: eight processes, each a single `SELECT 1` and then
 *   nothing, no state, no sleep, no long-running process for a container restart to lose. A
 *   single command that slept for eight minutes would hold a slot in `schedule:run` for eight
 *   minutes, and would have to be made `runInBackground()` to stop it delaying every other task
 *   in the container.
 *
 * WHY THE CRON IS A MINUTE AND HOUR LIST RATHER THAN A TIME FILTER
 *   `between()` cannot be used: it evaluates `strtotime()` in the *server's* default timezone
 *   while the event's own timezone is `swrr.timezone`, so the two only agree when those happen to
 *   be the same zone — a schedule that silently stops firing in a container configured otherwise.
 *   A cron expression is evaluated in the event's own timezone (`Event::expressionPasses()`),
 *   which is why the lead window is written into the expression instead: the minutes the range
 *   covers in each hour it touches, as a list.
 *
 *   That expression is still *wider* than the lead window — an hour in which the range begins at
 *   :52 also matches minute 0 of that hour, because a cron minute field cannot express "52 to 8 of
 *   the next hour". A cron field cannot be narrowed back, but the *event* can: each entry carries
 *   a `when()` filter built from its own `from`/`to` ({@see self::insideRange()}), evaluated in
 *   the scheduler process before the command is spawned. So the surplus minutes match the
 *   expression and still run nothing — no process, no probe, no log line — and the seventeen
 *   firings a boundary is actually for are the only ones it pays for. Left to the command alone,
 *   the same surplus was seventeen extra processes a day per crossing boundary, each one a `SELECT
 *   1`'s worth of work to report that it was early or expired.
 *
 * THE GRACE, AND WHY A BOUNDARY CAN BE MISSED WITHOUT BEING LOST
 *   The event fires from `boundary - lead` to `boundary + grace`, not just to the boundary, so a
 *   target that is not yet answering at the boundary is asked again on the following minutes
 *   rather than being given up for the day. Nothing flips more than once: the command applies a
 *   mode only when the mode the flipper says is applied is a different one, so the runs after a
 *   successful flip report that there was nothing to do.
 */
final class WindowFlipSchedule
{
    /** The command both tasks run. */
    public const COMMAND = 'db:pgcat-window-flip';

    /** The task that moves the pool to readers as a reader window opens. */
    public const TASK_ACTIVATE = 'activate_readers';

    /** The task that moves it back to the writer-only config as the window closes. */
    public const TASK_DEACTIVATE = 'deactivate_readers';

    /** The mode a flip applies. The flipper's own vocabulary, and the command's. */
    public const MODE_READERS = 'readers';

    public const MODE_WRITER = 'writer';

    /** How long before a boundary the probing starts: eight minutes, as the flip's window is. */
    public const DEFAULT_LEAD_SECONDS = 480;

    /** How long after a boundary it keeps asking, so a late target is not given up for the day. */
    public const DEFAULT_GRACE_SECONDS = 480;

    /** Where the block lives. Named so the task and the command read the same one. */
    public const CONFIG_KEY = 'db-manager.swrr.pgcat.schedule.windows';

    /**
     * The directory each task's log is written into, under the application's storage path.
     *
     * A directory rather than the file the flipper's own entry names, because there is no one file
     * here: a day of windows is four tasks with four boundaries, and each one's evidence is worth
     * reading on its own. The file is named after the task.
     */
    public const DEFAULT_LOG_DIRECTORY = 'logs/scheduled_tasks';

    private function __construct()
    {
    }

    /**
     * The schedule's settings.
     *
     * `enabled` is a switch and is read as one, defaulting — as the block documents — to off: an
     * installation that has not turned this on is one whose mode is still decided by the
     * per-minute flip, and turning both on at once would have two mechanisms applying modes a
     * minute apart. A value that is neither on nor off resolves to that same off and is reported.
     *
     * `windows` and `days` are read as the resolver reads them, because the two have to agree
     * about which moments are reader windows — the resolver for the mode the *rest* of the package
     * follows, and this class for when to apply it.
     *
     * @return array{enabled: bool, lead_seconds: int, grace_seconds: int, log_directory: string, windows: list<array<string, mixed>>, days: list<int>, timezone: DateTimeZone, setting: string, refused: string|null}
     */
    public static function settings(Repository $config): array
    {
        $setting = 'swrr.pgcat.schedule.windows.enabled';
        $block = ConfigValue::assoc($config->get(self::CONFIG_KEY));
        $swrr = ConfigValue::assoc($config->get('db-manager.swrr'));

        ['on' => $enabled, 'refused' => $refused] = SwitchValue::read($block['enabled'] ?? null, false);

        return [
            'enabled' => $enabled,
            'lead_seconds' => self::seconds($block['lead_seconds'] ?? null, self::DEFAULT_LEAD_SECONDS),
            'grace_seconds' => self::seconds($block['grace_seconds'] ?? null, self::DEFAULT_GRACE_SECONDS),
            'log_directory' => ConfigValue::string(
                $block['log_directory'] ?? null,
                storage_path(self::DEFAULT_LOG_DIRECTORY),
            ),
            'windows' => ConfigValue::assocList($swrr['reader_windows'] ?? null),
            'days' => self::days($swrr['reader_days'] ?? null),
            'timezone' => self::timezone($swrr['timezone'] ?? null),
            'setting' => $setting,
            'refused' => $refused,
        ];
    }

    /**
     * The two events each window needs, as data — no schedule and no container, so the arithmetic
     * is testable on its own.
     *
     * A window is skipped, with the reason, when it cannot be expressed honestly rather than being
     * approximated: an unreadable or inverted window, or a window shorter than `lead + grace` (the
     * deactivation would begin probing before the activation had finished, and the two would take
     * turns flipping the pool). That length check is also what makes the range a cron expression can
     * hold: the range is exactly `lead + grace`, so a window long enough to schedule is a range
     * shorter than 24 hours, and the "wider than a day" case cannot survive the check above.
     *
     * @param array{enabled: bool, lead_seconds: int, grace_seconds: int, log_directory: string, windows: list<array<string, mixed>>, days: list<int>, timezone: DateTimeZone, setting: string, refused: string|null} $settings
     * @return array{
     *     entries: list<array{name: string, mode: string, at: string, expression: string, days: list<int>, from: string, to: string}>,
     *     skipped: list<array{window: string, reason: string}> }
     * @throws \DateMalformedStringException
     */
    public static function plan(array $settings): array
    {
        $entries = [];
        $skipped = [];

        if (!$settings['enabled']) {
            return ['entries' => [], 'skipped' => []];
        }

        if ($settings['days'] === []) {
            return [
                'entries' => [],
                'skipped' => [
                    [
                        'window' => '(every window)',
                        'reason' => 'swrr.reader_days is empty, and the resolver reads that as no reader day at all — '
                            . 'there is no moment for these tasks to act on',
                    ],
                ],
            ];
        }

        foreach ($settings['windows'] as $window) {
            $start = ConfigValue::string($window['start'] ?? null);
            $end = ConfigValue::string($window['end'] ?? null);
            $label = $start . '–' . $end;

            if (!self::isTime($start) || !self::isTime($end)) {
                $skipped[] = ['window' => $label, 'reason' => 'the window does not carry two readable times'];

                continue;
            }

            $timezone = $settings['timezone'];
            $midnight = new DateTimeImmutable('today', $timezone);
            $opens = self::at($midnight, $start);
            $closes = self::at($midnight, $end);

            if ($closes <= $opens) {
                $skipped[] = ['window' => $label, 'reason' => 'the window ends before it opens'];

                continue;
            }

            $span = $closes->getTimestamp() - $opens->getTimestamp();

            if ($span < $settings['lead_seconds'] + $settings['grace_seconds']) {
                $skipped[] = [
                    'window' => $label,
                    'reason' => sprintf(
                        'the window is %d minute(s) long, and %d minute(s) of lead and grace are asked for: the '
                        . 'deactivation would begin probing before the activation could have acted',
                        intdiv($span, 60),
                        intdiv($settings['lead_seconds'] + $settings['grace_seconds'], 60),
                    ),
                ];

                continue;
            }

            foreach ([[self::TASK_ACTIVATE, self::MODE_READERS, $opens], [self::TASK_DEACTIVATE, self::MODE_WRITER, $closes]] as [$task, $mode, $boundary]) {
                $range = self::range(
                    $boundary->modify('-' . $settings['lead_seconds'] . ' seconds'),
                    $boundary->modify('+' . $settings['grace_seconds'] . ' seconds'),
                );

                $entries[] = [
                    'name' => $task . ':' . $boundary->format('H:i'),
                    'mode' => $mode,
                    'at' => $boundary->format('H:i:s'),
                    'expression' => $range['expression'],
                    'days' => $settings['days'],
                    'from' => $range['from'],
                    'to' => $range['to'],
                ];
            }
        }

        return ['entries' => $entries, 'skipped' => $skipped];
    }

    /**
     * Put the plan on `$schedule`, unless an entry of that name is already there.
     *
     * Idempotent by name, because the name is unique per boundary (`activate_readers:10:00`): a
     * second boot, or a schedule built twice, must not leave two tasks probing the same boundary
     * and two chances to apply the same mode a minute apart. The command's own guard makes a
     * double application harmless, but a duplicate entry is still a second process a minute and a
     * second line in the log.
     *
     * The per-container mutex that the flip's own entry carries is not needed here: the resource
     * these tasks guard is the mode, and the mode is changed through the flipper's lock. What the
     * tasks do share across containers is the *probe*, which is read-only.
     *
     * @param Schedule $schedule
     * @param Repository $config
     * @return array{registered: list<string>, skipped: list<array{window: string, reason: string}>}
     * @throws \DateMalformedStringException
     */
    public static function register(Schedule $schedule, Repository $config): array
    {
        /** @var array{enabled: bool, lead_seconds: int, grace_seconds: int, log_directory: string, windows: list<array<string, mixed>>, days: list<int>, timezone: DateTimeZone, setting: string, refused: string|null} $settings */
        $settings = self::settings($config);

        if ($settings['refused'] !== null) {
            Log::warning(
                '[WeightedDB] ' . SwitchValue::describeRefused([$settings['setting'] => $settings['refused']])
                . '. Refused: ' . SwitchValue::ACCEPTED . '. The reader-window tasks are not registered.',
                ['setting' => $settings['setting'], 'configured' => $settings['refused']],
            );
        }

        $plan = self::plan($settings);

        foreach ($plan['skipped'] as $skip) {
            Log::warning(
                '[WeightedDB] A reader window is not scheduled: ' . $skip['window'] . ' — ' . $skip['reason'] . '.',
                ['window' => $skip['window'], 'reason' => $skip['reason']],
            );
        }

        $registered = [];

        foreach ($plan['entries'] as $entry) {
            if (self::isRegistered($schedule, $entry['name'])) {
                continue;
            }

            $log = self::logFor($entry['name'], $settings['log_directory']);

            // A command event runs through a shell redirect, so a log whose directory is missing is
            // a command that never runs — see {@see self::ensureDirectory()}.
            self::ensureDirectory(dirname($log));

            $schedule->command(self::COMMAND, [
                '--mode' => $entry['mode'],
                '--at' => $entry['at'],
            ])
                ->cron($entry['expression'])
                // The cron is evaluated in this zone (`Event::expressionPasses()`), which is the
                // zone the window itself is written in — see the class docblock on why `between()`
                // is not used for the same job.
                ->timezone($settings['timezone']->getName())
                ->days($entry['days'])
                ->name($entry['name'])
                // The expression is wider than the range whenever the range crosses an hour (see
                // the class docblock). This is what narrows it back, in the scheduler process
                // rather than in the command: a surplus firing never becomes a process.
                ->when(static fn (): bool => self::insideRange($entry['from'], $entry['to'], Date::now($settings['timezone'])))
                ->appendOutputTo($log)
                ->withoutOverlapping(FlipSchedule::OVERLAP_MINUTES);

            $registered[] = $entry['name'];
        }

        return ['registered' => $registered, 'skipped' => $plan['skipped']];
    }

    /**
     * Whether `$schedule` already carries a task of this name.
     *
     * By name only, unlike {@see FlipSchedule::isRegistered()}: the name carries the task *and* the
     * boundary, so it identifies one event exactly, and the command is shared by every window — a
     * command match here would let the second window's task be mistaken for the first's.
     */
    public static function isRegistered(Schedule $schedule, string $name): bool
    {
        foreach ($schedule->events() as $event) {
            if (is_string($event->description) && $event->description === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The moment a run is for: the boundary `$at` names that falls nearest `$now`.
     *
     * Nearest, rather than the next one at or after some threshold, and the difference is what
     * makes the command's own bounds reachable. A run is scheduled to start `lead` seconds before
     * its boundary, so the boundary is normally a little ahead of `now` — but a run can be late (a
     * cron that drifted, a `schedule:run` that queued behind a slow task) as easily as early (the
     * cron expression is deliberately wider than the lead window when the range crosses an hour).
     * The nearest candidate is the boundary either of those runs is *for*, which leaves three
     * states, one for each of them: inside the window, before it, and past it.
     *
     * Any rule that only ever looks forward — "today's boundary unless it is already behind the
     * lead, in which case tomorrow's" — makes the third state unreachable and the second one reach
     * tomorrow's boundary, where the run is reported as early with a distance of nearly a day. Both
     * are still *correct*, in the sense that the run then declines to act; neither is what the
     * operator needs to read, and a route that cannot be reached is a route that cannot be tested.
     *
     * The candidates are the three nearest days rather than today alone, because a boundary shortly
     * after midnight is reached by probing on the previous evening: a run at 23:57 is 8 minutes from
     * tomorrow's 00:05 and nearly a day from today's.
     *
     * The zone is the resolver's, so "today" and the boundary's wall-clock time are the same
     * clock the mode itself is decided on.
     *
     * @throws InvalidArgumentException when `$at` is not a time of day
     */
    public static function boundary(string $at, DateTimeImmutable $now, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!self::isTime($at)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The boundary is a time of day (HH:MM or HH:MM:SS), and %s is not one.',
                    $at,
                )
            );
        }

        $local = $now->setTimezone($timezone);
        $nearest = self::at($local, $at);

        foreach ([$nearest->modify('-1 day'), $nearest->modify('+1 day')] as $candidate) {
            if (abs($candidate->getTimestamp() - $local->getTimestamp()) < abs($nearest->getTimestamp() - $local->getTimestamp())) {
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * Whether the boundary a run resolved to falls on a day the reader windows apply to.
     *
     * The day is checked here rather than left to the cron because the boundary a run resolves to
     * can belong to the *next* day — the 23:57 run that is for a 00:05 boundary — and a next day that
     * is not a reader day is a boundary these tasks have no business acting on. A cron day field
     * filters on the day the run *fires* on, so it cannot express that.
     *
     * The grace is deliberately not part of this question. It was, and the fold made a run past the
     * grace report itself as the wrong day: two different facts — "this boundary is not mine" and
     * "this boundary's chance has gone" — with two different repairs, collapsed into one verdict.
     * The grace is a bound on *when* a run may act, and the command holds it where it belongs, next
     * to the lead.
     *
     * @param list<int> $days
     */
    public static function onReaderDay(DateTimeImmutable $boundary, array $days): bool
    {
        return in_array((int) $boundary->format('N'), $days, true);
    }

    /** Whether the pool is already in the mode a run is for — the one thing that makes a repeat harmless. */
    public static function alreadyIn(string $mode, ?string $applied): bool
    {
        return $applied !== null && $applied === $mode;
    }

    /**
     * Whether a moment falls inside the probing range `[from, to]` of an entry, to the minute.
     *
     * This is the guard registered on each event with `when()`. The cron expression an entry
     * carries cannot be exact when the range crosses an hour — the minute field says every minute
     * the range touches and the hour field says every hour it touches, and cron multiplies the two
     * — so the expression fires on a handful of minutes that are outside the window (09:00–09:08
     * for a 09:52 start). A `when()` filter is evaluated by `ScheduleRunCommand` *before* the
     * command is spawned, so a minute this rejects costs a closure call and nothing else: no
     * process, no probe, no log line. Left to the command, each of them was a whole process that
     * did one thing — report itself early or expired.
     *
     * The comparison is a minute of the day rather than the `H:i:s` strings, because a range may
     * wrap past midnight: a boundary at 00:00 probes from 23:52 of the previous day, where the
     * string `from` sorts *after* the string `to` and a plain `<=` would reject the whole window.
     * The wrap is handled as `inside [start, end]` or, when the range crosses midnight, `at or
     * after start, or at or before end` — which is the same set of minutes either way.
     */
    public static function insideRange(string $from, string $to, DateTimeInterface $now): bool
    {
        $minute = ((int) $now->format('G')) * 60 + ((int) $now->format('i'));
        $start = self::minuteOfDay($from);
        $end = self::minuteOfDay($to);

        return $start <= $end
            ? $minute >= $start && $minute <= $end
            : $minute >= $start || $minute <= $end;
    }

    /** The minute of the day a time of day names — `HH:MM:SS` or `HH:MM`, as {@see self::range()} writes it. */
    private static function minuteOfDay(string $at): int
    {
        $parts = explode(':', $at);

        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * Where a task's output goes: one file per task, named after it.
     *
     * The name is not used verbatim. It carries the boundary — `activate_readers:10:00` — and a
     * colon is not a character Windows allows in a file name, so a task whose log was named after
     * itself would be a redirect the shell cannot open on one platform and a command that never
     * runs, silently, because the output is the file that could not be opened. The colon is
     * replaced rather than escaped: the log is read by a person, and a name nobody can type is not
     * the improvement.
     */
    public static function logFor(string $name, string $directory): string
    {
        return rtrim($directory, '/\\') . '/' . str_replace(':', '-', $name) . '.log';
    }

    /**
     * Create `$directory` if it is not there.
     *
     * The same reasoning as the per-minute entry's: a scheduled command event is run through a shell
     * command with its output redirected (`>> <log> 2>&1`), so a log whose directory does not exist
     * is a redirect the shell cannot open — and the command never runs. Creating it is part of
     * registering the entry rather than a convenience: the directory has to exist by the time
     * `schedule:run` gets there, and nothing else is going to make it.
     */
    private static function ensureDirectory(string $directory): void
    {
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }
    }

    /**
     * A time of day, as `HH:MM`, `HH:MM:SS` or a whole timestamp's time part.
     */
    public static function isTime(string $at): bool
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $at) === 1;
    }

    /**
     * The minute and hour lists a cron expression needs to fire on every minute of `[start, end]`.
     *
     * The range is `lead + grace` wide, and {@see self::plan()} has already refused any window too
     * short to hold both — so this is never asked to express a day or more, which is the one span an
     * hour field cannot hold. The check belongs there rather than here: a window that cannot be
     * given a lead and a grace is skipped with that reason, and by the time this is called the
     * arithmetic is settled.
     *
     * @return array{expression: string, from: string, to: string}
     */
    private static function range(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $span = $end->getTimestamp() - $start->getTimestamp();

        $minutes = [];
        $hours = [];

        // The span is seconds and the step is a minute, which is the one place the two units meet:
        // stepping by the second count would cover a hundred times the range and write every minute
        // of the day into the expression — an entry that fires far more often than it says.
        for ($offset = 0; $offset <= intdiv($span, 60); $offset++) {
            $moment = $start->modify('+' . $offset . ' minutes');

            $minutes[$moment->format('i')] = true;
            $hours[$moment->format('G')] = true;
        }

        return [
            'expression' => implode(',', self::ordered($minutes)) . ' ' . implode(',', self::ordered($hours)) . ' * * *',
            'from' => $start->format('H:i:s'),
            'to' => $end->format('H:i:s'),
        ];
    }

    /**
     * The keys of a set, numerically, without the leading zero the minute field would otherwise
     * carry: `08` is not a number a cron field is written with. The keys arrive as `int` for every
     * value a clock writes (PHP turns the numeric string `'08'` into the integer 8), which is the
     * other half of why the set exists rather than a list of the strings `format()` produced.
     *
     * @param array<int|string, true> $set
     * @return list<string>
     */
    private static function ordered(array $set): array
    {
        $values = array_map(static fn (int|string $value): int => (int) $value, array_keys($set));
        sort($values);

        return array_map(static fn (int $value): string => (string) $value, $values);
    }

    /**
     * A moment on the day of `$anchor` at the time `$at` names, in the anchor's own zone.
     */
    private static function at(DateTimeImmutable $anchor, string $at): DateTimeImmutable
    {
        $time = strlen($at) === 5 ? $at . ':00' : $at;

        return new DateTimeImmutable($anchor->format('Y-m-d') . ' ' . $time, $anchor->getTimezone());
    }

    /**
     * A number of seconds, or the documented default for a value that is not one.
     *
     * Zero is a legitimate answer for both of these — a lead of none is "probe at the boundary",
     * a grace of none is "do not retry past it" — and a negative one is not, so it is read as
     * none rather than as the default: the setting says how much *extra* time to allow, and a
     * negative amount is no amount rather than the eight minutes nobody asked for.
     */
    private static function seconds(mixed $value, int $fallback): int
    {
        $seconds = ConfigValue::int($value, $fallback);

        return $seconds < 0 ? 0 : $seconds;
    }

    /**
     * @return list<int>
     */
    private static function days(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $days = [];

        foreach ($value as $day) {
            $number = ConfigValue::int($day, 0);

            if ($number >= 1 && $number <= 7) {
                $days[$number] = true;
            }
        }

        return array_map(static fn (int|string $day): int => (int) $day, array_keys($days));
    }

    private static function timezone(mixed $value): DateTimeZone
    {
        $name = ConfigValue::string($value, 'UTC');

        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}

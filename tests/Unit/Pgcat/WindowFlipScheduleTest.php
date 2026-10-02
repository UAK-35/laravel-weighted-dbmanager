<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The reader windows as scheduled tasks: what is planned, when each task fires, which day a run
 * is for, and what stops a second pair.
 *
 * WHY THIS IS A FILE OF ITS OWN
 *   The two tasks are one arithmetic problem and one registration, and the arithmetic is the half
 *   that decides whether the pool is moved at the right minute. Four things have to agree for a
 *   window to be acted on: the boundary, the lead window written into a cron expression, the grace,
 *   and the day the boundary falls on — and each of them is a place where a plausible-looking
 *   mistake (an off-by-one minute, a range evaluated in the server's zone, tonight's run for
 *   tomorrow's boundary) produces a task that fires and does nothing, which is invisible from the
 *   schedule's own output.
 *
 *   So the plan is asserted as data — names, modes, boundaries, expressions, the window each
 *   expression really covers — and the registration is asserted on the container's own `Schedule`,
 *   because a plan nobody put on a schedule is the failure the whole class exists to avoid.
 *
 * WHY THE RANGE IS DELIBERATELY WIDER THAN THE LEAD WINDOW
 *   A cron minute field cannot express "52 to 8 of the next hour", so a range that crosses one is
 *   written as the union of the minutes on both sides — which makes the expression fire for a few
 *   minutes that are outside the window. That residue is still pinned here rather than described,
 *   because a reader has to be able to see how far out the expression goes — and `insideRange()` is
 *   pinned beside it, because that is the filter the event carries and the thing that turns the
 *   residue into minutes that match and do nothing, rather than processes that run and decline.
 */
final class WindowFlipScheduleTest extends TestCase
{
    /** A directory of this test's own, for the logs the registration is pointed at. */
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/window-flip-schedule-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * The config the package reads, with the window tasks armed as the test says.
     *
     * The windows are the ones the shipped sample config carries, because that is the shape an
     * installation actually schedules: two windows in one day, both crossing no boundary but the
     * first one crossing an hour on the *lead* side.
     *
     * @param array<string, mixed> $windows the `swrr.pgcat.schedule.windows` block
     * @param array<string, mixed> $swrr extra `swrr` keys to replace
     */
    private function configure(array $windows = [], array $swrr = []): Repository
    {
        $config = $this->app->make(Repository::class);

        $config->set('db-manager.swrr', [
            'reader_windows' => [
                ['start' => '10:00:00', 'end' => '14:20:00'],
                ['start' => '17:00:00', 'end' => '20:30:00'],
            ],
            'reader_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            ...$swrr,
        ]);

        $config->set(WindowFlipSchedule::CONFIG_KEY, [
            'enabled' => true,
            'log_directory' => $this->dir,
            ...$windows,
        ]);

        return $config;
    }

    /**
     * The plan for the shipped config, as data.
     *
     * Each window is one activation and one deactivation, both derived from the window's own two
     * times: the mode, the boundary, and the range the event fires over. The minutes are asserted
     * through the expression the event will carry rather than as a list this file builds again —
     * what the task is *for* is the window `from`/`to` pair, and the expression is how cron is told
     * about it.
     */
    public function test_the_shipped_windows_become_one_pair_of_tasks_each(): void
    {
        $plan = WindowFlipSchedule::plan(WindowFlipSchedule::settings($this->configure()));

        $this->assertSame([], $plan['skipped'], 'the sample config is expressible, or the package ships a schedule it cannot register');

        $this->assertSame(
            ['activate_readers:10:00', 'deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            array_column($plan['entries'], 'name'),
            'one task per boundary, named for the task and the boundary it acts at',
        );

        $this->assertSame(
            [WindowFlipSchedule::MODE_READERS, WindowFlipSchedule::MODE_WRITER, WindowFlipSchedule::MODE_READERS, WindowFlipSchedule::MODE_WRITER],
            array_column($plan['entries'], 'mode'),
            'an opening moves the pool to readers and a closing moves it back to the writer-only config',
        );

        $this->assertSame(
            ['10:00:00', '14:20:00', '17:00:00', '20:30:00'],
            array_column($plan['entries'], 'at'),
            'the boundary is the window\'s own time, not the moment the probing starts',
        );

        $this->assertSame(
            [
                ['from' => '09:52:00', 'to' => '10:08:00'],
                ['from' => '14:12:00', 'to' => '14:28:00'],
                ['from' => '16:52:00', 'to' => '17:08:00'],
                ['from' => '20:22:00', 'to' => '20:38:00'],
            ],
            array_map(
                static fn (array $entry): array => ['from' => $entry['from'], 'to' => $entry['to']],
                $plan['entries'],
            ),
            'eight minutes of probing before the boundary and eight of grace after it',
        );

        $this->assertSame(
            [
                '0,1,2,3,4,5,6,7,8,52,53,54,55,56,57,58,59 9,10 * * *',
                '12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28 14 * * *',
                '0,1,2,3,4,5,6,7,8,52,53,54,55,56,57,58,59 16,17 * * *',
                '22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38 20 * * *',
            ],
            array_column($plan['entries'], 'expression'),
            'the minutes and hours the range covers, as a cron expression rather than a time filter',
        );

        $this->assertSame([[1, 2, 3, 4, 5]], array_values(array_unique(array_column($plan['entries'], 'days'), SORT_REGULAR)), 'every task carries the reader days');
    }

    /**
     * The expression is wider than the lead window, and how much wider is worth pinning: this is the
     * residue the class docblock admits to, and the reason the command — not the cron — is what
     * decides whether a firing is inside its own bounds.
     *
     * The 10:00 boundary starts probing at 09:52, but the expression has to describe "52 to 8 of the
     * next hour", which a cron minute field cannot: it is written as minutes 52–59 of hour 9 *and*
     * minutes 0–8 of hours 9 and 10. So 09:00–09:08 also fire, 52 minutes before the boundary — the
     * expression is still the wider thing, and this pins exactly how much wider. The *event* is not:
     * the `when()` guard the registration attaches refuses that minute before the command would even
     * be spawned, which the guard test below pins independently.
     */
    public function test_a_range_that_crosses_an_hour_fires_earlier_than_the_window_it_is_for(): void
    {
        $entry = WindowFlipSchedule::plan(WindowFlipSchedule::settings($this->configure()))['entries'][0];

        $this->assertSame('09:52:00', $entry['from']);

        $event = (new Schedule())->command(WindowFlipSchedule::COMMAND)->cron($entry['expression'])->timezone('UTC');

        // Inside the window: the first firing is the opening minute itself.
        $this->assertSame('2026-10-01 09:52', $event->nextRunDate('2026-10-01 09:51:30')->format('Y-m-d H:i'));

        // ...and the residue, asked of the expression rather than taken from the string above.
        $this->assertSame('2026-10-01 09:01', $event->nextRunDate('2026-10-01 09:00:30')->format('Y-m-d H:i'));

        // The boundary that residue is measured against is still the one the window names, because
        // a boundary is resolved as the nearest one and 10:00 is 59 minutes from 09:01 and 23 hours
        // from the 10:00 of the following day.
        $this->assertSame(
            '2026-10-01 10:00:00',
            WindowFlipSchedule::boundary($entry['at'], new DateTimeImmutable('2026-10-01 09:01:00', new DateTimeZone('UTC')), new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
        );
    }

    /**
     * The guard, as data: which minutes of the day are inside a range and which are not.
     *
     * This is the half that decides how often a task actually runs. The expression matches the
     * residue; `insideRange()` is registered on the event with `when()` and is evaluated by
     * `schedule:run` before the command is spawned, so the minutes it refuses cost a closure call
     * and nothing else. The range is `lead + grace` wide and written as `from`/`to` on the entry,
     * so the guard reads the same two times the cron was built from.
     */
    public function test_the_guard_admits_only_the_minutes_the_range_covers(): void
    {
        $utc = new DateTimeZone('UTC');

        // The shipped 10:00 boundary: 09:52–10:08, seventeen minutes, both ends included.
        foreach (['09:52', '09:59', '10:00', '10:01', '10:08'] as $at) {
            $this->assertTrue(
                WindowFlipSchedule::insideRange('09:52:00', '10:08:00', new DateTimeImmutable('2026-10-01 '.$at.':00', $utc)),
                $at.' is one of the range\'s own minutes',
            );
        }

        // ...and the residue the expression also fires on: the minutes before the start and after
        // the end that share the hour field with a minute that is inside.
        foreach (['09:51', '09:00', '09:08', '10:09', '10:52', '10:59', '12:00'] as $at) {
            $this->assertFalse(
                WindowFlipSchedule::insideRange('09:52:00', '10:08:00', new DateTimeImmutable('2026-10-01 '.$at.':00', $utc)),
                $at.' is not in the range, however the expression fires there',
            );
        }

        // A range that stays inside one hour is admitted the same way, and its edges are inclusive.
        $this->assertTrue(WindowFlipSchedule::insideRange('14:12:00', '14:28:00', new DateTimeImmutable('2026-10-01 14:12:00', $utc)));
        $this->assertTrue(WindowFlipSchedule::insideRange('14:12:00', '14:28:00', new DateTimeImmutable('2026-10-01 14:28:00', $utc)));
        $this->assertFalse(WindowFlipSchedule::insideRange('14:12:00', '14:28:00', new DateTimeImmutable('2026-10-01 14:11:00', $utc)));
        $this->assertFalse(WindowFlipSchedule::insideRange('14:12:00', '14:28:00', new DateTimeImmutable('2026-10-01 14:29:00', $utc)));

        // A boundary at midnight probes from the previous evening, so `from` sorts *after* `to` and
        // the range wraps: both sides of midnight are inside, the rest of either hour is not. A
        // string comparison would refuse the whole window here, which is why the guard is a minute
        // of the day rather than `from <= now <= to`.
        $this->assertTrue(WindowFlipSchedule::insideRange('23:52:00', '00:08:00', new DateTimeImmutable('2026-10-01 23:52:00', $utc)));
        $this->assertTrue(WindowFlipSchedule::insideRange('23:52:00', '00:08:00', new DateTimeImmutable('2026-10-02 00:00:00', $utc)));
        $this->assertTrue(WindowFlipSchedule::insideRange('23:52:00', '00:08:00', new DateTimeImmutable('2026-10-02 00:08:00', $utc)));
        $this->assertFalse(WindowFlipSchedule::insideRange('23:52:00', '00:08:00', new DateTimeImmutable('2026-10-01 23:51:00', $utc)));
        $this->assertFalse(WindowFlipSchedule::insideRange('23:52:00', '00:08:00', new DateTimeImmutable('2026-10-02 00:09:00', $utc)));

        // A lead and grace of none is a range of one minute — the boundary, once — not a range that
        // never matches. `start <= end` holds, so the comparison is the plain one.
        $this->assertTrue(WindowFlipSchedule::insideRange('10:00:00', '10:00:00', new DateTimeImmutable('2026-10-01 10:00:30', $utc)));
        $this->assertFalse(WindowFlipSchedule::insideRange('10:00:00', '10:00:00', new DateTimeImmutable('2026-10-01 10:01:00', $utc)));
    }

    /**
     * Every registered event carries the guard, and the guard is the event's own range.
     *
     * This is the wiring half of the test above: `register()` attaches one `when()` filter per event,
     * built from that event's `from`/`to`, and the filter is what `ScheduleRunCommand` asks before it
     * spawns anything. The expression is asked the same question at 09:01 and answers *yes* — it has
     * to, the residue is a fact of the cron field — so the filter is the only thing standing between
     * a surplus match and a process. The clock is the framework's test-now, so the minute the guard
     * reads is the minute this test names.
     */
    public function test_each_event_is_guarded_to_its_own_range(): void
    {
        $schedule = new Schedule();

        WindowFlipSchedule::register($schedule, $this->configure());

        $events = [];

        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        $this->assertSame(
            ['activate_readers:10:00', 'deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            array_keys($events),
        );

        $this->assertSame(
            '2026-10-01 09:01',
            $events['activate_readers:10:00']->nextRunDate('2026-10-01 09:00:30')->format('Y-m-d H:i'),
            'the expression the guard rides on still matches the residue — the filter is what refuses it',
        );

        // A stub stands in for the application: `filtersPass()` asks it to call the filter, and the
        // filter is a closure that reads the clock the test set.
        $app = new class () {
            public function call(callable $callback): mixed
            {
                return $callback();
            }
        };

        try {
            // The 10:00 boundary's own range is 09:52–10:08. Its expression also matches 09:00–09:08
            // and 10:52–10:59; every one of those is a minute the guard has to refuse.
            foreach (['09:52:00' => true, '09:59:00' => true, '10:00:00' => true, '10:08:00' => true, '09:01:00' => false, '09:00:00' => false, '10:55:00' => false] as $at => $expected) {
                Carbon::setTestNow(new DateTimeImmutable('2026-10-01 '.$at, new DateTimeZone('UTC')));

                $this->assertSame(
                    $expected,
                    $events['activate_readers:10:00']->filtersPass($app),
                    $at.' is '.($expected ? 'inside' : 'outside').' the 10:00 range, so the event '.($expected ? 'runs' : 'is refused and spawns nothing'),
                );
            }

            // Each event reads its own range rather than the first one's: 14:12 is inside the
            // 14:20 boundary's window and nowhere near the 10:00 boundary's, and the two events
            // disagree about it, which is the whole point of building the filter per entry.
            Carbon::setTestNow(new DateTimeImmutable('2026-10-01 14:12:00', new DateTimeZone('UTC')));

            $this->assertTrue($events['deactivate_readers:14:20']->filtersPass($app), 'one minute inside the 14:20 range');
            $this->assertFalse($events['activate_readers:10:00']->filtersPass($app), 'and the same minute is outside the 10:00 range');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A window that cannot be expressed honestly is skipped, with the reason, rather than
     * approximated — and every way it can be unreadable is a skip rather than a guess, because the
     * cost of guessing is a task that flips the pool at a moment nobody chose.
     *
     * The short-window case is the one worth reading: with a minute of lead and a minute of grace
     * the deactivation would begin probing while the activation was still inside its own window, so
     * the two would take turns flipping the pool. That is a configuration mistake rather than
     * something to schedule around.
     */
    public function test_a_window_that_cannot_be_expressed_is_skipped_with_its_reason(): void
    {
        $cases = [
            'a time that is not a time' => [
                'windows' => [['start' => 'lol', 'end' => '14:20:00']],
                'days' => [1],
                'contains' => 'does not carry two readable times',
            ],
            'a window that ends before it opens' => [
                'windows' => [['start' => '14:00:00', 'end' => '10:00:00']],
                'days' => [1],
                'contains' => 'ends before it opens',
            ],
            'a window shorter than the lead and the grace' => [
                'windows' => [['start' => '10:00:00', 'end' => '10:10:00']],
                'days' => [1],
                'contains' => 'the deactivation would begin probing before the activation could have acted',
            ],
            // What is compared is the *sum* against the window: this window is twenty minutes long
            // and the two together ask for 1400 seconds of it, so it is refused — where the same
            // window with two minutes of each would be scheduled. A lead and a grace that each fit
            // on their own but not together would otherwise put the two tasks inside each other.
            'a lead and grace that together outlast the window' => [
                'windows' => [['start' => '10:00:00', 'end' => '10:20:00']],
                'days' => [1],
                'block' => ['lead_seconds' => 700, 'grace_seconds' => 700],
                'contains' => 'the deactivation would begin probing before the activation could have acted',
            ],
        ];

        foreach ($cases as $name => $case) {
            $plan = WindowFlipSchedule::plan(WindowFlipSchedule::settings($this->configure(
                ['windows' => $case['windows'], ...($case['block'] ?? [])],
                ['reader_days' => $case['days'], 'reader_windows' => $case['windows']],
            )));

            $this->assertSame([], $plan['entries'], "the {$name} case schedules nothing");
            $this->assertNotSame([], $plan['skipped'], "the {$name} case says why");
            $this->assertStringContainsString(
                $case['contains'],
                $plan['skipped'][0]['reason'],
                "the reason for the {$name} case names the repair",
            );
        }
    }

    /**
     * No reader day at all is the one skip that is not about a window: `swrr.reader_days` empty is
     * how the resolver is told replicas are never used, so there is no moment for these tasks to
     * act on — and it is reported once rather than once per window, because it is one fact about
     * the installation rather than one per boundary.
     */
    public function test_no_reader_day_at_all_is_reported_once_rather_than_per_window(): void
    {
        $plan = WindowFlipSchedule::plan(WindowFlipSchedule::settings($this->configure([], ['reader_days' => []])));

        $this->assertSame([], $plan['entries']);
        $this->assertCount(1, $plan['skipped']);
        $this->assertSame('(every window)', $plan['skipped'][0]['window']);
        $this->assertStringContainsString('swrr.reader_days is empty', $plan['skipped'][0]['reason']);
    }

    /**
     * The switch is off by default and is read as a switch: `(bool) 'off'` is true, and this is the
     * switch that decides whether a file is swapped on a schedule, so the spellings an operator
     * writes have to work and a typo has to be reported rather than cast.
     *
     * The refusal resolves *to off*, which is the opposite direction from the per-minute entry's and
     * is right for the same reason: there, being off is what stops the repair, and here being on is
     * what would start a second mechanism flipping the pool — a neighbouring mistake, not a repair.
     */
    public function test_the_switch_is_off_by_default_and_a_typo_does_not_turn_it_on(): void
    {
        // Not "a block with the switch missing" but "no block at all": the two are different, and
        // this is the case an installation upgrading into the tasks arrives in.
        $this->app->make(Repository::class)->set(WindowFlipSchedule::CONFIG_KEY, []);

        $settings = WindowFlipSchedule::settings($this->app->make(Repository::class));

        $this->assertFalse($settings['enabled'], 'the shipped block is left off, and an absent one is off too');
        $this->assertNull($settings['refused']);
        $this->assertSame([], WindowFlipSchedule::plan($settings)['entries'], 'nothing is planned while the tasks are off');

        foreach (['off', 'no', '0', false] as $spelling) {
            $this->assertFalse(
                WindowFlipSchedule::settings($this->configure(['enabled' => $spelling]))['enabled'],
                "the spelling '".var_export($spelling, true)."' is off",
            );
        }

        $refused = WindowFlipSchedule::settings($this->configure(['enabled' => 'flase']));

        $this->assertFalse($refused['enabled'], 'a typo must not be what arms a second mechanism');
        $this->assertSame('"flase"', $refused['refused'], 'the value is carried as it was written');
        $this->assertSame('swrr.pgcat.schedule.windows.enabled', $refused['setting']);
    }

    /**
     * The lead and the grace are seconds, and a negative one is read as none rather than as the
     * default nobody asked for: the setting says how much *extra* time to allow, so "minus five
     * minutes" is no extra time rather than eight.
     *
     * Zero is legitimate for both — a lead of none is "probe at the boundary", a grace of none is
     * "do not retry past it" — which is why this is not a floor on the setting's value.
     */
    public function test_a_negative_lead_or_grace_is_no_extra_time_rather_than_the_default(): void
    {
        $settings = WindowFlipSchedule::settings($this->configure([
            'lead_seconds' => -60,
            'grace_seconds' => -1,
        ]));

        $this->assertSame(0, $settings['lead_seconds']);
        $this->assertSame(0, $settings['grace_seconds']);

        $defaults = WindowFlipSchedule::settings($this->configure(['lead_seconds' => 'lol', 'grace_seconds' => null]));

        $this->assertSame(WindowFlipSchedule::DEFAULT_LEAD_SECONDS, $defaults['lead_seconds'], 'an unreadable lead is the documented eight minutes');
        $this->assertSame(WindowFlipSchedule::DEFAULT_GRACE_SECONDS, $defaults['grace_seconds']);
        $this->assertSame(480, WindowFlipSchedule::DEFAULT_LEAD_SECONDS);
    }

    /**
     * The days are the resolver's days, filtered to the ISO week the resolver reads: a day outside
     * 1–7 is not a day the resolver recognises, so keeping it would schedule a task for a weekday
     * that never matches, and a duplicate is one day rather than two entries in a cron field.
     */
    public function test_the_days_are_the_readers_days_and_nothing_else(): void
    {
        $settings = WindowFlipSchedule::settings($this->configure([], ['reader_days' => [5, 1, 1, 8, 0, '2']]));

        $this->assertSame([5, 1, 2], $settings['days'], 'the days are kept in the order they were written, deduplicated, and filtered to 1–7');

        $this->assertSame([], WindowFlipSchedule::settings($this->configure([], ['reader_days' => 'weekdays']))['days'], 'a value that is not a list of days is no day at all');
    }

    /**
     * The boundary a run is for is the one nearest it, and the three states that follow from that
     * are the three the command reports: inside the window, before it, and past it.
     *
     * This is what makes `early` and `expired` reachable at all. A rule that only looks forward —
     * "today's boundary unless it is already behind the lead, in which case tomorrow's" — puts a
     * late run on tomorrow's boundary, where it reads as early with a distance of nearly a day, and
     * leaves the past-the-grace state unreachable, so a route nothing can reach is a route nothing
     * can test.
     */
    public function test_the_boundary_is_the_one_nearest_the_run(): void
    {
        $utc = new DateTimeZone('UTC');

        $rows = [
            'a run before its boundary' => ['2026-10-01 09:58:00', '10:00', '2026-10-01 10:00:00'],
            'a run inside the grace, after its boundary' => ['2026-10-01 10:05:00', '10:00', '2026-10-01 10:00:00'],
            'a run half an hour late' => ['2026-10-01 10:30:00', '10:00', '2026-10-01 10:00:00'],
            'a run half an hour early' => ['2026-10-01 09:30:00', '10:00', '2026-10-01 10:00:00'],
            'a run on the previous evening, for a boundary just after midnight' => ['2026-10-01 23:57:00', '00:05', '2026-10-02 00:05:00'],
            'a run just after midnight, for that morning\'s boundary' => ['2026-10-01 00:03:00', '00:05', '2026-10-01 00:05:00'],
        ];

        foreach ($rows as $name => $row) {
            [$now, $at, $expected] = $row;

            $this->assertSame(
                $expected,
                WindowFlipSchedule::boundary($at, new DateTimeImmutable($now, $utc), $utc)->format('Y-m-d H:i:s'),
                $name,
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HH:MM/');

        WindowFlipSchedule::boundary('lol', new DateTimeImmutable('2026-10-01 09:58:00', $utc), $utc);
    }

    /**
     * The boundary is resolved in the zone the reader windows are written in, not in the server's:
     * a container whose clock is UTC and whose windows are Tokyo's has two different \"today\"s, and
     * the one the mode itself is decided on is the configured one.
     */
    public function test_the_boundary_is_resolved_in_the_configured_zone(): void
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');

        // 20:00 UTC is 05:00 the next day in Tokyo, so the nearest 06:00 boundary is that morning's.
        $boundary = WindowFlipSchedule::boundary('06:00', new DateTimeImmutable('2026-10-01 20:00:00', $tokyo), $tokyo);

        $this->assertSame('2026-10-02 06:00:00', $boundary->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tokyo', $boundary->getTimezone()->getName());

        // ...and the configured zone is what the settings carry, with an unreadable one degrading to
        // UTC rather than to the server's zone, which is the reading that would silently move a
        // window by hours.
        $this->assertSame('UTC', WindowFlipSchedule::settings($this->configure())['timezone']->getName());
        $this->assertSame('Asia/Tokyo', WindowFlipSchedule::settings($this->configure([], ['timezone' => 'Asia/Tokyo']))['timezone']->getName());
        $this->assertSame('UTC', WindowFlipSchedule::settings($this->configure([], ['timezone' => 'Mars/Olympus']))['timezone']->getName());
    }

    /**
     * The day check is in the command rather than only in the cron, and this is why: the boundary a
     * run resolves to can belong to the *next* day — the 23:57 run that is for a 00:05 boundary —
     * and a next day that is not a reader day is a boundary these tasks have no business acting on.
     * The cron visitor cannot express that, because the day it filters on is the day the run fires
     * on rather than the day the boundary falls on.
     *
     * What the day check is *not* is a place for the grace. It was one, and the fold made a run past
     * the grace report itself as the wrong day — two facts with two different repairs collapsed into
     * one verdict — which is why the two questions are separate and this one is asked alone.
     */
    public function test_the_day_check_asks_about_the_boundary_and_nothing_else(): void
    {
        $utc = new DateTimeZone('UTC');
        $boundary = new DateTimeImmutable('2026-10-02 00:05:00', $utc);   // a Friday

        $this->assertSame(5, (int) $boundary->format('N'));

        $this->assertTrue(WindowFlipSchedule::onReaderDay($boundary, [5]), 'a Friday boundary on a reader day');
        $this->assertTrue(WindowFlipSchedule::onReaderDay($boundary, [1, 2, 3, 4, 5, 6, 7]));
        $this->assertFalse(WindowFlipSchedule::onReaderDay($boundary, [1, 2, 3, 4]), 'the same boundary, one day short of a reader day');
        $this->assertFalse(WindowFlipSchedule::onReaderDay($boundary, []), 'and with no reader day at all');
    }

    /**
     * A repeat is harmless because the mode the flipper reports is what decides it, and only a run
     * whose mode is *already* the applied one is skipped — never a run whose mode has never been
     * applied, because that is a fresh container whose pool still has to be told what to do.
     */
    public function test_a_mode_already_applied_is_the_one_thing_that_makes_a_repeat_harmless(): void
    {
        $this->assertTrue(WindowFlipSchedule::alreadyIn('readers', 'readers'));
        $this->assertTrue(WindowFlipSchedule::alreadyIn('writer', 'writer'));

        $this->assertFalse(WindowFlipSchedule::alreadyIn('readers', 'writer'), 'the other mode is a change, not a repeat');
        $this->assertFalse(WindowFlipSchedule::alreadyIn('readers', null), 'nothing has ever been applied, so there is something to do');
    }

    /**
     * The registration on the container's own schedule, asserted as the events it leaves behind:
     * one per boundary, each carrying its own name, its own cron, the configured zone, the reader
     * days, its own log, and the overlap protection that stops two passes overlapping.
     */
    public function test_it_registers_one_event_per_boundary(): void
    {
        $schedule = new Schedule();

        $result = WindowFlipSchedule::register($schedule, $this->configure());

        $this->assertSame(
            ['activate_readers:10:00', 'deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            $result['registered'],
        );
        $this->assertSame([], $result['skipped']);

        $events = $schedule->events();

        $this->assertCount(4, $events, 'four boundaries, four events — one pair per window');

        foreach ($events as $event) {
            $this->assertInstanceOf(Event::class, $event);
            $this->assertStringContainsString(WindowFlipSchedule::COMMAND, (string) $event->command);
            $this->assertNotNull($event->description, 'an unnamed event cannot be guarded against a second one');
        }

        $this->assertSame(
            array_map(static fn (Event $event): string => (string) $event->description, $schedule->events()),
            $result['registered'],
            'the names the registration reports are the names the events carry',
        );
    }

    /**
     * The event's own fields, read one by one, because each is a decision: the name is what a
     * duplicate guard and `schedule:list` read, the cron is the lead window, the zone is the zone the
     * cron is evaluated in, the days are the reader days, the log is where the run's evidence goes,
     * and the overlap is the second layer behind the flipper's own lock.
     */
    public function test_each_event_carries_its_own_boundary_zone_days_and_log(): void
    {
        $schedule = new Schedule();
        WindowFlipSchedule::register($schedule, $this->configure());

        $events = [];

        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        $this->assertSame(
            ['activate_readers:10:00', 'deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            array_keys($events),
        );

        $first = $events['activate_readers:10:00'];

        // Quoted by the shell escaping rather than written plainly — a `--at` carrying a colon is
        // escaped on the platform the package is running on — so the value is matched rather than
        // the literal string, which would be a claim about this machine's quoting.
        $this->assertMatchesRegularExpression('/--mode=["\']?readers["\']?/', (string) $first->command);
        $this->assertMatchesRegularExpression('/--at=["\']?10:00:00["\']?/', (string) $first->command, 'the boundary is the time of day the command resolves');
        $this->assertSame('0,1,2,3,4,5,6,7,8,52,53,54,55,56,57,58,59 9,10 * * 1,2,3,4,5', $first->expression, 'the last field is the reader days, spliced in by the schedule rather than by this class');
        $this->assertSame('UTC', $first->timezone);
        $this->assertSame($this->dir.'/activate_readers-10-00.log', $first->output);
        $this->assertTrue($first->shouldAppendOutput, 'a task that fires eight times per boundary appends');

        $this->assertTrue($first->withoutOverlapping, 'the second layer behind the flipper\'s own lock');
        $this->assertSame(FlipSchedule::OVERLAP_MINUTES, $first->expiresAt, 'a mutex that outlives its run is a task that never happens again');

        $second = $events['deactivate_readers:14:20'];

        $this->assertMatchesRegularExpression('/--mode=["\']?writer["\']?/', (string) $second->command);
        $this->assertMatchesRegularExpression('/--at=["\']?14:20:00["\']?/', (string) $second->command);
        $this->assertSame($this->dir.'/deactivate_readers-14-20.log', $second->output, 'two windows never share one log');
    }

    /**
     * Registering twice adds nothing, because the name identifies one boundary exactly — and that
     * is the difference from the per-minute entry, whose guard has to match the command as well:
     * here every task runs the same command, so a command match would let the second window's task
     * be mistaken for the first's.
     */
    public function test_it_registers_nothing_a_second_time(): void
    {
        $config = $this->configure();
        $schedule = new Schedule();

        WindowFlipSchedule::register($schedule, $config);

        $again = WindowFlipSchedule::register($schedule, $config);

        $this->assertSame([], $again['registered'], 'a second boot must not add a second pair');
        $this->assertCount(4, $schedule->events());

        $this->assertTrue(WindowFlipSchedule::isRegistered($schedule, 'activate_readers:10:00'));

        // An entry carrying one of the names is left alone; the other three are still registered,
        // because they are other boundaries rather than the same one twice.
        $partial = new Schedule();
        $partial->call(static fn (): null => null)->name('activate_readers:10:00');

        $this->assertSame(
            ['deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            WindowFlipSchedule::register($partial, $config)['registered'],
        );
    }

    /**
     * A skipped window is reported, not silently dropped: a schedule with one task instead of two is
     * a pool that moves one way and never comes back, which is the state nobody notices until the
     * next morning.
     */
    public function test_a_skipped_window_is_reported_when_the_schedule_is_built(): void
    {
        $logged = [];

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->level.' '.$message->message;
        });

        $result = WindowFlipSchedule::register(new Schedule(), $this->configure([], ['reader_days' => []]));

        $this->assertSame([], $result['registered']);
        $this->assertCount(1, $result['skipped']);
        $this->assertCount(1, $logged, 'the reason is reported once, at warning level');
        $this->assertStringContainsString('warning', $logged[0]);
        $this->assertStringContainsString('reader_days is empty', $logged[0]);
    }

    /**
     * The log's directory is created by the registration, and the file is named after the task with
     * the boundary's colon replaced — a colon is not a character Windows allows in a file name, and a
     * redirect the shell cannot open is a command that never runs, silently, because the output *is*
     * the file that could not be opened.
     */
    public function test_the_logs_are_named_after_their_task_and_their_directory_is_created(): void
    {
        $this->assertSame('/logs/activate_readers-10-00.log', WindowFlipSchedule::logFor('activate_readers:10:00', '/logs'));
        $this->assertSame('/logs/activate_readers-10-00.log', WindowFlipSchedule::logFor('activate_readers:10:00', '/logs/'), 'a trailing separator is not a doubled one');

        $nested = $this->dir.'/nested/logs';

        $this->assertFalse(is_dir($nested), 'the fixture starts without the directory');

        WindowFlipSchedule::register(new Schedule(), $this->configure(['log_directory' => $nested]));

        $this->assertTrue(is_dir($nested), 'the directory the shell will redirect into has to exist by the time it runs');
    }

    /**
     * The tasks are on the schedule because the provider put them there — the container resolves its
     * own `Schedule`, exactly as `schedule:run` and `schedule:list` do, and the settings are read at
     * that moment rather than at boot.
     *
     * Both directions are asserted, because the failure that matters is the quiet one: a schedule
     * that carries the tasks while they are switched off would flip the pool on an installation that
     * never asked for it, and a schedule that carries none while they are on is the missing entry the
     * whole class exists to prevent.
     */
    public function test_the_provider_registers_them_when_the_schedule_is_resolved(): void
    {
        $this->configure();

        $registered = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => is_string($event->description)
                && (str_starts_with($event->description, WindowFlipSchedule::TASK_ACTIVATE.':')
                    || str_starts_with($event->description, WindowFlipSchedule::TASK_DEACTIVATE.':')),
        ));

        $this->assertCount(4, $registered, 'the provider registers both tasks for both windows');
        $this->assertSame(
            ['activate_readers:10:00', 'deactivate_readers:14:20', 'activate_readers:17:00', 'deactivate_readers:20:30'],
            array_map(static fn (Event $event): string => (string) $event->description, $registered),
        );

        // Off: the provider registers nothing, which is the half that is easy to get wrong — a
        // registration that ignored its own switch would be a pool flipped on a schedule nobody armed.
        $this->configure(['enabled' => false]);
        $this->app->forgetInstance(Schedule::class);

        $this->assertSame(
            [],
            array_filter(
                $this->app->make(Schedule::class)->events(),
                static fn (Event $event): bool => is_string($event->description)
                    && str_starts_with($event->description, WindowFlipSchedule::TASK_ACTIVATE.':'),
            ),
            'an installation that has not armed the tasks gets none of them',
        );
    }

    /**
     * Remove `$directory` and everything under it.
     */
    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir()) {
                @rmdir($entry->getPathname());

                continue;
            }

            @unlink((string) $entry->getPathname());
        }

        @rmdir($directory);
    }
}

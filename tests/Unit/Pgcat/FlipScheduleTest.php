<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use FilesystemIterator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The flip's schedule entry: whether it is registered at all, what it carries when it is, and
 * what stops a second one.
 *
 * The subject is a package decision rather than an installation's, which is why it is pinned here
 * rather than left to whichever application happens to call it: every choice in the entry — the
 * cadence, the per-container mutex, the absence of `onOneServer()` — follows from the flip being
 * container-local, and an entry written by hand is an entry that can be written wrongly in a way
 * that looks reasonable. So the four answers that matter are asserted directly: **when** it is
 * registered (its own switch, following `pgcat.enabled` when it is not given one), **what** it is
 * registered as (the command, the cadence, the mutex name, the name and the log), **once**
 * (already-registered is not registered again, whether by this class or by the installation), and
 * that the provider is what puts it there — the last one through the container's own `Schedule`,
 * because a registration nobody wires up is the failure this whole file is about.
 *
 * The file system is the suite's own temporary directory rather than an installation's: the log's
 * directory is created by the registration, and a test that let that happen under `storage/` would
 * be writing into the checkout it is checking.
 */
final class FlipScheduleTest extends TestCase
{
    /** A directory of this test's own, for the logs the registration is pointed at. */
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/flip-schedule-test-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * The config repository the package reads, with `pgcat` armed as the test says.
     *
     * `$schedule` is null for the installation that publishes no `schedule` block at all, which is
     * a different thing from one that publishes an empty block and is the case the documented
     * defaults are about.
     *
     * @param array<string, mixed> $pgcat
     * @param array<string, mixed>|null $schedule
     */
    private function configure(array $pgcat, ?array $schedule = null): Repository
    {
        if ($schedule !== null) {
            $pgcat['schedule'] = $schedule;
        }

        $config = $this->app->make(Repository::class);
        $config->set('db-manager.swrr.pgcat', $pgcat);

        return $config;
    }

    /** A log inside this test's own directory, so nothing is written near the checkout. */
    private function log(string $name): string
    {
        return $this->dir.'/'.$name;
    }

    public function test_it_schedules_the_flip_every_minute_with_a_mutex_of_its_own(): void
    {
        $log = $this->log('pgcat_flip.log');
        $schedule = new Schedule();

        $event = FlipSchedule::register($schedule, $this->configure(['enabled' => true], ['log' => $log]));

        $this->assertNotNull($event, 'the flip is scheduled once pgcat is armed');
        $this->assertSame([$event], $schedule->events(), 'one entry, and it is the one returned');

        $this->assertStringContainsString(FlipSchedule::COMMAND, (string) $event->command);
        $this->assertSame('* * * * *', $event->expression, 'every minute is the cadence the flip is designed around');
        $this->assertSame(FlipSchedule::DEFAULT_NAME, $event->description, 'the entry carries the configured name');
        $this->assertSame($log, $event->output, 'the run output goes where the config says');
        $this->assertTrue($event->shouldAppendOutput, 'a run a minute appends; it must not replace the previous run');

        $this->assertTrue($event->withoutOverlapping, 'the second layer: the previous run must be finished');
        $this->assertSame(FlipSchedule::OVERLAP_MINUTES, $event->expiresAt, 'a mutex that outlives its run is a flip that never happens again');

        $this->assertSame(FlipSchedule::mutexName(gethostname()), $event->mutexName(), 'the mutex is scoped to this container, not to the command');
    }

    /**
     * The cadence is the setting rather than a constant: `interval_minutes: 5` is the same entry
     * with a step of five in the minute field, and the minute field is why this setting is whole
     * minutes and has a ceiling.
     */
    public function test_the_cadence_is_the_interval_the_installation_asks_for(): void
    {
        $event = FlipSchedule::register(new Schedule(), $this->configure(
            ['enabled' => true],
            ['log' => $this->log('five.log'), 'interval_minutes' => 5],
        ));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression, 'five minutes is a step of five in the minute field');

        // From the top of an hour the next run is at :05 rather than at :01, which is the whole of
        // what "every five minutes" means here, asked of the event rather than of the string.
        $this->assertSame('00:05', $event->nextRunDate('2026-10-01 00:00:00')->format('H:i'));
        $this->assertSame('00:10', $event->nextRunDate('2026-10-01 00:05:00')->format('H:i'));

        // The same setting as an `.env` file holds it, and the shapes the constants name.
        $this->assertSame(5, FlipSchedule::options($this->configure(['enabled' => true], ['interval_minutes' => '5']))['interval_minutes']);
        $this->assertSame(FlipSchedule::EXPRESSION_EVERY_MINUTE, FlipSchedule::expression(FlipSchedule::DEFAULT_INTERVAL_MINUTES));
        $this->assertSame('*/30 * * * *', FlipSchedule::expression(30));
        $this->assertSame('*/59 * * * *', FlipSchedule::expression(FlipSchedule::MAX_INTERVAL_MINUTES));
    }

    /**
     * What the setting cannot express is not obeyed.
     *
     * The ceiling is not caution, and the expression below is here so that nobody has to take that
     * on trust: the cron library the scheduler itself runs on accepts a step of 90 in the minute
     * field and reports its next run at minute **30**, because the field wraps. That is a valid
     * expression meaning something nobody wrote. So anything past the top of the field resolves to
     * the documented minute — and so does every other way this setting can be unreadable, all of
     * which fail towards running *more* often than was asked for, never less.
     */
    public function test_an_interval_the_minute_field_cannot_hold_is_not_obeyed(): void
    {
        $ninety = (new Schedule())->command(FlipSchedule::COMMAND)->cron('*/90 * * * *');

        $this->assertSame('00:30', $ninety->nextRunDate('2026-10-01 00:00:00')->format('H:i'), 'the field wraps: a step of 90 is minute 30');
        $this->assertSame(59, FlipSchedule::MAX_INTERVAL_MINUTES, 'the ceiling is the top of the minute field');

        foreach ([0, -1, 60, 90, 1440, 'lol', ''] as $written) {
            $options = FlipSchedule::options($this->configure(['enabled' => true], ['interval_minutes' => $written]));

            $this->assertSame(
                FlipSchedule::DEFAULT_INTERVAL_MINUTES,
                $options['interval_minutes'],
                'the interval written as '.var_export($written, true).' is not one the minute field can hold',
            );
            $this->assertSame(FlipSchedule::EXPRESSION_EVERY_MINUTE, FlipSchedule::expression($options['interval_minutes']));
        }

        // ...and the absent one, which is the ordinary case rather than a mistake.
        $this->assertSame(
            FlipSchedule::DEFAULT_INTERVAL_MINUTES,
            FlipSchedule::options($this->configure(['enabled' => true]))['interval_minutes'],
        );
    }

    /**
     * Left unset, the switch follows `pgcat.enabled` — the honest default, because an installation
     * that has not armed the flipper has nothing for a schedule to keep in step, and an entry that
     * fired every minute anyway would be a command whose every run is a no-op.
     */
    public function test_the_schedule_follows_the_pgcat_switch_when_it_is_not_given_one(): void
    {
        $this->assertNotNull(
            FlipSchedule::register(new Schedule(), $this->configure(['enabled' => true], ['log' => $this->log('follows-on.log')])),
            'pgcat armed and no schedule switch: the flip is scheduled',
        );

        $this->assertNull(
            FlipSchedule::register(new Schedule(), $this->configure(['enabled' => false])),
            'pgcat not armed: nothing to keep in step, so nothing is scheduled',
        );
    }

    public function test_an_explicit_switch_overrides_the_one_it_follows(): void
    {
        $on = $this->configure(['enabled' => false], ['enabled' => true, 'log' => $this->log('override-on.log')]);

        $this->assertNotNull(FlipSchedule::register(new Schedule(), $on), 'scheduled on its own switch, with pgcat off');

        foreach (['false', 'off', '0', 'no'] as $spelling) {
            $this->assertNull(
                FlipSchedule::register(new Schedule(), $this->configure(['enabled' => true], ['enabled' => $spelling])),
                "the spelling '{$spelling}' is off, and a cast is not what reads it",
            );
        }
    }

    /**
     * The refusal is the half that has to be loud: `(bool) 'flase'` is true, so a typo here would
     * otherwise decide the schedule and tell nobody.
     *
     * What it resolves *to* is the default the setting documents — `swrr.pgcat.enabled` — and that
     * direction is deliberate. Treating a refused value as off would let one mistyped character
     * disarm the repair, which is a worse outcome than the one the switch guards; so the flip keeps
     * running and the typo is named instead. The second half of the test is the same reading from
     * the other side: with pgcat itself off, the default the refusal falls back to is off too.
     */
    public function test_a_value_that_is_not_a_switch_is_reported_and_resolves_to_the_default_it_documents(): void
    {
        $logged = [];

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->level.' '.$message->message;
        });

        $config = $this->configure(['enabled' => true], ['enabled' => 'flase']);
        $options = FlipSchedule::options($config);

        $this->assertSame('"flase"', $options['refused'], 'the value is carried as it was written');
        $this->assertTrue($options['enabled'], 'a refusal resolves to the value the setting documents, which here is pgcat\'s own switch');

        $this->assertNotNull(
            FlipSchedule::register(new Schedule(), $config),
            'a typo must not be what stops the flip',
        );

        $this->assertCount(1, $logged, 'the refusal is reported, not decided in silence');
        $this->assertStringContainsString('warning', $logged[0]);
        $this->assertStringContainsString('swrr.pgcat.schedule.enabled', $logged[0]);
        $this->assertStringContainsString('"flase"', $logged[0]);
        $this->assertStringContainsString('Refused', $logged[0]);

        $this->assertNull(
            FlipSchedule::register(new Schedule(), $this->configure(['enabled' => false], ['enabled' => 'flase'])),
            'the document\'s default is pgcat in both directions: with pgcat off, a refused switch is off as well',
        );
    }

    public function test_the_defaults_are_the_name_and_the_log_this_package_documents(): void
    {
        $options = FlipSchedule::options($this->configure(['enabled' => true]));

        $this->assertSame('pgcat_flip', FlipSchedule::DEFAULT_NAME);
        $this->assertSame(FlipSchedule::DEFAULT_NAME, $options['name']);
        $this->assertSame(storage_path(FlipSchedule::DEFAULT_LOG), $options['log']);
        $this->assertStringEndsWith(
            'logs/scheduled_tasks/pgcat_flip.log',
            str_replace('\\', '/', $options['log']),
            'the default log is the one the README documents, in the application storage directory',
        );
    }

    /**
     * An empty string is a published config with a hole in it rather than a decision, and both of
     * these are load-bearing: an empty name matches every installation that also wrote nothing,
     * and an empty log is a redirect the shell cannot open.
     */
    public function test_a_blank_name_or_log_falls_back_to_the_documented_default(): void
    {
        $options = FlipSchedule::options($this->configure(['enabled' => true], ['name' => '', 'log' => '']));

        $this->assertSame(FlipSchedule::DEFAULT_NAME, $options['name']);
        $this->assertSame(storage_path(FlipSchedule::DEFAULT_LOG), $options['log']);
    }

    /**
     * A command event runs through a shell redirect (`>> <log> 2>&1`), so a log whose directory
     * does not exist is a command that never runs — silently, because the output is the file that
     * could not be opened. Registering the entry therefore has to be what creates it.
     */
    public function test_it_creates_the_directory_the_log_is_written_into(): void
    {
        $log = $this->dir.'/nested/logs/pgcat_flip.log';

        $this->assertFalse(is_dir(dirname($log)), 'the fixture starts without the directory');

        FlipSchedule::register(new Schedule(), $this->configure(['enabled' => true], ['log' => $log]));

        $this->assertTrue(is_dir(dirname($log)), 'the directory the shell will redirect into has to exist by the time it runs');
    }

    public function test_it_adds_nothing_when_the_entry_is_already_there(): void
    {
        $config = $this->configure(['enabled' => true], ['log' => $this->log('twice.log')]);
        $schedule = new Schedule();

        $this->assertNotNull(FlipSchedule::register($schedule, $config));

        $this->assertNull(FlipSchedule::register($schedule, $config), 'a second boot must not add a second per-minute run');
        $this->assertCount(1, $schedule->events());
        $this->assertTrue(FlipSchedule::isRegistered($schedule, FlipSchedule::DEFAULT_NAME));
    }

    /**
     * The other reading of "already there", and the one the doubled run actually arrives by: an
     * entry the installation wrote itself — deliberately, or left behind from the snippet the
     * README used to hand out, which named no task at all and so cannot be found by name.
     *
     * Skipping is the right answer rather than joining it: two entries are two `schedule:run`
     * passes a minute, and the overlap protection of one cannot serialise the other.
     */
    public function test_it_adds_nothing_beside_an_entry_the_installation_wrote(): void
    {
        $config = $this->configure(['enabled' => true], ['log' => $this->log('theirs.log')]);

        $byCommand = new Schedule();
        $byCommand->command(FlipSchedule::COMMAND)->everyMinute();

        $this->assertTrue(FlipSchedule::isRegistered($byCommand, FlipSchedule::DEFAULT_NAME));
        $this->assertNull(FlipSchedule::register($byCommand, $config), 'an entry already running the command is left alone');
        $this->assertCount(1, $byCommand->events());

        $byName = new Schedule();
        $byName->call(static fn (): null => null)->name(FlipSchedule::DEFAULT_NAME);

        $this->assertTrue(FlipSchedule::isRegistered($byName, FlipSchedule::DEFAULT_NAME));
        $this->assertNull(FlipSchedule::register($byName, $config), 'an entry already carrying the name is left alone');
        $this->assertCount(1, $byName->events());
    }

    /**
     * `withoutOverlapping()` names its mutex `sha1(expression + command)` by itself, which is the
     * *same name in every container* when the cache store is shared — so one slow container would
     * hold the lock for all of them. The name is scoped instead, and the hostname being unreadable
     * must not put every container back on one shared name.
     */
    public function test_a_hostname_that_cannot_be_read_does_not_collapse_every_container_onto_one_mutex(): void
    {
        $this->assertSame('framework/schedule-pgcat-flip-web-1', FlipSchedule::mutexName('web-1'));

        $empty = FlipSchedule::mutexName('');
        $false = FlipSchedule::mutexName(false);

        foreach ([$empty, $false] as $name) {
            $this->assertStringStartsWith(FlipSchedule::MUTEX_PREFIX, $name);
            $this->assertNotSame(FlipSchedule::MUTEX_PREFIX, $name, 'the bare prefix is the one name every container would share');
        }

        $this->assertNotSame($empty, $false, 'each unreadable hostname gets a name of its own');

        $host = gethostname();

        if (is_string($host) && $host !== '') {
            $this->assertSame(FlipSchedule::MUTEX_PREFIX.$host, FlipSchedule::mutexName($host), 'a readable hostname is used as it is');
        }
    }

    /**
     * The entry is on the schedule because the provider put it there — the container resolves its
     * own `Schedule`, exactly as `schedule:run` and `schedule:list` do, and the config is read at
     * that moment rather than at boot.
     */
    public function test_the_provider_registers_it_when_the_schedule_is_resolved(): void
    {
        $log = $this->log('wired.log');

        $this->configure(['enabled' => true], ['log' => $log]);

        $schedule = $this->app->make(Schedule::class);

        $registered = array_values(array_filter(
            $schedule->events(),
            static fn ($event): bool => $event->description === FlipSchedule::DEFAULT_NAME,
        ));

        $this->assertCount(1, $registered, 'the provider registers the flip on the schedule the framework builds');
        $this->assertStringContainsString(FlipSchedule::COMMAND, (string) $registered[0]->command);
        $this->assertSame($log, $registered[0]->output, 'the settings are read when the schedule is resolved, so this one reached it');
    }

    /**
     * Remove `$directory` and everything under it.
     *
     * The registration creates the log's directory, so the fixture is a tree rather than a list of
     * files, and a suite that left one behind per run would fill a temp directory with them.
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

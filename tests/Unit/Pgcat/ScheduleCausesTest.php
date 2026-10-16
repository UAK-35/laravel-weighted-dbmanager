<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container as BareContainer;
use Illuminate\Contracts\Cache\Factory;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;
use Uak35\WeightedDbManager\Tests\Support\FakeCache;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The readings a silent schedule is diagnosed with, each driven as a *state* rather than as a stubbed
 * sentence: an entry on a real `Schedule`, a cache binding that answers or refuses, a stamp on disk
 * with a mode the platform either respects or ignores.
 *
 * The two halves of the object are asked separately, because they have different dependencies. What a
 * *container* can read — that an installation which never armed the schedule reads nothing, that an
 * armed one reads the schedule it built, that a run which may not reach out says so — is driven
 * through `fromContainer()`. What the *readings* say is driven through the constructor, with the
 * schedule and the stamp handed in, so the sentences can be asserted without a container deciding
 * anything about them.
 *
 * The unreadable stamp is its own test and skips where the platform ignores mode bits, which is the
 * same discipline the flipper's own unreadable-source case uses: the branch has to be reached at all
 * before the sentence it prints can be asked about.
 */
final class ScheduleCausesTest extends TestCase
{
    /** The name the entry carries in this suite. */
    private const NAME = 'pgcat_flip';

    /**
     * Directories made this run, removed at the end of each test — each of them made writable again
     * on the way out, because two of these tests deliberately leave a file the platform will not
     * overwrite, and a file like that cannot be unlinked where the mode is enforced either.
     *
     * @var list<string>
     */
    private array $tempDirs = [];

    /** A counter, so no two tests of one process share a directory even when one leaves a file behind. */
    private static int $dirs = 0;

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
     * An installation that has not armed the entry is not asked anything, and the schedule is not
     * even resolved — which is the half of that rule that matters rather than the convenience:
     * resolving the schedule is what registers this package's entry, so a reading taken for an
     * installation that never armed it would hand that installation an entry it never asked for.
     */
    public function test_an_installation_that_never_armed_the_schedule_reads_nothing(): void
    {
        $causes = ScheduleCauses::fromContainer($this->app, false, self::NAME, $this->stamp());

        $this->assertSame([], $causes->readings(), 'an entry that is not scheduled has no causes to read');
        $this->assertSame('', $causes->sentence(), 'and nothing to append to a row or a healthcheck line');
        $this->assertFalse(
            $this->app->resolved(Schedule::class),
            'the schedule is not resolved either, so asking for these readings cannot register an entry',
        );
    }

    /**
     * Armed: the three keys, in the order the questions are asked, and each reading says what it
     * found rather than whether it passed.
     */
    public function test_the_three_readings_are_named_when_the_entry_is_armed(): void
    {
        $stamp = $this->stamp();

        $causes = ScheduleCauses::fromContainer($this->app, true, self::NAME, $stamp);
        $readings = $causes->readings();

        $this->assertSame(
            [ScheduleCauses::KEY_MUTEX_STORE, ScheduleCauses::KEY_ENTRY, ScheduleCauses::KEY_BOOT_STAMP],
            array_column($readings, 'key'),
            'the keys are the three questions, in the order they are asked',
        );

        $this->assertStringContainsString('mutexes live in', $readings[0]['reading']);
        $this->assertStringContainsString('answered a read', $readings[0]['reading']);
        $this->assertStringContainsString('1 entry carries `'.self::NAME.'`', $readings[1]['reading']);
        $this->assertStringContainsString($stamp, $readings[2]['reading']);
        $this->assertStringContainsString('readable and writable', $readings[2]['reading']);

        $sentence = $causes->sentence();

        $this->assertStringStartsWith('The schedule\'s own readings: ', $sentence);
        $this->assertStringEndsWith('.', $sentence);
        $this->assertSame(
            2,
            substr_count($sentence, ';'),
            'three readings are joined as one sentence, so two separators',
        );
    }

    /**
     * Two entries running the same command is the shape no installation should have, and the reading
     * says why it matters: the framework's mutex cannot serialise them, so the flipper's lock is what
     * makes the second one skip.
     */
    public function test_a_second_entry_for_the_same_command_is_reported_as_two_runs_a_minute(): void
    {
        $schedule = $this->schedule(named: 1, unnamed: 1);

        $reading = $this->reading($schedule, ScheduleCauses::KEY_ENTRY);

        $this->assertStringContainsString('2 entries run db:pgcat-flip', $reading);
        $this->assertStringContainsString('1 of them carrying `'.self::NAME.'`', $reading);
        $this->assertStringContainsString('two runs a minute', $reading);
        $this->assertStringContainsString('flipper\'s lock is what makes the second `skipped`', $reading);
    }

    /**
     * One entry that is not this package's: the installation's own registration, which the duplicate
     * guard deliberately leaves alone — so the reading says whose it is rather than reporting a fault.
     */
    public function test_an_entry_that_is_not_the_packages_is_reported_as_the_installations_own(): void
    {
        $reading = $this->reading($this->schedule(unnamed: 1), ScheduleCauses::KEY_ENTRY);

        $this->assertStringContainsString('1 entry runs db:pgcat-flip', $reading);
        $this->assertStringContainsString('none carries `'.self::NAME.'`', $reading);
        $this->assertStringContainsString('the installation registered its own', $reading);
    }

    /**
     * No entry at all, with the entry armed: a schedule built before the entry was armed, which is a
     * state the command has to be able to describe without pretending it found one.
     */
    public function test_a_schedule_with_no_entry_for_the_command_says_so(): void
    {
        $reading = $this->reading($this->schedule(), ScheduleCauses::KEY_ENTRY);

        $this->assertStringContainsString('no entry on the schedule runs db:pgcat-flip', $reading);
        $this->assertStringContainsString('nothing to fire for this package', $reading);
    }

    /**
     * The store, named and asked. `Schedule::useCache()` reaches the *mutex object*, so the store a
     * reading asks for is the one the schedule named — not the installation's default, which a report
     * reading the cache configuration would name instead.
     */
    public function test_the_store_asked_is_the_one_the_schedule_names(): void
    {
        $cache = new FakeCache();
        $this->bindCache($cache);

        // The store is read off the *entry's* mutex, so the schedule needs the entry the reading is
        // taken beside — and `useCache()` is called the way an application's scheduling code calls it.
        $schedule = $this->schedule(named: 1);
        $schedule->useCache('a-store-named-by-the-schedule');

        $reading = $this->reading($schedule, ScheduleCauses::KEY_MUTEX_STORE);

        $this->assertSame(['a-store-named-by-the-schedule'], $cache->asked, 'the store the mutex names is the one asked');
        $this->assertStringContainsString('(a-store-named-by-the-schedule)', $reading);
        $this->assertStringContainsString('answered a read', $reading);
    }

    /**
     * A store that does not answer, in its own words: this is the cause a verdict cannot see, because
     * the beat goes stale exactly as it does when the cron line dies.
     */
    public function test_a_store_that_does_not_answer_carries_the_reason_it_gave(): void
    {
        $this->bindCache(new FakeCache(failure: 'Connection refused [tcp://127.0.0.1:6379]'));

        $schedule = $this->schedule(named: 1);
        $schedule->useCache('redis');

        $reading = $this->reading($schedule, ScheduleCauses::KEY_MUTEX_STORE);

        $this->assertStringContainsString('(redis) did not answer', $reading);
        $this->assertStringContainsString('Connection refused [tcp://127.0.0.1:6379]', $reading);
        $this->assertStringContainsString('throws on `withoutOverlapping()` before it reaches the flip', $reading);
        $this->assertStringContainsString('the entries after it do not run either', $reading);
    }

    /**
     * A schedule carrying no entry at all has no mutex to read yet, which is a different state from a
     * mutex that needs no store — and a schedule that is armed but empty is exactly the state the
     * entry reading reports alongside this one.
     */
    public function test_a_schedule_with_no_entry_yet_says_which_mutex_is_not_known(): void
    {
        $reading = $this->reading(new Schedule(), ScheduleCauses::KEY_MUTEX_STORE);

        $this->assertStringContainsString('carries no entry yet', $reading);
        $this->assertStringNotContainsString('not the cache-backed one', $reading);
    }

    /**
     * An `EventMutex` the installation bound itself needs no store, so there is nothing to ask — and
     * the reading says so rather than naming a store that is not consulted.
     */
    public function test_a_mutex_that_is_not_cache_backed_is_reported_as_the_installations_own(): void
    {
        $this->app->instance(EventMutex::class, new class () implements EventMutex {
            public function create(Event $event): bool
            {
                return true;
            }

            public function exists(Event $event): bool
            {
                return false;
            }

            public function forget(Event $event): void
            {
            }
        });

        $reading = $this->reading($this->schedule(named: 1), ScheduleCauses::KEY_MUTEX_STORE);

        $this->assertStringContainsString('overlap mutex is not the cache-backed one', $reading);
        $this->assertStringContainsString(EventMutex::class, $reading);
    }

    /**
     * A run that may not reach out says which run will: the reading is a fact about the omission
     * rather than a silence where the answer would be, which is the difference between "nothing to
     * report" and "not asked" the report must never blur.
     */
    public function test_a_store_that_is_not_asked_says_which_run_asks_it(): void
    {
        $causes = ScheduleCauses::fromContainer(
            $this->app,
            true,
            self::NAME,
            $this->stamp(),
            'a healthcheck answers from the state file and asks nothing that can time out',
        );

        $readings = $causes->readings();

        $this->assertStringContainsString('was not asked', $readings[0]['reading']);
        $this->assertStringContainsString('asks nothing that can time out', $readings[0]['reading']);
        $this->assertStringNotContainsString('answered a read', $readings[0]['reading']);
        $this->assertStringContainsString('1 entry carries', $readings[1]['reading'], 'the other readings are taken either way');
    }

    /**
     * A container that cannot build a schedule: both schedule-derived readings say what they could not
     * read, and they say it in their own words rather than twice in one, so a reader can tell that two
     * questions were asked and neither was answered.
     */
    public function test_a_container_with_no_schedule_reports_both_readings_as_unread(): void
    {
        // A container with no cache binding, which is what a schedule needs: the mutex it resolves
        // when it is built is the cache-backed one, and nothing here can hand it a store — so the
        // *global* instance is swapped for the duration, because that is where `Schedule` looks for
        // the mutex rather than at the container it is being built in.
        $bare = new BareContainer();
        $previous = BareContainer::getInstance();
        BareContainer::setInstance($bare);

        try {
            $causes = ScheduleCauses::fromContainer($bare, true, self::NAME, $this->stamp());
        } finally {
            BareContainer::setInstance($previous);
        }

        $readings = $causes->readings();

        $this->assertStringContainsString('built no schedule to read', $readings[0]['reading']);
        $this->assertStringContainsString('the store its runs\' mutexes live in was not read', $readings[0]['reading']);
        $this->assertStringContainsString('built no schedule to read', $readings[1]['reading']);
        $this->assertStringContainsString('were not counted', $readings[1]['reading']);
        $this->assertStringContainsString('readable', $readings[2]['reading'], 'the stamp is still read: it does not need a schedule');
    }

    /**
     * No stamp at all: the window is measured from nothing, which is a local run or an image whose
     * entrypoint predates the stamp — reported as the reading it is, not as a fault.
     */
    public function test_an_armed_installation_with_no_stamp_says_the_window_is_not_judged(): void
    {
        $missing = $this->dir().'/container-booted-at';

        $reading = $this->reading($this->schedule(named: 1), ScheduleCauses::KEY_BOOT_STAMP, bootFile: $missing);

        $this->assertStringContainsString('there is no boot stamp at '.$missing, $reading);
        $this->assertStringContainsString('the window is not judged and neither is the beat inside it', $reading);
    }

    /**
     * An empty setting is its own state rather than a missing file: the window is measured from
     * nothing *on every boot*, which is a configuration to fix.
     */
    public function test_an_empty_boot_file_setting_says_the_window_is_measured_from_nothing(): void
    {
        $reading = $this->reading($this->schedule(named: 1), ScheduleCauses::KEY_BOOT_STAMP, bootFile: '');

        $this->assertStringContainsString('swrr.pgcat.boot_file is empty', $reading);
        $this->assertStringContainsString('measured from nothing on every boot', $reading);
    }

    /**
     * A stamp that is there and cannot be read: the window reports a container nobody stamped, so a
     * schedule that stopped is reported as one nobody asked about — and the reading names the repair.
     *
     * Skipped where the platform ignores mode bits, which is where the branch cannot be reached at
     * all: the sentence is about a file this process was refused.
     */
    public function test_a_stamp_that_cannot_be_read_says_which_silence_it_causes(): void
    {
        $stamp = $this->stamp();
        @chmod($stamp, 0o000);

        if (is_readable($stamp)) {
            $this->markTestSkipped('This environment cannot make a file unreadable.');
        }

        $reading = $this->reading($this->schedule(named: 1), ScheduleCauses::KEY_BOOT_STAMP, bootFile: $stamp);

        $this->assertStringContainsString('is there and cannot be read by this process', $reading);
        $this->assertStringContainsString('mode 0000', $reading);
        $this->assertStringContainsString('judged as a container that never stamped a file', $reading);
        $this->assertStringContainsString('grant it to the user the schedule runs as', $reading);
    }

    /**
     * Readable and not writable: the reading reports both bits, because the half that decides whether
     * the window is judged is the read one, and the row that judges what a flip must write is not this
     * one.
     */
    public function test_a_stamp_that_cannot_be_written_is_reported_as_readable_without_being_failed(): void
    {
        $stamp = $this->stamp();
        @chmod($stamp, 0o444);

        if (is_writable($stamp)) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        $reading = $this->reading($this->schedule(named: 1), ScheduleCauses::KEY_BOOT_STAMP, bootFile: $stamp);

        $this->assertStringContainsString('is readable and not writable', $reading);
        $this->assertStringContainsString('mode 0444', $reading);
        $this->assertStringNotContainsString('cannot be read', $reading, 'a read-only stamp is still read');
    }

    /**
     * One reading of one key, from the object as a surface would build it.
     *
     * @return string the reading under `$key`
     */
    private function reading(
        Schedule $schedule,
        string $key,
        string $bootFile = 'the-boot-stamp',
    ): string {
        $causes = new ScheduleCauses(true, self::NAME, $bootFile, $schedule);

        foreach ($causes->readings() as $reading) {
            if ($reading['key'] === $key) {
                return $reading['reading'];
            }
        }

        $this->fail("The readings carry no [{$key}] reading");
    }

    /**
     * A schedule with `db:pgcat-flip` on it, `$named` of those entries carrying this installation's
     * name for it — the shapes `FlipSchedule::entries()` separates.
     */
    private function schedule(int $named = 0, int $unnamed = 0): Schedule
    {
        $schedule = new Schedule();

        for ($i = 0; $i < $named; $i++) {
            $schedule->command('db:pgcat-flip')->name(self::NAME);
        }

        for ($i = 0; $i < $unnamed; $i++) {
            $schedule->command('db:pgcat-flip');
        }

        return $schedule;
    }

    /**
     * The cache binding this run's schedules take their mutex from: bound before the schedule is
     * built, because the mutex is resolved when `Schedule` is constructed.
     */
    private function bindCache(FakeCache $cache): void
    {
        $this->app->instance(Factory::class, $cache);
    }

    /** A boot stamp an hour old, which is a container whose window has closed. */
    private function stamp(): string
    {
        $path = $this->dir().'/container-booted-at';

        file_put_contents($path, (string) (time() - 3600));

        return $path;
    }

    /** A temp directory of this run's own, removed in tearDown. */
    private function dir(): string
    {
        $dir = sys_get_temp_dir().'/swrr-causes-'.getmypid().'-'.self::$dirs++;

        if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            $this->fail("Could not create {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}

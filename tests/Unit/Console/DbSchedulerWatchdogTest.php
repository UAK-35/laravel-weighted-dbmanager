<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Console\Commands\DbSchedulerWatchdog;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\ScheduleBeat;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;
use stdClass;

/**
 * The exit code of `db:scheduler-watchdog` as a function of the state it finds.
 *
 * A watchdog is the one command in this package whose *success* is an exit code `0` that nothing
 * else would notice: it runs on a container's healthcheck, its output goes to a log nobody reads
 * while the container is well, and the only moment it matters is the one it fails. So every route
 * is a cell of the matrix, both channels are driven for each, and `--strict` is a third axis
 * because it is the difference between a container that is restarted when its schedule dies and
 * one whose schedule was switched off before it died.
 *
 * Every route is built from a real installation in a temp directory — a state file the flip would
 * have written, a boot stamp the entrypoint would have written, and a `ScheduleBeat` configured the
 * way `swrr.pgcat.schedule` configures one — rather than from a stubbed verdict, because the whole
 * claim of this command is that it reports the flipper's verdict rather than one of its own.
 *
 * The one route that is not an installation is `unbound`, and it is the same fixture the flip's own
 * matrix uses: a container with the binding replaced by something that is not a flipper.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbReplicaStatusTest::test_the_exit_code_is_a_function_of_the_route_and_the_channel
 */
final class DbSchedulerWatchdogTest extends TestCase
{
    /** The README section this command's two tables live under. */
    private const HEADING = '### When the schedule stops: `db:scheduler-watchdog`';

    /**
     * The README row each route is documented as — the correspondence between the matrix and the
     * table an operator reads.
     *
     * Two routes share a row twice over: the boot window and the absent stamp are one documented
     * case, and so are a beat that went quiet and one that never arrived. That is the table's claim
     * rather than this file's convenience — `ScheduleBeat` decides that each pair is one verdict
     * with two sentences — and it is why the documented cases outnumber the cells here the way the
     * flip's ten do against its eighteen.
     */
    private const ROWS = [
        'the per-minute entry is producing runs' => 'the per-minute entry is producing runs',
        'the per-minute entry is not scheduled' => 'the per-minute entry is not scheduled, so nothing stamps a beat',
        'the container is still inside its boot window' => 'the container is still inside its boot window, so nothing is judged yet',
        'the container recorded no boot time' => 'the container recorded no boot time, so the window cannot be judged',
        'the watch is switched off' => 'the watch is switched off (`swrr.pgcat.schedule.stale_after_missed_runs`)',
        'the last run is older than the tolerance' => 'the last run is older than the tolerance — the schedule has stopped',
        'no run has been recorded at all since the window closed' => 'no run has been recorded at all since the window closed',
        'the container has no flipper' => 'the container has no flipper, so there is no schedule to read',
    ];

    /**
     * The line each route's sentence is recognised by.
     *
     * A fragment rather than the whole sentence, because the sentence is the flipper's and this
     * file must not become a second copy of it — what is asserted here is that *a* sentence reached
     * the terminal and that it is the one this route's verdict carries, which is the half of the
     * contract a code alone cannot hold.
     */
    private const LINE = [
        'the per-minute entry is producing runs' => 'last ran',
        'the per-minute entry is not scheduled' => 'is not scheduled',
        'the container is still inside its boot window' => 'still inside its boot window',
        'the container recorded no boot time' => 'no boot time',
        'the watch is switched off' => 'switched off',
        'the last run is older than the tolerance' => 'has not run',
        'no run has been recorded at all since the window closed' => 'No run of',
        'the container has no flipper' => 'is not registered',
    ];

    /** The container's boot, as a fixed moment, so every age in the matrix is a whole number. */
    private int $now = 1_800_000_000;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(PgcatConfigFlipper::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The states a run can find, one per route — and each row's `kind`, the code it exits by
     * default, and the code `--strict` makes it exit.
     *
     * `--strict` is a field of the route rather than a route of its own, because it is a flag on
     * a run rather than a state of the installation; the two codes live side by side here for the
     * same reason the doctor's rows carry both `--strict` columns.
     *
     * @return array<string, array{bound: bool, scheduled: bool, missed: int, interval: int, window: string, beat: string, kind: string, exit: int, strict_exit: int}>
     */
    public static function routeProvider(): array
    {
        return [
            'the per-minute entry is producing runs' => [
                'bound' => true, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'closed', 'beat' => 'recent',
                'kind' => DbSchedulerWatchdog::KIND_RUNNING, 'exit' => 0, 'strict_exit' => 0,
            ],
            'the per-minute entry is not scheduled' => [
                'bound' => true, 'scheduled' => false, 'missed' => 5, 'interval' => 1,
                'window' => 'closed', 'beat' => 'recent',
                'kind' => DbSchedulerWatchdog::KIND_NOT_SCHEDULED, 'exit' => 0, 'strict_exit' => 0,
            ],
            'the container is still inside its boot window' => [
                'bound' => true, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'open', 'beat' => 'none',
                'kind' => DbSchedulerWatchdog::KIND_NOT_JUDGED, 'exit' => 0, 'strict_exit' => 0,
            ],
            'the container recorded no boot time' => [
                'bound' => true, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'absent', 'beat' => 'none',
                'kind' => DbSchedulerWatchdog::KIND_NOT_JUDGED, 'exit' => 0, 'strict_exit' => 0,
            ],
            'the watch is switched off' => [
                'bound' => true, 'scheduled' => true, 'missed' => 0, 'interval' => 1,
                'window' => 'closed', 'beat' => 'recent',
                'kind' => DbSchedulerWatchdog::KIND_WATCH_OFF, 'exit' => 0, 'strict_exit' => 1,
            ],
            'the last run is older than the tolerance' => [
                'bound' => true, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'closed', 'beat' => 'late',
                'kind' => DbSchedulerWatchdog::KIND_STALE, 'exit' => 1, 'strict_exit' => 1,
            ],
            'no run has been recorded at all since the window closed' => [
                'bound' => true, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'closed', 'beat' => 'none',
                'kind' => DbSchedulerWatchdog::KIND_STALE, 'exit' => 1, 'strict_exit' => 1,
            ],
            'the container has no flipper' => [
                'bound' => false, 'scheduled' => true, 'missed' => 5, 'interval' => 1,
                'window' => 'closed', 'beat' => 'recent',
                'kind' => DbSchedulerWatchdog::KIND_UNBOUND, 'exit' => 1, 'strict_exit' => 1,
            ],
        ];
    }

    /**
     * The matrix: every route, down both channels.
     *
     * Sixteen cells for eight routes, because the channel is the axis a code can be returned wrong
     * on without the sentence that explains it — the line an operator reads and the object a job
     * reads are two returns, and a matrix over routes alone would leave one of them undriven. It is
     * the distribution's shape, and the reason `db:replica-status` grew its own matrix.
     *
     * `--strict` is deliberately *not* a third axis. It is one boolean on one route, and a matrix
     * that carried it would be thirty-two cells — past the end of the vocabulary the record's counts
     * are spelled in, which is this package saying a table that size is a generated document rather
     * than a sentence. `test_the_strict_flag_moves_one_route_and_no_other` is where the flag is
     * pinned instead, the way `db:pgcat-flip`'s table has a rule a row cannot hold.
     *
     * @return array<string, array{route: string, bound: bool, scheduled: bool, missed: int, interval: int, window: string, beat: string, kind: string, json: bool, exit: int}>
     */
    public static function exitCodeProvider(): array
    {
        $cells = [];

        foreach (self::routeProvider() as $route => $row) {
            foreach (['on the terminal' => false, 'as one object' => true] as $channel => $json) {
                // Written out rather than merged, so a cell carries exactly the parameters the test
                // method takes; `strict_exit` is not one of them, and a data set with a parameter no
                // method declares is an error rather than a second assertion.
                $cells["{$route}, {$channel}"] = [
                    'route' => $route,
                    'bound' => $row['bound'],
                    'scheduled' => $row['scheduled'],
                    'missed' => $row['missed'],
                    'interval' => $row['interval'],
                    'window' => $row['window'],
                    'beat' => $row['beat'],
                    'kind' => $row['kind'],
                    'json' => $json,
                    'exit' => $row['exit'],
                ];
            }
        }

        return $cells;
    }

    /**
     * The code is a function of the route and the channel — and the sentence the route carries goes
     * down whichever channel is answering.
     *
     * Both halves of each cell are asserted, because either alone passes while the other is wrong:
     * a command that returned `1` and printed nothing leaves a healthcheck a code with no reason to
     * put in a log, and one that printed the reason and returned `0` is the failure this command
     * exists to remove.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_the_route_and_the_channel(
        string $route,
        bool $bound,
        bool $scheduled,
        int $missed,
        int $interval,
        string $window,
        string $beat,
        string $kind,
        int $exit,
        bool $json,
    ): void {
        $this->installation($bound, $scheduled, $missed, $interval, $window, $beat);

        $options = $json ? ['--json' => true] : [];

        $actual = Artisan::call('db:scheduler-watchdog', $options);
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "The [{$route}] route exits {$exit} this way:\n".$output);

        // The verdict, in the two places a caller can read it: the word in the object, and the
        // channel the rendered run wrote its sentence to.
        if ($json) {
            $this->assertStringStartsWith('{', ltrim($output), 'the object is the report, and the report is the object');

            $report = $this->report($output);

            $this->assertSame($kind, $report['kind'], "The verdict of [{$route}]");
            $this->assertSame($exit, $report['exit_code'], 'the code travels inside the report');
            $this->assertIsString($report['reason'], 'every route carries its sentence, so a job can log one');

            return;
        }

        $this->assertStringStartsNotWith('{', ltrim($output), 'the rendered channel renders');

        // The route's own sentence, on the channel whose code the run returned: a code with nothing
        // beside it leaves a healthcheck a failure it cannot put in a log.
        $this->assertStringContainsString(
            self::LINE[$route],
            $output,
            "The [{$route}] route says which state it found, and the word [{$kind}] is its own",
        );
    }

    /**
     * The routes with the flag, one cell each, for the one rule the matrix cannot hold.
     *
     * The channel is not an axis here because it is driven inside the test — both are asked for
     * every route — and `--strict` is not an axis of the matrix above for the reason its docblock
     * gives. Eight cells, and the second column of the README's table is read from them.
     *
     * @return array<string, array{route: string, bound: bool, scheduled: bool, missed: int, interval: int, window: string, beat: string, kind: string, exit: int, strict_exit: int}>
     */
    public static function strictProvider(): array
    {
        $cells = [];

        foreach (self::routeProvider() as $route => $row) {
            $cells[$route] = ['route' => $route] + $row;
        }

        return $cells;
    }

    /**
     * `--strict` is one boolean on one route: a watch that has been switched off stops being a
     * warning and becomes a failure.
     *
     * It is pinned here rather than as an axis of the matrix, and it is pinned over *every* route —
     * not only the one it moves — because that is the other half of the claim: a flag that changed
     * the code on a route it has no business touching would be a healthcheck failing for a state it
     * never looked at. Both channels are asked for each route, and the README's `--strict on` column
     * is read for the row the route documents, so the second column of that table is bound by this
     * test rather than by nothing.
     */
    #[DataProvider('strictProvider')]
    public function test_the_strict_flag_moves_one_route_and_no_other(
        string $route,
        bool $bound,
        bool $scheduled,
        int $missed,
        int $interval,
        string $window,
        string $beat,
        string $kind,
        int $exit,
        int $strict_exit,
    ): void {
        $this->installation($bound, $scheduled, $missed, $interval, $window, $beat);

        $terminal = Artisan::call('db:scheduler-watchdog', ['--strict' => true]);
        $rendered = Artisan::output();

        $this->assertSame($strict_exit, $terminal, "The [{$route}] route under --strict:\n".$rendered);
        $this->assertStringContainsString(self::LINE[$route], $rendered, "The [{$route}] route says what it found, flag and all");

        $object = Artisan::call('db:scheduler-watchdog', ['--strict' => true, '--json' => true]);

        $this->assertSame($strict_exit, $object, 'the flag reaches the object channel the same way');
        $this->assertSame($strict_exit, $this->report(Artisan::output())['exit_code']);

        // The table's second column is the claim that this flag is the only thing that moves a code
        // — so the code it documents for this row is the code the run just exited.
        $documented = Readme::row(Readme::table(self::HEADING, '--strict on'), 'what the watchdog found', self::ROWS[$route]);

        $this->assertSame(
            $strict_exit,
            Readme::code($documented['--strict on']),
            sprintf('The README documents [%s] under --strict as the code this run exited', self::ROWS[$route]),
        );

        if ($kind !== DbSchedulerWatchdog::KIND_WATCH_OFF) {
            $this->assertSame(
                $exit,
                $strict_exit,
                "The [{$route}] route is not a warning, so the flag must not move it",
            );
        }
    }

    /**
     * The report's shape: the envelope's five keys, then this command's own evidence, all of it
     * present on every route.
     *
     * The beat and the bound it was judged against are what makes a `stale` explainable — five
     * minutes late against a five-minute tolerance and five minutes late against an hour's are the
     * same word and not the same fault — and the two settings at the end are where the bound came
     * from, so a job can say how late is late without reading a configuration file.
     */
    public function test_the_report_carries_the_beat_and_the_bound_it_was_judged_against(): void
    {
        $this->installation(true, true, 5, 1, 'closed', 'recent');

        $this->assertSame(0, Artisan::call('db:scheduler-watchdog', ['--json' => true]));

        $report = $this->report(Artisan::output());

        $this->assertSame(
            [...array_keys(JsonEnvelope::CORE), 'last_beat_at', 'age_seconds', 'stale_after_seconds', 'missed_runs', 'interval_minutes'],
            array_keys($report),
            'the envelope\'s keys first, read from the class rather than restated, then this command\'s evidence',
        );

        $this->assertSame('db:scheduler-watchdog', $report['command']);
        $this->assertSame(1, $report['interval_minutes']);
        $this->assertSame(5, $report['missed_runs']);
        $this->assertSame(300, $report['stale_after_seconds'], 'five missed runs at a one-minute cadence');
        $this->assertSame(60, $report['age_seconds'], 'the age of the beat, measured rather than assumed');
        $this->assertIsString($report['last_beat_at']);
    }

    /**
     * The evidence is present on the route that never found a flipper too, with the two settings it
     * could not read as `null`.
     *
     * The rule the envelope states — a key a route has nothing for is `null`, not absent — matters
     * most here: a consumer that had to check whether `.missed_runs` exists is a consumer that can
     * be written wrong once and stay wrong, and the route where it is absent is the one nobody
     * tests by hand.
     */
    public function test_a_route_that_read_no_settings_still_writes_every_key(): void
    {
        $this->installation(false, true, 5, 1, 'closed', 'recent');

        $this->assertSame(1, Artisan::call('db:scheduler-watchdog', ['--json' => true]));

        $report = $this->report(Artisan::output());

        $this->assertSame(DbSchedulerWatchdog::KIND_UNBOUND, $report['kind']);
        $this->assertNull($report['last_beat_at']);
        $this->assertNull($report['age_seconds']);
        $this->assertNull($report['missed_runs']);
        $this->assertNull($report['interval_minutes']);
        $this->assertSame(0, $report['stale_after_seconds']);
    }

    /**
     * The tolerance is the cadence times the missed runs, so a schedule that is slowed down is
     * watched on its own terms — the setting is a count of runs, not a number of seconds somebody
     * has to remember to raise.
     *
     * And `0` is a decision rather than a zero: it switches the judgement off, which is the one
     * setting `--strict` refuses.
     */
    public function test_the_tolerance_follows_the_cadence(): void
    {
        $this->assertSame(300, $this->beat(5, 1)->staleAfterSeconds(), 'the shipped cadence');
        $this->assertSame(1500, $this->beat(5, 5)->staleAfterSeconds(), 'a five-minute entry is judged over twenty-five');
        $this->assertSame(60, $this->beat(1, 1)->staleAfterSeconds(), 'one missed run is one cadence');
        $this->assertSame(0, $this->beat(0, 1)->staleAfterSeconds(), 'switched off');
        $this->assertSame(0, $this->beat(-3, 1)->staleAfterSeconds(), 'a negative is switched off too');

        $this->assertTrue($this->beat(0, 1)->off());
        $this->assertFalse($this->beat(5, 1)->off());
    }

    /**
     * An age is read in the unit a person uses for it — minutes, then hours, then days — and it is
     * the flipper's own formatter, so a healthy row and a failing one describe the same state in
     * the same words.
     */
    public function test_an_age_is_read_in_the_unit_a_person_uses(): void
    {
        $this->assertSame('1 minute(s)', ScheduleBeat::ageText(45), 'a beat inside its first minute is not "0 minutes"');
        $this->assertSame('5 minute(s)', ScheduleBeat::ageText(300));
        $this->assertSame('1 hour(s)', ScheduleBeat::ageText(3600));
        $this->assertSame('23 hour(s)', ScheduleBeat::ageText(86399));
        $this->assertSame('1 day(s)', ScheduleBeat::ageText(86400));
        $this->assertSame('4 day(s)', ScheduleBeat::ageText(4 * 86400 + 900));
    }

    /**
     * A stamp in the future is a clock that moved rather than a beat that is early, and the honest
     * reading of it is an age of nothing: a negative age would make every comparison pass, and a
     * schedule reported as fresh because its container's clock was readjusted is the failure this
     * command exists to remove, arriving from the one direction nothing looks.
     */
    public function test_a_stamp_in_the_future_is_not_a_young_beat(): void
    {
        $dir = $this->dir();
        $state = $dir.'/state.json';

        file_put_contents($state, (string) json_encode([
            'last_mode' => 'readers',
            ScheduleBeat::STATE_KEY => gmdate('Y-m-d\TH:i:s\Z', $this->now + 3600),
        ]));

        $verdict = $this->beat(5, 1, $dir)->verdict(
            (array) json_decode((string) file_get_contents($state), true),
        );

        $this->assertSame(0, $verdict['age_seconds'], 'a stamp in the future has no age');
        $this->assertFalse($verdict['stale'], 'and it is not the reading that says the schedule stopped');
    }

    /**
     * A stamp that cannot be parsed is no stamp: the verdict reports no `last_beat_at` rather than
     * printing a string beside an age of nothing, and it says so as a schedule that never ran.
     *
     * @return void
     */
    public function test_a_stamp_that_cannot_be_read_is_no_stamp(): void
    {
        $verdict = $this->beat(5, 1)->verdict([
            ScheduleBeat::STATE_KEY => 'whenever the cron felt like it',
        ]);

        $this->assertNull($verdict['last_beat_at'], 'a value that is not a time is not reported as one');
        $this->assertNull($verdict['age_seconds']);
        $this->assertTrue($verdict['stale'], 'the entry is scheduled, the window has closed and nothing has run it');
    }

    /**
     * The README row each cell of the matrix is an instance of.
     *
     * The matrix is the routes against the flag and the channels, and the table is written for an
     * operator, so each row is four cells — the code is the same on either channel, which is the
     * claim its two `--strict` columns make by carrying one pair of numbers. This map is the
     * correspondence, and it is the only thing either side has to keep in step: the test below
     * fails when the two disagree in either direction. Rows are named by what was found rather than
     * by their `kind`, because that is what an operator reads the table for; the kind column is
     * bound separately, against the vocabulary the reports produce.
     *
     * @return array<string, string> key of exitCodeProvider() => the README row it documents
     */
    private static function documentedSituations(): array
    {
        $situations = [];

        foreach (self::exitCodeProvider() as $cell => $row) {
            $situations[$cell] = self::ROWS[$row['route']] ?? throw new \RuntimeException(
                "the route [{$row['route']}] names no README row, and the matrix documents every route",
            );
        }

        return $situations;
    }

    /**
     * The table an operator reads is the rule the cells enforce, in both directions — the cases the
     * matrix is written as are exactly the rows the table has, and each cell's code is the number
     * its row names in the column the cells were asked under. The matrix is the unflagged run, so
     * that column is `--strict off`; the other one is bound by the flag's own test.
     *
     * The kinds are bound the same way, one step over: the words the reports produce are the words
     * the table's `kind` column spells, so a verdict cannot be renamed in the code and left behind
     * in the documentation — which is the drift this pair of tables exists to make loud.
     */
    public function test_the_matrix_agrees_with_the_readme_exit_table(): void
    {
        $rows = Readme::table(self::HEADING, '--strict off');
        $situations = self::documentedSituations();

        $this->assertEqualsCanonicalizing(
            array_keys(self::exitCodeProvider()),
            array_keys($situations),
            'every cell of the matrix names the README row it is an instance of, and nothing else',
        );

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_values(array_unique($situations))),
            array_map([Readme::class, 'plain'], array_column($rows, 'what the watchdog found')),
            'the README documents a case the matrix is not written as, or the matrix is written as one the README does not document',
        );

        foreach (self::exitCodeProvider() as $cell => $row) {
            $documented = Readme::row($rows, 'what the watchdog found', $situations[$cell]);

            $this->assertSame(
                $row['exit'],
                Readme::code($documented['--strict off']),
                sprintf(
                    'The README documents [%s] as "%s" under --strict off, while the matrix asserts %d for cell [%s].',
                    $situations[$cell],
                    $documented['--strict off'],
                    $row['exit'],
                    $cell,
                ),
            );
        }

        // The vocabulary, as a set: a kind the reports produce that the table does not spell, or a
        // word in the table no route reaches. `stale` and `not_judged` are one word each for two
        // rows, which is the table's claim that they are one verdict with two sentences — and that
        // the two rows carry the same number in the same column.
        $kinds = array_values(array_unique(array_column(self::exitCodeProvider(), 'kind')));

        $this->assertEqualsCanonicalizing(
            $kinds,
            array_values(array_unique(array_map([Readme::class, 'plain'], array_column($rows, 'kind')))),
            'the kinds the reports produce are the kinds the table documents',
        );

        foreach (array_keys(self::routeProvider()) as $route) {
            $this->assertArrayHasKey($route, self::LINE, "The [{$route}] route has a sentence to recognise");
        }
    }

    /**
     * One installation, in a temp directory: a boot stamp, a state file with a beat, the switches
     * the command reads, and a flipper built with a `ScheduleBeat` that has the clock this test
     * owns.
     *
     * Every route is a *state on disk* rather than a stubbed verdict, which is what makes these
     * cells evidence about the command: the command resolves the flipper, asks it, and reads the
     * same files a container would have. The one route that is not a state — `unbound` — replaces
     * the binding with something that is not a flipper, the fixture the flip's own matrix uses.
     */
    private function installation(
        bool $bound,
        bool $scheduled,
        int $missed,
        int $interval,
        string $window,
        string $beat,
    ): void {
        $dir = $this->dir();
        $state = $dir.'/pgcat-flip-state.json';

        // The switches, which are what the command reads for the report and for the two silences
        // the flipper's verdict cannot name: `schedule.enabled` and the tolerance.
        config(['db-manager.swrr.pgcat.schedule' => [
            'enabled' => $scheduled,
            'interval_minutes' => $interval,
            'stale_after_missed_runs' => $missed,
        ]]);

        $this->stamp($dir, $window);
        $this->write($state, $beat);

        if (! $bound) {
            $this->app->instance(PgcatConfigFlipper::class, new stdClass());

            return;
        }

        $this->app->instance(PgcatConfigFlipper::class, new PgcatConfigFlipper(
            resolver: $this->app->make(TimeWindowResolver::class),
            config: config('db-manager.swrr.pgcat'),
            stateFile: $state,
            lockFile: $dir.'/pgcat-flip.lock',
            beat: $this->beat($missed, $interval, $dir, $window, $scheduled),
        ));
    }

    /**
     * The flipper's beat, configured the way `swrr.pgcat.schedule` configures one.
     *
     * Built directly rather than through `fromConfig()` so the clock is this test's: every age in
     * the matrix is a whole number of minutes, and nothing here waits for one.
     */
    private function beat(
        int $missed,
        int $interval,
        ?string $dir = null,
        string $window = 'closed',
        bool $scheduled = true,
    ): ScheduleBeat {
        $dir ??= $this->dir();
        $this->stamp($dir, $window);

        $clock = fn (): float => (float) $this->now;

        return new ScheduleBeat(
            scheduled: $scheduled,
            intervalMinutes: $interval,
            missedRuns: $missed,
            window: new FlipWindow($dir.'/boot.stamp', 480, $clock),
            clock: $clock,
        );
    }

    /**
     * The container's boot stamp: a moment an hour ago for a container whose window has closed, a
     * minute ago for one still starting, and no file at all for one whose entrypoint never wrote
     * one.
     */
    private function stamp(string $dir, string $window): void
    {
        if ($window === 'absent') {
            @unlink($dir.'/boot.stamp');

            return;
        }

        file_put_contents($dir.'/boot.stamp', (string) ($window === 'open' ? $this->now - 60 : $this->now - 3600));
    }

    /**
     * The state file, as a run of the entry would have left it — the beat `ScheduleBeat` reads, and
     * the mode record it deliberately does not touch, so a fixture that lost the second would still
     * be the file this command is about.
     */
    private function write(string $state, string $beat): void
    {
        if ($beat === 'none') {
            @unlink($state);

            return;
        }

        file_put_contents($state, (string) json_encode([
            'last_mode' => 'readers',
            ScheduleBeat::STATE_KEY => gmdate('Y-m-d\TH:i:s\Z', $beat === 'late' ? $this->now - 3600 : $this->now - 60),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** A temp directory of this run's own, removed in tearDown. */
    private function dir(): string
    {
        $dir = sys_get_temp_dir().'/swrr-watchdog-'.getmypid().'-'.count($this->tempDirs);

        if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * The report an envelope wrote, decoded — and, with `JSON_THROW_ON_ERROR`, an assertion in
     * itself: output that is not one JSON object fails here rather than in every reader.
     *
     * @return array<string, mixed>
     */
    private function report(string $output): array
    {
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, 'a report is one object');

        return $decoded;
    }
}

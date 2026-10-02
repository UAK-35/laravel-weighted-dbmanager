<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Console\Commands\DbWindowFlipCommand;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\ReadinessProbe;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule;
use Uak35\WeightedDbManager\Tests\Support\FakeSupervisor;
use Uak35\WeightedDbManager\Tests\Support\FakeWeightedManager;
use Uak35\WeightedDbManager\Tests\Support\PgcatInstallation;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The exit code of `db:pgcat-window-flip` as a function of what the firing found.
 *
 * This is the shape `db:probe-replicas`, `db:doctor` and `db:pgcat-flip` are tested in, for the
 * reason every one of them gives: the exit code is what a scheduler reports, and a scheduled task
 * that exits non-zero for a healthy installation stops being read. It matters more here than
 * anywhere, because this command is normally run *eight times per boundary* — once a minute across
 * the lead window — so a code that meant "nothing to do yet" would put eight error lines in the log
 * every time a reader window opened.
 *
 * THE RULE, AND THE ONE ROW THAT IS NOT OBVIOUS
 *   Non-zero exactly when the run could not do what it was asked: a mode applied, a mode already
 *   applied, another instance holding the lock, a firing outside its own window, and an
 *   installation that has the tasks switched off are all the run doing its job. The row that is
 *   worth reading is `deferred` — the boundary arrived and the target did not answer — which exits
 *   `1`, because the task exists to change the mode and this run could not; the following firings
 *   inside the grace ask again.
 *
 * WHY THE ROWS ARE DRIVEN THROUGH THE REAL FLIPPER
 *   A window flip is not a rehearsal: it swaps pgcat's config and signals supervisord. Every row
 *   that reaches one is therefore built on a real installation in a temp directory — the same
 *   fixture `db:pgcat-flip`'s matrix uses, with a stand-in supervisor — so the file that moved and
 *   the mode that was recorded are asserted rather than assumed, and the replica the gate asks is
 *   reached over SQLite so the probe really connects.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbProbeReplicasCommandTest::test_the_exit_code_is_a_function_of_what_the_sweep_reached
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbFlipPgcatCommandTest::test_the_exit_code_is_a_function_of_what_the_flip_would_do
 */
final class DbWindowFlipCommandTest extends TestCase
{
    /** The port a candidate is tracked under when its own config does not name one. */
    private const DEFAULT_PORT = 5433;

    /** A candidate that really answers: the driver is replaced, not the address. */
    private const REACHABLE = ['driver' => 'sqlite', 'database' => ':memory:'];

    /** @var list<string> */
    private array $tempDirs = [];

    /** The installation the current row was built on, for the assertions about what it did. */
    private ?PgcatInstallation $installation = null;

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
        $this->installation = null;

        parent::tearDown();
    }

    /**
     * A time of day relative to now, as the boundary a firing is for.
     *
     * The rows are about the *distance* between a firing and its boundary — before the window opens,
     * inside it, at it, past it — and the command resolves the nearest boundary, so a row that wants
     * "four minutes ago" only has to say so. The lead and the grace the rows are built with are the
     * shipped eight minutes, which is what makes +4 inside the window and -30 past everything.
     */
    private static function boundary(int $minutesFromNow): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->modify(($minutesFromNow < 0 ? '' : '+').$minutesFromNow.' minutes')->format('H:i:s');
    }

    /**
     * Each row declares the evidence its exit code is supposed to follow from: the sentence the run
     * has to carry, the sentence it must not, and the verdict in the JSON vocabulary. A row whose
     * fixture drifted fails on its own line instead of leaving the exit code standing as evidence
     * about a question nobody asked.
     *
     * @return array<string, array{preset: string, options: array<string, mixed>, exit: int, kind: string, line: string, absent: string}>
     */
    public static function exitCodeProvider(): array
    {
        $inside = static fn (string $mode): array => ['--mode' => $mode, '--at' => self::boundary(-4)];

        return [
            // ── refused before anything was read ──────────────────────────────────────
            'a mode this command has no meaning for is refused' => [
                'preset' => 'armed', 'options' => ['--mode' => 'sideways', '--at' => '10:00:00'], 'exit' => 1,
                'kind' => DbWindowFlipCommand::KIND_REFUSED,
                'line' => "--mode must be 'readers' or 'writer'",
                'absent' => 'the boundary is at',
            ],
            'a boundary that is not a time of day is refused' => [
                'preset' => 'armed', 'options' => ['--mode' => 'readers', '--at' => 'later'], 'exit' => 1,
                'kind' => DbWindowFlipCommand::KIND_REFUSED,
                'line' => '--at must be a time of day',
                'absent' => 'the boundary is at',
            ],

            // ── nothing for this run to do, and nothing wrong with it ─────────────────
            'the tasks are switched off' => [
                'preset' => 'tasks-off', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_DISABLED,
                'line' => 'The reader-window tasks are off',
                'absent' => 'the boundary is at',
            ],
            'pgcat cannot front this connection\'s driver' => [
                'preset' => 'mysql', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_DISABLED,
                'line' => 'PostgreSQL-only',
                'absent' => 'the boundary is at',
            ],

            // ── a dependency the gate cannot work without ─────────────────────────────
            'the container has no pgcat flipper' => [
                'preset' => 'unregistered', 'options' => $inside('readers'), 'exit' => 1,
                'kind' => DbWindowFlipCommand::KIND_UNBOUND,
                'line' => 'PgcatConfigFlipper is not registered',
                'absent' => 'the boundary is at',
            ],
            'the container has no weighted manager, so the target cannot be asked' => [
                'preset' => 'unweighted', 'options' => $inside('readers'), 'exit' => 1,
                'kind' => DbWindowFlipCommand::KIND_UNBOUND,
                'line' => 'WeightedDatabaseManager is not registered',
                'absent' => 'has arrived',
            ],

            // ── a firing outside the task's own window ───────────────────────────────
            'a firing before the lead window' => [
                'preset' => 'armed', 'options' => ['--mode' => 'readers', '--at' => self::boundary(30)], 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_EARLY,
                'line' => "before this task's lead window opens",
                'absent' => 'answered',
            ],
            'a firing past the boundary and its grace' => [
                'preset' => 'armed', 'options' => ['--mode' => 'readers', '--at' => self::boundary(-30)], 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_EXPIRED,
                'line' => 'the chance to act is gone',
                'absent' => 'answered',
            ],
            'the boundary resolves to a day that is not a reader day' => [
                'preset' => 'not-my-day', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_NOT_MY_DAY,
                'line' => 'which is not a reader day',
                'absent' => 'answered',
            ],

            // ── the probing the task exists for ──────────────────────────────────────
            'inside the lead window and before the boundary: the target is asked and nothing is applied' => [
                'preset' => 'armed', 'options' => ['--mode' => 'readers', '--at' => self::boundary(4)], 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_WAITING,
                'line' => 'probing: the boundary is at',
                'absent' => 'mode applied',
            ],
            'the boundary arrived and the target answered, so the mode is applied' => [
                'preset' => 'armed', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => 'flipped',
                'line' => 'mode applied',
                'absent' => 'nothing to do',
            ],
            'the boundary arrived and the target did not answer' => [
                'preset' => 'target-down', 'options' => $inside('readers'), 'exit' => 1,
                'kind' => DbWindowFlipCommand::KIND_DEFERRED,
                'line' => 'has arrived and',
                'absent' => 'mode applied',
            ],
            'the flipper already has the mode applied' => [
                'preset' => 'unchanged', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => DbWindowFlipCommand::KIND_NO_CHANGE,
                'line' => 'there is nothing to do',
                'absent' => 'answered',
            ],
            'another flipper instance holds the lock' => [
                'preset' => 'locked', 'options' => $inside('readers'), 'exit' => 0,
                'kind' => 'skipped',
                'line' => 'another flipper instance holds the lock',
                'absent' => 'mode applied',
            ],
            'a step the flip needs did not work' => [
                'preset' => 'missing-source', 'options' => $inside('readers'), 'exit' => 1,
                'kind' => 'failed',
                'line' => 'Source pgcat config not readable',
                'absent' => 'mode applied',
            ],

            // ── the other direction, on the same machinery ───────────────────────────
            'a deactivation asks the primary and applies the writer mode' => [
                'preset' => 'armed', 'options' => $inside('writer'), 'exit' => 0,
                'kind' => 'flipped',
                'line' => '1 of 1 primary answered',
                'absent' => 'replicas answered',
            ],
        ];
    }

    /**
     * The provider's keys are this method's parameter names: PHPUnit passes them as named arguments,
     * so a row that says `exit` is a row whose expected code is asserted.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_what_the_firing_found(
        string $preset,
        array $options,
        int $exit,
        string $kind,
        string $line,
        string $absent,
    ): void {
        $this->layOut($preset);

        $actual = Artisan::call(WindowFlipSchedule::COMMAND, $options);
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "Exit code for the {$preset} row:\n".$output);

        // The row says what happened, and — the other half — does not claim what did not.
        $this->assertStringContainsString($line, $output, "The {$preset} row has to say what its exit code means");
        $this->assertStringNotContainsString($absent, $output, "The {$preset} row must not blame something that did not happen");
    }

    /**
     * The same matrix again, read as JSON — the envelope the package's other reports write, with this
     * command's evidence after the keys they share.
     *
     * `--json` changes the report and nothing else, so every cell keeps its code and its verdict; the
     * whole output is decoded rather than searched for an object, because that is the assertion that
     * fails when a rendered line is written beside the report. A `refused` row is the one worth
     * reading: it is the difference between "the run never started" and "the run started and failed",
     * which a pipeline has to be able to tell.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_json_report_is_the_same_run_as_one_object(
        string $preset,
        array $options,
        int $exit,
        string $kind,
        string $line,
        string $absent,
    ): void {
        $this->layOut($preset);

        $actual = Artisan::call(WindowFlipSchedule::COMMAND, $options + ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "--json is a report, not a rule: the {$preset} row exits the same code either way");
        $this->assertStringStartsWith('{', ltrim($output), 'the object is the report, and the report is the object');
        $this->assertStringNotContainsString('[pgcat-window-flip]', $output, 'the rendered line is not written beside the object');

        $report = $this->report($output);

        $this->assertSame(
            [
                ...array_keys(JsonEnvelope::CORE),
                'mode', 'at', 'boundary', 'now', 'mode_before', 'target', 'probe', 'result', 'warning',
            ],
            array_keys($report),
            'the envelope\'s keys first, read from the class rather than restated, then this command\'s evidence',
        );

        $this->assertSame('db:pgcat-window-flip', $report['command']);
        $this->assertSame($kind, $report['kind'], "The {$preset} row's verdict");
        $this->assertSame($exit, $report['exit_code'], 'the code travels inside the report');
        $this->assertSame($options['--mode'] ?? null, $report['mode'], 'the mode a refused run could not read is still carried as it was written');
        $this->assertSame($options['--at'] ?? null, $report['at']);

        // The sentence in the object is the one the terminal printed — including the rows whose
        // sentence is the flipper's own summary rather than one this command wrote.
        $this->assertIsString($report['reason']);
        $this->assertStringContainsString($line, $report['reason'], 'the sentence in the object is the one the terminal printed');

        // The boundary is carried exactly when a run resolved one: a refusal, a switched-off
        // installation and an unbound one never got that far, and `null` is that fact rather than a
        // missing key.
        $resolved = ! in_array($kind, [DbWindowFlipCommand::KIND_REFUSED, DbWindowFlipCommand::KIND_DISABLED, DbWindowFlipCommand::KIND_UNBOUND], true);

        if ($resolved) {
            $this->assertIsString($report['boundary']);
            $this->assertIsString($report['now']);
        } else {
            $this->assertNull($report['boundary']);
            $this->assertNull($report['now'], 'a run that never resolved a boundary must not invent one');
        }
    }

    /**
     * The probe is the evidence of what was asked, and it is the half of a window flip that is
     * otherwise invisible: the probe is a throwaway connection, purged in a `finally`, and nothing
     * about it survives anywhere else.
     *
     * The counts are what a pipeline asserts on — "the target answered" is a fact about the
     * installation, and a run that reported it by parsing the sentence would be reading prose out of a
     * machine-readable channel.
     */
    public function test_the_probe_is_the_evidence_of_what_was_asked(): void
    {
        $this->layOut('armed');

        $manager = $this->app->make('db');

        $this->assertSame(0, Artisan::call(WindowFlipSchedule::COMMAND, [
            '--mode' => 'readers', '--at' => self::boundary(-4), '--json' => true,
        ]));

        $report = $this->report(Artisan::output());
        $probe = $report['probe'];

        $this->assertIsArray($probe);
        $this->assertSame([
            'target', 'available', 'probed', 'answered', 'failed', 'hosts', 'note',
        ], array_keys($probe), 'the verdict\'s own shape, from the gate rather than assembled here');

        $this->assertSame('replicas', $probe['target']);
        $this->assertTrue($probe['available']);
        $this->assertSame(2, $probe['probed']);
        $this->assertSame(2, $probe['answered']);
        $this->assertSame(0, $probe['failed']);
        $this->assertNull($probe['note']);
        $this->assertSame(ReadinessProbe::TARGET_REPLICAS, $report['target']);
        $this->assertNull(
            $report['mode_before'],
            'the mode the flipper had when the run started — read before the flip, because that is the fact the decision turned on',
        );

        $this->assertSame([
            ['host' => '10.9.0.5', 'port' => 6432, 'healthy' => true, 'error' => null],
            ['host' => '10.9.0.6', 'port' => self::DEFAULT_PORT, 'healthy' => true, 'error' => null],
        ], $probe['hosts'], 'a candidate without a port of its own is tracked under the connection\'s');

        $this->assertInstanceOf(FakeWeightedManager::class, $manager);
        $this->assertCount(2, $manager->marks, 'the sweep\'s results reached the health monitor, exactly as db:probe-replicas would report them');

        // The report carries the flipper's own result object, so a job has the sentence it decided by.
        $result = $report['result'];
        $this->assertIsArray($result);
        $this->assertSame('flipped', $result['kind']);
        $this->assertSame('readers', $result['mode']);
        $this->assertNull($result['previous_mode'], 'the mode had never been applied, which is why a flip ran');
    }

    /**
     * A mode applied at a window boundary is **not** a boot convergence, and this is the assertion
     * the whole design rests on: the flipper's forced path records no run, so a mode applied at 10:00
     * on a Tuesday cannot move the verdict `/health/db` reports about whether the container came up.
     *
     * The window's run bookkeeping is read after the flip rather than before, because a counter that
     * moved and was reset would still read zero at the start.
     */
    public function test_a_mode_applied_at_a_boundary_is_not_recorded_as_a_boot_convergence(): void
    {
        $this->layOut('armed');

        $before = $this->flipper()->status()['window'];

        $this->assertSame(0, Artisan::call(WindowFlipSchedule::COMMAND, [
            '--mode' => 'readers', '--at' => self::boundary(-4),
        ]), Artisan::output());

        $after = $this->flipper()->status()['window'];

        $this->assertSame($before['runs'], $after['runs'], 'the forced path records no run, so the convergence bookkeeping does not move');
        $this->assertSame('readers', $this->flipper()->status()['last_mode'], '...and the mode it applied is on record');
    }

    /**
     * The verbose rows are a field in one channel and nothing in the other: a per-host row printed
     * beside the object would make stdout two things, and a job reading it would have to skip a line
     * before it could parse anything.
     */
    public function test_the_per_host_rows_are_printed_only_on_the_terminal_and_only_when_asked(): void
    {
        $this->layOut('armed');

        $options = ['--mode' => 'readers', '--at' => self::boundary(-4)];

        Artisan::call(WindowFlipSchedule::COMMAND, $options);
        $quiet = Artisan::output();

        // The first run applied the mode and recorded it, which is what makes the *next* one a
        // `no_change` — so the state file is put back to "nothing has been applied" between the runs
        // this test is comparing. That is not a fixture convenience: it is the same session a
        // container is in on the morning the first window opens.
        $this->installation?->recordMode(null);

        Artisan::call(WindowFlipSchedule::COMMAND, $options + ['-v' => true]);
        $verbose = Artisan::output();

        $this->installation?->recordMode(null);

        Artisan::call(WindowFlipSchedule::COMMAND, $options + ['-v' => true, '--json' => true]);
        $json = Artisan::output();

        $this->assertStringNotContainsString('10.9.0.5:6432', $quiet, 'a task that runs eight times per boundary writes one line, not one per host');
        $this->assertStringContainsString('✓ 10.9.0.5:6432', $verbose);
        $this->assertStringContainsString('SELECT 1 answered', $verbose);

        $this->assertStringNotContainsString('✓', $json, 'the rows are the object\'s `probe.hosts` in this mode');
        $this->assertEquals(
            $this->report($json)['probe']['hosts'],
            [['host' => '10.9.0.5', 'port' => 6432, 'healthy' => true, 'error' => null], ['host' => '10.9.0.6', 'port' => self::DEFAULT_PORT, 'healthy' => true, 'error' => null]],
        );
    }

    /**
     * A container whose system clock and configured zone disagree is worth saying out loud on the run
     * that noticed: the cron is evaluated in `swrr.timezone` while the boundary is resolved in it too,
     * and a container that sets neither is the state where "10:00" means two different moments.
     *
     * Both channels carry it, because the run cannot act differently — it is information, not a
     * refusal — and a job that logs the report should keep the warning with it.
     */
    public function test_a_container_clock_that_disagrees_with_the_configured_zone_is_reported(): void
    {
        $this->layOut('armed');

        config()->set('db-manager.swrr.timezone', 'Asia/Tokyo');

        $this->assertSame(0, Artisan::call(WindowFlipSchedule::COMMAND, [
            '--mode' => 'readers', '--at' => self::boundary(30), '--json' => true,
        ]));

        $warning = $this->report(Artisan::output())['warning'];

        $this->assertIsString($warning);
        $this->assertStringContainsString('the container clock is '.date_default_timezone_get(), $warning);
        $this->assertStringContainsString('swrr.timezone is Asia/Tokyo', $warning);
        $this->assertStringContainsString('different clocks', $warning);

        // The same sentence on the terminal, where an operator reads it.
        $this->assertSame(0, Artisan::call(WindowFlipSchedule::COMMAND, [
            '--mode' => 'readers', '--at' => self::boundary(30),
        ]));

        $this->assertStringContainsString('swrr.timezone is Asia/Tokyo', Artisan::output());
    }

    /**
     * The documented table is the contract in both directions: every kind the report can carry is
     * documented with the code a scheduler branches on, and no documented kind is one nothing
     * produces.
     *
     * The table is read by its `kind` column rather than its `exit` column, because this command's
     * section has one table carrying both — a case and a verdict are the same thing here, and a
     * second table would be a second statement of the same rule to keep in step.
     */
    public function test_the_documented_kinds_are_the_kinds_the_matrix_reaches(): void
    {
        $rows = Readme::table('### The reader-window tasks: `db:pgcat-window-flip`', 'kind');

        $produced = [];

        foreach (self::exitCodeProvider() as $cell) {
            $produced[$cell['kind']] = $cell['exit'];
        }

        $this->assertEqualsCanonicalizing(
            array_keys($produced),
            array_map([Readme::class, 'plain'], array_column($rows, 'kind')),
            'a kind the report can carry is documented, and every documented kind is one the report carries',
        );

        foreach ($rows as $row) {
            $kind = Readme::plain($row['kind']);

            $this->assertSame(
                $produced[$kind],
                Readme::code($row['exit']),
                sprintf('The README documents `%s` as exiting %s, while the matrix asserts %d.', $kind, $row['exit'], $produced[$kind]),
            );
        }
    }

    /**
     * The README row each cell of the matrix is an instance of.
     *
     * Here the correspondence is one to one, because this command's section documents a *verdict*
     * rather than a situation: `kind` is the row, and a cell is an instance of the row it reports. It
     * is declared all the same, because `DocumentedExitCounts` reads this map to state how many
     * documented cases the package's matrices have and which record counts them.
     *
     * @return array<string, string> key of exitCodeProvider() => the documented case it is an instance of
     */
    private static function documentedSituations(): array
    {
        $situations = [];

        foreach (self::exitCodeProvider() as $name => $cell) {
            $situations[$name] = $cell['kind'];
        }

        return $situations;
    }

    /**
     * Build the installation a row starts from.
     *
     * The order matters and is the reason this is one method rather than a fixture per row: the
     * flipper reads the connection's driver when it is constructed and the gate reads the reader
     * windows when the command runs, so the driver is set before the installation is installed and the
     * package's own settings are set after it — `PgcatInstallation::install()` replaces the whole
     * `swrr.pgcat` slice, and a settings block written before it would be gone.
     */
    private function layOut(string $preset): void
    {
        $dir = $this->tempDir();

        $installation = PgcatInstallation::make(
            $dir,
            $preset === 'missing-source' ? ['readers_path' => $dir.'/not-there.toml'] : [],
        );

        $this->installation = $installation;

        if ($preset === 'mysql') {
            config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
            config()->set('database.default', 'mysql_app');
        } else {
            config()->set('database.default', TestCase::CONNECTION);
        }

        $installation->install([], new FakeSupervisor());

        // The package's own settings, after install(): the reader windows the boundary is resolved
        // from, the days, the zone, and the switch that arms the tasks.
        config()->set('db-manager.swrr.reader_windows', [['start' => '10:00:00', 'end' => '14:20:00']]);
        config()->set('db-manager.swrr.reader_days', $preset === 'not-my-day'
            ? [((int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('N')) % 7 + 1]
            : [1, 2, 3, 4, 5, 6, 7]);
        config()->set('db-manager.swrr.timezone', 'UTC');
        config()->set('db-manager.swrr.pgcat.schedule.windows', [
            'enabled' => $preset !== 'tasks-off',
            'lead_seconds' => 480,
            'grace_seconds' => 480,
        ]);

        // The candidates the gate asks. The connection keeps a PostgreSQL driver — that is what arms
        // the flipper — while each candidate's own map replaces the driver it would connect with, so a
        // probe really runs without a PostgreSQL server to run against.
        $reachable = ['host' => '10.9.0.5', 'port' => 6432, ...self::REACHABLE];
        $reachableSecond = ['host' => '10.9.0.6', ...self::REACHABLE];
        $unreachable = ['host' => '10.9.0.7', 'driver' => 'sqlite', 'database' => $dir.'/not-there/dead.sqlite'];
        $writer = ['host' => '10.9.0.9', 'port' => 5432, ...self::REACHABLE];

        config()->set('database.connections.'.TestCase::CONNECTION, [
            'driver' => 'pgsql',
            'database' => 'app',
            'port' => self::DEFAULT_PORT,
            'read' => $preset === 'target-down' ? [$unreachable] : [$reachable, $reachableSecond],
            'write' => $writer,
        ]);

        if ($preset === 'unweighted') {
            $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));

            return;
        }

        $this->app->instance('db', new FakeWeightedManager($this->app, $this->app->make('db.factory')));

        if ($preset === 'unregistered') {
            // The only way the guard's branch is reachable: a name bound to something that is not a
            // flipper. A key nothing is bound to would resolve the class instead.
            $this->app->instance(PgcatConfigFlipper::class, new \stdClass());

            return;
        }

        // A previous successful flip, as it would have left the state file.
        if ($preset === 'unchanged') {
            $installation->recordMode('readers');
        }

        if ($preset === 'locked') {
            $this->withTheLockHeldElsewhere($installation);
        }
    }

    /**
     * Another instance holding the lock, stated at the seam the flip takes it — the acquirer callback
     * — rather than by really taking the lock twice. On Windows the lock belongs to the process, so a
     * test that took it itself would still see the flip acquire it and quietly test the success path.
     */
    private function withTheLockHeldElsewhere(PgcatInstallation $installation): void
    {
        $this->app->instance(PgcatConfigFlipper::class, new PgcatConfigFlipper(
            resolver: $this->app->make(TimeWindowResolver::class),
            config: $installation->config(),
            stateFile: $installation->path('state_file'),
            lockFile: $installation->path('lock_file'),
            lockAcquirer: static fn (string $file): array => [false, null],
            lockReleaser: static fn ($fp): null => null,
        ));
    }

    /**
     * The flipper the run resolved, for the rows that assert what it recorded.
     */
    private function flipper(): PgcatConfigFlipper
    {
        $flipper = $this->app->make(PgcatConfigFlipper::class);

        $this->assertInstanceOf(PgcatConfigFlipper::class, $flipper);

        return $flipper;
    }

    /**
     * The report a run printed, decoded. `JSON_THROW_ON_ERROR` turns "the output is not JSON" into this
     * test's failure, which is the one thing a consumer of this mode has to be told.
     *
     * @return array<string, mixed>
     */
    private function report(string $output): array
    {
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, 'a report is one object');

        return $decoded;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/window-flip-command-'.bin2hex(random_bytes(6));

        if (! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/{*,*/*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @chmod($file, 0o666);
                @unlink($file);
            }
        }

        @rmdir($dir.'/bin');
        @rmdir($dir.'/not-there');
        @rmdir($dir);
    }
}

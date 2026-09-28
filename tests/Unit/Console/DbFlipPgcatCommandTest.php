<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Console\Commands\DbFlipPgcatCommand;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Pgcat\DryRunResult;
use Uak35\WeightedDbManager\Pgcat\FlipResult;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Tests\Support\FakeSupervisor;
use Uak35\WeightedDbManager\Tests\Support\PgcatInstallation;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The exit code of `db:pgcat-flip` as a function of what a flip would do.
 *
 * This is the shape `db:doctor` is tested in, for the same reason: an exit code is a contract
 * with a scheduler, and "it exits 1 when the flip failed" is true only of the cases somebody
 * thought to write down. Every row asserts both halves — the code, and the line the operator
 * reads beside it — because a run whose summary says "flipped" while its code says failure is
 * worse than either being wrong alone.
 *
 * The four kinds a flip can have are the axes: applied, nothing to do, skipped by the lock,
 * failed (two ways: a command that cannot work, and a source that is not there). A rehearsal
 * has its own three, because it must not exit non-zero for a flip nobody asked it to take.
 * The rows that never reach the flipper at all are in the matrix too, and a second test
 * asserts that they really do not reach it.
 *
 * Every row is a real installation in a temp directory — a state the flipper produces, not a
 * stubbed result — built by {@see PgcatInstallation}, and each row also asserts what it left
 * behind, because a code is only meaningful next to the file and the record it did or did not
 * touch.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbDoctorTest::test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag
 */
final class DbFlipPgcatCommandTest extends TestCase
{
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
     * Each row declares its own evidence, so a fixture that drifted fails on its own line
     * rather than leaving the exit code standing as evidence about a question nobody asked:
     * the target's bytes afterwards, and whether this run is what wrote the mode record.
     *
     * @return array<string, array{preset: string, options: array<string, mixed>, exit: int, kind: string, line: string, target: string, recorded: bool}>
     */
    public static function exitCodeProvider(): array
    {
        $untouched = "# live file pgcat reads\npool = 'unknown'\n";
        $readers = "# readers variant\npool = 'readers'\n";
        $writer = "# writer-only variant\npool = 'writer-only'\n";

        return [
            // ── the four kinds a flip can have ────────────────────────────────────────
            'the mode changed, so the flip applies' => [
                'preset' => 'armed', 'options' => [], 'exit' => 0,
                'kind' => 'flipped', 'line' => 'flipped: never → readers', 'target' => $readers, 'recorded' => true,
            ],
            'the mode is unchanged, so nothing happens' => [
                'preset' => 'unchanged', 'options' => [], 'exit' => 0,
                'kind' => 'no_change', 'line' => 'no change (mode=readers): mode unchanged since last flip', 'target' => $untouched, 'recorded' => false,
            ],
            'another instance holds the lock, so the flip is skipped' => [
                'preset' => 'locked', 'options' => [], 'exit' => 0,
                'kind' => 'skipped', 'line' => 'skipped (mode=readers): another flipper instance holds the lock', 'target' => $untouched, 'recorded' => false,
            ],
            'the boot window had closed before the flip converged, so it stopped trying' => [
                'preset' => 'expired', 'options' => [], 'exit' => 0,
                'kind' => FlipResult::KIND_WINDOW_CLOSED, 'line' => 'window closed (mode=readers): the flip window closed', 'target' => $untouched, 'recorded' => false,
            ],
            'the supervisor command cannot work, so the flip fails' => [
                'preset' => 'unknown-program', 'options' => [], 'exit' => 1,
                'kind' => 'failed', 'line' => 'a flip refuses before it swaps the file', 'target' => $untouched, 'recorded' => false,
            ],
            'the source config is not there, so the flip fails' => [
                'preset' => 'missing-source', 'options' => [], 'exit' => 1,
                'kind' => 'failed', 'line' => 'Source pgcat config not readable', 'target' => $untouched, 'recorded' => false,
            ],
            'the flipper is not armed for this driver, so nothing runs' => [
                'preset' => 'mysql', 'options' => [], 'exit' => 0,
                'kind' => 'disabled', 'line' => 'PostgreSQL-only', 'target' => $untouched, 'recorded' => false,
            ],

            // ── the flags that decide a flip without performing one ───────────────────
            'a rehearsal that would flip exits zero' => [
                'preset' => 'armed', 'options' => ['--dry-run' => true], 'exit' => 0,
                'kind' => 'would_flip', 'line' => 'would flip: never → readers', 'target' => $untouched, 'recorded' => false,
            ],
            'a rehearsal with nothing to do exits zero' => [
                'preset' => 'unchanged', 'options' => ['--dry-run' => true], 'exit' => 0,
                'kind' => 'would_not_flip', 'line' => 'would not flip', 'target' => $untouched, 'recorded' => false,
            ],
            'a rehearsal a flip would refuse exits one' => [
                'preset' => 'unknown-program', 'options' => ['--dry-run' => true], 'exit' => 1,
                'kind' => 'failed', 'line' => 'a flip would fail', 'target' => $untouched, 'recorded' => false,
            ],
            'a rehearsal cannot be a watch daemon' => [
                'preset' => 'armed', 'options' => ['--dry-run' => true, '--watch' => true], 'exit' => 1,
                'kind' => 'refused', 'line' => 'mutually exclusive', 'target' => $untouched, 'recorded' => false,
            ],
            'a JSON report cannot be a watch daemon' => [
                'preset' => 'armed', 'options' => ['--json' => true, '--watch' => true], 'exit' => 1,
                'kind' => 'refused', 'line' => 'mutually exclusive', 'target' => $untouched, 'recorded' => false,
            ],
            'an unknown --force-mode is refused before anything else' => [
                'preset' => 'armed', 'options' => ['--force-mode' => 'sideways'], 'exit' => 1,
                'kind' => 'refused', 'line' => "--force-mode must be 'readers' or 'writer'", 'target' => $untouched, 'recorded' => false,
            ],
            'a forced flip applies the mode it was given' => [
                'preset' => 'forced', 'options' => ['--force-mode' => 'writer'], 'exit' => 0,
                'kind' => 'flipped', 'line' => 'flipped: readers → writer', 'target' => $writer, 'recorded' => true,
            ],
            '--status reports and never flips' => [
                'preset' => 'armed', 'options' => ['--status' => true], 'exit' => 0,
                'kind' => 'status', 'line' => 'restart_command', 'target' => $untouched, 'recorded' => false,
            ],

            // ── the guard, and the other way to rehearse ─────────────────────────────
            'the flipper is not registered, so there was no run at all' => [
                'preset' => 'unregistered', 'options' => [], 'exit' => 1,
                'kind' => 'unbound', 'line' => 'PgcatConfigFlipper is not registered', 'target' => $untouched, 'recorded' => false,
            ],
            'a rehearsal of a forced flip is the flip it would apply' => [
                'preset' => 'forced', 'options' => ['--dry-run' => true, '--force-mode' => 'writer'], 'exit' => 0,
                'kind' => 'would_flip', 'line' => 'would flip: readers → writer', 'target' => $untouched, 'recorded' => false,
            ],
        ];
    }

    /**
     * The provider's keys are this method's parameter names: PHPUnit passes them as named
     * arguments, so a row that says `exit` is a row whose expected code is asserted.
     *
     * `$kind` is the row's verdict in the JSON vocabulary, asserted by the JSON half of this
     * matrix (`test_the_json_report_is_the_same_run_as_one_object`); this half asserts the
     * sentence the same run prints, which is the evidence an operator has.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_what_the_flip_would_do(
        string $preset,
        array $options,
        int $exit,
        string $kind,
        string $line,
        string $target,
        bool $recorded,
    ): void {
        $installation = $this->installation($preset);
        $recordBefore = $this->recordedMode($installation);

        $actual = Artisan::call('db:pgcat-flip', $options);
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "Exit code for the {$preset} row:\n".$output);
        $this->assertStringContainsString($line, $output, "The {$preset} row has to say what its exit code means");

        // What the row left behind, asserted from the installation rather than from the
        // flipper's own report: everything this area promises is about the file on disk.
        $this->assertSame($target, $installation->targetContents(), "Target content after the {$preset} row");

        if ($recorded) {
            $this->assertTrue($installation->stateRecorded(), 'a flip that reported success recorded the mode');
        } else {
            // The *mode* is what makes the next poll skip, so that is the thing that must not
            // move. The file itself may still be written, and since the boot window landed it
            // usually is: a run records its own bookkeeping (`runs`, `last_kind`, `last_run_at`)
            // so `/health/db` can tell "the flip ran and kept failing" from "the flip was never
            // scheduled" — the difference between a broken pooler and a broken cron. Asserting
            // on the mode keeps the rule under test while allowing both facts to coexist.
            $this->assertSame(
                $recordBefore,
                $this->recordedMode($installation),
                'a run that did not flip must not record a mode',
            );
        }
    }

    /**
     * The same matrix again, read as JSON.
     *
     * `--json` changes the report and nothing else, so each cell has to keep its code — that is
     * what a pipeline branches on — and leave the same file and the same record behind as the
     * rendered run does. The whole output is decoded rather than searched for an object, because
     * that is the assertion that fails when a rendered line is written beside the report: a job
     * that parsed one object out of a stream of prose would be relying on something this command
     * never promised.
     *
     * What the object has to carry is the verdict, the code, and the evidence of whichever route
     * produced it — a rehearsal's pipeline, a state report, or the sentence a run that never
     * reached the flipper would otherwise have printed down a human channel.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_json_report_is_the_same_run_as_one_object(
        string $preset,
        array $options,
        int $exit,
        string $kind,
        string $line,
        string $target,
        bool $recorded,
    ): void {
        $installation = $this->installation($preset);
        $recordBefore = $this->recordedMode($installation);

        $actual = Artisan::call('db:pgcat-flip', $options + ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "--json is a report, not a rule: the {$preset} row exits the same code either way");
        $this->assertStringStartsWith('{', ltrim($output), 'the report is the object, and the object is the report');

        $report = $this->report($output);

        $this->assertSame(
            [...array_keys(JsonEnvelope::CORE), 'mode', 'previous_mode', 'steps', 'status'],
            array_keys($report),
            'the keys are always present, so a rule never has to guard for a missing one — and the '
            .'report begins with the envelope\'s own keys, read from the class rather than restated, '
            .'so this command cannot drift out of the shape the other two write',
        );
        $this->assertSame('db:pgcat-flip', $report['command']);
        $this->assertSame($kind, $report['kind'], "The {$preset} row's verdict");
        $this->assertSame($exit, $report['exit_code'], 'the code travels inside the report, so nothing has to be read apart');

        $rehearsed = array_key_exists('--dry-run', $options) && ! array_key_exists('--watch', $options);

        if ($rehearsed) {
            $steps = $report['steps'];
            $this->assertIsArray($steps);
            $this->assertNotSame([], $steps, 'a rehearsal reports the pipeline it walked');

            foreach ($steps as $step) {
                $this->assertIsArray($step);
                $this->assertSame(['step', 'outcome', 'detail'], array_keys($step));
            }
        } else {
            $this->assertSame([], $report['steps'], 'only a rehearsal has steps');
        }

        if (array_key_exists('--status', $options)) {
            $this->assertIsArray($report['status'], 'the state report is the flipper\'s own array');
            $this->assertArrayHasKey('resolver_mode', $report['status']);
        } else {
            $this->assertNull($report['status']);
        }

        if (in_array($kind, [DbFlipPgcatCommand::KIND_REFUSED, DbFlipPgcatCommand::KIND_DISABLED, DbFlipPgcatCommand::KIND_UNBOUND], true)) {
            $this->assertStringContainsString(
                $line,
                (string) $report['reason'],
                'a run that never reached the flipper says why in the report, not only in the rendered one',
            );
        }

        if ($kind === FlipResult::KIND_FAILED) {
            $this->assertNotNull($report['error'], 'a failure names the step that failed');
        }

        // The same installation, the same outcome: the flag is not a second way to run.
        $this->assertSame($target, $installation->targetContents(), "--json leaves the target as the rendered run does ({$preset})");

        if ($recorded) {
            $this->assertTrue($installation->stateRecorded(), 'a flip that reported success recorded the mode');
        } else {
            // The *mode* is what makes the next poll skip, so that is the thing that must not
            // move. The file itself may still be written, and since the boot window landed it
            // usually is: a run records its own bookkeeping (`runs`, `last_kind`, `last_run_at`)
            // so `/health/db` can tell "the flip ran and kept failing" from "the flip was never
            // scheduled" — the difference between a broken pooler and a broken cron. Asserting
            // on the mode keeps the rule under test while allowing both facts to coexist.
            $this->assertSame(
                $recordBefore,
                $this->recordedMode($installation),
                'a run that did not flip must not record a mode',
            );
        }
    }

    /**
     * A rehearsal's report is its pipeline, not a sentence — which is the whole reason the mode
     * exists: a job asserting "the flip would work" wants the steps that prove it, and the step
     * whose outcome is neither `done` nor `would` is the one to print in the failure message.
     */
    public function test_the_json_rehearsal_carries_the_pipeline_and_its_outcomes(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        config()->set('database.default', self::CONNECTION);
        $installation->install();

        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--dry-run' => true, '--json' => true]));

        $report = $this->report(Artisan::output());
        $steps = $report['steps'];

        $this->assertSame(DryRunResult::KIND_WOULD_FLIP, $report['kind']);
        $this->assertSame(0, $report['exit_code']);
        $this->assertSame('readers', $report['mode']);
        $this->assertNull($report['previous_mode'], 'the mode has never been applied, which is why a flip would run');
        $this->assertNotNull($report['reason']);

        $this->assertIsArray($steps);
        $this->assertSame(
            ['lock', 'read source', 'supervisor check', 'write temp', 'remove temp', 'rename', 'supervisor', 'state file'],
            array_column($steps, 'step'),
            'the pipeline is a flip\'s order, so a step that stopped the run is readable next to the ones that did not',
        );

        // The two steps a rehearsal cannot take say `would`; everything before them was really
        // performed, which is what makes the rehearsal evidence rather than a claim.
        $outcomes = array_column($steps, 'outcome');

        $this->assertSame(DryRunResult::STEP_DONE, $outcomes[0]);
        $this->assertSame(DryRunResult::STEP_WOULD, $outcomes[array_search('rename', array_column($steps, 'step'), true)]);
        $this->assertSame(DryRunResult::STEP_WOULD, $outcomes[array_search('supervisor', array_column($steps, 'step'), true)]);
        $this->assertNotContains(DryRunResult::STEP_FAILED, $outcomes, 'nothing failed, or the verdict would not be would_flip');
    }

    /**
     * The state report as data: the flipper's own array, with none of the table's substitutions.
     *
     * A machine reading `--status --json` gets the absent path rather than `(not set)`, because
     * `(not set)` is how a table prints a `null` — and a job has to be able to test that a path
     * is unset without matching an English sentence.
     */
    public function test_the_json_state_report_is_the_flippers_own_array(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        config()->set('database.default', self::CONNECTION);
        $installation->install();

        /** @var PgcatConfigFlipper $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--status' => true, '--json' => true]));
        $output = Artisan::output();

        $report = $this->report($output);

        $this->assertSame(DbFlipPgcatCommand::KIND_STATUS, $report['kind']);
        $this->assertSame(0, $report['exit_code']);
        $this->assertSame($flipper->status(), $report['status'], 'the report carries the flipper\'s array, not the table');

        $this->assertStringNotContainsString('(not set)', $output, 'a substitution a table makes is not a value a machine wants');
        $this->assertStringNotContainsString('inactive reason', $output, 'the table\'s labels are the renderer\'s, not the report\'s');
    }

    /**
     * The JSON vocabulary is closed and documented, and the code beside each kind is the code the
     * matrix asserts for the rows that produce it.
     *
     * This is the same guard the exit table has, one level in: a job binds to `kind`, so a kind
     * that appears in a report without being written down (or a documented one that nothing
     * produces) is the drift that would break it silently. The codes are compared per kind because
     * a reader of that table is entitled to know what the run exits with.
     */
    public function test_every_json_kind_is_documented_with_its_exit_code(): void
    {
        $rows = Readme::table('### The JSON report: one object for a pipeline', 'exit');

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
     * The report a run printed, decoded. `JSON_THROW_ON_ERROR` turns "the output is not JSON" into
     * this test's failure, which is the one thing a consumer of this mode has to be told.
     *
     * @return array<string, mixed>
     */
    private function report(string $output): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The rows a flag combination can only decide by *not* reaching the flipper, asserted on
     * what the flipper was asked: a validation failure that still runs supervisorctl would be
     * a bad gate, and both of these exit 1 before anything is touched.
     */
    public function test_a_refused_flag_combination_never_reaches_the_flipper(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        $supervisor = $installation->install();
        $recordBefore = $installation->stateContents();

        $this->assertSame(1, Artisan::call('db:pgcat-flip', ['--force-mode' => 'sideways']));
        $this->assertSame(1, Artisan::call('db:pgcat-flip', ['--dry-run' => true, '--watch' => true]));
        $this->assertSame(1, Artisan::call('db:pgcat-flip', ['--json' => true, '--watch' => true]));

        $this->assertSame([], $supervisor->ran, 'a refused flag combination asks supervisor nothing');
        $this->assertSame($recordBefore, $installation->stateContents());
    }

    /**
     * `--status` wins when a rehearsal is asked for in the same run — the one documented
     * precedence the matrix cannot hold, because its evidence is what the *other* branch did
     * not print: a rehearsal that also happened would carry the `[dry run]` line, and every
     * other assertion a row makes is true of a rehearsal as well (nothing touched, nothing
     * recorded).
     *
     * The README states the rule — a state report is what was asked for — and this is what
     * pins it.
     */
    public function test_status_wins_when_a_rehearsal_is_asked_for_at_the_same_time(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        config()->set('database.default', self::CONNECTION);
        $supervisor = $installation->install();
        $recordBefore = $installation->stateContents();
        $targetBefore = $installation->targetContents();

        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--status' => true, '--dry-run' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('armed reason', $output, 'the state report is what a --status run prints');
        $this->assertStringNotContainsString('[dry run]', $output, '--status wins: the rehearsal did not run');

        $this->assertSame([], $supervisor->ran, 'neither branch asks supervisor anything');
        $this->assertSame($targetBefore, $installation->targetContents());
        $this->assertSame($recordBefore, $installation->stateContents());
    }

    /**
     * The kind→code mapping at the value objects, where the command's codes come from — so a
     * change to either `exitCode()` fails here before it reaches a scheduler.
     */
    public function test_only_a_failed_flip_or_rehearsal_is_non_zero(): void
    {
        $this->assertSame([0, 0, 0, 0, 1], [
            FlipResult::flipped('readers', null)->exitCode(),
            FlipResult::noChange('readers', 'unchanged')->exitCode(),
            FlipResult::skipped('readers', 'locked')->exitCode(),
            // A closed window is the flipper deciding not to try again, which is the run doing
            // exactly what it was asked to — the container's failure is `/health/db`'s to report.
            FlipResult::windowClosed('readers', 'the window closed')->exitCode(),
            FlipResult::failed('readers', 'broken')->exitCode(),
        ]);

        $this->assertSame([0, 0, 0, 1], [
            DryRunResult::wouldFlip('readers', null, [])->exitCode(),
            DryRunResult::wouldNotFlip('readers', 'unchanged', [])->exitCode(),
            DryRunResult::skipped('readers', 'locked', [])->exitCode(),
            DryRunResult::failed('readers', 'broken', [])->exitCode(),
        ], 'a rehearsal that cannot flip must not fail the run');
    }

    /**
     * The README row each cell of the matrix is an instance of.
     *
     * The table is written for an operator and the matrix for the code, so they do not line up
     * one to one: the three ways a flip can politely do nothing are one documented case, and
     * the three ways a command can be refused are another. This map is the correspondence, and
     * it is the only thing either side has to keep in step —
     * `test_the_matrix_agrees_with_the_readme_exit_table()` reads the table and fails when the
     * two disagree in either direction.
     *
     * @return array<string, string> key of exitCodeProvider() => the README row it documents
     */
    private static function documentedSituations(): array
    {
        return [
            'the mode changed, so the flip applies' => 'a flip applied, nothing to do, or skipped by another instance',
            'the mode is unchanged, so nothing happens' => 'a flip applied, nothing to do, or skipped by another instance',
            'another instance holds the lock, so the flip is skipped' => 'a flip applied, nothing to do, or skipped by another instance',
            'a forced flip applies the mode it was given' => 'a flip that was forced',
            'a rehearsal that would flip exits zero' => 'a rehearsal, whether it would flip, would not, or was forced',
            'a rehearsal with nothing to do exits zero' => 'a rehearsal, whether it would flip, would not, or was forced',
            'a rehearsal of a forced flip is the flip it would apply' => 'a rehearsal, whether it would flip, would not, or was forced',
            'the flipper is not armed for this driver, so nothing runs' => 'the flipper is not armed for this connection',
            '--status reports and never flips' => '--status: a state report was asked for',
            'the boot window had closed before the flip converged, so it stopped trying' => "the container's boot window had closed",
            'the supervisor command cannot work, so the flip fails' => 'a step a flip needs did not work, or a rehearsal of one',
            'the source config is not there, so the flip fails' => 'a step a flip needs did not work, or a rehearsal of one',
            'a rehearsal a flip would refuse exits one' => 'a step a flip needs did not work, or a rehearsal of one',
            'a rehearsal cannot be a watch daemon' => 'a flag combination that is refused',
            'a JSON report cannot be a watch daemon' => 'a flag combination that is refused',
            'an unknown --force-mode is refused before anything else' => 'a flag combination that is refused',
            'the flipper is not registered, so there was no run at all' => 'the container has no pgcat flipper',
        ];
    }

    /**
     * The table an operator reads is the rule the matrix enforces — the same guard the probe
     * and the doctor have, for the same reason: the exit code is what a scheduler branches on,
     * and it is documented in a different file from the code that produces it.
     *
     * Two claims, which cover the table in both directions: every row of the matrix is assigned
     * a documented case (a new cell cannot arrive undocumented), and the cases the matrix is
     * written as are exactly the rows the table has (a documented case cannot be reworded or
     * renumbered without this test being revisited, and cannot lose its last cell). The codes
     * themselves are compared per cell, because a table that lists a case against the wrong
     * number is the drift that matters.
     */
    public function test_the_matrix_agrees_with_the_readme_exit_table(): void
    {
        $rows = Readme::table('### Flipping: `db:pgcat-flip`', 'exit');
        $situations = self::documentedSituations();

        $this->assertEqualsCanonicalizing(
            array_keys(self::exitCodeProvider()),
            array_keys($situations),
            'every row of the matrix names the README row it is an instance of, and nothing else',
        );

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_values(array_unique($situations))),
            array_map([Readme::class, 'plain'], array_column($rows, 'what the run found')),
            'the README documents a case the matrix is not written as, or the matrix is written as one the README does not document',
        );

        foreach (self::exitCodeProvider() as $name => $cell) {
            $label = $situations[$name] ?? $this->fail("No README row is assigned to the [{$name}] cell.");
            $documented = Readme::row($rows, 'what the run found', $label);

            $this->assertSame(
                $cell['exit'],
                Readme::code($documented['exit']),
                sprintf(
                    'The README documents [%s] as "%s", while the matrix asserts %d.',
                    $label,
                    $documented['exit'],
                    $cell['exit'],
                ),
            );
        }
    }

    /**
     * The boot window as the `--status` table prints it — the facts an operator reads when a
     * container is about to be declared failed, from the same block `/health/db` publishes so the
     * two cannot say different things.
     *
     * The rows are asserted one by one because the interesting states are combinations: closed with
     * no runs is a scheduler that never fired, closed with runs is a pooler that cannot come up, and
     * open is a container still starting. A single sentence would collapse those, and this report is
     * read exactly when they disagree.
     */
    public function test_the_status_table_reports_the_boot_window_and_why_the_container_fails(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        config()->set('database.default', self::CONNECTION);

        $stamp = dirname($installation->path('state_file')).'/container-booted-at';
        file_put_contents($stamp, (string) (time() - 3600));

        $installation->install([
            'boot_file' => $stamp,
            'flip_window_seconds' => 480,
        ]);

        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--status' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('flip_window', $output);
        $this->assertStringContainsString('480s from boot', $output);
        $this->assertStringContainsString('flip_window_state', $output);
        $this->assertStringContainsString('closed at', $output);
        $this->assertStringContainsString('flip_converged', $output);
        $this->assertStringContainsString('never ran', $output, 'the repair differs: a missing schedule is not a pooler that will not start');
        $this->assertStringContainsString('0 recorded (last never at never)', $output);

        // And the same block in the report a machine reads, unsubstituted.
        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--status' => true, '--json' => true]));

        $window = $this->report(Artisan::output())['status']['window'];

        $this->assertTrue($window['closed']);
        $this->assertTrue($window['failed']);
        $this->assertFalse($window['converged']);
        $this->assertSame(480, $window['window_seconds']);
        $this->assertSame(0, $window['runs']);
    }

    /**
     * The other half of the same report: a container with no stamp is *not judged*, and says so
     * rather than reporting a window it cannot measure. That is the state a local run arrives in, so
     * it has to be quiet — a status command that failed a developer's machine over a boot file its
     * container never wrote would be the check nobody keeps.
     */
    public function test_the_status_table_says_when_no_boot_stamp_was_read(): void
    {
        $installation = PgcatInstallation::make($this->tempDir());
        config()->set('database.default', self::CONNECTION);

        $installation->install([
            'boot_file' => dirname($installation->path('state_file')).'/never-written',
            'flip_window_seconds' => 480,
        ]);

        $this->assertSame(0, Artisan::call('db:pgcat-flip', ['--status' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('no boot stamp was read', $output);
        $this->assertStringContainsString('unknown (no boot stamp)', $output);
        $this->assertStringNotContainsString('closed at', $output);
    }

    /**
     * Build the installation a row starts from, and answer the one question its kind depends
     * on: what supervisor says, or whether the lock is free.
     */
    private function installation(string $preset): PgcatInstallation
    {
        $dir = $this->tempDir();

        $installation = $preset === 'missing-source'
            ? PgcatInstallation::make($dir, ['readers_path' => $dir.'/not-there.toml'])
            : PgcatInstallation::make($dir);

        $supervisor = $preset === 'unknown-program'
            ? new FakeSupervisor(exit: 2, stdout: '', stderr: 'pgcat:*: ERROR (no such group)')
            : new FakeSupervisor();

        // The boot window as `entrypoint.sh` stamps it: this container booted an hour ago and
        // the flipper is allowed eight minutes, so the window has closed and the run has nothing
        // left to try.
        $windowOverrides = [];

        if ($preset === 'expired') {
            file_put_contents($dir.'/container-booted-at', (string) (time() - 3600));
            $windowOverrides = [
                'boot_file' => $dir.'/container-booted-at',
                'flip_window_seconds' => 480,
            ];
        }

        if ($preset === 'mysql') {
            config()->set('database.connections.mysql_app', ['driver' => 'mysql', 'database' => 'app']);
            config()->set('database.default', 'mysql_app');
        } else {
            config()->set('database.default', self::CONNECTION);
        }

        $installation->install($windowOverrides, $supervisor);

        // The guard's own state: the container answers the flipper's name with something that
        // is not a flipper, which is the only way that branch is reachable — a key nothing is
        // bound to resolves the class instead.
        if ($preset === 'unregistered') {
            app()->instance(PgcatConfigFlipper::class, new \stdClass());
        }

        // As a previous successful flip would have left them.
        if ($preset === 'unchanged' || $preset === 'forced') {
            $installation->recordMode('readers');
        }

        if ($preset === 'locked') {
            $this->withTheLockHeldElsewhere($installation);
        }

        return $installation;
    }

    /**
     * Another instance holding the lock, stated at the seam the flip takes it — the acquirer
     * callback — rather than arranged by really taking the lock twice.
     *
     * That is not laziness: on POSIX a second `flock` on a second handle does conflict, but on
     * Windows the lock belongs to the process, so a test that took the lock itself would
     * still see the flip acquire it and quietly test the success path. The seam is where the
     * contention is observable on every platform, and it is the same seam the flipper's own
     * tests use.
     */
    private function withTheLockHeldElsewhere(PgcatInstallation $installation): void
    {
        app()->instance(PgcatConfigFlipper::class, new PgcatConfigFlipper(
            resolver: app()->make(TimeWindowResolver::class),
            config: $installation->config(),
            stateFile: $installation->path('state_file'),
            lockFile: $installation->path('lock_file'),
            lockAcquirer: static fn (string $file): array => [false, null],
            lockReleaser: static fn ($fp): null => null,
        ));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/pgcat-flip-command-'.bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/{*,*/*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @chmod($file, 0o666);
                @unlink($file);
            }
        }

        @rmdir($dir.'/bin');
        @rmdir($dir);
    }

    /**
     * The mode the state file records, or null when it records none — which is the fact
     * "the next poll will try again" actually rests on.
     *
     * Read as the mode rather than as the file's bytes because the file now also carries the
     * run bookkeeping the boot window is judged on (`runs`, `last_kind`, `last_run_at`, and a
     * one-time `converged_at`). Those change on every run without changing the mode, so a
     * byte comparison would fail a run that behaved exactly as documented.
     */
    private function recordedMode(PgcatInstallation $installation): ?string
    {
        $contents = $installation->stateContents();

        if ($contents === null || $contents === '') {
            return null;
        }

        $state = json_decode($contents, true);

        if (!is_array($state)) {
            return null;
        }

        $mode = $state['last_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }
}

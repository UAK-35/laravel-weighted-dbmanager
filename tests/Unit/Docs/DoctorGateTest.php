<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The deploy gate the README pastes for `db:doctor --json`, run rather than read.
 *
 * WHY A GATE NEEDS A GUARD OF ITS OWN
 * -----------------------------------
 *   A preflight is the one report a pipeline refuses to ship on, so the gate that reads it is the
 *   part of this package most likely to be pasted somewhere nobody reads again — and it is written in
 *   `jq`, where the field names it selects on are strings. A `jq` expression over a key that is not
 *   there answers `null`, and `select` over a row name no row carries matches nothing, which is
 *   *false without being an error*: the gate then passes every report, including the one it was
 *   written to refuse, and the build that renamed the field is green.
 *
 *   So the gate is treated as code. The block the README pastes is read out of the file, its `jq`
 *   program is extracted and **run** against a report this command really produced — one it must ship
 *   (every row it names is `PASS`, nothing failed), and two it must refuse: a row it names that is
 *   not `PASS`, and a run in which a row it does not name failed, which is the run's own `verdict`.
 *   Each refusal is asserted to name the row, because an exit code an operator has to interpret is
 *   half a gate. And every row the gate names must be a row a real report carries, which is the one
 *   failure the gate cannot report about itself.
 *
 * WHAT IS NOT COVERED
 * -------------------
 *   The `php artisan db:doctor --json` line at the top of the block is the pipeline's, not this
 *   file's: the object is produced in process, which is the same object that line would have written
 *   to a file. `jq` has to be on the machine and the test skips without it, as `ReadmeAlertingTest`
 *   does. And the *choice* of rows is not checked — a pipeline naming a row it does not care about is
 *   a judgement, and the only rule this file enforces is that the row exists.
 */
class DoctorGateTest extends TestCase
{
    /** The line that identifies the gate among the section's other snippets: it names its rows. */
    private const MARKER = 'as $blocking';

    /**
     * The half a gate is never tested for: a preflight it should ship.
     *
     * A program that has lost its branches answers `false` to everything, which every "it refuses a
     * broken preflight" test in the world will happily confirm. This is the case that fails when it
     * does.
     */
    public function test_the_gate_the_readme_pastes_ships_a_preflight_whose_named_rows_pass(): void
    {
        $preflight = self::withRows($this->object(), [], 'PASS');

        $result = $this->jq(self::program(self::gate()), (string) json_encode($preflight));

        $this->assertSame(
            0,
            $result['exit'],
            "The gate the README pastes refuses a preflight a deploy should ship, so it would block a working installation. jq said: {$result['output']}",
        );
    }

    /**
     * A row the pipeline named is not `PASS`.
     *
     * Naming a row is an assertion about it — this one must pass — so a `WARN` on a named row is a
     * refusal rather than a note. And the refusal has to say which row, because the operator reading
     * a failed deploy step has an exit code and nothing else otherwise.
     */
    public function test_the_gate_refuses_a_named_row_that_is_not_pass(): void
    {
        $program = self::program(self::gate());
        $named = self::names($program)[0];

        $preflight = self::withRows($this->object(), [$named => 'WARN'], 'WARN');

        $result = $this->jq($program, (string) json_encode($preflight));

        $this->assertNotSame(
            0,
            $result['exit'],
            "The gate ships a preflight whose named row is not PASS. jq said: {$result['output']}",
        );

        $this->assertStringContainsString(
            $named,
            $result['output'],
            'The refusal names the row it refused on rather than leaving an exit code to be interpreted.',
        );
    }

    /**
     * Every row the gate names passed, and a row it does not name failed.
     *
     * That is the run's own `verdict`, and it is why the gate reads it: the row list is not a fixed
     * length — a connection with no read replicas has no replica rows at all — so a pipeline cannot
     * name a row it has never seen, and the fold is what catches it.
     */
    public function test_the_gate_refuses_a_run_whose_unnamed_row_failed(): void
    {
        $program = self::program(self::gate());
        $object = $this->object();

        $unnamed = array_values(array_diff(
            array_column($object['checks'], 'name'),
            self::names($program),
        ))[0] ?? null;

        $this->assertNotNull($unnamed, 'This fixture produced no row the gate does not name, so the case cannot be built.');

        $preflight = self::withRows($object, [$unnamed => 'FAIL'], 'FAIL');

        $result = $this->jq($program, (string) json_encode($preflight));

        $this->assertNotSame(
            0,
            $result['exit'],
            "The gate ships a preflight in which a row failed. jq said: {$result['output']}",
        );

        $this->assertStringContainsString(
            $unnamed,
            $result['output'],
            'The refusal names the rows that failed, so the operator has somewhere to go.',
        );
    }

    /**
     * Every row the gate blocks on is a row a report really carries.
     *
     * The quiet failure, and the reason this file exists: a row renamed in the doctor leaves the gate
     * selecting on a name nothing matches, so it passes every preflight — the broken one included —
     * and no run of it will ever say so.
     */
    public function test_every_row_the_gate_names_is_a_row_a_run_produces(): void
    {
        $rows = array_column($this->object()['checks'], 'name');

        foreach (self::names(self::program(self::gate())) as $name) {
            $this->assertContains(
                $name,
                $rows,
                sprintf('The gate blocks on [%s], which no row of a real preflight carries — it would match nothing and pass everything.', $name),
            );
        }
    }

    /**
     * The gate as the README pastes it: the fenced block that names the rows it blocks on.
     *
     * Read out of the file rather than restated, because the point of this file is that the program a
     * reader copies is the program that was run. A README whose gate has lost its marker fails here
     * rather than skipping: a guard that quietly found nothing would agree with a gate it never ran.
     */
    private static function gate(): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3).'/README.md');

        preg_match_all('/^```(?:bash|sh)\n(.*?)^```/ms', $readme, $blocks);

        foreach ($blocks[1] as $block) {
            if (str_contains($block, self::MARKER)) {
                return $block;
            }
        }

        self::fail('README.md pastes no deploy gate: no fenced block names the rows it blocks on.');
    }

    /**
     * The `jq` program inside the gate — what a pipeline runs, and what this file runs against a
     * report a run produced.
     */
    private static function program(string $gate): string
    {
        if (preg_match("/jq -e '(.*?)'\s*preflight\.json/s", $gate, $matches) !== 1) {
            self::fail('The gate no longer holds one jq -e program reading preflight.json, so there is nothing to run.');
        }

        return $matches[1];
    }

    /**
     * The rows the gate blocks on, read out of the program: the list its first line binds to
     * `$blocking`.
     *
     * @return list<string>
     */
    private static function names(string $program): array
    {
        if (preg_match('/\[\s*("(?:[^"]*)"(?:\s*,\s*"[^"]*")*)\s*\]/', $program, $matches) !== 1) {
            self::fail('The gate no longer opens with the list of rows it blocks on, so which rows it covers cannot be read.');
        }

        return array_values((array) json_decode('['.$matches[1].']', true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * A report this command really produced.
     *
     * The shape is the point: the gate is a promise about field names, and a real object is the only
     * thing that can keep it honest — a fixture written here would agree with whatever the gate
     * happened to say.
     *
     * @return array<string, mixed>
     */
    private function object(): array
    {
        $buffer = new BufferedOutput();

        $this->app->make(Kernel::class)->call('db:doctor', ['--json' => true], $buffer);

        $decoded = json_decode(trim($buffer->fetch()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, 'a run writes one object');

        return $decoded;
    }

    /**
     * The report with the rows put where a case needs them: the ones named here take the verdict
     * given, every other row passes, and the run's own verdict is set the way its fold would set it —
     * the loudest row.
     *
     * Moving the fold by hand rather than asking the doctor to produce each state is deliberate: the
     * cases then say what the *gate* does with a report, which is what is being pinned, rather than
     * what a fixture can be made to say.
     *
     * @param array<string, mixed>  $object
     * @param array<string, string> $rows row name => the verdict written on it
     * @return array<string, mixed>
     */
    private static function withRows(array $object, array $rows, string $verdict): array
    {
        foreach ($object['checks'] as $index => $row) {
            $object['checks'][$index]['verdict'] = $rows[$row['name']] ?? 'PASS';
        }

        $object['verdict'] = $verdict;

        return $object;
    }

    /**
     * One `jq` run of the gate, with the report piped in.
     *
     * Not installed is a fact about this machine rather than about the gate, so it skips rather than
     * failing.
     *
     * @return array{exit: int, output: string}
     */
    private function jq(string $program, string $preflight): array
    {
        if (! self::jqAvailable()) {
            $this->markTestSkipped('jq is not on this machine, so the gate the README pastes cannot be run.');
        }

        $process = new Process(['jq', '-e', $program]);
        $process->setInput($preflight);
        $process->setTimeout(20);

        return ['exit' => $process->run(), 'output' => $process->getErrorOutput().$process->getOutput()];
    }

    /**
     * Whether this machine has `jq`. A missing binary arrives as an exception or as a non-zero exit
     * depending on the platform, so both are read as "not there".
     */
    private static function jqAvailable(): bool
    {
        try {
            $process = new Process(['jq', '--version']);
            $process->setTimeout(20);
            $process->run();
        } catch (ProcessRuntimeException) {
            return false;
        }

        return $process->getExitCode() === 0;
    }
}

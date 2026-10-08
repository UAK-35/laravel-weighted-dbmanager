<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;

require_once Handoff::root() . '/bin/interpreter.php';

/**
 * The resolver in `bin/interpreter.php`: which interpreter the machine record resolves to, whether a
 * process may be handed over to it, and what the diagnosis says.
 *
 * WHY IT IS REQUIRED RATHER THAN RUN
 * ----------------------------------
 *   `bin/interpreter.php` is written to be required — its own docblock says so, and that is what
 *   `bin/tool.php` and `bin/checks.php` do — so the functions are called here rather than driven
 *   through a subprocess. The two things a subprocess answers that a call cannot are the diagnosis
 *   and the exit code, and both are read where they belong: through `Handoff::run()`, the same
 *   reader the other scripts' tests use.
 *
 * WHY THERE IS NO FIXTURE TREE
 * ---------------------------
 *   The states this file is about are states of a *root*: a record that is not there, one that names
 *   a file beside it, one that names a file that does not answer. Only the first needs no fixture —
 *   any directory without `.agents/machine.local.json` is that root — and the last two are read from
 *   the record this machine has, because a record naming an interpreter that runs is exactly what
 *   this machine is for. So the temp directory is asked for a root rather than written into, and the
 *   case a clone cannot answer skips rather than invents one.
 */
final class InterpreterResolverTest extends TestCase
{
    /** The interpreter this process is running on, which is the fallback every refusal answers with. */
    private const RUNNING = PHP_BINARY;

    /**
     * The green half: the record this machine has names an interpreter, it is a file, it answers as
     * a `php`, and the resolver hands it over rather than falling back. Held to the record's own
     * words, so the assertion is about the *reading* and not about which interpreter this suite
     * happens to be running on.
     */
    public function test_the_record_on_this_machine_resolves_to_the_interpreter_it_names(): void
    {
        $record = self::record();

        if ($record === null) {
            $this->markTestSkipped('This checkout has no .agents/machine.local.json, so there is no interpreter here for the resolver to name.');
        }

        $resolved = machineInterpreter(Handoff::root());

        self::assertNull($resolved['why'], 'the resolver fell back on a record that names a working interpreter');
        self::assertSame($record['exe'], $resolved['recorded']);
        self::assertNotNull($resolved['exe']);
        self::assertTrue(is_file($resolved['exe']), sprintf('%s was resolved and is not a file.', $resolved['exe']));
    }

    /**
     * A root with no record is a checkout nobody has described — a clone, CI's runner — and it is a
     * fallback rather than a failure here: the gate is the reader that reports it, this is the one
     * that keeps the commands runnable while it is reported.
     */
    public function test_a_root_with_no_record_falls_back_to_the_running_interpreter(): void
    {
        $resolved = machineInterpreter(sys_get_temp_dir());

        self::assertSame(self::RUNNING, $resolved['exe']);
        self::assertNull($resolved['recorded']);
        self::assertNotNull($resolved['why']);
        self::assertStringContainsString('there is no machine record at', $resolved['why']);
        self::assertStringContainsString('this is the interpreter that started the command', $resolved['why']);
    }

    /**
     * The probe, and the two answers it has to give: a `php` that reports its version and can read
     * the script it is about to be given, and one that reports its version and cannot.
     *
     * The unreadable script is a path with no file at it rather than a permission the fixture would
     * have to arrange: what the probe is asking is whether the interpreter can read *this* file, and
     * a file that is not there is the same answer — `false` from the same call — that a file it may
     * not read gives. That is why exit 2 means "started and could not read it" rather than naming a
     * reason: the reason is the filesystem's, and the verdict is the probe's.
     */
    public function test_the_probe_reads_the_script_the_interpreter_would_be_given(): void
    {
        $readable = machineInterpreterProbe(self::RUNNING, Handoff::root(), Handoff::root() . '/bin/interpreter.php');

        self::assertSame(0, $readable['exit'], 'the probe refused the interpreter this suite is running on');
        self::assertSame(PHP_VERSION, trim($readable['output']));

        $absent = machineInterpreterProbe(
            self::RUNNING,
            Handoff::root(),
            Handoff::root() . '/bin/swrr-no-such-script-' . bin2hex(random_bytes(6)) . '.php',
        );

        self::assertSame(2, $absent['exit'], 'the probe passed an interpreter that cannot read the script it was given');
        self::assertSame(PHP_VERSION, trim($absent['output']), 'the probe lost the version of an interpreter that answered');
    }

    /**
     * The guard that makes a hand-over terminating: one interpreter reachable by two paths is the
     * same interpreter, so the child resolves the pair the same way and stops.
     */
    public function test_two_paths_to_one_interpreter_are_the_same_file(): void
    {
        $one = Handoff::root() . '/bin/interpreter.php';

        self::assertTrue(machineInterpreterSame($one, Handoff::root() . '/bin/./interpreter.php'));
        self::assertFalse(machineInterpreterSame($one, Handoff::root() . '/bin/tool.php'));
        self::assertFalse(machineInterpreterSame($one, $one . '-and-not-there'));

        // The same file through a differently-cased directory, which is the second way Windows
        // spells one path. Asked only where the filesystem answers it, so the reading is a
        // comparison of two real paths rather than of two strings.
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(machineInterpreterSame($one, Handoff::root() . '/BIN/INTERPRETER.PHP'));
        } else {
            self::assertFalse(machineInterpreterSame($one, Handoff::root() . '/BIN/INTERPRETER.PHP'));
        }
    }

    /**
     * The diagnosis, which is the one thing here a person runs rather than a caller: the interpreter
     * the record names, the one the resolver answered with, and the one this process is. All three
     * rows are facts about a run, so they are asserted rather than hard-coded — and the exit code is
     * the third of them.
     */
    public function test_the_diagnosis_prints_the_recorded_and_the_running_interpreter(): void
    {
        $run = Handoff::run([self::RUNNING, Handoff::root() . '/bin/interpreter.php']);

        self::assertSame(0, $run['exit'], self::describe($run));
        self::assertStringContainsString('  recorded   ' . (self::record()['exe'] ?? '(none)'), $run['output'], self::describe($run));
        self::assertStringContainsString('  resolved   ', $run['output'], self::describe($run));
        self::assertStringContainsString('  running    ' . self::RUNNING, $run['output'], self::describe($run));
        self::assertStringContainsString('  switched   ', $run['output'], self::describe($run));
    }

    /**
     * The record's `php` block as this machine has it, or `null` on a checkout that has no record —
     * which is the state four of this file's readings skip in rather than failing.
     *
     * @return array{exe: string, version: string, requirement: string}|null
     */
    private static function record(): ?array
    {
        $path = Handoff::root() . '/.agents/machine.local.json';

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $php = is_array($decoded) ? ($decoded['php'] ?? null) : null;

        if (!is_array($php) || !is_string($php['exe'] ?? null)) {
            return null;
        }

        return [
            'exe' => (string) $php['exe'],
            'version' => is_string($php['version'] ?? null) ? $php['version'] : '',
            'requirement' => is_string($php['requirement'] ?? null) ? $php['requirement'] : '',
        ];
    }

    /** @param array{exit: int, output: string, error: string} $run */
    private static function describe(array $run): string
    {
        return sprintf(
            "exit %d\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $run['exit'],
            $run['output'],
            $run['error'],
        );
    }
}

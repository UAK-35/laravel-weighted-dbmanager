<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;

/**
 * The gate's `interpreter` check, driven against a record this test writes rather than the one
 * this machine has.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `bin/checks.php` asks one question about the interpreter: is the file the machine record names
 *   there, does it report the version the record writes, and does that version satisfy the
 *   requirement the record carries? Three claims, and every state that makes one of them false is a
 *   state of the *record* — which is gitignored, describes one disk, and cannot be broken on the
 *   machine that depends on it just to see whether the check notices.
 *
 *   So the state is made somewhere else. Each case builds a root holding the gate itself and one
 *   `.agents/machine.local.json`, and runs `--only=interpreter` in it; the interpreter the fixture
 *   points at is this machine's own `PHP_BINARY`, so the version it reports is a fact rather than a
 *   fixture, and the failures are the record's rather than the interpreter's.
 *
 * WHY THE FIXTURE CARRIES A vendor/
 * ---------------------------------
 *   The requirement half of the check is evaluated with composer/semver and skips where that is not
 *   installed, exactly as the tools check does — so a fixture root on its own would skip the check
 *   rather than exercise it. The fixture writes a `vendor/autoload.php` that requires this package's
 *   own autoloader, by absolute path, at run time: the same one the suite is running under, so what
 *   the check reads there is the library that is really installed rather than a stand-in for it.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   It does not touch `.agents/machine.local.json`. One case reads it, to hold the green half, and
 *   that one skips on a checkout with no record — a clone and CI's runner describe no machine, which
 *   is the state the check itself skips in.
 */
final class GateInterpreterTest extends TestCase
{
    /**
     * What the fixture roots are called, so a run that leaks one is recognisable in the temp
     * directory rather than a directory nobody can account for.
     */
    private const PREFIX = '/swrr-gate-interpreter-';

    /** @var list<string> */
    private static array $trash = [];

    private static bool $sweeper = false;

    public function test_a_checkout_with_no_record_skips_rather_than_failing(): void
    {
        $run = self::checkout(null);

        self::assertSame(0, $run['exit'], self::describe($run));
        self::assertStringContainsString('1 skipped', $run['output'], self::describe($run));
        self::assertStringContainsString(
            'this checkout has no .agents/machine.local.json',
            $run['output'],
            self::describe($run),
        );
    }

    public function test_a_record_that_does_not_read_as_json_fails(): void
    {
        $run = self::checkout('{"php": {');

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the machine record cannot be read: the file does not read as a JSON object',
            $run['output'],
            self::describe($run),
        );
    }

    public function test_a_record_with_no_php_block_fails(): void
    {
        $run = self::checkout(['checks' => ['count' => 13]]);

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the machine record cannot be read: the record carries no `php` block',
            $run['output'],
            self::describe($run),
        );
    }

    /**
     * The block is there and names no file: the shape a record takes when the interpreter fact is
     * edited out of it, which leaves the next session with nothing to run rather than a wrong path
     * to notice. Named separately from a path that does not resolve, because they are two states.
     */
    public function test_a_record_that_writes_no_interpreter_fails(): void
    {
        $run = self::checkout(['php' => ['version' => PHP_VERSION, 'requirement' => '^8.4']]);

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the record writes no php.exe, so no interpreter is written down anywhere',
            $run['output'],
            self::describe($run),
        );
    }

    public function test_a_record_with_no_requirement_fails(): void
    {
        $run = self::checkout(['php' => ['exe' => PHP_BINARY, 'version' => PHP_VERSION]]);

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the record carries no requirement, so nothing says which interpreter versions this machine is meant to satisfy',
            $run['output'],
            self::describe($run),
        );
    }

    public function test_a_record_naming_an_interpreter_that_is_not_there_fails(): void
    {
        // Written at run time rather than spelled out: a path that names a drive is the finding
        // `tests/Unit/Support/MachinePathsTest.php` refuses in the files a commit carries, and the
        // state this needs is any path with no file at it — the hole is the interpreter, not the disk.
        $gone = sys_get_temp_dir() . '/swrr-gate-interpreter-gone-' . bin2hex(random_bytes(6)) . '/php.exe';

        $run = self::checkout(self::record($gone));

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            sprintf('the record names %s as php.exe, and no file is there', $gone),
            $run['output'],
            self::describe($run),
        );
    }

    public function test_a_version_the_interpreter_does_not_report_fails(): void
    {
        $run = self::checkout(self::record(PHP_BINARY, version: '8.3.9', requirement: '^8.3'));

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the record writes PHP 8.3.9 and the interpreter reports ' . PHP_VERSION,
            $run['output'],
            self::describe($run),
        );
        self::assertStringContainsString('so the record is stale', $run['output'], self::describe($run));
    }

    public function test_a_requirement_the_interpreter_does_not_satisfy_fails(): void
    {
        $run = self::checkout(self::record(PHP_BINARY, requirement: '^9.0'));

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the interpreter reports PHP ' . PHP_VERSION . ', which does not satisfy the recorded ^9.0',
            $run['output'],
            self::describe($run),
        );
    }

    /**
     * The third verdict on a requirement, and the one a wrong-typed record produces: composer/semver
     * cannot parse it at all, so the check says so rather than reporting it as unsatisfied — a
     * constraint nothing can read is not evidence about the interpreter.
     */
    public function test_a_requirement_nothing_can_evaluate_fails(): void
    {
        $run = self::checkout(self::record(PHP_BINARY, requirement: '^8.4 ||'));

        self::assertSame(1, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'the recorded requirement ^8.4 || is not a constraint anything can evaluate',
            $run['output'],
            self::describe($run),
        );
    }

    /**
     * The green half, read from the record this machine actually has: the check's verdict on the
     * state the note was written in. It is the one case a clone cannot answer, so it skips there —
     * which is the same reason the check itself skips.
     */
    public function test_the_record_on_this_machine_passes(): void
    {
        $record = Handoff::root() . '/.agents/machine.local.json';

        if (!is_file($record)) {
            $this->markTestSkipped('This checkout has no .agents/machine.local.json, so there is no interpreter here for the record to name.');
        }

        $run = self::checkout((string) file_get_contents($record));

        self::assertSame(0, $run['exit'], self::describe($run));
        self::assertStringContainsString(
            'PHP ' . PHP_VERSION . ' at the interpreter the record names, which satisfies ^8.4',
            $run['output'],
            self::describe($run),
        );
    }

    /**
     * One record, written the way the real one is: the interpreter to run, the version it reports,
     * and the constraint it has to meet.
     *
     * @return array{php: array{exe: string, version: string, requirement: string}}
     */
    private static function record(string $exe, ?string $version = null, string $requirement = '^8.4'): array
    {
        return ['php' => ['exe' => $exe, 'version' => $version ?? PHP_VERSION, 'requirement' => $requirement]];
    }

    /**
     * A root holding the gate and one record — or no record at all — and the run of that gate.
     *
     * @param array<string, mixed>|string|null $record the record to write, verbatim when it is a
     *        string so a case can write one that is not JSON
     * @return array{exit: int, output: string, error: string}
     */
    private static function checkout(array|string|null $record): array
    {
        $root = sys_get_temp_dir() . self::PREFIX . bin2hex(random_bytes(6));

        self::directory($root . '/bin');
        self::directory($root . '/.agents');
        self::directory($root . '/vendor');

        // The gate and the two files it cannot run without: the tool manifest and the interpreter
        // resolver are `require`s beside it rather than arguments, so a root holding the gate alone
        // dies before any check. The resolver is copied as the gate's own companion, and it reads
        // the fixture's record rather than this machine's — which is why the fixture root has one.
        copy(Handoff::root() . '/bin/checks.php', $root . '/bin/checks.php');
        copy(Handoff::root() . '/bin/tools.php', $root . '/bin/tools.php');
        copy(Handoff::root() . '/bin/interpreter.php', $root . '/bin/interpreter.php');

        // The library the check's requirement half needs, as the real one rather than a stand-in:
        // see the class docblock for why a bare fixture root would skip instead of running.
        file_put_contents(
            $root . '/vendor/autoload.php',
            '<?php require ' . var_export(Handoff::root() . '/vendor/autoload.php', true) . ';' . PHP_EOL,
        );

        if ($record !== null) {
            file_put_contents(
                $root . '/.agents/machine.local.json',
                is_string($record) ? $record : (string) json_encode($record, JSON_PRETTY_PRINT),
            );
        }

        self::keep($root);

        return Handoff::run([PHP_BINARY, $root . '/bin/checks.php', '--only=interpreter']);
    }

    private static function directory(string $path): void
    {
        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            self::fail(sprintf('Could not create %s for a gate fixture.', $path));
        }
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

    /**
     * The fixture roots are removed when the process ends rather than when each test does, and one
     * sweeper is registered for all of them: a root removed under a handle that has not closed yet
     * is a directory left behind on Windows, and the reason is in `ReleaseRepo::remove()`.
     */
    private static function keep(string $root): void
    {
        self::$trash[] = $root;

        if (self::$sweeper) {
            return;
        }

        self::$sweeper = true;

        register_shutdown_function(static function (): void {
            foreach (array_reverse(self::$trash) as $path) {
                self::remove($path);
            }
        });
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0o777);
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (@rmdir($path)) {
                return;
            }

            usleep(25_000);
        }
    }
}

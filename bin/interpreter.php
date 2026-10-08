<?php

declare(strict_types=1);

/**
 * bin/interpreter.php — the interpreter this package's commands run under, read from the machine
 * record rather than typed on the command line.
 *
 * WHAT IT IS
 * ----------
 *   One reading of `.agents/machine.local.json`, and one way to hand the current process over to
 *   the interpreter it names. Every script here is started by whatever `php` the caller had —
 *   often a bare `php` that does not resolve at all on this machine, and otherwise one whose
 *   version nobody chose — and the record is the only place that says which interpreter the work
 *   is meant to happen on. So `bin/tool.php` and `bin/checks.php` both call `redispatch()` before
 *   they do anything else, and a process that turns out to be the wrong interpreter re-runs itself
 *   under the right one instead of doing the work twice on the wrong one.
 *
 * THE FOUR THINGS IT ANSWERS
 * --------------------------
 *   `machineInterpreterRecord($root)`  the record's `php` block as it stands: the path, whether
 *                                      there is a record at all, and why it could not be read.
 *
 *   `machineInterpreter($root)`        the executable children should be run with: the record's
 *                                      `php.exe` when it is a file *and answers as a php*, and the
 *                                      interpreter running this process when it is not, with the
 *                                      reason written down.
 *
 *   `redispatch($root)`                hand this process over to the resolved interpreter, or
 *                                      return and let the caller carry on. It is the same routine
 *                                      `--run` uses, minus the script name.
 *
 *   `--run <script> [args …]`          the same handover for something else: a script, a tool, a
 *                                      command. This is how a session that has any `php` at all can
 *                                      run a script under the recorded one without naming a path.
 *
 * WHY THE FALLBACK IS NOT A FAILURE HERE
 * --------------------------------------
 *   A record that names a moved interpreter, or no record at all, is a state the gate reports —
 *   `bin/checks.php` has an `interpreter` check for exactly that, and it fails on it. This file is
 *   the other half of that decision: it keeps the commands *runnable* while the report is red, so
 *   a broken record is something a session reads and fixes rather than something that stops it
 *   from running the thing that would tell it so. The fallback is never silent either — `why`
 *   carries the sentence, and every caller prints it.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   It does not evaluate the record's `requirement`. That is a constraint reader's job —
 *   `composer/semver`, which is not installed in a checkout before `composer install`, and this
 *   file has to work in exactly that state. The gate's `interpreter` check does evaluate it, with
 *   the library, and says so when it cannot.
 *
 *   It does not search the disk for an interpreter. A path that is not the one the record writes
 *   is a machine fact nobody has been told, which is what `tests/Unit/Support/MachinePathsTest.php`
 *   and the record itself exist to prevent.
 *
 * READ IT WITH `require`
 * ----------------------
 *   It runs nothing on require — the CLI below is behind the `$argv` check, and `PHP_SAPI` is
 *   `cli` for the scripts and for a test alike — so a caller requires this file and calls what it
 *   needs. Its own CLI is the diagnosis, not the interface.
 *
 * EXIT CODES
 * ----------
 *   With `--run`: whatever the command it ran exited with. Otherwise 0, and 1 when the resolved
 *   interpreter is not a file that can be started.
 */

/** The package root, when this file is the process's entry point rather than a `require`. */
$interpreterRoot = str_replace('\\', '/', dirname(__DIR__));

/**
 * The record's `php` block, and whether the record could be read at all.
 *
 * The three absences are told apart on purpose: no file is a checkout that describes no machine,
 * which is a skip wherever it is asked; a file that does not decode, or one with no `php` block,
 * is a record nobody can act on, which is a finding. `bin/checks.php`'s `interpreter` check fails
 * on the two findings and skips the absence, and it reads this function to do it.
 *
 * @return array{path: string, exists: bool, php: array<string, mixed>|null, error: string|null}
 */
function machineInterpreterRecord(string $root): array
{
    $path = $root . '/.agents/machine.local.json';

    if (!is_file($path)) {
        return ['path' => $path, 'exists' => false, 'php' => null, 'error' => null];
    }

    $decoded = json_decode((string) @file_get_contents($path), true);

    if (!is_array($decoded)) {
        return [
            'path' => $path,
            'exists' => true,
            'php' => null,
            'error' => 'the file does not read as a JSON object',
        ];
    }

    $php = $decoded['php'] ?? null;

    if (!is_array($php)) {
        return [
            'path' => $path,
            'exists' => true,
            'php' => null,
            'error' => 'the record carries no `php` block, which is where the interpreter is written down',
        ];
    }

    return ['path' => $path, 'exists' => true, 'php' => $php, 'error' => null];
}

/**
 * The interpreter the work should happen on, and why that is the answer.
 *
 * The record's `php.exe` wins whenever it is a file that answers as a `php` — that is the whole
 * point of writing it down — and anything else hands the caller the interpreter already running
 * this process, with a sentence saying which of the states it was in. Nothing is thrown and nothing
 * exits: a caller that wants the state to be a *failure* is the gate's `interpreter` check, which
 * asks the same record and is the only reader that does. The states — no record, unreadable, no
 * `exe`, no file, and a file that does not answer as a `php` — are told apart in `why`, so the
 * sentence a session reads names the one repair it has to make rather than five candidates.
 *
 * @return array{exe: string|null, recorded: string|null, why: string|null, source: string}
 */
function machineInterpreter(string $root): array
{
    $record = machineInterpreterRecord($root);
    $php = $record['php'] ?? [];
    $recorded = is_string($php['exe'] ?? null) ? $php['exe'] : null;

    if (! $record['exists']) {
        return [
            'exe' => PHP_BINARY,
            'recorded' => null,
            'why' => sprintf('there is no machine record at %s, so this is the interpreter that started the command', $record['path']),
            'source' => $record['path'],
        ];
    }

    if ($record['error'] !== null) {
        return [
            'exe' => PHP_BINARY,
            'recorded' => $recorded,
            'why' => 'the machine record cannot be read (' . $record['error'] . '), so this is the interpreter that started the command',
            'source' => $record['path'],
        ];
    }

    if ($recorded === null) {
        return [
            'exe' => PHP_BINARY,
            'recorded' => null,
            'why' => 'the machine record writes no php.exe, so this is the interpreter that started the command',
            'source' => $record['path'],
        ];
    }

    if (!is_file($recorded)) {
        return [
            'exe' => PHP_BINARY,
            'recorded' => $recorded,
            'why' => sprintf('the machine record names %s as php.exe and there is no file there, so this is the interpreter that started the command', $recorded),
            'source' => $record['path'],
        ];
    }

    // A file is not an interpreter, and answering as one is not enough either. A copy of `php.exe`
    // lifted out of its directory exits 127 on a missing DLL; one on a volume it cannot read starts,
    // prints its version, and then fails on the script it is handed. Handing the process over to
    // either would replace a readable report with a crash or a puzzle — the gate would die before
    // the check that exists to report it. So the recorded file has to answer as a `php` *and* read
    // the script it is about to be given, which is the same probe the gate's `interpreter` check
    // reads its verdict from.
    $probe = machineInterpreterProbe($recorded, $root);

    if ($probe['exit'] !== 0 || trim($probe['output']) === '') {
        return [
            'exe' => PHP_BINARY,
            'recorded' => $recorded,
            'why' => $probe['exit'] === 2
                ? sprintf('the machine record names %s as php.exe and it cannot read the script it would be given (exit 2), so this is the interpreter that started the command', $recorded)
                : sprintf('the machine record names %s as php.exe and it does not answer as a php (%s), so this is the interpreter that started the command', $recorded, $probe['exit'] === 0 ? 'it printed nothing' : 'exit ' . $probe['exit']),
            'source' => $record['path'],
        ];
    }

    return ['exe' => $recorded, 'recorded' => $recorded, 'why' => null, 'source' => $record['path']];
}

/**
 * What one interpreter says about itself: the version it reports, and whether it can read the script
 * it is about to be given — two questions, one run.
 *
 * Captured rather than handed this process's streams, because this runs *before* a caller has
 * decided anything and a version printed into a tool's output would be this file's noise rather
 * than the tool's answer. One reader wants the version (`bin/checks.php`'s `interpreter` check
 * compares it with the record), one wants only whether the file may be handed the process
 * (`machineInterpreter()`), and the exit code is what tells the two failures apart: 2 is a `php`
 * that runs and cannot read the tree it is about to run, anything else is a file that is not a
 * usable `php` at all.
 *
 * The script read is the one this process is already running — `$argv[0]` as the caller wrote it,
 * resolved — because that is the file a hand-over is about to hand over, and because it is a file
 * that necessarily exists wherever this runs rather than an assumption about the layout the probe
 * is called from.
 *
 * @return array{exit: int, output: string}
 */
function machineInterpreterProbe(string $exe, string $root, ?string $script = null): array
{
    $script ??= machineInterpreterScript();

    $process = @proc_open(
        [
            $exe,
            '-r',
            sprintf('echo PHP_VERSION; exit(@file_get_contents(%s) === false ? 2 : 0);', var_export($script, true)),
        ],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'could not start: ' . $exe];
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => $output];
}

/**
 * Whether two paths are the same interpreter — the guard that makes re-dispatching terminating.
 *
 * The command line can name one interpreter two ways: a path and the same path through a symlink or
 * a differently-cased directory. So they are compared as resolved files, case-insensitively on
 * Windows, which is the platform where `php.exe` arrives with a case and a `8.3`-beside-`8.4`
 * layout as often as not. When they are the same file the child resolves the same pair, sees them
 * equal, and stops — which is why no environment flag is needed to carry "already re-dispatched"
 * across the process boundary.
 */
function machineInterpreterSame(string $one, string $other): bool
{
    $first = realpath($one);
    $second = realpath($other);

    if ($first === false || $second === false) {
        return false;
    }

    return PHP_OS_FAMILY === 'Windows'
        ? strcasecmp($first, $second) === 0
        : $first === $second;
}

/**
 * Run a command under the resolved interpreter, with this process's own streams, and answer with
 * its exit code.
 *
 * The three standard streams are handed over rather than piped, so a script that reads a prompt
 * still reads it and a script that colours its output still colours it: `bin/release.php` asks
 * before it commits, and a tool whose output is being read by a person is not this file's to
 * capture. Nothing is read back, so nothing can be reordered or lost.
 *
 * @param list<string> $command
 */
function machineInterpreterRun(array $command, string $root): int
{
    $process = @proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, 'could not start: ' . implode(' ', $command) . PHP_EOL);

        return 127;
    }

    return proc_close($process);
}

/**
 * Hand this process over to the resolved interpreter, when that is a different one.
 *
 * It re-runs *this* script, with this script's arguments, which is what makes every caller
 * path-free: a session can start `bin/tool.php` or `bin/checks.php` with any `php` it can reach,
 * and the work happens on the interpreter the machine record names. The handover is silent about
 * itself — a line on stdout would land in the middle of a tool's output and a line on stderr would
 * look like an error — and it is visible where it matters instead: the gate's `interpreter` check
 * prints both interpreters, and `--verbose` shows the switch as the process restarting.
 *
 * A caller that has already been handed over does not come back here with a different answer: the
 * child is the resolved interpreter, so it resolves the same file and returns.
 */
function redispatch(string $root): void
{
    $interpreter = machineInterpreter($root);
    $exe = $interpreter['exe'];

    if ($exe === null || machineInterpreterSame($exe, PHP_BINARY)) {
        return;
    }

    if ($interpreter['why'] !== null) {
        fwrite(STDERR, '• ' . $interpreter['why'] . PHP_EOL);
    }

    // This script and its own arguments, with the script resolved: whatever the caller's cwd was,
    // the child runs in the package root, and a relative name would be looked for there instead.
    $arguments = $_SERVER['argv'] ?? [];
    $arguments[0] = machineInterpreterScript();

    exit(machineInterpreterRun([$exe, ...array_values($arguments)], $root));
}

/**
 * The script this process is running, as the argument a re-dispatch has to pass back to it.
 *
 * `$argv[0]` is the path the caller wrote, which is relative to wherever the caller stood rather
 * than to this package, so it is resolved first: the child runs in the package root, and a relative
 * script name would be looked for there instead of where the caller meant.
 */
function machineInterpreterScript(): string
{
    $script = $_SERVER['argv'][0] ?? '';
    $resolved = realpath($script);

    return $resolved === false ? $script : $resolved;
}

if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    interpreterUsage($interpreterRoot);
}

/**
 * The CLI, reached only when this file is the process's entry point.
 *
 * It is a diagnosis first — which interpreter the package resolves, and which one this process is —
 * and a runner second, which is the piece a session needs when it has no `php` on `PATH`: any
 * interpreter that can start this file can also be the launcher for a command that has to run under
 * the recorded one.
 */
function interpreterUsage(string $root): never
{
    $arguments = array_slice($_SERVER['argv'], 1);

    if ($arguments !== [] && ($arguments[0] === '--help' || $arguments[0] === '-h')) {
        echo <<<'TXT'
            bin/interpreter.php — the interpreter the machine record names, and running under it.

            Usage:
              php bin/interpreter.php                     which interpreter is resolved, and which one
                                                          this process is — nothing is run
              php bin/interpreter.php --run <script> [args …]
                                                          run a script under the resolved interpreter,
                                                          forwarding every argument after the name,
                                                          this process's streams and its exit code
              php bin/interpreter.php --help              The record read is .agents/machine.local.json, and `php.exe` in it is the answer wherever
            that is a file that answers as a `php` — a file that is not one falls back to the
            interpreter that started the command, with the reason printed, rather than handing the
            process to something that cannot run. Every script under bin/ does this by itself, so
            `php bin/checks.php` runs under the recorded interpreter whichever `php` started it.

            TXT;

        exit(0);
    }

    if ($arguments !== [] && $arguments[0] === '--run') {
        if (!isset($arguments[1])) {
            fwrite(STDERR, 'Nothing to run: --run wants a script name.' . PHP_EOL);
            exit(2);
        }

        $interpreter = machineInterpreter($root);
        $exe = $interpreter['exe'];

        if ($exe === null) {
            fwrite(STDERR, 'No interpreter to run with, and the machine record does not say why.' . PHP_EOL);
            exit(1);
        }

        if ($interpreter['why'] !== null) {
            fwrite(STDERR, '• ' . $interpreter['why'] . PHP_EOL);
        }

        // A script named the way the caller names it — relative to where they stood — is resolved
        // before it is handed to the child, which runs in the package root.
        $target = $arguments[1];
        $resolved = realpath($target);

        exit(machineInterpreterRun([$exe, ...($resolved === false ? [$target] : [$resolved]), ...array_slice($arguments, 2)], $root));
    }

    if ($arguments !== []) {
        fwrite(STDERR, 'Unknown option: ' . $arguments[0] . PHP_EOL . PHP_EOL);
        exit(2);
    }

    $interpreter = machineInterpreter($root);

    printf("  recorded   %s%s", $interpreter['recorded'] ?? '(none)', PHP_EOL);
    printf("  resolved   %s%s", $interpreter['exe'] ?? '(none)', PHP_EOL);
    printf("  running    %s%s", PHP_BINARY, PHP_EOL);
    printf(
        "  switched   %s%s",
        $interpreter['exe'] !== null && ! machineInterpreterSame($interpreter['exe'], PHP_BINARY) ? 'no — a re-dispatch would' : 'no — this process is it',
        PHP_EOL,
    );

    if ($interpreter['why'] !== null) {
        echo PHP_EOL . '• ' . $interpreter['why'] . PHP_EOL;
    }

    exit($interpreter['exe'] === null ? 1 : 0);
}

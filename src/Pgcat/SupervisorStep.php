<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Symfony\Component\Process\Process;

/**
 * The step a pgcat flip takes after it has swapped the file: telling supervisor to
 * pick the new configuration up.
 *
 * swap() copies the variant over pgcat.toml and then runs the configured command —
 * `supervisorctl signal HUP "pgcat:*"` by default. That command is a *shell* command
 * line (`Process::fromShellCommandline`), and it is the last thing standing between a
 * correct swap and traffic actually moving between pgcat's reader and writer pools. It
 * can fail in four ways that have nothing to do with the file that was just written:
 *
 *   1. the executable does not resolve — not an absolute path, not on this user's PATH;
 *   2. the program name reaches supervisorctl changed, because the shell expanded it:
 *      an unquoted `pgcat:*` is a glob, and a file whose name starts with `pgcat:` in
 *      the working directory turns it into that file's name;
 *   3. supervisorctl runs but does not know the program, so the flip has nothing that can
 *      make pgcat pick the file up;
 *   4. supervisorctl cannot reach supervisord at all (socket missing, permissions).
 *
 * This class answers all four *without taking the step*: it asks for a read-only
 * equivalent of the configured command — `supervisorctl status "<program>"` — and reads
 * supervisor's answer. Nothing here restarts, signals or stops anything; the only command
 * it ever runs is one this class derived with `status` in the verb
 * position, and the caller cannot pass it a different one.
 *
 * Everything is derived from the command the flip will run, so the report cannot drift
 * from the flip: the same token that names the executable is resolved, and the same
 * program argument is asked about.
 */
final class SupervisorStep
{
    /** The name supervisorctl is installed under, however its directory is spelled. */
    private const BINARY = 'supervisorctl';

    /**
     * supervisorctl's verbs. The first argument is one of these, so the program names
     * are what follows it — except for `signal`/`kill`, which name the signal first.
     */
    private const VERBS = [
        'add', 'avail', 'clear', 'fg', 'kill', 'maintail', 'pid', 'reload', 'reread',
        'restart', 'signal', 'start', 'status', 'stop', 'tail', 'update',
    ];

    /** Verbs that take a signal name before the program names. */
    private const SIGNAL_VERBS = ['signal', 'kill'];

    /**
     * Shell operators that end one command and start another: everything after them
     * belongs to a different invocation, which has its own program arguments.
     */
    private const OPERATORS = ['&&', '||', ';', '|', '&'];

    /** Characters a shell treats as a pattern, which is why the name has to be quoted. */
    private const GLOB_CHARS = ['*', '?', '['];

    /**
     * Why a command a flip would run cannot do its job. Each one is a repair, not a
     * diagnosis: they are answered in different places — the configuration, the PATH, the
     * wording of the name, supervisord's own `[program:]` sections, the user — which is why
     * one verdict cannot stand for all of them.
     */
    public const FAULT_EMPTY = 'empty';

    public const FAULT_UNRESOLVED = 'unresolved';

    public const FAULT_UNQUOTED = 'unquoted';

    public const FAULT_UNKNOWN_PROGRAM = 'unknown_program';

    public const FAULT_UNANSWERED = 'unanswered';

    public const FAULT_ERRORED = 'errored';

    /**
     * Not faults: the command drives something other than supervisorctl, or drives it in
     * a way that names no program. There is nothing to ask supervisor, so a flip runs the
     * command as it is — and a caller says so rather than reporting it as checked.
     */
    public const FAULT_NOT_SUPERVISORCTL = 'not_supervisorctl';

    public const FAULT_NO_PROGRAM = 'no_program';

    /**
     * Whether a backslash escapes the character after it. It does in a POSIX shell, which
     * is what `sh -c` runs — but the same command line on Windows goes through cmd.exe,
     * where a backslash is a path separator, and `C:\bin\supervisorctl.exe` has to come
     * back with both characters so it can be resolved.
     */
    private const BACKSLASH_ESCAPES = PHP_OS_FAMILY !== 'Windows';

    /** @var \Closure(string $command): array{0: int, 1: string, 2: string} */
    private \Closure $runner;

    /**
     * @param \Closure(string $command): array{0: int, 1: string, 2: string}|null $runner
     *        how a command is executed; the default runs it through a shell with a
     *        timeout, because that is what a flip does
     * @param int $timeout seconds to wait for an answer before giving up
     */
    public function __construct(?\Closure $runner = null, private readonly int $timeout = 5)
    {
        $this->runner = $runner ?? function (string $command): array {
            try {
                $process = Process::fromShellCommandline($command);
                $process->setTimeout($this->timeout);
                $process->run();

                return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
            } catch (\Throwable $e) {
                return [-1, '', $e->getMessage()];
            }
        };
    }

    /**
     * A command line as its tokens: what the shell would hand each argument, plus
     * whether a pattern character in it reached the command unquoted.
     *
     * Quoted and escaped stretches are marked as such, so a caller can say "this token
     * is the program name, and the shell may rewrite it" without guessing from the raw
     * text. Bash's own simplifications are not attempted — expansion, substitution and
     * command lines with more than one command are out of scope, and `statusCommand()`
     * refuses to guess about those rather than reporting on the wrong invocation.
     *
     * @return list<array{value: string, quoted: bool, glob_exposed: bool}>
     */
    public static function tokens(string $command): array
    {
        $tokens = [];
        $value = '';
        $quoted = false;
        $exposed = false;
        $started = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;

                    continue;
                }

                $value .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $quoted = true;
                $started = true;

                continue;
            }

            if ($char === '\\' && self::BACKSLASH_ESCAPES && $i + 1 < $length) {
                $value .= $command[++$i];
                $quoted = true;
                $started = true;

                continue;
            }

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                if ($started) {
                    $tokens[] = ['value' => $value, 'quoted' => $quoted, 'glob_exposed' => $exposed];
                    $value = '';
                    $quoted = false;
                    $exposed = false;
                    $started = false;
                }

                continue;
            }

            if (in_array($char, self::GLOB_CHARS, true)) {
                $exposed = true;
            }

            $value .= $char;
            $started = true;
        }

        if ($started) {
            $tokens[] = ['value' => $value, 'quoted' => $quoted, 'glob_exposed' => $exposed];
        }

        return $tokens;
    }

    /**
     * The read-only command that asks supervisor about the program the flip's command
     * names: `supervisorctl signal HUP "pgcat:*"` becomes
     * `supervisorctl status "pgcat:*"`.
     *
     * Null when the command does not invoke supervisorctl, or names no program for it
     * (`supervisorctl reread && supervisorctl update` acts on supervisor's own config,
     * where there is nothing to ask about pgcat). The caller says so rather than
     * pretending it checked something.
     *
     * @return array{bin: string, program: string, quoted_program: string, command: string, flip_command: string, glob_exposed: bool}|null
     */
    public static function statusCommand(string $command): ?array
    {
        $tokens = self::tokens($command);
        $index = self::supervisorctlIndex($tokens);

        return $index === null ? null : self::describe($tokens, $index);
    }

    /**
     * Whether the command drives supervisorctl at all — so a caller can tell "this is
     * not a supervisor command" apart from "this is a supervisor command that names no
     * program", which are repaired in different places.
     */
    public static function invokes(string $command): bool
    {
        return self::supervisorctlIndex(self::tokens($command)) !== null;
    }

    /**
     * @param list<array{value: string, quoted: bool, glob_exposed: bool}> $tokens
     */
    private static function supervisorctlIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if (self::isSupervisorctl($token['value'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * What a flip would find when it runs this command — judged without taking the step.
     *
     * One verdict for every surface that needs it. `db:doctor` reports it as a row, the
     * flipper refuses the swap on it, and `--dry-run` prints it as a step, all from this
     * one call: a row and a flip that disagreed about whether a command works would be a
     * worse bug than either being wrong alone.
     *
     * `detail` is the sentence an operator reads — what is wrong and how to repair it, or
     * what the command was found to do — and it is deliberately the *same* sentence in all
     * three places. `usable` is the answer to "may a flip proceed": a command that drives
     * something other than supervisorctl, or drives it without naming a program, is usable
     * with nothing to check (faults `not_supervisorctl` and `no_program`); every other
     * fault is a refusal.
     *
     * The read-only invocation is the only process this starts, and only when there is a
     * program to ask about.
     *
     * @return array{
     *     usable: bool,
     *     fault: string|null,
     *     detail: string,
     *     bin: string,
     *     path: string|null,
     *     searched: list<string>,
     *     status_command: string|null,
     *     flip_command: string,
     *     program: string|null,
     *     quoted_program: string|null,
     *     exit: int|null,
     *     answer: string,
     * }
     */
    public function inspect(string $command): array
    {
        $tokens = self::tokens($command);
        $executable = $tokens[0]['value'] ?? '';

        if ($executable === '') {
            return $this->verdict(
                usable: false,
                fault: self::FAULT_EMPTY,
                detail: 'the configured supervisor command is empty, so a flip would replace the config and then have nothing to tell pgcat with '
                    .'— write swrr.pgcat.restart_command (or reload_command, with use_reload on)',
            );
        }

        $status = self::statusCommand($command);
        $bin = $status['bin'] ?? $executable;
        $resolved = self::locate($bin);
        $common = [
            'bin' => $bin,
            'searched' => $resolved['searched'],
            'flip_command' => $status['flip_command'] ?? $command,
        ];

        if ($resolved['path'] === null) {
            return $this->verdict(
                usable: false,
                fault: self::FAULT_UNRESOLVED,
                detail: sprintf(
                    'a flip would run %s, but %s does not resolve to an executable: tried %s',
                    $command,
                    $bin,
                    self::searchedDescription($bin, $resolved['searched']),
                ),
                context: $common + ['path' => null],
            );
        }

        $context = $common + ['path' => $resolved['path']];

        // Nothing to ask supervisor about, and which of the two reasons it is decides
        // where the operator looks: a command that is not supervisorctl, or one that
        // drives it without naming a program.
        if ($status === null) {
            $drivesSupervisorctl = self::invokes($command);

            return $this->verdict(
                usable: true,
                fault: $drivesSupervisorctl ? self::FAULT_NO_PROGRAM : self::FAULT_NOT_SUPERVISORCTL,
                detail: $drivesSupervisorctl
                    ? sprintf(
                        '%s resolves to %s, and the command names no program for it: reread and update act on supervisor\'s own configuration, so there is nothing to ask about — a flip runs it as it is',
                        $bin,
                        $resolved['path'],
                    )
                    : sprintf(
                        '%s resolves to %s, and the command does not invoke supervisorctl, so there is no program for supervisor to know — a flip runs it as it is',
                        $bin,
                        $resolved['path'],
                    ),
                context: $context,
            );
        }

        // A pattern character that reached the command unquoted is a glob the shell may
        // rewrite: `pgcat:*` matches files, not supervisor programs, so what supervisorctl
        // receives is decided by the working directory at the moment a flip runs.
        if ($status['glob_exposed']) {
            return $this->verdict(
                usable: false,
                fault: self::FAULT_UNQUOTED,
                detail: sprintf(
                    'a flip would run %s, but %s is unquoted: the shell may expand it before supervisorctl sees it. Write %s — the quoted name is the one supervisorctl receives unchanged',
                    $command,
                    $status['program'],
                    $status['flip_command'],
                ),
                context: $context + [
                    'status_command' => $status['command'],
                    'program' => $status['program'],
                    'quoted_program' => $status['quoted_program'],
                ],
            );
        }

        [$exit, $stdout, $stderr] = $this->run($status['command']);
        $answer = trim($stdout) !== '' ? trim($stdout) : trim($stderr);

        $context += [
            'status_command' => $status['command'],
            'program' => $status['program'],
            'quoted_program' => $status['quoted_program'],
            'exit' => $exit,
            'answer' => $answer,
        ];

        $answerLines = self::answerLines($answer);

        if ($exit === -1) {
            return $this->verdict(
                usable: false,
                fault: self::FAULT_UNANSWERED,
                detail: sprintf('%s did not answer in time: %s', $status['command'], $answerLines),
                context: $context,
            );
        }

        if ($exit !== 0 || stripos($answer, 'error') !== false) {
            // supervisorctl's own words for "that program is not mine". A missing socket
            // also says "no such file", which is a different repair — a user, or a
            // running supervisord — so the generic fault below keeps it.
            $unknown = stripos($answer, 'no such group') !== false || stripos($answer, 'no such process') !== false;

            return $this->verdict(
                usable: false,
                fault: $unknown ? self::FAULT_UNKNOWN_PROGRAM : self::FAULT_ERRORED,
                detail: $unknown
                    ? sprintf(
                        'supervisor does not know %s: %s. The name has to match what supervisord runs: a group called pgcat is addressed as "pgcat:*", a single program by its own name',
                        $status['quoted_program'],
                        $answerLines,
                    )
                    : sprintf(
                        '%s could not answer for %s (exit %d): %s',
                        $bin,
                        $status['quoted_program'],
                        $exit,
                        $answerLines,
                    ),
                context: $context,
            );
        }

        return $this->verdict(
            usable: true,
            fault: null,
            detail: sprintf(
                'supervisorctl resolves to %s and knows %s: %s',
                $resolved['path'],
                $status['quoted_program'],
                $answerLines,
            ),
            context: $context,
        );
    }

    /**
     * supervisor's answer as one line: its own lines, whitespace collapsed, with a count
     * when there are more than two. A row and a failure message both have to stay
     * readable, and supervisorctl answers a group with one line per program.
     */
    private static function answerLines(string $answer): string
    {
        $lines = [];

        foreach (preg_split('/\R/', $answer) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/', ' ', $line));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return 'no output';
        }

        $shown = array_slice($lines, 0, 2);
        $rest = count($lines) - count($shown);

        return implode('; ', $shown).($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }

    /**
     * Where an executable was looked for, in a sentence an operator can act on. A command
     * that already named a path is reported as that path; a bare name is reported as the
     * PATH entries it was searched in — a long PATH has hundreds of candidate files, and
     * listing them would say less than the four directories and a count.
     *
     * @param list<string> $searched
     */
    private static function searchedDescription(string $binary, array $searched): string
    {
        if ($searched === []) {
            return 'an empty PATH, with no directory named in the command';
        }

        if (count($searched) === 1 && $searched[0] === $binary) {
            return $binary;
        }

        $shown = array_slice($searched, 0, 4);
        $rest = count($searched) - count($shown);

        return $binary.' on '.implode(', ', $shown)
            .($rest > 0 ? sprintf(' and %d more PATH entries', $rest) : '');
    }

    /**
     * One verdict, with every key present — so a caller can read any field it needs
     * without checking whether this particular branch filled it in.
     *
     * @param array{bin?: string, path?: string|null, searched?: list<string>, status_command?: string|null, flip_command?: string, program?: string|null, quoted_program?: string|null, exit?: int|null, answer?: string} $context
     * @return array{
     *     usable: bool,
     *     fault: string|null,
     *     detail: string,
     *     bin: string,
     *     path: string|null,
     *     searched: list<string>,
     *     status_command: string|null,
     *     flip_command: string,
     *     program: string|null,
     *     quoted_program: string|null,
     *     exit: int|null,
     *     answer: string,
     * }
     */
    private function verdict(bool $usable, ?string $fault, string $detail, array $context = []): array
    {
        return [
            'usable' => $usable,
            'fault' => $fault,
            'detail' => $detail,
            'bin' => $context['bin'] ?? '',
            'path' => $context['path'] ?? null,
            'searched' => $context['searched'] ?? [],
            'status_command' => $context['status_command'] ?? null,
            'flip_command' => $context['flip_command'] ?? '',
            'program' => $context['program'] ?? null,
            'quoted_program' => $context['quoted_program'] ?? null,
            'exit' => $context['exit'] ?? null,
            'answer' => $context['answer'] ?? '',
        ];
    }

    /**
     * Where an executable token would resolve to, and what was looked at.
     *
     * A token with a directory separator in it is a path and is checked as one;
     * anything else is searched for on PATH, with PATHEXT extensions on Windows. What was
     * looked at is returned so a failure can name it — the directories for a PATH search,
     * rather than the hundreds of candidate paths a long PATH turns into, and the path
     * itself when the command already named one.
     *
     * @return array{path: string|null, searched: list<string>}
     */
    public static function locate(string $executable): array
    {
        if ($executable === '') {
            return ['path' => null, 'searched' => []];
        }

        if (str_contains($executable, '/') || str_contains($executable, '\\')) {
            return [
                'path' => self::isRunnable($executable) ? $executable : null,
                'searched' => [$executable],
            ];
        }

        $searched = [];

        foreach (self::pathDirectories() as $directory) {
            $searched[] = $directory;

            $candidates = [$directory . DIRECTORY_SEPARATOR . $executable];

            foreach (self::executableExtensions() as $extension) {
                $candidates[] = $directory . DIRECTORY_SEPARATOR . $executable . $extension;
            }

            foreach ($candidates as $candidate) {
                if (self::isRunnable($candidate)) {
                    return ['path' => $candidate, 'searched' => $searched];
                }
            }
        }

        return ['path' => null, 'searched' => $searched];
    }

    /**
     * Run a command — used by db:doctor for the derived `status` invocation only.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(string $command): array
    {
        return ($this->runner)($command);
    }

    /**
     * The read-only invocation, as one command line: the same binary, the same program
     * argument, `status` in the verb position. The program is quoted here, always,
     * because this command is the one the inspection runs and it has to mean one thing.
     *
     * @param list<array{value: string, quoted: bool, glob_exposed: bool}> $tokens
     * @return array{bin: string, program: string, quoted_program: string, command: string, flip_command: string, glob_exposed: bool}|null
     */
    private static function describe(array $tokens, int $index): ?array
    {
        $bin = $tokens[$index]['value'];
        $arguments = [];

        for ($i = $index + 1; $i < count($tokens); $i++) {
            if (in_array($tokens[$i]['value'], self::OPERATORS, true)) {
                break;
            }

            $arguments[] = $tokens[$i];
        }

        if ($arguments === []) {
            return null;
        }

        $verb = $arguments[0]['value'];
        $programs = $arguments;

        if (in_array($verb, self::VERBS, true)) {
            $programs = array_slice($arguments, 1);

            // `signal`/`kill` name the signal before the program names.
            if (in_array($verb, self::SIGNAL_VERBS, true) && count($programs) > 1) {
                $programs = array_slice($programs, 1);
            }
        }

        if ($programs === []) {
            return null;
        }

        $exposed = false;
        $flipArguments = [];

        foreach ($arguments as $offset => $argument) {
            $isProgram = $offset >= count($arguments) - count($programs);
            $flipArguments[] = $isProgram ? self::quote($argument['value']) : $argument['value'];

            if ($isProgram && $argument['glob_exposed']) {
                $exposed = true;
            }
        }

        $written = array_map(static fn (array $token): string => $token['value'], $programs);
        $quoted = array_map(static fn (string $value): string => self::quote($value), $written);

        return [
            'bin' => $bin,
            'program' => implode(' ', $written),
            'quoted_program' => implode(' ', $quoted),
            'command' => $bin . ' status ' . implode(' ', $quoted),
            'flip_command' => $bin . ' ' . implode(' ', $flipArguments),
            'glob_exposed' => $exposed,
        ];
    }

    private static function isSupervisorctl(string $token): bool
    {
        $name = basename(str_replace('\\', '/', $token));

        return strtolower((string) preg_replace('/\.(exe|bat|cmd)$/i', '', $name)) === self::BINARY;
    }

    /**
     * Quote a token for the command lines this class builds, so a name with a pattern
     * character — or a space — survives the shell unchanged.
     */
    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @return list<string>
     */
    private static function pathDirectories(): array
    {
        $path = (string) (getenv('PATH') ?: '');

        if ($path === '') {
            return [];
        }

        $directories = [];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory);

            if ($directory !== '') {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /**
     * Command files cmd.exe runs but `is_executable()` will not vouch for: on Windows PHP
     * reports a `.bat`/`.cmd` script as not executable, while the shell the flip uses runs
     * it as readily as a `.exe`. The check exists to predict whether the command runs, so
     * a script that can run counts as runnable.
     */
    private const WINDOWS_SCRIPT_EXTENSIONS = ['BAT', 'CMD'];

    /**
     * Extensions Windows resolves a bare command name through. Empty elsewhere, where a
     * file is executable on its own.
     *
     * @return list<string>
     */
    private static function executableExtensions(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [''];
        }

        $extensions = [];

        foreach (explode(';', (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD')) as $extension) {
            $extension = trim($extension);

            if ($extension !== '') {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    private static function isRunnable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        if (is_executable($path)) {
            return true;
        }

        return PHP_OS_FAMILY === 'Windows'
            && in_array(strtoupper(pathinfo($path, PATHINFO_EXTENSION)), self::WINDOWS_SCRIPT_EXTENSIONS, true);
    }
}

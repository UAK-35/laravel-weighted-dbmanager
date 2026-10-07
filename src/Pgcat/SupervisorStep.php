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
 *      make pgcat pick the file up — the one fault whose repair is a *name*, which is why
 *      it is also the one that asks a second question: `supervisorctl status` lists every
 *      program supervisord is running, and the names nearest the configured one are
 *      reported, so an operator sees what the name probably should be instead of being
 *      told only that the one they have is wrong;
 *   4. supervisorctl cannot reach supervisord at all (socket missing, permissions).
 *
 * This class answers all four *without taking the step*: it asks for a read-only
 * equivalent of the configured command — `supervisorctl status "<program>"` — and reads
 * supervisor's answer. Nothing here restarts, signals or stops anything; the only commands
 * it ever runs are ones this class derived with `status` in the verb position: the program
 * it was asked about, and — only once that program has been found missing — the list of the
 * ones supervisord is actually running. The caller cannot pass it a command of its own.
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
     * The states `supervisorctl status` prints per program. It is what tells a program line
     * from the line supervisorctl writes when it cannot reach supervisord at all — the second
     * word of the first is a state, and the second word of `unix:///var/run/supervisor.sock no
     * such file` is `no`.
     */
    private const STATES = [
        'RUNNING', 'STOPPED', 'STARTING', 'STOPPING', 'BACKOFF', 'EXITED', 'FATAL', 'UNKNOWN',
    ];

    /**
     * A supervisor program name, as supervisor spells one: a group address is two of these
     * with a colon between them. Read as a filter rather than as a case to handle, because a
     * candidate list is offered to an operator as something to paste.
     */
    private const NAME = '/^[A-Za-z0-9_.-]+(?::[A-Za-z0-9_.-]+)?$/';

    /** The one state a flip may treat as "leave the daemon alone and just hand it the file". */
    private const RUNNING_STATE = 'RUNNING';

    /**
     * How much a state explains a program that is not up, most first. `supervisorctl status`
     * exits non-zero as soon as *one* named program is not RUNNING, and a group can hold a
     * mix — so the state a refusal, a repair or a report names has to be chosen rather than
     * taken from the first line, and the choice has to be the one an operator would want:
     * a FATAL program is the reason nothing answers, a STARTING one is on its way.
     */
    private const STATE_SEVERITY = [
        'FATAL' => 5,
        'BACKOFF' => 4,
        'EXITED' => 3,
        'STARTING' => 2,
        'STOPPING' => 2,
        'STOPPED' => 1,
        'UNKNOWN' => 0,
    ];

    /**
     * How many of the running programs a refusal names, and how many near misses it offers.
     * A row is one line: the point of the list is to be recognised, not to be complete, and
     * the full answer is in the context for anything that would rather have all of it.
     */
    private const LISTED_PROGRAMS = 4;

    private const NEAR_MISSES = 3;

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
     * The program supervisord was asked about exists, and the answer says it is not RUNNING:
     * `pgcat:pgcat_00  STARTING` while it is inside its `startsecs` window, `FATAL` after
     * supervisord gave up on it, `BACKOFF`/`EXITED` in between.
     *
     * This is *usable*, unlike every other fault above, and it is the whole reason the fault
     * is separate: a daemon that is down is the case where replacing its config is the
     * repair, while a daemon that is up is the case where replacing it needs a reload. What a
     * caller must not do is run a reload or a restart — those act on a program that is
     * already up — so the verdict carries the state and the `start` command that does work.
     */
    public const FAULT_NOT_RUNNING = 'not_running';

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
     * where a backslash is a path separator, and
     * `C:\ProgramData\supervisor\supervisorctl.exe` has to come back with
     * both characters so it can be resolved.
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
     * The read-only command that asks supervisor what it is running *at all*: the same
     * binary, `status` in the verb position, and no program argument —
     * `supervisorctl status`.
     *
     * This is what turns "supervisor does not know `pgcat:*`" into something an operator can
     * act on: supervisor's own list of programs is the only authority on what the name should
     * be, and a name that is one character away from a running program is the repair nobody
     * can see from the refusal alone.
     *
     * Read-only, and derived the same way `statusCommand()` derives its command: nothing here
     * takes a step, and the caller still cannot pass a command of its own — the binary is the
     * token the flip's command named.
     */
    public static function discoveryCommand(string $bin): string
    {
        return $bin.' status';
    }

    /**
     * The programs `supervisorctl status` reports, in the order it reported them.
     *
     * One line per program — `pgcat:pgcat_00   RUNNING   pid 4242, uptime 0:12:34` — so a line
     * counts only when its first word is a name supervisor could have and its second is one of
     * supervisor's states. That is the whole parser, and it is deliberately not "split on
     * whitespace and take the first word": the same command prints
     * `unix:///var/run/supervisor.sock no such file` when it cannot reach supervisord, and
     * `supervisord` is a plausible name for a program in an installation that names one after
     * the daemon.
     *
     * @return list<string>
     */
    public static function runningPrograms(string $answer): array
    {
        $names = [];

        foreach (preg_split('/\R/', $answer) ?: [] as $line) {
            $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) < 2 || preg_match(self::NAME, $words[0]) !== 1) {
                continue;
            }

            if (! in_array(strtoupper($words[1]), self::STATES, true)) {
                continue;
            }

            if (! in_array($words[0], $names, true)) {
                $names[] = $words[0];
            }
        }

        return $names;
    }

    /**
     * The state each program the answer names is in — the other half of the lines
     * `runningPrograms()` reads.
     *
     * That method answers "does supervisor know this name", which is what a refusal over an
     * unknown program needs. A failover needs what the same line says about the state: a
     * program that is STARTING, BACKOFF or FATAL is known *and* down, and that difference
     * decides whether a flip reloads pgcat or starts it. Matching is by name — the program
     * asked about, or, for a group address (`pgcat:*`) and for the bare group name, any program
     * carrying that group.
     *
     * @return array<string, string> program name => state word, upper-cased
     */
    public static function states(string $answer, string $program): array
    {
        $group = str_contains($program, ':') ? (string) strstr($program, ':', true) : $program;
        $states = [];

        foreach (preg_split('/\R/', $answer) ?: [] as $line) {
            $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) < 2 || preg_match(self::NAME, $words[0]) !== 1) {
                continue;
            }

            $state = strtoupper($words[1]);

            if (! in_array($state, self::STATES, true)) {
                continue;
            }

            if ($words[0] !== $program && ! str_starts_with($words[0], $group.':')) {
                continue;
            }

            $states[$words[0]] = $state;
        }

        return $states;
    }

    /**
     * The one state a caller acts on, out of a group's programs: RUNNING as soon as one of them
     * is, otherwise the state that explains why nothing answers — FATAL before BACKOFF before
     * EXITED, because that is the order an operator reads them in. Null when the answer named
     * no program at all, which is a different repair.
     *
     * @param array<string, string> $states
     */
    public static function aggregateState(array $states): ?string
    {
        if ($states === []) {
            return null;
        }

        if (in_array(self::RUNNING_STATE, $states, true)) {
            return self::RUNNING_STATE;
        }

        $chosen = null;
        $rank = -1;

        foreach ($states as $state) {
            $candidate = self::STATE_SEVERITY[$state] ?? -1;

            if ($candidate > $rank) {
                $rank = $candidate;
                $chosen = $state;
            }
        }

        return $chosen;
    }

    /**
     * The command that brings the program a flip's command names back up: the same binary and
     * the same quoted name, with `start` in the verb position.
     *
     * A reload cannot do this. `supervisorctl signal HUP "pgcat:*"` — the command the published
     * config ships — and `supervisorctl restart "pgcat:*"` both act on a program that is
     * already up; supervisor answers `ERROR (not running)` for one that is not. So a flip that
     * finds pgcat FATAL and runs either has replaced a file and changed nothing. Deriving the
     * command rather than configuring a second one keeps the binary, the group name and the
     * quoting identical to the command that was already judged to resolve.
     *
     * Null when the command names no program for supervisorctl: there is nothing to start by
     * name, and the caller says so rather than inventing one.
     */
    public static function startCommand(string $command): ?string
    {
        $status = self::statusCommand($command);

        return $status === null ? null : $status['bin'].' start '.$status['quoted_program'];
    }

    /**
     * Which of the running programs the configured name is probably a misspelling of, closest
     * first — so a refusal can say what to write instead of only what is wrong.
     *
     * The comparison is a cascade of four relations, from the one an operator means to the one
     * they mistyped, and each is checked against both spellings of the configured name (`pgcat`
     * and the group address `pgcat:*` mean the same program set, so either one is a near miss
     * of `pgcat:pgcat_00`):
     *
     *   same       the same name, which happens when supervisor answered the first question badly
     *   group      one is the group the other belongs to — `pgcat` and `pgcat:pgcat_00`
     *   prefix     one starts with the other — `pgcat` and `pgcat-1`
     *   contains   one holds the other — `pgcat` and `lpr-pgcat-a`
     *   close      edit distance within a third of the longer name, at least two
     *
     * Anything else is not a near miss, and an empty answer is the useful one: names with
     * nothing in common are not suggestions, and offering one would send an operator to a
     * program that is already running under a name that was never going to match.
     *
     * @param list<string> $running
     * @return list<string>
     */
    public static function nearMisses(string $program, array $running): array
    {
        $candidates = [];

        foreach ($running as $name) {
            $rank = self::nearness($program, $name);

            if ($rank === null) {
                continue;
            }

            $candidates[] = ['name' => $name, 'rank' => $rank, 'distance' => levenshtein(strtolower($program), strtolower($name))];
        }

        usort($candidates, static function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank']
                ?: $a['distance'] <=> $b['distance']
                ?: strcmp($a['name'], $b['name']);
        });

        return array_slice(array_column($candidates, 'name'), 0, self::NEAR_MISSES);
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
     * with nothing to check (faults `not_supervisorctl` and `no_program`), and so is a program
     * supervisord knows but is not running (`not_running` — there the file swap *is* the
     * repair, and the verdict carries the `start` command to run after it); every other
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
     *     running: list<string>,
     *     near_misses: list<string>,
     *     discovery_command: string|null,
     *     discovery_exit: int|null,
     *     discovery_answer: string,
     *     state: string|null,
     *     states: array<string, string>,
     *     start_command: string|null,
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
            // The program exists and supervisord answered about it — the answer is its state,
            // not an error. `supervisorctl status` exits non-zero the moment one named program
            // is not RUNNING, so this is the ordinary way a FATAL or a STARTING pgcat arrives,
            // and reading it as "supervisorctl could not answer" (which is what this branch
            // used to do) hides the one repair that needs no operator: replace the file pgcat
            // cannot start on and start it. The state is kept, because which one it is decides
            // what a caller says — and the command that goes with it is `start`, never the
            // reload/restart the configured command names.
            $states = self::states($answer, $status['program']);
            $state = self::aggregateState($states);

            if ($state !== null && $state !== self::RUNNING_STATE) {
                return $this->verdict(
                    usable: true,
                    fault: self::FAULT_NOT_RUNNING,
                    detail: sprintf(
                        '%s is %s, so supervisord knows the program and the answer is its state: %s. A flip replaces the config it could not start on and starts it — %s',
                        $status['quoted_program'],
                        $state,
                        $answerLines,
                        self::startCommand($command) ?? 'the program has to be started by hand',
                    ),
                    context: $context + [
                        'state' => $state,
                        'states' => $states,
                        'start_command' => self::startCommand($command),
                    ],
                );
            }

            // supervisorctl's own words for "that program is not mine". A missing socket
            // also says "no such file", which is a different repair — a user, or a
            // running supervisord — so the generic fault below keeps it.
            $unknown = stripos($answer, 'no such group') !== false || stripos($answer, 'no such process') !== false;

            if (! $unknown) {
                return $this->verdict(
                    usable: false,
                    fault: self::FAULT_ERRORED,
                    detail: sprintf(
                        '%s could not answer for %s (exit %d): %s',
                        $bin,
                        $status['quoted_program'],
                        $exit,
                        $answerLines,
                    ),
                    context: $context,
                );
            }

            // The name is the one thing this refusal can actually repair, and supervisor is
            // the only authority on what the name should be: it is running the programs, and
            // `supervisorctl status` lists them. One more read-only command, asked only here
            // — after the program has already been found missing — so a command that works
            // never pays for it.
            $discovered = $this->discover($status['bin'], $status['program']);

            return $this->verdict(
                usable: false,
                fault: self::FAULT_UNKNOWN_PROGRAM,
                detail: sprintf(
                    'supervisor does not know %s: %s. The name has to match what supervisord runs: a group called pgcat is addressed as "pgcat:*", a single program by its own name. %s',
                    $status['quoted_program'],
                    $answerLines,
                    self::discoverySentence($discovered, $status['program']),
                ),
                context: $context + [
                    'running' => $discovered['running'],
                    'near_misses' => $discovered['near'],
                    'discovery_command' => $discovered['command'],
                    'discovery_exit' => $discovered['exit'],
                    'discovery_answer' => $discovered['answer'],
                ],
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
     * What supervisor is running, asked once and read as data.
     *
     * The exit code is recorded and not obeyed: `supervisorctl status` exits non-zero when a
     * program it lists is not in the RUNNING state, which is exactly the installation whose
     * programs an operator most wants named — so the *output* is what is parsed, and an answer
     * that named no program is what makes the sentence say so.
     *
     * @return array{running: list<string>, near: list<string>, command: string, exit: int, answer: string}
     */
    private function discover(string $bin, string $program): array
    {
        $command = self::discoveryCommand($bin);
        [$exit, $stdout, $stderr] = $this->run($command);
        $answer = trim($stdout) !== '' ? trim($stdout) : trim($stderr);

        $running = self::runningPrograms($answer);

        return [
            'running' => $running,
            'near' => self::nearMisses($program, $running),
            'command' => $command,
            'exit' => $exit,
            'answer' => self::answerLines($answer),
        ];
    }

    /**
     * What supervisord is running, as the half of a refusal that an operator can act on: the
     * names it has, and which of them the configured name is probably a typo for.
     *
     * Three answers, and they are different sentences because they send a reader to different
     * places: a list with a near miss (write that name), a list with none (the program is not
     * there under any name like this one — the flip belongs to one of the names above, or the
     * `[program:]` section is missing), and no list at all (supervisor still cannot be asked).
     *
     * @param array{running: list<string>, near: list<string>, command: string, exit: int, answer: string} $discovered
     */
    private static function discoverySentence(array $discovered, string $program): string
    {
        if ($discovered['running'] === []) {
            return sprintf(
                '%s asked supervisor what it is running and named no program (exit %d): %s',
                $discovered['command'],
                $discovered['exit'],
                $discovered['answer'],
            );
        }

        $listed = self::programList($discovered['running']);

        if ($discovered['near'] === []) {
            return sprintf(
                '%s says supervisord runs %d program(s): %s — none of them is close to "%s", so the name to use is the one above that this flip belongs to, or the program has to be added to supervisord',
                $discovered['command'],
                count($discovered['running']),
                $listed,
                $program,
            );
        }

        $one = count($discovered['near']) === 1;

        return sprintf(
            '%s says supervisord runs %d program(s): %s — %s %s, %s%s',
            $discovered['command'],
            count($discovered['running']),
            $listed,
            $one ? 'the closest is' : 'the closest are',
            '"'.implode('", "', $discovered['near']).'"',
            $one ? 'which is the name to write' : 'which are the names to write',
            self::groupAdvice($discovered['near'][0], $program),
        );
    }

    /**
     * The group a near miss belongs to, when that is worth saying: `pgcat:pgcat_00` is also
     * addressable as `pgcat:*`, and that is the form the command probably wants when the flip
     * restarts a whole pool rather than one program.
     *
     * Empty when the name is not a group address, and empty when the command *already* addresses
     * that group — "did you mean `pgcat:*`" is not advice to a command that said exactly that.
     * A command that named the group as a bare program does get it, and that is the case the
     * line exists for: supervisorctl reads `pgcat` as a program name, so a group is only
     * addressable with the wildcard, and nothing else in the row would say so.
     */
    private static function groupAdvice(string $name, string $program): string
    {
        $group = self::groupOf($name);

        if ($group === null) {
            return '';
        }

        if (self::isGroupWildcard($program) && strtolower($group) === strtolower(self::programName($program))) {
            return '';
        }

        return sprintf(', or the whole group "%s:*"', $group);
    }

    /**
     * Whether a configured program was written as a group wildcard — `pgcat:*` — which is the
     * one spelling that already addresses a whole group.
     */
    private static function isGroupWildcard(string $program): bool
    {
        return str_ends_with(trim($program), ':*');
    }

    /**
     * The group in a `group:program` name, or null when the name is not one.
     */
    private static function groupOf(string $name): ?string
    {
        $colon = strpos($name, ':');

        return $colon === false || $colon === 0 ? null : substr($name, 0, $colon);
    }

    /**
     * How near a running program's name is to the configured one: the rank of the closest
     * relation that holds, or null when nothing relates them. Lower is nearer.
     *
     * The relations are checked in the order an operator would think of them, so a name that
     * is a prefix can never be reported as one that merely contains it.
     */
    private static function nearness(string $program, string $name): ?int
    {
        $written = strtolower(self::programName($program));
        $running = strtolower($name);

        if ($written === '' || $running === '') {
            return null;
        }

        if ($written === $running) {
            return 0;
        }

        $group = self::groupOf($name);

        if ($group !== null && strtolower($group) === $written) {
            return 1;   // the group the program belongs to
        }

        if (str_starts_with($running, $written) || str_starts_with($written, $running)) {
            return 2;
        }

        if (str_contains($running, $written) || str_contains($written, $running)) {
            return 3;
        }

        return levenshtein($written, $running) <= max(2, intdiv(max(strlen($written), strlen($running)), 3))
            ? 4
            : null;
    }

    /**
     * The name a configured program asks about, without the group wildcard: `pgcat:*` and
     * `pgcat:` both mean the programs in group `pgcat`, and either spelling has to compare
     * equal to a running `pgcat:pgcat_00` for the near miss to be found.
     */
    private static function programName(string $program): string
    {
        $name = rtrim(trim($program), '*');

        return rtrim($name, ':');
    }

    /**
     * The names as one line, with a count when there are more than a row should carry. The
     * full list is in the verdict's `running` for anything that wants all of it.
     *
     * @param list<string> $names
     */
    private static function programList(array $names): string
    {
        $shown = array_slice($names, 0, self::LISTED_PROGRAMS);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? sprintf(' and %d more', $rest) : '');
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
     * The three keys after `discovery_answer` are the ones the supervisor-fault branch adds: the
     * state supervisord reported, the states of every program it named, and the `start` command
     * that goes with a program which is not RUNNING. They are in this shape as well as in
     * `inspect()`'s because `verdict()` is what builds the array the caller declares — a key this
     * shape omitted would be one the return type said was absent while the code wrote it.
     *
     * @param array{bin?: string, path?: string|null, searched?: list<string>, status_command?: string|null, flip_command?: string, program?: string|null, quoted_program?: string|null, exit?: int|null, answer?: string, running?: list<string>, near_misses?: list<string>, discovery_command?: string|null, discovery_exit?: int|null, discovery_answer?: string, state?: string|null, states?: array<string, string>, start_command?: string|null} $context
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
     *     running: list<string>,
     *     near_misses: list<string>,
     *     discovery_command: string|null,
     *     discovery_exit: int|null,
     *     discovery_answer: string,
     *     state: string|null,
     *     states: array<string, string>,
     *     start_command: string|null,
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
            // Filled in only by the discovery below, which runs when supervisor does not know
            // the program — the one fault whose repair the operator cannot derive from the
            // message, because it is a fact about supervisord's own configuration.
            'running' => $context['running'] ?? [],
            'near_misses' => $context['near_misses'] ?? [],
            'discovery_command' => $context['discovery_command'] ?? null,
            'discovery_exit' => $context['discovery_exit'] ?? null,
            'discovery_answer' => $context['discovery_answer'] ?? '',
            // Filled in only when supervisord answered about a program that is not RUNNING:
            // the state is what a caller says (and what the repair reports), and the command
            // is the `start` that goes with it instead of the configured reload/restart.
            'state' => $context['state'] ?? null,
            'states' => $context['states'] ?? [],
            'start_command' => $context['start_command'] ?? null,
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

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Uak35\WeightedDbManager\Tests\Support\PushingDoc;

/**
 * The two claims in PUSHING.md that can be checked without pushing anything: the remote it
 * sends you to is the one `composer.json` publishes, and the commands it puts in your
 * clipboard are ones the shell it names can actually run.
 *
 * The file is honest about the third thing a runbook can be wrong about — its own
 * verification — and says in its first table that the push itself was never exercised. So
 * these are the failures it *can* be held to: a wrong remote (a push that succeeds and
 * publishes nothing, where the only other record of the URL is the homepage a consumer
 * reads) and a command that is not there (the one a reader meets at exactly the moment
 * nobody wants to debug it).
 *
 * Both are asked of the thing that decides the answer rather than restated from it. The
 * commands are resolved in the shell each fence names — `powershell` fences say PowerShell
 * 7 and `bash` fences say bash — so the answer is also that the tool can be reached from
 * there, and the repository is read out of `composer.json` itself. Each tool is asked
 * once, not once per command.
 */
final class PushingDocTest extends TestCase
{
    public function test_the_remote_it_pushes_to_is_the_homepage_composer_declares(): void
    {
        $homepage = PushingDoc::repository(PushingDoc::declaredHomepage());

        $this->assertNotNull(
            $homepage,
            sprintf('composer.json\'s homepage [%s] is not a GitHub repository URL to compare against.', PushingDoc::declaredHomepage()),
        );

        $remotes = array_values(array_filter(
            PushingDoc::urls(),
            static fn (array $url): bool => PushingDoc::repository($url['url']) !== null,
        ));

        $this->assertNotSame(
            [],
            $remotes,
            'PUSHING.md names no GitHub repository URL, so it never says where a push goes.',
        );

        foreach ($remotes as $remote) {
            $this->assertSame(
                $homepage,
                PushingDoc::repository($remote['url']),
                sprintf(
                    'PUSHING.md line %d points a push at %s, but composer.json publishes %s.',
                    $remote['source'],
                    $remote['url'],
                    PushingDoc::declaredHomepage(),
                ),
            );
        }
    }

    public function test_every_command_it_quotes_can_run_in_the_shell_that_fence_names(): void
    {
        $commands = PushingDoc::commands();

        $this->assertNotSame([], $commands, 'PUSHING.md quotes no commands at all.');

        $byShell = [];

        foreach ($commands as $command) {
            $byShell[$command['tool']][] = $command;
        }

        $failures = [];
        $unverifiable = [];

        foreach ($byShell as $shell => $shellCommands) {
            if (!self::runnable($shell)) {
                $unverifiable[] = sprintf('%s (%s)', self::shellName($shell), $shell);

                continue;
            }

            $checked = self::check($shell, $shellCommands);

            $failures = array_merge($failures, $checked['failures']);
            $unverifiable = array_merge($unverifiable, $checked['unverifiable']);
        }

        $this->assertSame(
            [],
            $failures,
            "PUSHING.md quotes commands the shell it names cannot run:\n  - ".implode("\n  - ", $failures),
        );

        // The assertions above have run by now, so a machine that is missing a shell loses
        // the checks that needed it rather than the checks that did not.
        if ($unverifiable !== []) {
            $this->markTestSkipped(sprintf(
                'PUSHING.md quotes commands this machine cannot be asked about: %s. The commands that could be checked passed.',
                implode(', ', $unverifiable),
            ));
        }
    }

    /**
     * The third failure a runbook can have, and the one a warning about a scope is most
     * exposed to: naming a file that is not there. The `workflow` section quotes the path
     * GitHub's refusal names, so a workflow renamed in this package would leave the file
     * warning about one that no longer exists — which reads as a rule that no longer applies,
     * exactly when a reader is deciding whether to trust the rest of it.
     */
    public function test_every_workflow_file_it_names_is_one_this_package_has(): void
    {
        $workflows = PushingDoc::workflowPaths();

        $this->assertNotSame(
            [],
            $workflows,
            'PUSHING.md names no workflow file, so the section about the scope has nothing concrete to be wrong about.',
        );

        foreach ($workflows as $workflow) {
            $this->assertFileExists(
                PushingDoc::root().'/'.$workflow['path'],
                sprintf(
                    'PUSHING.md line %d names %s, which is not a file in this package.',
                    $workflow['source'],
                    $workflow['path'],
                ),
            );
        }
    }

    /**
     * Every quoted command the shell it names disagrees with: a program the shell does not
     * have, a subcommand the tool it names does not have, or a `php` script that is not a
     * file in this package.
     *
     * @param list<array{tool: string, program: string, argument: list<string>, line: string, source: int}> $commands
     * @return array{failures: list<string>, unverifiable: list<string>}
     */
    private static function check(string $shell, array $commands): array
    {
        $byProgram = [];

        foreach ($commands as $command) {
            $byProgram[$command['program']][] = $command;
        }

        $failures = [];
        $unverifiable = [];

        foreach (self::missingPrograms($shell, $commands) as $program) {
            $failures[] = sprintf('`%s` is not a command %s has', $program, self::shellName($shell));
        }

        if (isset($byProgram['git'])) {
            $known = self::lines(self::stdout($shell, 'git --list-cmds=main,others,alias'));

            if ($known === []) {
                $unverifiable[] = 'git\'s own command list (`git --list-cmds`)';
            } else {
                foreach ($byProgram['git'] as $command) {
                    $failures = array_merge($failures, self::missingCommand('git', $command, $known));
                }
            }
        }

        if (isset($byProgram['composer'])) {
            // `composer list --raw` is one command per line — the name, padding, then its
            // summary — so the name is the line's first word.
            $known = array_map(
                static fn (string $line): string => (string) strtok($line, ' '),
                self::lines(self::stdout($shell, 'composer list --raw')),
            );

            if ($known === []) {
                $unverifiable[] = 'composer\'s own command list (`composer list`)';
            } else {
                foreach ($byProgram['composer'] as $command) {
                    $failures = array_merge($failures, self::missingCommand('composer', $command, $known));
                }
            }
        }

        if (isset($byProgram['gh'])) {
            $failures = array_merge($failures, self::missingGhCommands($shell, $byProgram['gh']));
        }

        if (isset($byProgram['php'])) {
            foreach ($byProgram['php'] as $command) {
                $failures = array_merge($failures, self::missingPhpScript($command));
            }
        }

        return ['failures' => $failures, 'unverifiable' => $unverifiable];
    }

    /**
     * The programs the shell cannot resolve, asked of the shell itself and in one process
     * rather than one per command.
     *
     * `Get-Command` answers aliases and cmdlets as well as executables, which is what makes
     * `cd` a command PowerShell has; `command -v` is bash's own answer to the same question.
     *
     * @param list<array{tool: string, program: string, argument: list<string>, line: string, source: int}> $commands
     * @return list<string>
     */
    private static function missingPrograms(string $shell, array $commands): array
    {
        $programs = array_values(array_unique(array_column($commands, 'program')));

        $script = $shell === 'pwsh'
            ? 'foreach ($name in ($env:LPR_PROGRAMS -split [char]10)) { if ($name -ne "" -and -not (Get-Command -Name $name -ErrorAction SilentlyContinue)) { $name } }'
            : 'for name in $LPR_PROGRAMS; do command -v "$name" >/dev/null 2>&1 || printf "%s\n" "$name"; done';

        return self::lines(self::stdout($shell, $script, ['LPR_PROGRAMS' => implode("\n", $programs)]));
    }

    /**
     * One command against a tool's published command list.
     *
     * @param list<array{tool: string, program: string, argument: list<string>, line: string, source: int}> $command
     * @param list<string>                                                                                   $known
     * @return list<string>
     */
    private static function missingCommand(string $tool, array $command, array $known): array
    {
        $words = PushingDoc::subcommand($command['program'], $command['argument']);

        if ($words === [] || in_array(implode(' ', $words), $known, true)) {
            return [];
        }

        return [sprintf(
            '`%s %s` is not a %s command (PUSHING.md line %d)',
            $tool,
            implode(' ', $words),
            $tool,
            $command['source'],
        )];
    }

    /**
     * `gh` publishes no list to read — `gh help` renders a page rather than printing names —
     * so each command is asked the way a reader would: run it with `--help`, which answers
     * without a network and exits non-zero when there is no such command.
     *
     * @param list<array{tool: string, program: string, argument: list<string>, line: string, source: int}> $commands
     * @return list<string>
     */
    private static function missingGhCommands(string $shell, array $commands): array
    {
        $sources = [];

        foreach ($commands as $command) {
            $words = PushingDoc::subcommand('gh', $command['argument']);

            if ($words !== []) {
                $sources[implode(' ', $words)] = $command['source'];
            }
        }

        if ($sources === []) {
            return [];
        }

        $script = $shell === 'pwsh'
            ? 'foreach ($line in ($env:LPR_CHAINS -split [char]10)) { if ($line -ne "") { $words = $line -split " "; & gh @words --help *> $null; if ($LASTEXITCODE -ne 0) { $line } } }'
            : 'while IFS= read -r line; do [ -z "$line" ] && continue; gh $line --help >/dev/null 2>&1 || printf "%s\n" "$line"; done <<< "$LPR_CHAINS"';

        $missing = [];

        foreach (self::lines(self::stdout($shell, $script, ['LPR_CHAINS' => implode("\n", array_keys($sources))])) as $chain) {
            $missing[] = sprintf(
                '`gh %s` is not a gh command (PUSHING.md line %d)',
                $chain,
                $sources[$chain] ?? 0,
            );
        }

        return $missing;
    }

    /**
     * A `php` line is a script, so the one thing to check is that the script is a file in
     * this package — the only claim here about the tree rather than about the machine.
     *
     * @param array{tool: string, program: string, argument: list<string>, line: string, source: int} $command
     * @return list<string>
     */
    private static function missingPhpScript(array $command): array
    {
        $words = PushingDoc::subcommand('php', $command['argument']);

        if ($words === [] || is_file(PushingDoc::root().'/'.$words[0])) {
            return [];
        }

        return [sprintf(
            '`php %s` names a script that is not in this package (PUSHING.md line %d)',
            implode(' ', $words),
            $command['source'],
        )];
    }

    /**
     * Whether this machine has the shell at all.
     *
     * A shell that has to be asked is a shell that may not be there, and *not installed* is
     * a different answer from *the command is missing*: one is a fact about this machine,
     * the other about the file. The exit code is checked as well as the start, because a
     * missing binary can arrive either way.
     */
    private static function runnable(string $shell): bool
    {
        try {
            return self::process($shell, 'exit 0')->getExitCode() === 0;
        } catch (ProcessRuntimeException) {
            return false;
        }
    }

    /**
     * One shell invocation's standard output. `stdout()`, because `output()` is PHPUnit's
     * own expectation about what a test writes.
     *
     * @param array<string, string> $environment
     */
    private static function stdout(string $shell, string $script, array $environment = []): string
    {
        return self::process($shell, $script, $environment)->getOutput();
    }

    /**
     * The shell, with its inputs in the environment rather than interpolated into the
     * script — so no quoting rule has to be got right, and nothing the file says can become
     * syntax.
     *
     * @param array<string, string> $environment
     *
     * @throws ProcessRuntimeException when the shell is not on this machine
     */
    private static function process(string $shell, string $script, array $environment = []): Process
    {
        $process = new Process($shell === 'pwsh'
            ? ['pwsh', '-NoProfile', '-Command', $script]
            : ['bash', '-c', $script]);

        $process->setTimeout(60);
        $process->run(null, $environment);

        return $process;
    }

    /**
     * The shell's output as the non-empty lines it was printed as, trimmed.
     *
     * @return list<string>
     */
    private static function lines(string $output): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * The shell's name as the file's fences spell it, so a refusal sends the reader to the
     * fence rather than to the binary.
     */
    private static function shellName(string $shell): string
    {
        return $shell === 'pwsh' ? 'PowerShell 7' : 'bash';
    }
}

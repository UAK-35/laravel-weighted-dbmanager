<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;

/**
 * The flip's last step is a shell command line, and `db:doctor` has to judge it without
 * running it. These tests pin what "judge" means: which token is the binary, which token
 * is the program name, whether a pattern character in it reached the command unquoted,
 * and what the read-only equivalent of the whole command is.
 *
 * The quoting cases are the ones that matter, because that is where the flip and the
 * command line disagree: `pgcat:*` is a name supervisor knows and a glob the shell may
 * rewrite, and only the quotes decide which of the two supervisorctl receives.
 *
 * An unknown program is the one fault whose repair is a *name*, so it is also the one that
 * asks a second question — what supervisord is running at all — and the tests below pin the
 * answer twice over: how a `status` line is read, and which running names are near enough to
 * the configured one to be worth offering.
 */
final class SupervisorStepTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private string $originalPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        if ($this->originalPath !== '') {
            putenv('PATH=' . $this->originalPath);
        }

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @chmod($file, 0o666);
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // tokens()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_quoted_name_is_one_token_with_its_quotes_stripped(): void
    {
        $tokens = SupervisorStep::tokens('supervisorctl signal HUP "pgcat:*"');

        $this->assertSame(
            [
                ['value' => 'supervisorctl', 'quoted' => false, 'glob_exposed' => false],
                ['value' => 'signal', 'quoted' => false, 'glob_exposed' => false],
                ['value' => 'HUP', 'quoted' => false, 'glob_exposed' => false],
                ['value' => 'pgcat:*', 'quoted' => true, 'glob_exposed' => false],
            ],
            $tokens,
        );
    }

    public function test_an_unquoted_pattern_character_is_marked_as_reaching_the_command(): void
    {
        $tokens = SupervisorStep::tokens('supervisorctl restart pgcat:*');

        $this->assertSame('pgcat:*', $tokens[2]['value']);
        $this->assertFalse($tokens[2]['quoted']);
        $this->assertTrue($tokens[2]['glob_exposed'], 'the shell may expand this before supervisorctl sees it');
    }

    public function test_single_quotes_are_quotes_too(): void
    {
        $tokens = SupervisorStep::tokens("supervisorctl restart 'pgcat:*'");

        $this->assertSame('pgcat:*', $tokens[2]['value']);
        $this->assertTrue($tokens[2]['quoted']);
        $this->assertFalse($tokens[2]['glob_exposed']);
    }

    public function test_shell_operators_are_tokens_so_the_next_command_is_not_read_as_an_argument(): void
    {
        $values = array_column(SupervisorStep::tokens('supervisorctl reread && supervisorctl update'), 'value');

        $this->assertSame(['supervisorctl', 'reread', '&&', 'supervisorctl', 'update'], $values);
    }

    public function test_a_backslash_escapes_the_next_character_where_the_shell_would(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('cmd.exe does not read a backslash as an escape.');
        }

        $tokens = SupervisorStep::tokens('supervisorctl restart pgcat:\\*');

        $this->assertSame('pgcat:*', $tokens[2]['value']);
        $this->assertFalse($tokens[2]['glob_exposed'], 'an escaped pattern is a literal to the shell');
    }

    public function test_a_windows_path_keeps_its_backslashes(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('cmd.exe paths are only spelled this way on Windows.');
        }

        $tokens = SupervisorStep::tokens('C:\\ProgramData\\supervisor\\supervisorctl.exe restart "pgcat:*"');

        $this->assertSame('C:\\ProgramData\\supervisor\\supervisorctl.exe', $tokens[0]['value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // statusCommand() / invokes()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_read_only_command_keeps_the_binary_and_gets_a_status_verb(): void
    {
        $status = SupervisorStep::statusCommand('supervisorctl signal HUP "pgcat:*"');

        $this->assertNotNull($status);
        $this->assertSame('supervisorctl', $status['bin']);
        $this->assertSame('pgcat:*', $status['program']);
        $this->assertSame('"pgcat:*"', $status['quoted_program']);
        $this->assertSame('supervisorctl status "pgcat:*"', $status['command']);
        $this->assertSame('supervisorctl signal HUP "pgcat:*"', $status['flip_command']);
        $this->assertFalse($status['glob_exposed']);
    }

    public function test_a_path_qualified_binary_stays_path_qualified(): void
    {
        $status = SupervisorStep::statusCommand('/usr/bin/supervisorctl restart "pgcat:*"');

        $this->assertNotNull($status);
        $this->assertSame('/usr/bin/supervisorctl', $status['bin']);
        $this->assertSame('/usr/bin/supervisorctl status "pgcat:*"', $status['command']);
    }

    public function test_the_flip_command_is_shown_with_the_program_quoted(): void
    {
        // The fix a failed inspection prints: same command, name quoted, so what the
        // operator pastes is what supervisorctl receives.
        $status = SupervisorStep::statusCommand('/usr/bin/supervisorctl restart pgcat:*');

        $this->assertNotNull($status);
        $this->assertTrue($status['glob_exposed']);
        $this->assertSame('/usr/bin/supervisorctl restart "pgcat:*"', $status['flip_command']);
    }

    public function test_a_command_that_is_not_supervisorctl_has_nothing_to_ask_about(): void
    {
        $this->assertNull(SupervisorStep::statusCommand('systemctl restart pgcat'));
        $this->assertFalse(SupervisorStep::invokes('systemctl restart pgcat'));
    }

    public function test_supervisorctl_without_a_program_has_nothing_to_ask_about(): void
    {
        // reread/update act on supervisor's own configuration: the command drives
        // supervisorctl, and there is still no program name in it.
        $this->assertTrue(SupervisorStep::invokes('supervisorctl reread && supervisorctl update'));
        $this->assertNull(SupervisorStep::statusCommand('supervisorctl reread && supervisorctl update'));
        $this->assertNull(SupervisorStep::statusCommand('supervisorctl'));
    }

    public function test_the_arguments_stop_at_the_next_command(): void
    {
        $status = SupervisorStep::statusCommand('supervisorctl restart "pgcat:*" && echo flipped');

        $this->assertNotNull($status);
        $this->assertSame('pgcat:*', $status['program']);
        $this->assertSame('supervisorctl status "pgcat:*"', $status['command']);
    }

    public function test_a_suffix_of_program_names_is_kept_as_written(): void
    {
        // A supervisor group wildcard can name several programs; asking about all of
        // them is what a flip would restart, so all of them are asked about.
        $status = SupervisorStep::statusCommand('supervisorctl restart "pgcat:*" "other:*"');

        $this->assertNotNull($status);
        $this->assertSame('pgcat:* other:*', $status['program']);
        $this->assertSame('"pgcat:*" "other:*"', $status['quoted_program']);
        $this->assertSame('supervisorctl status "pgcat:*" "other:*"', $status['command']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // locate()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_path_that_is_not_there_does_not_resolve(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/missing/supervisorctl';

        $located = SupervisorStep::locate($path);

        $this->assertNull($located['path']);
        $this->assertSame([$path], $located['searched']);
    }

    public function test_a_path_that_is_a_file_resolves(): void
    {
        $binary = $this->fakeBinary($this->tempDir());

        $located = SupervisorStep::locate($binary);

        $this->assertSame($binary, $located['path']);
    }

    public function test_a_bare_name_is_looked_for_on_the_path(): void
    {
        $dir = $this->tempDir();
        $binary = $this->fakeBinary($dir);

        putenv('PATH=' . $dir . PATH_SEPARATOR . $this->originalPath);

        $located = SupervisorStep::locate(basename($binary));

        // The directory the PATH entry named, joined the way this platform joins it.
        $this->assertNotNull($located['path']);
        $this->assertSame(realpath($binary), realpath($located['path']));
    }

    public function test_a_name_nothing_answers_to_reports_the_directories_it_searched(): void
    {
        // The directories, not the candidate files: a long PATH turns one bare name into
        // hundreds of candidate paths, and a row that listed them would say less.
        $dir = $this->tempDir();
        putenv('PATH=' . $dir);

        $located = SupervisorStep::locate('a-binary-that-does-not-exist');

        $this->assertNull($located['path']);
        $this->assertSame([$dir], $located['searched']);
    }

    public function test_a_windows_script_counts_as_runnable(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Only cmd.exe needs a script extension to be runnable.');
        }

        // `is_executable()` refuses a .cmd script on Windows while cmd.exe — the shell a
        // flip's command line goes through — runs it as readily as an .exe.
        $dir = $this->tempDir();
        $script = $dir . '/supervisorctl.cmd';
        file_put_contents($script, "@echo off\r\n");

        $this->assertFalse(is_executable($script));
        $this->assertSame($script, SupervisorStep::locate($script)['path']);
    }

    public function test_a_file_without_the_executable_bit_does_not_resolve(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows has no executable bit to test.');
        }

        $dir = $this->tempDir();
        $path = $dir . '/supervisorctl';
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, 0o644);

        $this->assertNull(SupervisorStep::locate($path)['path']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // run()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_runner_is_handed_the_command_and_nothing_else(): void
    {
        $ran = [];
        $step = new SupervisorStep(function (string $command) use (&$ran): array {
            $ran[] = $command;

            return [0, 'ok', ''];
        });

        $this->assertSame([0, 'ok', ''], $step->run('supervisorctl status "pgcat:*"'));
        $this->assertSame(['supervisorctl status "pgcat:*"'], $ran);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // inspect() — one verdict for the row, the flip and the rehearsal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The verdict a flip refuses on, an empty one included: a command that is not there is
     * not a command that does nothing.
     */
    public function test_inspect_refuses_an_empty_command(): void
    {
        $ran = [];
        $verdict = (new SupervisorStep($this->recorder($ran)))->inspect('');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_EMPTY, $verdict['fault']);
        $this->assertStringContainsString('the configured supervisor command is empty', $verdict['detail']);
        $this->assertStringContainsString('write swrr.pgcat.restart_command', $verdict['detail']);
        $this->assertSame([], $ran, 'there was nothing to run');
    }

    public function test_inspect_refuses_a_binary_that_does_not_resolve_and_says_where_it_looked(): void
    {
        $missing = $this->tempDir() . '/missing/supervisorctl';

        $verdict = (new SupervisorStep())->inspect($missing . ' restart "pgcat:*"');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_UNRESOLVED, $verdict['fault']);
        $this->assertSame($missing, $verdict['bin']);
        $this->assertNull($verdict['path']);
        $this->assertNull($verdict['exit'], 'nothing was asked');
        $this->assertStringContainsString('does not resolve to an executable: tried ' . $missing, $verdict['detail']);
    }

    /**
     * The quoting fault is refused *without asking anything*: the name the flip would send
     * is not the name this command means, and the repair is in the configuration.
     */
    public function test_inspect_refuses_an_unquoted_program_and_hands_over_the_quoted_form(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->recorder($ran)))
            ->inspect($binary . ' restart pgcat:*');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_UNQUOTED, $verdict['fault']);
        $this->assertSame('pgcat:*', $verdict['program']);
        $this->assertSame('"pgcat:*"', $verdict['quoted_program']);
        $this->assertSame($binary . ' restart "pgcat:*"', $verdict['flip_command']);
        $this->assertStringContainsString('is unquoted', $verdict['detail']);
        $this->assertStringContainsString('Write ' . $binary . ' restart "pgcat:*"', $verdict['detail']);
        $this->assertSame([], $ran, 'an unquoted name is not worth asking about');
    }

    public function test_inspect_asks_the_read_only_question_and_quotes_supervisors_answer(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->recorder($ran, "pgcat:pgcat_00  RUNNING  pid 1\npgcat:pgcat_01  STOPPED")))
            ->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertTrue($verdict['usable']);
        $this->assertNull($verdict['fault']);
        $this->assertSame(0, $verdict['exit']);
        $this->assertSame([$binary . ' status "pgcat:*"'], $ran);
        $this->assertSame($binary . ' signal HUP "pgcat:*"', $verdict['flip_command'], 'the report is about the flip\'s command, not the check');
        $this->assertStringContainsString('supervisorctl resolves to ' . $binary, $verdict['detail']);
        $this->assertStringContainsString('and knows "pgcat:*"', $verdict['detail']);
        $this->assertStringContainsString('pgcat:pgcat_00 RUNNING pid 1; pgcat:pgcat_01 STOPPED', $verdict['detail'], 'the answer is collapsed to one line');
    }

    public function test_inspect_reports_a_program_supervisor_does_not_know(): void
    {
        $binary = $this->fakeBinary($this->tempDir());

        $ran = [];
        $verdict = (new SupervisorStep($this->recorder($ran, '', 'pgcat:*: ERROR (no such group)', 2)))
            ->inspect($binary . ' restart "pgcat:*"');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_UNKNOWN_PROGRAM, $verdict['fault']);
        $this->assertStringContainsString('supervisor does not know "pgcat:*"', $verdict['detail']);
        $this->assertStringContainsString('a group called pgcat is addressed as "pgcat:*"', $verdict['detail']);

        // The refusal does not stop at the name it was given: the same answer is asked again
        // without a program, and an answer that names none of them says so rather than
        // inventing a candidate.
        $this->assertSame([$binary . ' status "pgcat:*"', $binary . ' status'], $ran);
        $this->assertSame([], $verdict['running'], 'supervisor named no program it is running');
        $this->assertSame([], $verdict['near_misses']);
        $this->assertStringContainsString('asked supervisor what it is running and named no program (exit 2)', $verdict['detail']);
    }

    /**
     * A supervisord socket that is not there and a program that is not there are repaired in
     * different places, so they must not be the same fault: one is the user or the daemon,
     * the other is the name.
     */
    public function test_inspect_separates_a_missing_socket_from_a_missing_program(): void
    {
        $binary = $this->fakeBinary($this->tempDir());

        $ran = [];
        $verdict = (new SupervisorStep($this->recorder($ran, '', 'unix:///var/run/supervisor.sock no such file', 2)))
            ->inspect($binary . ' restart "pgcat:*"');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_ERRORED, $verdict['fault']);
        $this->assertStringContainsString('could not answer for "pgcat:*" (exit 2)', $verdict['detail']);
        $this->assertStringContainsString('no such file', $verdict['detail']);
    }

    public function test_inspect_refuses_a_supervisorctl_that_never_answered(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep(function (string $command) use (&$ran): array {
            $ran[] = $command;

            return [-1, '', 'timed out'];
        }))->inspect($binary . ' restart "pgcat:*"');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_UNANSWERED, $verdict['fault']);
        $this->assertSame(-1, $verdict['exit']);
        $this->assertStringContainsString('did not answer in time', $verdict['detail']);
        $this->assertSame([$binary . ' status "pgcat:*"'], $ran);
    }

    /**
     * A command that drives something else, or drives supervisorctl without naming a
     * program, is usable with nothing checked — and the fault says which of the two, because
     * that is what decides where an operator would look if it ever failed.
     */
    public function test_inspect_treats_a_foreign_command_as_usable_with_nothing_to_ask(): void
    {
        $dir = $this->tempDir();
        $binary = $this->fakeBinary($dir);

        // A command named something else entirely, but one that resolves: a flip has no
        // reason to refuse it, and nothing to ask supervisor about.
        $foreign = $dir . '/' . (PHP_OS_FAMILY === 'Windows' ? 'pgcat-reload.exe' : 'pgcat-reload');
        file_put_contents($foreign, "# stands in for a reloader\n");
        @chmod($foreign, 0o755);

        $ran = [];
        $step = new SupervisorStep($this->recorder($ran));

        $notSupervisorctl = $step->inspect($foreign . ' restart pgcat');
        $this->assertTrue($notSupervisorctl['usable']);
        $this->assertSame(SupervisorStep::FAULT_NOT_SUPERVISORCTL, $notSupervisorctl['fault']);
        $this->assertStringContainsString('does not invoke supervisorctl', $notSupervisorctl['detail']);

        $noProgram = $step->inspect($binary . ' reread && ' . $binary . ' update');
        $this->assertTrue($noProgram['usable']);
        $this->assertSame(SupervisorStep::FAULT_NO_PROGRAM, $noProgram['fault']);
        $this->assertStringContainsString('names no program', $noProgram['detail']);

        $this->assertSame([], $ran, 'neither of them is worth running anything for');
    }

    /**
     * Every key is present in every verdict, so a caller (or a template) can read one
     * without asking whether this branch filled it in. The shape is the contract.
     */
    public function test_inspect_returns_the_same_keys_whatever_it_found(): void
    {
        $ran = [];
        $empty = (new SupervisorStep())->inspect('');
        $answered = (new SupervisorStep($this->recorder($ran)))->inspect($this->fakeBinary($this->tempDir()) . ' status "pgcat:*"');

        $keys = [
            'usable', 'fault', 'detail', 'bin', 'path', 'searched', 'status_command',
            'flip_command', 'program', 'quoted_program', 'exit', 'answer',
            'running', 'near_misses', 'discovery_command', 'discovery_exit', 'discovery_answer',
            'state', 'states', 'start_command',
        ];

        $this->assertSame($keys, array_keys($empty));
        $this->assertSame($keys, array_keys($answered));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // discovery — what supervisord is running when it does not know the program
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_discovery_command_is_the_same_binary_with_a_bare_status(): void
    {
        $this->assertSame('/usr/bin/supervisorctl status', SupervisorStep::discoveryCommand('/usr/bin/supervisorctl'));
    }

    public function test_the_programs_are_the_lines_whose_second_word_is_a_state(): void
    {
        $answer = "pgcat:pgcat_00   RUNNING   pid 4242, uptime 0:12:34\n"
            . "pgcat:pgcat_01   STOPPED   Sep 26 11:02 AM\n";

        $this->assertSame(['pgcat:pgcat_00', 'pgcat:pgcat_01'], SupervisorStep::runningPrograms($answer));
        $this->assertSame(
            ['pgcat:pgcat_00'],
            SupervisorStep::runningPrograms("pgcat:pgcat_00   RUNNING   pid 4242\npgcat:pgcat_00   RUNNING   pid 4242\n"),
            'a program is named once',
        );
    }

    /**
     * The parser is not "take the first word": the same command prints a socket error, and
     * `supervisord` is a plausible name for a program in an installation that names one after
     * the daemon — so the *second* word decides, and a line whose second word is not a state is
     * not a program.
     */
    public function test_a_line_that_is_not_a_program_is_not_read_as_one(): void
    {
        $answer = "unix:///var/run/supervisor.sock no such file\n"
            . "supervisord   RUNNING   pid 7, uptime 3:00:00\n"
            . "nginx\n"
            . "pgcat   not-a-state   pid 9\n";

        $this->assertSame(['supervisord'], SupervisorStep::runningPrograms($answer));
    }

    /**
     * The relation decides the order, not the order supervisor happened to print: a name that
     * is the group the configured one addresses is nearer than one that merely starts with it,
     * which is nearer than one that holds it.
     */
    public function test_the_nearest_name_is_the_one_the_configured_name_relates_to_most_directly(): void
    {
        $this->assertSame(
            ['pgcat:pgcat_00', 'pgcat-1', 'lpr-pgcat-a'],
            SupervisorStep::nearMisses('pgcat:*', ['lpr-pgcat-a', 'pgcat-1', 'pgcat:pgcat_00']),
        );

        $this->assertSame(['pgcat:*'], SupervisorStep::nearMisses('pgcat:*', ['pgcat:*']), 'the same name is the nearest of all');
    }

    /**
     * The two ends of the rule: a list is capped, and a program that is running under a name
     * with nothing in common is not a suggestion — pointing an operator at it would be a guess
     * dressed as an answer.
     */
    public function test_only_names_close_to_the_configured_one_are_offered_and_never_more_than_three(): void
    {
        $this->assertCount(3, SupervisorStep::nearMisses('pgcat', ['pgcat-1', 'pgcat-2', 'pgcat-3', 'pgcat-4']));
        $this->assertSame([], SupervisorStep::nearMisses('pgcat', ['nginx', 'redis', 'postgres']));
    }

    public function test_a_refusal_names_what_supervisor_is_running_and_which_name_is_nearest(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $running = "pgcat_x:pgcat_00   RUNNING   pid 1\n"
            . "pgcat_x:pgcat_01   RUNNING   pid 2\n"
            . "nginx   RUNNING   pid 3\n";

        $verdict = (new SupervisorStep($this->twoAnswers($ran, 'pgcat:*: ERROR (no such group)', 2, $running)))
            ->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertFalse($verdict['usable']);
        $this->assertSame(SupervisorStep::FAULT_UNKNOWN_PROGRAM, $verdict['fault']);
        $this->assertSame([$binary . ' status "pgcat:*"', $binary . ' status'], $ran);

        // The context is data, for anything that would rather read the list than the sentence.
        $this->assertSame(['pgcat_x:pgcat_00', 'pgcat_x:pgcat_01', 'nginx'], $verdict['running']);
        $this->assertSame(['pgcat_x:pgcat_00', 'pgcat_x:pgcat_01'], $verdict['near_misses'], 'nginx has nothing in common with pgcat');
        $this->assertSame($binary . ' status', $verdict['discovery_command']);
        $this->assertSame(0, $verdict['discovery_exit']);
        $this->assertStringContainsString('pgcat_x:pgcat_00 RUNNING pid 1', $verdict['discovery_answer']);

        // And the sentence an operator reads says which name is the one to write.
        $this->assertStringContainsString(
            'says supervisord runs 3 program(s): pgcat_x:pgcat_00, pgcat_x:pgcat_01, nginx',
            $verdict['detail'],
        );
        $this->assertStringContainsString('the closest are "pgcat_x:pgcat_00", "pgcat_x:pgcat_01", which are the names to write', $verdict['detail']);
        $this->assertStringContainsString('or the whole group "pgcat_x:*"', $verdict['detail']);
    }

    /**
     * A name that is the group already asked about gets no group line: "did you mean `pgcat:*`"
     * is not advice to a command that said exactly that.
     */
    public function test_a_near_miss_that_is_the_group_already_asked_about_gets_no_group_line(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->twoAnswers($ran, 'pgcat:*: ERROR (no such group)', 2, 'pgcat:pgcat_00   RUNNING   pid 1\n')))
            ->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertSame(['pgcat:pgcat_00'], $verdict['near_misses']);
        $this->assertStringContainsString('says supervisord runs 1 program(s): pgcat:pgcat_00', $verdict['detail']);
        $this->assertStringContainsString('the closest is "pgcat:pgcat_00", which is the name to write', $verdict['detail']);
        $this->assertStringNotContainsString('or the whole group', $verdict['detail']);
    }

    /**
     * The case the group line exists for: the command named a group the way it would name a
     * program, and supervisorctl reads a bare name as a program — so the wildcard is the repair
     * and nothing else in the row would say it.
     */
    public function test_a_program_name_that_is_really_a_group_gets_the_group_line(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->twoAnswers($ran, 'pgcat: ERROR (no such process)', 2, 'pgcat:pgcat_00   RUNNING   pid 1\n')))
            ->inspect($binary . ' restart "pgcat"');

        $this->assertSame(SupervisorStep::FAULT_UNKNOWN_PROGRAM, $verdict['fault']);
        $this->assertSame(['pgcat:pgcat_00'], $verdict['near_misses']);
        $this->assertStringContainsString('which is the name to write, or the whole group "pgcat:*"', $verdict['detail']);
    }

    /**
     * The other end: supervisor answered with programs, and none of them is close. Both halves
     * are the point — the list still names what *is* running, and the sentence refuses to
     * pretend one of them is a repair.
     */
    public function test_the_discovery_offers_no_name_when_no_running_program_is_close(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->twoAnswers($ran, 'pgcat:*: ERROR (no such group)', 2, "nginx   RUNNING   pid 1\nredis   STOPPED\n")))
            ->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertSame(['nginx', 'redis'], $verdict['running']);
        $this->assertSame([], $verdict['near_misses']);
        $this->assertStringContainsString('says supervisord runs 2 program(s): nginx, redis', $verdict['detail']);
        $this->assertStringContainsString('none of them is close to "pgcat:*"', $verdict['detail']);
        $this->assertStringNotContainsString('the closest', $verdict['detail']);
    }

    /**
     * The second question is asked for one fault and no other: a command that works, and a
     * supervisorctl that could not answer at all, both stop after the one read-only command. An
     * extra process per run for an answer nothing reads would be the cost of this feature paid
     * by every installation.
     */
    public function test_supervisor_is_asked_nothing_further_when_the_first_answer_is_not_an_unknown_program(): void
    {
        $binary = $this->fakeBinary($this->tempDir());

        $ran = [];
        $known = (new SupervisorStep($this->recorder($ran)))->inspect($binary . ' signal HUP "pgcat:*"');
        $this->assertTrue($known['usable']);
        $this->assertSame([$binary . ' status "pgcat:*"'], $ran);
        $this->assertSame([], $known['running']);
        $this->assertNull($known['discovery_command']);

        $ran = [];
        $socket = (new SupervisorStep($this->recorder($ran, '', 'unix:///var/run/supervisor.sock no such file', 2)))
            ->inspect($binary . ' signal HUP "pgcat:*"');
        $this->assertSame(SupervisorStep::FAULT_ERRORED, $socket['fault']);
        $this->assertSame([$binary . ' status "pgcat:*"'], $ran, 'a socket that is not there is not a name to look up');
        $this->assertNull($socket['discovery_command']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A program supervisor knows and is not running: usable, and repairable
    // ─────────────────────────────────────────────────────────────────────

    /**
     * `supervisorctl status` exits non-zero the moment one named program is not RUNNING, so a
     * FATAL or STARTING pgcat arrives the same way a broken socket does. The two are repaired in
     * different places — one is the config pgcat could not start on, the other is the daemon or
     * the user running the check — so they must not share a verdict. The difference that matters
     * is `usable`: refusing the first is what leaves a dead pgcat with a config no flip will
     * replace.
     */
    public function test_a_program_that_is_not_running_is_usable_and_carries_its_state(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->recorder(
            $ran,
            'pgcat:pgcat_00   FATAL   Exited too quickly (process log may have details)',
            '',
            3,
        )))->inspect($binary . ' restart "pgcat:*"');

        $this->assertTrue($verdict['usable'], 'the file swap is the repair, so a flip may proceed');
        $this->assertSame(SupervisorStep::FAULT_NOT_RUNNING, $verdict['fault']);
        $this->assertSame('FATAL', $verdict['state']);
        $this->assertSame(['pgcat:pgcat_00' => 'FATAL'], $verdict['states']);
        $this->assertSame($binary . ' start "pgcat:*"', $verdict['start_command']);
        $this->assertStringContainsString('so supervisord knows the program and the answer is its state', $verdict['detail']);
        $this->assertStringContainsString('Exited too quickly', $verdict['detail']);
        $this->assertSame([$binary . ' status "pgcat:*"'], $ran, 'the question asked is still the read-only one');
    }

    public function test_a_starting_program_is_not_running_either(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->recorder($ran, 'pgcat:pgcat_00   STARTING', '', 3)))
            ->inspect($binary . ' signal HUP "pgcat:*"');

        $this->assertSame(SupervisorStep::FAULT_NOT_RUNNING, $verdict['fault']);
        $this->assertSame('STARTING', $verdict['state']);
        $this->assertSame(
            $binary . ' start "pgcat:*"',
            $verdict['start_command'],
            'a HUP acts on a program that is up, so the repair is the verb that does not',
        );
    }

    /**
     * The state the configured reload and restart commands were chosen for: a running program
     * leaves the verdict faultless, so the flip keeps running whatever the config names.
     */
    public function test_a_running_program_keeps_the_verdict_faultless(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $verdict = (new SupervisorStep($this->recorder($ran, 'pgcat:pgcat_00   RUNNING   pid 4242')))
            ->inspect($binary . ' restart "pgcat:*"');

        $this->assertTrue($verdict['usable']);
        $this->assertNull($verdict['fault']);
        $this->assertNull($verdict['state']);
    }

    /**
     * The other side of the same non-zero exit: a socket that is not there names no program, so
     * there is no state to read and starting anything through it would not be a repair.
     */
    public function test_a_missing_socket_is_not_read_as_a_program_that_is_down(): void
    {
        $binary = $this->fakeBinary($this->tempDir());
        $ran = [];

        $socket = (new SupervisorStep($this->recorder($ran, '', 'unix:///var/run/supervisor.sock no such file', 2)))
            ->inspect($binary . ' restart "pgcat:*"');

        $this->assertFalse($socket['usable']);
        $this->assertSame(SupervisorStep::FAULT_ERRORED, $socket['fault']);
        $this->assertNull($socket['state']);
        $this->assertNull($socket['start_command']);
    }

    public function test_states_reads_every_program_of_a_group_and_ignores_the_rest(): void
    {
        $answer = "pgcat:pgcat_00   RUNNING   pid 1\npgcat:pgcat_01   FATAL   Sep 26 11:02 AM\nnginx   RUNNING   pid 9\n";

        $expected = ['pgcat:pgcat_00' => 'RUNNING', 'pgcat:pgcat_01' => 'FATAL'];

        $this->assertSame($expected, SupervisorStep::states($answer, 'pgcat:*'), 'a group address matches its programs and nothing else');
        $this->assertSame($expected, SupervisorStep::states($answer, 'pgcat'), 'the bare group name matches them too');
        $this->assertSame([], SupervisorStep::states($answer, 'redis:*'));
    }

    public function test_the_aggregate_state_is_running_when_anything_is(): void
    {
        $this->assertSame(
            'RUNNING',
            SupervisorStep::aggregateState(['pgcat:pgcat_00' => 'RUNNING', 'pgcat:pgcat_01' => 'FATAL']),
            'one program up means the reload the config names will work',
        );
        $this->assertSame(
            'FATAL',
            SupervisorStep::aggregateState(['a' => 'STOPPED', 'b' => 'FATAL', 'c' => 'BACKOFF']),
            'with none of them up, the most severe state is the one that explains the outage',
        );
        $this->assertNull(SupervisorStep::aggregateState([]));
    }

    public function test_the_start_command_is_derived_with_the_quoting_kept(): void
    {
        $this->assertSame('supervisorctl start "pgcat:*"', SupervisorStep::startCommand('supervisorctl restart "pgcat:*"'));
        $this->assertSame('supervisorctl start "pgcat:*"', SupervisorStep::startCommand('supervisorctl signal HUP "pgcat:*"'));
        $this->assertNull(
            SupervisorStep::startCommand('/usr/local/bin/pgcat-reload reload'),
            'a command that names no program for supervisorctl has nothing to start by name',
        );
    }

    /**
     * @param list<string> $ran
     * @return \Closure(string): array{0:int,1:string,2:string}
     */
    private function recorder(array &$ran, string $stdout = 'pgcat:pgcat_00  RUNNING  pid 1', string $stderr = '', int $exit = 0): \Closure
    {
        return function (string $command) use (&$ran, $stdout, $stderr, $exit): array {
            $ran[] = $command;

            return [$exit, $stdout, $stderr];
        };
    }

    /**
     * A runner for the two questions a refusal over an unknown program asks: supervisor's
     * answer for that program, and then — because the answer was `no such group` — the list of
     * what it is running. The bare command is the one with nothing after `status`.
     *
     * @param list<string> $ran
     * @return \Closure(string): array{0:int,1:string,2:string}
     */
    private function twoAnswers(
        array &$ran,
        string $programAnswer,
        int $programExit,
        string $runningAnswer,
        int $runningExit = 0,
    ): \Closure {
        return function (string $command) use (&$ran, $programAnswer, $programExit, $runningAnswer, $runningExit): array {
            $ran[] = $command;

            return str_ends_with($command, ' status')
                ? [$runningExit, $runningAnswer, '']
                : [$programExit, '', $programAnswer];
        };
    }

    /**
     * A file that passes for an executable, named the way this platform expects one.
     */
    private function fakeBinary(string $dir): string
    {
        $path = $dir . '/' . (PHP_OS_FAMILY === 'Windows' ? 'supervisorctl.exe' : 'supervisorctl');

        file_put_contents($path, "# stands in for supervisorctl\n");
        @chmod($path, 0o755);

        return $path;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/supervisor-step-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}

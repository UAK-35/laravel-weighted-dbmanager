<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PgcatConfigFlipper unit tests — covers idempotency, mode transitions,
 * lock contention, file copy failure, supervisor command failure,
 * force-mode bypass, the PostgreSQL-only guard, and the status snapshot.
 *
 * File-system operations and supervisorctl calls are captured via closure
 * injection — no real files touched, no processes spawned.
 *
 * Run: vendor/bin/phpunit tests/Unit/Pgcat/PgcatConfigFlipperTest.php --testdox
 */
class PgcatConfigFlipperTest extends TestCase
{
    private string $tmp;
    private string $stateFile;
    private string $lockFile;

    // Closure call recorders
    /** @var array<int, array{0:string, 1:string}> */
    private array $copyLog = [];

    /** @var array<int, array{0:string, 1:int, 2:string}> */
    private array $cmdLog = [];

    /** @var array<int, array{0:string, 1:bool}> */
    private array $lockLog = [];

    /** The PATH as the test process found it, so the stand-in below can be removed again. */
    private string $originalPath = '';

    /** Where the stand-in supervisorctl was written. */
    private string $standIn = '';

    /** A stand-in for a command that is not supervisorctl at all. */
    private string $reloader = '';

    protected function setUp(): void
    {
        $this->tmp        = sys_get_temp_dir() . '/pgcat-flip-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0755, true);

        $this->stateFile  = $this->tmp . '/state.json';
        $this->lockFile   = $this->tmp . '/flip.lock';

        // Pre-stage two source configs that the flipper will copy from.
        file_put_contents($this->tmp . '/pgcat-readers.toml', "pool = 'readers'\n");
        file_put_contents($this->tmp . '/pgcat-no-readers.toml', "pool = 'writer-only'\n");
        file_put_contents($this->tmp . '/pgcat.toml', "pool = 'unknown'\n");

        // A flip now judges its supervisor command before it replaces anything, and that
        // judgement resolves the executable the way the shell will — so the command has to
        // be one that resolves. The stand-in is put on PATH rather than into the command,
        // which keeps the configured command the one the sample ships and keeps the PATH
        // search real (`supervisorctl.exe` is not accepted on this platform unless the file
        // is there). It is never executed: every flipper here is built with a closure that
        // captures commands instead.
        $this->originalPath = (string) getenv('PATH');

        $bin = $this->tmp . '/bin';
        @mkdir($bin, 0755, true);

        $script = PHP_OS_FAMILY === 'Windows' ? "@echo off\r\n" : "#!/bin/sh\n";

        $this->standIn = $bin . '/supervisorctl' . (PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
        file_put_contents($this->standIn, $script);
        @chmod($this->standIn, 0755);

        // ...and one that does not look like supervisorctl, for the case where a flip drives
        // something else and there is no program for supervisor to know.
        $this->reloader = $bin . '/pgcat-reload' . (PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
        file_put_contents($this->reloader, $script);
        @chmod($this->reloader, 0755);

        putenv('PATH=' . $bin . PATH_SEPARATOR . $this->originalPath);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup.
        putenv('PATH=' . $this->originalPath);
        @unlink($this->stateFile);
        @unlink($this->lockFile);
        @unlink($this->tmp . '/pgcat-readers.toml');
        @unlink($this->tmp . '/pgcat-no-readers.toml');
        @unlink($this->tmp . '/pgcat.toml');
        @unlink($this->tmp . '/blocked');
        @unlink($this->standIn);
        @unlink($this->reloader);
        @rmdir(dirname($this->standIn));
        @rmdir($this->tmp);
    }

    /**
     * The commands the flip itself ran — everything but the read-only check it now makes
     * first. Used wherever an assertion is about the flip's own command line.
     *
     * @return list<array{0:string, 1:int, 2:string}>
     */
    private function flipCommands(): array
    {
        return array_values(array_filter(
            $this->cmdLog,
            static fn (array $entry): bool => !str_contains($entry[0], ' status '),
        ));
    }

    /**
     * The read-only supervisor checks, in order — the preflight a flip makes before it
     * replaces anything.
     *
     * @return list<array{0:string, 1:int, 2:string}>
     */
    private function supervisorChecks(): array
    {
        return array_values(array_filter(
            $this->cmdLog,
            static fn (array $entry): bool => str_contains($entry[0], ' status '),
        ));
    }

    /**
     * Build a flipper wired to a specific resolver mode and our capture closures.
     *
     * Mode is encoded by window WIDTH:
     *   - 'readers' → window covers the entire day → isReaderWindow() = true at any "now"
     *   - 'writer'  → window is 1-second wide at midnight → isReaderWindow() = false
     *                 at any realistic current time
     *
     * (Setting readerDays: [] would also force writer-mode, but TimeWindowResolver
     * short-circuits to "always reader" if readerDays is empty, which is the
     * opposite of what we want.)
     */
    /**
     * @param \Closure(string): array{0:int,1:string,2:string}|null $runner
     *        answers for every command, keyed by nothing — a flip runs two now (the check,
     *        then the command), so a test that needs them to differ branches on the command
     *        line. The default answers both with success.
     */
    private function build(
        string $mode,
        array $configOverrides = [],
        ?string $driver = null,
        ?string $connectionName = null,
        ?\Closure $runner = null,
    ): PgcatConfigFlipper {
        $resolver = $mode === 'readers'
            ? new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            )
            : new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '00:00:01']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            );

        $config = array_merge([
            'enabled'          => true,
            'config_path'      => $this->tmp . '/pgcat.toml',
            'readers_path'     => $this->tmp . '/pgcat-readers.toml',
            'no_readers_path'  => $this->tmp . '/pgcat-no-readers.toml',
            'restart_command'  => 'supervisorctl restart pgcat',
            'reload_command'   => 'supervisorctl signal HUP pgcat',
            'use_reload'       => false,
        ], $configOverrides);

        $flipper = new PgcatConfigFlipper(
            resolver: $resolver,
            config: $config,
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            fileCopier: function (string $from, string $to): bool {
                $this->copyLog[] = [$from, $to];
                return @copy($from, $to);
            },
            commandRunner: function (string $cmd) use ($runner): array {
                $this->cmdLog[] = [$cmd, 0, 'OK'];

                return $runner === null ? [0, 'pgcat: started', ''] : $runner($cmd);
            },
            lockAcquirer: function (string $file): array {
                $acquired = true;   // tests assume lock available by default
                $this->lockLog[] = [$file, $acquired];
                $fp = fopen($file, 'c');
                return [$acquired, $fp];
            },
            lockReleaser: function ($fp): void {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            },
            timezone: 'UTC',
            connectionName: $connectionName,
            driver: $driver,
        );

        return $flipper;
    }

    /**
     * A flipper built from a config that does not carry the switch keys at all — how these
     * settings arrive for an installation whose published config predates them, and the case
     * the documented defaults exist for.
     *
     * `build()` merges over a base that always names both switches, so this one goes to the
     * constructor itself. It is deliberately inert: the config says nothing about `enabled`,
     * and an absent switch is off, so none of the default closures is ever called.
     */
    private function buildWithoutSwitches(): PgcatConfigFlipper
    {
        return new PgcatConfigFlipper(
            resolver: new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            ),
            config: [
                'config_path' => $this->tmp . '/pgcat.toml',
                'readers_path' => $this->tmp . '/pgcat-readers.toml',
                'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
            ],
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
        );
    }

    /**
     * A `SupervisorStep::inspect()` verdict for a command, from a runner that answers in
     * supervisor's place. `$answer` is what the derived read-only invocation returns — exit,
     * stdout, stderr — defaulted to a supervisor that knows the program it was asked about.
     *
     * The same class and the same call the flipper makes before it swaps a file, so the faults a
     * suggestion is offered for are pinned against real verdicts rather than arrays shaped like
     * one. Resolution is real too: `setUp()` puts a stand-in `supervisorctl` on PATH, because the
     * judgement is about a command a shell would run.
     *
     * @param array{0: int, 1: string, 2: string} $answer
     * @return array{fault: string|null, flip_command: string}
     */
    private function verdictFor(string $command, array $answer = [0, 'pgcat:pgcat_00 RUNNING pid 1', '']): array
    {
        return (new SupervisorStep(static fn (string $ignored): array => $answer))->inspect($command);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Idempotency: first call applies, subsequent calls no-op
    // ─────────────────────────────────────────────────────────────────────────

    public function test_first_call_in_readers_mode_flips_and_records_state(): void
    {
        $flipper = $this->build('readers');
        $result  = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertSame('readers', $result->mode);
        $this->assertNull($result->previousMode);
        $this->assertCount(1, $this->copyLog);
        $this->assertCount(1, $this->flipCommands());
        $this->assertStringContainsString('supervisorctl restart', $this->flipCommands()[0][0]);

        // ...and the read-only check came first: the file is only replaced once the
        // command that makes pgcat pick it up has been shown to work.
        $this->assertCount(1, $this->supervisorChecks());
        $this->assertSame('supervisorctl status "pgcat"', $this->supervisorChecks()[0][0]);
        $this->assertSame($this->supervisorChecks()[0], $this->cmdLog[0], 'the check precedes the flip command');

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame('readers', $state['last_mode']);
        $this->assertNull($state['previous_mode']);
    }

    public function test_second_call_same_mode_is_no_change_no_io(): void
    {
        $flipper = $this->build('readers');

        $flipper->applyCurrentState();
        $this->assertCount(1, $this->copyLog);
        $this->assertCount(1, $this->flipCommands());

        $second = $flipper->applyCurrentState();
        $this->assertSame('no_change', $second->kind());
        $this->assertSame('readers', $second->mode);
        $this->assertSame('mode unchanged since last flip', $second->reason);

        // Critical: no extra file copy, no extra supervisor call — not even the check,
        // which is skipped along with the swap by the mode-unchanged gate above it.
        $this->assertCount(1, $this->copyLog, 'second call must not copy file');
        $this->assertCount(1, $this->flipCommands(), 'second call must not run supervisor');
        $this->assertCount(1, $this->supervisorChecks(), 'second call must not even check');
    }

    public function test_state_file_missing_first_run_is_still_a_flip(): void
    {
        $this->assertFileDoesNotExist($this->stateFile);

        $flipper = $this->build('readers');
        $result  = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertFileExists($this->stateFile);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mode transitions: writer → reader and reader → writer
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transition_from_writer_to_readers_flips(): void
    {
        $flipper = $this->build('writer');
        $flipper->applyCurrentState();        // first call → writer mode (1 copy recorded)
        $this->assertCount(1, $this->copyLog);

        // Force the state file to look like 'writer' was last applied.
        file_put_contents($this->stateFile, json_encode(['last_mode' => 'writer']));

        // Now spin up a fresh flipper in 'readers' mode and apply state.
        $readersFlipper = $this->build('readers');
        $result = $readersFlipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertSame('readers', $result->mode);
        $this->assertSame('writer', $result->previousMode);

        // Two flips total: writer (initial) + readers (after transition).
        $this->assertCount(2, $this->copyLog);
        $this->assertCount(2, $this->flipCommands());
    }

    public function test_transition_swaps_the_correct_file(): void
    {
        // First, force into writer-only mode.
        $writerFlipper = $this->build('writer');
        $writerFlipper->applyCurrentState();
        $this->assertStringContainsString(
            'pgcat-no-readers.toml',
            $this->copyLog[0][0],
        );

        // Verify pgcat.toml content reflects writer-only.
        $this->assertSame("pool = 'writer-only'\n", file_get_contents($this->tmp . '/pgcat.toml'));

        // Now simulate transitioning to readers by mutating the resolver.
        // (Easiest: write state directly and use a readers-mode flipper.)
        file_put_contents($this->stateFile, json_encode(['last_mode' => 'writer']));
        $readersFlipper = $this->build('readers');
        $result = $readersFlipper->applyCurrentState();
        $this->assertSame('flipped', $result->kind());

        $this->assertSame("pool = 'readers'\n", file_get_contents($this->tmp . '/pgcat.toml'));
        $this->assertStringContainsString('pgcat-readers.toml', $this->copyLog[1][0]);
        $this->assertCount(2, $this->supervisorChecks(), 'each flip checks before it swaps');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lock contention: skip, do not duplicate work
    // ─────────────────────────────────────────────────────────────────────────

    public function test_lock_not_acquired_returns_skipped(): void
    {
        $resolver = new TimeWindowResolver(
            readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
            readerDays: [1, 2, 3, 4, 5, 6, 7],
        );

        $flipper = new PgcatConfigFlipper(
            resolver: $resolver,
            config: [
                'enabled' => true,
                'config_path' => $this->tmp . '/pgcat.toml',
                'readers_path' => $this->tmp . '/pgcat-readers.toml',
                'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
                'restart_command' => 'supervisorctl restart pgcat',
                'reload_command' => 'supervisorctl signal HUP pgcat',
                'use_reload' => false,
            ],
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            lockAcquirer: function (string $file): array {
                $this->lockLog[] = [$file, false];
                return [false, null];
            },
            lockReleaser: fn ($fp) => null,
        );

        $result = $flipper->applyCurrentState();

        $this->assertSame('skipped', $result->kind());
        $this->assertStringContainsString('lock', strtolower($result->reason));
        $this->assertCount(0, $this->copyLog);
        $this->assertCount(0, $this->cmdLog);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Disabled configuration
    // ─────────────────────────────────────────────────────────────────────────

    public function test_disabled_config_returns_no_change(): void
    {
        $flipper = $this->build('readers', ['enabled' => false]);
        $result  = $flipper->applyCurrentState();

        $this->assertSame('no_change', $result->kind());
        $this->assertCount(0, $this->copyLog);
        $this->assertCount(0, $this->cmdLog);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Failure paths
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_source_file_returns_failed(): void
    {
        $flipper = new PgcatConfigFlipper(
            resolver: $this->build('readers')->status() ? new TimeWindowResolver(readerWindows: []) : new TimeWindowResolver(readerWindows: []),
            config: [
                'enabled'         => true,
                'config_path'     => $this->tmp . '/pgcat.toml',
                'readers_path'    => $this->tmp . '/does-not-exist.toml',
                'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
                'restart_command' => 'supervisorctl restart pgcat',
                'reload_command'  => 'supervisorctl signal HUP pgcat',
                'use_reload'      => false,
            ],
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            fileCopier: fn ($f, $t) => @copy($f, $t),
            commandRunner: fn ($c) => [0, '', ''],
            lockAcquirer: function (string $file): array {
                $fp = fopen($file, 'c');
                return [true, $fp];
            },
            lockReleaser: fn ($fp) => null,
        );

        $result = $flipper->applyCurrentState();
        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('not readable', $result->error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The command is judged before the file is replaced
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The ordering this exists for: a command that cannot make pgcat pick the new file up
     * must not leave one behind, because the next pgcat start — a crash, a deploy, an OOM
     * kill — would come up in a mode no window asked for. Nothing is copied, the command is
     * never run, and no mode is recorded, so the next poll retries and the flip happens by
     * itself once the command is fixed.
     */
    public function test_a_flip_refuses_before_the_swap_when_supervisor_does_not_know_the_program(): void
    {
        $flipper = $this->build('readers', runner: fn (string $command): array => str_contains($command, ' status ')
            ? [2, 'pgcat: ERROR (no such group)', '']
            : [0, '', '']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('no such group', (string) $result->error);
        $this->assertStringContainsString('refuses before it swaps the file', (string) $result->error);
        $this->assertCount(0, $this->copyLog, 'the target was never replaced');
        $this->assertCount(0, $this->flipCommands(), 'the command was never run');
        $this->assertSame("pool = 'unknown'\n", file_get_contents($this->tmp . '/pgcat.toml'));
        $this->assertFileDoesNotExist($this->stateFile, 'no mode was recorded, so the next poll tries again');
    }

    public function test_a_flip_refuses_before_the_swap_when_the_program_name_is_unquoted(): void
    {
        $flipper = $this->build('readers', [
            'restart_command' => 'supervisorctl restart pgcat:*',
        ]);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('is unquoted', (string) $result->error);
        $this->assertStringContainsString('Write supervisorctl restart "pgcat:*"', (string) $result->error);
        $this->assertCount(0, $this->copyLog);
        $this->assertSame([], $this->cmdLog, 'an unquoted name is refused without asking anything');
    }

    public function test_a_flip_refuses_before_the_swap_when_supervisorctl_does_not_resolve(): void
    {
        $flipper = $this->build('readers', [
            'restart_command' => 'no-such-supervisorctl restart pgcat',
        ]);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('does not resolve to an executable', (string) $result->error);
        $this->assertStringContainsString('tried no-such-supervisorctl on', (string) $result->error);
        $this->assertCount(0, $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_a_flip_refuses_before_the_swap_when_supervisorctl_cannot_answer(): void
    {
        $flipper = $this->build('readers', [], runner: fn (string $command): array => [-1, '', 'timed out']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('did not answer in time', (string) $result->error);
        $this->assertCount(0, $this->copyLog, 'a supervisorctl that cannot answer stops the flip, not the file');
        $this->assertCount(0, $this->flipCommands());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The command that passes the check and fails anyway is rolled back
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The residual case the check cannot cover: supervisorctl answered `status` and then
     * failed the command itself — it raced with a restart, or pgcat would not come back up.
     * The previous file goes back, so what is on disk is what pgcat is running; a new file
     * with the old processes running is the state only a restart would reveal.
     */
    public function test_a_command_that_fails_after_the_check_puts_the_previous_config_back(): void
    {
        $flipper = $this->build('readers', runner: fn (string $command): array => str_contains($command, ' status ')
            ? [0, 'pgcat:pgcat_00 RUNNING pid 4242', '']
            : [2, '', 'pgcat did not start']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('pgcat did not start', (string) $result->error);
        $this->assertStringContainsString('the previous config was put back', (string) $result->error);

        $this->assertCount(1, $this->copyLog, 'the swap did happen — the rollback undoes it');
        $this->assertCount(1, $this->flipCommands());
        $this->assertSame("pool = 'unknown'\n", file_get_contents($this->tmp . '/pgcat.toml'), 'the old variant is back');
        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertSame([], glob($this->tmp . '/pgcat.toml.tmp.*') ?: [], 'the rollback leaves no temp file');
    }

    public function test_the_rollback_removes_a_target_the_flip_created(): void
    {
        @unlink($this->tmp . '/pgcat.toml');

        $flipper = $this->build('readers', runner: fn (string $command): array => str_contains($command, ' status ')
            ? [0, 'pgcat: RUNNING', '']
            : [2, '', 'nope']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('did not exist before the swap either, so it was removed again', (string) $result->error);
        $this->assertFileDoesNotExist($this->tmp . '/pgcat.toml');
    }

    /**
     * force-mode is a manual operation, and the reason it exists is a maintenance window —
     * exactly when a flip that leaves the file behind is worst. It goes through the same
     * swap, so it is judged and rolled back the same way.
     */
    public function test_a_forced_flip_is_judged_before_it_swaps_too(): void
    {
        $flipper = $this->build('readers', [], runner: fn (string $command): array => [2, 'no such group', '']);

        $result = $flipper->forceMode('writer');

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('no such group', (string) $result->error);
        $this->assertCount(0, $this->copyLog);
        $this->assertSame("pool = 'unknown'\n", file_get_contents($this->tmp . '/pgcat.toml'));
    }

    /**
     * A command that is not supervisorctl has nothing for supervisor to know, so there is
     * nothing to refuse — the executable still has to resolve, and that is all.
     */
    public function test_a_command_that_is_not_supervisorctl_is_only_checked_for_resolution(): void
    {
        $flipper = $this->build('readers', [
            'restart_command' => $this->reloader.' reload pgcat',
        ]);

        $result = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertCount(1, $this->flipCommands());
        $this->assertSame([], $this->supervisorChecks(), 'nothing was asked about a program');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Force-mode bypasses the resolver
    // ─────────────────────────────────────────────────────────────────────────

    public function test_force_mode_bypasses_resolver_and_state_check(): void
    {
        // Even if state says 'readers' and resolver says 'readers', force-mode=writer should flip.
        file_put_contents($this->stateFile, json_encode(['last_mode' => 'readers']));

        $resolver = new TimeWindowResolver(
            readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
            readerDays: [1, 2, 3, 4, 5, 6, 7],
        );

        $flipper = new PgcatConfigFlipper(
            resolver: $resolver,
            config: [
                'enabled'         => true,
                'config_path'     => $this->tmp . '/pgcat.toml',
                'readers_path'    => $this->tmp . '/pgcat-readers.toml',
                'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
                'restart_command' => 'supervisorctl restart pgcat',
                'reload_command'  => 'supervisorctl signal HUP pgcat',
                'use_reload'      => false,
            ],
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            fileCopier: function (string $from, string $to): bool {
                $this->copyLog[] = [$from, $to];
                return @copy($from, $to);
            },
            commandRunner: function (string $cmd): array {
                $this->cmdLog[] = [$cmd, 0, 'OK'];
                return [0, 'OK', ''];
            },
            lockAcquirer: function (string $file): array {
                $fp = fopen($file, 'c');
                return [true, $fp];
            },
            lockReleaser: fn ($fp) => null,
        );

        $result = $flipper->forceMode('writer');
        $this->assertSame('flipped', $result->kind());
        $this->assertSame('writer', $result->mode);
        $this->assertSame('readers', $result->previousMode);

        // pgcat.toml should now be the writer-only content.
        $this->assertSame("pool = 'writer-only'\n", file_get_contents($this->tmp . '/pgcat.toml'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Status snapshot
    // ─────────────────────────────────────────────────────────────────────────

    public function test_status_returns_full_snapshot(): void
    {
        $flipper = $this->build('readers');
        $status  = $flipper->status();

        $this->assertTrue($status['enabled']);
        $this->assertSame('readers', $status['resolver_mode']);
        $this->assertNull($status['last_mode']);
        $this->assertSame($this->tmp . '/pgcat.toml', $status['config_path']);
        $this->assertSame('supervisorctl restart pgcat', $status['restart_command']);
        $this->assertSame('supervisorctl signal HUP pgcat', $status['reload_command']);
        $this->assertFalse($status['use_reload']);
        $this->assertSame($this->stateFile, $status['state_file']);
        $this->assertSame($this->lockFile, $status['lock_file']);
    }

    public function test_status_reflects_persisted_state(): void
    {
        file_put_contents($this->stateFile, json_encode([
            'last_mode' => 'readers',
            'last_flipped_at' => '2026-09-18T12:00:00+00:00',
        ]));

        $flipper = $this->build('readers');
        $status  = $flipper->status();

        $this->assertSame('readers', $status['last_mode']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // reload vs restart mode
    // ─────────────────────────────────────────────────────────────────────────

    public function test_use_reload_triggers_signal_hup_not_full_restart(): void
    {
        $flipper = $this->build('readers', ['use_reload' => true]);
        $result  = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertCount(1, $this->flipCommands());
        $this->assertStringContainsString('signal HUP', $this->flipCommands()[0][0]);
        $this->assertStringNotContainsString('restart', $this->flipCommands()[0][0]);
    }

    public function test_default_uses_full_restart(): void
    {
        $flipper = $this->build('readers');
        $flipper->applyCurrentState();

        $this->assertCount(1, $this->flipCommands());
        $this->assertStringContainsString('supervisorctl restart', $this->flipCommands()[0][0]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The repair line a report can print: what a fault reduces to, and when it does not
    //
    // `db:doctor` prints a `suggestion` line under a row only when the row can name the
    // replacement exactly. Both pgcat rows that can are asked here, so the faults that get a
    // line and the faults that do not are pinned against real `SupervisorStep::inspect()`
    // verdicts rather than against arrays a test made up.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_gate_suggestion_is_the_switch_turned_off_where_pgcat_cannot_act(): void
    {
        // The switch is on for a connection pgcat cannot front, so the value that clears the
        // warning is the one that turns it off — the line the sentence beside it already names
        // ("Set swrr.pgcat.enabled = false"), printed as something a report can paste and a job
        // can read out of --json rather than as a clause in a sentence.
        $flipper = $this->build('readers', ['enabled' => true], driver: 'mysql', connectionName: 'mysql_app');

        $this->assertTrue($flipper->isMismatched(), 'that is the fault being asked about');
        $this->assertSame('swrr.pgcat.enabled = false', $flipper->suggestionForGate());
    }

    public function test_the_gate_suggestion_is_null_when_the_repair_is_not_a_pgcat_value(): void
    {
        // Armed on a connection pgcat can front, and incomplete: the row names keys whose
        // values are this installation's to choose, so there is no line to print.
        $armedButIncomplete = $this->build('readers', ['no_readers_path' => ''], driver: 'pgsql');

        $this->assertNotNull($armedButIncomplete->armingWarning(), 'that is the other fault this row fails on');
        $this->assertNull($armedButIncomplete->suggestionForGate());

        // Switched off, and armed and ready: nothing is wrong, so nothing is repaired.
        $this->assertNull($this->build('readers', ['enabled' => false], driver: 'pgsql')->suggestionForGate());
        $this->assertNull($this->build('readers', [], driver: 'pgsql')->suggestionForGate());
    }

    public function test_the_supervisor_suggestion_is_the_command_with_the_program_name_quoted(): void
    {
        $verdict = $this->verdictFor('supervisorctl restart pgcat:*');

        $this->assertSame(SupervisorStep::FAULT_UNQUOTED, $verdict['fault']);
        $this->assertSame(
            "swrr.pgcat.restart_command = 'supervisorctl restart \"pgcat:*\"'",
            $this->build('readers', ['use_reload' => false])->suggestionForSupervisor($verdict),
        );
    }

    public function test_the_supervisor_suggestion_names_the_key_the_flip_reads(): void
    {
        // use_reload decides which of the two commands a flip runs, and the repair has to name
        // the same key: a line sending the operator to the setting their flip is not using is a
        // repair that changes nothing at all.
        $verdict = $this->verdictFor('supervisorctl signal HUP pgcat:*');

        $this->assertSame(SupervisorStep::FAULT_UNQUOTED, $verdict['fault']);
        $this->assertSame(
            "swrr.pgcat.reload_command = 'supervisorctl signal HUP \"pgcat:*\"'",
            $this->build('readers', ['use_reload' => true])->suggestionForSupervisor($verdict),
        );
    }

    public function test_the_supervisor_suggestion_for_an_empty_command_is_the_command_it_documents(): void
    {
        // The fault's own sentence names the keys and not the values ("write
        // swrr.pgcat.restart_command (or reload_command, with use_reload on)") — because the
        // step does not know which one the flip reads. The flipper does, and it also owns the
        // documented commands, so the line can name a value rather than a key.
        $verdict = $this->verdictFor('');

        $this->assertSame(SupervisorStep::FAULT_EMPTY, $verdict['fault']);
        $this->assertSame(
            "swrr.pgcat.restart_command = 'supervisorctl restart \"pgcat:*\"'",
            $this->build('readers', ['use_reload' => false])->suggestionForSupervisor($verdict),
        );
        $this->assertSame(
            "swrr.pgcat.reload_command = 'supervisorctl signal HUP \"pgcat:*\"'",
            $this->build('readers', ['use_reload' => true])->suggestionForSupervisor($verdict),
        );
    }

    public function test_no_suggestion_is_named_for_a_fault_a_pgcat_value_cannot_repair(): void
    {
        // The line is drawn where `ReaderWindows::suggestion()` draws it for a refused window: a
        // fault whose repair is not a value this package can name gets no line at all, rather
        // than a plausible-looking one. These are the rest of the step's faults, each a real
        // verdict, plus the two that are not faults at all.
        $flipper = $this->build('readers');

        $verdicts = [
            'an executable that does not resolve' => $this->verdictFor('no-such-supervisorctl restart pgcat'),
            'a supervisord that does not know the program' => $this->verdictFor(
                $this->standIn . ' restart "pgcat:*"',
                [2, '', 'pgcat:*: ERROR (no such group)'],
            ),
            'a supervisorctl that cannot answer' => $this->verdictFor('supervisorctl restart pgcat', [-1, '', 'timed out']),
            'a supervisorctl that errored' => $this->verdictFor('supervisorctl restart pgcat', [2, '', 'permission denied']),
            'a command that is not supervisorctl' => $this->verdictFor($this->reloader . ' reload pgcat'),
            'a supervisor command that names no program' => $this->verdictFor('supervisorctl reread'),
            'a command that works' => $this->verdictFor('supervisorctl restart "pgcat:*"'),
        ];

        foreach ($verdicts as $fault => $verdict) {
            $this->assertNull($flipper->suggestionForSupervisor($verdict), $fault);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The switches, read as written rather than cast
    //
    // `(bool) 'false'` is true, so every way of writing *off* used to read as *on* — and on
    // `enabled` that arms a file swap. These pin the replacement: the spellings an operator
    // writes are read, everything else is refused and named on `refusedSwitches()`, and a
    // refusal holds the value the setting documents rather than a value the cast picked.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: mixed, 1: bool, 2: bool}>
     */
    public static function enabledSpellings(): array
    {
        return [
            'the string false' => ['false', false, false],
            'the string off' => ['off', false, false],
            'the string no' => ['no', false, false],
            'the string zero' => ['0', false, false],
            'the int zero' => [0, false, false],
            'the string true' => ['true', true, true],
            'the string on' => ['on', true, true],
            'the string yes' => ['yes', true, true],
            'the string one' => ['1', true, true],
            'the int one' => [1, true, true],
        ];
    }

    /**
     * @param mixed $written the value as it would be in a published config or a .env file
     */
    #[DataProvider('enabledSpellings')]
    public function test_a_switch_written_as_a_spelling_is_read_as_that_spelling(
        mixed $written,
        bool $configured,
        bool $enabled,
    ): void {
        $flipper = $this->build('readers', ['enabled' => $written]);

        $this->assertSame($configured, $flipper->status()['configured_enabled']);
        $this->assertSame($enabled, $flipper->isEnabled());
        $this->assertSame([], $flipper->refusedSwitches(), 'a readable switch is not a refusal');
    }

    public function test_off_written_as_the_string_false_actually_disables_the_flip(): void
    {
        // The exact case the cast inverted. Nothing is read, copied or restarted.
        $flipper = $this->build('readers', ['enabled' => 'false']);

        $this->assertSame('no_change', $flipper->applyCurrentState()->kind());
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_a_refused_switch_is_named_and_holds_the_value_the_setting_documents(): void
    {
        $flipper = $this->build('readers', ['enabled' => 'flase']);

        $this->assertSame(['swrr.pgcat.enabled' => '"flase"'], $flipper->refusedSwitches());

        // The documented default, not a guess in either direction: pgcat ships off, so a
        // typo can never be the thing that arms a file swap.
        $this->assertFalse($flipper->status()['configured_enabled']);
        $this->assertFalse($flipper->isEnabled());
        $this->assertSame('no_change', $flipper->applyCurrentState()->kind());
        $this->assertSame([], $this->copyLog);
    }

    public function test_a_refused_use_reload_holds_the_documented_default_the_other_way(): void
    {
        $flipper = $this->build('readers', ['use_reload' => 'maybe']);

        $this->assertSame(['swrr.pgcat.use_reload' => '"maybe"'], $flipper->refusedSwitches());

        // The gentler command is what the setting documents, so a refusal reloads rather
        // than restarting pgcat — a refused switch must not escalate the action either.
        $this->assertTrue($flipper->status()['use_reload']);
        $this->assertStringContainsString('signal HUP', $flipper->supervisorCommand());
    }

    public function test_both_pgcat_switches_are_refused_together(): void
    {
        // One value per setting, so a flipper that is wrong twice says so twice: fixing one
        // typo must not be the only way to find out about the other.
        $flipper = $this->build('readers', ['enabled' => 2, 'use_reload' => '']);

        $this->assertSame(
            ['swrr.pgcat.enabled' => 'int', 'swrr.pgcat.use_reload' => '""'],
            $flipper->refusedSwitches(),
        );
    }

    public function test_a_switch_that_is_not_there_is_not_refused(): void
    {
        $flipper = $this->buildWithoutSwitches();

        $this->assertSame([], $flipper->refusedSwitches(), 'nothing written is nothing to refuse');
    }

    public function test_an_absent_switch_holds_the_value_the_shipped_config_prints(): void
    {
        // The published config ships `enabled` off and `use_reload` on, and those are the
        // values the flipper falls back to — the same ones a refusal lands on, which is what
        // lets a report say "the value this setting documents" and be right.
        $flipper = $this->buildWithoutSwitches();
        $status = $flipper->status();

        $this->assertFalse($status['configured_enabled']);
        $this->assertTrue($status['use_reload']);
        $this->assertSame('no_change', $flipper->applyCurrentState()->kind());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PostgreSQL only — any other driver turns pgcat flipping off
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_non_postgresql_driver_disables_pgcat(): void
    {
        $flipper = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app');
        $status  = $flipper->status();

        $this->assertFalse($status['enabled']);
        $this->assertTrue($status['configured_enabled']);
        $this->assertSame('mysql_app', $status['connection']);
        $this->assertSame('mysql', $status['driver']);
        $this->assertFalse($status['driver_supported']);
        $this->assertFalse($flipper->isEnabled());
    }

    public function test_a_non_postgresql_driver_never_touches_files_or_supervisor(): void
    {
        $flipper = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app');

        $result = $flipper->applyCurrentState();

        $this->assertSame('no_change', $result->kind());
        $this->assertStringContainsString('PostgreSQL-only', $result->reason);
        $this->assertStringContainsString('mysql_app', $result->reason);
        $this->assertCount(0, $this->copyLog);
        $this->assertCount(0, $this->cmdLog);
        // The guard sits before the lock, so nothing was even attempted.
        $this->assertCount(0, $this->lockLog);
        $this->assertFileDoesNotExist($this->stateFile);
    }

    public function test_force_mode_is_a_no_op_on_a_non_postgresql_driver(): void
    {
        $flipper = $this->build('readers', [], driver: 'sqlite');

        $result = $flipper->forceMode('writer');

        $this->assertSame('no_change', $result->kind());
        $this->assertStringContainsString('sqlite', $result->reason);
        $this->assertCount(0, $this->copyLog);
        $this->assertCount(0, $this->cmdLog);
    }

    public function test_a_postgresql_driver_still_flips(): void
    {
        $flipper = $this->build('readers', [], driver: 'pgsql', connectionName: 'pgsql_proxy');

        $result = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertCount(1, $this->copyLog);
        $this->assertCount(1, $this->flipCommands());
    }

    public function test_the_configured_switch_still_wins_when_it_is_off(): void
    {
        $flipper = $this->build('readers', ['enabled' => false], driver: 'pgsql');
        $status  = $flipper->status();

        $this->assertFalse($status['enabled']);
        $this->assertFalse($status['configured_enabled']);
        $this->assertTrue($status['driver_supported']);
    }

    public function test_status_extends_health_summary_so_they_cannot_disagree(): void
    {
        $status = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app')->status();
        $health = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app')->healthSummary();

        // Every shared key carries the same value: status() is healthSummary()
        // plus the file/command settings, which is what keeps /health/db,
        // db:replica-status and db:pgcat-flip --status in agreement.
        $this->assertSame($health, array_intersect_key($status, $health));

        $this->assertFalse($health['enabled']);
        $this->assertTrue($health['configured_enabled']);
        $this->assertSame('mysql_app', $health['connection']);
        $this->assertSame('mysql', $health['driver']);
        $this->assertFalse($health['driver_supported']);
        $this->assertSame('readers', $health['resolver_mode']);
        $this->assertStringContainsString('PostgreSQL-only', (string) $health['reason']);

        // The arming verdict travels with the snapshot too.
        $this->assertTrue($health['mismatch']);
        $this->assertNull($health['armed_reason']);
        $this->assertStringContainsString('will never act', (string) $health['warning']);

        $this->assertArrayHasKey('config_path', $status);
        $this->assertArrayNotHasKey('config_path', $health);
    }

    public function test_disabled_reason_covers_both_ways_of_being_off(): void
    {
        $this->assertNull($this->build('readers', [], driver: 'pgsql')->disabledReason());

        $this->assertSame(
            'pgcat flipping disabled (swrr.pgcat.enabled = false)',
            $this->build('readers', ['enabled' => false], driver: 'pgsql')->disabledReason(),
        );

        $this->assertStringContainsString(
            'uses driver "mysql"',
            (string) $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app')->disabledReason(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arming: why it acts — and the case where it is switched on but cannot
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_armed_flipper_names_the_switch_and_the_driver(): void
    {
        $health = $this->build('readers', [], driver: 'pgsql', connectionName: 'pgsql_proxy')->healthSummary();

        $this->assertTrue($health['enabled']);
        $this->assertNull($health['reason']);          // not disabled
        $this->assertFalse($health['mismatch']);
        $this->assertNull($health['warning']);         // armed and ready

        $this->assertStringContainsString('swrr.pgcat.enabled = true', (string) $health['armed_reason']);
        $this->assertStringContainsString('driver "pgsql"', (string) $health['armed_reason']);
        $this->assertStringContainsString('pgsql_proxy', (string) $health['armed_reason']);
    }

    public function test_reason_and_armed_reason_are_exact_opposites(): void
    {
        $cases = [
            'armed' => $this->build('readers', [], driver: 'pgsql')->healthSummary(),
            'switch off' => $this->build('readers', ['enabled' => false], driver: 'pgsql')->healthSummary(),
            'driver pgcat cannot front' => $this->build('readers', [], driver: 'sqlite')->healthSummary(),
        ];

        foreach ($cases as $case => $health) {
            $this->assertNotSame(
                $health['reason'] === null,
                $health['armed_reason'] === null,
                "{$case}: exactly one of reason/armed_reason must be null",
            );
        }
    }

    public function test_an_enabled_switch_on_a_driver_pgcat_cannot_front_is_a_mismatch(): void
    {
        $health = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app')->healthSummary();

        $this->assertFalse($health['enabled'], 'the flipper is inert — there is no pool to swap');
        $this->assertTrue($health['configured_enabled'], '...yet the operator did switch it on');
        $this->assertFalse($health['driver_supported']);
        $this->assertTrue($health['mismatch']);
        $this->assertNull($health['armed_reason']);

        $warning = (string) $health['warning'];
        $this->assertStringContainsString('swrr.pgcat.enabled is true but pgcat will never act', $warning);
        $this->assertStringContainsString('driver "mysql"', $warning);
        $this->assertStringContainsString('mysql_app', $warning);
    }

    public function test_switching_it_off_on_an_unsupported_driver_is_not_a_mismatch(): void
    {
        $health = $this->build('readers', ['enabled' => false], driver: 'sqlite')->healthSummary();

        $this->assertFalse($health['mismatch']);
        $this->assertNull($health['warning'], 'off on purpose is not a mismatch');
        $this->assertNull($health['armed_reason']);
        $this->assertStringContainsString('swrr.pgcat.enabled = false', (string) $health['reason']);
    }

    public function test_an_armed_flipper_missing_a_target_warns_that_a_flip_would_fail(): void
    {
        $health = $this->build('readers', [
            'config_path' => '',
            'no_readers_path' => '',
        ], driver: 'pgsql')->healthSummary();

        $this->assertTrue($health['enabled']);
        $this->assertFalse($health['mismatch'], 'the driver gate is not the problem here');
        $this->assertStringContainsString(
            'set swrr.pgcat.config_path and swrr.pgcat.no_readers_path',
            (string) $health['warning'],
        );
    }

    public function test_a_standalone_flipper_without_a_driver_is_armed_and_ready(): void
    {
        $health = $this->build('readers')->healthSummary();   // no driver → unrestricted

        $this->assertTrue($health['enabled']);
        $this->assertFalse($health['mismatch']);
        $this->assertNull($health['warning']);
        $this->assertStringContainsString('(unset)', (string) $health['armed_reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dry run: rehearse the flip, change nothing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The whole point of a rehearsal: every step that can be taken without changing anything
     * is taken, and the two that change the world are reported. The proof is on both sides —
     * the report says what would happen, and the filesystem and the two capture closures say
     * that nothing did.
     */
    public function test_a_dry_run_performs_the_steps_that_leave_nothing_behind(): void
    {
        $flipper = $this->build('readers');
        $result  = $flipper->dryRun();

        $this->assertSame('would_flip', $result->kind());
        $this->assertTrue($result->wouldFlipHappen());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame('readers', $result->mode);
        $this->assertNull($result->previousMode, 'never applied, so a first flip would run');

        // Performed for real: the read, the temp write, and the lock.
        $this->assertSame('done', $result->step('lock')['outcome']);
        $this->assertSame('done', $result->step('read source')['outcome']);
        $this->assertStringContainsString('pgcat-readers.toml', $result->step('read source')['detail']);
        $this->assertStringContainsString('17 bytes', $result->step('read source')['detail'], 'the source\'s own size, so the read is provably the real one');
        $this->assertSame('done', $result->step('write temp')['outcome']);
        $this->assertStringContainsString('17 bytes', $result->step('write temp')['detail'], 'the same bytes reached the target\'s directory');
        $this->assertSame('done', $result->step('remove temp')['outcome']);

        // Reported, never taken: the rename and the supervisor command.
        $this->assertSame('would', $result->step('rename')['outcome']);
        $this->assertStringContainsString('the content would change', $result->step('rename')['detail']);
        $this->assertSame('would', $result->step('supervisor')['outcome']);
        $this->assertStringContainsString('supervisorctl restart', $result->step('supervisor')['detail']);

        // The check a flip makes first, performed here because it changes nothing. The
        // only command that reaches the runner is the read-only one this class derived.
        $this->assertSame('done', $result->step('supervisor check')['outcome']);
        $this->assertStringContainsString('supervisorctl resolves to', $result->step('supervisor check')['detail']);
        $this->assertSame(['supervisorctl status "pgcat"'], array_column($this->supervisorChecks(), 0));

        // The copier *is* the rename, so an untouched copy log is the strongest evidence
        // there is that the target was never replaced.
        $this->assertSame([], $this->copyLog, 'a dry run must not copy over the target');
        $this->assertSame([], $this->flipCommands(), 'a dry run must not run the flip command');

        // ...and the filesystem agrees.
        $this->assertSame("pool = 'unknown'\n", file_get_contents($this->tmp . '/pgcat.toml'));
        $this->assertFileDoesNotExist($this->stateFile, 'a rehearsal must not record a mode it did not apply');
        $this->assertSame([], glob($this->tmp . '/pgcat.toml.tmp.*') ?: [], 'the probe is removed again');
    }

    public function test_a_dry_run_of_a_mode_already_applied_reports_nothing_to_do(): void
    {
        file_put_contents($this->stateFile, json_encode(['last_mode' => 'readers']));

        $flipper = $this->build('readers');
        $result  = $flipper->dryRun();

        $this->assertSame('would_not_flip', $result->kind());
        $this->assertFalse($result->wouldFlipHappen());
        $this->assertSame(0, $result->exitCode(), 'nothing to do is not a failure');
        $this->assertSame('mode unchanged since last flip', $result->reason);
        $this->assertSame('refused', $result->step('mode')['outcome']);
        $this->assertNull($result->step('write temp'), 'it stopped before the swap');
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_rehearsing_twice_is_still_not_a_flip(): void
    {
        $flipper = $this->build('readers');

        $this->assertSame('would_flip', $flipper->dryRun()->kind());
        $this->assertSame('would_flip', $flipper->dryRun()->kind(), 'nothing was recorded, so both rehearse the same flip');
        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertSame([], $this->flipCommands());
        $this->assertCount(2, $this->supervisorChecks(), 'each rehearsal asks again — the answer is not cached');
    }

    public function test_a_dry_run_is_skipped_when_another_instance_holds_the_lock(): void
    {
        $flipper = new PgcatConfigFlipper(
            resolver: $this->resolver('readers'),
            config: $this->pgcatConfig(),
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            lockAcquirer: function (string $file): array {
                $this->lockLog[] = [$file, false];
                return [false, null];
            },
            lockReleaser: fn ($fp) => null,
        );

        $result = $flipper->dryRun();

        $this->assertSame('skipped', $result->kind());
        $this->assertSame(0, $result->exitCode(), 'a flip would be skipped too, which is not a fault');
        $this->assertSame('another flipper instance holds the lock', $result->reason);
        $this->assertSame('refused', $result->step('lock')['outcome']);
        $this->assertNull($result->step('read source'), 'it stopped at the lock');
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_a_dry_run_on_a_disabled_flipper_does_not_even_take_the_lock(): void
    {
        $flipper = $this->build('readers', ['enabled' => false]);
        $result  = $flipper->dryRun();

        $this->assertSame('would_not_flip', $result->kind());
        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('swrr.pgcat.enabled = false', $result->reason);
        $this->assertSame('refused', $result->step('gates')['outcome']);
        $this->assertSame([], $this->lockLog, 'a flip would decline before it takes the lock');
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_a_forced_dry_run_rehearses_the_file_force_mode_would_apply(): void
    {
        // The resolver is in readers mode and the record says readers was applied; a forced
        // rehearsal has to bypass both, exactly as forceMode() does.
        file_put_contents($this->stateFile, json_encode(['last_mode' => 'readers']));

        $flipper = $this->build('readers');
        $result  = $flipper->dryRun('writer');

        $this->assertSame('would_flip', $result->kind());
        $this->assertSame('writer', $result->mode);
        $this->assertSame('readers', $result->previousMode);
        $this->assertStringContainsString('pgcat-no-readers.toml', $result->step('read source')['detail']);
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->flipCommands());
    }

    public function test_a_forced_dry_run_declines_on_a_driver_pgcat_cannot_front(): void
    {
        $flipper = $this->build('readers', [], driver: 'mysql', connectionName: 'mysql_app');
        $result  = $flipper->dryRun('writer');

        $this->assertSame('would_not_flip', $result->kind());
        $this->assertSame('writer', $result->mode, 'the rehearsal is about the mode that was asked for');
        $this->assertStringContainsString('PostgreSQL-only', $result->reason);
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    /**
     * swap() throws when the source cannot be read. A rehearsal cannot: it exists to be
     * asked, and "a flip would fail here" is the answer, not an exception.
     */
    public function test_a_dry_run_reports_an_unreadable_source_instead_of_throwing(): void
    {
        $flipper = $this->build('readers', [
            'readers_path' => $this->tmp . '/not-there.toml',
        ]);

        $result = $flipper->dryRun();

        $this->assertSame('failed', $result->kind());
        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('Source pgcat config not readable', (string) $result->error);
        $this->assertSame('failed', $result->step('read source')['outcome']);
        $this->assertNull($result->step('write temp'));
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->cmdLog);
    }

    public function test_a_dry_run_reports_empty_paths_as_a_failure(): void
    {
        $flipper = $this->build('readers', ['config_path' => '', 'no_readers_path' => '']);
        $result  = $flipper->dryRun();

        $this->assertSame('failed', $result->kind());
        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('set swrr.pgcat.{config_path,readers_path,no_readers_path}', (string) $result->error);
        $this->assertSame('failed', $result->step('read source')['outcome']);
    }

    /**
     * The one failure a metadata check cannot find: the target's own directory refusing the
     * temp file the swap writes. Here the "directory" is a file, which is how a path that
     * looks writable stops being one — an ACL, a read-only mount or a full disk do the same
     * thing silently.
     */
    public function test_a_dry_run_fails_where_a_flip_could_not_write_its_temp_file(): void
    {
        file_put_contents($this->tmp . '/blocked', 'not a directory');

        $flipper = $this->build('readers', ['config_path' => $this->tmp . '/blocked/pgcat.toml']);
        $result  = $flipper->dryRun();

        $this->assertSame('failed', $result->kind());
        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('could not be created', (string) $result->error);
        $this->assertSame('done', $result->step('read source')['outcome'], 'the read worked — the write did not');
        $this->assertSame('failed', $result->step('write temp')['outcome']);
        $this->assertNull($result->step('rename'));
        $this->assertSame([], $this->copyLog);
        $this->assertSame([], $this->flipCommands());
    }

    /**
     * A flip's state write is silenced with @, so an unwritable state file produces a flip
     * that reports success and then repeats on every poll, restarting pgcat each time. The
     * rehearsal names that, and still reports the steps a flip would get through first.
     */
    public function test_a_dry_run_fails_when_the_state_file_cannot_be_written(): void
    {
        file_put_contents($this->tmp . '/blocked', 'not a directory');
        $this->stateFile = $this->tmp . '/blocked/state.json';

        $flipper = $this->build('readers');
        $result  = $flipper->dryRun();

        $this->assertSame('failed', $result->kind());
        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('repeat on every poll', (string) $result->error);
        $this->assertSame('failed', $result->step('state file')['outcome']);
        $this->assertSame('would', $result->step('rename')['outcome'], 'the flip would get that far');
        $this->assertSame('would', $result->step('supervisor')['outcome']);
        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertSame([], $this->flipCommands(), 'the command the state failure would follow was never run either');
    }

    public function test_a_dry_run_says_what_the_rename_would_do_to_the_target(): void
    {
        // Already the source's bytes: not a fault, but worth saying — an operator who
        // applied the file by hand is about to replace it with itself.
        file_put_contents($this->tmp . '/pgcat.toml', "pool = 'readers'\n");

        $identical = $this->build('readers')->dryRun();

        $this->assertSame('would_flip', $identical->kind(), 'an identical target is not a reason to skip a flip');
        $this->assertStringContainsString('already holds these bytes', $identical->step('rename')['detail']);

        @unlink($this->tmp . '/pgcat.toml');

        $missing = $this->build('readers')->dryRun();

        $this->assertStringContainsString('does not exist yet', $missing->step('rename')['detail']);
    }

    private function resolver(string $mode): TimeWindowResolver
    {
        return $mode === 'readers'
            ? new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            )
            : new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '00:00:01']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function pgcatConfig(): array
    {
        return [
            'enabled' => true,
            'config_path' => $this->tmp . '/pgcat.toml',
            'readers_path' => $this->tmp . '/pgcat-readers.toml',
            'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
            'restart_command' => 'supervisorctl restart pgcat',
            'reload_command' => 'supervisorctl signal HUP pgcat',
            'use_reload' => false,
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function driverProvider(): array
    {
        return [
            'pgsql'              => ['pgsql', true],
            'postgres alias'     => ['postgres', true],
            'postgresql alias'   => ['postgresql', true],
            'unusual casing'     => ['PostgreSQL', true],
            'mysql'              => ['mysql', false],
            'mariadb'            => ['mariadb', false],
            'sqlite'             => ['sqlite', false],
            'sqlsrv'             => ['sqlsrv', false],
        ];
    }

    #[DataProvider('driverProvider')]
    public function test_only_postgresql_drivers_keep_pgcat_enabled(string $driver, bool $supported): void
    {
        $flipper = $this->build('readers', [], driver: $driver);

        $this->assertSame($supported, $flipper->status()['driver_supported']);
        $this->assertSame($supported, $flipper->isEnabled());
    }
}

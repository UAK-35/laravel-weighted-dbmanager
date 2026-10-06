<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\FlipResult;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Support\ActiveConnection;
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
            static fn (array $entry): bool => !self::isSupervisorCheck($entry[0]),
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
            static fn (array $entry): bool => self::isSupervisorCheck($entry[0]),
        ));
    }

    /**
     * Whether a command is one of the read-only questions a flip asks before it swaps a file:
     * `supervisorctl status "<program>"`, and — after an unknown program — the bare
     * `supervisorctl status` that lists what supervisord is running.
     *
     * Both are checks and neither is the flip's own command, which is what the two filters above
     * are for: a flip that never ran its reload must not look as though it did because the
     * refusal asked supervisor one more question.
     */
    private static function isSupervisorCheck(string $command): bool
    {
        return str_contains($command, ' status ') || str_ends_with(trim($command), ' status');
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
        ?string $connectionSource = null,
        ?string $stateFile = null,
        ?string $lockFile = null,
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
            stateFile: $stateFile ?? $this->stateFile,
            lockFile: $lockFile ?? $this->lockFile,
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
            connectionSource: $connectionSource ?? ActiveConnection::DEFAULT_SOURCE,
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
        // Two answers: the program is not supervisor's, and the list of what is — the second
        // question the refusal asks, because the repair for this fault is a name.
        $flipper = $this->build('readers', runner: fn (string $command): array => match (true) {
            self::isSupervisorCheck($command) && !str_ends_with(trim($command), ' status') => [2, 'pgcat: ERROR (no such group)', ''],
            self::isSupervisorCheck($command) => [0, "pgcat_primary   RUNNING   pid 1\n", ''],
            default => [0, '', ''],
        });

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('no such group', (string) $result->error);
        $this->assertStringContainsString('refuses before it swaps the file', (string) $result->error);
        $this->assertStringContainsString('the closest is "pgcat_primary", which is the name to write', (string) $result->error, 'the refusal an operator acts on names the program to write instead');
        $this->assertCount(0, $this->copyLog, 'the target was never replaced');
        $this->assertCount(0, $this->flipCommands(), 'the command was never run');
        $this->assertSame("pool = 'unknown'\n", file_get_contents($this->tmp . '/pgcat.toml'));
        $this->assertNoModeRecorded('no mode was recorded, so the next poll tries again');
    }

    /**
     * The invariant behind "no mode was recorded": `last_mode` is what makes the next poll skip,
     * so a run that did not apply one must not write it — whatever else the run records.
     *
     * The file itself may exist without `last_mode`, and since the boot window landed it usually
     * does: a failed run records its own bookkeeping (`runs`, `last_kind`, `last_run_at`) so
     * `/health/db` can tell "the flip ran and kept failing" from "the flip was never scheduled",
     * which is the whole difference between a broken pooler and a broken cron. Asserting on the
     * key rather than on the file's absence is what keeps both facts testable at once.
     */
    private function assertNoModeRecorded(string $message = ''): void
    {
        if (!is_file($this->stateFile)) {
            $this->assertFileDoesNotExist($this->stateFile, $message);

            return;
        }

        $state = json_decode((string) file_get_contents($this->stateFile), true);

        $this->assertIsArray($state, $message);
        $this->assertArrayNotHasKey('last_mode', $state, $message);
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
        $this->assertNoModeRecorded('a rollback puts the config back, so no mode was applied');
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

    // ─────────────────────────────────────────────────────────────────────────
    // A pgcat that is known and down: the flip repairs it instead of refusing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The case that stopped a deployment: pgcat is crash-looping, so
     * `supervisorctl status "pgcat:*"` exits non-zero, and reading that as "supervisorctl could
     * not answer" refuses the flip in the one situation where the file it would write is the
     * fix — leaving a dead pooler and a config nothing will replace.
     *
     * The repair has to be the other verb as well: `restart` and `signal HUP` act on a program
     * that is up, so the flip starts pgcat and then reads its state back.
     */
    public function test_a_flip_starts_a_pgcat_that_is_fatal_instead_of_refusing(): void
    {
        $state = 'FATAL';
        $starts = 0;

        $flipper = $this->build('readers', runner: function (string $command) use (&$state, &$starts): array {
            if (self::isSupervisorCheck($command)) {
                return [strtoupper($state) === 'RUNNING' ? 0 : 3, "pgcat:pgcat_00   {$state}   Exited too quickly (process log may have details)", ''];
            }

            $starts++;
            $state = 'RUNNING';

            return [0, 'pgcat:pgcat_00: started', ''];
        });

        $result = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertSame(1, $starts, 'the repair starts pgcat once');
        $this->assertCount(1, $this->copyLog, 'the writer-only file goes in first — it is the repair');
        $this->assertSame("pool = 'readers'\n", file_get_contents($this->tmp . '/pgcat.toml'));
        $this->assertSame('supervisorctl start "pgcat"', $this->flipCommands()[0][0], 'start, not the configured restart');
        $this->assertSame(
            'readers',
            json_decode((string) file_get_contents($this->stateFile), true)['last_mode'],
            'a repair that comes up is a flip like any other',
        );
    }

    /**
     * The failure half, and the one place the rollback is deliberately not taken: the file on
     * disk *is* the repair, so putting the previous config back would restore exactly the variant
     * pgcat could not start on. The state file stays unwritten too, which is what makes the next
     * poll retry instead of concluding the mode is already in place.
     */
    public function test_a_repair_that_cannot_start_pgcat_keeps_the_new_config(): void
    {
        $flipper = $this->build('readers', [
            'start_attempts' => 2,
            'start_retry_delay_ms' => 0,
        ], runner: fn (string $command): array => self::isSupervisorCheck($command)
            ? [3, 'pgcat:pgcat_00   BACKOFF   Exited too quickly (process log may have details)', '']
            : [1, '', 'ERROR (spawn error)']);

        $result = $flipper->applyCurrentState();

        $this->assertSame('failed', $result->kind());
        $this->assertStringContainsString('did not come up after 2 attempt(s)', (string) $result->error);
        $this->assertStringContainsString('the new config was left in place', (string) $result->error);
        $this->assertStringNotContainsString('the previous config was put back', (string) $result->error);

        $this->assertCount(1, $this->copyLog);
        $this->assertSame("pool = 'readers'\n", file_get_contents($this->tmp . '/pgcat.toml'), 'the repair is not undone');
        $this->assertNoModeRecorded('no mode recorded, so the next poll tries again');
        $this->assertCount(2, $this->flipCommands(), 'both attempts ran');
    }

    /**
     * `STARTING` is a program on its way up, not a broken one — and the read-back after each
     * start is the only thing that tells the two apart, which is why the attempts exist rather
     * than a single optimistic command.
     */
    public function test_a_repair_retries_until_pgcat_reads_back_as_running(): void
    {
        $starts = 0;
        $running = false;

        $flipper = $this->build('readers', [
            'start_attempts' => 3,
            'start_retry_delay_ms' => 0,
        ], runner: function (string $command) use (&$starts, &$running): array {
            if (self::isSupervisorCheck($command)) {
                return $running
                    ? [0, 'pgcat:pgcat_00   RUNNING   pid 4242', '']
                    : [3, 'pgcat:pgcat_00   STARTING', ''];
            }

            $starts++;

            if ($starts >= 3) {
                $running = true;

                return [0, 'pgcat:pgcat_00: started', ''];
            }

            return [1, '', 'ERROR (spawn error)'];
        });

        $result = $flipper->applyCurrentState();

        $this->assertSame('flipped', $result->kind());
        $this->assertSame(3, $starts, 'the attempts are bounded, and the third one is the one that took');
        $this->assertFileExists($this->stateFile);
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
    // The files a flip needs: the state each one is in, and the repair it reduces to
    //
    // `db:doctor`'s `pgcat files` row prints this list and the boot audit records a finding
    // from it, so both halves are pinned here — which state each unusable file is in, and the
    // line the package can state for it: a mode for a permission, the value the published
    // config ships for a key that is empty, and nothing at all where the value is this
    // installation's to choose.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The repair for an empty key is a second copy of a default unless it is read from the file
     * that ships the default, so the published config is parsed here and compared with it. An
     * operator pastes the line this test checks, which is why both halves failing together is the
     * point rather than an inconvenience.
     */
    public function test_the_documented_paths_are_the_ones_the_published_config_ships(): void
    {
        $sample = (string) file_get_contents(dirname(__DIR__, 3).'/config/db-manager.php');

        preg_match("/'config_path'\\s*=>\\s*env\\(\\s*'SWRR_PGCAT_CONFIG'\\s*,\\s*'([^']+)'/", $sample, $config);
        preg_match("/'state_file'\\s*=>\\s*sys_get_temp_dir\\(\\)\\s*\\.\\s*'([^']+)'/", $sample, $state);
        preg_match("/'lock_file'\\s*=>\\s*sys_get_temp_dir\\(\\)\\s*\\.\\s*'([^']+)'/", $sample, $lock);

        $this->assertNotSame('', $config[1] ?? '', 'the sample no longer declares config_path in the shape this reads');
        $this->assertNotSame('', $state[1] ?? '', 'the sample no longer declares state_file in the shape this reads');
        $this->assertNotSame('', $lock[1] ?? '', 'the sample no longer declares lock_file in the shape this reads');

        $documented = PgcatConfigFlipper::documentedPaths();

        $this->assertSame($config[1], $documented['config_path']);
        $this->assertSame(sys_get_temp_dir().$state[1], $documented['state_file']);
        $this->assertSame(sys_get_temp_dir().$lock[1], $documented['lock_file']);
    }

    public function test_an_empty_key_carries_the_published_value_only_where_the_config_ships_one(): void
    {
        // `readers_path` and `no_readers_path` are the two the README documents as `—`: the
        // published config shows an example of the *shape*, and a flip pointed at a file that is
        // not there is worse off than one with an empty column, because it looks like an answer.
        $flipper = $this->build(
            'readers',
            ['config_path' => '', 'readers_path' => '', 'no_readers_path' => ''],
            stateFile: '',
            lockFile: '',
        );

        $lines = [];

        foreach ($flipper->fileProblems() as $problem) {
            $lines[$problem['setting']] = $problem['suggestion'];
        }

        $this->assertSame(
            [
                'readers_path' => null,
                'no_readers_path' => null,
                'config_path' => 'swrr.pgcat.config_path = '.var_export('/etc/pgcat/pgcat.toml', true),
                'state_file' => 'swrr.pgcat.state_file = '.var_export(sys_get_temp_dir().'/pgcat-flip-state.json', true),
                'lock_file' => 'swrr.pgcat.lock_file = '.var_export(sys_get_temp_dir().'/pgcat-flip.lock', true),
            ],
            $lines,
            'every empty key is a problem, and only the three with a published value are repairable',
        );
    }

    public function test_a_directory_that_will_not_take_a_write_carries_the_mode_that_would(): void
    {
        // A path already occupied by a regular file can never be the directory beside it, so this
        // holds on every filesystem — no mode bits involved. The line adds write *and* traverse:
        // the temp file goes into the directory and the rename that finishes the swap acts on it.
        $dir = $this->tmp.'/blocked';
        file_put_contents($dir, '');

        $flipper = $this->build(
            'readers',
            ['config_path' => $dir.'/pgcat.toml'],
            stateFile: $dir.'/state.json',
            lockFile: $dir.'/flip.lock',
        );

        $problems = [];

        foreach ($flipper->fileProblems() as $problem) {
            $problems[$problem['setting']] = [$problem['kind'], $problem['suggestion']];
        }

        $line = 'chmod +wx '.escapeshellarg($dir);

        $this->assertSame([PgcatConfigFlipper::FILE_DIRECTORY_UNWRITABLE, $line], $problems['config_path']);
        $this->assertSame([PgcatConfigFlipper::FILE_DIRECTORY_UNWRITABLE, $line], $problems['state_file']);
        $this->assertSame([PgcatConfigFlipper::FILE_DIRECTORY_UNWRITABLE, $line], $problems['lock_file']);
    }

    /**
     * A file that is not there gets no line at all, and this half is asserted on every
     * filesystem — no mode bits are involved, so nothing about the environment can skip it.
     */
    public function test_a_file_that_is_not_there_gets_no_line(): void
    {
        $gone = $this->build('readers', ['readers_path' => $this->tmp.'/gone.toml']);

        $problems = [];

        foreach ($gone->fileProblems() as $problem) {
            $problems[$problem['setting']] = $problem;
        }

        $this->assertSame(PgcatConfigFlipper::FILE_MISSING, $problems['readers_path']['kind']);
        $this->assertNull(
            $problems['readers_path']['suggestion'],
            'the file has to be put there by whatever installs pgcat, and the package does not know where it went',
        );
    }

    public function test_a_file_that_cannot_be_read_carries_the_mode_that_would(): void
    {
        // The other half: the path is known and the bit that failed is known, so the repair is a
        // mode. Skipped where the environment ignores mode bits — the check itself has to fail
        // before the line it prints can be asked about — which is why this case is its own test
        // rather than the tail of the deterministic one above.
        @chmod($this->tmp.'/pgcat-readers.toml', 0o000);

        if (is_readable($this->tmp.'/pgcat-readers.toml')) {
            $this->markTestSkipped('This environment cannot make a file unreadable.');
        }

        $unreadable = $this->build('readers');

        foreach ($unreadable->fileProblems() as $problem) {
            if ($problem['setting'] === 'readers_path') {
                $this->assertSame(PgcatConfigFlipper::FILE_UNREADABLE, $problem['kind']);
                $this->assertSame('chmod +r '.escapeshellarg($this->tmp.'/pgcat-readers.toml'), $problem['suggestion']);

                return;
            }
        }

        $this->fail('a source that cannot be read was not reported at all');
    }

    /**
     * The three modes the row can print, asked of the rule rather than of a filesystem.
     *
     * The unreadable case above has to skip where the environment ignores mode bits — the check has
     * to fail before the line it prints can be asked about — so on such a platform a `+r` line that
     * was never printed would go unnoticed. This asks `suggestionForFileProblem()` for the line of a
     * *kind* and a *path*, which involves no file at all, so every mode is covered everywhere.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fileModeProblems(): array
    {
        return [
            'a source that cannot be read' => ['readers_path', PgcatConfigFlipper::FILE_UNREADABLE, '+r'],
            'a file that cannot be written' => ['config_path', PgcatConfigFlipper::FILE_UNWRITABLE, '+w'],
            'a directory a flip writes into' => ['state_file', PgcatConfigFlipper::FILE_DIRECTORY_UNWRITABLE, '+wx'],
        ];
    }

    #[DataProvider('fileModeProblems')]
    public function test_each_file_mode_problem_carries_the_bit_that_failed(
        string $setting,
        string $kind,
        string $mode,
    ): void {
        $path = $this->tmp.'/pgcat-readers.toml';
        $suggest = new \ReflectionMethod(PgcatConfigFlipper::class, 'suggestionForFileProblem');

        $this->assertSame(
            'chmod '.$mode.' '.escapeshellarg($path),
            $suggest->invoke($this->build('readers'), $setting, $kind, $path),
        );
    }

    public function test_an_inert_flipper_has_no_file_preconditions_at_all(): void
    {
        // Nothing reads or writes a pgcat file while the flipper is inert, so there is no
        // precondition to judge and no reason to require a path to exist — the same reason the row
        // passes and the same reason the boot audit resolves a finding it recorded while armed.
        $flipper = $this->build('readers', ['enabled' => false, 'config_path' => '', 'readers_path' => '']);

        $this->assertSame([], $flipper->fileProblems());
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

    /**
     * A mismatch sentence names the setting that chose the connection, so an operator edits
     * the key the flipper actually read. An installation that named its PostgreSQL path via
     * `swrr.connection` (a non-default connection, e.g. `app.default_api_connection`) used to
     * be told to edit `database.default`, which it does not follow.
     */
    public function test_the_mismatch_sentence_names_the_key_the_connection_came_from(): void
    {
        $byDefault = (string) $this->build(
            'readers',
            [],
            driver: 'mysql',
            connectionName: 'mysql_app',
        )->armingWarning();

        $this->assertStringContainsString(
            'point database.default at the pgcat-fronted connection',
            $byDefault,
        );

        $configured = (string) $this->build(
            'readers',
            [],
            driver: 'mysql',
            connectionName: 'mysql_app',
            connectionSource: ActiveConnection::CONFIGURED_SOURCE,
        )->armingWarning();

        $this->assertStringContainsString(
            'point db-manager.swrr.connection at the pgcat-fronted connection',
            $configured,
        );
        $this->assertStringNotContainsString('point database.default', $configured);
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

    // ─────────────────────────────────────────────────────────────────────────
    // The boot window: bounded attempts, and the verdict that outlives them

    /**
     * Write the container's boot stamp the way `entrypoint.sh` writes it — bare unix seconds —
     * and answer the path the flipper was configured with.
     */
    private function stampBoot(float $when): string
    {
        $file = $this->tmp . '/container-booted-at';
        file_put_contents($file, (string) (int) $when);

        return $file;
    }

    /**
     * A closed window is a decision, not a bad run: the command exits 0, nothing is written, and
     * supervisor is not even asked. That last one is the point of checking the window before the
     * lock — a container that has stopped trying must not look busy, and a minute of every one of
     * its remaining minutes must not be spent on a question whose answer cannot change.
     */
    public function test_a_closed_window_stops_the_flip_without_touching_anything(): void
    {
        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot(time() - 3600),
            'flip_window_seconds' => 480,
        ]);

        $result = $flipper->applyCurrentState();

        $this->assertSame(FlipResult::KIND_WINDOW_CLOSED, $result->kind());
        $this->assertSame(0, $result->exitCode(), 'the run did what it should, so a scheduler must not see a failure');
        $this->assertStringContainsString('window closed (mode=readers)', $result->summary());
        $this->assertStringContainsString('no further attempts are made until it is replaced', $result->summary());

        $this->assertSame([], $this->copyLog, 'no config was copied');
        $this->assertSame([], $this->cmdLog, 'supervisor was asked nothing at all');
        $this->assertSame([], $this->lockLog, 'the lock is not taken by a run that cannot act');
        $this->assertFileDoesNotExist($this->stateFile, 'a run after the window must not record a mode or a run');
    }

    /**
     * The case most likely to be broken by a window: a container with no stamp. A local run, and
     * an image whose entrypoint predates the stamp, both land here, and both have to keep flipping
     * — "no boot time" is not "a boot time that has passed".
     */
    public function test_a_window_without_a_boot_stamp_is_not_judged_and_the_flip_still_runs(): void
    {
        $flipper = $this->build('readers', [
            'boot_file' => $this->tmp . '/never-written',
            'flip_window_seconds' => 480,
        ]);

        $result = $flipper->applyCurrentState();

        $this->assertSame(FlipResult::KIND_FLIPPED, $result->kind());
        $this->assertNotSame([], $this->copyLog, 'the flip ran');
        $this->assertFalse($flipper->windowStatus()['failed'], 'nothing was recorded, so nothing is judged');
        $this->assertSame(FlipWindow::SOURCE_NO_STAMP, $flipper->windowStatus()['source']);
    }

    /**
     * A run inside the window reaching a usable mode is convergence, and convergence is what
     * makes a closed window not a failure. `runs` is asserted too: it is the only field that makes
     * "the flip is scheduled at all" observable from inside the container, which is the difference
     * between a pooler that cannot come up and a cron nobody wired.
     */
    public function test_a_run_inside_the_window_converges_the_container(): void
    {
        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot(time()),
            'flip_window_seconds' => 480,
        ]);

        $this->assertSame(FlipResult::KIND_FLIPPED, $flipper->applyCurrentState()->kind());

        $window = $flipper->windowStatus();

        $this->assertTrue($window['converged']);
        $this->assertSame('readers', $window['converged_mode']);
        $this->assertSame(1, $window['runs']);
        $this->assertSame(FlipResult::KIND_FLIPPED, $window['last_kind']);
        $this->assertFalse($window['closed'], 'the window is measured from boot, and this container just booted');
        $this->assertFalse($window['failed']);
        $this->assertNull($window['failed_reason'], 'a container still inside its window is starting up, not broken');
    }

    /**
     * The verdict `/health/db` acts on, told apart from "not scheduled": no run was ever recorded, so
     * the reason names the schedule rather than the pooler.
     */
    public function test_a_window_that_closed_with_no_runs_blames_the_missing_schedule(): void
    {
        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot(time() - 3600),
            'flip_window_seconds' => 480,
        ]);

        $window = $flipper->windowStatus();

        $this->assertTrue($window['closed']);
        $this->assertTrue($window['failed']);
        $this->assertFalse($window['converged']);
        $this->assertSame(0, $window['runs']);
        $this->assertStringContainsString('never ran', (string) $window['failed_reason']);
        $this->assertStringContainsString('is not being scheduled', (string) $window['failed_reason']);
    }

    /**
     * Runs that happened and never reached a usable mode: the pooler is the problem, and the reason
     * says so instead of repeating the scheduler's sentence.
     */
    public function test_a_window_that_closed_with_failing_runs_says_the_pooler_is_the_problem(): void
    {
        $booted = time() - 3600;

        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot($booted),
            'flip_window_seconds' => 480,
        ]);

        file_put_contents($this->stateFile, (string) json_encode([
            'last_mode' => null,
            'runs' => 4,
            'last_run_at' => gmdate(DATE_ATOM, $booted + 240),
            'last_kind' => FlipResult::KIND_FAILED,
        ]));

        $window = $flipper->windowStatus();

        $this->assertTrue($window['failed']);
        $this->assertSame(4, $window['runs']);
        $this->assertStringContainsString('has not reached a usable mode', (string) $window['failed_reason']);
        $this->assertStringNotContainsString('is not being scheduled', (string) $window['failed_reason']);
    }

    /**
     * A flip that succeeded, but only after the window had closed. The container did come up — and
     * that is exactly why this is a failure rather than a pass: the window is about whether the
     * pooler was serving while it mattered, and a late success does not un-fail the minutes it was
     * not. `converged_at` is written once and never moved for the same reason.
     */
    public function test_a_flip_that_only_converged_after_the_window_does_not_pass_the_container(): void
    {
        $booted = time() - 3600;

        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot($booted),
            'flip_window_seconds' => 480,
        ]);

        file_put_contents($this->stateFile, (string) json_encode([
            'last_mode' => 'readers',
            'runs' => 3,
            'last_run_at' => gmdate(DATE_ATOM, $booted + 1800),
            'last_kind' => FlipResult::KIND_FLIPPED,
            'converged_at' => gmdate(DATE_ATOM, $booted + 1800),
            'converged_mode' => 'readers',
        ]));

        $window = $flipper->windowStatus();

        $this->assertFalse($flipper->window()->contains((float) ($booted + 1800)));
        $this->assertFalse($window['converged'], 'converged_at outside the window is not convergence');
        $this->assertTrue($window['failed']);
        $this->assertStringContainsString('every flip run inside the window failed', (string) $window['failed_reason']);
    }

    /**
     * The window the flipper was built with is the one `db:doctor` and a deploy rehearsal read
     * through `window()`, rather than each surface re-deriving it from the config — which is how
     * two readers end up answering one question differently.
     */
    public function test_the_configured_window_is_the_one_the_flipper_carries(): void
    {
        $flipper = $this->build('readers', [
            'boot_file' => $this->stampBoot(1000000),
            'flip_window_seconds' => 600,
        ]);

        $this->assertSame(600, $flipper->window()->seconds());
        $this->assertSame($this->tmp . '/container-booted-at', $flipper->window()->bootFile());

        // And the default when the published config predates the key, which is how every install
        // that has not republished arrives.
        $this->assertSame(480, $this->build('readers')->window()->seconds());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // In step with the window: what pgcat's file holds, versus what the windows ask
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The mode is read off pgcat's own file, because that is the only thing an operator can check
     * by hand — and the only thing that survives a flip which aborted before it recorded
     * anything. `last_mode` is exactly the field that is empty in that case, so a verdict built on
     * it would have answered "unknown" on the container this was written for.
     */
    public function test_the_applied_mode_is_read_off_the_file_rather_than_the_record(): void
    {
        $flipper = $this->build('readers');

        // The fixture's target matches neither variant: it is a file this package did not put
        // there, and the answer for it is null rather than the nearer-looking of the two.
        $this->assertNull($flipper->appliedMode());

        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-no-readers.toml'));
        $this->assertSame('writer', $flipper->appliedMode());

        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-readers.toml'));
        $this->assertSame('readers', $flipper->appliedMode());
    }

    public function test_a_target_that_is_not_there_has_no_applied_mode(): void
    {
        $flipper = $this->build('readers', ['config_path' => $this->tmp . '/not-there.toml']);

        $this->assertNull($flipper->appliedMode());
    }

    /**
     * The failure this was written for: a container booted outside a window converged to the
     * writer-only config and then stopped tracking the windows, so when one opened the pooler
     * stayed as it was. The boot window says the flip converged; this says it stopped.
     */
    public function test_a_pooler_left_writer_only_inside_a_reader_window_is_a_failure(): void
    {
        // Booted an hour ago, so the boot window is closed and the check is judging the file
        // rather than a container that is still starting up.
        $flipper = $this->build('readers', ['boot_file' => $this->stampBoot(time() - 3600)]);
        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-no-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('readers', $verdict['expected']);
        $this->assertSame('writer', $verdict['applied']);
        $this->assertFalse($verdict['in_step']);
        $this->assertTrue($verdict['failed']);

        // The sentence names the file that is in place and the command that moves it — the two
        // things an operator acts on — and no guess at why the flip stopped.
        $reason = (string) $verdict['reason'];
        $this->assertStringContainsString('reader window is open', $reason);
        $this->assertStringContainsString('pgcat-no-readers.toml', $reason);
        $this->assertStringContainsString('db:pgcat-flip', $reason);

        // It travels with the snapshot, so /health/db, db:replica-status and db:pgcat-flip
        // --status cannot read two different answers to it.
        $this->assertSame($verdict, $flipper->healthSummary()['reader_window']);
    }

    public function test_a_pooler_in_step_with_the_window_is_not_a_failure(): void
    {
        $flipper = $this->build('readers', ['boot_file' => $this->stampBoot(time() - 3600)]);
        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('readers', $verdict['expected']);
        $this->assertSame('readers', $verdict['applied']);
        $this->assertTrue($verdict['in_step']);
        $this->assertFalse($verdict['failed']);
        $this->assertNull($verdict['reason']);
    }

    /**
     * The mirror disagreement, and now the same fault as the one above: outside a window the file
     * still holds the readers variant, so every read through the pooler reaches a replica during
     * the hours the fallback exists to keep them on the writer. It is also the same evidence —
     * the mode the windows asked for at the end of the last window is the one that never arrived,
     * and the file is what proves it.
     */
    public function test_a_pooler_left_on_the_readers_config_outside_a_window_is_a_failure(): void
    {
        $flipper = $this->build('writer', ['boot_file' => $this->stampBoot(time() - 3600)]);
        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('writer', $verdict['expected']);
        $this->assertSame('readers', $verdict['applied']);
        $this->assertFalse($verdict['in_step'], 'a reader can still see the disagreement');
        $this->assertTrue($verdict['failed'], '...and outside a window it costs the same as inside one');

        // The sentence names which side of the window this is, the file in place, and the command
        // that moves it — the three things an operator acts on.
        $reason = (string) $verdict['reason'];
        $this->assertStringContainsString('no reader window is open', $reason);
        $this->assertStringContainsString('pgcat-readers.toml', $reason);
        $this->assertStringContainsString('db:pgcat-flip', $reason);
    }

    /**
     * A file matching neither variant — an operator's own edit, a half-written file — is
     * `in_step: null` rather than an invented verdict, the same line `ReaderWindows` draws when it
     * refuses to guess at what a malformed setting meant.
     */
    public function test_a_file_that_matches_neither_variant_is_not_judged(): void
    {
        $flipper = $this->build('readers', ['boot_file' => $this->stampBoot(time() - 3600)]);   // the fixture's target is `pool = 'unknown'`

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('readers', $verdict['expected']);
        $this->assertNull($verdict['applied']);
        $this->assertNull($verdict['in_step']);
        $this->assertFalse($verdict['failed']);
        $this->assertNull($verdict['reason']);
    }

    /**
     * Nothing was asked of the pooler, so there is no expectation for it to be out of step with —
     * even with the writer-only file in place. This is the case the driver gate exists for.
     */
    public function test_an_inert_flipper_has_no_window_verdict(): void
    {
        $flipper = $this->build('readers', [], driver: 'sqlite');
        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-no-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('readers', $verdict['expected']);
        $this->assertNull($verdict['applied'], 'the file is not even read for a pooler this package does not front');
        $this->assertNull($verdict['in_step']);
        $this->assertFalse($verdict['failed']);
    }

    /**
     * A container whose boot window is still open is starting up, not out of step: a task that
     * begins inside a reader window is on the writer-only config until its first flip lands, and
     * failing that would fail every healthy deploy for as long as the pooler takes to converge.
     * The file is still reported — `applied` is there to be read — and only the verdict waits.
     */
    public function test_a_container_still_inside_its_boot_window_is_not_judged_on_the_file(): void
    {
        $flipper = $this->build('readers', ['boot_file' => $this->stampBoot(time() - 60)]);
        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-no-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertFalse($flipper->window()->closed(), 'the premise: the window is still open');
        $this->assertSame('writer', $verdict['applied'], 'the file is still reported');
        $this->assertFalse($verdict['in_step']);
        $this->assertFalse($verdict['failed'], '...but a starting container is not a failure');
        $this->assertNull($verdict['reason']);
    }

    /**
     * With no windows configured the resolver is permissive on purpose — the documented opt-out —
     * so there is no window to be inside of, and a pooler the setting leaves alone is not a pooler
     * that is wrong. The check stays quiet rather than inventing a window to fail against.
     */
    public function test_a_permissive_resolver_has_no_window_to_judge(): void
    {
        $flipper = new PgcatConfigFlipper(
            resolver: new TimeWindowResolver(readerWindows: [], readerDays: [1, 2, 3, 4, 5]),
            config: [
                'enabled' => true,
                'config_path' => $this->tmp . '/pgcat.toml',
                'readers_path' => $this->tmp . '/pgcat-readers.toml',
                'no_readers_path' => $this->tmp . '/pgcat-no-readers.toml',
            ],
            stateFile: $this->stateFile,
            lockFile: $this->lockFile,
            driver: 'pgsql',
        );

        file_put_contents($this->tmp . '/pgcat.toml', (string) file_get_contents($this->tmp . '/pgcat-no-readers.toml'));

        $verdict = $flipper->readerWindowVerdict();

        $this->assertSame('readers', $verdict['expected'], 'permissive means always readers');
        $this->assertNull($verdict['applied']);
        $this->assertFalse($verdict['failed']);
    }
}

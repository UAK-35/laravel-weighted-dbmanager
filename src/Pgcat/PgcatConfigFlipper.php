<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\SwitchValue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Flips pgcat.toml between reader-enabled and writer-only configurations
 * in lock-step with TimeWindowResolver's mode.
 *
 * IDEMPOTENCY
 * -----------
 * On every call to applyCurrentState() the resolver is consulted for the
 * current mode. The result is compared against the previously-recorded
 * mode in the state file. If they match, the method returns FlipResult::noChange()
 * WITHOUT touching the filesystem or supervisor. So a 30-second poll on a
 * day with no transitions performs zero work.
 *
 * RACE PROTECTION
 * ---------------
 * The state file is mutex-protected via flock(LOCK_EX|LOCK_NB). Concurrent
 * callers (cron overlap, multiple CLI triggers, supervisor + manual ops)
 * contend for the same lock — losers return FlipResult::skipped() instead
 * of duplicating the supervisorctl call.
 *
 * ATOMIC FILE SWAP
 * ----------------
 *   1. Read source file contents into memory
 *   2. Write to {target}.tmp.{pid}
 *   3. rename() → atomic on the same filesystem (POSIX guarantees)
 *   4. supervisorctl restart "pgcat:*"
 *
 * The program name is quoted because the command is a shell command line: an unquoted
 * `pgcat:*` is a glob, and the shell decides what supervisorctl receives. `pgcat:*`
 * names every program in a supervisor group called pgcat — matching the
 * `[program:pgcat]` section this package is written for — and the name has to match the
 * group the operator actually runs, so both commands stay configurable.
 *
 * If pgcat reads the file between steps 2 and 3, it sees a complete file
 * (the temp one). If it reads after step 3, it sees the new config.
 * Either way, no torn reads.
 *
 * POSTGRESQL ONLY
 * ---------------
 * pgcat only ever fronts PostgreSQL. When the current database connection uses
 * any other driver (MySQL, MariaDB, SQLite, SQL Server), there is no pgcat pool
 * to keep in step, so the flipper reports enabled = false and both
 * applyCurrentState() and forceMode() return FlipResult::noChange() without
 * reading, writing or restarting anything — whatever `pgcat.enabled` says.
 *
 * The driver comes from WeightedDatabaseServiceProvider, which reads it off the
 * app's default connection. A flipper constructed without a driver is left
 * unrestricted, so standalone use keeps its old behaviour.
 *
 * ARMED, AND PROVABLY SO
 * ----------------------
 * Being inert is not the same as being wired correctly. Two states are reported
 * separately so an operator can tell them apart:
 *
 *   - disabledReason()  why the flipper will not act (null when it will)
 *   - armedReason()     why it will act — names the switch and the driver that
 *                       both passed (null when it will not)
 *
 * A third state is called out as a warning: `swrr.pgcat.enabled = true` on a
 * connection whose driver pgcat cannot front. The flipper is correctly inert,
 * but the setting says flips are expected, and none will ever happen. An armed
 * flipper that is missing one of its three paths is warned about too, because
 * swap() would throw rather than flip.
 *
 * STATE FILE
 * ----------
 *   Path: configured via `state_file` (default sys_get_temp_dir())
 *   Format: JSON { "last_mode": "readers"|"writer", "last_flipped_at": ISO, ... }
 *   Missing file → treated as "never applied"; first call applies current mode.
 */
final class PgcatConfigFlipper
{
    /**
     * Drivers pgcat can sit in front of. Everything else has no pgcat pool, so
     * flipping is disabled for those connections.
     */
    public const SUPPORTED_DRIVERS = ['pgsql', 'postgres', 'postgresql'];

    /**
     * The one documented home for what each switch means when it is not written: the
     * published `config/db-manager.php`, which ships `enabled` off and `use_reload` on.
     *
     * The same value is what a switch the package cannot read falls back to, so a refused
     * value lands exactly where an absent one would — never somewhere the setting's own
     * documentation does not describe. These two used to disagree with that file (`?? true`
     * and `?? false`), which is only visible on a flipper built without the key, and
     * aligning them is what lets a report say which value a refusal fell back to without
     * contradicting the config it points at.
     */
    private const ENABLED_DEFAULT = false;

    /** See ENABLED_DEFAULT: `use_reload` ships true, so a flip reloads unless told otherwise. */
    private const USE_RELOAD_DEFAULT = true;

    /**
     * The command a flip runs when the pgcat block does not say otherwise — the one the
     * published `config/db-manager.php` prints beside `restart_command` and `reload_command`,
     * and the one `status()` reports for a key that is not written.
     *
     * Named rather than spelled out at each use because the same two strings are needed as a
     * *value* as well as a fallback: `db:doctor` prints the documented command as the repair
     * for a command the operator left empty (`suggestionForSupervisor()`), and a repair that
     * was a second copy of the default could drift from the default it is telling them to
     * write.
     */
    private const DEFAULT_RESTART_COMMAND = 'supervisorctl restart "pgcat:*"';

    private const DEFAULT_RELOAD_COMMAND = 'supervisorctl signal HUP "pgcat:*"';

    /** @var \Closure(string $from, string $to): bool */
    private \Closure $fileCopier;

    /** @var \Closure(string $command): array{0:int,1:string,2:string} */
    private \Closure $commandRunner;

    /** @var \Closure(string $file): array{0:bool,1:resource} */
    private \Closure $lockAcquirer;

    /** @var \Closure(resource $fp): void */
    private \Closure $lockReleaser;

    private DateTimeZone $timezone;

    private SupervisorStep $supervisorStep;

    /**
     * @param array<string, mixed> $config pgcat config slice (see config/db-manager.php)
     */
    public function __construct(
        private readonly TimeWindowResolver $resolver,
        private readonly array $config,
        private readonly string $stateFile,
        private readonly string $lockFile,
        ?\Closure $fileCopier = null,
        ?\Closure $commandRunner = null,
        ?\Closure $lockAcquirer = null,
        ?\Closure $lockReleaser = null,
        ?string $timezone = null,
        private readonly ?string $connectionName = null,
        private readonly ?string $driver = null,
        ?SupervisorStep $supervisorStep = null,
    ) {
        $this->fileCopier = $fileCopier ?? static function (string $from, string $to): bool {
            $tmp = $to . '.tmp.' . getmypid();
            $bytes = file_put_contents($tmp, file_get_contents($from));
            if ($bytes === false) {
                return false;
            }
            return @rename($tmp, $to);
        };

        $this->commandRunner = $commandRunner ?? static function (string $cmd): array {
            $p = \Symfony\Component\Process\Process::fromShellCommandline($cmd);
            $p->setTimeout(10);
            $p->run();
            return [
                $p->getExitCode() ?? -1,
                $p->getOutput(),
                $p->getErrorOutput(),
            ];
        };

        $this->lockAcquirer = $lockAcquirer ?? static function (string $file): array {
            $fp = @fopen($file, 'c');
            if ($fp === false) {
                return [false, null];
            }
            $acquired = @flock($fp, LOCK_EX | LOCK_NB);
            return [$acquired, $fp];
        };

        $this->lockReleaser = $lockReleaser ?? static function ($fp): void {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        };

        // The inspector a flip judges its own command with. Defaulted to the same runner
        // the flip executes commands through, so a host that replaced that closure
        // replaced it for the check as well — the check has to run the way the flip will.
        $this->supervisorStep = $supervisorStep ?? new SupervisorStep($this->commandRunner);

        $this->timezone = new DateTimeZone($timezone ?? 'UTC');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check the resolver's current mode against the last applied mode.
     * If they differ, swap the pgcat config and restart pgcat. Update state.
     */
    public function applyCurrentState(): FlipResult
    {
        if ($reason = $this->disabledReason()) {
            return FlipResult::noChange(
                mode: $this->resolver->currentModeName(),
                reason: $reason,
            );
        }

        [$acquired, $fp] = ($this->lockAcquirer)($this->lockFile);
        if (!$acquired) {
            return FlipResult::skipped(
                currentMode: $this->resolver->currentModeName(),
                reason: 'another flipper instance holds the lock',
            );
        }

        try {
            $currentMode = $this->resolver->currentModeName();
            $lastMode = $this->readLastMode();

            // A missing state file falls through to the swap below: with no
            // previous mode there is nothing for the current mode to be
            // "unchanged" from, so a first run is reported as a flip whose
            // previousMode is null.
            if ($lastMode === $currentMode) {
                return FlipResult::noChange(
                    mode: $currentMode,
                    reason: 'mode unchanged since last flip',
                );
            }

            $this->swap($currentMode);

            $this->writeLastMode($currentMode, $lastMode);

            return FlipResult::flipped(
                newMode: $currentMode,
                previousMode: $lastMode,
            );
        } catch (\Throwable $e) {
            return FlipResult::failed(
                currentMode: $this->resolver->currentModeName(),
                error: $e->getMessage(),
            );
        } finally {
            ($this->lockReleaser)($fp);
        }
    }

    /**
     * Force the flipper into a specific mode regardless of resolver state.
     * Useful for manual ops ("force into readers" before maintenance).
     */
    public function forceMode(string $mode): FlipResult
    {
        $mode = $mode === 'readers' ? 'readers' : 'writer';

        if (!$this->driverSupported()) {
            return FlipResult::noChange(
                mode: $mode,
                reason: $this->unsupportedDriverReason(),
            );
        }

        [$acquired, $fp] = ($this->lockAcquirer)($this->lockFile);
        if (!$acquired) {
            return FlipResult::skipped(
                currentMode: $mode,
                reason: 'another flipper instance holds the lock',
            );
        }

        try {
            $lastMode = $this->readLastMode();
            $this->swap($mode);
            $this->writeLastMode($mode, $lastMode);

            return FlipResult::flipped(newMode: $mode, previousMode: $lastMode);
        } catch (\Throwable $e) {
            return FlipResult::failed(currentMode: $mode, error: $e->getMessage());
        } finally {
            ($this->lockReleaser)($fp);
        }
    }

    /**
     * A flip, rehearsed: the steps that leave nothing behind are performed for real, and the
     * ones that change the world are reported instead — the rename over pgcat's config and
     * the supervisor command.
     *
     * It also runs the flip's supervisor check — the read-only `supervisorctl status
     * "pgcat:*"`, never the command itself — because that is now a step of the flip and a
     * rehearsal that skipped it would predict a flip the flipper would refuse.
     *
     * It writes the same temp file a swap writes, in the same directory, and removes it
     * again. That is the one thing a metadata check cannot do: `db:doctor`'s pgcat files row
     * judges readability and writability from `is_readable`/`is_writable` and deliberately
     * leaves no trace, and a directory can pass that and still refuse the write — an ACL, a
     * read-only mount, a full disk. The rename is then reported as one a flip *would* take,
     * with its precondition proved rather than assumed: a temp file beside the target, on the
     * target's own filesystem.
     *
     * Three things it deliberately does not do, and says so in the report:
     *
     * - the rename, which is the point of the rehearsal;
     * - the supervisor command, so nothing is restarted or signalled;
     * - the state write. That one is not a safety measure but a correctness one: recording a
     *   mode that was never applied would make the next real flip believe it had already
     *   happened. Whether that write *could* work is still answered, from metadata, because
     *   a flip's write to it is silenced — a failure there means the flip reports success
     *   and then repeats on every poll, restarting pgcat each time.
     *
     * The gates are the same gates: the same `disabledReason()`/`driverSupported()` checks
     * and the same lock, so a rehearsal cannot report a flip that a real run would decline.
     * Taking the lock also proves it can be taken — and a held lock is reported as a flip
     * that would be skipped, because that is what a flip would do, not a failure.
     *
     * @param string|null $forcedMode the mode a `--force-mode` flip would apply, or null to
     *        ask the resolver exactly as a scheduled flip does
     */
    public function dryRun(?string $forcedMode = null): DryRunResult
    {
        $mode = $forcedMode === null
            ? $this->resolver->currentModeName()
            : ($forcedMode === 'readers' ? 'readers' : 'writer');

        if ($forcedMode === null) {
            if ($reason = $this->disabledReason()) {
                return DryRunResult::wouldNotFlip($mode, $reason, [[
                    'step' => 'gates',
                    'outcome' => DryRunResult::STEP_REFUSED,
                    'detail' => $reason,
                ]]);
            }
        } elseif (!$this->driverSupported()) {
            $reason = $this->unsupportedDriverReason();

            return DryRunResult::wouldNotFlip($mode, $reason, [[
                'step' => 'gates',
                'outcome' => DryRunResult::STEP_REFUSED,
                'detail' => $reason,
            ]]);
        }

        [$acquired, $fp] = ($this->lockAcquirer)($this->lockFile);

        if (!$acquired) {
            return DryRunResult::skipped($mode, 'another flipper instance holds the lock', [[
                'step' => 'lock',
                'outcome' => DryRunResult::STEP_REFUSED,
                'detail' => "held by another instance: {$this->lockFile}",
            ]]);
        }

        $lockStep = [
            'step' => 'lock',
            'outcome' => DryRunResult::STEP_DONE,
            'detail' => "taken and released: {$this->lockFile}",
        ];

        try {
            $lastMode = $this->readLastMode();

            if ($forcedMode === null && $lastMode === $mode) {
                return DryRunResult::wouldNotFlip($mode, 'mode unchanged since last flip', [
                    $lockStep,
                    [
                        'step' => 'mode',
                        'outcome' => DryRunResult::STEP_REFUSED,
                        'detail' => "on record as \"{$mode}\" already",
                    ],
                ]);
            }

            return $this->rehearse($mode, $lastMode, [$lockStep]);
        } finally {
            ($this->lockReleaser)($fp);
        }
    }

    /**
     * The swap itself, short of the rename and the command: read the source, prove the
     * target's directory accepts the file the swap writes, and remove the proof again.
     *
     * Every failure a swap would *throw* on is reported as a verdict instead — a rehearsal
     * exists to be asked, and "a flip would fail here" is a better answer than an exception.
     * A cleanup that fails makes the whole rehearsal a failure, because a dry run that leaves
     * a file behind has changed the world, which is the one thing it promises not to do.
     *
     * @param list<array{step: string, outcome: string, detail: string}> $steps the steps already taken
     */
    private function rehearse(string $mode, ?string $lastMode, array $steps): DryRunResult
    {
        $target = ConfigValue::string($this->config['config_path'] ?? null);
        $source = ConfigValue::string(
            $mode === 'readers'
                ? ($this->config['readers_path'] ?? null)
                : ($this->config['no_readers_path'] ?? null),
        );

        if ($target === '' || $source === '') {
            return DryRunResult::failed(
                mode: $mode,
                error: 'pgcat config paths missing: set swrr.pgcat.{config_path,readers_path,no_readers_path}',
                steps: [...$steps, [
                    'step' => 'read source',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => 'a path a flip needs is empty',
                ]],
            );
        }

        if (!is_readable($source)) {
            return DryRunResult::failed(
                mode: $mode,
                error: "Source pgcat config not readable: {$source}",
                steps: [...$steps, [
                    'step' => 'read source',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => "not readable: {$source}",
                ]],
            );
        }

        $contents = @file_get_contents($source);

        if ($contents === false) {
            return DryRunResult::failed(
                mode: $mode,
                error: "Source pgcat config could not be read: {$source}",
                steps: [...$steps, [
                    'step' => 'read source',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => "readable but the read failed: {$source}",
                ]],
            );
        }

        $steps[] = [
            'step' => 'read source',
            'outcome' => DryRunResult::STEP_DONE,
            'detail' => sprintf('%s (%d bytes)', $source, strlen($contents)),
        ];

        // The step a flip takes between reading the source and replacing the target. It is
        // read-only — `supervisorctl status "pgcat:*"` and nothing else — so the rehearsal
        // performs it rather than reporting it, which is the same rule the other steps
        // follow: what can be done without changing anything, is done.
        $verdict = $this->supervisorStep->inspect($this->supervisorCommand());

        if (!$verdict['usable']) {
            return DryRunResult::failed(
                mode: $mode,
                error: $verdict['detail'].' — a flip refuses before it swaps the file, so nothing would be replaced',
                steps: [...$steps, [
                    'step' => 'supervisor check',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => $verdict['detail'],
                ], [
                    'step' => 'rename',
                    'outcome' => DryRunResult::STEP_REFUSED,
                    'detail' => 'never reached: the command was refused first, so the file would be left as it is',
                ]],
            );
        }

        $steps[] = [
            'step' => 'supervisor check',
            'outcome' => DryRunResult::STEP_DONE,
            'detail' => $verdict['detail'],
        ];

        $temporary = $target.'.tmp.'.getmypid();
        $written = @file_put_contents($temporary, $contents);

        if ($written === false) {
            return DryRunResult::failed(
                mode: $mode,
                error: "The temp file a swap writes could not be created: {$temporary}",
                steps: [...$steps, [
                    'step' => 'write temp',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => "refused: {$temporary}",
                ]],
            );
        }

        $steps[] = [
            'step' => 'write temp',
            'outcome' => DryRunResult::STEP_DONE,
            'detail' => sprintf('%s (%d bytes)', $temporary, $written),
        ];

        if (@unlink($temporary) !== true) {
            return DryRunResult::failed(
                mode: $mode,
                error: "The rehearsal could not remove its own temp file, so it left one behind: {$temporary}",
                steps: [...$steps, [
                    'step' => 'remove temp',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => "left behind: {$temporary}",
                ]],
            );
        }

        $steps[] = [
            'step' => 'remove temp',
            'outcome' => DryRunResult::STEP_DONE,
            'detail' => 'the rehearsal leaves nothing behind',
        ];

        $steps[] = [
            'step' => 'rename',
            'outcome' => DryRunResult::STEP_WOULD,
            'detail' => sprintf('%s → %s (%s)', $temporary, $target, $this->targetComparison($target, $contents)),
        ];

        $steps[] = [
            'step' => 'supervisor',
            'outcome' => DryRunResult::STEP_WOULD,
            'detail' => $this->supervisorCommand(),
        ];

        if (!$this->stateFileWritable()) {
            return DryRunResult::failed(
                mode: $mode,
                error: sprintf(
                    'the state file cannot be written (%s), and a flip silences that write: the flip would succeed and then repeat on every poll, restarting pgcat each time',
                    $this->stateFile,
                ),
                steps: [...$steps, [
                    'step' => 'state file',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => "cannot be written: {$this->stateFile}",
                ]],
            );
        }

        $steps[] = [
            'step' => 'state file',
            'outcome' => DryRunResult::STEP_WOULD,
            'detail' => sprintf('%s (writable, left as it is)', $this->stateFile),
        ];

        return DryRunResult::wouldFlip($mode, $lastMode, $steps);
    }

    /**
     * What a flip would do to the target's content, in words — the question a rehearsal of a
     * window boundary is really asking. A target already holding the source's bytes is not a
     * fault (an operator may have applied the file by hand), so it is reported rather than
     * judged.
     */
    private function targetComparison(string $target, string $contents): string
    {
        if (!is_file($target)) {
            return 'the target does not exist yet, so a flip would create it';
        }

        if (@file_get_contents($target) === $contents) {
            return 'the target already holds these bytes, so a flip would replace it with itself';
        }

        return 'the content would change';
    }

    /**
     * Whether a flip's state write would land, judged the same way `db:doctor` judges it —
     * from metadata, because the rehearsal must not write it. A flip does not create the
     * directory itself, so a missing directory is a failure here too.
     */
    private function stateFileWritable(): bool
    {
        if (is_file($this->stateFile)) {
            return is_writable($this->stateFile);
        }

        $directory = dirname($this->stateFile);

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Inspect current state without flipping.
     *
     * `enabled` is the effective switch — the configured value AND whether pgcat
     * applies to the current connection's driver. `configured_enabled` is what
     * `swrr.pgcat.enabled` says on its own.
     *
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null, config_path: string, readers_path: string, no_readers_path: string, restart_command: string, reload_command: string, use_reload: bool, state_file: string, lock_file: string}
     */
    public function status(): array
    {
        return [
            ...$this->healthSummary(),
            'config_path' => ConfigValue::string($this->config['config_path'] ?? null),
            'readers_path' => ConfigValue::string($this->config['readers_path'] ?? null),
            'no_readers_path' => ConfigValue::string($this->config['no_readers_path'] ?? null),
            'restart_command' => ConfigValue::string(
                $this->config['restart_command'] ?? null,
                self::DEFAULT_RESTART_COMMAND,
            ),
            'reload_command' => ConfigValue::string(
                $this->config['reload_command'] ?? null,
                self::DEFAULT_RELOAD_COMMAND,
            ),
            'use_reload' => $this->useReload(),
            'state_file' => $this->stateFile,
            'lock_file' => $this->lockFile,
        ];
    }

    /**
     * The command a flip runs to make supervisor pick the new file up: the reload
     * command when `use_reload` is on, otherwise the restart command.
     *
     * Public because `db:doctor` has to judge the same command the flip will run —
     * whether its executable resolves, whether the program name survives the shell and
     * whether supervisor knows it — and one method means the report and the flip can
     * never be looking at different commands.
     */
    public function supervisorCommand(): string
    {
        return ConfigValue::string($this->config[$this->commandKey()] ?? null, $this->documentedCommand());
    }

    /**
     * Which of the two command settings a flip reads: the reload one when `use_reload` is on,
     * the restart one otherwise. The switch decides it, and it is decided in one place so a
     * repair cannot name a different key from the one the command came out of.
     */
    private function commandKey(): string
    {
        return $this->useReload() ? 'reload_command' : 'restart_command';
    }

    /**
     * The value that key documents when it is not written — what a flip runs instead, and the
     * command a report prints when the key is empty.
     */
    private function documentedCommand(): string
    {
        return $this->useReload() ? self::DEFAULT_RELOAD_COMMAND : self::DEFAULT_RESTART_COMMAND;
    }

    /**
     * The pgcat setting line that clears the gate problem a row is reporting, or null when the
     * problem is not repaired by a value this package can name.
     *
     * The switch is on where pgcat cannot act, so the value that stops the warning is the one
     * that turns it off — and the sentence already says so ("Set swrr.pgcat.enabled = false,
     * or point database.default at the pgcat-fronted connection"). The line is what a *sentence*
     * cannot be: something to paste, and a string a job can read out of `--json`. The sentence
     * and the line agree, which is the point — and the sentence stays, because the flip's refusal
     * and `--dry-run` print it too, and neither of those has a suggestion column.
     *
     * Null for the gate row's other failure — armed with a path a flip needs unset, which the row
     * names as a key (`swrr.pgcat.no_readers_path`) whose value is this installation's to choose,
     * so a path guessed here would replace the operator's intent rather than carry it out. Also
     * null when the row passed this boot and is only carrying a mismatch still on record: the
     * configuration has nothing to change, because a boot that sees the gate open closes the
     * record out.
     */
    public function suggestionForGate(): ?string
    {
        return $this->isMismatched() ? 'swrr.pgcat.enabled = false' : null;
    }

    /**
     * The pgcat setting line that clears a supervisor problem, or null when the problem is not
     * repaired by a value this package can name.
     *
     * Two of the step's faults reduce to an exact replacement, and they are the two whose
     * sentence already names it:
     *
     *   - a program name that reached the command unquoted, where the repair is the same
     *     command with the name quoted — the line `SupervisorStep` builds for its own read-only
     *     invocation, so the value printed is the one a flip would then run;
     *   - a command left empty, where the repair is the command the setting documents.
     *
     * The rest do not, and the line is drawn where `ReaderWindows::suggestion()` draws it: an
     * executable that does not resolve (a PATH or an install, not a config value), a supervisord
     * that does not know the program (a `[program:]` section on the supervisor side), a socket
     * that is not there, a command that drove something other than supervisorctl. The key is the
     * one a flip reads — `commandKey()`, not a guess between the two — so a repair can never
     * send an operator to the setting their flip is not using.
     *
     * @param array{fault: string|null, flip_command: string} $verdict what `SupervisorStep::inspect()` returned
     */
    public function suggestionForSupervisor(array $verdict): ?string
    {
        $command = match ($verdict['fault']) {
            SupervisorStep::FAULT_UNQUOTED => $verdict['flip_command'],
            SupervisorStep::FAULT_EMPTY => $this->documentedCommand(),
            default => null,
        };

        return $command === null
            ? null
            : sprintf('swrr.pgcat.%s = %s', $this->commandKey(), var_export($command, true));
    }

    /**
     * The part of status() that answers "is flipping active right now" — and the
     * only place that answer is computed. /health/db, db:replica-status and
     * db:pgcat-flip --status all read it, so the three cannot disagree.
     *
     * The flipper is global: it follows `database.default`, not whichever
     * connection a caller happens to be inspecting.
     *
     * `reason` and `armed_reason` are exact opposites: exactly one of them is
     * null, and which one says whether the gate opened or closed. `mismatch`
     * flags the configuration that reads as armed but can never act, and
     * `warning` carries the sentence for it (or for an incomplete arming).
     *
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null}
     */
    public function healthSummary(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'configured_enabled' => $this->configuredEnabled(),
            'connection' => $this->connectionName ?? '',
            'driver' => $this->driver ?? '',
            'driver_supported' => $this->driverSupported(),
            'resolver_mode' => $this->resolver->currentModeName(),
            'last_mode' => $this->readLastMode(),
            'reason' => $this->disabledReason(),
            'armed_reason' => $this->armedReason(),
            'mismatch' => $this->isMismatched(),
            'warning' => $this->armingWarning(),
        ];
    }

    /**
     * True when pgcat applies here: configured on AND the current connection uses
     * a driver pgcat can front. A flipper built without a driver stays as
     * configured.
     */
    public function isEnabled(): bool
    {
        return $this->configuredEnabled() && $this->driverSupported();
    }

    /**
     * Why the flipper will not act — or null when it will. Both ways of being
     * off are covered: `swrr.pgcat.enabled = false`, and a current connection
     * whose driver pgcat cannot front.
     *
     * db:pgcat-flip uses this to stop before it starts, so a --watch daemon on a
     * MySQL connection exits instead of printing the same no-change line every
     * interval.
     */
    public function disabledReason(): ?string
    {
        if (!$this->configuredEnabled()) {
            return 'pgcat flipping disabled (swrr.pgcat.enabled = false)';
        }

        return $this->driverSupported() ? null : $this->unsupportedDriverReason();
    }

    /**
     * Why the flipper is armed — the counterpart to disabledReason(), and `null`
     * whenever it is not. Both conditions that had to hold are named, so an
     * armed flipper proves itself instead of merely reporting enabled = true.
     */
    public function armedReason(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return sprintf(
            'pgcat flipping armed: swrr.pgcat.enabled = true and connection "%s" uses driver "%s"',
            $this->connectionName ?: '(unset)',
            $this->driver ?: '(unset)',
        );
    }

    /**
     * True when the switch and the driver gate disagree: `swrr.pgcat.enabled` is
     * on, but the current connection's driver is not one pgcat can front, so the
     * flipper is inert. Nothing is broken — there is no pool to swap — but the
     * configuration says flips are expected and none will happen.
     */
    public function isMismatched(): bool
    {
        return $this->configuredEnabled() && !$this->driverSupported();
    }

    /**
     * Both pgcat switches, in the one place they are read.
     *
     * Four call sites used to read `enabled` — two through `ConfigValue::bool()` and two
     * through a bare `(bool)` cast — and the two idioms disagreed about an array, so one
     * `status()` could report `enabled: false` beside `configured_enabled: true`. They all
     * read this now, and a switch a person wrote is read from the spellings a person writes
     * (`Support\SwitchValue`) rather than cast, so `'false'` is off and `'maybe'` is a value
     * the package refuses instead of a value it decides.
     *
     * `$default` is the value the setting documents when it is not written: `enabled`
     * documents `false`, `use_reload` documents `true`, and neither is derivable from "this is
     * a switch". The refused value resolves to the same default — the degradation
     * `ConfigValue` already promises for a malformed setting, landed on the value the config
     * file prints beside the setting — and `refused` carries what was written, for the boot
     * audit and the preflight to report.
     *
     * @return array{on: array{enabled: bool, use_reload: bool}, refused: array<string, string>}
     */
    private function switchReadings(): array
    {
        $on = [];
        $refused = [];

        foreach (['enabled' => self::ENABLED_DEFAULT, 'use_reload' => self::USE_RELOAD_DEFAULT] as $key => $default) {
            $reading = SwitchValue::read($this->config[$key] ?? null, $default);

            $on[$key] = $reading['on'];

            if ($reading['refused'] !== null) {
                $refused['swrr.pgcat.'.$key] = $reading['refused'];
            }
        }

        return ['on' => $on, 'refused' => $refused];
    }

    /**
     * `swrr.pgcat.enabled` as written, before the driver gate narrows it.
     */
    private function configuredEnabled(): bool
    {
        return $this->switchReadings()['on']['enabled'];
    }

    /**
     * `swrr.pgcat.use_reload`: the reload command rather than the restart one.
     */
    private function useReload(): bool
    {
        return $this->switchReadings()['on']['use_reload'];
    }

    /**
     * The pgcat switches whose value the package could not read, as `setting => as written`.
     *
     * Public because the boot audit reports from here rather than re-reading the block: the
     * flipper is built with `swrr.pgcat` and every reader of "is pgcat on" goes through the
     * methods above, so a refusal read twice would be a switch the report and the routing
     * could disagree about.
     *
     * @return array<string, string>
     */
    public function refusedSwitches(): array
    {
        return $this->switchReadings()['refused'];
    }

    /**
     * One sentence for any state where flipping is expected to act but cannot:
     * an enabled switch on a driver pgcat cannot front, or an armed flipper
     * missing a path a flip needs. Null when the flipper is off on purpose, or
     * armed and ready.
     *
     * Only the switches and the configured paths are inspected — never the
     * filesystem — so this is safe to call on every health request.
     */
    public function armingWarning(): ?string
    {
        if ($this->isMismatched()) {
            return sprintf(
                'swrr.pgcat.enabled is true but pgcat will never act: connection "%s" uses driver "%s", '
                .'and pgcat only fronts PostgreSQL. Set swrr.pgcat.enabled = false, or point '
                .'database.default at the pgcat-fronted connection.',
                $this->connectionName ?: '(unset)',
                $this->driver ?: '(unset)',
            );
        }

        if (!$this->isEnabled()) {
            return null;
        }

        $missing = $this->missingFlipTargets();

        return $missing === []
            ? null
            : 'pgcat flipping is armed but incomplete: set swrr.pgcat.'
                .implode(' and swrr.pgcat.', $missing)
                .', or a flip will fail';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * pgcat is PostgreSQL-only, so a connection on any other driver has no pool
     * for the flipper to swap. An absent driver is treated as supported so a
     * standalone flipper keeps its existing behaviour.
     */
    private function driverSupported(): bool
    {
        if ($this->driver === null || $this->driver === '') {
            return true;
        }

        return in_array(strtolower(trim($this->driver)), self::SUPPORTED_DRIVERS, true);
    }

    private function unsupportedDriverReason(): string
    {
        return sprintf(
            'pgcat is PostgreSQL-only; connection "%s" uses driver "%s", so pgcat flipping is disabled',
            $this->connectionName ?: '(unset)',
            $this->driver ?: '(unset)',
        );
    }

    /**
     * The config keys a flip cannot do without: the target file, plus the source
     * for each mode — swap() throws when any of them is empty. Emptiness only;
     * readability is left to swap(), so health reporting does no filesystem I/O.
     *
     * @return list<string>
     */
    private function missingFlipTargets(): array
    {
        $missing = [];

        foreach (['config_path', 'readers_path', 'no_readers_path'] as $key) {
            if (ConfigValue::string($this->config[$key] ?? null) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Swap pgcat config to the file matching the desired mode, then restart.
     *
     * The supervisor command is judged *before* the file is replaced. A flip that cannot
     * make pgcat pick the new file up has no business leaving one in place: the next pgcat
     * start — a crash, a deploy, an OOM kill — would come up in a mode no window asked
     * for, and nobody would be looking. A refusal is a failed flip with nothing touched
     * and nothing recorded, so the next poll tries again and the flip goes through by
     * itself once the command is fixed.
     *
     * If the command passes that check and still fails — supervisorctl raced with a
     * restart, or pgcat would not come back up — the previous file is put back. A new file
     * on disk with the old processes running is precisely the state this ordering exists
     * to prevent, and it is the one an operator cannot see.
     */
    private function swap(string $mode): void
    {
        $target = ConfigValue::string($this->config['config_path'] ?? null);
        $source = ConfigValue::string(
            $mode === 'readers'
                ? ($this->config['readers_path'] ?? null)
                : ($this->config['no_readers_path'] ?? null),
        );

        if ($target === '' || $source === '') {
            throw new \RuntimeException(
                'pgcat config paths missing: set swrr.pgcat.{config_path,readers_path,no_readers_path}'
            );
        }

        if (!is_readable($source)) {
            throw new \RuntimeException("Source pgcat config not readable: {$source}");
        }

        $verdict = $this->supervisorStep->inspect($this->supervisorCommand());

        if (!$verdict['usable']) {
            throw new \RuntimeException(
                $verdict['detail'].' — a flip refuses before it swaps the file, so nothing was replaced and no mode was recorded'
            );
        }

        // Kept for the rollback below, and read *before* the copy: afterwards the previous
        // mode exists nowhere but in pgcat's memory. A read that fails becomes null, which
        // the rollback reports rather than restoring an empty file over a real one.
        $existed = is_file($target);
        $read = $existed ? @file_get_contents($target) : null;
        $previous = is_string($read) ? $read : null;

        $copied = ($this->fileCopier)($source, $target);
        if ($copied !== true) {
            throw new \RuntimeException("Failed to copy {$source} → {$target}");
        }

        try {
            $this->restartSupervisor();
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage().$this->rollBack($target, $existed, $previous));
        }
    }

    /**
     * Put the target back the way it was, and say which of the three things happened: put
     * back, nothing to put back, or **not** put back. The last is the one an operator has
     * to know about, because pgcat is then running one mode from memory while the disk
     * holds the other, and only a restart would reveal it.
     *
     * Written locally rather than through the injected copier, whose contract is "make the
     * target the source's content" — and the rollback's source is a string in memory. A
     * host that replaced the copier to swap files somewhere else gets a local rollback.
     */
    private function rollBack(string $target, bool $existed, ?string $previous): string
    {
        if ($existed && $previous === null) {
            return sprintf(
                ' — the previous %s could not be read before the swap, so it cannot be put back: pgcat runs the old mode from memory while the new file sits on disk',
                $target,
            );
        }

        if (!$existed) {
            $removed = !is_file($target) || @unlink($target);

            return $removed
                ? sprintf(' — %s did not exist before the swap either, so it was removed again', $target)
                : sprintf(' — %s did not exist before the swap and could not be removed: a file no flip asked for is on disk now', $target);
        }

        $temporary = $target.'.tmp.'.getmypid();
        $written = @file_put_contents($temporary, (string) $previous);

        if ($written === false || @rename($temporary, $target) !== true) {
            @unlink($temporary);

            return sprintf(
                ' — the previous config could not be put back: %s now holds the new variant while pgcat runs the old one',
                $target,
            );
        }

        return sprintf(' — the previous config was put back, so %s holds the mode pgcat is actually running', $target);
    }

    private function restartSupervisor(): void
    {
        $cmd = $this->supervisorCommand();

        [$exitCode, $stdout, $stderr] = ($this->commandRunner)($cmd);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'supervisorctl exited %d: stdout=%s stderr=%s',
                    $exitCode,
                    trim($stdout),
                    trim($stderr),
                )
            );
        }
    }

    private function readLastMode(): ?string
    {
        if (!is_file($this->stateFile)) {
            return null;
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $lastMode = $decoded['last_mode'] ?? null;

        return is_string($lastMode) ? $lastMode : null;
    }

    private function writeLastMode(string $mode, ?string $previousMode): void
    {
        $payload = [
            'last_mode' => $mode,
            'previous_mode' => $previousMode,
            'last_flipped_at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
            'flipped_by' => 'pgcat-flipper',
        ];

        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->stateFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

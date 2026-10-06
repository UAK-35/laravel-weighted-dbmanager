<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Support\ActiveConnection;
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
 *   4. supervisorctl restart "pgcat:*" — or `start` when supervisord reports the program is
 *      not running, which is the case where the file being replaced is the reason it is down:
 *      the swap is then a repair, it is not rolled back, and the mode stays unrecorded so the
 *      next poll tries again.
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
 * IN STEP WITH THE WINDOW
 * -----------------------
 * The boot window answers "did this container ever come up". It does not answer the
 * question that outlives it: with reader windows still moving the mode every day, is the
 * pooler *still* on the configuration the window asks for? A flip that stopped — a scheduler
 * that stopped running it, a mutex that cannot be taken, an exhausted boot window — leaves
 * pgcat on whatever it last applied, and none of that is visible in a query that answers.
 *
 * readerWindowVerdict() is that second answer, and it is read off pgcat's own file rather
 * than from the recorded mode. A flip that never got as far as recording anything still left
 * a file behind, and `last_mode` is exactly the field that is empty in that case — which is
 * the shape the 2026-10-02 incident arrived in: pgcat on the writer-only config through an
 * open reader window, every flip run aborting before it reached the flipper, and a health
 * endpoint reporting `ok` because nothing asked the file.
 *
 * Either direction is a fault, because the file and the windows are two statements about one
 * routing decision and the stale one is stale whichever way round the disagreement is. In a
 * reader window, a pooler with no readers configured forces every read onto the writer and
 * silently defeats the setting. Outside one, a pooler still holding the readers config sends
 * every read through it to a replica during the hours the fallback exists to keep them on the
 * writer — and it is the same evidence that a boundary flip stopped landing, because the mode
 * the windows asked for at the end of the last window is the one that never arrived. A file
 * matching neither variant is still not judged: the package cannot tell an operator's own edit
 * from a half-written copy, so it reports `in_step: null` rather than inventing a direction.
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
     * The states a file a flip needs can be in. Named rather than left to the sentence, because
     * the repair line is keyed on them: a path that is not there is the installation's to point
     * somewhere else, one that cannot be read is a mode, and a test that pinned this by matching
     * prose would be pinning the prose.
     */
    public const FILE_UNSET = 'unset';

    public const FILE_MISSING = 'missing';

    public const FILE_UNREADABLE = 'unreadable';

    public const FILE_UNWRITABLE = 'unwritable';

    public const FILE_DIRECTORY_UNWRITABLE = 'directory_unwritable';

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

    /**
     * The command a repair runs when the config does not name one: what the other two would do,
     * with the only verb that acts on a program that is *not* up. `restart` and `signal HUP`
     * both need a running program — supervisor answers `ERROR (not running)` otherwise — so a
     * flip that finds pgcat STARTING or FATAL and runs either has replaced a file and left the
     * daemon exactly as it was.
     */
    private const DEFAULT_START_COMMAND = 'supervisorctl start "pgcat:*"';

    /**
     * How many times a repair starts pgcat before it reports the failure. More than one because
     * the first start is what a crash-looping program needs — supervisor's own `startretries`
     * continue after it — and because the state is read back after each attempt, so a pgcat
     * that is merely inside its `startsecs` window on the first read is not a failure.
     */
    private const START_ATTEMPTS_DEFAULT = 3;

    /** How long a repair waits between two attempts, in milliseconds. */
    private const START_RETRY_DELAY_MS_DEFAULT = 2000;

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
     * How long this container is allowed to get pgcat into a working shape, and whether that
     * has run out. Built from the config slice when not injected so a host that has not heard
     * of it gets the documented default rather than no bound at all.
     */
    private readonly FlipWindow $window;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

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
        // The config key the connection name was read from — `db-manager.swrr.connection` or
        // `database.default` — so the mismatch sentence points an operator at the setting that
        // actually decided this connection instead of guessing `database.default`.
        private readonly string $connectionSource = ActiveConnection::DEFAULT_SOURCE,
        // How a repair waits between two attempts at starting pgcat. Injected so a test does
        // not spend the delay, and so an installation that would rather not sleep inside a
        // poll has one place to change it.
        ?\Closure $sleeper = null,
        // The container's boot window. Injected so a test can close it without waiting ten
        // minutes; defaulted from the same config slice a host passes in, so `boot_file` and
        // `flip_window_seconds` are read in exactly one place.
        ?FlipWindow $window = null,
    ) {
        $this->window = $window ?? FlipWindow::fromConfig($config);

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

        $this->sleeper = $sleeper ?? static function (int $micros): void {
            usleep($micros);
        };

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
        $reason = $this->disabledReason();
        if (!empty($reason)) {
            return FlipResult::noChange(
                mode: $this->resolver->currentModeName(),
                reason: $reason,
            );
        }

        // The window is checked before the lock, because a closed window has nothing to
        // serialise: nothing is read, written or run, and taking the lock only to release it
        // would make a stopped flipper look busy. A run that lands here is not recorded
        // either — the convergence verdict is about what happened *inside* the window, so a
        // run after it has closed must not be able to change the answer.
        if ($this->window->closed()) {
            return FlipResult::windowClosed(
                currentMode: $this->resolver->currentModeName(),
                reason: $this->windowClosedReason(),
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
                // A run that changes nothing is still a run: it is the ordinary outcome of a
                // per-minute schedule, and it is exactly the evidence `/health/db` needs to
                // say this container converged rather than merely that it booted.
                $this->recordRun(FlipResult::KIND_NO_CHANGE, $currentMode);

                return FlipResult::noChange(
                    mode: $currentMode,
                    reason: 'mode unchanged since last flip',
                );
            }

            $this->swap($currentMode);

            $this->writeLastMode($currentMode, $lastMode);
            $this->recordRun(FlipResult::KIND_FLIPPED, $currentMode);

            return FlipResult::flipped(
                newMode: $currentMode,
                previousMode: $lastMode,
            );
        } catch (\Throwable $e) {
            // Recorded as well: a convergence verdict that only counted successes would read
            // the same whether the flip was failing or never ran at all, and telling those two
            // apart is most of the point of the window.
            $this->recordRun(FlipResult::KIND_FAILED, $this->resolver->currentModeName());

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

        // The rehearsal has to name the command the flip would really run: a pgcat that is not
        // running turns the last step into a `start`, and a rehearsal that printed the reload
        // would be predicting a flip that does something else.
        $repair = $verdict['fault'] === SupervisorStep::FAULT_NOT_RUNNING;

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

        if ($repair) {
            $steps[] = [
                'step' => 'start',
                'outcome' => DryRunResult::STEP_WOULD,
                'detail' => sprintf(
                    '%s — pgcat is %s, so the flip starts it instead of reloading it',
                    $this->startCommand(),
                    $verdict['state'] ?? 'not RUNNING',
                ),
            ];
        }

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
            'detail' => $repair ? $this->startCommand() : $this->supervisorCommand(),
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
     * `window` is the boot window block `healthSummary()` already carries, restated here because
     * this method is written as a spread of that array plus the path and command settings — and a
     * reader of this shape (or `db:pgcat-flip --status`, which renders it) would not otherwise
     * know the window is in there.
     *
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null, window: array<string, mixed>, config_path: string, readers_path: string, no_readers_path: string, restart_command: string, reload_command: string, use_reload: bool, start_command: string, start_attempts: int, start_retry_delay_ms: int, state_file: string, lock_file: string}
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
            // What a repair would run, and how hard it would try — reported beside the other two
            // commands because a report that named only the reload would not say what happens
            // when pgcat is down, which is the case a flip now also covers.
            'start_command' => $this->startCommand(),
            'start_attempts' => max(1, ConfigValue::int($this->config['start_attempts'] ?? null, self::START_ATTEMPTS_DEFAULT)),
            'start_retry_delay_ms' => max(0, ConfigValue::int($this->config['start_retry_delay_ms'] ?? null, self::START_RETRY_DELAY_MS_DEFAULT)),
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
     * The values the published `config/db-manager.php` ships for the path settings that have one
     * there, resolved for this host. Named for the same reason the documented commands above are:
     * the value is needed as a *repair* as well as a default, and a repair that was a second copy
     * of the default could drift from the default it is telling an operator to write.
     *
     * `readers_path` and `no_readers_path` are deliberately absent. The published config carries
     * example values for them, but those are an example of the *shape* — the README's table
     * documents both as `—`, because there is no default to document — and a flip pointed at a
     * file that does not exist on this host is worse off than one with an empty column: it looks
     * like an answer.
     *
     * @return array{config_path: string, state_file: string, lock_file: string}
     */
    public static function documentedPaths(): array
    {
        return [
            'config_path' => '/etc/pgcat/pgcat.toml',
            'state_file' => sys_get_temp_dir().'/pgcat-flip-state.json',
            'lock_file' => sys_get_temp_dir().'/pgcat-flip.lock',
        ];
    }

    /**
     * Every way a file a flip needs can be unusable, one problem each.
     *
     * `db:doctor`'s `pgcat files` row prints this list and the boot audit records a finding from
     * it, so the two cannot disagree about which file this installation is missing — and the
     * finding's key, its sentence and the state it names all come from here rather than from the
     * row that happens to be rendering it.
     *
     * Each problem carries the repair line the package can state *exactly*, which is a smaller
     * set than the problems: a path that does not exist is this installation's to point somewhere
     * else, while a mode and a key with a published value are two things the package knows. The
     * rule is `ReaderWindows::suggestion()`'s, one row over — a line rather than a plausible-
     * looking guess, both halves pinned by tests rather than by taste.
     *
     * Empty while the flipper is inert, because nothing reads or writes a pgcat file then: there
     * is no precondition to judge, and no reason to require a path to exist.
     *
     * @return list<array{setting: string, kind: string, path: string, sentence: string, suggestion: string|null}>
     */
    public function fileProblems(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $problems = [];

        // swap() reads the source matching the mode it is entering, so both sources have to be
        // readable or one of the two directions cannot run. The sentence says where a flip stops
        // rather than only what is wrong, because the same text is the boot finding's warning.
        foreach (['readers_path' => 'reader source', 'no_readers_path' => 'writer source'] as $setting => $label) {
            $path = ConfigValue::string($this->config[$setting] ?? null);

            if ($path === '') {
                $problems[] = $this->fileProblem(
                    $setting,
                    self::FILE_UNSET,
                    '',
                    "swrr.pgcat.{$setting} is not set ({$label}), so one direction of a flip has no path to read.",
                );

                continue;
            }

            if (!is_file($path)) {
                $problems[] = $this->fileProblem(
                    $setting,
                    self::FILE_MISSING,
                    $path,
                    "{$label} [{$path}] does not exist, so a flip stops there with nothing replaced.",
                );
            } elseif (!is_readable($path)) {
                $problems[] = $this->fileProblem(
                    $setting,
                    self::FILE_UNREADABLE,
                    $path,
                    "{$label} [{$path}] is not readable, so a flip stops there with nothing replaced.",
                );
            }
        }

        $target = ConfigValue::string($this->config['config_path'] ?? null);

        if ($target === '') {
            $problems[] = $this->fileProblem(
                'config_path',
                self::FILE_UNSET,
                '',
                'swrr.pgcat.config_path is not set (flip target), so there is no path to swap a variant into.',
            );
        } else {
            if (!is_file($target)) {
                $problems[] = $this->fileProblem(
                    'config_path',
                    self::FILE_MISSING,
                    $target,
                    "flip target [{$target}] does not exist, so pgcat is running on whatever is at another path.",
                );
            } elseif (!is_writable($target)) {
                $problems[] = $this->fileProblem(
                    'config_path',
                    self::FILE_UNWRITABLE,
                    $target,
                    "flip target [{$target}] is not writable, so a flip may not get its copy into place.",
                );
            }

            // The copy is written beside the target as {target}.tmp.{pid} and then renamed over
            // it, so the directory needs write permission even when the file itself has it — and
            // a rename needs to traverse it, which is why the repair names both bits.
            $directory = dirname($target);

            if (!self::directoryWritable($directory)) {
                $problems[] = $this->fileProblem(
                    'config_path',
                    self::FILE_DIRECTORY_UNWRITABLE,
                    $directory,
                    "directory [{$directory}] is not writable, so the atomic swap cannot write its temp file.",
                );
            }
        }

        // The state and lock files are how a flip remembers that it happened and how a concurrent
        // flipper stands down, and the state directory is also where the boot check records an
        // unresolved pgcat mismatch so a later boot can log its resolution. Every one of those
        // writes is silenced with @, so when their directory is not writable a flip still reports
        // success — and then restarts pgcat again on the next poll, forever, and the mismatch
        // warning is logged with nothing on record to close it out.
        foreach (['state_file' => 'flip state', 'lock_file' => 'flip lock'] as $setting => $label) {
            $path = $setting === 'state_file' ? $this->stateFile : $this->lockFile;

            if ($path === '') {
                $problems[] = $this->fileProblem(
                    $setting,
                    self::FILE_UNSET,
                    '',
                    $setting === 'state_file'
                        ? "swrr.pgcat.{$setting} is not set ({$label}), so a flip cannot remember that it happened and re-applies on every poll."
                        : "swrr.pgcat.{$setting} is not set ({$label}), so the mutex a concurrent flipper stands down on cannot be taken.",
                );

                continue;
            }

            $directory = dirname($path);

            if (!self::directoryWritable($directory)) {
                $problems[] = $this->fileProblem(
                    $setting,
                    self::FILE_DIRECTORY_UNWRITABLE,
                    $directory,
                    $setting === 'state_file'
                        ? "{$label} directory [{$directory}] is not writable, so a flip reports success while recording nothing and restarts pgcat again on the next poll."
                        : "{$label} directory [{$directory}] is not writable, so the mutex a concurrent flipper stands down on cannot be taken.",
                );
            }
        }

        return $problems;
    }

    /**
     * One file problem, with the repair line this package can state for it — or none, where the
     * repair is a path or an owner only the installation knows.
     *
     * `path` is the path the problem is about, which is the directory itself for the two
     * directory problems: that is what a mode is changed on, so it is what the line names.
     *
     * @return array{setting: string, kind: string, path: string, sentence: string, suggestion: string|null}
     */
    private function fileProblem(string $setting, string $kind, string $path, string $sentence): array
    {
        return [
            'setting' => $setting,
            'kind' => $kind,
            'path' => $path,
            'sentence' => $sentence,
            'suggestion' => $this->suggestionForFileProblem($setting, $kind, $path),
        ];
    }

    /**
     * The line that clears a file problem, or null when the package cannot name one.
     *
     * Two kinds of repair qualify, and they are the two the package knows rather than guesses:
     *
     *   - **a mode**, for a path this installation has chosen and cannot use. The line adds the
     *     bit the check found missing (`chmod +r` for a source that cannot be read, `chmod +w` for
     *     a file or a directory a flip must write, and both bits for a directory, which a rename
     *     also has to traverse). It is deliberately a symbolic add rather than a mode: it changes
     *     nothing the operator set beyond the bit that failed. It is also deliberately unscoped —
     *     the check was run as whoever ran the command, so the line has to clear it for that user
     *     rather than for a class this class cannot know. An installation whose pgcat config
     *     carries credentials should scope it to the user pgcat runs as; the row says which path
     *     and which bit, which is what a narrower repair needs.
     *   - **the key a flip reads**, for a path setting that is empty and whose value the published
     *     `config/db-manager.php` ships — see documentedPaths(). A key with no published value
     *     (`readers_path`, `no_readers_path`) gets no line: the value is this installation's to
     *     choose, and a path this package invented would replace the operator's intent rather
     *     than carry it out.
     *
     * A path that simply is not there gets no line either, and that is the same rule: the file has
     * to be put in place by whatever installs pgcat, and the package does not know where it went.
     */
    private function suggestionForFileProblem(string $setting, string $kind, string $path): ?string
    {
        $mode = match ($kind) {
            self::FILE_UNREADABLE => '+r',
            self::FILE_UNWRITABLE => '+w',
            self::FILE_DIRECTORY_UNWRITABLE => '+wx',
            default => null,
        };

        if ($mode !== null) {
            return sprintf('chmod %s %s', $mode, escapeshellarg($path));
        }

        $published = self::documentedPaths()[$setting] ?? null;

        return $kind === self::FILE_UNSET && $published !== null
            ? sprintf('swrr.pgcat.%s = %s', $setting, var_export($published, true))
            : null;
    }

    /**
     * A directory that exists and will accept the temp file a flip writes into it.
     */
    private static function directoryWritable(string $directory): bool
    {
        return is_dir($directory) && is_writable($directory);
    }

    /**
     * The pgcat setting line that clears the gate problem a row is reporting, or null when the
     * problem is not repaired by a value this package can name.
     *
     * The switch is on where pgcat cannot act, so the value that stops the warning is the one
     * that turns it off — and the sentence already says so ("Set swrr.pgcat.enabled = false,
     * or point <the configured connection> at the pgcat-fronted connection"). The line is what a *sentence*
     * cannot be: something to paste, and a string a job can read out of `--json`. The sentence
     * and the line agree, which is the point — and the sentence stays, because the flip's refusal
     * and `--dry-run` print it too, and neither of those has a suggestion column.
     *
     * Null for the gate row's other failure — armed with a path a flip needs unset, which the row
     * names as a key (`swrr.pgcat.no_readers_path`) whose value is this installation's to choose,
     * so a path guessed here would replace the operator's intent rather than carry it out. That
     * row's sibling prints a line for the same state *where the published config ships a value* —
     * see `fileProblems()`. Also null when the row passed this boot and is only carrying a
     * mismatch still on record: the configuration has nothing to change, because a boot that sees
     * the gate open closes the record out.
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
     * An unknown program is the closest of those, and the line stays absent deliberately. The
     * step can now name what supervisord *is* running — the verdict's `near_misses` — and a row
     * prints that in its sentence for an operator to read. A line is a different thing: it is
     * written to be applied, by a gate or a checklist, without anyone judging it. Replacing a
     * program name with the nearest running one is a judgement — `pgcat_x:pgcat_00` is the
     * closest *name*, not evidence that this flip belongs to that pool — so it belongs in the
     * sentence, where it is offered as the probable answer, and not in the value column.
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
     * The flipper is global: it follows the package's active connection —
     * `db-manager.swrr.connection` when an installation names one, and
     * `database.default` otherwise — not whichever connection a caller happens
     * to be inspecting.
     *
     * `reason` and `armed_reason` are exact opposites: exactly one of them is
     * null, and which one says whether the gate opened or closed. `mismatch`
     * flags the configuration that reads as armed but can never act, and
     * `warning` carries the sentence for it (or for an incomplete arming).
     *
     * `window` is the container's boot window — when it booted, how long it had, whether that
     * has run out, and whether a flip converged inside it. It is part of this block rather
     * than a separate accessor because `/health/db` and `--status` must not be able to read
     * two different answers to "did this container ever work".
     *
     * `reader_window` is {@see readerWindowVerdict()}: whether the pooler is on the
     * configuration the reader windows ask for *now*, which the boot window above cannot say.
     * It travels with this block for the same reason the window does — `/health/db`,
     * `db:replica-status` and `db:pgcat-flip --status` must not be able to read two different
     * answers to one question.
     *
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null, window: array<string, mixed>, reader_window: array{expected: string, applied: string|null, in_step: bool|null, failed: bool, reason: string|null}}
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
            'window' => $this->windowStatus(),
            'reader_window' => $this->readerWindowVerdict(),
        ];
    }

    /**
     * Whether the pooler is on the configuration the reader windows ask for right now.
     *
     * `expected` is what the resolver says the mode should be, `applied` is what pgcat's file
     * actually holds, and `failed` marks a disagreement between the two: the pooler holds the
     * variant the windows are not asking for, so reads are routing the wrong way. Both
     * directions fail, for the same reason — the file and the windows are two statements about
     * one routing decision, and a stale one is stale whichever way round it is:
     *
     * - **A reader window is open and the writer-only config is in place.** Every read the
     *   resolver meant for the replica pool reaches the writer instead.
     * - **No reader window is open and the readers config is in place.** Every read through the
     *   pooler reaches a replica during the hours the fallback holds reads on the writer — the
     *   mode the windows asked for at the end of the last window was the one that never
     *   arrived, and the file is the evidence of that.
     *
     * `applied` is null when the file cannot be compared to either variant — the target is not
     * there, cannot be read, or holds bytes that are neither (an operator's own edit, a
     * half-written file). That is reported as `in_step: null` rather than as a failure, because
     * the package does not know enough to name a fault — the same line `ReaderWindows` draws
     * when it refuses to guess at what a malformed setting meant.
     *
     * The check is not judged at all in two cases, and both report `failed: false`: while the
     * flipper is inert (nothing was asked of the pooler, so there is no expectation to be out
     * of step with), and while the resolver is permissive (with `reader_windows` unset there is
     * no window to be inside of, and a pooler the setting leaves alone is not a pooler that is
     * wrong).
     *
     * One case is suppressed rather than judged: **while the container's boot window is still
     * open**. A task that starts inside a reader window is out of step until its first flip lands —
     * the entrypoint writes one of the two variants and the per-minute flip moves it — and failing
     * that would fail every healthy deploy for as long as the pooler takes to converge, which is
     * the exact reason the boot window is measured from boot rather than from "now". It is the
     * same line `FlipWindow::closed()` already draws, reused rather than re-argued: a starting
     * container gets its eight minutes, and the check begins where the boot window ends.
     *
     * The consequence is worth naming: with no boot stamp at all (`window.source: no_stamp` — a
     * local run, or an image whose entrypoint predates the stamp) `closed()` is false, so this
     * check stays quiet for the same reason the boot verdict does. That silence is inherited
     * deliberately rather than worked around; `applied` is still reported, so a reader can see the
     * file without a verdict being invented about it.
     *
     * This reads two small files, so it is deliberately not part of `armingWarning()`, which
     * promises to look at configuration only. It is called by `healthSummary()` and
     * `windowStatus()`'s consumers — surfaces that already read the state file — and never by
     * `disabledReason()` or a flip's own hot path.
     *
     * @return array{expected: string, applied: string|null, in_step: bool|null, failed: bool, reason: string|null}
     */
    public function readerWindowVerdict(): array
    {
        $expected = $this->resolver->currentModeName();

        if (!$this->isEnabled() || $this->resolver->isAlwaysReaderMode()) {
            return [
                'expected' => $expected,
                'applied' => null,
                'in_step' => null,
                'failed' => false,
                'reason' => null,
            ];
        }

        $applied = $this->appliedMode();
        $failed = $applied !== null && $applied !== $expected && $this->window->closed();

        return [
            'expected' => $expected,
            'applied' => $applied,
            'in_step' => $applied === null ? null : $applied === $expected,
            'failed' => $failed,
            'reason' => $failed ? $this->readerWindowReason($expected, $applied) : null,
        ];
    }

    /**
     * The mode pgcat's own configuration is in, read off the file rather than remembered.
     *
     * The comparison is bytes, in the direction the flip copies them: the target holds the
     * readers variant, the writer-only variant, or something else. Bytes because that is what a
     * flip puts there — `swap()` writes the source's contents verbatim — so a file that differs
     * from both is a file this package did not put in place, and the answer for it is null
     * rather than the nearer-looking guess.
     *
     * It deliberately does not consult the state file's `last_mode`. That field records what a
     * flip *applied*, and it is written only by a flip that got that far: a run that aborted
     * before the flipper (a mutex that could not be taken, a scheduler that never fired) leaves
     * the previous file in place and this field empty — the exact shape of the incident this
     * exists to catch, where a `last_mode`-based check would have answered "unknown" while the
     * pooler was provably writer-only.
     *
     * The rendered files are what is compared, not the templates: the entrypoint injects
     * credentials into both variants at container start, so the copy a flip makes and the file
     * it reads back stay byte-identical.
     */
    public function appliedMode(): ?string
    {
        $target = ConfigValue::string($this->config['config_path'] ?? null);

        if ($target === '' || !is_file($target)) {
            return null;
        }

        $contents = @file_get_contents($target);

        if ($contents === false) {
            return null;
        }

        foreach (['readers' => 'readers_path', 'writer' => 'no_readers_path'] as $mode => $key) {
            $source = ConfigValue::string($this->config[$key] ?? null);

            if ($source === '' || !is_file($source)) {
                continue;
            }

            if (@file_get_contents($source) === $contents) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Why a pooler holding the configuration the windows are not asking for matters, and what moves
     * it: the sentence `/health/db` reports as `reader_window.reason` and logs beside the degraded
     * status.
     *
     * Both directions are one sentence's worth of a difference, so there are two sentences and the
     * one that applies is chosen by `$expected` — the mode the windows ask for, which is by
     * definition not the one the pooler is on. Each names the file that is in place, because that is
     * the fact an operator checks by hand, and the command that moves it — a flip run by hand while
     * the schedule is being repaired. Neither guesses at *why* the flip stopped, which the boot
     * window's own reason already does for the case it can see.
     *
     * `$applied` cannot be null here: this is only called for a file the comparison could place, and
     * which of the two paths to name follows from it.
     */
    private function readerWindowReason(string $expected, string $applied): string
    {
        $holding = ConfigValue::string($this->config[$applied === 'readers' ? 'readers_path' : 'no_readers_path'] ?? null);

        return $expected === 'readers'
            ? sprintf(
                'a reader window is open, so reads should use the replica pool, but pgcat is still on '
                .'the writer-only config [%s]: every read through the pooler reaches the writer. Run '
                .'db:pgcat-flip (or db:pgcat-window-flip) to move it, and check why the scheduled flip '
                .'stopped.',
                $holding,
            )
            : sprintf(
                'no reader window is open, so reads should use the writer, but pgcat is still on '
                .'the readers config [%s]: every read through the pooler reaches a replica during the '
                .'hours the fallback holds reads on the writer. Run db:pgcat-flip (or '
                .'db:pgcat-window-flip) to move it, and check why the scheduled flip stopped.',
                $holding,
            );
    }

    /**
     * The container's boot window as one block: the window itself, plus what the recorded runs
     * inside it say about convergence.
     *
     * `failed` is the field `/health/db` acts on, and it is deliberately only ever true *after*
     * the window has closed: while a container is still inside its window, a flip that has not
     * converged yet is a container still starting, not a broken one. The three ways to be
     * `failed` are told apart in `reason`, because they call for different work — a scheduler
     * that never ran the flip, a flip that ran and kept failing, and a flip that succeeded
     * outside the window (which means the container was too slow, not that anything is broken).
     *
     * `converged` is sticky for the container's life: it is set by the first run that reached a
     * usable mode, and never cleared, so a later failure cannot retroactively un-work a
     * container that did come up. `runs` counts every recorded run, which is what makes "the
     * flip is scheduled" observable at all.
     *
     * @return array<string, mixed>
     */
    public function windowStatus(): array
    {
        $window = $this->window->toArray();
        $state = $this->readState();
        $convergedAt = $this->stateTime($state, 'converged_at');

        $converged = $convergedAt !== null && $this->window->contains($convergedAt);
        $runs = ConfigValue::int($state['runs'] ?? null, 0);
        $closed = (bool) $window['closed'];

        $reason = null;

        if ($closed && !$converged) {
            $reason = match (true) {
                $runs === 0 => 'the flip never ran inside this container\'s window: db:pgcat-flip is not being scheduled, or it cannot reach the flipper',
                $convergedAt !== null => 'every flip run inside the window failed',
                default => 'the flip has not reached a usable mode inside the window',
            };
        }

        return [
            ...$window,
            'runs' => $runs,
            'last_run_at' => $state['last_run_at'] ?? null,
            'last_kind' => $state['last_kind'] ?? null,
            'converged' => $converged,
            'converged_at' => $state['converged_at'] ?? null,
            'converged_mode' => $state['converged_mode'] ?? null,
            'failed' => $closed && !$converged,
            'failed_reason' => $reason,
        ];
    }

    /**
     * The window itself, for a caller that only needs the bounds — `db:doctor` and the deploy
     * rehearsal both ask "would a flip act", and the bounds answer it without reading state.
     */
    public function window(): FlipWindow
    {
        return $this->window;
    }

    /**
     * Why a flip stopped: the two numbers that decided it, so the sentence can be checked
     * against the container rather than taken on trust.
     */
    private function windowClosedReason(): string
    {
        $window = $this->window->toArray();

        return sprintf(
            'the flip window closed: this container booted at %s and had %d seconds, so no '
            .'further attempts are made until it is replaced',
            $window['booted_at'] ?? '(an unrecorded time)',
            $window['window_seconds'],
        );
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
     * Both pgcat switches in a `swrr.pgcat` block, in the one place they are read.
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
     * Static and taking a block rather than reading `$this->config`, because a block can be
     * judged before it is installed: `db:doctor --config-file` reports the switches a candidate
     * `config/db-manager.php` would have refused, with no flipper built and nothing read from
     * the application's own configuration. One method answers both callers, so a value a
     * pipeline passes and a value a boot refuses cannot be two readings of the same rule.
     *
     * @param array<string, mixed> $pgcat the `swrr.pgcat` block as written
     * @return array{on: array{enabled: bool, use_reload: bool}, refused: array<string, string>}
     */
    public static function switchReadingsIn(array $pgcat): array
    {
        $on = [];
        $refused = [];

        foreach (['enabled' => self::ENABLED_DEFAULT, 'use_reload' => self::USE_RELOAD_DEFAULT] as $key => $default) {
            $reading = SwitchValue::read($pgcat[$key] ?? null, $default);

            $on[$key] = $reading['on'];

            if ($reading['refused'] !== null) {
                $refused['swrr.pgcat.'.$key] = $reading['refused'];
            }
        }

        return ['on' => $on, 'refused' => $refused];
    }

    /**
     * The block this flipper was built with, classified by `switchReadingsIn()`.
     *
     * @return array{on: array{enabled: bool, use_reload: bool}, refused: array<string, string>}
     */
    private function switchReadings(): array
    {
        return self::switchReadingsIn($this->config);
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
                .'%s at the pgcat-fronted connection.',
                $this->connectionName ?: '(unset)',
                $this->driver ?: '(unset)',
                $this->connectionSource,
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
     *
     * The one verdict that is not a refusal is `SupervisorStep::FAULT_NOT_RUNNING`: supervisord
     * knows the program and the answer says it is STARTING, BACKOFF or FATAL. There the ordering
     * above is inverted on purpose, because the daemon being down is what makes the swap a
     * *repair*: the file being replaced is the one pgcat could not start on, so it goes in
     * first, and `start` follows — the reload and restart commands act on a program that is
     * already up, and supervisor answers `ERROR (not running)` for one that is not. If the start
     * never takes, the new file stays: putting the old one back would restore exactly the config
     * that could not boot, which is the failure this path exists to end.
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

        // A pgcat supervisord knows and is not running: the flip is the repair, not a hot swap.
        $repair = $verdict['fault'] === SupervisorStep::FAULT_NOT_RUNNING;

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

        if ($repair) {
            try {
                $this->startAndWait();
            } catch (\Throwable $e) {
                // No rollback here, deliberately: rollBack() exists to keep the disk in step with
                // a process that is already running the old mode, and there is no such process —
                // the file just written is the repair and the one it replaced is the config
                // pgcat could not start on. The mode is left unrecorded for the same reason, so
                // the next poll writes the same file again and retries the start.
                throw new \RuntimeException(sprintf(
                    '%s — the new config was left in place (%s) and no mode was recorded, so the next flip retries',
                    $e->getMessage(),
                    $target,
                ));
            }

            return;
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

    /**
     * The command a repair runs to bring pgcat up on the file that was just written.
     *
     * `swrr.pgcat.start_command` when an installation starts pgcat some other way; otherwise
     * derived from the command a flip would have run, so the binary, the group name and the
     * quoting are the ones `SupervisorStep` already judged to resolve and to reach supervisord.
     */
    private function startCommand(): string
    {
        $configured = ConfigValue::string($this->config['start_command'] ?? null);

        if ($configured !== '') {
            return $configured;
        }

        return SupervisorStep::startCommand($this->supervisorCommand()) ?? self::DEFAULT_START_COMMAND;
    }

    /**
     * Start pgcat and read its state back, up to a bounded number of attempts.
     *
     * The read-back is what makes this self-healing rather than a single optimistic command: a
     * program that is inside supervisor's `startsecs` window answers `STARTING` for a few
     * seconds after a start that is going to succeed, and a program in a crash loop answers the
     * same thing after one that is not. Only a state read after the start separates the two, and
     * it is also what lets the retry be bounded — an attempt that ends in RUNNING is the end of
     * the repair, whatever the exit code said.
     *
     * Throws when the attempts run out, naming each one: the caller does not roll back, so the
     * message is the only record of the failure — and the state file is left unwritten, which is
     * what makes the next poll try again.
     */
    private function startAndWait(): void
    {
        $command = $this->startCommand();
        $attempts = max(1, ConfigValue::int($this->config['start_attempts'] ?? null, self::START_ATTEMPTS_DEFAULT));
        $delayMs = max(0, ConfigValue::int($this->config['start_retry_delay_ms'] ?? null, self::START_RETRY_DELAY_MS_DEFAULT));
        $failures = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            [$exitCode, $stdout, $stderr] = ($this->commandRunner)($command);

            if ($exitCode === 0) {
                $verdict = $this->supervisorStep->inspect($this->supervisorCommand());

                if ($verdict['usable'] && $verdict['fault'] === null) {
                    return;
                }

                $failures[] = sprintf('attempt %d: %s', $attempt, $verdict['detail']);
            } else {
                $failures[] = sprintf(
                    'attempt %d: %s exited %d: %s',
                    $attempt,
                    $command,
                    $exitCode,
                    trim($stdout.' '.$stderr),
                );
            }

            // No sleep after the last attempt: the caller is about to report the failure, and
            // a poll that has given up has nothing left to wait for.
            if ($attempt < $attempts && $delayMs > 0) {
                ($this->sleeper)($delayMs * 1000);
            }
        }

        throw new \RuntimeException(sprintf(
            'pgcat did not come up after %d attempt(s) of "%s": %s',
            $attempts,
            $command,
            implode('; ', $failures),
        ));
    }

    /**
     * The state file as an array — every key, not just the last mode, because the file now
     * carries the run bookkeeping the window is judged on as well as the mode a flip applied.
     *
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $raw = @file_get_contents($this->stateFile);

        if ($raw === false || $raw === '') {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge keys into the state file.
     *
     * A merge rather than a rewrite, and that is the whole reason this method replaced the
     * `file_put_contents` the old `writeLastMode` did: the file now holds facts written at
     * different moments — the last applied mode, the run counters, and the one-time record of
     * when this container converged — and a flip that overwrote the file with its own four keys
     * would erase the convergence the window is judged on. On a later flip that would move
     * `converged_at` forward, which could put it *outside* the window and fail a container that
     * had in fact come up in time.
     *
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function readLastMode(): ?string
    {
        $lastMode = $this->readState()['last_mode'] ?? null;

        return is_string($lastMode) ? $lastMode : null;
    }

    private function writeLastMode(string $mode, ?string $previousMode): void
    {
        $this->writeState([
            ...$this->readState(),
            'last_mode' => $mode,
            'previous_mode' => $previousMode,
            'last_flipped_at' => $this->nowIso(),
            'flipped_by' => 'pgcat-flipper',
        ]);
    }

    /**
     * Record one run of the flip, whether or not it changed anything.
     *
     * Called inside the lock, on every path that got that far, because "the flip ran and had
     * nothing to do" and "the flip never ran" are the two states a booting container can be in
     * and only one of them is healthy. `converged_at` is written once and never moved: the first
     * run that reached a usable mode is what this container's window is judged on, and a later
     * failure must not be able to un-work a container that did come up.
     */
    private function recordRun(string $kind, string $mode): void
    {
        $state = $this->readState();
        $usable = in_array($kind, [FlipResult::KIND_FLIPPED, FlipResult::KIND_NO_CHANGE], true);

        $state['runs'] = ConfigValue::int($state['runs'] ?? null, 0) + 1;
        $state['last_run_at'] = $this->nowIso();
        $state['last_kind'] = $kind;
        $state['last_mode_active'] = $mode;

        if ($usable && !isset($state['converged_at'])) {
            $state['converged_at'] = $this->nowIso();
            $state['converged_mode'] = $mode;
        }

        $this->writeState($state);
    }

    /**
     * A timestamp in the state file as unix seconds, or null when it is absent or unreadable.
     * The values are DATE_ATOM, which carries its own offset, so this parses to an absolute
     * moment rather than to whatever the reading process' timezone happens to be.
     *
     * @param array<string, mixed> $state
     */
    private function stateTime(array $state, string $key): ?float
    {
        $raw = $state[$key] ?? null;

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $parsed = strtotime($raw);

        return $parsed === false ? null : (float) $parsed;
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM);
    }
}

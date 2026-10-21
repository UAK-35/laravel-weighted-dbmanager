<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\SwitchValue;
use Uak35\WeightedDbManager\Support\WritableFile;
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
 *   Format: JSON { "last_mode": "readers"|"writer", "last_flipped_at": ISO,
 *                  "flipped_via": "schedule"|"window"|"manual",
 *                  "pool_repairs": N, "last_pool_repair_at": ISO,
 *                  "last_repair_pool": NAME, "last_repair_mode": MODE,
 *                  "pool_repair_times": [ISO, ...], ... }
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
     * Which mechanism applied the mode the state file records last: the `flipped_via` key.
     *
     * One value per path that can move the pooler — the scheduled entry's own apply, a
     * reader-window task, and an operator running `db:pgcat-flip --force-mode`. The vocabulary is
     * closed for the same reason `FlipResult::KIND_*` is: a surface reads one of these to say
     * *who* moved the mode, and a fourth spelling would be a reading nothing can render.
     * `manual` is what a caller that does not say gets, because a caller this class does not
     * know is somebody's hand rather than a schedule.
     */
    public const VIA_SCHEDULE = 'schedule';

    public const VIA_WINDOW = 'window';

    public const VIA_MANUAL = 'manual';

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
     * The state key the repair times are kept under, beside the count they belong to.
     *
     * A constant because three readers ask for it by name — the writer in `withRepair()`, the
     * reading in `poolRepairRate()`, and a test that seeds a state file to be read — and a
     * hand-written string in each of them is how the writer and the reader begin to disagree
     * about one file without either of them looking wrong.
     */
    public const REPAIR_HISTORY_KEY = 'pool_repair_times';

    /**
     * How often a repair is too often, when the configuration does not say: more than three in an
     * hour is the shipped line.
     *
     * A repair is only possible on a run that applies a mode or finds the mode already applied
     * inside the boot window — the flip's two repair paths — so the repairs a container can
     * record are the ones its first eight minutes produced and the ones a mode it applied at a
     * boundary asked for. The first eight minutes alone can produce three, and a pooler that
     * refused the pools it was just given more than once is the one worth reading; a single repair
     * is the self-heal doing its job, and the hour is wide enough that a container booting twice
     * inside it is not read as one pooler failing twice.
     */
    public const DEFAULT_MAX_REPAIRS_PER_WINDOW = 3;

    /**
     * The window the shipped line is drawn over, in seconds: an hour.
     *
     * It is also how much repair history the state file keeps, so a file is bounded by the
     * window rather than by the life of the container. A window widened later starts from the
     * times the narrower one kept, which is a limit of keeping the history small enough to write
     * on the poll path rather than a fact about the pooler.
     */
    public const DEFAULT_REPAIR_WINDOW_SECONDS = 3600;

    /**
     * The most repair times the state file keeps, whatever the window asks for.
     *
     * The window is the bound that matters — a repair needs a flip that is applying a mode or is
     * inside its boot window — and this is the ceiling that keeps a hand-edited or misconfigured
     * state file from growing one array for the life of a container.
     */
    private const REPAIR_HISTORY_LIMIT = 100;

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

    /**
     * How one configured pool is asked of the running pooler, or null when nothing probes: the
     * answerability half of the self-heal. `\Closure(string $database): array` — the verdict
     * `PoolProbe::ask()` returns. Defaulted from the `pool_probe` config slice, and injectable so
     * a test can answer without a socket.
     */
    private readonly ?\Closure $poolProbe;

    /**
     * How every configured pool is asked at once, or null when this installation's probe can only
     * answer one name at a time. `\Closure(list<string>): list<array>` — one verdict per name, in
     * the order asked, as `PoolProbe::ask()` returns them. Defaulted beside the single probe, so
     * the shipped path opens one non-blocking connection per pool under one deadline; a host that
     * injected `$poolProbe` keeps its own transport, and therefore its own one-at-a-time order.
     */
    private readonly ?\Closure $poolSweep;

    /**
     * How the pooler's own admin console lists the pools it holds, or null when that question is
     * not asked. It is the fallback the front door needs: a pooler that authenticates before it
     * resolves a pool answers every name, and the console is the only statement left that names
     * its pools.
     */
    private readonly ?\Closure $poolAdmin;

    /**
     * The scheduled entry's beat and the bound its silence is judged against. Built from the same
     * config slice as the window beside it — and given that window, when the window was defaulted
     * here rather than injected, so a test that fakes the container's clock fakes it for both.
     */
    private readonly ScheduleBeat $beat;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * @param array<string, mixed> $config pgcat config slice (see config/db-manager.php)
     * @param array{write: string, read: list<string>} $connectionPools the pool names the pinned
     *        connection is written with, as `VariantShape::namedPools()` reads them out of it
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
        // How a running pooler is asked whether it is answerable for a pool, or null to leave
        // the pooler's answer unexamined. Defaulted from `swrr.pgcat.pool_probe` below, and
        // injected so a test can answer without opening a socket.
        ?\Closure $poolProbe = null,
        // How every declared pool is asked at once, under one deadline — the shipped reading of the
        // front door when the console cannot be read. Defaulted beside the single probe below,
        // and left null when a host injected its own probe: that closure is that host's transport,
        // and this package should not open a second set of connections behind it.
        ?\Closure $poolSweep = null,
        // How the pooler's own admin console lists what it holds — the reading the sweep asks
        // first, with the startup packets left for a console that cannot be read. Defaulted below
        // beside the probe, and injected so a test can answer without a console — and so an
        // installation whose console this package cannot read has one place to say how it is
        // asked.
        ?\Closure $poolAdmin = null,
        // The beat the scheduled entry leaves and the tolerance its silence is judged with.
        // Defaulted from `swrr.pgcat.schedule` and the window above, so the two readings of
        // "has this container been moving" share one clock and one boot stamp.
        ?ScheduleBeat $beat = null,
        // The boot audit's record. Passed in rather than read from this slice, because it is
        // `swrr.audit.file` rather than a pgcat key — and judged here anyway, because it is one
        // of the paths this package has to be able to write while a flip is armed and the whole
        // point of the reading below is that it is *one* list: a path judged unwritable here is
        // judged unwritable by the row and by the boot record alike. Null means the caller did
        // not resolve it, and then it is not judged.
        private readonly ?string $auditFile = null,
        // The pool names the pinned connection is written with — the pool it writes through and the
        // pools its `read` list weights — told to the flipper by whoever resolved that connection,
        // because a flipper holds the pgcat slice and not the connection's own entry. Empty means
        // nothing was resolved, and then a variant's shape is read but nothing is held against it.
        // See `VariantShape::namedPools()`.
        private readonly array $connectionPools = ['write' => '', 'read' => []],
        // How one address is asked whether it resolves from this host, for the reading that reports
        // a pool the container's boot would prune. Injected so a test can answer from a strict
        // allowlist rather than reach a resolver — the fixture discipline the boot's own prune test
        // uses — and so an installation that resolves names some other way has one place to say so.
        private readonly ?\Closure $hostResolver = null,
    ) {
        $this->window = $window ?? FlipWindow::fromConfig($config);
        $this->beat = $beat ?? ScheduleBeat::fromConfig($config, $this->window);

        // Null is a decision, not a fallback: an installation that has not switched the probe
        // on gets no closure, so nothing opens a socket on the poll path. The default is only
        // built from a config that asks for it.
        $this->poolProbe = $poolProbe ?? self::defaultPoolProbe($config);

        // Only beside the default probe: a host that injected its own single-name probe has said
        // how its pooler is reached, and batching names through this package's sockets beside it
        // would ask the same pooler twice by two different rules.
        $this->poolSweep = $poolSweep ?? ($poolProbe === null ? self::defaultPoolSweep($config) : null);

        // Only beside the default probe: an installation that injected its own probe has said how
        // its pooler is reached, and this package should not open a second connection to the
        // configured address beside it.
        $this->poolAdmin = $poolAdmin ?? ($poolProbe === null ? self::defaultPoolAdmin($config) : null);

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
            // The lock is one of the files a flip leaves behind, and the next run may be a
            // different account — the per-minute entry and the shell an operator ran are the
            // ordinary pair. A 0644 lock cannot be opened for writing at all, so that run would
            // report a lock it could not take instead of one nobody holds. The first call repairs
            // a lock another account left read-only, before the open can refuse it; the second
            // covers the file the open may have just created under this process's umask. Written
            // on the way in rather than on release, so a run killed while holding it leaves the
            // file usable too.
            WritableFile::handBack($file);

            $fp = @fopen($file, 'c');
            if ($fp === false) {
                return [false, null];
            }

            WritableFile::handBack($file);

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
    {        // First on every run that is the scheduled job — including the runs that decide nothing at
        // all, which is why it is here rather than beside the state writes below. The state file
        // already records what a run *did*, and that is not the question a schedule that stopped
        // raises: those keys are the boot window's evidence, written inside it and never again, so
        // a container whose cron dies an hour after it converged leaves a state file that reads
        // exactly like a healthy one. The beat is the other fact — that the entry ran — and it is
        // the only one a run can write without changing what the window found. See ScheduleBeat.
        //
        // After the switch below and not before it, because "there is no schedule to watch" and
        // "the schedule ran" are different facts and an inert flipper is the first: pgcat off, or
        // a connection this package will not touch, is an installation whose per-minute entry is
        // not registered either (`swrr.pgcat.schedule.enabled` follows `swrr.pgcat.enabled`), so a
        // beat stamped there would be a state file written for a run that is not scheduled — and
        // answering that pgcat's files are untouched. The window-closed path below is the opposite
        // case and keeps the beat: the entry is still registered and still running every minute
        // after the window has closed, and it is precisely that silence the beat exists to catch.
        $reason = $this->disabledReason();
        if (!empty($reason)) {
            return FlipResult::noChange(
                mode: $this->resolver->currentModeName(),
                reason: $reason,
            );
        }

        $this->beat();


        // The window is checked before the lock, because a closed window has nothing to
        // serialise: nothing is read, written or run, and taking the lock only to release it
        // would make a stopped flipper look busy. A run that lands here is not recorded
        // either — the convergence verdict is about what happened *inside* the window, so a
        // run after it has closed must not be able to change the answer. The beat written above
        // is the exception and the reason it is stamped before this check rather than with the
        // state writes below: "this run decided nothing" and "no run happened at all" are the
        // two states this method cannot tell apart from the state file alone once the window has
        // closed, and only the second one is a stopped schedule.
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
                // Nothing to change — but a mode that is already applied is not the same
                // fact as a pooler that is answering for it. supervisord says the program is
                // up; only the pooler can say whether it holds the pool, and a pooler that
                // does not is re-applied rather than reported healthy. This is the fault the
                // ordinary flip cannot see, because the mode never moved.
                $fault = $this->poolFault($currentMode);

                if ($fault !== null) {
                    // The same file, applied again: the pooler's own configuration is what is
                    // out of step, so the repair is a re-application and a restart, not a
                    // transition. A swap that fails is the catch below — a failed repair is a
                    // failed run, and the mode stays unrecorded so the next poll retries.
                    $this->swap($currentMode);
                    $this->recordRun(FlipResult::KIND_REPAIRED, $currentMode, $fault['database']);

                    return FlipResult::repaired(
                        mode: $currentMode,
                        reason: $this->poolFaultReason($fault, $currentMode),
                    );
                }

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

            // The file is in place and supervisord has been told to make pgcat pick it up —
            // which is not the same fact as pgcat having picked it up. A flip has never asked
            // that second question, and the state it cannot see is this one: the mode moved,
            // the file is the variant the new mode asks for, and the pooler is still answering
            // for the pools it held a moment ago, so every read to a pool the new variant adds
            // fails while `pgcat mode` compares two files and finds them in step. So the run
            // that applies a mode now reads the pooler back, the same way the run that found the
            // mode *already* applied does — see `poolFault()` above.
            $refusal = $this->refusalAfterApply($currentMode);
            $clause = '';
            $after = $refusal;

            if ($refusal !== null) {
                $repair = $this->restartOnAppliedFile();
                $clause = $repair['clause'];
                $after = $repair['attempted'] ? $this->refusalAfterApply($currentMode) : $refusal;
            }

            $this->writeLastMode($currentMode, $lastMode, self::VIA_SCHEDULE);

            // Applied, and the pooler did not answer for a pool the file declares. The run
            // reports the repair rather than the transition: the file and the mode are the
            // flip's job and both are in place, while what a reader has to act on is the
            // pooler. It exits 0 for the reason `FlipResult::KIND_REPAIRED` gives — the run did
            // what it was asked to — and the standing fault is `/health/db`'s `pool_probe`
            // block, which is where a state is reported rather than a run's outcome.
            if ($refusal !== null) {
                $this->recordRun(FlipResult::KIND_REPAIRED, $currentMode, $refusal['database']);

                return FlipResult::repaired(
                    mode: $currentMode,
                    reason: $this->appliedPoolReason($currentMode, $lastMode, $refusal, $clause, $after),
                );
            }

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
     *
     * It applies a mode, so it asks the same second question the scheduled flip asks: the file is
     * one fact and the pooler holding it is another — see `refusalAfterApply()`. This is the path
     * the reader-window tasks take at every boundary, in the middle of the day, which is exactly
     * when a pooler that did not act on the reload leaves every read to a pool the new variant
     * declares failing while `pgcat mode` compares two files and finds them in step. So a forced
     * flip reads the pools back with the retry a pooler that was just signalled needs, escalates a
     * refusal to `restart_command`, reads them once more, and reports `repaired` rather than
     * `flipped` when the pooler did not pick the mode up.
     *
     * What it still does not do is record a *run*. `runs`, `last_kind` and `converged_at` are the
     * boot window's evidence — a mode applied at 10:00 must not be able to move the convergence
     * verdict a deployment gate reads — so a forced run writes `last_mode` and nothing else about
     * itself. A repair is different: it is a fact about the pooler rather than about the window,
     * and it is counted through `withRepair()` wherever it happens, so a boundary the pooler
     * refused is in the same `pool_repairs` reading a boot-window repair lands in.
     *
     * WHAT IT DOES WRITE IS WHO MOVED THE MODE
     *   `$via` is the mechanism, and it lands in the state file as `flipped_via` beside the
     *   `last_mode` and `last_flipped_at` that both writers already leave there. That is what
     *   lets a reading say which mechanism last moved the pooler *without* claiming the move was
     *   a convergence: the run record stays the boot window's, and the mode record is the one
     *   both owners keep. A caller that does not say gets `manual` — this class knows the two
     *   commands that call it, and a third caller is somebody's hand.
     */
    public function forceMode(string $mode, string $via = self::VIA_MANUAL): FlipResult
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

            // The flip has applied the mode; whether the pooler holds it is the question the file
            // cannot answer. Same read, same policy and same escalation as the scheduled flip's
            // apply path (`applyCurrentState()`), because it is the same fault — only the reason
            // the mode moved is different.
            $refusal = $this->refusalAfterApply($mode);
            $clause = '';
            $after = $refusal;

            if ($refusal !== null) {
                $repair = $this->restartOnAppliedFile();
                $clause = $repair['clause'];
                $after = $repair['attempted'] ? $this->refusalAfterApply($mode) : $refusal;
            }

            $this->writeLastMode($mode, $lastMode, $via);

            if ($refusal !== null) {
                $this->writeState($this->withRepair($this->readState(), $mode, $refusal['database']));

                return FlipResult::repaired(
                    mode: $mode,
                    reason: $this->appliedPoolReason($mode, $lastMode, $refusal, $clause, $after),
                );
            }

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

        // The shape of the file this flip would put in front of pgcat, read before anything is
        // written or signalled rather than after the swap has already happened. It is performed
        // rather than reported — it reads three files and asks a resolver, and it changes nothing —
        // and it is the one step whose failure no later step can repair: a variant missing a pool
        // the connection writes through is a pooler that does not come back up, and a flip that
        // replaced the file first would have taken the running one away to get there.
        $shape = $this->variantShape();
        $shapeFailures = $shape->failures();
        $shapeWarnings = $shape->warnings();

        if ($shapeFailures !== []) {
            return DryRunResult::failed(
                mode: $mode,
                error: $shapeFailures[0]['sentence'].' — a flip would swap in a file this installation cannot serve, so nothing would be replaced',
                steps: [...$steps, [
                    'step' => 'variant shape',
                    'outcome' => DryRunResult::STEP_FAILED,
                    'detail' => implode(' ', array_column($shapeFailures, 'sentence')),
                ]],
            );
        }

        $steps[] = [
            'step' => 'variant shape',
            'outcome' => DryRunResult::STEP_DONE,
            // A warning is a reading, not a refusal: the pools a variant declares are the ones the
            // container's own boot would prune, and whether a name resolves here is a fact about
            // this host's resolver. So the step names them without failing the run, which is the
            // line the shape's own severity draws.
            'detail' => $shapeWarnings === []
                ? $shape->summary()
                : implode(' ', array_column($shapeWarnings, 'sentence')),
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

        // The read that makes a transition verified rather than assumed, and the one step of a
        // flip a rehearsal cannot perform — not because it changes anything, but because there is
        // nothing yet for it to be about. The pools it would ask after are the ones the *new*
        // variant declares, and the rename above has not happened: probing the pooler now would
        // put the pools of a variant that is not applied to it and collect a refusal the flip is
        // about to make untrue. So it is reported like the rename and the signal.
        $steps[] = [
            'step' => 'pool read',
            'outcome' => DryRunResult::STEP_WOULD,
            'detail' => $this->poolProbe === null
                ? 'nothing would be asked: the probe is not armed (swrr.pgcat.pool_probe.enabled, and a dialable `[general] port` in the pooler\'s own file), so the flip would report the transition on supervisord\'s word'
                : sprintf(
                    'the pools %s declares would be read back from the pooler, and a pool it refused would be repaired by a restart on that file',
                    $source,
                ),
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
        return WritableFile::canWrite($this->stateFile);
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
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null, window: array<string, mixed>, reader_window: array{expected: string, applied: string|null, in_step: bool|null, failed: bool, direction: string|null, reason: string|null}, config_path: string, readers_path: string, no_readers_path: string, restart_command: string, reload_command: string, use_reload: bool, start_command: string, start_attempts: int, start_retry_delay_ms: int, state_file: string, lock_file: string}
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
            'start_attempts' => $this->startAttempts(),
            'start_retry_delay_ms' => $this->startRetryDelayMs(),
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
     * The shape of what a flip would swap to: the pools each configured variant declares, held
     * against the pools the pinned connection is written with.
     *
     * It is one reading rather than two, and both surfaces ask for it here: `db:pgcat-flip --dry-run`
     * performs it as a step before it reports the swap, and `db:doctor`'s `variant shape` row prints
     * it, so a rehearsal and a preflight cannot disagree about whether a variant is one this
     * installation can serve. `VariantShape` owns the rules; this method hands it the three paths a
     * flip is armed with, the connection's own pool names and the resolver the prune reading asks.
     *
     * It is deliberately *not* gated on `isEnabled()`, which is the one way it differs from
     * `fileProblems()` below. The question here is what the files declare, and that is a fact about
     * three files rather than about whether anything is swapping them; a surface that must not judge
     * an inert flipper says so itself — `db:doctor`'s row names the arming, and a rehearsal cannot be
     * reached while the flipper is inert, because the gates refuse it before the steps begin.
     */
    public function variantShape(): VariantShape
    {
        return VariantShape::fromConfig(
            $this->config,
            $this->connectionPools,
            $this->connectionName ?? '',
            $this->hostResolver,
        );
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

        // The paths this package has to be able to write that no step of a flip names, judged
        // beside every path above because an unwritable one is the same class of fault as the rest:
        // the boot stamp the container's entrypoint writes, and the `.lock` companion two boots
        // serialise the audit record's write on. `writtenPaths()` is the set and its rule about when
        // each is judged, so how many are added is that table's length rather than a number stated
        // here. They were the gap the deployment this list came from found — a container that passed
        // every other gate and could not flip, logging that its target was not writable — so a
        // preflight that answers "can this installation carry out what it is configured to do?"
        // answers it about these too, in the row and the record that already list the rest. The
        // record *file*
        // is deliberately not among them: `store probe` and the boot audit's own finding judge it,
        // and a second sentence for it would be one fault reported twice.
        //
        // Each is judged as a *writer*: both may legitimately not exist yet (a container an hour
        // old has a stamp; one being provisioned does not), so what is asked is the directory
        // they will be created in, and the file itself only when it is already there and cannot be
        // written. The cost sentence is the reason the path is on the list at all, and the mode and
        // owner travel with it: see fileProblem().
        foreach ($this->writtenPaths() as $setting => $row) {
            $path = $row['path'];

            if ($path === '' || self::writable($path)) {
                continue;   // not resolved by the caller, or nothing to report about it
            }

            // The file itself when it is there, and the directory that will have to take it when
            // it is not — which is where the mode is changed either way, so it is the path the
            // sentence and the repair line name.
            $there = file_exists($path);

            $problems[] = $this->fileProblem(
                $setting,
                $there ? self::FILE_UNWRITABLE : self::FILE_DIRECTORY_UNWRITABLE,
                $there ? $path : dirname($path),
                $there
                    ? "{$row['label']} [{$path}] is not writable, so {$row['cost']}."
                    : "{$row['label']} directory [".dirname($path)."] is not writable, so {$row['cost']}.",
            );
        }

        return $problems;
    }

    /**
     * Every path this package must be able to write while a flip is armed, one reading for all of
     * them, each with the path whose mode decides the write, what an unwritable one costs, and
     * whether it can be written here.
     *
     * WHY ONE LIST
     *   `fileProblems()` answers "is one of these unusable?", and it is the list a fault is
     *   reported from. This answers the other half of the same question — "which paths are they,
     *   and are they writable?" — as one reading that exists in every state rather than only when
     *   something is wrong. A preflight whose only evidence is a failure cannot tell an operator
     *   what the package was going to write in the first place, and an installation that moves its
     *   state directory has no way to confirm the new one is the path being judged.
     *
     *   The two lists are deliberately not one method. A problem carries a setting, a kind, a
     *   sentence and a repair because a boot finding needs them; a reading carries a path, the
     *   cost of its absence, and the mode it was found with, because a row prints them whether or
     *   not anything failed. Folding one into the other would make the reading's shape depend on
     *   the fault set, which is the rule `db:doctor --json` refuses for its own rows.
     *
     * WHAT IS ON IT, AND WHAT IS NOT
     *   The flip target and the directory the atomic swap writes beside it; the two variants a
     *   deployment renders (a flip reads them, the entrypoint writes them); the state and lock
     *   files a flip records itself in; the boot stamp the window is measured from; and the audit
     *   record and its lock, which the package writes for a different reason — findings and the
     *   probe's throttle share that file. That is every path `fileProblems()` judges as a
     *   *writer*, plus the record itself, which `store probe` owns as a verdict and which is on
     *   this list because the question here is the inventory rather than the fault.
     *
     *   Empty while the flipper is inert, exactly like `fileProblems()`: nothing is written then,
     *   and a reading that named paths anyway would describe a swap that cannot happen.
     *
     * The path a reading names is the one whose mode decides the write — the file when it is
     * there, and the directory that will have to take it when it is not, which is the normal state
     * of the stamp and the record on a container that has not booted yet. `writtenPaths()` supplies
     * the ones no flip step of its own names, so their labels and costs cannot drift between the
     * fault and the inventory — and the set's length, which `docs/boot-audit-surfaces.md` counts, is
     * the table's rather than a number either docblock states.
     *
     * @return list<array{setting: string, path: string, label: string, cost: string, writable: bool, facts: string|null}>
     */
    public function writePaths(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $paths = [];

        $target = ConfigValue::string($this->config['config_path'] ?? null);

        if ($target !== '') {
            $paths[] = $this->writtenPath(
                'config_path',
                $target,
                'flip target',
                'a flip cannot get its copy into place, so the window it was meant to open never opens',
            );

            // The temp file and the rename that replaces the target both live in its directory,
            // so the target being writable is not enough on its own.
            $paths[] = $this->writtenPath(
                'config_path.directory',
                dirname($target),
                'flip target directory',
                'the atomic swap cannot write its temp file beside the target, so no copy reaches it',
            );
        }

        // Read by a flip, written by the deployment's entrypoint: an unwritable variant is a boot
        // whose render fails, and a flip with nothing to read for that direction.
        foreach ([
            'readers_path' => ['reader source', 'the entrypoint cannot re-render the readers variant, and a flip has nothing to read for that direction'],
            'no_readers_path' => ['writer source', 'the entrypoint cannot re-render the writer-only variant, and a flip has nothing to read for that direction'],
        ] as $setting => [$label, $cost]) {
            $path = ConfigValue::string($this->config[$setting] ?? null);

            if ($path !== '') {
                $paths[] = $this->writtenPath($setting, $path, $label, $cost);
            }
        }

        foreach ([
            'state_file' => ['flip state', 'a flip reports success while recording nothing, and restarts pgcat again on the next poll'],
            'lock_file' => ['flip lock', 'the mutex a concurrent flipper stands down on cannot be taken'],
        ] as $setting => [$label, $cost]) {
            $path = $setting === 'state_file' ? $this->stateFile : $this->lockFile;

            if ($path !== '') {
                $paths[] = $this->writtenPath($setting, $path, $label, $cost);
            }
        }

        // The two the fault list already carries — the boot stamp and the record's lock — so their
        // sentences and this inventory cannot describe the same path two ways.
        foreach ($this->writtenPaths() as $setting => $row) {
            if ($row['path'] === '') {
                continue;   // an unset key is the arming's problem, not a path to judge
            }

            $paths[] = $this->writtenPath($setting, $row['path'], $row['label'], $row['cost']);
        }

        $auditFile = $this->auditFile ?? '';

        if ($auditFile !== '') {
            $paths[] = $this->writtenPath(
                'audit_file',
                $auditFile,
                'audit record',
                'a finding and the probe\'s throttle cannot be recorded, so a fault is logged once and the store is PINGed on every boot',
            );
        }

        return $paths;
    }

    /**
     * One entry of the list above: the path whose mode decides the write, whether it can be
     * written, and the mode and owner it was found with.
     *
     * The file itself when it is there and the directory that will have to take it when it is not,
     * which is the same rule `writable()` judges by and the same path the fault sentences name.
     *
     * @return array{setting: string, path: string, label: string, cost: string, writable: bool, facts: string|null}
     */
    private function writtenPath(string $setting, string $path, string $label, string $cost): array
    {
        $subject = file_exists($path) ? $path : dirname($path);

        return [
            'setting' => $setting,
            'path' => $subject,
            'label' => $label,
            'cost' => $cost,
            'writable' => self::writable($path),
            'facts' => self::facts($subject),
        ];
    }

    /**
     * Whether this package can create or write a path: the file itself when it is already there,
     * and the directory it will be created in when it is not — which is the normal state of both
     * of the paths above on a container that has not booted yet.
     */
    private static function writable(string $path): bool
    {
        return file_exists($path) ? is_writable($path) : self::directoryWritable(dirname($path));
    }

    /**
     * The paths a flip step never names, as one table: where each is configured, the label a row
     * prints for it, what finding it unwritable costs, where its path comes from, and whether it is
     * judged only while the record it guards is usable.
     *
     * A table rather than code that builds the same array, because these facts have three readers
     * and one record counts them. `fileProblems()` judges each entry beside the paths a flip step
     * names, `writePaths()` prints the labels and costs from here so the fault and the inventory
     * cannot describe one path two ways, and `fileSettingPaths()` is the `config` column read back.
     * `docs/boot-audit-surfaces.md` says how many of the fault list's paths are not pgcat's at all,
     * and `ProseNumbersTest` binds that sentence to this table's length — so the count is derived
     * from the set rather than restated beside it, and a third path added here is a record that
     * fails until it says so rather than a sentence somebody has to remember to change.
     *
     * `boot_file` is judged whenever the window has a stamp path. `audit_lock` only while the record
     * it locks is usable: a record that cannot be written is one finding already — `BootAudit`'s and
     * the store probe's — and a lock judged beside it would be that same fault said twice.
     *
     * @var array<string, array{config: string, label: string, cost: string, from: string, needs_record_writable: bool}>
     */
    private const UNNAMED_WRITTEN_PATHS = [
        'boot_file' => [
            'config' => 'swrr.pgcat.boot_file',
            'label' => 'boot stamp',
            'cost' => 'the container cannot record when it booted, and the boot window never '
                .'closes: the entry flips on every run instead of standing down after it',
            'from' => 'boot_file',
            'needs_record_writable' => false,
        ],
        'audit_lock' => [
            'config' => 'swrr.audit.file.lock',
            'label' => 'audit lock',
            'cost' => 'two boots can write the record at once: one boot\'s findings are '
                .'overwritten by the other\'s rather than merged with them',
            'from' => 'audit_file',
            'needs_record_writable' => true,
        ],
    ];

    /**
     * The paths this package must be able to write while a flip is armed, and what an unwritable
     * one costs — the ones in `UNNAMED_WRITTEN_PATHS`, one entry per setting, each kept only while
     * the condition its row names holds.
     *
     * The consequences are the ones the faults were observed with rather than a general statement
     * about permissions: an unwritable boot stamp leaves `FlipWindow::closed()` permanently false,
     * so the per-minute entry keeps flipping instead of standing down after the window; an
     * unwritable record cannot remember a finding or the store probe's throttle, so a fault is
     * logged once and the store is PINGed on every boot; and an untakeable lock lets two boots
     * write the record at once, which is the merge the audit's own log line reports.
     *
     * `swrr.audit.file` is not a key of this class's slice — the provider resolves it and passes it
     * in, because the class that owns the paths is the class that should judge them. The record
     * *file* itself is deliberately not on this list: `db:doctor`'s `store probe` row and
     * `BootAudit`'s finding already judge it (`BootAudit::probeUnwritableSentence()`), the reading
     * belongs to the object that owns the record, and a second row printing the same sentence would
     * be one fault reported twice. What is on the list is the record's **lock**, which nothing
     * judged, and the boot stamp, which nothing judged. The record *is* on `writePaths()`'s list
     * below, which is the inventory rather than this fault list: it names every path the package
     * writes, and a record it cannot write is reported there with the cost of that. The two rows
     * print different sentences — the probe's is about the throttle — and both reach the verdict the
     * record's unwritability is, which is the aggregate's point rather than a repetition of it.
     *
     * @return array<string, array{path: string, label: string, cost: string}>
     */
    private function writtenPaths(): array
    {
        $auditFile = $this->auditFile ?? '';
        $paths = [];

        foreach (self::UNNAMED_WRITTEN_PATHS as $setting => $row) {
            // The lock is judged only while the record itself is usable. A record that cannot be
            // written is one finding — `BootAudit`'s own, the store probe's, which the row for the
            // record prints — and the lock's state beside it is moot: the same unwritable directory
            // would be two findings for one fault, and a reader would have to work out that they
            // are one fault from two sentences.
            if ($row['needs_record_writable'] && ($auditFile === '' || ! self::writable($auditFile))) {
                continue;
            }

            $paths[$setting] = [
                'path' => $row['from'] === 'boot_file' ? $this->window->bootFile() : $auditFile.'.lock',
                'label' => $row['label'],
                'cost' => $row['cost'],
            ];
        }

        return $paths;
    }

    /**
     * The config path each setting above is written at, for the record's context: neither is one of
     * pgcat's own — the boot stamp is the flip's and the lock is the audit record's.
     *
     * Read out of the table rather than written down again, so a setting added to the set is one
     * the record can name without a second line here to forget.
     *
     * @return array<string, string>
     */
    public static function fileSettingPaths(): array
    {
        $paths = [];

        foreach (self::UNNAMED_WRITTEN_PATHS as $setting => $row) {
            $paths[$setting] = $row['config'];
        }

        return $paths;
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
        // What the path was found *with*, after what is wrong with it: an operator reading "not
        // writable" is one question away from "by whom", and the answer is a fact the check
        // already holds — it asked the filesystem about this path. It is appended rather than
        // added as a field so a sentence stays one sentence wherever it is printed, and it is
        // absent for a path that is not there: there is no mode to quote for a file nobody has.
        $facts = self::facts($path);

        return [
            'setting' => $setting,
            'kind' => $kind,
            'path' => $path,
            'sentence' => $facts === null ? $sentence : rtrim($sentence, '.').' ('.$facts.').',
            'suggestion' => $this->suggestionForFileProblem($setting, $kind, $path),
        ];
    }

    /**
     * The mode and owner a path was found with, as an operator reads them — `mode 0644, owner
     * app:www-data` — or null when there is nothing to quote.
     *
     * Both halves matter to the fault this is printed beside: an unwritable flip target is
     * usually a file that belongs to another account, and the repair differs depending on
     * whether the mode is too narrow or the owner is somebody else. A numeric id is used when
     * the platform cannot name the account (no `posix` extension, a container with no passwd
     * entry), because an id an operator can `ls -n` against is a better answer than silence.
     *
     * Public because a second reading quotes it: the boot stamp a silent schedule is diagnosed
     * against has the same question asked of it ({@see ScheduleCauses}), and a container whose
     * stamp belongs to another account reads as one nobody stamped. One reading of mode and owner,
     * so the two sentences an operator can see quote the same facts in the same words.
     */
    public static function facts(string $path): ?string
    {
        if ($path === '' || !file_exists($path)) {
            return null;
        }

        $parts = [];
        $mode = @fileperms($path);

        if (is_int($mode)) {
            // The permission bits alone: the file type is what `is_dir()` already decided, and a
            // four-digit octal is what `chmod` and `stat` are read with.
            $parts[] = sprintf('mode %04o', $mode & 0o7777);
        }

        $owner = self::account(@fileowner($path), 'posix_getpwuid');
        $group = self::account(@filegroup($path), 'posix_getgrgid');

        if ($owner !== null) {
            $parts[] = 'owner '.($group === null ? $owner : $owner.':'.$group);
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * A user or group id as a name, or as the number itself when the platform cannot name it.
     */
    private static function account(int|false $id, string $namer): ?string
    {
        if ($id === false) {
            return null;
        }

        if (function_exists($namer)) {
            $entry = @$namer($id);

            if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                return $entry['name'];
            }
        }

        return (string) $id;
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
     * `schedule` is {@see scheduleStatus()}: whether the per-minute entry is still producing runs,
     * which is the one thing neither block beside it can see. The window judges a container's first
     * minutes and then stops, and the reader-window verdict speaks again only when a boundary moves
     * — so a schedule that died in between leaves a container that looks converged, is out of step
     * with nothing yet, and has no run coming. It travels here rather than in an accessor of its own
     * for the third time and the same reason: four surfaces print this verdict and none of them may
     * compute it.
     *
     * @return array{enabled: bool, configured_enabled: bool, connection: string, driver: string, driver_supported: bool, resolver_mode: string, last_mode: string|null, reason: string|null, armed_reason: string|null, mismatch: bool, warning: string|null, window: array<string, mixed>, reader_window: array{expected: string, applied: string|null, in_step: bool|null, failed: bool, direction: string|null, reason: string|null}, schedule: array{applicable: bool, stale: bool|null, not_judged: string|null, last_beat_at: string|null, age_seconds: int|null, stale_after_seconds: int, reason: string|null}}
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
            'schedule' => $this->scheduleStatus(),
        ];
    }

    /**
     * The schedule as a verdict: is the per-minute entry producing runs, and how long since it did.
     *
     * The other half of the boot window, and the half that has no end. The window asks whether a
     * *booting* container converged and answers once; this asks whether the entry is still running
     * and can answer at any moment of the container's life. Both are needed, and neither implies the
     * other: a container can converge inside its window and lose its schedule afterwards — which is
     * the state a container sat in for 85 minutes, with every reading healthy — and a container whose
     * schedule never started at all fails both, in their own words, because the two are different
     * questions about the same silence.
     *
     * Read off the state file rather than off the schedule, deliberately: `schedule:list` shows what
     * is *configured* to run, which is the question `db:doctor`'s `flip schedule` row already asks,
     * and the failure this exists for is a schedule that is configured exactly as expected and not
     * firing. The verdict itself — the tolerance, the four silences, the sentence — belongs to
     * {@see ScheduleBeat}, so the endpoint, the row and the watchdog command print one answer rather
     * than three ages of one file.
     *
     * @return array{applicable: bool, stale: bool|null, not_judged: string|null, last_beat_at: string|null, age_seconds: int|null, stale_after_seconds: int, reason: string|null}
     */
    public function scheduleStatus(): array
    {
        return $this->beat->verdict($this->readState());
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
     * `direction` names which way round a failure is, for the surfaces that print it: a writer-only
     * file while a reader window is open (`inside_window`) and the readers config left in place
     * outside every window (`outside_window`) are one fault seen from opposite ends of the last
     * window, and a report that leads with which one it found sends the operator to the right end of
     * it. It is null whenever `failed` is false, so a surface never prints a direction for a
     * disagreement that does not exist.
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
     * @return array{expected: string, applied: string|null, in_step: bool|null, failed: bool, direction: string|null, reason: string|null}
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
                'direction' => null,
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
            'direction' => $failed ? ($expected === 'readers' ? 'inside_window' : 'outside_window') : null,
            'reason' => $failed ? $this->readerWindowReason($expected, $applied) : null,
        ];
    }

    /**
     * Whether anything asks the running pooler about its pools, and what it answered for each.
     *
     * WHY THIS EXISTS
     *   `poolFault()` is the *repair* half of the pool-answerability self-heal, and it only ever runs
     *   inside a flip: `applyCurrentState()` reads it when the mode is already applied, and only
     *   while the container's boot window is open — the window tasks apply through `forceMode()`,
     *   which has no window, and which reads the pools back only on the run that applies a mode
     *   rather than on a poll afterwards. So the state this reading exists for is the one nothing
     *   else can report: a container past its boot window whose pgcat is RUNNING and holds no pool
     *   for a database the applied variant declares. Every read to that pool fails, supervisord
     *   calls the program healthy, `pgcat mode` compares two files and finds them in step, the
     *   forced path's one read was taken when it applied that mode, and the repair path will not
     *   run again. `/health/db`'s `pool_probe` block and `db:doctor`'s `pool
     *   probe` row are the two surfaces that say so, and both read this — so they cannot disagree.
     *
     * WHAT IT REPORTS
     *   `enabled` is whether a probe is armed: the switch is on, and the pooler's own file names a
     *   port to dial (`swrr.pgcat.pool_probe.enabled` and `[general] port`). A switch on a file that
     *   names no port arms nothing, because there is no address the pooler itself has stated — and a
     *   probe aimed by a guess could report a healthy pooler as silent. `applicable` is true when a
     *   probe is armed, the flipper applies, and the mode's variant declares at least one pool —
     *   that is, when there was something to ask. `pools` is one entry per pool the variant declares, in file
     *   order, each carrying the sweep's vocabulary (`answered`, `missing`, `indeterminate`,
     *   `silent`) and the sentence the exchange produced for it. `answered` is true when every pool
     *   is `answered` and false when one is not, and null when nothing was asked. `indeterminate`
     *   is a pool the pooler answered while it answered the sentinel too: the answer is real, and it
     *   would have been the same for a name no configuration declares, so it is not a pass.
     *
     *   `admin` is the pooler's own answer: `SHOW POOLS` on its admin console, asked first. It is
     *   the primary reading — one connection for the whole sweep, a list of the pools the process
     *   serves, and a statement that does not depend on when a pooler resolves a pool — so where it
     *   can be read, `pools` is built from that list and the front door is not asked at all. A pool
     *   the list does not carry is published as `missing`, with the sentence naming the console,
     *   because that is the fault this whole reading exists for. `admin` is that answer — its
     *   status, its pool names and its own sentence — or the refusal that made the front door the
     *   reading instead, or null when no console was configured.
     *
     *   The front-door fallback is one wave rather than one exchange per pool: every declared name
     *   and the control are opened together with `PoolProbe::askMany()`, and a single deadline
     *   bounds the lot, so a pooler that accepts and says nothing costs the configured timeout once
     *   instead of once per pool. A name still unanswered at that deadline is `silent`, with the
     *   same evidence shape a single exchange produces.
     *
     *   `discriminates` is what makes a front-door `answered` pool a *proof* or only an answer, and
     *   it is only asked on the fallback path. It is read off one control name no configuration
     *   declares: a refusal means the pooler resolves a pool before it authenticates, so `answered`
     *   is evidence the pool exists; an answer means it authenticates first and answers every name,
     *   so every pool that answered is published as `indeterminate` instead — the reading cannot be
     *   mistaken for a pass, which is what an `answered` pool would have been. The control travels
     *   in the same wave as the pools, but `control` is published only when no pool was refused: a
     *   `missing` pool is itself the proof and publishes null, and null is also what the console's
     *   own answer leaves, because there was nothing left for the control to settle. The sentinel's
     *   connection is paid for inside the wave rather than as a second wait.
     *
     *   `verified` is whether an `answered` pool is proof it exists: true when the console listed
     *   it or the control was refused; false when neither could be read; null while the control is
     *   unchecked.
     *
     *   `judged` is whether the boot window has closed, and therefore whether `failed` and
     *   `warned` are verdicts rather than descriptions: a surface that wants to say a reading is
     *   *unverified* has to know whether a container is still allowed to be starting, which is the
     *   line `failed` and `warned` already draw and the one a row cannot draw for itself.
     *
     *   `host`, `port` and `address` are where the packets were actually sent, taken from the
     *   answers rather than from the configuration: a probe aimed at the wrong port is the one fault
     *   that looks exactly like a pooler that is down, because both are silence, and a reader that
     *   only sees "no pool answered" has nowhere to go. Naming the address beside the refusal is what
     *   lets that installation diagnose itself. They are null when nothing was asked, and they are the
     *   probe's own answer rather than a reading of the settings — an installation that injects a
     *   probe reporting some other endpoint is reported at that endpoint.
     *
     *   It deliberately repairs, restarts and records nothing: the throw, the swap and the record
     *   belong to a flip, and a status reading that restarted a pooler would not be one an endpoint
     *   could be polled with. `failed` is the judgement, and it is the repair's own — `missing`, the
     *   pooler's refusal, and never `silent` or `indeterminate`, so a probe pointed at the wrong
     *   address or a pooler that authenticates first cannot fail a healthy installation.
     *
     * WHY A POOL IS ONLY JUDGED AFTER THE BOOT WINDOW
     *   While the window is open the repair still has somewhere to run: the per-minute flip
     *   re-applies the variant and restarts the pooler, which is exactly the state a container is in
     *   for its first minutes. `failed` and `warned` therefore wait for `window->closed()`, the same
     *   line {@see readerWindowVerdict()} draws and for the same reason — a starting container is
     *   not a broken one. The answers themselves are reported either way, so a reader can see what
     *   the pooler said without a verdict being invented about it, and `reason` carries the sentence
     *   from the moment one pool did not answer or could not be told apart from a name no file
     *   declares.
     *
     * WHY IT IS NOT IN healthSummary()
     *   It is the one reading here that opens a socket. `healthSummary()` is what `db:replica-status`
     *   and `db:pgcat-flip --status` print, and both are diagnostics an operator runs on a host that
     *   may be the wrong side of the pooler: adding a connect timeout to them would change their
     *   contract rather than add a field to this one. The surfaces that want the answer ask for it
     *   by name, and neither of the two is on the flip's own hot path.
     *
     * @return array{enabled: bool, applicable: bool, answered: bool|null, discriminates: bool|null, verified: bool|null, judged: bool, control: array{database: string, status: string, evidence: string}|null, admin: array{status: string, pools: list<string>, evidence: string}|null, host: string|null, port: int|null, address: string|null, failed: bool, warned: bool, pools: list<array{database: string, status: string, evidence: string}>, reason: string|null}
     */
    public function poolProbeVerdict(): array
    {
        // The shape a caller gets whenever nothing was asked, so a surface never has to test for a
        // key's absence to tell "the probe is off" from "the probe is on".
        $unasked = [
            'enabled' => $this->poolProbe !== null || $this->poolSweep !== null,
            'applicable' => false,
            'answered' => null,
            'discriminates' => null,
            'verified' => null,
            'judged' => false,
            'control' => null,
            'admin' => null,
            'host' => null,
            'port' => null,
            'address' => null,
            'failed' => false,
            'warned' => false,
            'pools' => [],
            'reason' => null,
        ];

        // An inert flipper has no pooler to be answerable for: nothing swaps the file and nothing
        // restarts the program, so nothing asks it anything. `enabled` still reports the switch, so
        // a reader can tell an installation that never armed the probe from one that armed it and
        // has no pgcat at all.
        if (($this->poolProbe === null && $this->poolSweep === null) || !$this->isEnabled()) {
            return $unasked;
        }

        $single = $this->poolProbe;
        $sweep = $this->poolSweep;

        $mode = $this->resolver->currentModeName();
        $declared = $this->configuredPools($mode);

        // No pool declared is not a pooler that failed: there is nothing the variant claims the
        // pooler should hold, so there is nothing to be missing — and nothing worth a connection.
        if ($declared === []) {
            return $unasked;
        }

        $judged = $this->window->closed();
        $probed = ['host' => null, 'port' => null, 'address' => null];

        // The pooler's own inventory, asked first: `SHOW POOLS` is one connection for the whole
        // sweep rather than a front-door exchange per pool, and it is the process naming the pools it
        // serves. Where it can be read it *is* the reading, and the front door is not asked at all
        // — the answerability of a pool then rests on the pooler's list rather than on how it
        // answers an anonymous packet, which is the one thing a pooler may decide before it looks
        // a pool up.
        $admin = null;

        if ($this->poolAdmin !== null) {
            /** @var array{status?: string, pools?: array<array-key, mixed>, evidence?: string} $answer */
            $answer = ($this->poolAdmin)();
            $probed = self::probedAddress($probed, $answer);
            $admin = self::poolAdminAnswer($answer);

            if ($admin['status'] === PoolProbe::ADMIN_STATUS_READ) {
                $pools = self::poolsListedBy($declared, $admin['pools']);
                $missing = array_values(array_filter($pools, static fn (array $pool): bool => $pool['status'] === PoolProbe::STATUS_MISSING));

                return [
                    'enabled' => true,
                    'applicable' => true,
                    'answered' => $missing === [],
                    'discriminates' => null,
                    'verified' => true,
                    'judged' => $judged,
                    'control' => null,
                    'admin' => $admin,
                    'host' => $probed['host'],
                    'port' => $probed['port'],
                    'address' => $probed['address'],
                    'failed' => $judged && $missing !== [],
                    'warned' => false,
                    'pools' => $pools,
                    'reason' => $missing === [] ? null : $this->poolProbeReason($missing, [], [], $judged, $admin),
                ];
            }
        }

        // The console could not be read — no credentials in the pooler's file, a refusal, a
        // challenge this probe cannot meet, silence — so the front door is asked instead. Nothing
        // it says is proof on its own: `discriminates` and `verified` below say what the answers
        // are worth, the reading the console replaced wherever it could be made.
        $controlName = PoolProbe::controlName();

        $batched = [];

        if ($sweep !== null) {
            // One wave: every declared name and the sentinel are asked together, so the whole
            // reading is covered by the one budget the sweep was given. The sentinel is asked even
            // when a refusal will make it unnecessary — an extra connection in the same wave is
            // cheaper than a second wait, and its answer is simply not published when a missing
            // pool already proves the ordering.
            $answers = $sweep([...$declared, $controlName]);

            /** @var list<array<string, mixed>> $batched */
            $batched = is_array($answers) ? array_values($answers) : [];
        }

        $pools = [];

        foreach ($declared as $index => $database) {
            /** @var array<string, mixed> $verdict */
            $verdict = $sweep !== null
                ? (is_array($batched[$index] ?? null) ? $batched[$index] : [])
                : ($single !== null ? $single($database) : []);

            $probed = self::probedAddress($probed, $verdict);
            $status = is_string($verdict['status'] ?? null) && $verdict['status'] !== ''
                ? $verdict['status']
                : PoolProbe::STATUS_SILENT;

            $pools[] = [
                'database' => $database,
                'status' => $status,
                'evidence' => is_string($verdict['evidence'] ?? null) && $verdict['evidence'] !== ''
                    ? $verdict['evidence']
                    : sprintf('the pooler gave no answer for pool "%s"', $database),
            ];
        }

        $missing = array_values(array_filter($pools, static fn (array $pool): bool => $pool['status'] === PoolProbe::STATUS_MISSING));
        $silent = array_values(array_filter($pools, static fn (array $pool): bool => $pool['status'] === PoolProbe::STATUS_SILENT));

        // A refusal of a declared pool already proves this pooler resolves pools before it
        // authenticates, so the control is published only when nothing was refused: it turns "the
        // pooler answered" into "the pool exists" or into "the pooler answers everything", which
        // no pool's answer alone can do. A sweep has already asked it in the same wave and its
        // answer is used only here; a single-name probe is asked now, because it was not.
        $discriminates = true;
        $control = null;

        if ($missing === []) {
            /** @var array<string, mixed> $controlVerdict */
            $controlVerdict = $sweep !== null
                ? (is_array($batched[count($declared)] ?? null) ? $batched[count($declared)] : [])
                : ($single !== null ? $single($controlName) : []);

            // The same exchange as the pools, so this only ever fills in for a probe that named an
            // address on the control and not on a pool — never a second endpoint.
            $probed = self::probedAddress($probed, $controlVerdict);

            $controlStatus = is_string($controlVerdict['status'] ?? null) && $controlVerdict['status'] !== ''
                ? $controlVerdict['status']
                : PoolProbe::STATUS_SILENT;

            $control = [
                'database' => $controlName,
                'status' => $controlStatus,
                'evidence' => is_string($controlVerdict['evidence'] ?? null) && $controlVerdict['evidence'] !== ''
                    ? $controlVerdict['evidence']
                    : sprintf('the pooler gave no answer for the control name "%s"', $controlName),
            ];

            $discriminates = PoolProbe::discriminates($controlStatus);
        }

        // A sentinel that was answered is the whole point of asking it: this pooler authenticates
        // before it resolves a pool, so the answer it gave for each declared pool is the answer it
        // gives every name. Publishing those pools as `answered` would be the green reading this
        // reading exists to prevent, so they become `indeterminate` — the pooler spoke, and what it
        // said cannot be taken as evidence. The sentinel's own answer stays in `control`.
        $indeterminate = [];

        if ($control !== null && $discriminates === false) {
            foreach ($pools as $index => $pool) {
                if ($pool['status'] !== PoolProbe::STATUS_ANSWERED) {
                    continue;
                }

                $pools[$index]['status'] = PoolProbe::STATUS_INDETERMINATE;
                $pools[$index]['evidence'] = sprintf(
                    'the pooler also answered the sentinel name "%s", which no configuration declares, so its answer for pool "%s" does not show that the pool exists',
                    // The name the sentinel was asked under, which is `$control`'s own `database` by
                    // construction — read from the variable rather than off the array so the
                    // sentence does not depend on the reader narrowing a nullable to get it.
                    $controlName,
                    $pool['database'],
                );

                $indeterminate[] = $pools[$index];
            }
        }

        // The console was asked first and could not answer, so the front door is as far as this
        // reading goes: `discriminates` is its proof, and false or null is the honest answer rather
        // than a pass.
        $verified = $discriminates;

        return [
            'enabled' => true,
            'applicable' => true,
            'answered' => $missing === [] && $silent === [] && $indeterminate === [],
            'discriminates' => $discriminates,
            'verified' => $verified,
            'judged' => $judged,
            'control' => $control,
            'admin' => $admin,
            'host' => $probed['host'],
            'port' => $probed['port'],
            'address' => $probed['address'],
            'failed' => $judged && $missing !== [],
            'warned' => $judged && $missing === [] && ($silent !== [] || $indeterminate !== []),
            'pools' => $pools,
            'reason' => $missing === [] && $silent === [] && $indeterminate === []
                ? null
                : $this->poolProbeReason($missing, $silent, $indeterminate, $judged, $admin),
        ];
    }

    /**
     * The endpoint a probe answer names, laid over the one already known.
     *
     * Taken from the answer rather than from the settings, because the fact a surface has to report
     * is where the packet was *sent*: `PoolProbe::ask()` returns `host`, `port` and the `address` it
     * dialled, and an installation that injects its own probe reports its own endpoint. A probe that
     * names no address leaves these null rather than having one invented from the configuration — a
     * surface that printed a configured address the probe never used would be answering the very
     * question this exists for with the wrong answer.
     *
     * The first answer wins: every exchange in one sweep is the same endpoint, so a later answer only
     * fills in what an earlier one left out, and an address and a host/port pair that disagree cannot
     * both be reported. `address` is composed from the pair when only the pair is given, so a caller
     * that carries one or the other still gets the one string a row can print.
     *
     * @param array{host: string|null, port: int|null, address: string|null} $known
     * @param array<string, mixed>                                         $answer
     * @return array{host: string|null, port: int|null, address: string|null}
     */
    private static function probedAddress(array $known, array $answer): array
    {
        if ($known['address'] !== null) {
            return $known;
        }

        $host = is_string($answer['host'] ?? null) && $answer['host'] !== '' ? $answer['host'] : null;
        $port = is_int($answer['port'] ?? null) ? $answer['port'] : null;
        $address = is_string($answer['address'] ?? null) && $answer['address'] !== '' ? $answer['address'] : null;

        if ($address === null && $host !== null && $port !== null) {
            $address = sprintf('tcp://%s:%d', $host, $port);
        }

        return ['host' => $host, 'port' => $port, 'address' => $address];
    }

    /**
     * A console reading reduced to the shape the verdict publishes: one of the two statuses it can
     * carry, the pool names it listed, and a sentence. Anything a probe of another shape returns is
     * read as silence rather than guessed at, the same way a pool answer without a status is.
     *
     * @param array{status?: string, pools?: array<array-key, mixed>, evidence?: string} $answer
     * @return array{status: string, pools: list<string>, evidence: string}
     */
    private static function poolAdminAnswer(array $answer): array
    {
        $status = is_string($answer['status'] ?? null)
            && in_array($answer['status'], [PoolProbe::ADMIN_STATUS_READ, PoolProbe::ADMIN_STATUS_REFUSED], true)
                ? $answer['status']
                : PoolProbe::STATUS_SILENT;

        $listed = [];

        foreach (is_array($answer['pools'] ?? null) ? $answer['pools'] : [] as $name) {
            if (is_string($name) && $name !== '') {
                $listed[] = $name;
            }
        }

        return [
            'status' => $status,
            'pools' => $listed,
            'evidence' => is_string($answer['evidence'] ?? null) && $answer['evidence'] !== ''
                ? $answer['evidence']
                : 'the pooler\'s admin console gave no answer',
        ];
    }

    /**
     * The reading the pooler's own list produces: one row per pool the variant declares, `answered`
     * where the list carries it and `missing` where it does not.
     *
     * This is the reading the console exists for, so the sentence names the console rather than the
     * front door: the fault is that the process does not hold a pool its configuration declares,
     * and a reader should be told which reading found that — the pooler's statement about itself
     * rather than an inference from a startup packet.
     *
     * @param list<string> $declared the pools the applied variant declares, in file order
     * @param list<string> $listed   the pools the console's `SHOW POOLS` named
     * @return list<array{database: string, status: string, evidence: string}>
     */
    private static function poolsListedBy(array $declared, array $listed): array
    {
        $pools = [];

        foreach ($declared as $database) {
            $held = in_array($database, $listed, true);

            $pools[] = [
                'database' => $database,
                'status' => $held ? PoolProbe::STATUS_ANSWERED : PoolProbe::STATUS_MISSING,
                'evidence' => $held
                    ? sprintf('the pooler\'s own admin console lists pool "%s"', $database)
                    : sprintf('the pooler\'s own admin console lists no pool "%s"', $database),
            ];
        }

        return $pools;
    }

    /**
     * The sentence for a pooler that did not answer for something, in the pooler's own words.
     *
     * One sentence for the whole reading rather than one per pool, because the fault is one: what
     * the operator has to decide is what to do about this pooler, and the first refusal's evidence is
     * the pooler's own admission rather than a paraphrase of it. The names are quoted and listed in
     * file order, so a reader can find them in the variant a flip would apply.
     *
     * The sentence ends on the same distinction the verdict is judged by, because the two readings of
     * one answer call for different work. Past the boot window a missing pool is the whole reason
     * this reading exists — the repair path is `applyCurrentState()`, which stops at the end of the
     * window, and the window tasks apply through `forceMode()`, which asks once, on the run that
     * applies a mode, so a pooler that loses a pool afterwards is never re-applied again — and inside
     * it the same answer is a container that is
     * still coming up. A pooler that answered nothing is neither: it is reported and never repaired,
     * which is the line that keeps a probe pointed at the wrong address from failing a pooler that is
     * fine. A pooler that answered the sentinel as well is a third case: it spoke, and what it said
     * is not evidence, so the reading is indeterminate rather than answered — also reported, and also
     * never repaired, because it says nothing about which pools the process holds.
     *
     * The console's own refusal is appended to the indeterminate sentence when there was one, so a
     * row says both why the inventory could not be read and what the fallback made of that.
     *
     * @param list<array{database: string, status: string, evidence: string}> $missing
     * @param list<array{database: string, status: string, evidence: string}> $silent
     * @param list<array{database: string, status: string, evidence: string}> $indeterminate
     * @param array{status: string, pools: list<string>, evidence: string}|null $admin
     */
    private function poolProbeReason(array $missing, array $silent, array $indeterminate, bool $judged, ?array $admin = null): string
    {
        $names = static function (array $pools): string {
            /** @var list<array{database: string, status: string, evidence: string}> $pools */
            return implode(', ', array_map(
                static fn (array $pool): string => '"'.$pool['database'].'"',
                $pools,
            ));
        };

        if ($missing !== []) {
            return sprintf(
                'the pooler is running but holds no pool %s — %s. %s',
                $names($missing),
                $missing[0]['evidence'],
                $judged
                    ? sprintf(
                        'Nothing re-applies that configuration past the boot window, so every read to %s fails until the container is replaced',
                        count($missing) === 1 ? 'it' : 'them',
                    )
                    : 'The boot window is still open, where the per-minute flip re-applies the configuration, so this is not judged yet',
            );
        }

        if ($indeterminate !== []) {
            $console = $admin !== null
                ? sprintf(' Its own admin console could not list them: %s.', $admin['evidence'])
                : '';

            return sprintf(
                'the pooler answered for %s — %s. %s.%s',
                $names($indeterminate),
                $indeterminate[0]['evidence'],
                $judged
                    ? 'Its answers are not proof that those pools exist, so this reading is indeterminate rather than a pass'
                    : 'The boot window is still open, where the per-minute flip re-applies the configuration, so this is not judged yet',
                $console,
            );
        }

        return sprintf(
            'the pooler did not answer for pool %s — %s. %s',
            $names($silent),
            $silent[0]['evidence'],
            $judged
                ? 'That is reported rather than repaired: a probe pointed at the wrong address must not be able to fail a pooler that is fine'
                : 'The boot window is still open, and a pooler that has not finished starting is what that looks like',
        );
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
     * How many pool repairs this container has recorded, when the last one happened, which pool
     * was refused and which mode it was applied with — the reading `db:pgcat-flip --status` prints
     * and `db:doctor`'s `pool repairs` row reports.
     *
     * The pool and the mode are the two facts a count cannot carry and a log can: a run that
     * repaired says `repaired` and names the pool in its sentence, and an hour later the sentence
     * is one line among thousands while the state file still holds the answer. Naming the mode
     * beside it is what makes the pool name mean something — a pool is declared by a variant, so
     * "the pooler refused `reader_db_2`" says nothing until it says which variant asked for it.
     * Both are null for a state file written before they were recorded, and for a hand-edited
     * value that is not a non-empty string, which is the line `last_at` already draws.
     *
     * WHY ONE READING AND NOT A COUNTER EACH
     *   `windowStatus()` carries the same four facts, and the preflight could have read them out of
     *   that block. It does not, because that block is the *boot window's* — an age, a deadline, a
     *   convergence — and a caller asking only what a repair left behind would be given the
     *   window's questions in the same array. What the two callers share instead is this method's
     *   derivation: one cast of `pool_repairs` and three readings that are null unless they are
     *   non-empty strings, over the state file the flip wrote. Two readers of that file
     *   — a `json_decode()` here and a key cast there — is how two surfaces start disagreeing
     *   about a file whose writes are serialised by the flip's own lock.
     *
     *   `last_at` is null rather than whatever the file holds when the value is absent, empty or
     *   not a string, which is the line `windowStatus()` already draws for `converged_at`. A
     *   report that printed a scalar beside an ISO time would be one field in two shapes, and a
     *   state file edited by hand is the case an operator arrives in first.
     *
     * @return array{count: int, last_at: string|null, pool: string|null, mode: string|null}
     */
    public function poolRepairStatus(): array
    {
        return $this->repairFacts($this->readState());
    }

    /**
     * The repair facts in a state file already read, so a caller holding the state does not
     * read the file twice and the two surfaces agree by construction.
     *
     * @param array<string, mixed> $state
     *
     * @return array{count: int, last_at: string|null, pool: string|null, mode: string|null}
     */
    private function repairFacts(array $state): array
    {
        $last = $state['last_pool_repair_at'] ?? null;
        $pool = $state['last_repair_pool'] ?? null;
        $mode = $state['last_repair_mode'] ?? null;

        // The shapes are the ones `last_at` already draws: a stamp that is not a string is the
        // same state as an absent one, because the file is one an operator may edit by hand and
        // a report that printed an array beside a name would be one field in two shapes.
        return [
            'count' => ConfigValue::int($state['pool_repairs'] ?? null, 0),
            'last_at' => is_string($last) && $last !== '' ? $last : null,
            'pool' => is_string($pool) && $pool !== '' ? $pool : null,
            'mode' => is_string($mode) && $mode !== '' ? $mode : null,
        ];
    }

    /**
     * Whether this container has needed the pooler repaired faster than the configured threshold —
     * the reading `/health/db` publishes as `pool_repairs`, and the one a monitor pages on.
     *
     * WHY A RATE RATHER THAN THE COUNT
     *   `poolRepairStatus()` gives the count and the time of the last repair, and neither
     *   separates the two shapes an installation has to be told apart by: one repair inside
     *   a boot window, which is the self-heal working exactly as intended, and a pooler that is
     *   repaired on run after run, which is a pooler that needs looking at. The count is cumulative
     *   for the container's life and `last_at` is one moment, so a rate is the only shape of the
     *   same history that answers the second question: too many repairs inside a window.
     *
     * WHY THE TIMES ARE IN THE STATE FILE
     *   A count and one timestamp cannot be turned into a rate by a reader, however the reader is
     *   written: the file is the only place the moments are known, so `recordRun()` keeps them and
     *   this reads them. The window is also the bound on what is kept — the write drops the times
     *   older than the window it is measuring, which is why the array does not grow with the life
     *   of the container — and the configured window is therefore both the measurement and the
     *   retention. Widening it later starts from the times the narrower one kept; that is stated
     *   rather than hidden, because it is a limit of keeping the file small on the poll path.
     *
     * WHY A FILE WITH A COUNT AND NO HISTORY IS NOT "NO REPAIRS IN THE WINDOW"
     *   Every state file written before this reading existed carries `pool_repairs` and no times.
     *   Reporting zero recent repairs there would be the most comfortable answer available and the
     *   wrong one — the file says repairs happened and says nothing about when — so `judged` is
     *   false and `reason` names both the count and the window it could not measure. A file that has
     *   never seen a repair is different: zero is then the whole history, so it is judged and quiet.
     *
     * WHY IT WARNS AND DOES NOT FAIL
     *   A repair is the self-heal working, so failing an installation for it would fail it for the
     *   flip having done its job; the faults that are one *now* are `/health/db`'s other blocks —
     *   `pool_probe` asks the pooler, this reads what already happened. The threshold is where the
     *   reading stops being a reassurance and becomes a warning for a person, and the endpoint keeps
     *   its status for the blocks that answer "is this installation serving now".
     *
     * A window or a threshold the configuration cannot express — zero, a negative, a word — falls
     * back to the documented default rather than meaning "never warn" or "warn on everything": a
     * refused setting must not be the thing that silences the reading.
     *
     * @return array{judged: bool, window_seconds: int, max_repairs: int, count: int, in_window: int|null, last_at: string|null, warned: bool, reason: string|null}
     */
    public function poolRepairRate(): array
    {
        $state = $this->readState();
        $facts = $this->repairFacts($state);
        $settings = $this->repairRateSettings();
        $times = $state[self::REPAIR_HISTORY_KEY] ?? null;

        // A repair recorded before the history existed is the one state this cannot read: the
        // count proves something happened, and the file has nothing to say about when. Zero
        // repairs is a history in its own right, and a file that has never seen one is quiet.
        if (!is_array($times) && $facts['count'] > 0) {
            return [
                'judged' => false,
                'window_seconds' => $settings['window_seconds'],
                'max_repairs' => $settings['max_repairs'],
                'count' => $facts['count'],
                'in_window' => null,
                'last_at' => $facts['last_at'],
                'warned' => false,
                'reason' => sprintf(
                    'this state file records %d pool repair(s) and no times for them: it was written '
                    .'before repairs were dated, so how many fell inside the last %d seconds cannot be read',
                    $facts['count'],
                    $settings['window_seconds'],
                ),
            ];
        }

        $since = time() - $settings['window_seconds'];
        $inWindow = 0;

        foreach (is_array($times) ? $times : [] as $time) {
            $parsed = $this->parseTimestamp($time);

            // An entry that is not a time is dropped rather than counted: the state file is one an
            // operator may edit by hand, and a value nobody can place is not a repair inside the
            // window — the same line `last_pool_repair_at` draws for a stamp that is not a string.
            if ($parsed !== null && $parsed >= $since) {
                $inWindow++;
            }
        }

        $warned = $inWindow > $settings['max_repairs'];

        return [
            'judged' => true,
            'window_seconds' => $settings['window_seconds'],
            'max_repairs' => $settings['max_repairs'],
            'count' => $facts['count'],
            'in_window' => $inWindow,
            'last_at' => $facts['last_at'],
            'warned' => $warned,
            'reason' => $warned
                ? sprintf(
                    'the pooler has been repaired %d time(s) in the last %d seconds, and the threshold '
                    .'is %d: a repair is the self-heal working, so this many means the pooler keeps '
                    .'refusing the pools the applied file declares — look at why it is being restarted '
                    .'or losing its file rather than at the flip',
                    $inWindow,
                    $settings['window_seconds'],
                    $settings['max_repairs'],
                )
                : null,
        ];
    }

    /**
     * The window and the threshold the rate is measured against, with a default in place of a
     * value that cannot mean anything.
     *
     * A zero or negative window would ask a question about no time at all, and a zero or negative
     * threshold would warn about a container that never repaired anything — the two shapes a
     * typo takes. Both land on the documented default instead, which is the same rule the
     * switches follow: a refused value lands where an absent one would, never somewhere the
     * setting's own documentation does not describe.
     *
     * @return array{window_seconds: int, max_repairs: int}
     */
    private function repairRateSettings(): array
    {
        $settings = ConfigValue::assoc($this->config['pool_repairs'] ?? null);
        $window = ConfigValue::int($settings['window_seconds'] ?? null, self::DEFAULT_REPAIR_WINDOW_SECONDS);
        $max = ConfigValue::int($settings['max_repairs'] ?? null, self::DEFAULT_MAX_REPAIRS_PER_WINDOW);

        return [
            'window_seconds' => $window > 0 ? $window : self::DEFAULT_REPAIR_WINDOW_SECONDS,
            'max_repairs' => $max > 0 ? $max : self::DEFAULT_MAX_REPAIRS_PER_WINDOW,
        ];
    }

    /**
     * The repair times to keep after one more happened: the ones still inside the window, plus
     * the moment being recorded, capped at the history limit.
     *
     * Pruning here rather than in the reader is what keeps the array bounded: a state file is
     * rewritten on every run, and a list that only ever grew would be a list the poll path pays
     * for on every write. The window is the setting's, so the history it keeps is exactly the
     * history its reading asks about — and an entry that is not a timestamp is dropped for the
     * reason the reader drops it.
     *
     * @return list<string>
     */
    private function recordRepairTime(mixed $existing): array
    {
        $cutoff = time() - $this->repairRateSettings()['window_seconds'];
        $kept = [];

        foreach (is_array($existing) ? $existing : [] as $raw) {
            // Narrowed before it is compared and kept: an entry that is not a string cannot be a
            // timestamp, and what is kept is the string the moment was written as rather than a
            // rendering of the number it parsed to.
            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $parsed = $this->parseTimestamp($raw);

            if ($parsed === null || $parsed < $cutoff) {
                continue;
            }

            $kept[] = $raw;
        }

        $kept[] = $this->nowIso();

        return array_slice($kept, -self::REPAIR_HISTORY_LIMIT);
    }

    /**
     * The container's boot window as one block: the window itself, plus what the recorded runs
     * inside it say about convergence.
     *
     * `failed` is the field `/health/db` acts on, and it is deliberately only ever true *after*
     * the window has closed: while a container is still inside its window, a flip that has not
     * converged yet is a container still starting, not a broken one. The three ways to be
     * `failed` are told apart in `reason`, because they call for different work — nothing at all
     * applied a mode inside the window, a flip that ran and kept failing, and a flip that
     * succeeded outside the window (which means the container was too slow, not that anything is
     * broken).
     *
     * `converged` is sticky for the container's life: it is set by the first run that reached a
     * usable mode, and never cleared, so a later failure cannot retroactively un-work a
     * container that did come up. `runs` counts every recorded run, which is what makes "the
     * flip is scheduled" observable at all.
     *
     * `applied`, `applied_at`, `applied_mode` and `applied_via` are the other half of that, and
     * they are what a second mechanism made necessary: `converged` is written only by the
     * per-minute entry, which stops acting when the window closes, while the reader-window tasks
     * go on moving the mode through the forced path. Both write the same mode record — see
     * `writeLastMode()` — so this half answers *what the pooler is in and who put it there* for
     * the container's whole life, and `failed` is the pair: a container that reached no usable
     * mode by either mechanism inside its window is the one that failed, and one whose pooler a
     * boundary task is keeping in step is not.
     *
     * `pool_repairs` is the sibling of `runs` that answers a different question: a running
     * pooler that was not answering for a pool was re-applied (or restarted on the file that was
     * written), and the count says whether that was a one-off or a pooler that keeps needing
     * repair — which `last_kind` alone cannot, because two repairs a minute apart and two repairs
     * a week apart both leave it reading `repaired`. `last_pool_repair_at` is when the last one
     * happened, read the same way `last_run_at` is; `last_repair_pool` and `last_repair_mode` are
     * what it happened to — the pool the pooler refused, and the variant that declares it — so the
     * failing pool is named by the table rather than by the log line of the run that saw it. All
     * four are `poolRepairStatus()`'s facts, from the state read below rather than from a second
     * read of the file, so this block and the preflight's `pool repairs` row cannot count one
     * container's repairs differently.
     *
     * @return array<string, mixed>
     */
    public function windowStatus(): array
    {
        $window = $this->window->toArray();
        $state = $this->readState();
        $convergedAt = $this->stateTime($state, 'converged_at');
        $repairs = $this->repairFacts($state);

        $converged = $convergedAt !== null && $this->window->contains($convergedAt);
        $runs = ConfigValue::int($state['runs'] ?? null, 0);
        $closed = (bool) $window['closed'];

        // What the pooler is *in*, whichever mechanism put it there. `converged` above is the
        // per-minute entry's own evidence and stops at the window's end; this reading goes on
        // being written by the window tasks for as long as the container lives, because both
        // paths write `last_mode`/`last_flipped_at`/`flipped_via` — so a container whose pooler
        // is in step reads as settled rather than as a flip that never converged. The mechanism
        // is named for the reason the pair exists: the two do not share a *run* record, so a
        // reading that could not say which one moved the mode would describe the entry at the
        // moment the tasks took over.
        $appliedAt = $this->stateTime($state, 'last_flipped_at');
        $applied = $appliedAt !== null && $this->window->contains($appliedAt);
        $settled = $converged || $applied;
        $appliedMode = $state['last_mode'] ?? null;
        $appliedVia = $state['flipped_via'] ?? null;

        $reason = null;

        if ($closed && !$settled) {
            $reason = match (true) {
                $runs === 0 && $appliedAt === null => 'nothing applied a mode inside this container\'s window: db:pgcat-flip is not being scheduled and no reader-window task acted, or neither can reach the flipper',
                $convergedAt !== null => 'every flip run inside the window failed',
                default => 'the flip has not reached a usable mode inside the window',
            };
        }

        return [
            ...$window,
            'runs' => $runs,
            'last_run_at' => $state['last_run_at'] ?? null,
            'last_kind' => $state['last_kind'] ?? null,
            'pool_repairs' => $repairs['count'],
            'last_pool_repair_at' => $repairs['last_at'],
            'last_repair_pool' => $repairs['pool'],
            'last_repair_mode' => $repairs['mode'],
            'converged' => $converged,
            'converged_at' => $state['converged_at'] ?? null,
            'converged_mode' => $state['converged_mode'] ?? null,
            'applied' => $applied,
            'applied_at' => is_string($state['last_flipped_at'] ?? null) ? $state['last_flipped_at'] : null,
            'applied_mode' => is_string($appliedMode) ? $appliedMode : null,
            'applied_via' => is_string($appliedVia) ? $appliedVia : null,
            'failed' => $closed && !$settled,
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
     * The probe a config asks for, or null when it does not switch one on.
     *
     * The switch is read the way every other switch in this block is — `SwitchValue`, so
     * `'yes'`/`'no'` work and a value that is neither is refused rather than cast — and it
     * defaults to off: a probe that opened a socket on every poll is a thing an installation
     * opts into, not one it inherits by upgrading. An absent or refused value therefore leaves
     * the pooler's answer unexamined, which is the state every installation was in before this
     * existed.
     *
     * The host is the pooler's own, which is why it is configured rather than derived: a variant
     * file does name an address, but a variant is one of the files a flip may put in front of the
     * process, so an address read out of a variant would move with the flip instead of staying with
     * the pooler. The *port* is read from the file the pooler is actually running on —
     * `config_path`, the target a swap renames over — because that file is the process's own
     * statement rather than a candidate's. It is the only source: there is no separate port setting
     * and no convention to fall back to, so a file that names no dialable port arms no probe rather
     * than aiming one where the pooler is not listening. `PoolProbe::port()` is the one place that
     * reading is made.
     *
     * @param array<string, mixed> $config the `swrr.pgcat` block
     */
    private static function defaultPoolProbe(array $config): ?\Closure
    {
        $endpoint = self::poolProbeEndpoint($config);

        if ($endpoint === null) {
            return null;
        }

        $probe = ConfigValue::assoc($config['pool_probe'] ?? null);
        $user = ConfigValue::string($probe['user'] ?? null, 'postgres');
        $host = $endpoint['host'];
        $port = $endpoint['port'];
        $timeout = $endpoint['timeout'];

        return static fn (string $database): array => PoolProbe::ask($host, $port, $database, $user, $timeout);
    }

    /**
     * The same probe over every declared name at once, under one timeout budget.
     *
     * Built from the same endpoint reading as the single probe — `poolProbeEndpoint()` is the one
     * place the host, the port and the timeout are resolved — so the two closures cannot ask two
     * different poolers. `PoolProbe::askMany()` opens one non-blocking connection per name and one
     * deadline for the sweep, which is what keeps a large pool set from turning the fallback into
     * a wait that grows with the configuration.
     *
     * @param array<string, mixed> $config the `swrr.pgcat` block
     */
    private static function defaultPoolSweep(array $config): ?\Closure
    {
        $endpoint = self::poolProbeEndpoint($config);

        if ($endpoint === null) {
            return null;
        }

        $probe = ConfigValue::assoc($config['pool_probe'] ?? null);
        $user = ConfigValue::string($probe['user'] ?? null, 'postgres');
        $host = $endpoint['host'];
        $port = $endpoint['port'];
        $timeout = $endpoint['timeout'];

        return static function (array $databases) use ($host, $port, $user, $timeout): array {
            /** @var list<string> $names */
            $names = array_values(array_filter(
                $databases,
                static fn (mixed $name): bool => is_string($name) && $name !== '',
            ));

            return PoolProbe::askMany($host, $port, $names, $user, $timeout);
        };
    }

    /**
     * The default console reading: the pooler's own admin console on the same host and port the
     * client-facing probe dials, with the credentials out of the file that pooler is running on.
     *
     * It is asked in one place — `poolProbeVerdict()`, before the front door — because the list it
     * returns is the reading this probe wants: the process naming the pools it serves, rather than
     * an anonymous startup packet per pool whose meaning depends on when the pooler resolves one.
     * Nothing else calls it, and it is built only when the probe itself was, so an installation
     * that has not armed the probe opens nothing.
     *
     * @param array<string, mixed> $config the `swrr.pgcat` block
     */
    private static function defaultPoolAdmin(array $config): ?\Closure
    {
        $endpoint = self::poolProbeEndpoint($config);

        if ($endpoint === null) {
            return null;
        }

        $host = $endpoint['host'];
        $port = $endpoint['port'];
        $timeout = $endpoint['timeout'];

        // The file is read when the console is asked rather than when the flipper is built: a
        // flip in the same run may have just written the file whose credentials are wanted, and a
        // reading taken at construction would describe the file before it.
        return static fn (): array => PoolProbe::adminPools(
            self::poolerConfigToml($config),
            $host,
            $port,
            $timeout,
        );
    }

    /**
     * The address and timeout both readings of the pooler use, or null when the probe is switched
     * off or cannot be aimed — the one place `swrr.pgcat.pool_probe.host` and `.timeout_ms` are
     * resolved, and where the port is taken from the file the pooler is running on, so the front
     * door and the console cannot end up asking two different poolers.
     *
     * Off is a decision rather than an error: an installation that has not armed the probe opens no
     * socket, and the console fallback does not create one behind its back. A file that names no
     * port is the same outcome for a different reason — there is no address the pooler has stated,
     * and a guess would be a probe pointed at something that may not be the pooler at all.
     *
     * @param array<string, mixed> $config the `swrr.pgcat` block
     * @return array{host: string, port: int, timeout: int}|null
     */
    private static function poolProbeEndpoint(array $config): ?array
    {
        $probe = ConfigValue::assoc($config['pool_probe'] ?? null);

        if (! SwitchValue::read($probe['enabled'] ?? null, false)['on']) {
            return null;
        }

        $port = PoolProbe::port(self::poolerConfigToml($config));

        if ($port === null) {
            return null;
        }

        return [
            'host' => ConfigValue::string($probe['host'] ?? null, '127.0.0.1'),
            'port' => $port,
            'timeout' => ConfigValue::int($probe['timeout_ms'] ?? null, PoolProbe::DEFAULT_TIMEOUT_MS),
        ];
    }

    /**
     * The bytes of the file the pooler is running on — `config_path`, the target a flip renames
     * over — or an empty string when it cannot be read.
     *
     * Read here rather than inside `PoolProbe::port()` so that class stays a function of bytes, and
     * empty rather than thrown because a file that cannot be read is a file that names no port: no
     * probe is armed, which is reported as such rather than as a pooler that said nothing.
     *
     * @param array<string, mixed> $config the `swrr.pgcat` block
     */
    private static function poolerConfigToml(array $config): string
    {
        $path = ConfigValue::string($config['config_path'] ?? null);

        if ($path === '' || ! is_readable($path)) {
            return '';
        }

        $toml = @file_get_contents($path);

        return is_string($toml) ? $toml : '';
    }

    /**
     * The pool the running pooler refuses for the mode that is already applied, in its own words —
     * or null when it answers for every pool the applied variant declares.
     *
     * One pool is enough to call it a fault, and this answers *which* rather than a sentence
     * about it: the run records the name beside the mode it was refused under, so `--status` can
     * say which pool the last repair was for without an operator reading the log back. The
     * sentence the run reports is built from the same array by `poolFaultReason()`, so the pool
     * the report names and the pool the state records cannot be two answers.
     *
     * The comparison is against the *variant the mode asks for* rather than against a list
     * configured twice — the pool names are the section headers of the file a flip would write,
     * so the probe and the flip read one source.
     *
     * Only `missing` is a fault. A pooler that answered nothing (`silent`) is the supervisor's
     * question or a wrong address, and treating either as a reason to restart would let a probe
     * pointed at the wrong port restart a healthy pooler every minute — the line `PoolProbe`
     * draws, kept here.
     *
     * One read and no wait: the reading is `refusalFor()` with the policy that fits a pooler which has
     * been up and answering for a while — the mode never moved, so nothing was just signalled. The
     * reading a mode that has *just* been applied gets is `refusalAfterApply()`, which is the same
     * read with a different policy rather than a second loop, so the two cannot come to different
     * answers about how many times the pooler is asked and how long the run waits in between.
     *
     * @return array{database: string, evidence: string}|null
     */
    private function poolFault(string $mode): ?array
    {
        return $this->refusalFor($mode, 1, 0);
    }

    /**
     * The sentence `poolFault()`'s refusal is reported with — the run's own reason, unchanged by
     * the state now naming the same pool.
     *
     * @param array{database: string, evidence: string} $refusal
     */
    private function poolFaultReason(array $refusal, string $mode): string
    {
        return sprintf(
            'the pooler was running but had no pool "%s" — %s, so the %s config was applied again',
            $refusal['database'],
            $refusal['evidence'],
            $this->modeNoun($mode),
        );
    }

    /**
     * Whether the pooler is answering for the mode a flip has just applied, asked the way a
     * pooler that has just been signalled has to be asked: more than once, with a wait between
     * the attempts.
     *
     * Only the policy separates this read from `poolFault()`: both are `refusalFor()` over the same
     * pools, and this one asks more than once with a wait between the attempts. There the pooler has
     * been up and answering for a while — the mode never moved, so nothing was just restarted — and
     * one read is the entire question. Here the file was replaced and supervisord was signalled a
     * moment ago, and both ways of applying a config are asynchronous: a reload returns before pgcat
     * has re-read anything, and a restart returns while the program is still inside supervisor's
     * `startsecs` window, where it may not be listening yet. One read would call that second state a
     * refusal and restart a pooler that was doing exactly what it was told.
     *
     * The attempts and the delay are the pair a repair already uses to bring pgcat up
     * (`start_attempts`, `start_retry_delay_ms`) rather than a third and a fourth setting: it is
     * the same question — is the program on this file yet — and an installation that raised one
     * bound has raised it for both. And a pooler that answers nothing is never a refusal, so no
     * number of attempts can turn a probe pointed at the wrong address into one.
     *
     * @return array{database: string, evidence: string}|null the refusal that stood after the
     *         last attempt, or null when the pooler answered for every pool the variant declares
     */
    private function refusalAfterApply(string $mode): ?array
    {
        return $this->refusalFor($mode, $this->startAttempts(), $this->startRetryDelayMs());
    }

    /**
     * The pool the running pooler refuses for `$mode`'s variant, read up to `$attempts` times with
     * `$delayMs` between them — the one read both of a flip's pool questions are built from.
     *
     * The policy is an argument rather than a second loop because the two questions differ in
     * nothing but the policy: `poolFault()` asks a pooler that has been up for a while, and
     * `refusalAfterApply()` asks one that was signalled a moment ago and needs the wait. Two loops
     * over `refusedPool()` is how the count and the delay drift apart — a bound raised in the loop a
     * reader is looking at, and not in the one that was copied from it. No behavioural test can see
     * that copy, because each loop is correct on its own; what sees it is the source scan in
     * `PgcatConfigFlipperTest`, which counts the config reads rather than the behaviour.
     *
     * A variant that declares no pool is null rather than a read: there is nothing the file claims
     * the pooler should be serving, so nothing can be refused, and the poll path pays nothing for a
     * variant that names no pools.
     *
     * @return array{database: string, evidence: string}|null the refusal that stood after the last
     *         attempt, or null when the pooler answered for every pool the variant declares
     */
    private function refusalFor(string $mode, int $attempts, int $delayMs): ?array
    {
        $pools = $this->configuredPools($mode);

        if ($pools === []) {
            return null;
        }

        $refusal = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $refusal = $this->refusedPool($pools);

            if ($refusal === null) {
                return null;
            }

            // No sleep after the last attempt: the caller is about to report the refusal, and a
            // run that has given up has nothing left to wait for.
            if ($attempt < $attempts && $delayMs > 0) {
                ($this->sleeper)($delayMs * 1000);
            }
        }

        return $refusal;
    }

    /**
     * How many times a repair asks one bounded question before it gives up: `start_attempts`, floored
     * at one.
     *
     * Read in one method because three callers need the same answer — `startAndWait()` for the starts
     * that bring pgcat up, `refusalAfterApply()` for the pool read-back that follows an applied mode,
     * and `status()` for the report an operator reads — and a floor applied at one of them is how the
     * report starts describing a retry the run does not perform.
     */
    private function startAttempts(): int
    {
        return max(1, ConfigValue::int($this->config['start_attempts'] ?? null, self::START_ATTEMPTS_DEFAULT));
    }

    /**
     * How long a repair waits between those attempts, in milliseconds: `start_retry_delay_ms`,
     * floored at zero — which is a legitimate value rather than an absent one, and the one a test or
     * an installation that would rather ask twice back to back sets.
     */
    private function startRetryDelayMs(): int
    {
        return max(0, ConfigValue::int($this->config['start_retry_delay_ms'] ?? null, self::START_RETRY_DELAY_MS_DEFAULT));
    }

    /**
     * The first pool in `$pools` the running pooler refuses, in its own words, or null when it
     * answered for every one of them.
     *
     * One pool at a time and `missing` only, which is the fault a flip can repair: a pooler that
     * answered nothing (`silent`) is the supervisor's question or an address this probe was never
     * pointed at, and treating either as a refusal is how a probe on the wrong port restarts a
     * healthy pooler every minute — the line `PoolProbe` draws, kept here.
     *
     * @param list<string> $pools
     * @return array{database: string, evidence: string}|null
     */
    private function refusedPool(array $pools): ?array
    {
        if ($this->poolProbe === null) {
            return null;
        }

        foreach ($pools as $database) {
            /** @var array{status?: string, evidence?: string} $verdict */
            $verdict = ($this->poolProbe)($database);

            if (($verdict['status'] ?? null) !== PoolProbe::STATUS_MISSING) {
                continue;
            }

            return [
                'database' => $database,
                'evidence' => is_string($verdict['evidence'] ?? null) ? $verdict['evidence'] : 'no pool configured for that database',
            ];
        }

        return null;
    }

    /**
     * Make a fresh process read the file a flip has just written, and say what was done about it.
     *
     * WHY A RESTART RATHER THAN THE SAME COMMAND AGAIN
     *   A flip's own command is a reload when `use_reload` is on, because a pooler that is up
     *   should not lose every connection to pick up a file. A reload is a *signal*: it returns
     *   before pgcat has re-read anything, and a pooler that is still answering for the mode it
     *   held a moment ago after `refusalAfterApply()` has waited is not a pooler about to catch
     *   up — it is one that did not act on the signal. What makes a process read a file is a
     *   process that reads it, so the repair escalates to `restart_command`. The flip's own
     *   command is deliberately not run again: the same signal twice is the same signal.
     *
     *   An installation with `use_reload = false` never reaches the command: its flip already
     *   restarted pgcat on this very file, and a second restart of one program on one set of
     *   bytes is the same command twice. That is said rather than run.
     *
     * WHY IT IS JUDGED BEFORE IT RUNS
     *   `swap()` refuses to leave a file in place behind a command that cannot reach supervisord,
     *   and this is that kind of command, so `SupervisorStep::inspect()` asks the same two
     *   questions first — does its executable resolve, does supervisord know the program — and a
     *   refusal is *reported* rather than thrown: the file and the mode are in place and the fault
     *   is the pooler's, so a run that says why it could not be repaired is worth more than one
     *   that failed. A command that *runs* and exits non-zero is the other case and is thrown:
     *   supervisord was reached and answered no.
     *
     * @return array{attempted: bool, clause: string} `attempted` is true when the pooler was
     *         restarted — the caller reads the pools back for that reason and no other — and
     *         `clause` is never empty, reading as the middle of the reason sentence
     */
    private function restartOnAppliedFile(): array
    {
        if (!$this->useReload()) {
            return [
                'attempted' => false,
                'clause' => 'the flip had already restarted the pooler on that file, so it was not restarted again',
            ];
        }

        $command = ConfigValue::string($this->config['restart_command'] ?? null, self::DEFAULT_RESTART_COMMAND);
        $verdict = $this->supervisorStep->inspect($command);

        if (!$verdict['usable']) {
            return [
                'attempted' => false,
                'clause' => 'the restart command that would have made a fresh process read it was refused before it ran: '.$verdict['detail'],
            ];
        }

        [$exitCode, $stdout, $stderr] = ($this->commandRunner)($command);

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'the pooler did not pick the applied config up and %s exited %d: stdout=%s stderr=%s',
                $command,
                $exitCode,
                trim($stdout),
                trim($stderr),
            ));
        }

        return [
            'attempted' => true,
            'clause' => 'the pooler was restarted on the file the flip wrote',
        ];
    }

    /**
     * The sentence a flip that applied a mode reports when the pooler did not answer for a pool
     * of it: what was applied, what the pooler said, what the run did about it, and what was seen
     * afterwards.
     *
     * The last two are the ones only this run knows. `$clause` is the repair's own words and is
     * empty when nothing was attempted; `$after` is the read taken once the repair had run, and a
     * null there is the outcome worth having — the pooler answered for everything, one restart
     * later. A refusal that survives to `$after` is on record here rather than left to the next
     * poll to rediscover, which is what the sentence is for.
     *
     * @param array{database: string, evidence: string}      $refusal the refusal that triggered it
     * @param array{database: string, evidence: string}|null $after   the refusal that stood after
     *        the repair, or null when it cleared
     */
    private function appliedPoolReason(string $mode, ?string $previousMode, array $refusal, string $clause, ?array $after): string
    {
        $sentence = sprintf(
            'the %s config was applied (%s → %s) and the running pooler did not answer for pool "%s" — %s',
            $this->modeNoun($mode),
            $previousMode ?? 'never',
            $mode,
            $refusal['database'],
            $refusal['evidence'],
        );

        if ($clause !== '') {
            $sentence .= '; '.$clause;
        }

        return $after === null
            ? $sentence.', and it then answered for every pool the variant declares'
            : sprintf('%s, and it still had no pool "%s" — %s', $sentence, $after['database'], $after['evidence']);
    }

    /**
     * A mode as the sentences here name it: what the config *is* rather than the resolver's own
     * spelling of it, because an operator looking at pgcat is looking at one of the two files.
     */
    private function modeNoun(string $mode): string
    {
        return $mode === 'readers' ? 'readers' : 'writer-only';
    }

    /**
     * The pool names the variant for `$mode` declares, read off the file a flip would apply.
     *
     * The source is read first because it is the variant the mode is *asking for*, and the
     * target only as a fallback for a source that cannot be read — the same ordering a swap
     * uses, so a pool named here is one the repair would actually put in place. An unreadable
     * file yields no pools rather than a guess, and no pools is not a fault.
     *
     * @return list<string>
     */
    private function configuredPools(string $mode): array
    {
        $toml = $this->readToml(ConfigValue::string(
            $mode === 'readers'
                ? ($this->config['readers_path'] ?? null)
                : ($this->config['no_readers_path'] ?? null),
        ));

        if ($toml === null) {
            $toml = $this->readToml(ConfigValue::string($this->config['config_path'] ?? null));
        }

        return $toml === null ? [] : PoolProbe::declaredPools($toml);
    }

    /**
     * A pgcat TOML file's bytes, or null when the path is empty, is not a readable file, or
     * cannot be read. Null rather than an empty string so "no pools" and "no file" stay
     * distinguishable at the one place that has to fall back.
     */
    private function readToml(string $path): ?string
    {
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
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
        $attempts = $this->startAttempts();
        $delayMs = $this->startRetryDelayMs();
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

        // A state file another account left read-only is repaired before the write, or the
        // silenced `file_put_contents` below loses the run's record with no line to say so —
        // the same fault the boot audit repairs on its record, one file over.
        WritableFile::handBack($this->stateFile);

        $written = @file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // The state file is written in place, so its mode survives a rewrite — which is exactly
        // why it needs handing back: the first writer is not necessarily the account that reads
        // and rewrites it next, and the run after it would otherwise rewrite nothing and report
        // a state it cannot record.
        if ($written !== false) {
            WritableFile::handBack($this->stateFile);
        }
    }

    private function readLastMode(): ?string
    {
        $lastMode = $this->readState()['last_mode'] ?? null;

        return is_string($lastMode) ? $lastMode : null;
    }

    /**
     * The mode record, and the mechanism that wrote it.
     *
     * Both mechanisms write this file — the scheduled entry's apply and the forced path — so
     * `$via` is what makes the record answer *who moved the pooler* rather than only *what mode
     * it is in*. A reading that cannot tell the two apart is one that describes the mechanism
     * which has stopped acting, which is the whole of what `windowStatus()` has to be able to
     * say: the per-minute entry stops at the boot window, and the tasks go on moving the mode
     * for as long as the container lives.
     */
    private function writeLastMode(string $mode, ?string $previousMode, string $via): void
    {
        $this->writeState([
            ...$this->readState(),
            'last_mode' => $mode,
            'previous_mode' => $previousMode,
            'last_flipped_at' => $this->nowIso(),
            'flipped_by' => 'pgcat-flipper',
            'flipped_via' => $via,
        ]);
    }

    /**
     * Stamp the beat every run of the scheduled entry leaves behind.
     *
     * WHY THIS IS NOT `recordRun()`
     *   `recordRun()` answers what a run *did* — how many there were, which kind, when it converged
     *   — and all of it is the boot window's evidence, so it is written inside the window and the
     *   state file stops moving when the window closes. A stopped schedule therefore leaves the same
     *   file as a healthy one, for as long as the container lives: the last run an hour ago, the
     *   convergence it recorded on its second try, and nothing to say that the entry that wrote it is
     *   not running any more. `beat_at` is the fact that closes it, and it is written on every run,
     *   including the ones that take the closed-window path and record nothing else.
     *
     * WHY IT IS WRITTEN WITHOUT THE LOCK
     *   The lock serialises flips — the file swap and the supervisor command — and a beat is not a
     *   flip: it is one `readState()` and one `writeState()` of a merge that changes a single key.
     *   Taking the lock here would also mean a run that is about to be a no-op waits behind a flip in
     *   another process, which is the busiest moment a container has. The cost of not taking it is
     *   one lost beat: a flip writing its own state in the same instant can replace this from a copy
     *   it read a moment earlier, so the beat is at most one cadence stale — which the tolerance
     *   {@see ScheduleBeat} judges with is measured in cadences and absorbs.
     *
     * And a run the flipper is *told* to make does not stamp it: `forceMode()` — which
     * `db:pgcat-flip --force-mode` and the reader-window tasks are — is a request from outside the
     * schedule rather than the entry firing, and a schedule that is being driven by hand is exactly
     * the schedule whose beat should not be kept alive by it.
     */
    private function beat(): void
    {
        $state = $this->readState();
        $state[ScheduleBeat::STATE_KEY] = $this->nowIso();

        $this->writeState($state);
    }

    /**
     * Record one run of the flip, whether or not it changed anything.
     *
     * Called inside the lock, on every path that got that far, because "the flip ran and had
     * nothing to do" and "the flip never ran" are the two states a booting container can be in
     * and only one of them is healthy. `converged_at` is written once and never moved: the first
     * run that reached a usable mode is what this container's window is judged on, and a later
     * failure must not be able to un-work a container that did come up.
     *
     * A repair is counted as well as run: `pool_repairs` and `last_pool_repair_at` are the two
     * facts `db:pgcat-flip --status` reports, and they are written where the run is rather than
     * at the call sites — a pool repair happens on both of this method's paths, and a counter
     * that only one path moved would be a pooler that keeps needing repair read as one that never
     * did. The five facts themselves are `withRepair()`'s, so the repair a `forceMode()` flip
     * makes at a boundary lands in the same count even though that path records no run at all.
     *
     * `last_repair_pool` and `last_repair_mode` ride with them, and for the same reason they are
     * written here rather than by a caller: they are the answer to the question the count opens —
     * *which* pool, under *which* variant — and either call site could forget one of them. `$pool`
     * is the name the refusing probe gave, and null leaves the key null rather than carrying the
     * previous repair's pool into a new run's record; `$mode` is already a parameter because a run
     * records the mode it left in place.
     */
    private function recordRun(string $kind, string $mode, ?string $pool = null): void
    {
        $state = $this->readState();
        $usable = in_array($kind, [FlipResult::KIND_FLIPPED, FlipResult::KIND_NO_CHANGE, FlipResult::KIND_REPAIRED], true);

        $state['runs'] = ConfigValue::int($state['runs'] ?? null, 0) + 1;
        $state['last_run_at'] = $this->nowIso();
        $state['last_kind'] = $kind;
        $state['last_mode_active'] = $mode;

        if ($kind === FlipResult::KIND_REPAIRED) {
            $state = $this->withRepair($state, $mode, $pool);
        }

        if ($usable && !isset($state['converged_at'])) {
            $state['converged_at'] = $this->nowIso();
            $state['converged_mode'] = $mode;
        }

        $this->writeState($state);
    }

    /**
     * The state a repair leaves: the count, the moment, the pool and the mode, and one more entry in
     * the history the rate is read from.
     *
     * A pure function of the state it is given rather than a second writer, because two paths count
     * repairs and only one of them may write a run: `recordRun()` merges this into the run it is
     * already recording (one write), and `forceMode()` merges it into a state file it has just written
     * its mode into (one more). The facts are the same either way — a repair is a repair wherever a
     * flip made it — and a copy of these five lines in `forceMode()` is how the forced path's repairs
     * would end up counted somewhere `--status` and `/health/db` do not read.
     *
     * `$pool` is the name the refusing probe gave; null leaves the key null rather than carrying the
     * previous repair's pool into a new record, so a repair with no pool to name cannot make the last
     * one read as current.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function withRepair(array $state, string $mode, ?string $pool): array
    {
        $state['pool_repairs'] = ConfigValue::int($state['pool_repairs'] ?? null, 0) + 1;
        $state['last_pool_repair_at'] = $this->nowIso();
        $state['last_repair_pool'] = $pool;
        $state['last_repair_mode'] = $mode;
        $state[self::REPAIR_HISTORY_KEY] = $this->recordRepairTime($state[self::REPAIR_HISTORY_KEY] ?? null);

        return $state;
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
        return $this->parseTimestamp($state[$key] ?? null);
    }

    /**
     * One value as a moment, for both the single stamps beside the count and the list the rate is
     * read from — so an entry that cannot be placed is read as absent in one place rather than
     * handled twice.
     */
    private function parseTimestamp(mixed $raw): ?float
    {
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

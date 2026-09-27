<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ReaderDays;
use Uak35\WeightedDbManager\Support\ReaderWindows;
use Uak35\WeightedDbManager\Support\RedisAccess;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * php artisan db:doctor [connection] [--strict] [--json]
 *
 * One command that answers "will this installation do what it is configured to
 * do", before traffic arrives. Eleven things can be wrong while the application
 * still boots and answers requests:
 *
 *   provider swap     the framework's DatabaseServiceProvider is still the one
 *                     binding `db`, so reads are unweighted and nothing says so
 *   weighted factory  the factory is where the replica is chosen, so a host app
 *                     that rebinds `db.factory` silently loses weighting too
 *   published config  the application never published its own config/db-manager.php
 *                     and is running on the sample values
 *   pgcat gate        swrr.pgcat.enabled is on for a connection pgcat cannot
 *                     front, or armed without the paths a flip needs
 *   pgcat files       the flipper is armed but a file a flip reads or writes is
 *                     missing or unreachable, so the swap fails at the moment
 *                     traffic is supposed to switch. The one row that never prints a
 *                     suggestion: what is wrong is a path or a permission, and neither
 *                     is a value this package can name for somebody else's filesystem
 *   pgcat supervisor  the flipper is armed but the command it runs *after* the swap
 *                     cannot work: the executable does not resolve for this user, the
 *                     program name is an unquoted glob the shell may rewrite, or
 *                     supervisor does not know the program — so the flip refuses
 *                     before it swaps anything, and this row is the preflight that
 *                     says so before the window rather than at it
 *   replica metadata  replicas carry no weight metadata, so the resolver picks at
 *                     random while the table looks weighted — or a replica's weight,
 *                     cores or memory is a value the resolver cannot read, which
 *                     drops it from the pool or weights it as something else while
 *                     the pool still looks whole
 *   switch values     swrr.pgcat.enabled, swrr.pgcat.use_reload or
 *                     swrr.allow_local_fallback is written as something that is not
 *                     on or off, which is refused: a cast reads 'false', 'off' and
 *                     'no' as on — and on the pgcat switch that arms a file swap
 *   reader windows    swrr.reader_windows holds entries that are not windows, which
 *                     are refused: the natural flat string '10:00-14:20' is read as
 *                     no window at all, leaving reads on the pool at every hour
 *   store probe       the store reachability check can never run: probing is
 *                     switched off, or the record that throttles it cannot be
 *                     written, so the PING is skipped on every boot in silence
 *   store             the primary store (Redis) is not answering, so workers fall
 *                     back to an in-process rotation that is not shared
 *
 * The store is checked with a live PING rather than trusted to the store's own
 * health flag: that flag is only raised after an operation has already failed, so
 * on its own it reports "nothing has failed yet", not "the store is reachable".
 *
 * SUGGESTIONS
 * -----------
 * A row prints a `suggestion` line under itself only when it can name the replacement
 * exactly — the value the offending setting reduces to — and never when naming one would
 * mean guessing at what the operator meant. Three rows can, each from the class that owns
 * the value rather than from a second copy of the rule: `reader windows` prints the setting
 * line for the two spellings that reduce to one (`ReaderWindows`, `ReaderDays`), `pgcat gate`
 * prints `swrr.pgcat.enabled = false` when the switch is armed where pgcat cannot act, and
 * `pgcat supervisor` prints the command a flip would run when a program name needs quoting or
 * the command was left empty (`PgcatConfigFlipper`).
 *
 * Every other failure is repaired somewhere a config line cannot reach — a PATH, a permission,
 * a `[program:]` section on the supervisor side, a path only the installation knows — and
 * those rows print no line rather than a plausible-looking one. That is the same line the
 * reader-window refusal draws for a value it will not re-spell, applied to a whole row.
 *
 * READ ONLY
 * ---------
 * Every check inspects configuration, resolved classes and the store. Nothing is
 * written, no replica is probed with a query and no flip is attempted, so the
 * command is safe in a deploy pipeline and against a live database. PING is the
 * only network call; an unreachable store costs one connect timeout. The one other
 * process this command starts is the read-only `supervisorctl status` that
 * `pgcat supervisor` derives from the flip's own command — the same binary, the same
 * program name, `status` in the verb position — so nothing is restarted or signalled. For which
 * replica answers what, see db:probe-replicas. The pgcat file check judges
 * readability and writability from filesystem metadata (is_readable/is_writable);
 * it never creates a probe file to prove a directory is writable, so it leaves no
 * trace even on a read-only deploy.
 *
 * EXIT CODE
 * ---------
 * 0 when nothing failed, 1 when a check failed — or, with --strict, when any check
 * warned. That makes `php artisan db:doctor --strict` usable as a release gate: it
 * fails on "the app boots, but the configuration cannot work".
 *
 * The rule lives in gateFailed() and nowhere else, so the summary can derive why a run
 * exits 1 instead of restating it: with nothing failed and --strict on, the last line
 * says the flag is what turned the warnings into a failure, because an operator reading
 * exit 1 has only rows of PASS and WARN in front of them and nothing to tell them the
 * gate — not the installation — is what broke.
 *
 * JSON
 * ----
 * `--json` writes one object and nothing else — every row as `checks`, each with its
 * `verdict` and its `suggestions`, the run's `counts`, the `verdict` for the run, and the
 * `exit_code` the process exits with — so a deploy gate asserts on a field rather than
 * parsing the table. The flag, the envelope and the rules it keeps are the same ones
 * db:pgcat-flip's --json keeps, so the package has one machine-readable contract rather
 * than one per command.
 *
 * The key set is fixed and always present — never a key that is sometimes absent — so a
 * rule can be written as a comparison instead of a lookup that might be missing, which is
 * the rule the health payload's `counts` already follows. The verdicts are the three the
 * table prints — PASS, WARN, FAIL — rather than a second vocabulary for the same fact: the
 * report an operator read and the object a job parsed name the same row the same way, and
 * there is no mapping between the two to drift.
 *
 * `exit_code` travels inside the object because the two halves of the one answer belong to
 * one artefact. A row's verdict says how the installation looks; the code says what the
 * gate decided about that; and `strict` explains the only case where they differ — with the
 * flag on, warnings exit 1 while every single row is PASS or WARN. That is the sentence the
 * rendered run prints beside the code, carried here as the three fields a job branches on.
 *
 * `--json` changes the report and nothing else: the same checks run, the code is the one
 * gateFailed() computed, and nothing is written either way. It composes with `--strict`,
 * which is how a pipeline will pass it, and there is no combination to refuse — a doctor is
 * not a daemon, so a report cannot be untrue of the run that produced it.
 */
class DbDoctor extends Command
{
    /** @var string */
    private const PASS = 'PASS';

    /** @var string */
    private const WARN = 'WARN';

    /** @var string */
    private const FAIL = 'FAIL';

    /**
     * Every on/off setting this package has, and the finding key its refusal is filed under.
     *
     * The keys come from the provider rather than being spelled again, because they are the
     * same keys the boot audit writes: a row that invented its own name for a refusal could
     * not date it from the boot that reported it.
     *
     * @var array<string, string>
     */
    private const SWITCH_KEYS = [
        'swrr.pgcat.enabled' => WeightedDatabaseServiceProvider::KEY_PGCAT_ENABLED_REFUSED,
        'swrr.pgcat.use_reload' => WeightedDatabaseServiceProvider::KEY_PGCAT_RELOAD_REFUSED,
        'swrr.allow_local_fallback' => WeightedDatabaseServiceProvider::KEY_FALLBACK_REFUSED,
    ];

    /**
     * What each of those settings is left as when its value cannot be read, said in the row's
     * own words so the sentence names the consequence instead of only the default.
     *
     * @var array<string, string>
     */
    private const SWITCH_CONSEQUENCES = [
        'swrr.pgcat.enabled' => 'the switch falls back to off — the value this setting documents — so the flipper is inert and no pgcat file is swapped',
        'swrr.pgcat.use_reload' => 'the switch falls back to on — the value this setting documents — so a flip signals a reload rather than running the restart command',
        'swrr.allow_local_fallback' => 'the switch falls back to on — the value this setting documents — so a read that cannot reach a replica may still be served from the in-process fallback',
    ];

    protected $signature = 'db:doctor
                            {connection=pgsql : Connection whose weighted replicas are inspected}
                            {--strict : Treat warnings as failures, for a deploy gate}
                            {--json : Print one JSON object — every row, its verdict, the suggestions and the exit code — instead of the rendered table}';

    protected $description = 'Check an installation end to end: provider swap, weighted factory, published config, pgcat gate, files and supervisor step, replica metadata, switch values, reader windows, store probe and reachability';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        [$manager, $resolutionError] = $this->weightedManager();

        $rows = [
            $this->providerSwap($manager, $resolutionError),
            $this->weightedFactory(),
            $this->publishedConfig(),
            $this->pgcatGate(),
            $this->pgcatFiles(),
            $this->pgcatSupervisor(),
        ];

        if ($manager instanceof WeightedDatabaseManager) {
            $rows[] = $this->replicaMetadata($manager, $connection);
            $rows[] = $this->switchValues();
            $rows[] = $this->readerWindows();
            $rows[] = $this->storeProbe();
            $rows[] = $this->storeReachability($manager, $connection);
        }

        $counts = $this->counts($rows);
        $strict = (bool) $this->option('strict');

        $exitCode = $this->gateFailed($counts['failed'], $counts['warnings'])
            ? self::FAILURE
            : self::SUCCESS;

        if ($this->option('json')) {
            return $this->report($rows, $connection, $strict, $counts, $exitCode);
        }

        $this->render($rows, $connection, $counts);

        return $exitCode;
    }

    /**
     * The rule the whole command exists to enforce, in one place: a run fails when a check
     * failed, or — under `--strict` — when any check warned.
     *
     * Both the exit code and the summary read it, so the sentence an operator gets next to
     * the code cannot describe a different rule from the one that produced it.
     */
    private function gateFailed(int $failed, int $warned): bool
    {
        return $failed > 0 || ($warned > 0 && (bool) $this->option('strict'));
    }

    /**
     * Resolve `db` once, so every later check reports against the same manager and
     * a failure to resolve it is a result rather than a stack trace.
     *
     * @return array{0: WeightedDatabaseManager|null, 1: string|null}
     */
    private function weightedManager(): array
    {
        try {
            $manager = DB::getFacadeRoot();
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }

        return [$manager instanceof WeightedDatabaseManager ? $manager : null, null];
    }

    /**
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function providerSwap(?WeightedDatabaseManager $manager, ?string $resolutionError): array
    {
        if ($resolutionError !== null) {
            return $this->row('provider swap', self::FAIL, 'resolving db failed: ' . $resolutionError);
        }

        if (!$this->providerRegistered()) {
            return $this->row(
                'provider swap',
                self::FAIL,
                'WeightedDatabaseServiceProvider is not registered — see the README\'s provider step',
            );
        }

        if (!$manager instanceof WeightedDatabaseManager) {
            return $this->row(
                'provider swap',
                self::FAIL,
                'db is not the weighted manager, so reads are served unweighted',
            );
        }

        return $this->row('provider swap', self::PASS, 'WeightedDatabaseServiceProvider is in charge, weighted manager bound');
    }

    /**
     * The replica is chosen inside the factory, so this binding is what makes
     * weighting run at all — a host app can register the provider and still lose
     * weighting by replacing `db.factory`.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function weightedFactory(): array
    {
        try {
            $factory = $this->laravel->make('db.factory');
        } catch (Throwable $e) {
            return $this->row('weighted factory', self::FAIL, 'resolving db.factory failed: ' . $e->getMessage());
        }

        if (!$factory instanceof WeightedConnectionFactory) {
            return $this->row(
                'weighted factory',
                self::FAIL,
                'db.factory is ' . get_debug_type($factory) . ' — replica selection happens there, so reads stay unweighted',
            );
        }

        return $this->row('weighted factory', self::PASS, 'db.factory is ' . WeightedConnectionFactory::class);
    }

    /**
     * The application owns config/db-manager.php; the package ships a sample. A
     * missing copy means the sample is in use, and a byte-identical copy means
     * nobody has reviewed the values yet. Neither is an error — both are worth
     * saying before anyone trusts a window or a path that was never chosen.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function publishedConfig(): array
    {
        $published = $this->laravel->configPath('db-manager.php');
        $sample = dirname(__DIR__, 3) . '/config/db-manager.php';

        if (!is_file($published)) {
            return $this->row(
                'published config',
                self::WARN,
                'no config/db-manager.php — the package sample is in use: '
                .'php artisan vendor:publish --tag=db-manager-config',
            );
        }

        if (is_file($sample) && file_get_contents($published) === file_get_contents($sample)) {
            return $this->row(
                'published config',
                self::WARN,
                'config/db-manager.php is still byte-identical to the sample — no value has been reviewed',
            );
        }

        return $this->row('published config', self::PASS, 'config/db-manager.php is published and differs from the sample');
    }

    /**
     * The gate as this process sees it, and the mismatch the boot record still holds.
     *
     * The two can disagree, and the disagreement is the point: the record is written
     * by whichever boot last saw a mismatch and is cleared by a boot that does not, so
     * a record that still stands means an installation has been in that state across
     * boots. A preflight that only reports its own verdict would call a release clean
     * over a mismatch first seen days ago — or, when the record names a different
     * connection than the one being inspected, over one it never looked at.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatGate(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat gate', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat gate', self::FAIL, 'the flipper could not be built: ' . $e->getMessage());
        }

        $recorded = $this->recordedFinding(WeightedDatabaseServiceProvider::KEY_PGCAT_GATE);
        $connection = $flipper->healthSummary()['connection'];

        // Switched on and unable to act: the driver mismatch the boot warning logs,
        // or an armed flipper missing a path a flip needs. Both fail a preflight,
        // because the setting promises something that cannot happen.
        $warning = ($flipper->isEnabled() || $flipper->isMismatched()) ? $flipper->armingWarning() : null;

        if ($warning !== null) {
            $detail = $warning;

            // When the record agrees there is a mismatch, the only thing it adds is
            // age: whether this is the state the deploy created or one it inherited.
            if ($recorded !== null) {
                $detail .= ' — ' . $this->recordClause($recorded, $connection);
            }

            // The sentence names the repair in prose — "Set swrr.pgcat.enabled = false, or
            // point database.default at the pgcat-fronted connection" — because the flip's
            // refusal and --dry-run print that same sentence and have no suggestion column.
            // The line is the half of it a report can be acted on without being read as
            // English, and the flipper names it: the row prints what the flip reads, in the
            // spelling the config file uses. Null when the fault is the other one this branch
            // covers — paths that are unset, whose values are this installation's to choose.
            return $this->row(
                'pgcat gate',
                self::FAIL,
                $detail,
                self::suggestionLines($flipper->suggestionForGate()),
            );
        }

        $detail = $flipper->isEnabled()
            ? 'armed and ready — ' . ($flipper->armedReason() ?? '')
            : 'off — ' . ($flipper->disabledReason() ?? 'disabled');

        // This boot has no mismatch, and a mismatch is still on record for the
        // installation: either the record names a connection this run does not
        // inspect, or the boot that would have closed it out never ran. A warning
        // rather than a failure, because the gate being judged here is fine — but it
        // is on record, so it is not silent either.
        if ($recorded !== null) {
            return $this->row(
                'pgcat gate',
                self::WARN,
                $detail . '; the boot record still holds a mismatch ' . $this->recordClause($recorded, $connection),
            );
        }

        return $this->row('pgcat gate', self::PASS, $detail);
    }

    /**
     * One finding a previous boot recorded and no boot has closed out, or null.
     *
     * Read from the same file the audit writes, so the doctor reports what the
     * installation has been saying about itself rather than a second opinion. Every
     * unreadable state — no record, no audit binding, a hand-edited file — is "nothing
     * recorded": the doctor falls back to its own verdict instead of failing over a
     * file it could not parse.
     *
     * The caller names the one finding it is reporting. There is no list of keys here to
     * fall back through: a row that is about one problem and quotes another's finding is
     * dating something it is not saying.
     *
     * @return array{resolution: string, context: array<string, mixed>, first_reported_at: string}|null
     */
    private function recordedFinding(string $key): ?array
    {
        return $this->recordedFindings()[$key] ?? null;
    }

    /**
     * Every finding a previous boot recorded, keyed by the finding's own name.
     *
     * The reader fallback reports several findings under several keys, and a row that
     * names all of them has to date each from its own — see `readerRow()`. Reading the
     * whole record once is what makes that possible without a lookup order to get wrong.
     *
     * @return array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}>
     */
    private function recordedFindings(): array
    {
        try {
            return $this->laravel->make(BootAudit::class)->read()['findings'];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The prefix a row adds when a finding is still on record: empty when nothing is, so
     * a caller can append it unconditionally. The clause itself is shared with `pgcat
     * gate`, which is the row that has to name a connection.
     *
     * @param array{resolution: string, context: array<string, mixed>, first_reported_at: string}|null $recorded
     */
    private function recordSuffix(?array $recorded): string
    {
        return $recorded === null ? '' : ' — '.$this->recordClause($recorded, '');
    }

    /**
     * The recorded mismatch as one clause: when it was first reported, and on which
     * connection when that is not the connection this row is about.
     *
     * @param array{resolution: string, context: array<string, mixed>, first_reported_at: string} $finding
     */
    private function recordClause(array $finding, string $connection): string
    {
        $since = sprintf('recorded unresolved since %s', $finding['first_reported_at']);
        $seenOn = ConfigValue::string($finding['context']['connection'] ?? null, '');

        if ($seenOn === '' || $seenOn === $connection) {
            return $since;
        }

        return sprintf(
            '%s, on connection "%s" (driver "%s")',
            $since,
            $seenOn,
            ConfigValue::string($finding['context']['driver'] ?? null, 'unknown'),
        );
    }

    /**
     * The filesystem preconditions a flip cannot survive without. `pgcat gate` only
     * asks whether the flipper is switched on and whether the three paths are set;
     * this asks whether the files those paths name can actually be read and written.
     * The difference is the failure an armed, correctly-gated installation discovers
     * at the moment traffic is supposed to switch: swap() throws then, and the window
     * it was meant to open never opens.
     *
     * Paths come from the flipper's own status(), so the doctor checks exactly what a
     * flip would touch rather than parsing the same config a second time.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatFiles(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat files', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat files', self::FAIL, 'the flipper could not be built: ' . $e->getMessage());
        }

        // Nothing reads or writes pgcat files while the flipper is inert, so there is
        // no precondition to judge and no reason to require the paths to exist.
        if (!$flipper->isEnabled()) {
            return $this->row('pgcat files', self::PASS, 'flipper is not armed — no pgcat file is read or written');
        }

        $status = $flipper->status();
        $problems = $this->pgcatFileProblems($status);

        if ($problems !== []) {
            // Deliberately no suggestion, and this is the only row that never carries one.
            // Every problem here is a path or a permission: a file that is not there, a
            // directory that will not take the temp file, a state file whose directory is
            // read-only. The package can name none of them — the right value is whatever this
            // installation's pgcat and supervisor are actually configured to use — and a
            // plausible-looking path pasted into configuration is worse than a blank column,
            // because it replaces the operator's intent with this tool's guess. So the row
            // names each unusable file and stops.
            return $this->row('pgcat files', self::FAIL, implode('; ', $problems));
        }

        return $this->row(
            'pgcat files',
            self::PASS,
            'a flip could run: both sources are readable and '
            . ConfigValue::string($status['config_path'])
            . ' is writable',
        );
    }

    /**
     * The flip's last step, judged without taking it: does the command supervisor would
     * receive resolve, and does supervisor know the program it names?
     *
     * swap() copies the variant over pgcat.toml and then runs a *shell* command line —
     * `supervisorctl signal HUP "pgcat:*"` by default. Everything before that point can
     * be perfect and the flip still fails, because this step depends on three things no
     * file check can see: the executable resolving for the user the flipper runs as, the
     * program name surviving the shell, and supervisord knowing that name. `pgcat files`
     * covers the files; this covers the command, and it is the last row that can — a
     * flip that gets this far has already replaced the file, so a failure here leaves
     * the new config in place with nothing having read it.
     *
     * The command is the flipper's own — `PgcatConfigFlipper::supervisorCommand()`, the
     * one swap() runs — so the row cannot describe a different command than the flip
     * performs. The only process this row starts is derived from that command with
     * `status` in the verb position: no restart, no signal, no stop.
     *
     * Moot while the flipper is inert, exactly like `pgcat files`: nothing swaps a file,
     * so nothing restarts supervisor either.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatSupervisor(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat supervisor', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat supervisor', self::FAIL, 'the flipper could not be built: '.$e->getMessage());
        }

        if (!$flipper->isEnabled()) {
            return $this->row('pgcat supervisor', self::PASS, 'flipper is not armed — no supervisor command is ever run');
        }

        $command = $flipper->supervisorCommand();

        try {
            $step = $this->laravel->make(SupervisorStep::class);
        } catch (Throwable $e) {
            return $this->row('pgcat supervisor', self::FAIL, 'the supervisor step could not be built: '.$e->getMessage());
        }

        // The verdict is the flipper's own: the same call decides whether a flip may swap
        // the file, so a row that passes and a flip that refuses are impossible. The
        // sentence is shared too — the row appends what the refusal means for this report,
        // and adds nothing that could describe a different fault.
        $verdict = $step->inspect($command);

        if (!$verdict['usable']) {
            // Two of the step's faults reduce to an exact command, and the flipper names the
            // key a flip reads for each — the unquoted program name (where the repair is the
            // command the step itself built, with the name quoted) and a command left empty
            // (where it is the command the setting documents). The other faults — an executable
            // that does not resolve, a socket that is not there, a supervisor that does not know
            // the program — are repaired in a PATH, a running supervisord or a `[program:]`
            // section, so the row prints the fault and no line.
            return $this->row(
                'pgcat supervisor',
                self::FAIL,
                $verdict['detail'].' — a flip refuses before it swaps the file, so nothing is replaced and no mode is recorded',
                self::suggestionLines($flipper->suggestionForSupervisor($verdict)),
            );
        }

        // Nothing to ask supervisor about, and which of the two reasons it is decides
        // where the operator looks: a command that is not supervisorctl, or one that
        // drives supervisorctl without naming a program.
        if ($verdict['fault'] === SupervisorStep::FAULT_NOT_SUPERVISORCTL || $verdict['fault'] === SupervisorStep::FAULT_NO_PROGRAM) {
            return $this->row('pgcat supervisor', self::PASS, $verdict['detail']);
        }

        return $this->row(
            'pgcat supervisor',
            self::PASS,
            sprintf(
                '%s — a flip would run %s, and would refuse to swap the file if this answer changed',
                $verdict['detail'],
                $verdict['flip_command'],
            ),
        );
    }

    /**
     * Every way a file a flip uses can be unusable, named individually so an operator
     * sees which one to fix rather than only that a flip would fail.
     *
     * @param array<string, mixed> $status
     * @return list<string>
     */
    private function pgcatFileProblems(array $status): array
    {
        $problems = [];

        // swap() reads the source matching the mode it is entering, so both sources
        // have to be readable or one of the two directions cannot run.
        foreach (['readers_path' => 'reader source', 'no_readers_path' => 'writer source'] as $key => $label) {
            $path = ConfigValue::string($status[$key] ?? null);

            if ($path === '') {
                $problems[] = "swrr.pgcat.{$key} is not set ({$label})";
            } elseif (!is_file($path)) {
                $problems[] = "{$label} [{$path}] does not exist";
            } elseif (!is_readable($path)) {
                $problems[] = "{$label} [{$path}] is not readable";
            }
        }

        $target = ConfigValue::string($status['config_path'] ?? null);

        if ($target === '') {
            $problems[] = 'swrr.pgcat.config_path is not set (flip target)';
        } else {
            if (!is_file($target)) {
                $problems[] = "flip target [{$target}] does not exist";
            } elseif (!is_writable($target)) {
                $problems[] = "flip target [{$target}] is not writable";
            }

            // The copy is written beside the target as {target}.tmp.{pid} and then
            // renamed over it, so the directory needs write permission even when the
            // file itself has it.
            $directory = dirname($target);

            if (!$this->directoryWritable($directory)) {
                $problems[] = "directory [{$directory}] is not writable, so the atomic swap cannot write its temp file";
            }
        }

        // The state and lock files are how a flip remembers that it happened and how a
        // concurrent flipper stands down, and this directory is also where the boot
        // check records an unresolved pgcat mismatch so a later boot can log its
        // resolution. Every one of those writes is silenced with @, so when their
        // directory is not writable a flip still reports success — and then
        // restarts pgcat again on the next poll, forever, and the mismatch warning is
        // logged with nothing on record to close it out.
        foreach (['state_file' => 'flip state', 'lock_file' => 'flip lock'] as $key => $label) {
            $path = ConfigValue::string($status[$key] ?? null);

            if ($path === '') {
                $problems[] = "swrr.pgcat.{$key} is not set ({$label})";
                continue;
            }

            $directory = dirname($path);

            if (!$this->directoryWritable($directory)) {
                $problems[] = "{$label} directory [{$directory}] is not writable";
            }
        }

        return $problems;
    }

    /**
     * A directory that exists and will accept the temp file a flip writes into it.
     */
    private function directoryWritable(string $directory): bool
    {
        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Whether the replicas' own metadata is what the resolver reads.
     *
     * The row's question is not "does this installation look weighted" — a replica with
     * `weight => 'heavy'` looks weighted and is not — but "is the pool the read list describes".
     * So it reads the configured replicas rather than the resolved pool: the pool is what the
     * resolver kept, and a value it could not read is exactly what it dropped. A replica whose
     * metadata is unreadable is a **failure** that names it, because the alternative is what used
     * to happen — the replica left the pool and the row reported the smaller pool as the
     * installation, with the count already short and nothing to say which replica went.
     *
     * `weight: 0` is the one difference the package means: it is how a replica is disabled, so the
     * row passes, and names the replica so that "2 replicas" is never mistaken for the read list.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function replicaMetadata(WeightedDatabaseManager $manager, string $connection): array
    {
        try {
            $config = $manager->connectionConfig($connection);
            $rows = $manager->replicaStatus($connection);
        } catch (Throwable $e) {
            return $this->row('replica metadata', self::FAIL, "connection [{$connection}]: " . $e->getMessage());
        }

        $replicas = ConfigValue::assocList($config['read'] ?? []);

        if ($replicas === []) {
            return $this->row(
                'replica metadata',
                self::WARN,
                "no read replicas on [{$connection}] — every read uses the writer",
            );
        }

        // Read the *configured* replicas, not the resolved pool: a value the resolver cannot read
        // is exactly the case where the two disagree, and the pool is the half that has already
        // lost the evidence — a replica whose weight it cannot read is one it drops, so counting
        // the pool would report a smaller installation as the installation. This runs before the
        // "no metadata at all" warning, because metadata that is present and unreadable is a
        // different fault from metadata that is absent.
        $problems = [];
        $drops = false;

        foreach ($replicas as $replica) {
            [$sentences, $leaves] = $this->metadataProblems($replica);
            $drops = $drops || $leaves;

            foreach ($sentences as $sentence) {
                $problems[] = '['.$manager->replicaKey($replica).'] '.$sentence;
            }
        }

        if ($problems !== []) {
            return $this->row(
                'replica metadata',
                self::FAIL,
                sprintf(
                    'replica metadata the resolver cannot read on [%s]: %s — %s',
                    $connection,
                    implode('; ', $problems),
                    $drops
                        ? 'a replica weighted 0 leaves the pool, so reads are routed over a smaller pool than the read list describes and nothing else reports that it happened'
                        : 'the replica stays in the pool, weighted as something other than what the read list describes',
                ),
            );
        }

        if (!$this->hasWeightMetadata($replicas)) {
            return $this->row(
                'replica metadata',
                self::WARN,
                sprintf(
                    'the %d replicas on [%s] carry no weight metadata (weight/cpu_cores/ram_gb) — '
                    .'the resolver falls back to random picks',
                    count($replicas),
                    $connection,
                ),
            );
        }

        $total = (int) array_sum(array_column($rows, 'weight'));

        if ($total <= 0) {
            return $this->row(
                'replica metadata',
                self::FAIL,
                "every replica on [{$connection}] resolves to weight 0 — reads cannot be routed",
            );
        }

        // `weight: 0` is the documented way to disable a replica, so one that is missing from the
        // pool is a decision rather than a fault — but the pool is then smaller than the read
        // list, and saying which replica left is the difference between a count an operator can
        // trust and one that quietly shrank.
        $disabled = $this->disabledReplicas($manager, $replicas);

        if ($disabled !== []) {
            return $this->row(
                'replica metadata',
                self::PASS,
                sprintf(
                    'the pool on [%s] holds %d of the %d configured replicas (total weight %d): %s disabled with weight 0',
                    $connection,
                    count($rows),
                    count($replicas),
                    $total,
                    implode(', ', $disabled),
                ),
            );
        }

        return $this->row(
            'replica metadata',
            self::PASS,
            sprintf('%d replicas on [%s], total weight %d', count($rows), $connection, $total),
        );
    }

    /**
     * The metadata on one replica that the resolver does not read as written.
     *
     * The judgement is the resolver's own arithmetic rather than a second opinion about it.
     * `WeightResolver::resolveWeight()` reads a weight through `max(0, ConfigValue::int(…))`,
     * a core count through `max(1, ConfigValue::int(…, 1))` and memory through
     * `max(0.0, ConfigValue::float(…))`, and `ConfigValue` falls back — to 0, or to 1 for cores —
     * for anything that is not a number. So there are exactly two ways a value stops being the
     * value routing uses:
     *
     *   1. it is not a number, so the fallback is read instead; or
     *   2. it is a number under the floor its key is read at.
     *
     * `weight` is the key with a floor a value is *meant* to sit on: 0 disables the replica, which
     * is the one difference between what was written and what is used that the package means. So
     * for that key the second condition is stated as its opposite — a reading of 0 out of a value
     * that is not 0 — and an explicit 0 is left to the row's count rather than reported here. A
     * negative weight, a `weight: 0.5` the resolver truncates to 0, and a weight that is not a
     * number all read as 0 and all leave the pool, which is why they are one condition.
     *
     * @param array<string, mixed> $replica
     * @return array{0: list<string>, 1: bool} the sentences, and whether a replica leaves the pool
     */
    private function metadataProblems(array $replica): array
    {
        $problems = [];
        $leaves = false;

        if (array_key_exists('weight', $replica)) {
            $written = $replica['weight'];
            $read = max(0, ConfigValue::int($written));

            if (!self::isNumeric($written) || ($read <= 0 && ConfigValue::float($written) !== 0.0)) {
                $leaves = true;

                $problems[] = sprintf(
                    'weight is %s, which the resolver reads as %d',
                    self::describeMetadata($written),
                    $read,
                );
            }
        }

        // The floors here are the resolver's own: `max(1, …)` for cores, `max(0.0, …)` for memory.
        // A core count below one is read as one core, so a replica declared with none is weighted
        // like a one-core box; memory below zero is read as none at all.
        if (array_key_exists('cpu_cores', $replica) && !self::aboveFloor($replica['cpu_cores'], 1.0)) {
            $read = max(1, ConfigValue::int($replica['cpu_cores'], 1));

            $problems[] = sprintf(
                'cpu_cores is %s, which the resolver reads as %d core%s',
                self::describeMetadata($replica['cpu_cores']),
                $read,
                $read === 1 ? '' : 's',
            );
        }

        if (array_key_exists('ram_gb', $replica) && !self::aboveFloor($replica['ram_gb'], 0.0)) {
            $problems[] = sprintf(
                'ram_gb is %s, which the resolver reads as %s GB',
                self::describeMetadata($replica['ram_gb']),
                max(0.0, ConfigValue::float($replica['ram_gb'])),
            );
        }

        return [$problems, $leaves];
    }

    /**
     * A metadata value as the row reads it: the number it is, or the shape it is instead.
     *
     * `ReaderWindows::describe()` is the package's describer for a value a setting will not read —
     * strings quoted, arrays counted, anything else named by its type — and it is right for every
     * non-number here. A number is printed as itself, because "weight is int" says nothing about
     * *which* int, and the numbers this row reports are the ones the resolver changed. A bool is a
     * number to `ConfigValue`, so it is printed as the number it is read as rather than as "bool".
     */
    private static function describeMetadata(mixed $value): string
    {
        if (!self::isNumeric($value)) {
            return ReaderWindows::describe($value);
        }

        $number = ConfigValue::float($value);

        return $number === floor($number) ? (string) (int) $number : (string) $number;
    }

    /**
     * Whether `ConfigValue` reads a number out of a value rather than falling back.
     *
     * The accepted set is `ConfigValue`'s own — an int, a float, a bool, or the string form of a
     * number — because the question this asks is "does the resolver substitute something for this
     * value", and a value `ConfigValue` reads is one it does not substitute for. A bool is
     * therefore readable: `weight: true` is read as 1 and `weight: false` as the documented 0, and
     * neither is a value anything was substituted for.
     */
    private static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value)
            || (is_string($value) && is_numeric($value));
    }

    /**
     * Whether a value is a number at or above the floor its key is read at — the same question as
     * isNumeric(), plus the clamp the resolver applies on top of the read.
     */
    private static function aboveFloor(mixed $value, float $floor): bool
    {
        return self::isNumeric($value) && ConfigValue::float($value) >= $floor;
    }

    /**
     * The replicas the read list disables on purpose: `weight: 0`, read as written.
     *
     * One value only, because it is the one the package documents as meaning something other than
     * a size — so a replica absent from the pool because of it is named rather than failed on. A
     * weight that is negative, unreadable, or a fraction the read truncates to 0 is not this, and
     * reaches the row as a value the resolver could not read.
     *
     * @param list<array<string, mixed>> $replicas
     * @return list<string>
     */
    private function disabledReplicas(WeightedDatabaseManager $manager, array $replicas): array
    {
        $disabled = [];

        foreach ($replicas as $replica) {
            if (!array_key_exists('weight', $replica)) {
                continue;
            }

            if (max(0, ConfigValue::int($replica['weight'])) !== 0 || ConfigValue::float($replica['weight']) !== 0.0) {
                continue;
            }

            $disabled[] = $manager->replicaKey($replica);
        }

        return $disabled;
    }

    /**
     * The three on/off settings, read as the values they were written as rather than cast.
     *
     * A switch is the one setting a cast cannot be trusted with. `ConfigValue::bool()` narrows
     * with PHP's rules and its docblock says what that means — `(bool) 'false'` is true — so
     * `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` and `swrr.allow_local_fallback` all used to
     * read `'false'`, `'off'` and `'no'` as *on*, and `'maybe'` as on too, without a word. On
     * the pgcat switch that is not a cosmetic mistake: it arms a file swap the operator asked
     * for the opposite of. Every value that is not on or off is now refused — here and at boot,
     * at error level — and the setting falls back to the value its own documentation prints.
     *
     * The three are one row because they are one mistake repeated. A row each would make a run
     * of them look like three unrelated faults, and the fix is the same gesture in all three
     * cases. Each problem still carries its own finding key, so each is dated from the boot that
     * reported *it*: the record holds one entry per key, and one date on all three would claim
     * the installation started being wrong about all of them at once.
     *
     * The pgcat pair is read from the flipper, exactly as `pgcat gate` reads it, and the third
     * from the provider's own reader. That is the whole point of the split: the flip and this
     * row have to agree that `'off'` is off, so neither classifies a switch the other acts on.
     * Nothing is guessed: a value that is not a switch is reported and left refused, so no
     * suggestion line is printed — re-spelling `'flase'` would be inventing the operator's
     * intent, which is the mistake the refusal exists to prevent.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function switchValues(): array
    {
        $swrr = ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr'));

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                'switch values',
                self::FAIL,
                'the flipper could not be built, so the pgcat switches are not inspected: '.$e->getMessage(),
            );
        }

        $recorded = $this->recordedFindings();
        $status = $flipper->status();
        $fallback = WeightedDatabaseServiceProvider::allowLocalFallback($swrr['allow_local_fallback'] ?? null);

        $refused = $flipper->refusedSwitches();

        if ($fallback['refused'] !== null) {
            $refused['swrr.allow_local_fallback'] = $fallback['refused'];
        }

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = [];

        foreach (self::SWITCH_KEYS as $setting => $key) {
            if (!isset($refused[$setting])) {
                continue;
            }

            $problems[] = [
                'status' => self::FAIL,
                'key' => $key,
                'sentence' => SwitchValue::describeRefused([$setting => $refused[$setting]])
                    .' — '.SwitchValue::ACCEPTED.'. Refused: '.self::SWITCH_CONSEQUENCES[$setting].'.',
                // Deliberately none: a value that is not a switch cannot be re-spelled without
                // guessing at what was meant, and a guess pasted in is a decision made by the
                // report instead of by the operator.
                'suggestion' => null,
            ];
        }

        if ($problems === []) {
            return $this->row('switch values', self::PASS, implode(', ', [
                'swrr.pgcat.enabled '.self::switchWord($status['configured_enabled']),
                'swrr.pgcat.use_reload '.self::switchWord($status['use_reload']),
                'swrr.allow_local_fallback '.self::switchWord($fallback['on']),
            ]).' — each reads as on or off, so nothing is refused');
        }

        return $this->datedRow('switch values', $problems, $recorded);
    }

    /**
     * A row's repair lines: the ones it could name, in the order it built them, with the
     * ones it could not left out entirely.
     *
     * Written once because three rows now hand over a repair that may or may not exist, and
     * the difference between "this row has no repair" and "this row's list is empty" is not
     * one a caller should have to spell: the JSON contract's `suggestions` is a list either
     * way, and a row that passed `null` where the contract wants `[]` would break a job that
     * counted on `[]`.
     *
     * @return list<string>
     */
    private static function suggestionLines(?string ...$lines): array
    {
        return array_values(array_filter($lines, static fn (?string $line): bool => $line !== null));
    }

    /**
     * A resolved switch as the word the row prints for it, so the pass line writes `on` and
     * `off` the way the sentences that refuse them spell the same two values.
     */
    private static function switchWord(bool $on): string
    {
        return $on ? 'on' : 'off';
    }

    /**
     * `swrr.reader_windows` as the resolver reads it, and as a preflight should.
     *
     * The accepted shape is one array per window. A flat `'10:00-14:20'` is the way a
     * person naturally writes a window, and it is not one: read as "no window at all",
     * it leaves the resolver in its permissive mode, so reads use the replica pool at
     * every hour — the opposite of the fallback the setting describes. That is input the
     * package refuses rather than interprets, so a preflight fails on it instead of
     * leaving the mistake to be discovered from read routing.
     *
     * The other three ways the setting can fail to act are warnings, matching the boot
     * audit: an unusable day list (permissive), days outside 1…7 (the pool is never
     * used), and windows whose start is not before their end (never entered).
     *
     * Every judgement comes from the code that acts on the value — `ReaderWindows` for
     * the shape, `ReaderDays` for the days, `TimeWindowResolver` for the mode and the
     * windows that can never be entered — so the row cannot disagree with routing.
     *
     * It names every problem it finds, in the order the audit reports them, rather than
     * stopping at the first: the audit already says all of them, and an operator who fixes
     * the windows only to hear about the days on the next preflight has paid a second
     * deploy for a sentence that would have fitted on this run. The verdict is the loudest
     * problem in the row — a refusal beside a warning is still a refusal — and a refused
     * value that names its own replacement contributes a `suggestion` line, so two refused
     * settings print two repairs.
     *
     * Each problem is dated from *its own* finding. The record holds one entry per finding
     * key and the reader fallback has several, so quoting the first key the record happens
     * to hold would date a problem the row is not reporting — a claim about when this
     * installation started being wrong, made about the wrong thing.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readerWindows(): array
    {
        $swrr = ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr'));
        $configured = $swrr['reader_windows'] ?? null;
        $configuredDays = $swrr['reader_days'] ?? null;

        $split = ReaderWindows::split($configured);
        $splitDays = ReaderDays::split($configuredDays ?? [1, 2, 3, 4, 5]);
        $recorded = $this->recordedFindings();

        $windows = $split['usable'];
        $days = $splitDays['usable'];

        $windowsRefused = $split['shape'] !== null || $split['rejected'] !== [];
        $daysRefused = $configuredDays !== null && ($splitDays['shape'] !== null || $splitDays['rejected'] !== []);

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = [];

        // A value the package will not read as windows. Refusing it is the point: the flat
        // string '10:00-14:20' is how a person naturally writes a window, so interpreting it
        // (or dropping it) turns a typo into the opposite behaviour without a word. This one is
        // a failure rather than a warning — the value is rejected, not merely unable to act —
        // and it names the entry and the shape the setting reads.
        if ($windowsRefused) {
            $offending = $split['shape'] !== null
                ? 'swrr.reader_windows is '.$split['shape'].', not a list of windows'
                : ReaderWindows::describeRejected($split['rejected']);

            $consequence = $windows === []
                ? 'nothing is left to apply, so reads use the replica pool at every hour'
                : sprintf('%d well-formed window(s) still apply', count($windows));

            $suggestion = ReaderWindows::suggestion($configured);

            $problems[] = [
                'status' => self::FAIL,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED,
                'sentence' => $offending.' — '.ReaderWindows::ACCEPTED.'. Refused: '.$consequence.'.',
                // The repair, when the refused value re-spells into one. The sentence above
                // already names what is wrong; this is the half an operator can paste.
                'suggestion' => $suggestion === null ? null : 'swrr.reader_windows = '.$suggestion,
            ];
        }

        // The days setting, one key over and the same rule. A list written as a string is the
        // `.env` spelling of a list and the one thing this setting cannot read, and dropping it
        // silently is worse than refusing it: the resolver is permissive with no day left, so a
        // restrictive day list reads as its opposite. Only a configured value is refused — the
        // `[1, 2, 3, 4, 5]` default is the package's own.
        if ($daysRefused) {
            $offending = $splitDays['shape'] !== null
                ? 'swrr.reader_days is '.$splitDays['shape'].', not a list of days'
                : ReaderDays::describeRejected($splitDays['rejected']);

            // The same order the audit applies, because the two share a setting: nothing
            // usable left is the permissive case whatever the windows say, then the days that
            // survive, then the two ways there is no window to go with them — refused (named
            // above, in this row) or never configured.
            $consequence = match (true) {
                $days === [] => 'nothing is left to apply, so reads use the replica pool on every day',
                $windows !== [] => sprintf('%d well-formed day(s) still apply', count($days)),
                $windowsRefused => 'no window can be used either — see the reader_windows problem above — so neither list can put a read on the replica pool',
                default => 'no reader_windows are configured, so the fallback is off whatever the day list says',
            };

            $suggestion = ReaderDays::suggestion($configuredDays);

            $problems[] = [
                'status' => self::FAIL,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_DAYS_REFUSED,
                'sentence' => $offending.' — '.ReaderDays::ACCEPTED.'. Refused: '.$consequence.'.',
                // `'1,2,3'` is the one spelling that reduces to an exact replacement; an entry
                // that is not a day number and not a list of them gets no line, because the
                // package does not guess at what a name meant.
                'suggestion' => $suggestion === null ? null : 'swrr.reader_days = '.$suggestion,
            ];
        }

        try {
            $resolver = $this->laravel->make(TimeWindowResolver::class);
        } catch (Throwable $e) {
            $problems[] = [
                'status' => self::FAIL,
                'key' => null,
                'sentence' => 'the resolver could not be built: '.$e->getMessage().'.',
                'suggestion' => null,
            ];

            return $this->readerRow($problems, $recorded);
        }

        // Permissive mode. The documented opt-out — no windows at all — is this state reached
        // on purpose, so it passes with the reason stated, whatever reader_days says: with no
        // window to enter, a day list cannot stop a read. Nothing usable left after a refusal is
        // the same state, and the refusal above has already said as much.
        if ($windows === []) {
            if ($problems === []) {
                $problems[] = [
                    'status' => self::PASS,
                    'key' => null,
                    'sentence' => 'no reader_windows set — every read uses the replica pool, the documented opt-out.',
                    'suggestion' => null,
                ];
            }

            return $this->readerRow($problems, $recorded);
        }

        // The day list holds no usable day, so permissive mode answers every read before the
        // days or the windows are consulted — the other way to reach "always readers". A day
        // list whose entries were unusable arrives here as well and is refused above; that
        // refusal has already said what it leaves behind, so this sentence is not added beside
        // it.
        if ($configuredDays !== null && $days === []) {
            if (!$daysRefused) {
                $problems[] = [
                    'status' => self::WARN,
                    'key' => WeightedDatabaseServiceProvider::KEY_READER_ALWAYS_READERS,
                    'sentence' => 'swrr.reader_days holds no usable day — a day is an ISO-8601 number, '
                        .'1 = Monday … 7 = Sunday — so the fallback cannot apply: the resolver is '
                        .'permissive and reads use the replica pool on every day, at every hour.',
                    'suggestion' => null,
                ];
            }

            // Nothing below applies: permissive mode means the resolver never reaches a window,
            // so a window that can never be entered is not a fact about how reads are routed —
            // it is a fact about a window nothing consults.
            return $this->readerRow($problems, $recorded);
        }

        // Days outside ISO-8601 can never be a reader day, and a window whose start is not
        // before its end can never be entered. One finding in the audit, under one key, so one
        // sentence here: the pool is never used, and whichever of the two causes hold are named
        // in it.
        $noReaderDay = ReaderDays::inIsoRange($days) === [];

        $unreachable = $resolver->unreachableWindows();

        $described = implode(', ', array_map(
            static fn (int $index): string => 'window '.($index + 1),
            $unreachable,
        ));

        $neverEntered = $unreachable !== [] && count($unreachable) === count($windows);

        if ($noReaderDay || $neverEntered) {
            $problems[] = [
                'status' => self::WARN,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_ALWAYS_WRITER,
                'sentence' => match (true) {
                    $noReaderDay && $neverEntered => sprintf(
                        'swrr.reader_days has no day in 1…7 (read as %s) and every window (%s) has a start that is not before its end, so no day is ever a reader day and none can be entered: the replica pool is never used.',
                        implode(', ', $days),
                        $described,
                    ),
                    $noReaderDay => sprintf(
                        'no day in 1…7 (read as %s), so no day is ever a reader day and the replica pool is never used.',
                        implode(', ', $days),
                    ),
                    default => sprintf(
                        'every window (%s) has a start that is not before its end, so none can be entered and the replica pool is never used.',
                        $described,
                    ),
                },
                'suggestion' => null,
            ];
        } elseif ($unreachable !== []) {
            $problems[] = [
                'status' => self::WARN,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_UNMATCHABLE_WINDOWS,
                'sentence' => sprintf(
                    '%s has a start that is not before its end, so %s can never be entered and never falls back to the writer.',
                    $described,
                    count($unreachable) === 1 ? 'it' : 'they',
                ),
                'suggestion' => null,
            ];
        }

        // Everything above is about a fallback that cannot apply or cannot be entered; with none
        // of it, the setting is doing what it says and the row says so.
        if ($problems === []) {
            $problems[] = [
                'status' => self::PASS,
                'key' => null,
                'sentence' => sprintf(
                    '%d window(s) on %s reader day(s) — reads fall back to the writer outside them.',
                    count($windows),
                    count(ReaderDays::inIsoRange($days)),
                ),
                'suggestion' => null,
            ];
        }

        return $this->readerRow($problems, $recorded);
    }

    /**
     * One row out of every problem the reader fallback has.
     *
     * @param list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems
     * @param array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}> $recorded
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readerRow(array $problems, array $recorded): array
    {
        return $this->datedRow('reader windows', $problems, $recorded);
    }

    /**
     * A row assembled out of every problem a setting has, each dated from its own finding.
     *
     * Shared rather than written twice because the two rows that use it — `reader windows` and
     * `switch values` — make the same claim about their problems and would otherwise be able to
     * make it two ways: that the verdict is the loudest problem in the row (`verdict()` is where
     * that rule lives), that the detail is each problem's own sentence separated so an operator
     * can tell where one ends and the next begins, that a problem naming a replacement adds a
     * `suggestion` line — a list of them, since two refused settings have two repairs and
     * printing one would leave the other to be re-spelled by hand — and that a problem is dated
     * from *its own* key. The record holds one entry per finding key and either row can have
     * several, so quoting the first key the record happens to hold would date a problem the row
     * is not reporting: a claim about when this installation started being wrong, made about the
     * wrong thing.
     *
     * Every sentence ends in a full stop before it arrives here — that is what the age is
     * inserted in front of, so a dated problem reads as one sentence rather than a sentence with
     * an appendix.
     *
     * @param list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems
     * @param array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}> $recorded
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function datedRow(string $name, array $problems, array $recorded): array
    {
        $sentences = [];
        $suggestions = [];

        foreach ($problems as $problem) {
            $finding = $problem['key'] === null ? null : ($recorded[$problem['key']] ?? null);

            $sentences[] = $finding === null
                ? $problem['sentence']
                : substr($problem['sentence'], 0, -1).$this->recordSuffix($finding).'.';

            if ($problem['suggestion'] !== null) {
                $suggestions[] = $problem['suggestion'];
            }
        }

        return $this->row($name, $this->verdict($problems), implode('; ', $sentences), $suggestions);
    }

    /**
     * Whether the store reachability check can run at all, which the row below it
     * cannot say for itself.
     *
     * A probe is skipped in two states, and both are silent by design: probing is
     * switched off (`store_probe_seconds = 0`, the documented way to keep the audit to
     * configuration checks), or the audit record cannot be written — and the record is
     * the throttle, so without it the audit skips the PING rather than run one per
     * request. Either way an unreachable store is reported by nothing, on every boot,
     * forever, and the reachability row's PASS means "this boot did not probe" rather
     * than "the store answered". This row is where that distinction is visible.
     *
     * The two are independent, so an installation can be in both at once, and the row names
     * every one of them rather than the first it reaches: an operator who switches the probe
     * on to clear the warning should not have to deploy again to be told that the record it
     * writes can never be written. The verdict is the loudest of them, so a switched-off
     * probe beside an unwritable record is the failure the record is. A third state is named
     * the same way — the in-process store that leaves a running probe nothing to reach — and
     * only while the probe is switched on, because with it off "nothing is probed" is that
     * same warning rather than a second problem.
     *
     * Nothing here is decided in the doctor: the interval and the writability come from
     * `BootAudit::storeProbeStatus()`, which is the same source `storeProbeDue()` reads,
     * so the row cannot disagree with the runtime about whether a probe happens.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function storeProbe(): array
    {
        try {
            $status = $this->laravel->make(BootAudit::class)->storeProbeStatus();
        } catch (Throwable $e) {
            return $this->row(
                'store probe',
                self::FAIL,
                'the boot audit could not be built, so nothing probes the store: ' . $e->getMessage(),
            );
        }

        $interval = $status['seconds'] === 1 ? '1 second' : $status['seconds'] . ' seconds';

        /** @var list<array{status: string, sentence: string}> $problems */
        $problems = [];

        if (!$status['enabled']) {
            $problems[] = [
                'status' => self::WARN,
                'sentence' => sprintf(
                    'switched off (swrr.audit.store_probe_seconds = %d) — an unreachable store is reported by nothing at boot; db:doctor still probes on demand%s',
                    $status['seconds'],
                    // Only a warning while the record can be written: beside an unwritable
                    // record the row is a failure anyway, and --strict has nothing left to add.
                    $status['writable'] ? ', and --strict would fail this warning' : '',
                ),
            ];
        }

        if (!$status['writable']) {
            $problems[] = [
                'status' => self::FAIL,
                'sentence' => "the record at {$status['file']} cannot be written, and it is what throttles the probe, so the PING is skipped on every boot — nothing will report an unreachable store",
            ];
        }

        // A configured interval with an in-process store has nothing to reach. The
        // probe is not missing here, it is moot — and the reachability row already
        // warns about the store that makes it so. The sentence says "switched on", so it
        // describes a probe that is on and is not added beside the warning above.
        $store = WeightedDatabaseServiceProvider::storeSelection(
            ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr')),
        );

        if ($status['enabled'] && $store['effective'] !== 'redis') {
            $problems[] = [
                'status' => self::WARN,
                'sentence' => sprintf(
                    'switched on every %s but nothing is probed: swrr.primary_store is %s, so reads are served in-process and the check has nothing to reach',
                    $interval,
                    $store['configured'] === '' ? 'the in-process store' : "\"{$store['configured']}\"",
                ),
            ];
        }

        if ($problems === []) {
            return $this->row(
                'store probe',
                self::PASS,
                "at most one PING every {$interval}, throttled by the record at {$status['file']}, which is writable",
            );
        }

        $sentences = [];

        foreach ($problems as $problem) {
            $sentences[] = $problem['sentence'];
        }

        return $this->row('store probe', $this->verdict($problems), implode('; ', $sentences));
    }

    /**
     * The verdict of a row built out of several problems: the loudest one wins, because a
     * refusal beside a warning is still a refusal.
     *
     * The fold itself lives in loudest() and this is one of its two callers, for the same
     * reason `gateFailed()` is a method rather than a copy of itself at the exit code — a row
     * that names more than one problem cannot then disagree with the run's summary about how
     * bad the installation is.
     *
     * @param list<array{status: string}> $problems
     */
    private function verdict(array $problems): string
    {
        $statuses = [];

        foreach ($problems as $problem) {
            $statuses[] = $problem['status'];
        }

        return $this->loudest($statuses);
    }

    /**
     * The loudest of a set of verdicts — the one fold behind both scopes: how bad a row is,
     * from the problems it names, and how bad the run is, from its rows.
     *
     * One fold rather than two because the two answers are read together: the closing line
     * of the table summarises the run while the rows above it state their own verdicts, and
     * the JSON report carries both. A rule written twice is a rule that can say a run is
     * healthy above a row that failed.
     *
     * @param list<string> $statuses
     */
    private function loudest(array $statuses): string
    {
        $status = self::PASS;

        foreach ($statuses as $one) {
            if ($one === self::FAIL) {
                $status = self::FAIL;
            } elseif ($one === self::WARN && $status !== self::FAIL) {
                $status = self::WARN;
            }
        }

        return $status;
    }

    /**
     * The run's tally, computed once so the three readers of it cannot count different things:
     * the summary line an operator reads, the `checks` the exit matrix asserts, and the JSON
     * report's `counts`.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @return array{checks: int, passed: int, warnings: int, failed: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['checks' => count($rows), 'passed' => 0, 'warnings' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if ($row['status'] === self::FAIL) {
                $counts['failed']++;
            } elseif ($row['status'] === self::WARN) {
                $counts['warnings']++;
            } else {
                $counts['passed']++;
            }
        }

        return $counts;
    }

    /**
     * How bad the run is, from its rows — the same fold as a row's verdict, one scope up, so
     * the object's `verdict` is the row vocabulary rather than a second one for the same
     * question.
     *
     * This is deliberately *not* derived from the exit code. Under `--strict` the code is `1`
     * with nothing failed, so a verdict read back out of the code would have to call an
     * installation that answered every check broken — which is the confusion the closing
     * sentence exists to prevent. The row verdicts are the installation's; the code is the
     * gate's; `strict` is what joins them.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     */
    private function runVerdict(array $rows): string
    {
        $statuses = [];

        foreach ($rows as $row) {
            $statuses[] = $row['status'];
        }

        return $this->loudest($statuses);
    }

    /**
     * The connection the package follows — `db-manager.swrr.connection` when the installation
     * names one, `database.default` otherwise — as the banner and the JSON report both name it
     * (the key stays `default_connection` so a gate that already reads it keeps working). Read
     * through ActiveConnection, the same place the gate and the flipper read it, so the three
     * cannot describe different applications.
     */
    private function followedConnection(): string
    {
        return ActiveConnection::resolve($this->laravel->make(Repository::class))['connection'];
    }

    /**
     * The run as one JSON object on stdout, and nothing else — written raw, so a detail that
     * happens to contain angle brackets is not read as a console tag and dropped on the way
     * out of a machine-readable channel.
     *
     * The key set is fixed and always present, empty where a run has nothing for a key, so a
     * gate can write `jq -e '.counts.failed == 0'` without first asking whether the field
     * exists. `exit_code` is inside the object because it is the other half of the answer: the
     * number a deploy step branches on, in the same artefact as the rows that explain it, so a
     * run cannot be read one way and acted on another.
     *
     * `checks` is the rows themselves — the same names, the same verdicts and the same
     * suggestions the table prints, including the repairs a row can name — and each key is
     * renamed only where the table's word is the wrong one for a machine: a row's `status` is
     * published as `verdict`, which is what the table calls it and what decides the run.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @param array{checks: int, passed: int, warnings: int, failed: int} $counts
     */
    private function report(array $rows, string $connection, bool $strict, array $counts, int $exitCode): int
    {
        $this->output->writeln(
            (string) json_encode(
                [
                    'command' => 'db:doctor',
                    'connection' => $connection,
                    'default_connection' => $this->followedConnection(),
                    'strict' => $strict,
                    'verdict' => $this->runVerdict($rows),
                    'exit_code' => $exitCode,
                    'counts' => $counts,
                    'checks' => array_map(
                        static fn (array $row): array => [
                            'name' => $row['name'],
                            'verdict' => $row['status'],
                            'detail' => $row['detail'],
                            'suggestions' => $row['suggestions'],
                        ],
                        $rows,
                    ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return $exitCode;
    }

    /**
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function storeReachability(WeightedDatabaseManager $manager, string $connection): array
    {
        try {
            $summary = $manager->healthSummary($connection);
        } catch (Throwable $e) {
            return $this->row('store reachability', self::FAIL, 'the store could not be inspected: ' . $e->getMessage());
        }

        $primary = $summary['primary_store'];
        $serving = $summary['store'];

        // Evidence that the store has already failed here, ahead of any probe.
        if (!$summary['store_healthy']) {
            return $this->row(
                'store reachability',
                self::FAIL,
                "[{$primary}] failed in this process — reads fall back to [{$serving}], which is not shared between workers",
            );
        }

        if ($summary['degraded']) {
            return $this->row(
                'store reachability',
                self::WARN,
                "this process already fell back to [{$serving}] — check whether that predates the deploy",
            );
        }

        // A deliberate in-process store has nothing to reach; the price is that
        // replica rotation is per-process, which is the point of the package.
        if ($this->swrr('primary_store', 'redis') === 'local') {
            return $this->row(
                'store reachability',
                self::WARN,
                "[{$primary}] is the configured primary store — rotation is per-process and not shared between workers",
            );
        }

        $redis = $this->swrr('redis_connection', 'default');

        // Through the container, not the facade: a `Redis` facade resolved before the
        // application bound `redis` caches the phpredis extension class, and every
        // `Redis::connection()` in that process then throws. Asking the container
        // reports the real reason instead of that artefact.
        $error = RedisAccess::ping($this->laravel, $redis);

        if ($error !== null) {
            return $this->row(
                'store reachability',
                self::FAIL,
                "[{$primary}] could not serve a read: {$error} — reads fall back to an in-process rotation that is not shared between workers",
            );
        }

        return $this->row('store reachability', self::PASS, "[{$primary}] answered a PING");
    }

    /**
     * A `db-manager.swrr.*` value, read the way the provider builds the store so
     * the doctor and the runtime cannot disagree about which store is in use.
     */
    private function swrr(string $key, string $fallback): string
    {
        $config = $this->laravel->make(Repository::class);

        return ConfigValue::string($config->get("db-manager.swrr.{$key}"), $fallback);
    }

    /**
     * @param list<array<string, mixed>> $replicas
     */
    private function hasWeightMetadata(array $replicas): bool
    {
        foreach ($replicas as $replica) {
            if (isset($replica['weight']) || isset($replica['cpu_cores']) || isset($replica['ram_gb'])) {
                return true;
            }
        }

        return false;
    }

    private function providerRegistered(): bool
    {
        return $this->laravel->getProviders(WeightedDatabaseServiceProvider::class) !== [];
    }

    /**
     * One check's verdict. `suggestions` are the repairs, printed one per line under the
     * row and only where the offending value reduces to one without guessing —
     * `ReaderWindows::suggestion()` and `ReaderDays::suggestion()` for the reader settings,
     * `PgcatConfigFlipper::suggestionForGate()` and `suggestionForSupervisor()` for the pgcat
     * ones, each from the class that owns the value. A row that names more than one refused
     * setting has one per setting, in the order the problems are named. They are not a fourth
     * verdict and are not counted as checks: the row above them is still the FAIL this
     * run exits on.
     *
     * @param list<string> $suggestions
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function row(string $name, string $status, string $detail, array $suggestions = []): array
    {
        return [
            'status' => $status,
            'name' => $name,
            'detail' => $detail,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @param array{checks: int, passed: int, warnings: int, failed: int} $counts
     */
    private function render(array $rows, string $connection, array $counts): void
    {
        $this->line(
            'Connection inspected: <comment>' . $connection . '</comment>, followed connection is <comment>'
            . $this->followedConnection() . '</comment>',
        );
        $this->newLine();

        foreach ($rows as $row) {
            if ($row['status'] === self::FAIL) {
                $colour = 'red';
            } elseif ($row['status'] === self::WARN) {
                $colour = 'yellow';
            } else {
                $colour = 'green';
            }

            $this->line(sprintf(
                '<fg=%s;options=bold>%s</>  %s  %s',
                $colour,
                $row['status'],
                str_pad($row['name'], 18),
                $row['detail'],
            ));

            // The repairs, in the name column so they read as part of the row above them
            // and so a log can be grepped for them — the label is the only marker they
            // need, and none of them ever becomes a row of its own.
            foreach ($row['suggestions'] as $suggestion) {
                $this->line(sprintf(
                    '      <comment>%s</comment>  %s',
                    str_pad('suggestion', 18),
                    $suggestion,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d checks: <fg=green>%d passed</>, <fg=yellow>%d warning%s</>, <fg=red>%d failed</>',
            $counts['checks'],
            $counts['passed'],
            $counts['warnings'],
            $counts['warnings'] === 1 ? '' : 's',
            $counts['failed'],
        ));

        if ($counts['failed'] === 0 && $counts['warnings'] === 0) {
            $this->line('<fg=green>Installation looks healthy.</>');
        } elseif ($counts['failed'] === 0) {
            // A warning became a failure on purpose, so the run is not the thing that
            // broke — and the rows above, all PASS and WARN, cannot say that on their own.
            $this->line($this->gateFailed($counts['failed'], $counts['warnings'])
                ? sprintf(
                    '<fg=yellow>No failures — but </><fg=red>--strict counts the %d warning%s above as failures: this run exits 1 as a release gate, not because the installation is broken.</>',
                    $counts['warnings'],
                    $counts['warnings'] === 1 ? '' : 's',
                )
                : '<fg=yellow>No failures — review the warnings above.</>');
        } else {
            $this->line('<fg=red>This installation will not behave as configured.</>');
        }
    }
}

<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Log;
use Uak35\WeightedDbManager\Console\Commands\DbDoctor;
use Uak35\WeightedDbManager\Console\Commands\DbFlipPgcatCommand;
use Uak35\WeightedDbManager\Console\Commands\DbProbeReplicas;
use Uak35\WeightedDbManager\Console\Commands\DbReplicaStatus;
use Uak35\WeightedDbManager\Database\Weighted\HealthMonitor;
use Uak35\WeightedDbManager\Database\Weighted\LocalStateStore;
use Uak35\WeightedDbManager\Database\Weighted\RedisAtomicStateStore;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\BootAuditFinding;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ReaderDays;
use Uak35\WeightedDbManager\Support\ReaderWindows;
use Uak35\WeightedDbManager\Support\RedisAccess;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * WeightedDatabaseServiceProvider
 *
 * Drop-in replacement for Illuminate\Database\DatabaseServiceProvider.
 * Registers WeightedDatabaseManager instead of the default DatabaseManager
 * and wires up its SWRR store, weight resolver, health monitor, and
 * windowed read-fallback resolver.
 *
 * REGISTRATION
 * ------------
 *
 * This provider is deliberately NOT auto-discovered. It must take the place of
 * the framework's own database provider, so register it explicitly:
 *
 *  Laravel 11/12 — bootstrap/providers.php:
 *
 *      return [
 *          // Replace (or comment out) Illuminate\Database\DatabaseServiceProvider::class
 *          Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider::class,
 *      ];
 *
 *  Laravel 10 — config/app.php → 'providers' array:
 *
 *      // Illuminate\Database\DatabaseServiceProvider::class,
 *      Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider::class,
 *
 *  Apps that swap providers through a rebind map (Illuminate\Database\DatabaseServiceProvider::class
 *  => WeightedDatabaseServiceProvider::class) work the same way — the class you
 *  point the map at is this one.
 *
 * WHO PICKS THE REPLICA
 * ---------------------
 * Laravel chooses the read host in Illuminate\Database\Connectors\ConnectionFactory,
 * not in DatabaseManager. So this provider also binds `db.factory` to
 * WeightedConnectionFactory and installs a resolver that calls
 * WeightedDatabaseManager::readConfigFor(). Without that binding the weighted
 * routing would never execute.
 *
 * CONFIGURATION (.env / config/db-manager.php → 'swrr' block)
 * ---------------------------------------------------------
 *  The application owns config/db-manager.php. The file in this package is the
 *  sample that gets published — sample values, not values picked for a given
 *  install — so publishing is part of installing it, and the published copy is
 *  the one to edit afterwards:
 *
 *      php artisan vendor:publish --tag=db-manager-config
 *
 *   DB_STORE_PRIMARY                   redis | local          (default: redis)
 *   SWRR_REDIS_CONNECTION              default                 (default)
 *   SWRR_STATE_TTL                     86400                   (24h)
 *   SWRR_KEY_PREFIX                    swrr                    (used by both stores)
 *   SWRR_ALLOW_LOCAL_FALLBACK          true
 *   DB_WEIGHT_FORMULA                  linear | diminishing    (default: linear)
 *   DB_DEFAULT_WEIGHT_CPU_FACTOR       3.0
 *   DB_DEFAULT_WEIGHT_RAM_FACTOR       3.375
 *
 * Windowed fallback (writer serves reads outside reader_windows):
 *   db-manager.swrr.reader_windows : list<{start, end}>
 *   db-manager.swrr.reader_days    : list<int>  (ISO: 1=Mon … 7=Sun)
 *   db-manager.swrr.timezone       : string    (defaults to UTC)
 */
class WeightedDatabaseServiceProvider extends DatabaseServiceProvider
{
    /**
     * One process audits once, however many applications it boots: Octane reuses a
     * worker, and both the test suite and `config:cache` boot more than one
     * application inside a single process.
     */
    private static bool $bootAudited = false;

    /**
     * The findings the audit evaluates from configuration on every boot. The store's
     * reachability is not here because it is gated on a probe interval; it is added
     * to the checked set on the boots that probe.
     */
    private const AUDITED_KEYS = [
        self::KEY_PGCAT_GATE,
        self::KEY_READER_WINDOWS_REFUSED,
        self::KEY_READER_DAYS_REFUSED,
        self::KEY_READER_ALWAYS_READERS,
        self::KEY_READER_ALWAYS_WRITER,
        self::KEY_READER_UNMATCHABLE_WINDOWS,
        self::KEY_STORE_UNKNOWN,
        self::KEY_WEIGHT_FORMULA,
        self::KEY_PGCAT_ENABLED_REFUSED,
        self::KEY_PGCAT_RELOAD_REFUSED,
        self::KEY_FALLBACK_REFUSED,
    ];

    /**
     * Stable identities for the audit's findings. They are what a record remembers
     * and what a log can be grepped for, so they describe the setting rather than the
     * sentence, and they never change meaning.
     *
     * This one is public because `db:doctor` reads the same record by key: a preflight
     * reports the mismatch still standing from an earlier boot, not only the verdict
     * the current process computes.
     */
    public const KEY_PGCAT_GATE = 'swrr.pgcat.gate';

    /**
     * Values the package refuses to read as windows. At `error` level, unlike the other
     * reader findings: those describe a setting that cannot act, this one is input the
     * package will not interpret on the operator's behalf.
     */
    public const KEY_READER_WINDOWS_REFUSED = 'swrr.reader_windows.refused';

    /**
     * A day list the package will not read: a day number, or an array of them. Same
     * claim as the windows key above and the same level — the value is refused, not
     * merely unable to act — because `'1,2,3'` is a list written as one string, and
     * dropping it turns a restrictive day list into its opposite.
     */
    public const KEY_READER_DAYS_REFUSED = 'swrr.reader_days.refused';

    /**
     * A day list that reads as a list and decides nothing: the resolver is permissive, so
     * reads use the replica pool on every day, at every hour.
     */
    public const KEY_READER_ALWAYS_READERS = 'swrr.reader_fallback.always_readers';

    /**
     * A fallback that can never apply — no day in 1…7, no window that can be entered, or
     * both — so the pool is never used.
     */
    public const KEY_READER_ALWAYS_WRITER = 'swrr.reader_fallback.always_writer';

    /**
     * Some windows can be entered and some cannot, so the ones that can still apply and the
     * setting is doing less than it looks like.
     */
    public const KEY_READER_UNMATCHABLE_WINDOWS = 'swrr.reader_fallback.unmatchable_windows';

    private const KEY_STORE_UNKNOWN = 'swrr.primary_store.unknown';

    private const KEY_STORE_UNREACHABLE = 'swrr.primary_store.unreachable';

    private const KEY_WEIGHT_FORMULA = 'swrr.default_weight_formula.unknown';

    /**
     * `swrr.pgcat.enabled` written as something that is not on or off. At `error` level, like
     * the reader refusals and for the same reason: this is input the package will not
     * interpret on the operator's behalf, not a setting that merely cannot act.
     *
     * The switch is read once, by PgcatConfigFlipper, so the finding reports from the flipper
     * rather than re-reading the block — a refusal classified twice is a report and a flip
     * that can disagree about whether pgcat is on.
     */
    public const KEY_PGCAT_ENABLED_REFUSED = 'swrr.pgcat.enabled.refused';

    /** `swrr.pgcat.use_reload` written as something that is not on or off. */
    public const KEY_PGCAT_RELOAD_REFUSED = 'swrr.pgcat.use_reload.refused';

    /**
     * `swrr.allow_local_fallback` written as something that is not on or off. This switch is
     * the provider's own, so this is the one refusal the provider classifies itself — and it
     * still only classifies: Support\SwitchValue owns the rule and the manager answers to the
     * same reading, so the warning cannot describe a switch the routing does not hold.
     */
    public const KEY_FALLBACK_REFUSED = 'swrr.allow_local_fallback.refused';

    /**
     * Every on/off setting this package has, the finding key its refusal is filed under, and
     * what the setting lands on when it cannot be read.
     *
     * The keys are per setting, not one shared `switches.refused`, because a record remembers
     * a finding by its key: three typos filed under one key would resolve together and date
     * together, and an operator who fixed one would be told nothing about the other two.
     *
     * `$consequence` states the value the refusal falls back to, in words — the value the
     * published config prints beside the setting. It is not `SwitchValue`'s to say: the same
     * malformed value lands differently on each of the three, and a report that guessed would
     * be describing a value the switch does not in fact hold.
     *
     * @var array<string, array{key: string, consequence: string}>
     */
    private const SWITCHES = [
        'swrr.pgcat.enabled' => [
            'key' => self::KEY_PGCAT_ENABLED_REFUSED,
            'consequence' => 'Falling back to the value this setting documents — off — leaves the flipper inert: no pgcat file is read or written until the switch is readable again.',
        ],
        'swrr.pgcat.use_reload' => [
            'key' => self::KEY_PGCAT_RELOAD_REFUSED,
            'consequence' => 'Falling back to the value this setting documents — on — makes a flip signal a reload, which is the gentler of the two commands.',
        ],
        'swrr.allow_local_fallback' => [
            'key' => self::KEY_FALLBACK_REFUSED,
            'consequence' => 'Falling back to the value this setting documents — on — means a read that cannot reach a replica may still be served from the in-process fallback.',
        ],
    ];

    /**
     * What `swrr.allow_local_fallback` documents when it is not written, and where a value the
     * package cannot read lands. Stated beside the setting in config/db-manager.php, the same
     * way PgcatConfigFlipper states its two.
     */
    private const FALLBACK_DEFAULT = true;

    /** The two formulas WeightResolver implements, spelled as it compares them. */
    private const FORMULA_LINEAR = 'linear';

    private const FORMULA_DIMINISHING = 'diminishing';

    public function register(): void
    {
        // Let the parent wire up the connection factory, Eloquent, etc.
        parent::register();

        // The sample config, merged as the base. The app's own config/db-manager.php
        // replaces this file's `swrr` block wholesale — a shallow merge means an
        // app copy shadows the sample — and every install keeps one.
        $this->mergeConfigFrom(__DIR__ . '/../../config/db-manager.php', 'db-manager');

        // -- Weighted connection factory ---------------------------------------
        //
        // The replica choice happens in the connection factory, so the factory
        // itself has to be the weighted one.
        $this->app->singleton('db.factory', fn () => new WeightedConnectionFactory($this->app));

        // -- Primary SWRR state store ------------------------------------------
        $this->app->singleton(RedisAtomicStateStore::class, function (Application $app) {
            $config = $app->make(Repository::class);

            return new RedisAtomicStateStore(
                redisConnection: ConfigValue::string(
                    $config->get('db-manager.swrr.redis_connection'),
                    'default',
                ),
                stateTtlSeconds: ConfigValue::int($config->get('db-manager.swrr.state_ttl'), 86400),
                keyPrefix: ConfigValue::string($config->get('db-manager.swrr.key_prefix'), 'swrr'),
                // The store reaches Redis through the container, so it needs the one
                // it was built in — and it must not use the `Redis` facade, which is
                // what resolves `redis` in the first place.
                app: $app,
            );
        });

        // -- In-process store (primary when configured, fallback otherwise) -----
        $this->app->singleton(LocalStateStore::class, fn (Application $app) => new LocalStateStore(
            ConfigValue::string($app->make(Repository::class)->get('db-manager.swrr.key_prefix'), 'swrr'),
        ));

        // -- Pool cache + health monitor ---------------------------------------
        $this->app->singleton(WeightResolver::class, fn () => new WeightResolver());
        $this->app->singleton(HealthMonitor::class, fn () => new HealthMonitor());

        // -- Windowed read-fallback resolver -----------------------------------
        //
        // If `reader_windows` is empty/absent in config, the resolver returns
        // isReaderWindow()=true at all times — backward-compatible with
        // installations that don't want a writer-fallback window.
        $this->app->singleton(TimeWindowResolver::class, function (Application $app) {
            $swrr = ConfigValue::assoc($app->make(Repository::class)->get('db-manager.swrr'));

            return new TimeWindowResolver(
                readerWindows: self::readerWindows($swrr['reader_windows'] ?? []),
                readerDays: self::readerDays($swrr['reader_days'] ?? [1, 2, 3, 4, 5]),
                timezone: ConfigValue::string($swrr['timezone'] ?? null, 'UTC'),
            );
        });

        // -- Pgcat config flipper -----------------------------------------------
        //
        // Idempotent state-tracking service that swaps pgcat.toml between the
        // reader-enabled and writer-only configurations in lock-step with
        // TimeWindowResolver's mode. Wired to supervisorctl for pgcat reload.
        // Set swrr.pgcat.enabled = false to disable without removing the provider.
        //
        // The flipper is told which connection is current and which driver it
        // uses, because pgcat only fronts PostgreSQL: an installation on MySQL,
        // MariaDB, SQLite or SQL Server has no pgcat pool to flip, so the flipper
        // reports itself disabled and every flip becomes a no-op.
        $this->app->singleton(PgcatConfigFlipper::class, function (Application $app) {
            $config = $app->make(Repository::class);
            $swrr = ConfigValue::assoc($config->get('db-manager.swrr'));
            $pgcat = ConfigValue::assoc($swrr['pgcat'] ?? null);

            $current = self::currentConnection($config);

            return new PgcatConfigFlipper(
                resolver: $app->make(TimeWindowResolver::class),
                config: $pgcat,
                stateFile: ConfigValue::string(
                    $pgcat['state_file'] ?? null,
                    sys_get_temp_dir() . '/pgcat-flip-state.json',
                ),
                lockFile: ConfigValue::string(
                    $pgcat['lock_file'] ?? null,
                    sys_get_temp_dir() . '/pgcat-flip.lock',
                ),
                timezone: ConfigValue::string($swrr['timezone'] ?? null, 'UTC'),
                connectionName: $current['connection'],
                driver: $current['driver'],
                connectionSource: $current['source'],
                supervisorStep: $app->make(SupervisorStep::class),
            );
        });

        // -- The supervisor step, as one object per application ------------------
        //
        // A pgcat flip judges its own supervisor command *before* it replaces the file, and
        // `db:doctor` reports the same judgement as a row. Both resolve this, and so does
        // `--dry-run`: one instance means the row, the preflight and the rehearsal cannot
        // disagree about whether a command works — which is the failure mode that matters,
        // because a row that passes while the flip refuses is worse than either alone.
        $this->app->singleton(SupervisorStep::class, static fn (): SupervisorStep => new SupervisorStep());

        // -- Boot self-audit ----------------------------------------------------
        //
        // Remembers the settings that read as on but cannot act, so the boot that
        // sees one fixed can log its resolution. `store_probe_seconds` is the only
        // knob that costs anything: it is the interval at which the primary store is
        // probed, and it is what keeps the probe off the request path under FPM — see
        // BootAudit's docblock.
        $this->app->singleton(BootAudit::class, function (Application $app) {
            $swrr = ConfigValue::assoc($app->make(Repository::class)->get('db-manager.swrr'));
            $audit = ConfigValue::assoc($swrr['audit'] ?? null);

            return new BootAudit(
                file: ConfigValue::string(
                    $audit['file'] ?? null,
                    sys_get_temp_dir() . '/swrr-audit.json',
                ),
                storeProbeSeconds: ConfigValue::int($audit['store_probe_seconds'] ?? null, 60),
            );
        });

        // -- The main manager singleton ----------------------------------------
        $this->app->singleton('db', function (Application $app) {
            $config = $app->make(Repository::class);
            $swrr = static fn (string $key, mixed $default = null): mixed => $config->get(
                "db-manager.swrr.{$key}",
                $default,
            );

            $store = $swrr('primary_store', 'redis') === 'local'
                ? $app->make(LocalStateStore::class)
                : $app->make(RedisAtomicStateStore::class);

            $factory = self::weightedFactory($app);

            $manager = new WeightedDatabaseManager(
                $app,
                $factory,
                $app->make(WeightResolver::class),
                $app->make(HealthMonitor::class),
                $store,
                $app->make(TimeWindowResolver::class),
            );

            $manager->setAllowLocalFallback(self::allowLocalFallback($swrr('allow_local_fallback'))['on']);
            $manager->setDefaultWeightCpuFactor(ConfigValue::float($swrr('default_weight_cpu_factor'), 3.0));
            $manager->setDefaultWeightRamFactor(ConfigValue::float($swrr('default_weight_ram_factor'), 3.375));
            $manager->setDefaultWeightFormula(ConfigValue::string($swrr('default_weight_formula'), 'linear'));

            // Both stores key their state with the same prefix.
            $manager->setLocalKeyPrefix(ConfigValue::string($swrr('key_prefix'), 'swrr'));
            $manager->setFallbackStore($app->make(LocalStateStore::class));

            // Let the factory delegate every read-PDO decision to the manager.
            $factory->resolveReadConfigUsing(
                static fn (array $connectionConfig): array => $manager->readConfigFor($connectionConfig),
            );

            return $manager;
        });

        // Ensure the transactions manager is present.
        if (!$this->app->bound('db.transactions')) {
            $this->app->singleton('db.transactions', fn () => new DatabaseTransactionsManager());
        }
    }

    /**
     * Config publishing + artisan command registration.
     *
     * Laravel auto-discovers commands only from the application's own
     * app/Console/Commands directory, so the package registers its three
     * commands here:
     *
     *   db:replica-status   weighted replica traffic table
     *   db:probe-replicas   active SELECT 1 probing of every replica
     *   db:pgcat-flip       keep pgcat.toml in step with the reader window
     *
     * parent::boot() is called first — it is what points Eloquent at this
     * manager (Model::setConnectionResolver) and hands models the event
     * dispatcher; skipping it breaks every Eloquent model in the host app.
     */
    public function boot(): void
    {
        parent::boot();

        // Every setting that reads as on while it cannot act is silent in the same
        // way: nothing flips, nothing fails, and a setting that starts working again
        // is not remarked on by anything. Both halves are logged here, warning and
        // resolution — see BootAudit.
        $this->reportBootAudit();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/db-manager.php' => $this->app->configPath('db-manager.php'),
            ], 'db-manager-config');

            $this->commands([
                DbDoctor::class,
                DbReplicaStatus::class,
                DbProbeReplicas::class,
                DbFlipPgcatCommand::class,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Container helpers
    // -------------------------------------------------------------------------

    /**
     * The connection the flipper follows and the driver that decides whether it
     * applies — `db-manager.swrr.connection` when the installation names one, and
     * `database.default` otherwise — plus that connection's driver. Read here once,
     * through ActiveConnection, so the flipper, the health snapshot and the
     * boot-time warning cannot disagree about which connection is in play.
     *
     * @return array{connection: string, driver: string, source: string}
     */
    private static function currentConnection(Repository $config): array
    {
        return ActiveConnection::resolve($config);
    }

    /**
     * Audit the configuration at boot, and log both halves of every finding.
     *
     * The settings audited are the ones that fail silently: an on/off setting written as a
     * value the package cannot read as on or off, `swrr.pgcat.enabled` on for a connection
     * pgcat cannot front, a reader fallback that can never apply (or never lets the pool be
     * used), a `primary_store` that is not the store that runs, an unreachable primary store,
     * and a weight formula that is not one of the two this package has. Each reads as on while
     * doing nothing, and each logs the same sentence /health/db and the CLI report, so the
     * surfaces cannot disagree.
     *
     * A misconfiguration that throws on first use is deliberately not audited here:
     * the runtime logs it, `db:doctor` reports it, and a flip reports it as a failure.
     *
     * The resolution half has to survive a restart — correcting any of these means
     * editing configuration, which is read once per process — so the findings that
     * still stand are written to `swrr.audit.file` and the boot that finds one gone
     * logs its resolution instead. The store probe is the only check that costs
     * anything, and `swrr.audit.store_probe_seconds` bounds it; BootAudit documents
     * both.
     *
     * At most once per process — see $bootAudited.
     */
    private function reportBootAudit(): void
    {
        if (self::$bootAudited) {
            return;
        }

        self::$bootAudited = true;

        try {
            $audit = $this->app->make(BootAudit::class);
            $flipper = $this->app->make(PgcatConfigFlipper::class);
        } catch (\Throwable $e) {
            // A diagnostic must never be the thing that stops an application from
            // booting, so report that it could not run rather than rethrowing.
            Log::warning(
                '[WeightedDB] The boot self-audit could not run: '.$e->getMessage(),
            );

            return;
        }

        $record = $audit->read();
        $swrr = ConfigValue::assoc($this->app->make(Repository::class)->get('db-manager.swrr'));
        $store = self::storeSelection($swrr);

        // The probe is the one check an FPM installation cannot afford per request, so
        // it is gated on the interval and only ever runs against the store that is
        // actually serving reads.
        $probeStore = $store['effective'] === 'redis' && $audit->storeProbeDue($record);

        $checkeds = self::AUDITED_KEYS;

        if ($store['effective'] === 'local' || $probeStore) {
            // Either nothing can be unreachable (the in-process store is local by
            // definition), or this boot probed for real. Otherwise the key is left out
            // and a recorded finding is carried over rather than resolved.
            $checkeds[] = self::KEY_STORE_UNREACHABLE;
        }

        $findings = [
            ...$this->switchFindings($swrr, $flipper),
            ...$this->pgcatFindings($flipper),
            ...$this->readerFallbackFindings($swrr),
            ...$this->primaryStoreFindings($store, $swrr, $probeStore),
            ...$this->weightFormulaFindings($swrr),
        ];

        $audit->report(
            $record,
            $findings,
            checked: $checkeds,
            probedAt: $probeStore ? time() : null,
        );
    }

    /**
     * `swrr.pgcat.enabled` on for a connection pgcat cannot front: nothing flips,
     * nothing fails, and the setting quietly does not do what it says.
     *
     * @return list<BootAuditFinding>
     */
    private function pgcatFindings(PgcatConfigFlipper $flipper): array
    {
        if (!$flipper->isMismatched()) {
            return [];
        }

        $warning = $flipper->armingWarning();

        if ($warning === null) {
            return [];   // isMismatched() implies a sentence; nothing to report without one
        }

        return [new BootAuditFinding(
            key: self::KEY_PGCAT_GATE,
            warning: $warning,
            // True however this stops applying: pgcat arming where it can act, or the
            // switch going off. "Pgcat flipping is active" would be a lie in the second
            // case, which is a real way to silence this warning.
            resolution: 'The pgcat mismatch no longer applies: swrr.pgcat.enabled and the connection\'s driver no longer disagree.',
            context: self::currentConnection($this->app->make(Repository::class)),
        )];
    }

    /**
     * The windowed reader fallback, which claims the writer takes over outside
     * `reader_windows` on `reader_days`. Both of its silent failures are inversions,
     * and they are inversions of each other: an unusable windows or days list leaves
     * the resolver *permissive* (reads never stop using the pool), while windows that
     * can never be entered leave it *strict* (reads never use the pool).
     *
     * Every finding that applies is reported, rather than the first one that does. Two
     * refused values are two mistakes with two repairs, and a boot that named one of them
     * would leave the other to be discovered by whoever goes looking — which is the log
     * archaeology this audit exists to replace.
     *
     * The one thing that cannot be reported twice is a finding *key*: the audit records a
     * finding by its key, so two findings sharing one would have the second overwrite the
     * first. The two causes that meet at `always_writer` — no day in 1…7, and no window
     * that can ever be entered — are therefore one finding in one sentence when both hold,
     * instead of two that collide.
     *
     * What is *not* reported is a finding that does not apply. Permissive mode is the case
     * to keep in mind: the resolver answers it before it looks at a single window
     * (`isAlwaysReaderMode()`), so with no day to match on there is no fact about the
     * windows to state — reporting one would describe a state the resolver never reaches.
     *
     * Refusals come first: they are `error`-level and they are about the input, while
     * everything below them is about the fallback those values describe.
     *
     * @param array<string, mixed> $swrr
     * @return list<BootAuditFinding>
     */
    private function readerFallbackFindings(array $swrr): array
    {
        $configuredWindows = $swrr['reader_windows'] ?? null;
        $configuredDays = $swrr['reader_days'] ?? null;

        $split = ReaderWindows::split($configuredWindows);
        $splitDays = ReaderDays::split($configuredDays ?? [1, 2, 3, 4, 5]);
        $windows = $split['usable'];
        $days = $splitDays['usable'];

        $context = [
            'reader_windows' => $configuredWindows,
            'reader_days' => $configuredDays,
        ];

        $windowsRefused = $split['shape'] !== null || $split['rejected'] !== [];
        $daysRefused = $configuredDays !== null && ($splitDays['shape'] !== null || $splitDays['rejected'] !== []);

        $findings = [];

        // A value the package will not read as windows. Refusing it is the point: the
        // flat string '10:00-14:20' is how a person naturally writes a window, so
        // interpreting it (or dropping it) turns a typo into the opposite behaviour
        // without a word. This one is an error rather than a warning — the value is
        // rejected, not merely unable to act — and it names the entry and the shape.
        if ($windowsRefused) {
            $offending = $split['shape'] !== null
                ? 'swrr.reader_windows is '.$split['shape'].', not a list of windows'
                : ReaderWindows::describeRejected($split['rejected']);

            $consequence = $windows === []
                ? 'With nothing usable left, the fallback cannot apply: the resolver is permissive, and reads use the replica pool on every day, at every hour.'
                : sprintf('The %d window(s) that are well formed still apply.', count($windows));

            $findings[] = new BootAuditFinding(
                key: self::KEY_READER_WINDOWS_REFUSED,
                warning: $offending.'. Refused: '.ReaderWindows::ACCEPTED.'. '.$consequence,
                resolution: 'swrr.reader_windows is readable again: every entry is a window, so nothing is refused.',
                context: $context + [
                    'rejected_windows' => $split['rejected'],
                    'unreadable_value' => $split['shape'],
                    'windows_in_use' => count($windows),
                ],
                level: 'error',
            );
        }

        // The same refusal one key over. `'1,2,3'` is how a list is written in `.env`
        // and not how this setting is read, and dropping it is the worse outcome: with
        // no day left, the resolver is permissive and reads use the replica pool all
        // week, which is the opposite of what a day list is for. Only a configured
        // value is refused — the `[1, 2, 3, 4, 5]` default is the package's own.
        //
        // Both refusals are reported when both hold: they are separate keys, and an
        // operator who fixes one of them should not have to boot again to learn about
        // the other.
        if ($daysRefused) {
            $offending = $splitDays['shape'] !== null
                ? 'swrr.reader_days is '.$splitDays['shape'].', not a list of days'
                : ReaderDays::describeRejected($splitDays['rejected']);

            // Most severe first: a day list with nothing usable left is the permissive case
            // however the windows look, because that is what routing does next.
            $consequence = match (true) {
                $days === [] => 'With nothing usable left, the fallback cannot apply: the resolver is permissive, and reads use the replica pool on every day, at every hour.',
                $windows !== [] => sprintf('The %d day(s) that are well formed still apply.', count($days)),
                // Days that survive, and no window that can be used. The windows finding above
                // reports the other half, so this one says what the pair leaves behind rather
                // than claiming no windows were configured when they plainly were.
                $windowsRefused => 'No window can be used either — see the reader_windows finding in this report — so neither list can put a read on the replica pool.',
                default => 'No reader_windows are configured, so the fallback is off whatever the day list says.',
            };

            $findings[] = new BootAuditFinding(
                key: self::KEY_READER_DAYS_REFUSED,
                warning: $offending.'. Refused: '.ReaderDays::ACCEPTED.'. '.$consequence,
                resolution: 'swrr.reader_days is readable again: every entry is a day number, so nothing is refused.',
                context: $context + [
                    'rejected_days' => $splitDays['rejected'],
                    'unreadable_days_value' => $splitDays['shape'],
                    'days_in_use' => count($days),
                ],
                level: 'error',
            );
        }

        // No windows at all is the documented way to opt out: the resolver is permissive
        // on purpose, and the derived findings below are about a fallback that can apply.
        // Nothing usable left after a refusal is the same state, and the refusal above has
        // already said so in as many words.
        if ($windows === []) {
            return $findings;
        }

        // The day list is empty, so permissive mode answers every read before the days or
        // the windows are consulted — the other way to reach "always readers".
        //
        // A day list whose entries were unusable used to arrive here too. Refusing those
        // entries ends it: every value that cannot be a day is reported above, so an empty
        // list here means an empty list — `reader_days = []` — and not input that was
        // quietly dropped on the way in.
        if ($configuredDays !== null && $days === []) {
            if (!$daysRefused) {
                $findings[] = $this->readerFallbackFinding(
                    self::KEY_READER_ALWAYS_READERS,
                    'swrr.reader_days is set, but none of its entries is a usable day — ISO-8601, 1 = Monday … 7 = Sunday — so the fallback it describes can never apply: the resolver is permissive, and reads use the replica pool on every day, at every hour.',
                    $context,
                );
            }

            // Nothing below applies: permissive mode means the resolver never reaches a
            // window, so a window that can never be entered is not a fact about how reads
            // are routed — it is a fact about a window nothing consults.
            return $findings;
        }

        // Days outside ISO-8601 can never be a reader day, so no hour of the week reaches
        // the pool — the mirror image of the permissive case above.
        $noReaderDay = ReaderDays::inIsoRange($days) === [];

        // And a window whose start is not before its end is never entered: the start is
        // inclusive, the end exclusive, and a time the resolver cannot parse normalises to
        // midnight. TimeWindowResolver answers which ones those are, so the rule lives in
        // one place.
        $unreachable = $this->timeWindowResolver()->unreachableWindows();

        $described = implode(', ', array_map(
            static fn (int $index): string => 'window '.($index + 1),
            $unreachable,
        ));

        $neverEntered = $unreachable !== [] && count($unreachable) === count($windows);

        // One key, so one sentence: "the pool is never used" with both of its causes named
        // when both hold, rather than a second finding that would overwrite this one.
        if ($noReaderDay || $neverEntered) {
            $findings[] = $this->readerFallbackFinding(
                self::KEY_READER_ALWAYS_WRITER,
                match (true) {
                    $noReaderDay && $neverEntered => sprintf(
                        'swrr.reader_days has no day in 1…7 (read as %s) and every configured reader window (%s) has a start that is not before its end: no day is ever a reader day and no window can ever be entered, so the replica pool is never used.',
                        implode(', ', $days),
                        $described,
                    ),
                    $noReaderDay => 'swrr.reader_days is set, but it has no day in 1…7 — ISO-8601, 1 = Monday … 7 = Sunday — so no day is ever a reader day and the replica pool is never used.',
                    default => sprintf(
                        'Every configured reader window (%s) has a start that is not before its end, so no window can ever be entered: the fallback never applies and the replica pool is never used on a reader day.',
                        $described,
                    ),
                },
                $unreachable === [] ? $context : $context + ['unreachable_windows' => $unreachable],
            );
        }

        // Windows that can never be entered, when some of them still can: a different fact
        // from the one above, under its own key, so it is reported beside it rather than
        // instead of it.
        if ($unreachable !== [] && !$neverEntered) {
            $findings[] = $this->readerFallbackFinding(
                self::KEY_READER_UNMATCHABLE_WINDOWS,
                sprintf(
                    '%s has a start that is not before its end, so %s can never be entered and never falls back to the writer.',
                    $described,
                    count($unreachable) === 1 ? 'it' : 'they',
                ),
                $context + ['unreachable_windows' => $unreachable],
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function readerFallbackFinding(string $key, string $warning, array $context): BootAuditFinding
    {
        return new BootAuditFinding(
            key: $key,
            warning: $warning,
            // True whether the lists were repaired or removed: dropping reader_windows
            // is the documented way to opt out, and it ends the finding too.
            resolution: 'The reader windows and days no longer describe a fallback that cannot apply, so the earlier warning no longer applies.',
            context: $context,
        );
    }

    /**
     * The store that will serve reads, and the two ways it can fail to be the store
     * the setting describes: a value that names a store this package does not have
     * (the only accepted ones are `redis` and `local`), and one that cannot be
     * reached. Reachability needs the network, so it is probed — never assumed from
     * the store's own health flag, which is only lowered after a call has already
     * failed and therefore means "nothing has gone wrong yet".
     *
     * @param array{configured: string, effective: string} $store
     * @param array<string, mixed> $swrr
     * @return list<BootAuditFinding>
     */
    private function primaryStoreFindings(array $store, array $swrr, bool $probeStore): array
    {
        $findings = [];
        $intended = strtolower(trim($store['configured']));

        if ($intended !== '' && $intended !== $store['effective']) {
            $findings[] = new BootAuditFinding(
                key: self::KEY_STORE_UNKNOWN,
                warning: sprintf(
                    'swrr.primary_store is "%s", which is not a store this package has, so Redis runs instead — the only stores are "redis" and "local", matched exactly.',
                    $store['configured'],
                ),
                resolution: 'swrr.primary_store and the store that runs agree again, so the earlier warning no longer applies.',
                context: $store,
            );
        }

        if (!$probeStore) {
            return $findings;
        }

        $connection = ConfigValue::string($swrr['redis_connection'] ?? null, 'default');

        // Both halves of "the store cannot serve a read": no usable factory at all
        // (unbound, or bound to something that is not a manager), and a factory whose
        // connection refuses. The first also covers the container resolving `redis`
        // to the phpredis extension class by name.
        $error = RedisAccess::ping($this->app, $connection);

        if ($error !== null) {
            $findings[] = new BootAuditFinding(
                key: self::KEY_STORE_UNREACHABLE,
                warning: sprintf(
                    'The primary store [redis(%s)] could not serve a read: %s — reads fall back to an in-process rotation that is not shared between PHP workers.',
                    $connection,
                    $error,
                ),
                // Also true when the installation stops serving reads from Redis at all —
                // that key is checked on those boots precisely so this closes out.
                resolution: 'The configured primary store is not unreachable any more, so the earlier warning no longer applies.',
                context: ['store' => 'redis('.$connection.')', 'redis_connection' => $connection],
            );
        }

        return $findings;
    }

    /**
     * `swrr.default_weight_formula` naming a formula this package does not have.
     * WeightResolver compares it against `diminishing` and weights everything else
     * linearly, so an unknown value — including a case variant — silently runs
     * linear, and a cluster sized for the diminishing curve is weighted wrongly.
     *
     * @param array<string, mixed> $swrr
     * @return list<BootAuditFinding>
     */
    private function weightFormulaFindings(array $swrr): array
    {
        $formula = ConfigValue::string($swrr['default_weight_formula'] ?? null, 'linear');

        // Compared exactly the way WeightResolver compares it, so nothing that runs as
        // linear is reported as anything else.
        if ($formula === self::FORMULA_LINEAR || $formula === self::FORMULA_DIMINISHING) {
            return [];
        }

        return [new BootAuditFinding(
            key: self::KEY_WEIGHT_FORMULA,
            warning: sprintf(
                'swrr.default_weight_formula is "%s", which is not one of the two formulas this package has ("%s", "%s"), so every replica is weighted linearly.',
                $formula,
                self::FORMULA_LINEAR,
                self::FORMULA_DIMINISHING,
            ),
            resolution: 'swrr.default_weight_formula names a formula that exists again, so the earlier warning no longer applies.',
            context: ['configured' => $formula, 'effective' => self::FORMULA_LINEAR],
        )];
    }

    /**
     * `swrr.allow_local_fallback` read as the switch it is — on, off, or a value to refuse.
     *
     * The value is handed over as written rather than already narrowed, because narrowing is
     * the mistake this exists to stop: `ConfigValue::bool()` would read `'false'`, `'off'` and
     * `'no'` as on, and `'maybe'` as on too, each without a word. Support\SwitchValue owns the
     * reading instead, and a value that is not on or off resolves to the documented default
     * with the refusal reported — so the manager still gets a bool and nothing silently
     * inverts.
     *
     * Public because `db:doctor` reports this switch from the same answer the boot audit and
     * the manager use. Three readers of one rule is the point: a row that re-derived it could
     * fail a switch the routing happily obeys. Takes the raw value rather than the whole
     * `swrr` block because the manager resolves config through a keyed closure, and one
     * signature has to serve both callers.
     *
     * @return array{on: bool, refused: string|null}
     */
    public static function allowLocalFallback(mixed $value): array
    {
        return SwitchValue::read($value, self::FALLBACK_DEFAULT);
    }

    /**
     * The on/off settings whose written value the package cannot read, one finding each.
     *
     * A switch is the one setting a cast cannot be trusted with. `(bool) 'false'` is true, so
     * a typo or a spelling this package does not list used to become a decision nobody was
     * told about — and on `swrr.pgcat.enabled` that decision can arm a file swap. Every value
     * that is not on or off is therefore refused at `error` level, beside the reader-window
     * refusals and for the same reason: the setting is input the package will not interpret
     * on the operator's behalf.
     *
     * The flipper is asked rather than the config block re-read, so the two pgcat switches are
     * classified exactly where they are used. All three are reported when all three hold —
     * three typos are three repairs, and one finding per setting is what lets the record date
     * them separately.
     *
     * @param array<string, mixed> $swrr
     * @return list<BootAuditFinding>
     */
    private function switchFindings(array $swrr, PgcatConfigFlipper $flipper): array
    {
        $refused = $flipper->refusedSwitches();
        $fallback = self::allowLocalFallback($swrr['allow_local_fallback'] ?? null);

        if ($fallback['refused'] !== null) {
            $refused['swrr.allow_local_fallback'] = $fallback['refused'];
        }

        $findings = [];

        foreach (self::SWITCHES as $setting => $switch) {
            if (!isset($refused[$setting])) {
                continue;
            }

            $findings[] = new BootAuditFinding(
                key: $switch['key'],
                warning: SwitchValue::describeRefused([$setting => $refused[$setting]])
                    .'. Refused: '.SwitchValue::ACCEPTED.'. '.$switch['consequence'],
                resolution: sprintf(
                    '%s reads as on or off again — one of the spellings it accepts — so the value is no longer refused.',
                    $setting,
                ),
                context: ['setting' => $setting, 'configured' => $refused[$setting]],
                level: 'error',
            );
        }

        return $findings;
    }

    /**
     * The store the `db` singleton will run, and what the setting says it should be,
     * read exactly the way that closure reads it — so the audit cannot disagree with
     * the store that actually serves reads.
     *
     * Public because `db:doctor` asks the same question: which store is effective is
     * what decides whether a store probe has anything to probe.
     *
     * @param array<string, mixed> $swrr
     * @return array{configured: string, effective: string}
     */
    public static function storeSelection(array $swrr): array
    {
        $configured = ConfigValue::string($swrr['primary_store'] ?? null, 'redis');

        return [
            'configured' => $configured,
            'effective' => $configured === 'local' ? 'local' : 'redis',
        ];
    }

    /**
     * The resolver the manager uses, for questions about the configured windows.
     */
    private function timeWindowResolver(): TimeWindowResolver
    {
        return $this->app->make(TimeWindowResolver::class);
    }

    /**
     * The `db.factory` binding, asserted to be the weighted factory. Weighted
     * routing only happens inside the factory, so a host app that replaces the
     * binding has to be told rather than silently served unweighted reads.
     */
    private static function weightedFactory(Application $app): WeightedConnectionFactory
    {
        $factory = $app->make('db.factory');

        if (!$factory instanceof WeightedConnectionFactory) {
            throw new \RuntimeException(
                'WeightedDatabaseServiceProvider requires the [db.factory] binding to be a '
                .WeightedConnectionFactory::class.'.',
            );
        }

        return $factory;
    }

    // -------------------------------------------------------------------------
    // Config normalisation
    // -------------------------------------------------------------------------

    /**
     * The windows the resolver will be built with. Entries that are not windows are
     * left out — the resolver must keep working — but they are not forgotten: they
     * come back as a rejection from the same classifier, and `readerFallbackFindings()`
     * reports them at error level. See Support\ReaderWindows.
     *
     * @return list<array{start?: string, end?: string}>
     */
    private static function readerWindows(mixed $value): array
    {
        return ReaderWindows::split($value)['usable'];
    }

    /**
     * Normalise `swrr.reader_days` into a list of ISO-8601 day numbers
     * (1=Mon … 7=Sun). Numeric strings are accepted alongside ints, and a bare
     * scalar is treated as that single day — `(array) '3'` used to yield ['3'],
     * which never matched the resolver's strict int comparison and silently
     * pushed every read to the writer.
     *
     * @return list<int>
     */
    private static function readerDays(mixed $value): array
    {
        return ReaderDays::normalise($value);
    }
}

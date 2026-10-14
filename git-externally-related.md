# The LPR repository's last eight days, and what in it bears on this package

A commit-level reading of the consuming application's repository, from a clone, over the last
eight days — which commits touch the database path this package owns, what those files do, and
what porting them here would mean.

## 0. How this was read

* **The window** is 2026-10-07 → 2026-10-14 inclusive, taken as `git log --all --since='8 days
  ago'` evaluated on 2026-10-15: **39 commits**, all by one author, on one branch.
* **The source** is a bare, blob-filtered, shallow-by-date clone of
  `git@gitlab.com:lpr802309/laravel.git`, read over https (the SSH endpoint on this machine
  answers `Permission denied (publickey)` for this account, and the repository is readable
  anonymously), taken into `lpr-git/laravel.git` at this package's root and covered by a line in
  `.gitignore`. It is scratch: the document below is the deliverable, the clone is the evidence
  behind it. **Nothing outside this package's root was read, listed or written**, and the sibling
  application checkout on this disk was never opened — the clone is the copy, as `AGENTS.md`
  section 0 requires a fact from outside to be brought in deliberately.
* **The branch map matters.** This repository holds several unrelated projects on separate
  branches (`main` and `staging` sit at a 2025-02-24 initial commit; `wordpress-01`, `cms-1`,
  `pytools-01`, `go-wss`, `analysis-01`, `data-import-1` are other work). Everything of interest
  is on `changes-by-umar`, whose tip is 2026-10-14. Its **root commit is `0e74e0e`** (2026-10-07
  15:42, "Retune the production image's worker and buffer limits") which *adds 2 889 files at
  once* — a whole-tree import, not a change. Every file in the tree therefore reports that commit
  as its last modification unless it moved after, and that commit is not counted as related work
  anywhere below.
* **What is not quoted.** Nothing here reproduces a line from a file that names a machine — the
  AWS command transcripts, the local git config and the machine-local records all carry this
  workspace's own path, drive letter and all, and a quoted line would import that into a carried
  file. What those files *do* is described instead. No path in this document is a location on
  this disk.
* **One caveat about the clone.** It holds whatever the repository holds, blob for blob. The
  tracked environment files are `.env.example`, `.env.prod.encrypted`, `.env.sqlite-live` and
  `.env.testing.example` — the production one is encrypted and no plaintext `.env.prod` is
  tracked, so nothing here is a plaintext credential. Delete `lpr-git/` (and the `.gitignore`
  line that covers it) when this reading is finished with.

## 1. The five ways a commit there bears on this package

| | Relation | What it means | Weight |
|---|---|---|---|
| **A** | **It pins or configures this package** | `composer.json`, and the application's published `config/db-manager.php` | a divergence is a bug on one side |
| **B** | **It calls this package** | a schedule, a deploy gate or a script that runs `db:pgcat-flip`, `db:doctor`, `db:pgcat-window-flip` or reads `/health/db` | the strongest: a change here breaks production |
| **C** | **It is evidence about this package** | a production warning, a deploy log, or a record of a failure this package's own code produced | the only external reading of this package's behaviour there is |
| **D** | **It does this package's job at the app layer** | a retry wrapper, a routing rule, a diagnostics command that exists because this package does not do it | the port candidates |
| **E** | **It is the environment this package runs in** | the pooler, its three rendered variants, the RDS parameter groups, the credential path, the image | context a port has to respect |

## 2. The related commits

23 of the 39 touch something in section 3. Tier 1 is a change to how this package is configured or
called, or a production failure of it; tier 2 is adjacent work that reads the path this package
routes, or a guard of the same class.

### Tier 1 — this package's own configuration, callers, and failures

| Commit | Date | Subject | Relation | What it does to this package |
|---|---|---|---|---|
| `89a76bc` | 2026-10-12 | Bump the weighted-dbmanager to alpha3 and arm its pool probe | A, B | moves the constraint `v0.2.0-alpha2` → `v0.2.0-alpha3`, relocks (reference `62b22644` → `33afd4e8`, content-hash `30b48bb8` → `c4f19d49`), and gains the `pool_probe` block that turns the second self-heal **on** |
| `cc25625` | 2026-10-07 | Move the weighted-dbmanager constraint to alpha2 | A | `v0.2.0-alpha1` → `v0.2.0-alpha2`, relock only |
| `8bd2f9d` | 2026-10-09 | Default pgcat's flip schedule and boundary window to on, and widen the cadence to two minutes | A | three defaults in the published config: `schedule.enabled` unset → `true`, `interval_minutes` 1 → **2**, `windows.enabled` false → **true**; the commit names three contradictions between that file's comments and its code and leaves the comments alone |
| `e03b1ec` | 2026-10-08 | Move the weighted replica manager's state out of the temp directory into a provisioned `/var/swrr` | A, E | the four paths this package writes move: `state_file`, `lock_file` (renamed `pgcat-flip-state.json.lock`), `boot_file`, `audit.file`; `audit.store_probe_seconds` 60 → **120**; `entrypoint.sh` gains `setup_dbmgr_swrr` to provision them |
| `300d676` | 2026-10-09 | Let the pgcat flip actually run: re-enable `proc_open` and make the pooler configs writable | C, E | a real deploy's failure: the flip rehearsal died on `proc_open` in `disable_functions`, and the container logged `[WeightedDB] flip target [/etc/pgcat/pgcat.toml] is not writable` and `the record at /var/swrr/swrr-audit.json cannot be written, and it is what throttles the probe, so the PING is skipped` |
| `352f267` | 2026-10-08 | Run the per-minute scheduler as `www-data`, so what it writes is owned by the user running the app | B, E | the entry that runs `db:pgcat-flip` moves to the application's own user; `APP_ENV=prod` moves *inside* the `runuser` call, because unset it made `withoutOverlapping`'s mutex resolve to SQLite and abort `schedule:run` before the flip ran |
| `dce1a01` | 2026-10-09 | Retire the dead reader pools and renumber the live one on both sides | A, E | `pgcat.toml`'s `reader_db_2` → `reader_db_1`, dead blocks deleted; the `read` array follows; weights `writer_db_1` 13.0 → 1.0 and the reader 21.0 → 10.0 |
| `faac421` | 2026-10-09 | Take `reader_db_3` out of the read path on both sides, and rebalance the writer's weight | A, E | the pool and its `read` entry retired together; `writer_db_1` 10.0 in the same commit |
| `3665a26` | 2026-10-13 | Re-run a read a standby cancelled instead of handing the caller a 500 | D | `ConcurrencyReadRetry` + `database-retry.php` wrap every `SELECT`; **weights land here** — `writer_db_1` 2.5 → 145.0 and `reader_db_1` 10.0 → 500.0 |
| `1271d96` | 2026-10-13 | Answer the manifest and notification lists from the writer pool, behind a config flag | D | `HeavyReadRouting` + `BaseRepository::$routeReadsToWriter`, gated by `database.route_heavy_reads_to_writer` (env `DB_ROUTE_HEAVY_READS_TO_WRITER`, on by default) |
| `0c490fe` | 2026-10-14 | Write down which of the replica's two cancellations is which, with the recordings it derives from | C, E | the read path's two failures named, with the RDS recordings committed under `aws/data/rds/` and `docs/replica-conflict-errors.md` (nine sections plus a re-derivation section) |
| `6badd23` | 2026-10-14 | Make a recovery conflict report itself instead of waiting to be queried by hand | C, E | `install-log-alarm.sh`: two log metric filters on the application's log group and one alarm, because the application-visible consequence of a replica problem had no metric at all |

### Tier 2 — adjacent: the reads this package routes, and guards of the same class

| Commit | Date | Subject | Relation | Why it is here |
|---|---|---|---|---|
| `00e1bc4` | 2026-10-14 | Name the request that climbs a php-fpm worker to `memory_limit` | D | the app's own instrument-instead-of-guess posture (a shutdown function writing one greppable line), on a different failure |
| `04ba6ca` | 2026-10-07 | Read the manifest listing from the unified view | D | the read the heavy-routing pin exists for |
| `6c26699` | 2026-10-08 | Paginate the manifest against a precomputed count column instead of counting rows | D | the `COUNT(*)` that made the read heavy enough to be pinned to the writer |
| `f6637cb` | 2026-10-08 | Stop casting two `jsonb` columns to `jsonb` in the manifest's raw SQL | D | the same query's other cost |
| `3d8240c` | 2026-10-13 | Read the manifest page count out of the row instead of handing over the row | D | the regression the override above caused, and its 500s |
| `8015f67` | 2026-10-09 | Detect the driver-vehicle relationship change explicitly, and refresh views with a deferred event | D | materialized-view refresh — the write side of the views those reads scan |
| `23061be` | 2026-10-08 | Harden the PHP runtime and the FPM pool, and record the one command this breaks | E | where `disable_functions` came from — the image-wide setting that later stopped the flip |
| `94b251a` | 2026-10-12 | Every session reads the behavioural rules from one file in the tree, not from a prompt | E | the app's rule-file consolidation and `.agents/verify.py`; the opposite decision from this package's, which has no generator |
| `e00a18b` | 2026-10-12 | Let a `path:line` entry excuse a file whose name contains a space | E | a guard-class bug in the app's secret-scan allowlist — the same family as this package's prose-path readers |
| `eb3d269` | 2026-10-08 | Commit the `machine.local.json` sample in token form, so a new clone has a starting point | E | the app commits a token-form sample of its machine record, including `__PACKAGE_DIR__` for *this* package's checkout |
| `da682d0` | 2026-10-12 | Update database configuration and add `orders-students` support | E | on the `lambda-func-01` branch: the Go function that refreshes the same database's materialized views for orders/students, and the Lambda `ClientOrdersCrudEventListener` invokes |

### The other 15, and why they are not related

Image and build tuning (`a5a36cc` rlimit, `001fcdf` OPcache, `8de709f` framework cache,
`0e74e0e` worker/buffer limits, `745c43a` the Postman manifest in the `Dockerfile`), the certificate
and dev-environment work (`52e98b1`, `d03d26b`, `6dd5005`), the Postman suite and its guard
(`aa91ce7`), the push/commit tooling (`ce626b8`, `405de47`, `f9511fb`), and application behaviour
with no database path in it (`79efc08`, `f78dc1f`, `99eb014`, `298f88d`). None of them touches
`config/db-manager.php`, the pooler configuration, the scheduler entry, the read path or the deploy
gates that read this package.

## 3. The related files, by relation

Paths are the repository's own, as they are in the clone. "Verdict" is what this document proposes.

### 3.1 It pins or configures this package — relation A

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `site/composer.json`, `site/composer.lock` | the requirement `"uak35/laravel-weighted-dbmanager": "v0.2.0-alpha3"` | version pinning, with the lock moved in the same commit because the app's own `composer_lock.py` refuses a manifest whose lock does not describe it | the version the application actually runs on, and the fact that a *pin* is how it learns about this package | read |
| `site/config/db-manager.php` | the application's **published copy** of this package's sample config, 18.9 KB, comments included | configuration, and the record of three decisions this package's own comments contradict | it is the input to everything this package does at boot, and it has diverged from the sample in thirteen keyed lines (section 4.1) | app + port |
| `docker-files/production/laravel/entrypoint.sh` | container start: renders three pooler configurations, prunes unresolvable pools from all three, stamps the boot, `config:cache`, and `setup_dbmgr_swrr` which provisions `/var/swrr` and the pooler files | provisioning, and the other half of this package's file contract | it creates exactly the files this package writes — `pgcat-flip-state.json`, `pgcat-flip-state.json.lock`, `container-booted-at`, `swrr-audit.json`, `swrr-audit.json.lock` — as `www-data` mode `0664`/`0770`, and widens the three `/etc/pgcat` variants to `g+w,o+w` | docs + port |
| `site/app/Helpers/SchedulingHelpers.php` | the application's schedule; `setupPgcatFlipSchedule()` registers `db:pgcat-flip` `->everyMinute()->withoutOverlapping(2)->createMutexNameUsing(…gethostname()…)->name('pgcat_flip:<host>')  ->appendOutputTo(...)`, inside a schedule that calls `useCache('file')` | scheduling, with 60 KB of docblocks recording what went wrong before | it is the *other* registration of this package's command — the duplicate guard is the only thing keeping two entries from becoming two runs — and its mutex store is `file`, not the SQLite store that once aborted the run | read + docs |
| `docker-files/production/laravel/lpr-schedules` | the minutely cron entry, live lines: `runuser -u www-data -- env APP_ENV=prod php … artisan schedule:run` | cron, with the comment block that records why `APP_ENV` must be inside `runuser` | the entry that has to reach this package's command for the flip to happen at all | docs |

### 3.2 It calls this package — relation B

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `docker-files/production/laravel/flip-rehearsal.sh` | the deploy's 6th test: runs `artisan db:pgcat-flip --dry-run --json` in the candidate and fails unless a JSON report declares one of three verdicts | a deploy gate over this package's own interface | the shape `--dry-run --json` has to keep; it also detects an image whose `db:pgcat-flip` is undefined and says so ("this image predates the flip") | read |
| `docker-files/production/laravel/flip-rehearsal-selftest.sh` | 11 fixture cases over that gate — every verdict, non-JSON output, a missing command, a missing app root | fixtures written outside this package for this package's contract | a ready-made set of edge cases for `--dry-run`'s report | docs |
| `docker-files/production/laravel/pgcat-health-gate.sh` | the deploy's 5th test: supervisor state for the pooler, a TCP accept on `127.0.0.1:5433`, and one real query through the application's pinned connection (`artisan db:connection-diagnostics --real-test`) | a deploy gate that asks the pooler rather than the container | it is the pooler half of what this package's flipper and health endpoint report, and it names the option (`--real-test`) it needs from the app's command | read |
| `docker-files/production/laravel/pooler-healthcheck.sh` | the ECS container health check: supervisor state plus a real TCP connect on the pooler's port, `--deep` adding `select 1` | the production answer to "is the pooler up" | what this package's `pgcat supervisor` row and `flip_window` report alongside; a package-side recipe should defer to it | docs |
| `docker-files/production/aws-settings/deploy-ecs.sh` | the deploy channel: register, gate (tests 3–8), roll back; its comments cite this package's dry run and `/health-db` | deployment | two of its exit codes (rc 5 pooler gate, rc 6 flip rehearsal) exist because of this package's commands | read |
| `docker-files/production/laravel/smoke-test-image.sh`, `validate-image.sh` | the image gates: both run `php artisan db:connection-diagnostics` before booting anything | image validation, using the app's own diagnostics command | the place a *light* `db:doctor` would be run instead — see 3.4 | port |
| `docker-files/production/laravel/Dockerfile` | a comment records that the flip needs "the lock, the source variant, supervisor's state" to work here; `proc_open` is removed from `disable_functions` | the image this package runs inside | the hardening list is what stopped the flip once already | read |
| `scripts/php/verify-db-hosts.php` | opens a TCP listener on the configured address and makes *real* database calls to record which host, port and pool each call uses; drives this package's `TimeWindowResolver` via `$app['db']->setTimeWindow(...)` | a hand-run integration probe, described in `TOOL-REGISTRY.md` | it is a hand-written version of a check this package could own — and it still asserts `['writer_db_1', 'reader_db_2', 'reader_db_3']`, a pool set retired on 2026-10-09 | port |
| `scripts/php/demo-pgcat-supervisor.php` | a live check whose docblock says it demonstrates "what `db:doctor`'s new `pgcat supervisor` row says"; it calls `$kernel->call('db:doctor', ['connection' => 'pgsql_proxy'], $buffer)` | a demonstration script built around this package's doctor output | an external consumer of the doctor's *row*, which is the interface a row change here breaks | read |
| `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php` | `db:connection-diagnostics [--json] [--strict] [--real-test]`: the environment file this boot read, whether config is cached, `swrr.connection` and the setting it came from, per-connection write/read endpoints, the pgcat switch "as written, as cast, and as the package reads it", and a verdict whose exit is 1 on states the armed configuration cannot work in | application diagnostics | a parallel of this package's `db:doctor` that exists for one stated reason — "`db:doctor` … also probes the store and asks supervisorctl, so it cannot run everywhere an image is built" | port |

### 3.3 It is evidence about this package — relation C

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `docs/replica-conflict-errors.md` | the read path's two cancellations, their topology, both parameter groups, the change history, the measured counts and the traps — nine sections, a "what to change, cheapest first", an open-questions section and a re-derivation section that names the query behind each figure | a measurement record | it is the external account of the path this package routes to: two pools at weights 145.0 and 500.0 on `127.0.0.1:5433`; and its headline result is about this package's read choice, not the app's code | docs |
| `aws/data/rds/param-groups/timeoutgroup2/parameters.json` | the writer's group: `statement_timeout` **120000** ms | a captured parameter group | the longer of the two caps, and what makes an escalation to the writer able to answer a read the replica cut | port |
| `aws/data/rds/param-groups/timeoutgroup2-replica/parameters.json` | the replica's group: `statement_timeout` **90000**, `hot_standby_feedback` **1** (changed 2026-10-13), `max_standby_streaming_delay` 300000, `max_connections` 3470 | a captured parameter group, re-fetched because the previous copy was stale (it recorded 2400000 where the live value was 120000) | the tighter cap is the replica's, and `hot_standby_feedback` is the switch that decides whether this package's read picks get cancelled | port |
| `aws/data/rds/lpr-database-4c-r7/{details,cancellation-evidence,hot-standby-feedback-check}.json` | the replica's identity and class, the 30-/7-day cancellation transcripts, and the windows either side of the feedback change | recordings every figure in the record cites | the endpoint this package's read picks reach, and the measurement that the conflict class ran at ~422 failed requests an hour until the parameter landed | read |
| `aws/data/rds/param-group-changes.json`, `aws/data/rds/lpr-database-4c-r7/**` | the change history and the fleet | recordings | the same | read |
| `docker-files/production/aws-settings/install-log-alarm.sh` | installs two log metric filters on the application's log group — `lpr-backend-conflict-with-recovery` → `LPR/Database RecoveryConflicts` and `lpr-backend-statement-timeout` → `StatementTimeouts` — and one alarm (`>= 1` in 300 s, `notBreaching`), with `--verify-only`; it proves its own patterns with `aws logs test-metric-filter` | alerting, for a failure of the read path | the alarm this package's routing decisions can set off; the phrase is preferred to `40001` because `40001` also names a real serialization failure on the primary | docs |
| `docker-files/production/aws-settings/README.md` | the deploy/health-gate family's own README, including why `/health-db` must not be derived from a cached route | operations documentation | states the surface this package publishes (`/health-db`, 200/`status=ok`) and the trap it exists to avoid | docs |
| `docs/db-connection-resolution.md`, `docs/TODO-database-tests.md` (named by these commits' context) | the connection-resolution record, and the app's database-test failures itemised, item 2 being "8 tests read through pgcat and no pooler listens on 5433" | a measurement record and a defect list | the same problem this package's own suite solves with a fixture pooler, and the app's own probe command's reason for existing | read |

### 3.4 It does this package's job at the app layer — relation D

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `site/app/Database/ConcurrencyReadRetry.php` + `site/config/database-retry.php` | re-runs a `SELECT` a standby cancelled (40001) and sends a statement timeout (57014) to the write connection; `DB_READ_RETRY_ENABLED` (on), `attempts` 3, `base_delay_ms` 25, `fallback_to_primary`; only outside a transaction, because a cancelled statement inside one leaves it aborted | application resilience | this package chooses the endpoint a read lands on; the application has had to add the recovery layer for what that endpoint does. The port is the *knowledge* (a record and a probe), not the retry | docs + port (staged) |
| `site/vendorOverrides/Illuminate/Database/Enhanced/Postgresql/PostgresEnhancedConnection.php` | the app's override of the enhanced connection's `select()`, calling the retry | an in-place override (the framework's `vendor/` untouched) | every read funnels through `Connection::select()`; a package change that pins or rewrites the query builder has to survive this layer | read |
| `site/app/Database/HeavyReadRouting.php`, `site/app/Repository/Base/Implementations/BaseRepository.php` | `$routeReadsToWriter` (false everywhere, true on four view repositories) and the flag reader; the pin is Laravel's own `useWritePdo()`, read per query from `database.route_heavy_reads_to_writer` | routing policy for two heavy reads | its own docblock states the invariant this package should verify: a connection honours `useWritePdo()` **only where it has a `read`/`write` pair** — which is a fact about the connection this package builds | port |
| `site/app/Services/Route/ManifestViewService.php`, `site/app/Repository/Implementations/View*Repository.php`, `site/Schemas/Base/ViewManifest.php` | the manifest and notification lists: scans of `view_manifest` / `view_manifest_new`, PHP-side pagination (`BaseService::customPaginate()`), a precomputed count column and two `jsonb` comparisons | query work on the heaviest reads | the reads whose cost makes the weighted pick matter, and the reason two of them were pinned to the writer | read |
| `site/app/Helpers/MemoryProbe.php`, `site/app/Http/Middleware/API/LprHandleCors.php` | `MEMPROBE` — one `error_log` line per request whose peak reaches a 64 MB floor, armed from a shutdown function so a fatal is still named | runtime instrumentation | not this package's business, but the same posture as its boot audit: name the failing thing in a line a log filter can find | read |
| `site/app/Repository/Implementations/LprNotificationRepository.php`, `site/Repository/...` | the notification list, opted into the writer pin | query work | the third caller of the pin | read |

### 3.5 It is the environment this package runs in — relation E

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `docker-files/production/laravel/pgcat.toml`, `pgcat-no-readers.toml` | the pooler's pool template (one `[pools.<name>.users.0]` per database, passwords spliced at container start) and the writer-only variant | pooler configuration | the files this package's flipper swaps between; the pools they declare are the `database` values in the app's `read` array | port |
| `docker-files/production/laravel/entrypoint.sh` → rendering | renders `pgcat.toml` **and** `pgcat-readers.toml` from the *same* template, `pgcat-no-readers.toml` from the other, then prunes unresolvable pools from all three | provisioning | this package names three paths; the relationship between them (one pair identical, one pair not) is only in the app's renderer | port |
| `docker-files/production/laravel/supervisor.d/pgcat.conf` + the `supervisorctl restart "pgcat:*"` / `signal HUP` / `start` commands in the config | the `[program:pgcat]` block and the commands this package's flipper runs | supervision | the program name the flipper addresses, and the reason a "not running" state needs a `start` distinct from a `restart` | read |
| `docker-files/production/laravel/php/php.ini`, `php-fpm-www.conf` | the hardened runtime: `disable_functions` gained `exec, shell_exec, system, passthru, proc_open, popen, show_source`; the FPM socket's owner/mode, `rlimit_files`, `decorate_workers_output` | image hardening | the image-wide list is what made the flip impossible until `proc_open` was removed from it (300d676) | docs + port |
| `aws/data/ecs/task-defs/lpr-backend-sites-tdef-01/{details,revisions-list}.json` | the task definition and its revisions, including the health check and the `secrets` block | the task this package runs in | shows how the pooler health check reaches the container and how a database credential arrives | read |
| `site/config/database.php` | `pgsql_proxy` with `write` = one `writer_db_1` entry and `read` = `writer_db_1` weight **145.0**, `reader_db_1` weight **500.0**, both `127.0.0.1:5433`, `sticky => true`, `application_name` `lprSite`, options resolved through `AutoWireCallbacks` | the connection this package weights | the input to the pick: a pick between *pools*, which pgcat then routes | read |
| `site/vendorOverrides/Illuminate/Database/Connectors/PostgresConnector.php`, `.../Enhanced/Postgresql/**` | the app's connector override and enhancement layer | framework overrides | every connection this package builds goes through a connector the app has replaced | port (a doctor row naming the connector in use) |
| `docker-files/production/laravel/get-cached-db-token.sh` | an RDS IAM auth token read out of Redis for the connection's options | credentials | the token has a lifetime and is resolved at boot; a report on the connection must not treat it as a password | read |
| `.postman/**`, `postman/**`, `aws/data/elbv2/**`, `aws/data/codebuild/**`, `.idea/**`, `.trae/**`, `site/.agents/**` | the imported API suite (890 files), load-balancer and build captures, editor and agent configuration | unrelated bulk | none of it touches this package | skip |

## 4. The divergences, each with the command that shows it

### 4.1 The application's published config against this package's sample

```
git -C lpr-git/laravel.git show changes-by-umar:site/config/db-manager.php > lpr-git/app-db-manager.php
diff <(grep -E "=>" lpr-git/app-db-manager.php) <(grep -E "=>" config/db-manager.php)
```

Thirteen keyed lines differ. Seven are the app's installation decisions, six are defects or gaps:

| Key | The application's copy | This package's sample | Reading |
|---|---|---|---|
| `pgcat.enabled` | `(bool) env('SWRR_PGCAT_ENABLED', true)` | `env('SWRR_PGCAT_ENABLED', false)` | a `(bool)` cast in front of the switch this package reads as a switch: `'off'` becomes `true` before the package sees it, which is the defect `Support/SwitchValue.php` exists for — and the flipper is **armed by default** there |
| `swrr.allow_local_fallback` | `(bool) env(…)` | uncast | the same defect, one key over |
| `swrr.connection` | `env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'sqlite'))` | `env('SWRR_CONNECTION')` | left unset here the package follows `database.default`; the app's copy resolves to the literal connection name `sqlite` when neither variable is set, so the package's surfaces judge a SQLite connection while every real query runs on `pgsql_proxy` |
| `schedule.enabled` | `true` (literal default) | `false` | the app has the per-minute schedule armed by default |
| `schedule.interval_minutes` | `2` | `1` | same command, wider cadence, against a comment that still says 1 is the shipped value |
| `schedule.windows.enabled` | `true` | `false` | **the contradiction**: the app's copy has both the schedule *and* the window tasks armed, while the comment eight lines above says "LEFT OFF, AND DELIBERATELY SO. Turn this on and turn `enabled` above off in the same change" |
| `schedule.stale_after_missed_runs` | absent | present (5) | the key is in this tree and not in `HEAD` — unreleased, so the app cannot have it; it arrives with the next publish |
| `pool_probe.enabled` | `true` | `false` | the probe is armed there (that is what alpha3 was for) |
| `pool_probe.port` | `5432` | `6432` | the probe is pointed at PostgreSQL's own port rather than the pooler's; the package's `PoolProbe` limitation note is about exactly this |
| `pgcat.state_file`, `lock_file`, `boot_file` | `/var/swrr/…` (with the temp spellings commented above) | `sys_get_temp_dir()` | an installation decision, and a good one — it matches what `entrypoint.sh` provisions, down to the lock's `…state.json.lock` name |
| `audit.file` | `/var/swrr/swrr-audit.json` | temp | the same |
| `audit.store_probe_seconds` | `120` | `60` | half as many store PINGs as the shipped default, which is the one setting that keeps the audit off the request path |

### 4.2 A production container that was healthy and could not flip

`300d676` quotes the deploy's own output: the promotion gate failed at reason 6 with
`supervisorctl status "pgcat:*" did not answer in time: The Process class relies on proc_open,
which is not available on your PHP installation`, and the report's own verdict was
`rename refused never reached`. The other four gates passed — rollout `COMPLETED`, task `HEALTHY`,
db-health `OK`, and the **pooler gate `GATE PASSED`** (pgcat RUNNING, `127.0.0.1:5433` accepting, a
real query answering). The container served traffic correctly; what it could not do was *change*
the pooler's configuration. Two of this package's own warnings were in that container's log:
`flip target [/etc/pgcat/pgcat.toml] is not writable, so a flip may not get its copy into place`
and `the record at /var/swrr/swrr-audit.json cannot be written, and it is what throttles the
probe, so the PING is skipped`.

This package already *has* both readings — `PgcatConfigFlipper::FILE_UNWRITABLE` is judged during a
flip attempt, and `DbDoctor` reports the audit record's writability — but they are taken at the
moment of failure, one path at a time, and the supervisor step reports a spawn failure as a
timeout. Nothing reports, *before* a deploy rehearses a flip, that this installation cannot write
the files a flip needs. `grep -rn "proc_open" src/` finds nothing here: the disabled function is
not a cause this package can currently name.

### 4.3 The read path's caps differ on purpose, and the tighter one is the replica's

`aws/data/rds/param-groups/timeoutgroup2/parameters.json` says `statement_timeout` 120000;
`…/timeoutgroup2-replica/parameters.json` says 90000 with `hot_standby_feedback` now 1. So the two
endpoints this package picks between are capped differently, which is the whole reason the
application's `fallback_to_primary` can help at all: a read cut at 90 s on the replica still has
30 s of headroom on the writer. The replica carried **no** `hot_standby_feedback` until
2026-10-13, and while it did not, the conflict class ran at about 422 failed requests an hour
(`0c490fe`: 1 618 failures in 3 h 50 m over 6 472 matching log lines); it stopped within a minute
of the parameter being applied and stayed at zero for four hours while traffic continued, and a
much smaller statement-timeout class appeared in its place (`6badd23`: 4 996 matching events in the
hour before the update, 0 in the 102 minutes after, 28 timeout lines after). A standby with that
parameter off is the thing that cancels reads this package's weights sent there, and nothing in
this package asks about it.

### 4.4 Two files are one declaration, and the pair has already disagreed

`faac421` and `dce1a01` are both about the same fact stated twice: `pgcat.toml` declares pools,
`site/config/database.php`'s `read` array names them in its `database` field, and "a rename on one
side without the other leaves reads routed to a pool that does not exist — a failed connection,
not a silent one". It happened on 2026-10-09: `pgcat.toml` at 03:06 and the connection at 03:11.
`dce1a01` also records that `scripts/php/verify-db-hosts.php` line 142 still asserts
`['writer_db_1', 'reader_db_2', 'reader_db_3']` and that `pool-prune-selftest.sh` asserts the same
retired names — both run by hand, neither in CI. So the app has a manual check of this invariant
that is now wrong, and this package has a probe that asks the pooler about the pools a *variant*
declares but not about the pools the *connection* declares.

## 5. The port plan

Seven phases, ordered by what goes wrong if it is not done first, plus four small items. Each phase
names the evidence, what "done" means, and the check that proves it. Nothing here requires the
sibling checkout: the clone is the source.

### Phase 1 — one preflight for everything this package must write

*Evidence:* 4.2. *Today:* the flipper judges writability one path at a time, during an attempt;
the audit record's writability is a doctor row of its own.

Add a single reading — a `db:doctor` row and one boot-audit finding — that lists every path this
package must be able to write while the flipper is armed: `config_path`, `readers_path`,
`no_readers_path`, `state_file`, `lock_file`, `boot_file`, `audit.file` and the record's `.lock`
companion, each with the mode and owner found, and each with the sentence that says what an
unwritable one costs (a flip that cannot get its copy into place; a probe that is skipped because
its throttle cannot be written). Aggregate; do not add an eighth thing that reports only when it
fails. Reuse the flipper's existing constants so a path judged unwritable there is judged
unwritable here.

*Done when:* a fixture tree with a read-only variant reports each path, the mode found and what it
costs, in one row; a writable tree is silent; and the row is present in `db:doctor --json`.
*Check:* the doctor and flipper suites, then `bin/checks.php --only=counts,sentences,pint,phpstan`.

### Phase 2 — a supervisor failure names its cause, not a timeout

*Evidence:* 4.2's first half. The package's supervisor step reports "did not answer in time" for a
command that could never be spawned.

Distinguish three states where there is one message today: the process cannot be spawned at all
because `proc_open` is disabled (report `ini_get('disable_functions')` and the ini line to change),
the binary is not on `PATH`, and a genuine timeout. The same reading belongs in `db:doctor`, so an
image build can catch it before a deploy's rehearsal does — a container with this defect passes
every other gate, which is what made it a rollback rather than a failed build.

*Done when:* the supervisor step's failure sentence says which of the three it is, and the doctor
reports the same without running a flip.
*Check:* the supervisor/flip suites plus the doctor suite; one new test arming a disabled-function
stub.

### Phase 3 — the one-mechanism invariant, checked

*Evidence:* 4.1 — the application has `schedule.enabled` **and** `schedule.windows.enabled` armed,
against this package's own comment that says not to.

Add an audit finding and a doctor row for two mechanisms owning one mode: name both keys, say what
they share (the boot convergence record) and leave it a warning, because an installation may be
mid-migration between them. While that file is open, settle the two defaults the app's copy exposes:
whether `schedule.enabled` still inherits from `pgcat.enabled`, and whether an unparseable
`interval_minutes` resolving to 1 while the default is 2 is intended — the comments in the sample
promise one behaviour and the code has another.

*Done when:* an installation with both armed is reported by name; a clean one is silent; the
config's comments and its code agree on the cadence and the inheritance.
*Check:* doctor, audit and config suites.

### Phase 4 — the published config can say how old it is

*Evidence:* 4.1 — the pin is `v0.2.0-alpha3`, the app's copy lacks `stale_after_missed_runs`, and
its three `(bool)`/fallback lines are the application's to remove but the package's to *notice*.

Add a schema generation to the sample (a `config_version` owned by the class that reads it) and a
doctor row comparing the published file's value with the package's: equal is silent, older is a
warning naming the release, absent is a warning saying the file predates the key. Then a short
`docs/` note listing what a publisher must not do to the keys the package reads as switches — the
three lines in 4.1 are the worked example.

*Done when:* a fresh install is silent, an older published copy is reported by name, and the
published sample reads as current.
*Check:* doctor suite, `bin/counts.php --check`, `tests/Unit/Docs`.

### Phase 5 — the read path: what a routed read can expect

*Evidence:* 4.3 and 3.4. This package picks between a writer capped at 120 s and a replica capped
at 90 s; the replica cancelled reads at ~422 an hour until `hot_standby_feedback` was turned on;
the application answers both failures in a wrapper (`DB_READ_RETRY_ENABLED`, 3 attempts, 25 ms
base, `fallback_to_primary`) and pre-empts them for two reads through `useWritePdo()`.

Two doctor rows — each read endpoint's own `statement_timeout` (unequal caps reported as a fact:
"a read cut here can be answered by the write endpoint, whose cap is the longer one") and whether
a standby has `hot_standby_feedback` on — plus a row that says whether the pinned connection has a
`read`/`write` pair at all, since `useWritePdo()` is honoured only there. And one record under
`docs/`: the two SQLSTATEs and the sentence each carries, why a re-run is the conflict's answer
while the server's cap is not, the application's contract as it is today, and where this package
stops — it chooses the endpoint, it does not re-run a statement the application may have wrapped in
a transaction.

*Done when:* the rows report silence, not failure, where an endpoint is unreachable; the record is
registered like every other.
*Check:* doctor suite, `tests/Unit/Docs`, `bin/counts.php --check`.

### Phase 6 — the deployment contract, written down here

*Evidence:* 3.1, 3.2 and 3.5 together — the three rendered variants and prune-before-start, the
boot stamp, the `/var/swrr` provisioning, the minutely entry and its `useCache('file')` mutex
store, the two deploy tests that read this package, the container health check, `/health/db`
answering through the pooler, and the image-wide `disable_functions` that once stopped the flip.

One record under `docs/`: what an installation must provide for this package to work in a
container (writable variants and state, a boot stamp, a spawnable supervisor, an armed schedule and
its mutex store), and what a deploy gate can ask it (the two commands and their exit codes). Cite
the app's own files as the field evidence they are, by name.

*Done when:* the record is in `docs/`, registered in the counts and sentences records, and its
citations resolve.
*Check:* `tests/Unit/Docs` in full plus `bin/counts.php --check`.

### Phase 7 — pool-name agreement, in the package rather than in a script

*Evidence:* 4.4, and `scripts/php/verify-db-hosts.php` asserting a retired pool set by hand.

Extend the reading the pool probe already takes (it asks the pooler about the pools a variant
declares) to compare that set with the pools the *pinned connection* declares, at boot and in the
flip's dry run, and refuse a swap to a variant that cannot serve the connection's pools. The
package can then answer the question the app's hand-run script asks, and that script's expectations
can be retired on the application side rather than silently repaired.

*Done when:* a rehearsal against a fixture whose variant lacks a pool the connection names fails
with a sentence naming both; a consistent set passes.
*Check:* the dry-run suite and the flipper suite.

### Small items, one commit each

* **S1 — a committed machine-record sample.** `eb3d269` commits the app's sample in token form
  (`__PHP_EXE__`, `__PHP_DIR__`, `__PWSH_EXE__`, `__PACKAGE_DIR__`, `__COMPOSER_PHAR__`, `__RG_EXE__`,
  `__GIT_SIGNING_KEY__`) after its own guard found six real paths in it. This package keeps
  `.agents/machine.local.json` ignored with **no** committed sample, so a fresh clone has nothing to
  copy and `HANDOFF.md` section 5 holds its rows to a file that is not there. Commit
  `.agents/sample.machine.local.json` in token form and have `MachinePathsTest`'s excused shapes
  cover it explicitly.
* **S2 — a `db:doctor` mode an image build can run.** The app's own diagnostics command exists
  because "`db:doctor` also probes the store and asks supervisorctl, so it cannot run everywhere an
  image is built", and two image gates already run it. Add a bounded mode (`--connection-only`) that
  leaves those probes out, adds the two facts the app's command has that this one does not — **which
  environment file this boot read** and **whether the configuration is cached** — and publishes
  `swrr.connection` beside the setting it came from. That is what retires the clone instead of
  duplicating it.
* **S3 — the alarm filter names, in `docs/`.** `install-log-alarm.sh` alerts on this package's read
  path under specific names (`lpr-backend-conflict-with-recovery`, `LPR/Database RecoveryConflicts`,
  `≥ 1` per 300 s, `notBreaching`). Name them in the phase-5 record so an installation can install
  the alert rather than rediscover the pattern — and note that the phrase, not `40001`, is the
  pattern that means "a standby cancelled this".
* **S4 — a path with a space.** `e00a18b` is a guard-class bug: a `path:line` key built from git's
  `+++` header kept the tab git terminates a space-containing name with, so such a file could never
  be excused. Run the same question against this package's readers of paths in prose
  (`Handoff::paths()`, the citation readers) and add a test if the terminator is not stripped.
  `grep -rn '+++' tests/` finds nothing here today, which is suggestive rather than conclusive.

## 6. What this could not answer

* **`site/tests` was not read beyond the paths the commits name.** The two new test files
  (`ConcurrencyReadRetryTest`, `HeavyReadRoutingTest`, and the enhanced-connection one) are named in
  3.4 by their commits, not read: they exercise the application's own classes, not this package's.
* **The other branches were not searched for database work.** They are five other projects
  (`wordpress-01`, `cms-1`, `pytools-01`, `go-wss`, `analysis-01`, `data-import-1`), and their tips
  are months to a year old; `lambda-func-01` (2026-10-12) is the one that carried a database change
  (`da682d0`). If a branch outside the window matters, say which and the same reading follows for it.
* **`TOOL-REGISTRY.md` and `SCRIPTS.md` were not read.** One commit (`6badd23`) says its new script
  is *not yet* registered in the registry and that the row is owed — so the registry is a live
  document that names hand-run tools. A package-side inventory of *this* package's `bin/` could take
  its shape from it, and that is a separate reading.

## 7. What was not done, deliberately

* No file outside this package's root was read, listed or written; the sibling checkout was not
  opened. The clone is `lpr-git/laravel.git`, covered by `/lpr-git/` in `.gitignore` — a line this
  session added to a file another hand has also modified, and which should be removed with the
  directory when the reading is finished with.
* Nothing was copied into `src/`, `config/`, `tests/`, `bin/` or `docs/`. Every port item is a
  *capability* to re-express here, in this package's idiom, with this package's own tests.
* No phase was implemented, and nothing was committed. This document is the deliverable.

# What the LPR application's tree holds that bears on this package

A comparison, made from a copy, of the consuming application's database-facing material against
this package — and a plan to port what belongs here.

## 0. Provenance, and the rule this was made under

`AGENTS.md` section 0 makes this package's root the whole of what a session here may read or write,
and it names the case explicitly: no sibling checkout, and a fact that lives outside the root is
brought in *deliberately* — asked for, or copied in — never discovered by walking. So the material
this document reads was copied into `lpr-compare/` at this package's root by the user, at that
directory's explicit request, and `.gitignore` carries a line for it. Nothing outside this root was
read, listed or written: the sibling application repository was never opened, and no path in this
document is its location on this disk.

Two reading rules follow, and they are worth stating because they shape the wording below:

* **No line is quoted verbatim from a file that names this machine.** Several of the copied files
  are command transcripts that carry the workspace's own path, drive letter and all. A quoted line
  would import that into a carried file, which is what `tests/Unit/Support/MachinePathsTest.php`
  reads for, so what is reported here is *what those lines do*, with the paths described rather than
  reproduced.
* **Every path below is relative to `lpr-compare/`**, which is the shape the copy has. A leading
  `site/`, `docs/`, `aws/` or `docker-files/` therefore means the LPR repository's own directory of
  that name, not this package's `src/`-adjacent tree.

**The copy, as read.** 8,642 files, of which 3,593 are not third-party or generated. It holds
`docker-files/production` (85), `aws/data` (349), `aws/commands` (15), `docs` (44),
`site/.agents` (85), `site/bin` (5), `site/app` (1,134), `site/tests` (197) and`site/config` (38), plus the app's `database/`, `routes/`, `bootstrap/`, `resources/`, `vendorOverrides/`,
`composer.json`, its `AGENTS.md` and two plaintext environment files. It does **not** hold the
application's top-level `app/` and `config/`, `CHANGELOG.md`, `SCRIPTS.md` or `TOOL-REGISTRY.md` —
section 7 says what that costs the comparison.

**The copy was read once and then removed from this tree, deliberately.** The reason is worth
writing down, because it is a fact about this package rather than about the copy: `pint --test` is
run at the root with no path (`bin/checks.php`), so a foreign tree sitting at the root is inside the
package's style check. Measured before the folder went: pint reported 1,698 findings over this
tree, **1,697 of them files under `lpr-compare/`** and one in this package (another hand's pending
test file). The scratch folder existed to be read once and the reading is recorded here, so it and
the `/lpr-compare/` ignore rule were removed together; keeping either would have left this package's
gate red for a tree that is not this package. Nothing of the deliverable depends on the files being
here — every path in it is quoted — and the copy can be taken again with the same command as before
if a later phase needs the material, since the source is the sibling application checkout the user
named, which this file deliberately does not name.

**Two sweeps, and one thing the first missed.** The first pass searched for *this package's own
names* — `db-manager`, `weighted`, `WeightedDB`, `db:doctor`, `pgcat`, `pgbouncer` — which finds a
file that already knows about this package and misses one that merely works on the same database.
The second pass, in `bin/`-free form, ranked every file by how often it says any of `pgcat`, `swrr`,
`pgsql_proxy`, `reader_db`, `writer_db`, `statement_timeout`, `work_mem`, `health-db`, `db:doctor`,
`db:pgcat`, `replica` or `RDS`, with the package manager's directory, the coverage report and the
byte-code caches excluded. It found the pooler's own image build, the pool-pruning regression test,
a deployment analysis that names this package's published config, the CloudWatch alarm installed on
this package's read failures, the Redis the package's state store uses, the app's own bootstrap
override of config loading, the token provider behind the connection's credentials, the statistics
guard behind the read-path records, and `site/tests` — which was **not** in the copy when the first
pass was made. Everything the second pass added is in sections 3 and 4; a session repeating this
should use the second pass's token set, not the first's.

> **Before anything else: three paths in the copy are production credentials.**
> `site/.env.prod` and `site/.env.local` are plaintext environment files, and
> `docker-files/production/laravel/nginx/ssl/privkey.pem` is a TLS private key with its encrypted
> twin beside it. They are ignored by `.gitignore`, so no commit can carry them, but they are on
> this disk and none of them was opened for this document. Delete those three paths out of
> `lpr-compare/`. `site/.agents/machine.local.json` and its `.bak` are a machine record rather than
> a secret, and are the same file this package keeps ignored for the same reason. Everything below
> is readable without any of them.

## 1. The five ways a file there bears on this package

The folders that were asked for hold 3,593 files once the third-party and generated ones are set
aside — `site/.opencode/node_modules` is a package manager's output, `site/build/coverage` is a
PHPUnit HTML report of the app's own source, and `__pycache__` is a byte-code cache. None of it is
read here. What is left answers one of five questions, and the inventory in section 3 is sorted by
them:

| | Relation | What it means | Weight |
|---|---|---|---|
| **A** | **It calls this package** | a script, schedule or gate that runs `db:pgcat-flip`, `db:doctor` or `/health/db` | the strongest: a change here breaks production |
| **B** | **It configures this package** | the app's published `config/db-manager.php`, and the `database.php` connection it weights | a divergence is a bug on one side |
| **C** | **It is evidence about this package** | a production log, a finding note, or an alarm, that quotes this package's own rows, payloads and failures | the only external reading of this package's output there is |
| **D** | **It does this package's job at the app layer** | a command, a retry wrapper or a routing rule that exists because the package does not do it | the port candidates |
| **E** | **It is the environment this package runs in** | the pooler and its image, its three config variants, the RDS parameter groups, the credentials, the Redis, the deploy channel | context a port has to respect |

## 2. The shape of the relation, in one paragraph

This package is not a library the application uses incidentally: it is the thing that decides which
PostgreSQL endpoint each read goes to, it owns the per-minute repair of the pooler's configuration,
and its `/health/db` endpoint is one of the tests a deploy has to pass. Concretely, from the copied
files: the app's scheduler registers `db:pgcat-flip` every minute and again through the package's
own `FlipSchedule`; the deploy gate's 5th and 6th tests run `db:connection-diagnostics --real-test`
and `db:pgcat-flip --dry-run --json` inside the candidate container; cron runs `db:doctor` with
`www-data`'s environment; the container's entrypoint pre-creates the package's state, lock, boot
and audit files with group and other write bits; the app's `config/database.php` weights two pgcat
pools — the writer at 145 and the reader at 500 — through the package's weighted picker; and a
CloudWatch alarm is installed on the log line a cancelled read produces on the connection this
package names. In the other direction, the app's own `docs/` cite this package's source by file and
line (`PgcatConfigFlipper.php`, `WeightedDatabaseServiceProvider.php`,
`Support/ActiveConnection.php`), which makes those records the closest thing to an external audit of
this package's internals that exists.

## 3. The inventory

Paths are relative to `lpr-compare/`. "Verdict" is what this document proposes, not what was done:
**port** (bring something into this package), **docs** (write something here about it), **app**
(change belongs on the application side — no package edit), **read** (context only, nothing to
port), **skip** (not related after all).

### 3.1 It calls this package — relation A

| Path | What it is | Why it bears on this package | Verdict |
|---|---|---|---|
| `site/app/Helpers/SchedulingHelpers.php` | the application's schedule, including `setupPgcatFlipSchedule()`: `db:pgcat-flip` every minute, `withoutOverlapping(2)`, a mutex named per container from `gethostname()`, task name `pgcat_flip:<host>`, output appended to a log file | the caller this package's `FlipSchedule` now also registers; its 64 KB of docblocks are a consumer's account of the flipper's boot window, its `flock` and why `onOneServer()` is wrong here | read + docs |
| `docker-files/production/laravel/lpr-schedules` | the minutely cron entry: `runuser -u www-data -- env APP_ENV=prod php artisan schedule:run`, with a long comment about why `APP_ENV` must be here | the trap it documents is a package-relevant one: `schedule:run` died because `withoutOverlapping`'s mutex store resolved to SQLite when the environment was unset, so `db:pgcat-flip` never ran even once | docs |
| `docker-files/production/laravel/flip-rehearsal.sh` | the deploy's 6th test: runs `db:pgcat-flip --dry-run --json` inside the candidate and fails the revision unless it reports `would_flip`, `would_not_flip` or `skipped` | a non-dry-run-shaped consumer of the flip's own pipeline: the lock, the variant, supervisor's state and a temp write all have to work while nothing is changed | read |
| `docker-files/production/laravel/flip-rehearsal-selftest.sh` | 11 fixture cases over that rehearsal — every verdict, output that is not a report, an image with no flip command, an app root with no `artisan` | a fixture set for this package's `--dry-run` contract, written outside the package | docs |
| `docker-files/production/laravel/pgcat-health-gate.sh` | the deploy's 5th test: supervisor's state for the pooler, a TCP accept on 5433, and one `select 1` through the application's pinned connection (`db:connection-diagnostics --real-test`) | the pooler-side half of what this package's flipper and health endpoint report; its three checks are the ones a container cannot fake | read |
| `docker-files/production/laravel/pooler-healthcheck.sh` | the ECS **container health check**: supervisor's state plus a real TCP connect on the pooler's port, with `--deep` adding the `select 1` | the production answer to "is the pooler up", which is the situation this package's `pgcat supervisor` row and `flip_window` report on; a package-side healthcheck recipe should defer to it | docs |
| `docker-files/production/aws-settings/deploy-ecs.sh`, `inspect-task.sh` | the deploy channel: register a revision, gate it (tests 3–8, with rc 5 = pooler gate, rc 6 = flip rehearsal), roll back | two of its exit codes exist because of this package's dry run and the app's connection probe; a package change that alters either contract breaks a deploy | read |
| `docs/git-msg.txt` | a transcript of production log lines, including this package's own warnings: the `swrr.pgcat.gate` finding with `first_reported_at` / `resolved`, the store's fallback to the in-process SWRR store, the "no `redis` binding yet" reason | the only recording there is of this package's boot-audit payloads as production emitted them, with the finding keys as keys | read (evidence) |
| `docker-files/production/laravel/entrypoint.sh` | container start: renders the three pgcat configs from templates, prunes pools whose addresses do not resolve, stamps the boot time, runs `config:cache`, and `setup_dbmgr_swrr()` pre-creates `/var/swrr/pgcat-flip-state.json`, `.lock`, `container-booted-at` and `swrr-audit.json` with `chmod g+w,o+w` | it is the other half of this package's file contract: `state_file`, `lock_file`, `boot_file` and the audit record, all under `/var/swrr` here rather than in the temp directory the sample ships, and all handed over writable — the requirement the audit record's own writeability fix addresses | docs + port |
| `site/bin/verify-machine-local.py`, `site/.agents/toolchain.py` | the app's own machine-record and toolchain guards | the same idea as this package's `.agents/machine.local.json` and its guards, in the other repository — no shared code, but the same contract | skip |

### 3.2 It configures this package — relation B

| Path | What it is | Why it bears on this package | Verdict |
|---|---|---|---|
| `site/config/db-manager.php` (18.9 KB) | the application's **published copy** of this package's sample config | it is several releases behind the sample, and the divergences are exactly the ones this package fixed (section 4.1) | app + port |
| `site/config/database.php` | the app's connections: `pgsql_proxy` with `write` = one entry (`writer_db_1`, weight 145.0) and `read` = two (`writer_db_1` 145.0, `reader_db_1` 500.0), all on `127.0.0.1:5433`, `sticky => true`, plus an `options` value that is an object resolved at boot | this is the input to this package's weight resolver: the pick is *between pgcat pools*, weighted, and pgcat then fronts RDS — so a weight change here is a routing change there, and a pool missing from `pgcat.toml` fails every read that picks it (section 4.6) | read |
| `site/config/app.php` | includes `default_api_connection` (env `API_DB_CONNECTION`), a `timezone` taken from env, and an AWS region key | the connection the package should follow is this one, not `database.default`; the app's copy of `db-manager.php` sets `swrr.connection` with a nested fallback to `API_DB_CONNECTION` and then to `sqlite` — the two-fallback shape behind the silent-SQLite incident | docs |
| `site/vendorOverrides/Illuminate/Foundation/Bootstrap/LprLoadConfiguration.php` | the app's replacement for the framework's configuration bootstrapper, with its own logging path | this is the class that decides whether the cached config is read, which is the mechanism behind the "sticky cached config" finding in `docs/db-connection-resolution.md` §7 — and the reason a *config-cached* boolean belongs in this package's own report (phase 2) | port (a row) |
| `site/app/Helpers/ConfigHelpers.php` | the connection's dynamic values: an RDS IAM token provider, a Redis-cached token (key `aws-rds-auth-token`, TTL 900 s), username, SSL mode and the `getPostgresqlDbProxyOptions()` array the connection's `options` resolves to | the credentials path behind the weighted connection, and the reason a password here can be a live token rather than a string — the package must report such a value as its type, which is what its own doctor already does | read |
| `site/bootstrap/cache/{packages,services}.php` | the compiled provider/package cache, which names this package's provider | evidence the package is registered and the provider is what boots the audit | read |
| `site/composer.json` | the requirement on the package, by name | the version the application runs | read |
| `site/vendorOverrides/Illuminate/Database/Connectors/PostgresConnector.php` | the app's override of the framework's PostgreSQL connector (adds an `App\Config\AutoWireCallbacks`-resolved option path) | every connection this package builds goes through a connector class; if the app has replaced it, the package's factory must resolve the application's class rather than the framework's, or the app's connection options are lost | port (a doctor row printing the connector in use) |
| `site/vendorOverrides/Illuminate/Database/Enhanced/Postgresql/**` | a large app-side enhancement layer over the PostgreSQL grammar, schema and query builder | the SQL a weighted read is expressed in goes through this layer; a package change that pins or rewrites query builders has to survive it | read |

### 3.3 It is evidence about this package — relation C

| Path | What it is | Why it bears on this package | Verdict |
|---|---|---|---|
| `docs/db-connection-resolution.md` (62 KB) | how the connection is decided, why a pgcat gate can silently judge SQLite, and what was done about it | cites this package's source by file and line, reads the boot-audit payload's keys, and documents the `swrr.pgcat.enabled` reading where `'off'` casts to true — the finding `Support/SwitchValue.php` exists for; §12 is the app's own probe command, written *because* `db:doctor` is too heavy for a build container | port + docs |
| `docs/pgcat-issues.md` (22 KB) | the 2026-09-28 crash-loop: a promoted image answered every request with `SQLSTATE[08006] … Connection refused` on 5433 while ECS, the ALB and the container health check all said healthy | the failure this package's flipper, boot window and health endpoint were hardened against, told from the deploy side; §7's "the flip cannot rescue a pooler that is not running" is the reason the flip now has a `start_command` | docs |
| `docs/pgcat-boot-hardening.md` (36 KB) | the boot-time pool pruning, the per-minute flip and its window, the deploy rehearsal, and the health check ECS reads | a full external account of this package's `flip_window_seconds`, `FlipWindow::DEFAULT_SECONDS`, the `flock` on `swrr.pgcat.lock_file`, `--status --json`'s `status.window` block and the `/health/db` field names — and of the schedule that had no caller until it was added | docs |
| `docs/replica-conflict-errors.md` (72 KB) | two cancellations on the read path — 40001 "conflict with recovery" and 57014 "statement timeout" — with the hop table, the parameter groups, the 30-day SQLSTATE distribution and the sizing question | names the read path this package routes: `writer_db_1` weight 145.0 against `reader_db_1` weight 500.0 through the pooler, then RDS Proxy for writes and a `db.t4g.large` replica for reads | port + docs |
| `docker-files/production/aws-settings/install-log-alarm.sh` | installs a CloudWatch metric filter and an alarm on the application log's cancelled-read lines, published to the replica-alerts topic, plus a second metric with deliberately no alarm for the statement-time-out half; it verifies its own pattern with `logs test-metric-filter` because a wrong pattern reports a flat zero rather than failing | **the operational alarm on this package's read path.** Its sample line names the connection (`pgsql_proxy, SQL: select 1`) and its reason ("conflict with recovery"), and its two filters are the two failures `docs/replica-conflict-errors.md` separates — so this is the thing that pages when a weighted read picks a replica that cancels it | docs |
| `docs/primary-temp-spill.md` | why the writer spills temp files through a 4 MB `work_mem`, and which statements do it | the same investigation's second half; relevant because a heavy read routed to the writer is the package's `useWritePdo()`-shaped decision, and the spill is what that routing disturbs | docs |
| `site/.agents/statistics_windows.py` | a guard over the recorded PostgreSQL statistics: a cumulative counter may only be divided by its own family's clock (`pg_postmaster_start_time()` for `pg_stat_database`, `pg_stat_statements_info.stats_reset` for the statement statistics) | it exists because a rate was computed against the wrong window during the read-path investigation, and it is the app-side analogue of this package's own prose-number guards: a claim has to be backed by a recording, and it exits 2 — "nothing was judged" — when there is none | read |
| `docs/TODO-database-tests.md` | the app's database-backed tests do not pass, item by item, with the counts | its item 2 is *this package's* environment fact: 8 tests fail because they read through pgcat and no pooler is listening on 5433 — the same problem this package's own suite solves with a fixture pooler | app + docs |
| `docs/codebuild-pipeline.md` | what the build does, and where the connection probe fits, including its known gaps | documents that a missing secrets mapping makes the gating connection probe skip silently — the "green build, unexamined configuration" shape the package's own audit is written against | docs |
| `docs/deploy-troubleshooting.md` (35 KB) | failures met in the field, the 2026-09-29 rollback, and the Redis and WebSocket gates | its §11 argues `/health-redis` must be separate from `/health-db` so a failure names the dependency — a design statement about a surface this package publishes | docs |
| `docker-files/production/laravel/deployment-analysis/depl-anlyss-20260925_195342.md` | a pre-migration analysis of the deployment, file by file, with numbered findings — including that `site/config/db-manager.php` is the package's published config, and that the rendered pooler configuration and the app's connection disagreed about which pools exist | its F-05 is the field evidence for section 4.6, and its method — enumerate the files a deploy carries, then check the claims that cross the boundary between them — is the method this document uses | read |
| `docs/laravel-repo-schema/SKILL.md`, `site/.agents/skills/laravel-repo-schema/` | the app's schema skill: generate models, migrations and casts from the live database | adjacent rather than related — it reads the same PostgreSQL, but nothing in it touches this package | skip |
| `docs/tables.txt` | a bare list of the application's table names | a schema inventory from the same investigation | read |
| `docs/1.txt`, `docs/2.txt`, `docs/git-config-1.txt`, `git-config-2.txt`, `git-msg-1.txt`, `postman1.json`, `orders-history-api.md.txt`, `database.php.txt`, `database1.php.txt`, `database2.php.txt` and the other `.txt` twins | working notes, an address and a port of a local container engine, personal git config, API collections, and older copies of files that also exist as themselves | strays: no relation to this package, and two of them are a machine's own configuration. The `database*.php.txt` pair is worth knowing about only because a stale copy of a connection config is exactly the kind of file a reader mistakes for the live one | skip |

### 3.4 It does this package's job at the app layer — relation D

| Path | What it is | Why it bears on this package | Verdict |
|---|---|---|---|
| `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php` (39 KB) | `db:connection-diagnostics [--json] [--strict] [--real-test]`: the environment file this boot read, whether config is cached, `swrr.connection` and the setting it came from, per connection the driver and its write/read endpoints, whether pgcat can front that driver, the pgcat switch "as written, as PHP's cast reads it, and as the package reads it", and a verdict with exit 1 on the states the armed configuration cannot work in — credentials printed as their type only | a parallel of this package's `db:doctor`, and its docblock says why it exists: *`db:doctor` also probes the store and asks supervisorctl, so it cannot run everywhere an image is built*. That is a statement about this package's interface, and the port is to remove the reason rather than to keep two commands | **port** |
| `site/tests/Unit/DbConnectionDiagnosticsCommandTest.php` | the tests over that command, whose `--real-test` half is the only field that proves the connection can answer | the acceptance criteria a package-side equivalent inherits | port |
| `docs/db-connection-resolution.md` §12–15 | the same command from the reader's side, its two smoke runs, `CONNECTION_PROBE=0`, and the one greppable `swrr.connection = <value>` line | specifies the fields and the exit contract a package-side equivalent has to carry | port |
| `site/config/database-retry.php` + `site/app/Database/ConcurrencyReadRetry.php` + `site/app/Database/HeavyReadRouting.php` | re-runs a read a standby cancelled (SQLSTATE 40001), sends a statement time-out straight to the write connection (57014) with `attempts`, jittered backoff and `fallback_to_primary`; and pins two named heavy reads to the write pool with `useWritePdo()` behind `database.route_heavy_reads_to_writer` | this package chooses the replica the read lands on; the app has had to add the recovery layer for what that replica does. Port the *knowledge* (a record plus a doctor row for the caps and for the read/write-pair invariant), not the retry — see 4.4 | docs (+ port, staged) |
| `site/tests/Unit/Database/{ConcurrencyReadRetryTest,HeavyReadRoutingTest,PostgresEnhancedConnectionReadRetryTest}.php` | three files: the retry's decision against a callable that fails the way a replica fails it, the wiring of the two heavy reads to the write PDO, and the proof that every read actually reaches the retry | the app tests its own layer three ways where this package tests none of the routing's consequences; a ported capability would need its own fixtures here, not a copy of these | read |
| `site/tests/db-views/pg/**.sql`, `site/tests/db-mat-views/pg/**.sql`, `site/tests/db-functions/pg/**.sql`, `site/tests/database.sqlite` | the database's own definitions — 47 views, 12 materialized views, 48 stored procedures — and a SQLite fixture | the statements whose cost makes the routing decision matter, and the fixture that makes "no replicas" a passing read path in a test | read |
| `docker-files/production/laravel/get-cached-db-token.sh` | reads an RDS IAM auth token out of Redis (`aws-rds-auth-token`) for the application's connection options | the credentials path behind the weighted connection: a token with a lifetime, cached, resolved at boot through `ConfigHelpers` — a package that reports on the connection should not report a token as a password, and does not | read |
| `site/app/Services/DbViews/**`, `site/app/Database/QueryBuilders/**` | the app's view-backed services and query builders, including the two statements `docs/primary-temp-spill.md` names | the reads whose cost makes the routing decision matter | read |

### 3.5 It is the environment this package runs in — relation E

| Path | What it is | Why it bears on this package | Verdict |
|---|---|---|---|
| `docker-files/production/laravel/pgcat.toml` | the pooler template: port 5433, `pool_mode = "transaction"`, `pool_size = 250`, `min_pool_size = 25`, `idle_timeout` and `max_wait_time` in ms, `server_tls` with the RDS CA bundle, and one `[pools.<name>.users.0]` block per database with placeholder passwords spliced in at container start | the file this package's flipper swaps. It declares `writer_db_1` and `reader_db_1` today, and those are the exact `database` values the app's `read` list weights — so a flip that changes the pool set changes which reads can be served | read + docs |
| `docker-files/production/laravel/pgcat-no-readers.toml` | the writer-only variant: `writer_db_1` only | one of the three paths this package's `config_path` / `readers_path` / `no_readers_path` name, seen from the files' side: **two** templates are in the copy and the entrypoint renders **three** configs, one of them derived | port (variant integrity) |
| `docker-files/production/laravel/pgcat-Dockerfile` | the pooler's own image: Rust and `build-essential`, the source cloned at a pinned `PGCAT_VERSION` tag (`v1.2.0`) and compiled in a builder stage, a minimal runtime stage, then the binary on the path | the pooler's version is pinned and compiled here rather than pulled, so the semantics this package's flipper, probe and health rows assume are the ones of *that* tag — a package record about the deployment should name it, because a pgcat upgrade is a change to this package's runtime | docs |
| `docker-files/production/laravel/supervisor.d/pgcat.conf`, `supervisord.conf` | `[program:pgcat]`, `numprocs=1`, `autorestart=true`, `startsecs=3`, `startretries=5`, logs under `/app/logs` | the program name the package's `restart_command` / `start_command` address as `pgcat:*`, and the reason a "not running" state has a repair distinct from `restart` | read |
| `docker-files/production/laravel/pool-prune-selftest.sh` | the regression test for the boot-time pruning: `getent` stubbed with a strict allowlist, every assertion over the parser's names, and the silent failure it guards (a parser that picks up more or less than the bare hostname makes *every* address unresolvable and the prune a no-op) | the pruning half of this package's variant problem, with the fixture discipline a package-side check should copy — including that a no-op that looks like success is the failure mode to write a case for | port (phase 5) |
| `docker-files/production/laravel/entrypoint.sh` → see 3.1 | — | — | — |
| `docker-files/production/redis/redis.conf`, `docker-files/production/redis/{Dockerfile,entrypoint.sh,healthcheck.sh}`, `get-cached-db-token.sh` | the Redis the app runs: 16 logical databases, `appendonly no`, no `maxmemory` (so no eviction policy to lose a key to), the SWRR state's store | this package's `primary_store` is a Redis connection and it has a documented fallback when the store is unreachable — `docs/git-msg.txt` records that fallback firing in production ("the container has no `redis` binding yet"), which is the state to understand: the weighted state and the flip's state survive only as well as this instance's memory, and the token cache shares the process | read |
| `aws/data/rds/param-groups/timeoutgroup2/` and `timeoutgroup2-replica/` | the writer's and the replica's parameter groups as captured: writer `statement_timeout` 120000; replica `statement_timeout` 90000 with `max_standby_streaming_delay` 300000 and `max_connections` 3470 | the two caps are *different on purpose* in this deployment, which is the whole reason a statement timeout can be answered by the other server — a fact the read-path port depends on | port (probe) |
| `aws/data/rds/param-groups/timeoutgroup1/` | a third group whose `statement_timeout` is 600 ms | a group that will cut nearly every read it serves; the shape a `db:doctor` row for caps would catch | read |
| `aws/data/rds/lpr-database-4c-r7/{details,cancellation-evidence,hot-standby-feedback-check,cancelled-manifest-statement-plan}.json` | the replica's identity, its class, the cancellations observed, and the plan of a statement that was cancelled | the measurement behind the replica-conflict record: this is the endpoint the package's read picks reach | read |
| `aws/data/rds/{aws-rds-list-db-proxies,param-group-changes,sizing-evidence}.json`, `proxies/lpr-database-3-proxy-3/` | the RDS Proxy that fronts the writer — named for one instance and targeting another — and the record of when the caps changed | the write side of the same path; the record's own warning (do not read the proxy's name as its target) is the kind of thing a health report can get wrong | read |
| `aws/commands/rds-replicate-create-commands.txt` | eleven attempts at creating a read replica, with the class, the AZ, the parameter group and the outcome of each | the replica fleet the package weights, built by hand, with two failures recorded. **Its lines carry this machine's own workspace path**, which is why nothing here quotes them | read |
| `aws/data/rds/aws-rds-describe-db-instances-2026-10-09.json`, `aws/data/rds/lpr-database-*/**`, `aws/data/rds/engine-options/**`, `aws/data/rds/option-groups/**` | the captured fleet: instances, snapshots, tags, engine options, option groups | the inventory a weighted read set is drawn from | read |
| `docker-files/production/aws-settings/install-rds-credit-alarms.sh` | installs CloudWatch alarms on RDS CPU credits | a burstable replica that spends its credit every day is an undersized read endpoint — the alarm is the operational half of "is the replica the right size", which the read path cares about | docs |
| `docker-files/production/aws-settings/install-log-alarm.sh` → see 3.3 | — | — | — |
| `docker-files/production/aws-settings/install-memprobe-metrics.sh` | a memory-probe metric and its alarm for the container | indirect: the container's health is what the deploy gate reads | skip |
| `docker-files/production/aws-settings/lpr-backend-sites-tdef-01.json` and the other three task definitions | the task definitions, including the health check and the secrets mapping | shows the health check that runs the pooler probe, and the `secrets` block a DB credential arrives through | read |
| `docker-files/production/laravel/{Dockerfile,Makefile,validate-image.sh,smoke-test-image.sh}`, `composer-refresh.sh`, `crontab`, `php/**`, `nginx/**`, `redis/**` | the image: build, the shell stage that checks every script for present/executable/`bash -n`/`--help`, the smoke gate's `2b/3` connection run, and the surrounding services | the smoke gate is where the app's own probe gates a release — the place a package-side light doctor would be run instead | port (phase 2) |
| `docker-files/production/laravel/processWatchdog`, `processWatchdog-config.ini`, `supervisor.d/*.conf`, `deployment-analysis/**` | a process watchdog, the workers, and an analysis of the deployment | context for where the flipper sits among the container's processes | read |
| `site/.agents/skills/lpr-deploy/SKILL.md` (and `.agents/commands/lpr-deploy.md`) | the deploy runbook: the promotion gate's numbered tests (5 = pooler gate, 6 = flip rehearsal), the `pgcat-logs` command that reads `/app/logs/pgcat.log` out of a task, and the guardrails | the operational procedure that depends on this package's dry run and the app's connection probe; its log-reading half is what an operator needs when the pooler is the fault | docs |
| `site/.agents/skills/{laravel-dev,lpr-commit,lpr-push,php-code-analyzer,skill-creator}/`, `site/.agents/{code,commands,instructions,prompts,agents}/**`, `site/.agents/*.py`, `site/.agents/types/**` | the app's own agent tooling: skills, a validator for the schedule's per-day keys, the statistics-window guard above, an evidence-note guard, a stale-index guard, and a toolchain record | not this package's, but two of them are *guards* worth reading for shape — one that refuses a record whose numbers are not backed by a recording, and one that keeps the schedule's key vocabulary checked rather than hand-written | read |
| `site/tests/Helpers/**, site/tests/Factories/**, site/tests/Feature/**, site/tests/HttpRequests/**` | the rest of the application's suite: helpers, factories, feature and HTTP-request fixtures | the fixtures a database-backed test needs, which is the half this package's own suite supplies for itself | skip |

## 4. The divergences, each with the command that shows it

These are the findings the port plan acts on. Every one is reproducible from the copy alone.

### 4.1 The application's published config predates the sample by several releases

`diff -u config/db-manager.php lpr-compare/site/config/db-manager.php`. The app's copy, dated
2026-10-09, differs from this package's sample (2026-10-12) in ways that are not cosmetic:

| The app's copy | This package's sample | Why it matters |
|---|---|---|
| `'enabled' => (bool) env('SWRR_PGCAT_ENABLED', true)` | uncast, default `false` | `(bool) 'off'` is true: the app's copy converts the spellings an operator writes into their opposite before the package ever reads them. This is the exact defect `Support/SwitchValue.php` and `db:doctor`'s switch reading exist for — and the app, running that copy, cannot be protected from it |
| `'allow_local_fallback' => (bool) env(…)` | uncast | same defect, one setting over |
| both `pgcat.enabled` **and** `schedule.windows.enabled` default **true** | both `false`, with a comment saying to arm one and disarm the other | the sample's contract is that exactly one mechanism owns the mode. The app's copy arms both by default |
| `schedule.interval_minutes` default `2` | `1` | while `SchedulingHelpers` also registers the command **every minute**, so the package's duplicate guard is the only thing keeping one entry from becoming two |
| no `stale_after_missed_runs` | present, default 5 | the app will take the package's default — fine, and worth knowing before the watchdog is released |
| `pool_probe.enabled` true, `port` 5432 | false, `port` 6432 | the shipped port is pgcat's own convention; 5432 is PostgreSQL's, and the probe pointed there is answered by a server, not by the pooler — the package's `PoolProbe` limitation note is about exactly this |
| comment: the pooler "does not authenticate before it looks the pool up" | the note that a pooler which authenticates *before* resolving a pool answers *every* name | the app's copy states the older, stronger claim — the one this package's own code writes down as a limitation |
| `state_file`, `lock_file`, `boot_file` under `/var/swrr` | the temp directory, as a default | an installation decision, and a good one: it matches `entrypoint.sh`'s `setup_dbmgr_swrr()` — which is worth a doctor row, since those files are pre-created `g+w,o+w` and a moved path is a silently unusable flipper |

**Verdict:** an app-side republish, and a package-side way to *know* the copy is old — see phase 1.

### 4.2 The app keeps a lighter doctor of its own, and says why

`site/app/Console/Commands/DbConnectionDiagnosticsCommand.php`, docblock lines 37–39: *"`db:doctor`
judges the same gate, but it also probes the store and asks supervisorctl, so it cannot run
everywhere an image is built."* Its JSON key set is fixed and always present — `environment`,
`selection`, `connections`, `pgcat`, `pinned`, `checks`, `counts`, `verdict`, `exit_code` — and two
of its facts are ones this package's `db:doctor` does not print at all: **which environment file
this boot read**, and **whether the configuration was compiled to a cache file**. Both matter here,
because the app's own finding 6 is that a cached config is sticky and no later process corrects it
without an explicit `config:cache` — and the class that implements that stickiness is
`site/vendorOverrides/Illuminate/Foundation/Bootstrap/LprLoadConfiguration.php`.

**Verdict:** port. Not the command — the *capability*, so the gate can run this package's doctor
instead of a clone.

### 4.3 The pooler's configuration has three variants, and only two are files in the copy

`entrypoint.sh` renders `pgcat.toml`, `pgcat-readers.toml` and `pgcat-no-readers.toml`, then prunes
unresolvable pools from all three before supervisord starts; `docker-files/production/laravel/`
holds `pgcat.toml` and `pgcat-no-readers.toml`. This package's `config_path`, `readers_path` and
`no_readers_path` name all three, and `docs/pgcat-boot-hardening.md` §3.1 is explicit that one pair
of them must be identical and another pair must not be. Nothing in this package checks that
relationship at runtime: a flip to a variant that is missing, or one that still declares readers, is
a pooler pointed at addresses the container cannot reach. And the pruning is not a package concern
but a *deployment* one — `pool-prune-selftest.sh` shows how carefully the app has had to test it,
including the no-op-that-looks-like-success case.

**Verdict:** port. The flipper swaps whole files; it should be able to say that what it is swapping
*to* is a shape it can serve, before it swaps to it.

### 4.4 The read path's failures are managed at the app layer

`docs/replica-conflict-errors.md` §1 gives the hops: writes through the pooler at weight 145.0 to
the writer, then an RDS Proxy to the primary instance; reads at weight 500.0 to a `db.t4g.large`
replica. The replica cancels reads two ways — 40001 while applying WAL, 57014 at its own
`statement_timeout` of 90 s against the writer's 120 s. `site/app/Database/ConcurrencyReadRetry.php`
and `site/config/database-retry.php` re-run the first and escalate the second to the writer.
`site/app/Database/HeavyReadRouting.php` pre-empts both for two named reads through `useWritePdo()`,
and there is a CloudWatch alarm on the first of them.

This package picks the replica. It does not re-run anything, does not know the two caps differ, and
does not report that a read it routed to a standby can be cancelled at all. The knowledge belongs
in this package because the routing decision is this package's; the resilience does not obviously
belong here, because re-running a `SELECT` inside the application's own transaction handling is the
application's business — and `ConcurrencyReadRetry` is explicit that it never re-runs inside a
transaction for exactly that reason.

**Verdict:** docs and probe now (a record, and a doctor row that asks each read endpoint for its own
`statement_timeout` and says whether the endpoints disagree), a retry facility later and only if
asked for.

### 4.5 The read sets' host names are pool names, and a missing pool is a failed read

`site/config/database.php` sends every read to `127.0.0.1:5433` — the pooler — with the *database*
field naming a pool (`writer_db_1`, `reader_db_1`). So a weighted pick is a pick between pools, and
a pool that no rendered variant declares fails every read that lands on it. `docs/pgcat-issues.md`
§5 is the same mechanism from the failure side: pgcat would not start because a configured server
did not resolve — and the pruning in `entrypoint.sh` exists so that a dead address costs a pool
rather than the pooler.

**Verdict:** port. This package's `PoolProbe` already asks the pooler about each pool a variant
declares; the missing half is asking about the pools the *connection* declares, which is the set
that can actually be picked.

### 4.6 The variant in place and the pools the connection names, checked against each other

The deployment analysis of 2026-09-25 (`deployment-analysis/depl-anlyss-20260925_195342.md`, finding
F-05) recorded this exact class of defect: the `writer_db_1` pool commented out of the rendered
`pgcat.toml` while the application's *write* connection targeted `writer_db_1`, the readers variant
rendered twice under two names, and supervisor launching the file without the writer pool — so the
pools the connection named did not exist in the config in place. It also recorded the aftermath:
"no supported way to switch `pgcat.toml` between reader/writer" — which is the gap this package's
flipper now fills.

Checked against today's copy, that defect reads as repaired and the invariant holds:

| Config | Pools it declares | Pools the connection names |
|---|---|---|
| `pgcat.toml` (uncommented sections) | `writer_db_1`, `reader_db_1` | write: `writer_db_1`; read: `writer_db_1`, `reader_db_1` |
| `pgcat-no-readers.toml` | `writer_db_1` | the write pool, and no read pool — the repair the writer-only variant exists to be |

So the *fact* is fine today and the *check* is what is missing: nothing in this package compares the
two columns, and the mode this package reports as applied is only usable if they agree. That is
phase 5, and it is the reason phase 5 is a check rather than a fix.

## 5. The port plan

Seven phases, ordered by what goes wrong if it is not done first. Each names the files it touches
here, the LPR files it comes from, what "done" means, and the check that proves it. Nothing in the
plan requires reading the sibling repository again: the copy is the source.

### Phase 1 — the published config can say how old it is

*From:* `site/config/db-manager.php`, and the diff in 4.1.

Add a schema generation to the sample config (`'config_version' => N`, with the number owned in the
class that reads it), and a `db:doctor` row that compares the published file's value with the
package's: equal is silent, older is a warning naming the release it was published at, absent is a
warning naming the difficulty (the file predates the key, so its age cannot be read) rather than a
failure. The row is a warning and not a failure on purpose: running an older published config is
not a fault, it is a fact a preflight should state, and the app's copy in 4.1 is precisely a config
that looks fine and behaves as this package did two releases ago.

*Done when:* a fresh install that has never published is silent; a config published from an older
release is reported by name; the sample's own copy reads as current; and the row appears in
`db:doctor --json`.
*Check:* `php bin/tool.php phpunit tests/Unit/Console/DbDoctorTest.php` plus the JSON fixture the
doctor's key set is pinned by, then `php bin/checks.php --only=counts,sentences,pint,phpstan`.

### Phase 2 — `db:doctor` gets a mode a build container can run

*From:* `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php`, its test, and `docs/db-connection-resolution.md` §12.

Give the doctor a bounded mode — `--connection-only`, the same command with the probes that need a
store, a supervisor or a socket left out — so the app's smoke gate can run this package instead of
its own clone. Add the two facts the app's command has and this package's does not: **the
environment file this boot read** and **whether the configuration is cached**, and publish
`swrr.connection` beside the setting it came from, so *unset* and *set to the default* stay
distinguishable. The exit contract follows the app's, which is already this package's: 1 for the
states the armed configuration cannot work in, 0 otherwise, with a `--strict` that fails warnings.

*Done when:* `db:doctor --connection-only --json` runs with no store, no supervisor and no
reachable database, and fails (exit 1) for a `swrr.pgcat.enabled` true over a `sqlite` connection —
the state `docs/db-connection-resolution.md` §6 found in a promoted candidate.
*Check:* the doctor suite, plus one new test that runs the mode with the store unarmed and the
supervisor absent; then the app's own probe command can be retired on the application side.

### Phase 3 — the schedule's two silences get a cause, not just a verdict

*From:* `docker-files/production/laravel/lpr-schedules`, `site/app/Helpers/SchedulingHelpers.php`.

The pending watchdog work reports that the per-minute entry stopped. Two causes it cannot see are
worth rows, because both happened: the mutex store the entry needs was unreachable (cron without
`APP_ENV` resolved `CACHE_STORE` to a `database` store against SQLite, and `schedule:run` threw
before the command was ever invoked), and **two entries carry the name** — the app's own every
minute and the package's own registration — which only this package's duplicate guard keeps from
becoming two runs. Rows: the schedule's mutex store is reachable; exactly one entry carries
`swrr.pgcat.schedule.name`; and the boot-stamp file is readable and writable by the user the
schedule runs as (the `/var/swrr` contract from 3.1).

*Done when:* each row reports what it read, not just pass or fail, and all three are silent on an
installation that never armed the schedule at all.
*Check:* the watchdog suite plus `DbDoctorTest`.

### Phase 4 — the read path's caps, and what a routed read can expect

*From:* `docs/replica-conflict-errors.md` §1–§3, `site/config/database-retry.php`, the parameter
groups in `aws/data/rds/param-groups/`, and `install-log-alarm.sh`.

A `db:doctor` row that asks each read endpoint for its own `statement_timeout` and reports the set:
equal caps are silent, unequal caps are reported as a fact ("a read cut here can be answered by the
write endpoint, whose cap is the longer one"), and a cap lower than the write endpoint's by a wide
margin is a warning. A second row that asks whether the *pinned* connection has a `read`/`write`
pair — because `useWritePdo()` is honoured only where it does, which `HeavyReadRouting`'s docblock
states and nothing in this package verifies. And one record under `docs/`: what a standby cancels
(40001 and 57014, with the sentence each carries), why re-running is the conflict's answer and the
server's cap is not, why this package stops short of re-running a read the application may have
opened a transaction around, and that an alarm on the first of them already exists on the consuming
side rather than being this package's to install.

*Done when:* the rows are read from the live endpoints where the endpoints are reachable and report
silence, not failure, where they are not; the record is registered in the counts/sentences records
like every other.
*Check:* the doctor suite, `php bin/counts.php --check`, and the documentation guards under
`tests/Unit/Docs`.

### Phase 5 — the variants a flip swaps between are checked before the swap

*From:* `docker-files/production/laravel/entrypoint.sh`'s rendering and pruning,
`pool-prune-selftest.sh`, `pgcat.toml` and `pgcat-no-readers.toml` as they stand, and
`docs/pgcat-boot-hardening.md` §2–§4.

`db:pgcat-flip --dry-run` already rehearses the world-changing steps without taking them. Extend the
checks it performs — and give `db:doctor` the same reading — to the *shape* of what is being swapped
to: each configured path exists and parses; the writer-only variant declares no reader pools; the
config in place declares the pools the pinned connection names (4.6); and any pool pruned at boot is
reported rather than merely absent. The last is the app's rule ("a pool whose address does not
resolve at boot is removed, because pgcat validates every configured server when it starts") and it
belongs in this package's reading of the file, because a flip that swaps to a variant declaring a
pruned pool re-introduces the failure the prune was for. `pool-prune-selftest.sh` is the fixture
discipline to copy: a stub with a strict allowlist, and a case for the no-op that looks like
success.

*Done when:* a rehearsal against a fixture tree with a reader-bearing writer-only variant fails with
a sentence naming the path and the pool; a tree whose config does not declare a pool the connection
names fails the same way; and the contract's shape passes.
*Check:* the dry-run suite (`DbFlipPgcatDryRunTest`) and the flipper suite.

### Phase 6 — one record for the deployment, under `docs/`

*From:* `docs/pgcat-boot-hardening.md`, `docs/pgcat-issues.md`, `docs/deploy-troubleshooting.md` §11–12, `deploy-ecs.sh`, `pooler-healthcheck.sh`, the `flip-rehearsal.sh`/`pgcat-health-gate.sh` pair, `pgcat-Dockerfile`, and the `lpr-deploy` skill's gate table.

Write down, here, what a deployment of this package has to have: the pooler's three rendered
variants and the prune-before-start rule; the pooler's pinned version, since it is compiled into the
image rather than pulled; the boot stamp and where it comes from; the per-minute entry, its window
and its `flock`; the container health check that asks supervisor and the port rather than a cached
route; the two deploy tests that depend on this package (the pooler gate and the flip rehearsal)
with their exit codes; the fact that `/health/db` is a *machine's* surface and
`db:scheduler-watchdog` is the container's own; the store the weighted state lives in and what its
absence means; and that the database's read caps and the write cap need not match — which is what
makes an escalation to the writer possible. Cite the LPR records as the field evidence they are, by
name, and cite nothing that carries a machine's own path.

*Done when:* the record is in `docs/`, registered in the counts and sentences records, and its
citations resolve — the same bar every other record here meets.
*Check:* `tests/Unit/Docs` in full, plus `php bin/counts.php --check`.

### Phase 7 — the application-side list, for the other repository's session

No package edit. Written here so the two halves stay comparable: republish `config/db-manager.php`
(changing the uncast switches, arming exactly one of the schedule and the window tasks, and setting
the pool probe's port to the pooler's own); decide whether to keep `DbConnectionDiagnosticsCommand`
once phase 2 lands; leave `ConcurrencyReadRetry` where it is; and — a real one — the app's `docs/`
cite this package's files by line number, so a package edit silently makes those citations wrong.
That is the other repository's guard to write, and it is worth telling them so.

## 6. How the sweep was made, so it can be repeated

* **The first token set** — `db-manager`, `weighted-dbmanager`, `WeightedDatabase`, `db:doctor`,
  `DbDoctor`, `pgcat`, `pgbouncer` — finds a file that already knows about this package.
* **The second** adds the vocabulary of the *subject*: `pgcat`, `swrr`, `pgsql_proxy`, `reader_db`,
  `writer_db`, `statement_timeout`, `work_mem`, `health-db`, `health/db`, `db:doctor`, `db:pgcat`,
  `replica`, `RDS`, then ranks files by how often they say any of it. That is the pass that found
  `pgcat-Dockerfile`, `pool-prune-selftest.sh`, `deployment-analysis/**`,
  `install-log-alarm.sh`, `redis.conf`, `statistics_windows.py`, `ConfigHelpers.php`,
  `LprLoadConfiguration.php` and `site/tests`.
* **Excluded**, as third-party or generated: `**/node_modules/**`, `**/build/coverage/**`,
  `**/__pycache__/**`. `site/vendorOverrides/**` is *not* excluded — it is the application's own
  source, patched into framework names, and two of its files matter here (3.2).
* **Never read**: `site/.env.prod`, `site/.env.local`, the TLS private key and its encrypted twin,
  and anything under `site/.agents/machine.local.json`'s family beyond the key names.
* **Nothing outside `lpr-compare/` was opened.** While it existed it was ignored by `.gitignore`, so
  no sweep of it could reach a commit.

## 7. What the copy does not let this document answer

* **The application's top-level `app/` and `config/` were not copied**, so "the app and config
  folders" was answered with `site/app` and `site/config`, which is what arrived. If the intent was
  the top-level pair as well, tell me and the same reading follows.
* **`CHANGELOG.md`, `SCRIPTS.md` and `TOOL-REGISTRY.md` were not copied.** The registry is the one
  that matters most here: it would say how the application's own scripts are catalogued, which is
  the shape a package-side inventory of *its* `bin/` and its consumers could follow.
* **The application's test *policy* is only half visible.** `site/tests` was in the copy and the files
  in 3.4 were read, but the skip block `docs/TODO-database-tests.md` says a `LPR_SKIP_DB_TESTS`
  variable guards, and the pre-commit notice it says must be deleted together with that record, are
  described by that record alone: neither was read here.
* **Nothing is quoted from `aws/commands/*.json` or `rds-replicate-create-commands.txt`.** Those
  files carry this workspace's own path on most lines.
* **The copy is a moment, not a history.** The deployment analysis reads as pre-migration and its
  F-05 as repaired by the files beside it today; both statements are about the bytes copied here.

## 8. What was not done, deliberately

* No file outside this package's root was read, listed or written. The comparison reads
  `lpr-compare/` only.
* No LPR file was copied into `src/`, `config/`, `tests/`, `bin/` or `docs/`. The port plan is a
  plan: what gets ported is a capability, re-expressed in this package's own idiom with this
  package's own tests, not a file moved across.
* No phase was implemented. This document is the deliverable, and it is the only file this session
  added.
* `lpr-compare/` was ignored while it existed, nothing in it was committed or staged, and the
  folder and its ignore rule were removed once the reading was complete — section 0 says why.

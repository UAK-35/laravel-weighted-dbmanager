# What the LPR application holds that bears on this package

One reading, reconciled, of the consuming application's database-facing material — what each related
file is, what work it does, why it bears on this package — with the plan to port the parts that
belong here kept separately, in [site-to-package-port-plan.md](site-to-package-port-plan.md).

This merges two earlier documents, and it supersedes both:

* **`app-externally-related.md`** — the application's **tree**, read from a copy; the file-by-file
  inventory and the evidence records.
* **`git-externally-related.md`** — the application's **last eight days of commits**, read from a
  clone; which changes touched this package, what they changed it to, and what failed in production.

They read the same repository and largely the same files, so merging them means choosing between
readings in the places where they describe the same thing. Section 0 lists every one of those places
and which reading wins.

## 0. The two readings, how they were taken, and how they were reconciled

**Reading 1 — the tree copy.** 8 642 files, of which 3 593 are not third-party or generated, copied
into `lpr-compare/` at this package's root by the user; it held `docker-files/production` (85),
`aws/data` (349), `aws/commands` (15), `docs` (44), `site/.agents` (85), `site/bin` (5), `site/app`
(1 134), `site/tests` (197) and `site/config` (38), plus the application's `database/`, `routes/`,
`bootstrap/`, `resources/`, `vendorOverrides/` and `composer.json`. It was read twice: once for this
package's own names, once for the vocabulary of the *subject* (`pgcat`, `swrr`, `pgsql_proxy`,
`reader_db`, `writer_db`, `statement_timeout`, `work_mem`, `health-db`, `db:doctor`, `db:pgcat`,
`replica`, `RDS`), ranking files by how often they say any of it. The second pass is the one that
found the pooler's own image build, the pool-prune regression test, the deployment analysis, the
CloudWatch alarm, the Redis, the token provider, the statistics guard and `site/tests`. Excluded as
third-party or generated: `**/node_modules/**`, `**/build/coverage/**`, `**/__pycache__/**`;
`site/vendorOverrides/**` was **not** excluded, because it is the application's own source patched
into framework names.

The copy was then **removed from this tree, deliberately**, and the reason is a fact about this
package: `pint --test` runs at the root with no path, so a foreign tree sitting at the root is
inside the package's own style check. Measured before the folder went: pint reported 1 698 findings,
1 697 of them under `lpr-compare/`. The scratch folder existed to be read once, the reading is
recorded here, and the folder and its ignore rule went together.

**Reading 2 — the commit history.** A bare, blob-filtered, date-shallow clone of
`git@gitlab.com:lpr802309/laravel.git`, taken over https (the SSH endpoint answers `Permission
denied (publickey)` for this account; the repository reads anonymously) into `lpr-git/laravel.git` at
this package's root, covered by a line in `.gitignore`. The window is 2026-10-07 → 2026-10-14 as
`git log --all --since='8 days ago'`: **39 commits**, one author, one branch. The branch map matters —
the repository holds several unrelated projects on separate branches (`main`, `staging`,
`wordpress-01`, `cms-1`, `pytools-01`, `go-wss`, `analysis-01`, `data-import-1`), and everything
here is on `changes-by-umar`, whose **root commit `0e74e0e`** (2026-10-07 15:42) adds **2 889 files
at once**. That commit is a whole-tree import, not a change: it is why every file reports it as its
last modification unless it moved afterwards, and it is counted as related work nowhere below.

**Reading rules that shape the wording.** No line is quoted verbatim from a file that names a
machine — the AWS command transcripts, the local git config and the machine-local records carry this
workspace's own path, drive letter and all, and a quoted line would import that into a carried file,
which is what `tests/Unit/Support/MachinePathsTest.php` reads for. Every path below is the
repository's own, so a leading `site/`, `docs/`, `aws/` or `docker-files/` means the application's
directory of that name. Neither reading opened a sibling checkout, and no path here is a location on
this disk.

### 0.1 Where the two readings disagreed, and which wins

| Question | Reading 1 (tree copy) | Reading 2 (commit history) | Reconciled |
|---|---|---|---|
| How old is the application's published config? | "several releases behind", read from a copy dated 2026-10-09, against a sample dated 2026-10-12 | the tip (2026-10-14) at `v0.2.0-alpha3`, 13 keyed lines differing from the sample | **Reading 2 wins.** The tip is the state that matters, and it is what section 5.1 tabulates. Reading 1's rows survive inside it — the casts, the double-armed schedule, the cadence, the probe port, the `/var/swrr` paths — and Reading 2 adds what only a version pin can show: `swrr.connection`'s fallback chain, `pgcat.enabled`'s default, `audit.store_probe_seconds`, and the absence of `stale_after_missed_runs` |
| Are there credentials in what was read? | three paths in the copy: `site/.env.prod`, `site/.env.local`, and a TLS private key with its encrypted twin | the repository tracks `.env.example`, `.env.prod.encrypted`, `.env.sqlite-live` and `.env.testing.example` — no plaintext production environment | **Both are true of different things.** The plaintext files were in the *copy the user made*, not in the repository. The warning belonged to the copy and died with it; the clone carries no plaintext credential, and neither was opened by either reading |
| What do the relation letters mean? | A calls, B configures, C evidence, D does the job, E environment | A pins or configures, B calls, C evidence, D does the job, E environment | **Reading 1's order wins**, fixed once in section 1 below. Reading 2's rows are re-lettered: what it called "A — pins or configures" is B here, and what it called "B — calls" is A |
| Which failures does the read path present? | 40001 and 57014, with the hop table, the parameter groups and the SQLSTATE distribution | the same two, plus the numbers a commit carries: 90 s against 120 s, `hot_standby_feedback` off until 2026-10-13, ~422 failures an hour, 4 996 matching events in the hour before the parameter landed | **Merged, Reading 2's figures on Reading 1's frame** (section 5.5) |
| Was the two-sided pool fact broken or repaired? | the 2026-09-25 deployment analysis recorded it broken (F-05) and the files read as repaired by 2026-10-14 | the same fact disagreed again on 2026-10-09 — `pgcat.toml` at 03:06, the connection at 03:11 — and the app's hand-run checker still asserts the retired names | **Merged.** The *fact* is consistent today; the *check* is missing, and Reading 2 supplies the two occasions it was not (section 5.4) |
| What is the source of truth for the plan? | seven phases, ordered by the copy's findings | seven phases, ordered differently, with four small items | **Merged into one list of ten phases**, in section 5 of the plan document, each keeping the evidence of whichever reading found it |

### 0.2 The shape of the relation, in one paragraph

This package is not a library the application uses incidentally: it is the thing that decides which
PostgreSQL endpoint each read goes to, it owns the per-minute repair of the pooler's configuration,
and its `/health/db` endpoint is one of the tests a deploy has to pass. Concretely: the application's
scheduler registers `db:pgcat-flip` every minute and again through this package's own `FlipSchedule`;
the deploy gate's 5th and 6th tests run the application's connection probe with `--real-test` and
this package's `db:pgcat-flip --dry-run --json` inside the candidate container, and two of the deploy
channel's exit codes exist because of them; cron runs the schedule as `www-data` with `APP_ENV`
inside the `runuser` call, because unset it once aborted `schedule:run` before the flip was ever
invoked; the container's entrypoint pre-creates this package's state, lock, boot and audit files
under `/var/swrr` and widens the three pooler configurations to `g+w,o+w`; the application's
`config/database.php` weights two pgcat pools — the writer at 145.0 and the reader at 500.0, both on
`127.0.0.1:5433` — through this package's weighted picker; a CloudWatch alarm is installed on the log
line a cancelled read produces; and the application's own `docs/` cite this package's source by file
and line, which makes them the closest thing to an external audit of its internals that exists. In
the other direction, a production deploy failed its promotion gate on this package's flip rehearsal,
for two reasons this package could not name at the time (section 5.2).

## 1. The five ways a file there bears on this package

| | Relation | What it means | Weight |
|---|---|---|---|
| **A** | **It calls this package** | a script, schedule, gate or health check that runs `db:pgcat-flip`, `db:doctor`, `/health/db`, or the application's own probe where the package's doctor would be | the strongest: a change here breaks production |
| **B** | **It configures or pins this package** | the version constraint, the published `config/db-manager.php`, the `database.php` connection it weights, the connector every connection is built through | a divergence is a bug on one side |
| **C** | **It is evidence about this package** | a production log, a finding note, a recording, or an alarm that quotes this package's own rows, payloads and failures | the only external reading of this package's output there is |
| **D** | **It does this package's job at the app layer** | a command, a retry wrapper or a routing rule that exists because the package does not do it | the port candidates |
| **E** | **It is the environment this package runs in** | the pooler and its image, its three rendered variants, the RDS parameter groups and recordings, the credentials, the Redis, the deploy channel | context a port has to respect |

A path marked **\*** was reached by the tree reading only; **†** by the commit reading only; unmarked
means both readings reached it. Verdicts are what this document proposes, not what was done:
**port**, **docs**, **app** (belongs on the application side), **read**, **skip**.

## 2. The related commits

23 of the window's 39 commits touch something in section 3 — 12 that change how this package is
configured or called, or record a failure of it, and 11 adjacent.

### 2.1 Tier 1 — this package's own configuration, callers, and failures

| Commit | Date | Subject | Relation | What it does to this package |
|---|---|---|---|---|
| `89a76bc` | 2026-10-12 | Bump the weighted-dbmanager to alpha3 and arm its pool probe | B, A | the constraint moves `v0.2.0-alpha2` → `v0.2.0-alpha3`, the lock follows (reference `62b22644` → `33afd4e8`, content-hash `30b48bb8` → `c4f19d49`), and the published config gains the `pool_probe` block that turns the second self-heal **on** |
| `cc25625` | 2026-10-07 | Move the weighted-dbmanager constraint to alpha2 | B | `v0.2.0-alpha1` → `v0.2.0-alpha2`, relock only |
| `8bd2f9d` | 2026-10-09 | Default pgcat's flip schedule and boundary window to on, and widen the cadence to two minutes | B | three defaults in the published config: `schedule.enabled` unset → `true`, `interval_minutes` 1 → **2**, `windows.enabled` false → **true** — against a comment that says to arm one and disarm the other; the commit names three contradictions between that file's comments and its code and deliberately leaves the comments alone |
| `e03b1ec` | 2026-10-08 | Move the weighted replica manager's state out of the temp directory into a provisioned `/var/swrr` | B, E | the four paths this package writes move to `/var/swrr`, the lock renamed to `pgcat-flip-state.json.lock` beside the state it guards; `audit.store_probe_seconds` 60 → **120**; `entrypoint.sh` gains `setup_dbmgr_swrr` to provision them |
| `300d676` | 2026-10-09 | Let the pgcat flip actually run: re-enable `proc_open` and make the pooler configs writable | C, E | a real deploy's failure: the flip rehearsal died on `proc_open` in `disable_functions`, and the container logged that its flip target and its audit record were not writable |
| `352f267` | 2026-10-08 | Run the per-minute scheduler as `www-data`, so what it writes is owned by the user running the app | A, E | the entry that runs `db:pgcat-flip` moves to the application's own user, with `APP_ENV=prod` moved inside the `runuser` call because unset it made the schedule's mutex resolve to SQLite and abort `schedule:run` |
| `dce1a01` | 2026-10-09 | Retire the dead reader pools and renumber the live one on both sides | B, E | `reader_db_2` → `reader_db_1` in `pgcat.toml`, the dead blocks deleted, the connection's `read` array following; weights 13.0 → 1.0 and 21.0 → 10.0 |
| `faac421` | 2026-10-09 | Take `reader_db_3` out of the read path on both sides, and rebalance the writer's weight | B, E | the pool and its `read` entry retired in one commit, five minutes after they had briefly disagreed |
| `3665a26` | 2026-10-13 | Re-run a read a standby cancelled instead of handing the caller a 500 | D | the retry wrapper and its config; **the weights land here** — `writer_db_1` 2.5 → 145.0 and `reader_db_1` 10.0 → 500.0 |
| `1271d96` | 2026-10-13 | Answer the manifest and notification lists from the writer pool, behind a config flag | D | the routing rule and the pin, gated by `database.route_heavy_reads_to_writer` (env `DB_ROUTE_HEAVY_READS_TO_WRITER`, on by default) |
| `0c490fe` | 2026-10-14 | Write down which of the replica's two cancellations is which, with the recordings it derives from | C, E | the read path's two failures named, with the RDS recordings committed beside the record |
| `6badd23` | 2026-10-14 | Make a recovery conflict report itself instead of waiting to be queried by hand | C, E | two log metric filters and one alarm on the application's log group, because the application-visible consequence of a replica problem had no metric at all |

### 2.2 Tier 2 — adjacent: the reads this package routes, and guards of the same class

| Commit | Date | Subject | Relation | Why it is here |
|---|---|---|---|---|
| `00e1bc4` | 2026-10-14 | Name the request that climbs a php-fpm worker to `memory_limit` | D | the same instrument-instead-of-guess posture as this package's boot audit, on a different failure |
| `04ba6ca` | 2026-10-07 | Read the manifest listing from the unified view | D | the read the heavy-routing pin exists for |
| `6c26699` | 2026-10-08 | Paginate the manifest against a precomputed count column instead of counting rows | D | the `COUNT(*)` that made the read heavy enough to be pinned to the writer |
| `f6637cb` | 2026-10-08 | Stop casting two `jsonb` columns to `jsonb` in the manifest's raw SQL | D | the same query's other cost |
| `3d8240c` | 2026-10-13 | Read the manifest page count out of the row instead of handing over the row | D | the regression that override caused, and the 500s it produced |
| `8015f67` | 2026-10-09 | Detect the driver-vehicle relationship change explicitly, and refresh views with a deferred event | D | materialized-view refresh — the write side of the views those reads scan |
| `23061be` | 2026-10-08 | Harden the PHP runtime and the FPM pool, and record the one command this breaks | E | where `disable_functions` came from — the image-wide setting that later stopped the flip |
| `94b251a` | 2026-10-12 | Every session reads the behavioural rules from one file in the tree, not from a prompt | E | the application's rule-file consolidation and `.agents/verify.py` — the opposite decision from this package's, which keeps one file and has no generator |
| `e00a18b` | 2026-10-12 | Let a `path:line` entry excuse a file whose name contains a space | E | a guard-class bug in the application's own allowlist, in the family of this package's prose-path readers |
| `eb3d269` | 2026-10-08 | Commit the `machine.local.json` sample in token form, so a new clone has a starting point | E | the application commits a token-form sample of its machine record, including `__PACKAGE_DIR__` for *this* package's checkout |
| `da682d0` | 2026-10-12 | Update database configuration and add `orders-students` support | E | on the `lambda-func-01` branch: the Go function that refreshes the same database's materialized views for orders and students |

### 2.3 The other 15, and why they are not related

Image and build tuning (`a5a36cc` rlimit, `001fcdf` OPcache, `8de709f` framework cache, `0e74e0e` the
import commit that also carries the limits, `745c43a` the Postman manifest in the `Dockerfile`), the
certificate and dev-environment work (`52e98b1`, `d03d26b`, `6dd5005`), the Postman suite and its
guard (`aa91ce7`), the push and commit tooling (`ce626b8`, `405de47`, `f9511fb`), and application
behaviour with no database path in it (`79efc08`, `f78dc1f`, `99eb014`, `298f88d`). None of them
touches `config/db-manager.php`, the pooler configuration, the scheduler entry, the read path or the
deploy gates that read this package.

## 3. The inventory

### 3.1 It calls this package — relation A

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `site/app/Helpers/SchedulingHelpers.php` | the application's schedule; `setupPgcatFlipSchedule()` registers `db:pgcat-flip` `->everyMinute()->withoutOverlapping(2)->createMutexNameUsing(…gethostname()…)->name('pgcat_flip:<host>')->appendOutputTo(...)`, inside a schedule that calls `useCache('file')` | scheduling, with 64 KB of docblocks recording what went wrong before | the *other* registration of this package's command — the duplicate guard is the only thing keeping two entries from becoming two runs — and its mutex store is a file store, not the SQLite one that once aborted the run | read + docs |
| `docker-files/production/laravel/lpr-schedules` | the minutely cron entry; live lines: `runuser -u www-data -- env APP_ENV=prod php … artisan schedule:run` | cron, with the comment block on why `APP_ENV` must sit inside `runuser` | the entry that has to reach this package's command for the flip to happen at all | docs |
| `docker-files/production/laravel/flip-rehearsal.sh` | the deploy's 6th test: runs `artisan db:pgcat-flip --dry-run --json` in the candidate and fails the revision unless a JSON report declares `would_flip`, `would_not_flip` or `skipped` | a deploy gate over this package's own interface | the shape `--dry-run --json` must keep; it also detects an image whose `db:pgcat-flip` is undefined and says so | read |
| `docker-files/production/laravel/flip-rehearsal-selftest.sh` | 11 fixture cases over that gate — every verdict, non-JSON output, a missing command, a missing app root | fixtures written outside this package for this package's contract | a ready-made set of edge cases for `--dry-run`'s report | docs |
| `docker-files/production/laravel/pgcat-health-gate.sh` | the deploy's 5th test: supervisor state for the pooler, a TCP accept on `127.0.0.1:5433`, and one real query through the pinned connection (`artisan db:connection-diagnostics --real-test`) | a deploy gate that asks the pooler rather than the container | the pooler half of what this package's flipper and health endpoint report, and the option it needs from the probe that would be this package's | read |
| `docker-files/production/laravel/pooler-healthcheck.sh` | the ECS **container health check**: supervisor state plus a real TCP connect on the pooler's port, `--deep` adding `select 1` | the production answer to "is the pooler up" | what this package's `pgcat supervisor` row and `flip_window` report alongside; a package-side recipe should defer to it | docs |
| `docker-files/production/aws-settings/deploy-ecs.sh`, `inspect-task.sh` | the deploy channel: register a revision, gate it (tests 3–8, rc 5 = pooler gate, rc 6 = flip rehearsal), roll back | deployment | two of its exit codes exist because of this package's dry run and the application's connection probe; a change to either contract breaks a deploy | read |
| `docker-files/production/laravel/entrypoint.sh` | container start: renders three pooler configurations (two from one template), prunes unresolvable pools from all three, stamps the boot (`date +%s`), runs `config:cache`, and `setup_dbmgr_swrr` provisions `/var/swrr` (`0770`, `www-data`) with the five files seeded from `/dev/null` at `0664`, plus the flip's log, then widens the three `/etc/pgcat` variants to `g+w,o+w` | provisioning, and the other half of this package's file contract | it creates exactly the files this package writes — `pgcat-flip-state.json`, `…json.lock`, `container-booted-at`, `swrr-audit.json`, `swrr-audit.json.lock` — and is the reason a moved path is a silently unusable flipper | docs + port |
| `docker-files/production/laravel/smoke-test-image.sh`, `validate-image.sh` | the image gates: both run `php artisan db:connection-diagnostics` before booting anything | image validation against the application's own diagnostics | the place a *light* `db:doctor` would be run instead — the port that retires the clone rather than duplicating it | port |
| `docs/git-msg.txt` \* | a transcript of production log lines, including this package's own warnings: the `swrr.pgcat.gate` finding with `first_reported_at`/`resolved`, the store's fallback to the in-process SWRR store, and the "no `redis` binding yet" reason | a production record | the only recording there is of this package's boot-audit payloads as production emitted them, with the finding keys as keys | read |
| `scripts/php/verify-db-hosts.php` † | opens a TCP listener on the configured address and makes *real* database calls to record which host, port and pool each call uses; drives this package's `TimeWindowResolver` through `$app['db']->setTimeWindow(...)` | a hand-run integration probe, described in `TOOL-REGISTRY.md` | a hand-written version of a check this package could own — and it still asserts `['writer_db_1', 'reader_db_2', 'reader_db_3']`, a pool set retired on 2026-10-09 | port |
| `scripts/php/demo-pgcat-supervisor.php` † | a live check whose docblock demonstrates "what `db:doctor`'s new `pgcat supervisor` row says"; it calls `$kernel->call('db:doctor', ['connection' => 'pgsql_proxy'], $buffer)` | a demonstration built around this package's doctor output | an external consumer of the doctor's *row*, which is the interface a row change here breaks | read |
| `site/bin/verify-machine-local.py`, `site/.agents/toolchain.py` \* | the application's own machine-record and toolchain guards | guards | the same idea as this package's `.agents/machine.local.json` and its guards, in the other repository — no shared code, same contract | skip |

### 3.2 It configures or pins this package — relation B

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `site/composer.json`, `site/composer.lock` | the requirement `"uak35/laravel-weighted-dbmanager": "v0.2.0-alpha3"` † | version pinning, with the lock moved in the same commit because the application's own lock guard refuses a manifest its lock does not describe | the version the application runs on, and the fact that a *pin* is how it learns about this package at all | read |
| `site/config/db-manager.php` | the application's **published copy** of this package's sample config, 18.9 KB, comments included | configuration, and the record of three decisions this package's own comments contradict | the input to everything this package does at boot; it has diverged from the sample in thirteen keyed lines (section 5.1) | app + port |
| `site/config/database.php` | `pgsql_proxy`: `write` = one `writer_db_1` entry; `read` = `writer_db_1` weight **145.0** and `reader_db_1` weight **500.0**, both `127.0.0.1:5433`, `sticky => true`, `application_name` `lprSite`, options resolved at boot through `AutoWireCallbacks`; `route_heavy_reads_to_writer` (default true) | the connection this package weights | the input to the pick: a pick *between pools*, which pgcat then routes to RDS | read |
| `site/config/app.php` \* | `default_api_connection` (env `API_DB_CONNECTION`), a `timezone` from env, an AWS region key | application configuration | the connection this package *should* follow is this one, not `database.default` — and the published copy of `db-manager.php` resolves `swrr.connection` through a fallback chain that ends at the literal name `sqlite` | docs |
| `site/vendorOverrides/Illuminate/Foundation/Bootstrap/LprLoadConfiguration.php` \* | the application's replacement for the framework's configuration bootstrapper, with its own logging path | a framework override | the class that decides whether a cached configuration is read — the mechanism behind the sticky-config finding, and the reason a "config is cached" fact belongs in this package's report | port |
| `site/app/Helpers/ConfigHelpers.php` \* | the connection's dynamic values: an RDS IAM token provider, a Redis-cached token (key `aws-rds-auth-token`, TTL 900 s), username, SSL mode, and the options array the connection resolves to | credentials | the reason a password here can be a live token rather than a string — a report on the connection must print such a value as its type, which this package's doctor already does | read |
| `site/bootstrap/cache/{packages,services}.php` \* | the compiled provider and package cache, which names this package's provider | build output | evidence the provider is registered and is what boots the audit | read |
| `site/vendorOverrides/Illuminate/Database/Connectors/PostgresConnector.php` | the application's override of the framework's PostgreSQL connector, adding an `AutoWireCallbacks`-resolved option path | a framework override | every connection this package builds goes through a connector class; if the application has replaced it, the package's factory must resolve the application's class, or the connection's options are lost | port (a row naming the connector in use) |
| `site/vendorOverrides/Illuminate/Database/Enhanced/Postgresql/**` | a large app-side enhancement layer over the PostgreSQL grammar, schema and query builder — and the `select()` override the retry wrapper hooks into | framework overrides | the SQL a weighted read is expressed in goes through this layer, so a package change that pins or rewrites query builders has to survive it | read |
| `docker-files/production/laravel/pgcat.toml`, `pgcat-no-readers.toml` | the pooler's pool template (port 5433, `pool_mode = "transaction"`, `pool_size` 250, `min_pool_size` 25, ms timeouts, `server_tls` with the RDS CA bundle, one `[pools.<name>.users.0]` block per database with passwords spliced in at start) and the writer-only variant | pooler configuration | the files this package's flipper swaps between; the pools they declare are the `database` values the connection's `read` array names | port |

### 3.3 It is evidence about this package — relation C

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `docs/db-connection-resolution.md` \* (62 KB) | how the connection is decided, why a pgcat gate can silently judge SQLite, and what was done about it; §12–15 are the application's own probe command, its smoke runs and the one greppable `swrr.connection = <value>` line | a measurement record | cites this package's source by file and line, reads the boot-audit payload's keys, and documents the switch reading where `'off'` casts to true — the finding this package's switch reader exists for; §12 is written *because* `db:doctor` is too heavy for a build container | port + docs |
| `docs/pgcat-issues.md` \* (22 KB) | the 2026-09-28 crash-loop: a promoted image answered every request with `SQLSTATE[08006] … Connection refused` on 5433 while ECS, the ALB and the container health check all said healthy | a failure record | the failure this package's flipper, boot window and health endpoint were hardened against, told from the deploy side; §7's "the flip cannot rescue a pooler that is not running" is why the flip now has a `start_command` | docs |
| `docs/pgcat-boot-hardening.md` \* (36 KB) | the boot-time pool pruning, the per-minute flip and its window, the deploy rehearsal, the health check ECS reads, and the relationship between the three variants | a design record | a full external account of this package's `flip_window_seconds`, its `flock`, its `--status --json` window block and the `/health/db` field names — and §3.1 is explicit that one pair of variants must be identical and another must not | docs |
| `docs/replica-conflict-errors.md` | the read path's two cancellations, their topology, both parameter groups, the change history, the measured counts and the traps — nine sections, plus "what to change, cheapest first", open questions, and a re-derivation section naming the query behind each figure | a measurement record | the external account of the path this package routes to: two pools at 145.0 and 500.0 on `127.0.0.1:5433`, a writer capped at 120 s and a replica at 90 s, and its headline result is about this package's read choice rather than the application's code | docs |
| `docker-files/production/aws-settings/install-log-alarm.sh` | installs two log metric filters on the application's log group (`lpr-backend-conflict-with-recovery` → `LPR/Database RecoveryConflicts`, `lpr-backend-statement-timeout` → `StatementTimeouts`) and one alarm (`>= 1` per 300 s, `notBreaching`, published to the replica-alerts topic), with `--verify-only`; it proves its own patterns with `aws logs test-metric-filter`, because a wrong pattern reports a flat zero rather than failing | alerting, for a failure of this package's read path | the alarm that pages when a weighted read picks a replica that cancels it; the phrase is preferred to `40001` because `40001` also names a real serialization failure on the primary | docs |
| `docker-files/production/aws-settings/README.md` † | the deploy and health-gate family's own README, including why `/health-db` must not be derived from a cached route | operations documentation | states the surface this package publishes and the trap it exists to avoid | docs |
| `aws/data/rds/param-groups/timeoutgroup2/parameters.json` | the writer's group: `statement_timeout` **120000** ms | a captured parameter group | the longer of the two caps, and what makes an escalation to the writer able to answer a read the replica cut | port |
| `aws/data/rds/param-groups/timeoutgroup2-replica/parameters.json` | the replica's group: `statement_timeout` **90000**, `hot_standby_feedback` **1** (changed 2026-10-13), `max_standby_streaming_delay` 300000, `max_connections` 3470 | a captured parameter group, re-fetched because the recorded copy had gone stale | the tighter cap is the replica's, and `hot_standby_feedback` is the switch that decides whether this package's read picks are cancelled at all | port |
| `aws/data/rds/param-groups/timeoutgroup1/` \* | a third group whose `statement_timeout` is 600 ms | a captured parameter group | a group that will cut nearly every read it serves — the shape a doctor row for caps would catch | read |
| `aws/data/rds/lpr-database-4c-r7/{details,cancellation-evidence,hot-standby-feedback-check,cancelled-manifest-statement-plan}.json` | the replica's identity and class, the 30- and 7-day cancellation transcripts, the windows either side of the feedback change, and the plan of a cancelled statement | recordings every figure in the record cites | the endpoint this package's read picks reach, and the measurement that the conflict class ran at about 422 failed requests an hour until the parameter landed | read |
| `aws/data/rds/param-group-changes.json`, `aws/data/rds/{aws-rds-list-db-proxies,sizing-evidence}.json`, `proxies/lpr-database-3-proxy-3/`, the fleet and engine-option captures | the change history, the RDS Proxy that fronts the writer — named for one instance and targeting another — and the fleet | recordings | the write side of the same path; the record's own warning (do not read the proxy's name as its target) is the kind of thing a health report can get wrong | read |
| `docker-files/production/laravel/deployment-analysis/depl-anlyss-20260925_195342.md` \* | a pre-migration analysis of the deployment, file by file, with numbered findings — including that the published config is this package's, and that the rendered pooler configuration and the connection disagreed about which pools exist (F-05) | a deployment record | the field evidence for section 5.4, and the method — enumerate the files a deploy carries, then check the claims that cross the boundary between them — that this document uses | read |
| `docs/primary-temp-spill.md` \* | why the writer spills temp files through a 4 MB `work_mem`, and which statements do it | a measurement record | a heavy read routed to the writer is the `useWritePdo()`-shaped decision, and the spill is what that routing disturbs | docs |
| `site/.agents/statistics_windows.py` \* | a guard over the recorded PostgreSQL statistics: a cumulative counter may only be divided by its own family's clock | a guard, exiting 2 when there is nothing to judge | it exists because a rate was computed against the wrong window during the read-path investigation — the app-side analogue of this package's own prose-number discipline | read |
| `docs/TODO-database-tests.md` \* | the application's database-backed tests do not pass, item by item, with counts | a defect list | its item 2 is this package's environment fact: 8 tests fail because they read through pgcat and no pooler is listening on 5433 — the problem this package's own suite solves with a fixture pooler | app + docs |
| `docs/codebuild-pipeline.md` \* | what the build does, where the connection probe fits, and its known gaps | build documentation | a missing secrets mapping makes the gating connection probe skip silently — the green-build-unexamined-configuration shape this package's audit is written against | docs |
| `docs/deploy-troubleshooting.md` \* (35 KB) | failures met in the field, the 2026-09-29 rollback, and the Redis and WebSocket gates | a failure record | its §11 argues a Redis health surface must be separate from the database one so a failure names the dependency | docs |
| `docs/laravel-repo-schema/SKILL.md`, `site/.agents/skills/laravel-repo-schema/` \* | the schema skill: generate models, migrations and casts from the live database | agent tooling | adjacent rather than related — it reads the same PostgreSQL, but nothing in it touches this package | skip |
| `docs/tables.txt` \* | a bare list of the application's table names | a schema inventory | the same investigation's index | read |
| `docs/1.txt`, `docs/2.txt`, `docs/git-config-1.txt`, `git-config-2.txt`, `git-msg-1.txt`, `postman1.json`, `orders-history-api.md.txt`, `database*.php.txt` and the other `.txt` twins \* | working notes, a local container engine's address and port, personal git config, API collections, and older copies of files that also exist as themselves | strays | no relation to this package, and two of them are a machine's own configuration; the `database*.php.txt` copies are worth knowing about only because a stale copy of a connection config is the kind of file a reader mistakes for the live one | skip |

### 3.4 It does this package's job at the app layer — relation D

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php` (39 KB) | `db:connection-diagnostics [--json] [--strict] [--real-test]`: the environment file this boot read, whether config is cached, `swrr.connection` and the setting it came from, per connection the driver and its write/read endpoints, whether pgcat can front that driver, the pgcat switch "as written, as PHP's cast reads it, and as the package reads it", and a verdict whose exit is 1 on the states the armed configuration cannot work in — credentials printed as their type only; its JSON key set is fixed and always present | application diagnostics | a parallel of this package's `db:doctor`, and its docblock says why it exists: "`db:doctor` … also probes the store and asks supervisorctl, so it cannot run everywhere an image is built". The port is to remove the reason, not to keep two commands | **port** |
| `site/tests/Unit/DbConnectionDiagnosticsCommandTest.php` \* | the tests over that command, whose `--real-test` half is the only field proving the connection can answer | tests | the acceptance criteria a package-side equivalent inherits | port |
| `site/config/database-retry.php` + `site/app/Database/ConcurrencyReadRetry.php` | re-runs a `SELECT` a standby cancelled (40001) and sends a statement timeout (57014) to the write connection; `DB_READ_RETRY_ENABLED` (on), `attempts` 3, `base_delay_ms` 25, `fallback_to_primary`; only outside a transaction, because a cancelled statement inside one leaves it aborted | application resilience | this package chooses the endpoint a read lands on; the application has had to add the recovery layer for what that endpoint does. Port the knowledge (a record, and a row for the caps), not the retry | docs + port (staged) |
| `site/app/Database/HeavyReadRouting.php`, `site/app/Repository/Base/Implementations/BaseRepository.php` | `$routeReadsToWriter` (false everywhere, true on four view repositories) and the flag reader; the pin is Laravel's own `useWritePdo()`, read per query | routing policy for two heavy reads | its own docblock states the invariant this package should verify: a connection honours `useWritePdo()` **only where it has a `read`/`write` pair** — a fact about the connection this package builds | port |
| `site/vendorOverrides/Illuminate/Database/Enhanced/Postgresql/PostgresEnhancedConnection.php` † | the application's override of the enhanced connection's `select()`, calling the retry wrapper | an in-place override, `vendor/` untouched | every read funnels through `Connection::select()`, so this is where the application's recovery attaches to a path this package chose | read |
| `site/tests/Unit/Database/{ConcurrencyReadRetryTest,HeavyReadRoutingTest,PostgresEnhancedConnectionReadRetryTest}.php` \* | three files: the retry's decision against a callable that fails the way a replica fails, the wiring of the two heavy reads to the write PDO, and the proof that every read reaches the retry | tests | the application tests its own layer three ways where this package tests none of the routing's consequences; a ported capability needs its own fixtures here, not a copy of these | read |
| `site/app/Services/Route/ManifestViewService.php`, `site/app/Repository/Implementations/View*Repository.php`, `site/Schemas/Base/ViewManifest.php` | the manifest and notification lists: scans of `view_manifest` / `view_manifest_new`, PHP-side pagination, a precomputed count column, two `jsonb` comparisons | query work on the heaviest reads | the reads whose cost makes the weighted pick matter, and the reason two of them were pinned to the writer | read |
| `site/tests/db-views/pg/**.sql`, `site/tests/db-mat-views/pg/**.sql`, `site/tests/db-functions/pg/**.sql`, `site/tests/database.sqlite` \* | the database's own definitions — 47 views, 12 materialized views, 48 stored procedures — and a SQLite fixture | the schema the reads run against | the statements whose cost makes the routing decision matter, and the fixture that makes "no replicas" a passing read path in a test | read |
| `site/app/Services/DbViews/**`, `site/app/Database/QueryBuilders/**` | the application's view-backed services and query builders, including the two statements `docs/primary-temp-spill.md` names | query work | the reads whose cost makes the routing decision matter | read |
| `site/app/Helpers/MemoryProbe.php`, `site/app/Http/Middleware/API/LprHandleCors.php` † | one `error_log` line per request whose peak reaches a 64 MB floor, armed from a shutdown function so a fatal is still named | runtime instrumentation | not this package's business, but the same posture as its boot audit: name the failing thing in a line a log filter can find | read |

### 3.5 It is the environment this package runs in — relation E

| Path | What it is | Nature of work | Why it bears on this package | Verdict |
|---|---|---|---|---|
| `docker-files/production/laravel/pgcat-Dockerfile` \* | the pooler's own image: Rust and `build-essential`, the source cloned at a pinned `PGCAT_VERSION` tag (`v1.2.0`) and compiled in a builder stage, a minimal runtime stage, then the binary on the path | the pooler's build | the pooler's version is pinned and compiled rather than pulled, so the semantics this package's flipper, probe and health rows assume are that tag's — a package record about the deployment should name it, because a pgcat upgrade changes this package's runtime | docs |
| `docker-files/production/laravel/supervisor.d/pgcat.conf`, `supervisord.conf` | `[program:pgcat]`, `numprocs=1`, `autorestart=true`, `startsecs=3`, `startretries=5`, logs under `/app/logs` | supervision | the program name this package's flipper addresses as `pgcat:*`, and the reason a "not running" state has a `start` distinct from a `restart` | read |
| `docker-files/production/laravel/pool-prune-selftest.sh` \* | the regression test for the boot-time pruning: `getent` stubbed with a strict allowlist, assertions over the parser's names, and the silent failure it guards — a parser that picks up more or less than the bare hostname makes *every* address unresolvable and the prune a no-op | a regression test | the pruning half of this package's variant problem, with the fixture discipline a package-side check should copy, including a case for the no-op that looks like success | port |
| `docker-files/production/laravel/php/php.ini`, `php-fpm-www.conf` † | the hardened runtime: `disable_functions` gained `exec, shell_exec, system, passthru, proc_open, popen, show_source`; the FPM socket's owner, group and mode; `rlimit_files`; `decorate_workers_output` | image hardening | the image-wide list is what made the flip impossible until `proc_open` was removed from it | docs + port |
| `docker-files/production/laravel/{Dockerfile,Makefile,validate-image.sh,smoke-test-image.sh}`, `composer-refresh.sh`, `crontab`, `php/**`, `nginx/**`, `redis/**` | the image: build, the shell stage that checks every script for present/executable/`bash -n`/`--help`, the smoke gate's connection run, and the surrounding services; the `Dockerfile` records that the flip needs the lock, the source variant and supervisor's state to work here | build and packaging | the smoke gate is where a package-side light doctor would be run instead of a clone (3.1) | port (see the plan's phase 5) |
| `docker-files/production/redis/redis.conf`, `docker-files/production/redis/{Dockerfile,entrypoint.sh,healthcheck.sh}` \* | the Redis the application runs: 16 logical databases, `appendonly no`, no `maxmemory` (so no eviction policy to lose a key to), and the store this package's state lives in | infrastructure | this package's `primary_store` is a Redis connection with a documented fallback, and `docs/git-msg.txt` records that fallback firing in production — the weighted state and the flip's state survive only as well as this instance's memory, and the token cache shares the process | read |
| `docker-files/production/laravel/get-cached-db-token.sh` | reads an RDS IAM auth token out of Redis (`aws-rds-auth-token`) for the connection's options | credentials | a token with a lifetime, cached, resolved at boot — a report on the connection should not print it as a password, and does not | read |
| `aws/data/ecs/task-defs/lpr-backend-sites-tdef-01/{details,revisions-list}.json` and the other three task definitions \* / † | the task definitions, including the health check and the `secrets` block | the task this package runs in | shows how the pooler health check reaches the container and how a database credential arrives | read |
| `docker-files/production/aws-settings/install-rds-credit-alarms.sh` \* | installs CloudWatch alarms on RDS CPU credits | infrastructure alerting | a burstable replica that spends its credit every day is an undersized read endpoint — the operational half of "is the replica the right size" | docs |
| `docker-files/production/aws-settings/install-memprobe-metrics.sh` \* | a memory-probe metric and its alarm for the container | infrastructure alerting | indirect: the container's health is what the deploy gate reads | skip |
| `aws/commands/rds-replicate-create-commands.txt` and the other `aws/commands/*` | eleven attempts at creating a read replica, with the class, the AZ, the parameter group and the outcome of each | an operations transcript | the replica fleet this package weights, built by hand. **Its lines carry this machine's own workspace path**, which is why nothing here quotes them | read |
| `docker-files/production/laravel/processWatchdog`, `processWatchdog-config.ini`, `supervisor.d/*.conf`, `deployment-analysis/**` | a process watchdog, the workers, and a deployment analysis | process supervision | context for where the flipper sits among the container's processes | read |
| `site/.agents/skills/lpr-deploy/SKILL.md`, `.agents/commands/lpr-deploy.md` \* | the deploy runbook: the promotion gate's numbered tests (5 = pooler gate, 6 = flip rehearsal), the `pgcat-logs` command that reads the pooler's log out of a task, and the guardrails | an operations runbook | the procedure that depends on this package's dry run and the connection probe; its log-reading half is what an operator needs when the pooler is the fault | docs |
| `site/.agents/skills/{laravel-dev,lpr-commit,lpr-push,php-code-analyzer,skill-creator}/`, `site/.agents/{code,commands,instructions,prompts,agents}/**`, `site/.agents/*.py`, `site/.agents/types/**` | the application's agent tooling: skills, a validator for the schedule's per-day keys, the statistics-window guard above, an evidence-note guard, a stale-index guard, a toolchain record | agent tooling | not this package's, but two are *guards* worth reading for shape: one that refuses a record whose numbers are not backed by a recording, and one that keeps the schedule's key vocabulary checked rather than hand-written | read |
| `site/tests/Helpers/**`, `site/tests/Factories/**`, `site/tests/Feature/**`, `site/tests/HttpRequests/**` \* | the rest of the application's suite: helpers, factories, feature and HTTP-request fixtures | tests | the fixtures a database-backed test needs, which is the half this package's own suite supplies for itself | skip |
| `.postman/**` (890 files), `postman/**`, `aws/data/elbv2/**`, `aws/data/codebuild/**`, `.idea/**`, `.trae/**` | the imported API suite, load-balancer and build captures, and editor and agent configuration | imports and tooling | none of it touches this package | skip |

## 4. The divergences, reconciled

Five, each with the command or the file that shows it. Every one is reproducible: the tree reading
from the copy, the commit reading from `git -C lpr-git/laravel.git`.

### 4.1 The application's published config against this package's sample

```
git -C lpr-git/laravel.git show changes-by-umar:site/config/db-manager.php > lpr-git/app-db-manager.php
diff <(grep -E "=>" lpr-git/app-db-manager.php) <(grep -E "=>" config/db-manager.php)
```

Thirteen keyed lines differ at the tip. Seven are the application's installation decisions; six are
defects or gaps. The tree reading's older version of this table is folded in where it adds a
distinction the tip's alone does not show.

| Key | The application's copy | This package's sample | Reading |
|---|---|---|---|
| `pgcat.enabled` | `(bool) env('SWRR_PGCAT_ENABLED', true)` | `env(…, false)` | a `(bool)` cast in front of the switch this package reads as a switch: `'off'` becomes `true` before the package sees it — the defect the switch reader exists for — and the flipper is armed by default there |
| `swrr.allow_local_fallback` | `(bool) env(…)` | uncast | the same defect, one key over |
| `swrr.connection` | `env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'sqlite'))` | `env('SWRR_CONNECTION')` | left unset here the package follows `database.default`; the application's copy resolves to the literal connection name `sqlite` when neither variable is set, so this package's surfaces judge a SQLite connection while every real query runs on `pgsql_proxy` |
| `schedule.enabled` | `true` | `false` | the per-minute schedule is armed by default there |
| `schedule.interval_minutes` | `2` | `1` | against a comment in the same file that still says 1 is the shipped value, while `SchedulingHelpers` registers the command every minute regardless — the duplicate guard is what keeps one entry from becoming two |
| `schedule.windows.enabled` | `true` | `false` | **the contradiction**: both mechanisms are armed, eight lines under a comment that says "LEFT OFF, AND DELIBERATELY SO. Turn this on and turn `enabled` above off in the same change: two mechanisms deciding one mode is two mechanisms that can disagree" |
| `schedule.stale_after_missed_runs` | absent | present (5) | the key is in this tree and not in `HEAD` — unreleased, so the application cannot have it; it arrives with the next publish |
| `pool_probe.enabled` | `true` | `false` | the pool probe is armed there — which is what alpha3 was taken for |
| `pool_probe.port` | `5432` | `6432` | the probe is pointed at PostgreSQL's own port rather than the pooler's; this package's `PoolProbe` limitation note is about exactly that |
| `pgcat.state_file`, `lock_file`, `boot_file` | `/var/swrr/…` (the temp spellings commented above them) | `sys_get_temp_dir()` | an installation decision, and a good one — it matches what `entrypoint.sh` provisions, down to the lock's `…state.json.lock` name — but it is a silently unusable flipper if a later move misses one of the three |
| `audit.file` | `/var/swrr/swrr-audit.json` | temp | the same |
| `audit.store_probe_seconds` | `120` | `60` | half as many store PINGs as the shipped default — the one setting that keeps the audit off the request path |
| the comment over `pool_probe` | the pooler "does not authenticate before it looks the pool up" | a pooler that authenticates *before* resolving a pool answers *every* name | the published copy states the older, stronger claim — the one this package's own code writes down as a limitation |

The pin behind all of this is `site/composer.json`'s `v0.2.0-alpha3`, moved there by `89a76bc` and
before it `v0.2.0-alpha2` by `cc25625`.

**Verdict:** an application-side republish, and a package-side way to *know* the copy is old — the
plan's phase 1.

### 4.2 A production container that was healthy and could not flip

`300d676` quotes the deploy's own output. The promotion gate failed at reason 6:
`supervisorctl status "pgcat:*" did not answer in time: The Process class relies on proc_open, which
is not available on your PHP installation`, and the report's own verdict was `rename refused never
reached`. The other four gates passed — rollout `COMPLETED`, task `HEALTHY`, db-health `OK`, and the
**pooler gate `GATE PASSED`** (pgcat RUNNING, `127.0.0.1:5433` accepting, a real query answering) —
and the change was rolled back. The container served traffic correctly; what it could not do was
*change* the pooler's configuration. Two of this package's own warnings were in that container's log:
`flip target [/etc/pgcat/pgcat.toml] is not writable, so a flip may not get its copy into place` and
`the record at /var/swrr/swrr-audit.json cannot be written, and it is what throttles the probe, so
the PING is skipped`. The fix widened all three variants to `g+w,o+w` and took `proc_open` back out of
`disable_functions`.

This package already has both readings — `PgcatConfigFlipper::FILE_UNWRITABLE` is judged during a
flip attempt, and `DbDoctor` reports the audit record's writability — but they are taken at the
moment of failure, one path at a time, and the supervisor step reports a spawn failure as a timeout.
Nothing reports, *before* a deploy rehearses a flip, that the installation cannot write the files a
flip needs; and `grep -rn "proc_open" src/` finds nothing here, so the disabled function is not a
cause this package can name.

### 4.3 The application keeps a lighter doctor of its own, and says why

`site/app/Console/Commands/DbConnectionDiagnosticsCommand.php`, docblock lines 37–39: "`db:doctor`
judges the same gate, but it also probes the store and asks supervisorctl, so it cannot run
everywhere an image is built." Two of the facts it prints are ones this package's `db:doctor` does not
print at all: **which environment file this boot read**, and **whether the configuration was compiled
to a cache file** — and the class that implements that stickiness is the application's own
configuration bootstrapper. Two image gates already run it (`smoke-test-image.sh`,
`validate-image.sh`), the deploy's pooler gate runs it with `--real-test`, and a script exists whose
only purpose is to demonstrate this package's `pgcat supervisor` row beside it.

**Verdict:** port. Not the command — the capability, so a build gate can run this package instead.

### 4.4 The pool set is one fact stated twice

The pooler's configuration declares pools; the connection names them in its `database` field; and
"a rename on one side without the other leaves reads routed to a pool that does not exist — a failed
connection, not a silent one". Three things have happened around that fact:

* the 2026-09-25 deployment analysis recorded it **broken** (F-05): `writer_db_1` commented out of the
  rendered configuration while the write connection targeted it, a readers variant rendered twice
  under two names, and supervisor launching the file without the writer pool — and it recorded the
  aftermath, "no supported way to switch `pgcat.toml` between reader/writer", which is the gap this
  package's flipper now fills;
* by the 2026-10-14 reading the fact was consistent — `pgcat.toml` declares `writer_db_1` and
  `reader_db_1`, the writer-only variant declares `writer_db_1` alone, and those are exactly the
  `database` values the connection's `write` and `read` entries name — but it had disagreed again in
  between, for five minutes on 2026-10-09: `pgcat.toml` edited at 03:06, the connection at 03:11;
* and the application's own hand-run checker, `scripts/php/verify-db-hosts.php`, still asserts
  `['writer_db_1', 'reader_db_2', 'reader_db_3']` — a pool set retired on 2026-10-09 — while the
  pool-prune selftest asserts the same retired names. Both are run by hand, neither is in CI, and both
  will report the wrong expectations to whoever runs them next.

Nothing in this package compares the two columns today, and an applied mode is only usable if they
agree; this package's `PoolProbe` asks the pooler about the pools a *variant* declares, not about the
pools the *connection* declares.

**Verdict:** port. This package can answer the question the hand-run script asks.

### 4.5 The read path's two cancellations, the caps that differ, and who answers them

Writes go through the pooler at weight 145.0 to the writer and then through an RDS Proxy to the
primary; reads go at weight 500.0 to a `db.t4g.large` replica. The replica cancels reads two ways:
40001 while applying WAL removes row versions a query's snapshot still needed, and 57014 at its own
`statement_timeout`. The two sides are capped differently **on purpose** — the writer at 120 000 ms,
the replica at 90 000 ms — which is the whole reason an escalation to the writer can answer a read the
replica cut. The replica carried **no** `hot_standby_feedback` until 2026-10-13, and while it did not,
the conflict class ran at about 422 failed requests an hour (1 618 failures in 3 h 50 m over 6 472
matching log lines); it stopped within a minute of the parameter being applied and stayed at zero for
four hours while traffic continued, and a much smaller statement-timeout class appeared in its place
(4 996 matching events in the hour before the update, 0 in the 102 minutes after, 28 timeout lines
after). A log metric filter and an alarm now watch the first class.

The application answers both failures in a wrapper — `DB_READ_RETRY_ENABLED` on, 3 attempts, 25 ms
base delay, `fallback_to_primary`, never inside a transaction, because a cancelled statement
there leaves the transaction aborted — and pre-empts both for two named reads by pinning them to the
write pool behind `database.route_heavy_reads_to_writer`.

This package picks the endpoint. It does not re-run anything, does not know the two caps differ, does
not report that a read it routed to a standby can be cancelled at all, and does not ask whether a
standby has `hot_standby_feedback` on. The knowledge belongs here because the routing decision is
this package's; the resilience does not obviously belong here, because re-running a `SELECT` inside
the application's own transaction handling is the application's business.

**Verdict:** docs and probe now; a retry facility later, and only if asked for.

## 5. The port plan

Ten phases, each with its evidence, what "done" means, and the check that proves it, are in
[site-to-package-port-plan.md](site-to-package-port-plan.md) — kept in its own document so the
planning can be worked through and amended without re-reading the evidence above.

## 6. What neither reading could answer

* **The application's top-level `app/` and `config/` were never copied**, so "the app and config
  folders" was answered with `site/app` and `site/config`, which is what arrived. If the intent was
  the top-level pair as well, say so and the same reading follows.
* **`CHANGELOG.md`, `SCRIPTS.md` and `TOOL-REGISTRY.md` were not read.** The registry matters most: it
  catalogues the application's own scripts, which is the shape a package-side inventory of *its*
  `bin/` and its consumers could follow. `TOOL-REGISTRY.md` is also a live document — one commit says
  its new script is not yet registered and that the row is owed.
* **The application's test policy is only half visible.** `site/tests` was in the copy and the files
  in 3.4 were read, but the skip variable `docs/TODO-database-tests.md` describes, and the pre-commit
  notice it says must be deleted with it, are described by that record alone.
* **The branch map is read only where the window reaches.** Five branches hold other projects, and
  their database work — if any — is outside the eight days searched. `lambda-func-01` is the one that
  carried a change (`da682d0`).
* **The copy was a moment, not a history; the clone is a history, not a tree.** Where they overlap,
  section 0.1 says which reading wins. Neither answered a question about the other's blind spot:
  nothing here says what the application's code did *before* 2026-10-07, and nothing says what the
  tree looked like on a file the window never touched except as the copy had it.
* **Nothing is quoted from the files that name this machine** — the AWS command transcripts, the local
  git configurations, or the machine-local records.

## 7. What was not done, deliberately

* **No file outside this package's root was read, listed or written.** The tree reading read
  `lpr-compare/` only; the history reading read `lpr-git/` only; the sibling application checkout was
  never opened.
* **No LPR file was copied into `src/`, `config/`, `tests/`, `bin/` or `docs/`.** What gets ported is
  a capability re-expressed in this package's idiom with this package's own tests, not a file moved
  across.
* **No phase was implemented.** The two documents are the deliverable.
* **Nothing was committed.** `lpr-git/` and its `.gitignore` line are scratch — the evidence behind
  the commit reading — and should be removed together when this reading is finished with. The tree
  copy's folder was already removed for the reason section 0 gives.

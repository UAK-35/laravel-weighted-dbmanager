# What the site can remove, and what it must edit

Before implementation: which files the consuming application can delete because this package now
owns what they do, which files need lines changed to drop redundant or defective code, and the
altogether-code for each change.

**Source and how to check it.** Every line number below is that file's own line at branch
`changes-by-umar`, tip `00e1bc415eb01b600b398057e79b5697c30b6a35` (2026-10-14), in the clone this
package's session read at `lpr-git/laravel.git`. Reproduce any of it with:

```bash
git -C lpr-git/laravel.git show changes-by-umar:<path> | sed -n '<start>,<end>p'
```

Three verdicts, and nothing in between:

| | Verdict | Meaning |
|---|---|---|
| **REMOVE** | a file can go | nothing in the build, deploy, test or request path calls it, and this package covers its subject |
| **EDIT** | lines change | the file stays; the numbered lines are deleted or replaced, with the replacement written out |
| **KEEP** | looks removable, is not | deleting it breaks something concrete, which is named |

**The rule this list obeys.** Nothing is removed before the thing that replaces it exists and its
check passes. That is why most of the removals are conditional, and why the order in section 5 is
part of the answer rather than a suggestion.

---

## 1. Removable now — nothing calls it

### 1.1 `scripts/php/demo-pgcat-supervisor.php` — REMOVE (194 lines)

A hand-run demonstration: it stands up stand-in processes and calls
`$kernel->call('db:doctor', ['connection' => 'pgsql_proxy'], $buffer)` to show what this package's
`pgcat supervisor` row reports. Its subject is this package's own row, which this package's suite
covers against real fixtures; the application adds no coverage with it.

Its only references outside itself are documentation — `TOOL-REGISTRY.md:111`,
`SCRIPTS.md`, and a `CHANGELOG.md` entry — and no build, deploy, cron or test path runs it:

```bash
git -C lpr-git/laravel.git grep -ln 'demo-pgcat-supervisor' changes-by-umar
```

**Remove with it:** the `TOOL-REGISTRY.md:111` row (leave the `CHANGELOG.md` entry — it is history).
Delete the `SCRIPTS.md` row if one names it.

**Risk:** none to the site's working. It is a tool, not a dependency.

### 1.2 Nothing else qualifies today

The honest answer to "what can go right now" is one file. Every other candidate is either something
this package does not yet do (section 2) or something that only looks redundant (section 3) — and the
ones that look most redundant are the ones the outages of the last three weeks were survived in.

---

## 2. Removable once a named phase lands — with the order that makes it safe

### 2.1 `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php` (947) and its test (133) — REMOVE after this package's phase 5

This command exists for one stated reason, in its own docblock (lines 37–39): "`db:doctor` judges the
same gate, but it also probes the store and asks supervisorctl, so it cannot run everywhere an image
is built." Once `db:doctor` has a bounded mode (`--connection-only`, this package's plan phase 5) and
that mode carries the one thing the deploy gate needs from this command — a real `select 1` on the
pinned connection — the two commands are one command, and this one is the copy.

**Remove with it:** `site/tests/Unit/DbConnectionDiagnosticsCommandTest.php` (133 lines).

**Before removing, repoint its four callers** — these are the order, and each is an EDIT in section 4:

| Caller | Line | What it needs |
|---|---|---|
| `docker-files/production/laravel/pgcat-health-gate.sh` | 221 | the real-query run, and the two sentences at 226 and 239 that name the command |
| `docker-files/production/laravel/smoke-test-image.sh` | 325 | the informational run; the parse at 333 needs no change (section 4.6) |
| `docker-files/production/laravel/validate-image.sh` | 54 | a comment naming the command |
| `buildspec.yml` | 22 | a comment; the build reaches the command only through `validate-image.sh` (invoked at `buildspec.yml:128`) |

**Documents that cite it** (`CHANGELOG.md:3074` is history and stays; the rest are EDITs in 4.7):
`docs/codebuild-pipeline.md:49,105`, `docs/db-connection-resolution.md:498,500,505,582,583,586,743,854`,
`docs/pgcat-issues.md:211`, `site/.agents/skills/lpr-push/SKILL.md:323`,
`site/.skills/lpr-push/instructions.md:317`.

**Risk if removed early:** the promotion gate loses its connection proof. `pgcat-health-gate.sh`
currently *fails a deploy* on this command's absence or on an exit 1 — which is the behaviour to keep,
pointed at the package's command instead.

### 2.2 `scripts/php/verify-db-hosts.php` (681) — REMOVE after this package's phase 8

It answers "which host, port and pool does each database call actually use" by making real calls, and
it drives this package's own `TimeWindowResolver`. This package's plan phase 8 gives the package the
same reading (the pools the connection names against the pools the configuration declares), at which
point the application's hand-run copy is the duplicate. Until then it is **broken rather than
redundant**: line 142 asserts a pool set retired on 2026-10-09, so its replica path reports a failure
that no longer exists. Repair it (4.5) if it is kept.

---

## 3. Files that look removable and are not — KEEP, with the reason

| Path | Why it looks redundant | Why it stays |
|---|---|---|
| `site/app/Database/ConcurrencyReadRetry.php`, `site/config/database-retry.php` | this package already picks the endpoint, so the application "should not need" a retry | the package does not re-run a statement. Without this, every read a standby cancels (SQLSTATE 40001) is a 500 — measured at about 422 an hour while the replica ran without `hot_standby_feedback` |
| `site/app/Database/HeavyReadRouting.php`, `BaseRepository::$routeReadsToWriter` | a routing rule next to a package that routes | the package has no heavy-read policy; the flag and the four opted-in repositories are the application's own judgement about its queries |
| `site/tests/Unit/Database/{ConcurrencyReadRetryTest,HeavyReadRoutingTest,PostgresEnhancedConnectionReadRetryTest}.php` | tests of the two above | they are the only tests of that layer |
| `docker-files/production/laravel/flip-rehearsal.sh`, `flip-rehearsal-selftest.sh` | the package has its own dry run | they are the *deploy gate* around it: verdict checking, the "this image predates the flip" detection, and 11 fixtures |
| `docker-files/production/laravel/pgcat-health-gate.sh`, `pooler-healthcheck.sh` | superseded by `/health/db` | they ask supervisor and the port, not a route through the application — the 2026-09-28 outage was survived by exactly that difference |
| `docker-files/production/laravel/entrypoint.sh` → `setup_dbmgr_swrr` | the package could create its own state files | it is the container half of the package's file contract: the five files seeded `0664` before the first request, the directory `0770`, and the three pooler configurations widened. Without it the flipper's first run finds nothing to write |
| `docker-files/production/aws-settings/install-log-alarm.sh` | the package routes the reads, so it "should" alert | it is the only thing that turns a cancelled read into a metric; this package publishes no alarm |
| `site/routes/api.php:33` and the import at `:26` | an application route for a database health endpoint | it is **already this package's** controller (`Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController`) — there is nothing of the application's to delete here |
| `site/app/Http/Controllers/APIV2/{RedisHealthController,WebsocketHealthController}.php` | three health endpoints for one container | separate dependencies by design: a failure has to name the dependency that failed |
| `site/app/Helpers/ConfigHelpers.php`, `docker-files/production/laravel/get-cached-db-token.sh`, `site/vendorOverrides/Illuminate/Database/Connectors/PostgresConnector.php` | connection plumbing the package handles | they supply what the package *reads*: the IAM token, its cache, and the connector every connection is built through |
| `docker-files/production/laravel/pool-prune-selftest.sh` | a test for a boot step | it guards the prune that keeps one unresolvable address from stopping the pooler starting — it needs a rename (4.4), not deletion |

---

## 4. Modifications, line by line, with the alternate code

### 4.1 `site/config/db-manager.php` — six edits and four dead comment lines

**Line 34 — the connection fallback ends at a driver name.**

```php
// before
        'connection' => env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'sqlite')),
// after
        'connection' => env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'pgsql_proxy')),
```

Removing the chain entirely is **not** the fix here: this package's unset behaviour is to follow
`database.default`, and this application's `database.default` is SQLite while its data path is
`pgsql_proxy`. The defect is the terminal name, and the replacement names the connection the
application actually queries. (The package's own sample carries no fallback because a sample cannot
know a consumer's connection names; this line is the consumer's, and it is right for it to be
explicit.)

**Line 40 — a cast in front of a switch.**

```php
// before
        'allow_local_fallback' => (bool) env('SWRR_ALLOW_LOCAL_FALLBACK', true),
// after
        'allow_local_fallback' => env('SWRR_ALLOW_LOCAL_FALLBACK', true),
```

`(bool) 'off'` is `true`: the cast converts the spellings an operator writes into their opposite
before the package reads them. The package reads this key as a switch and reports a value it cannot
read; the cast is what stops that reading from ever happening.

**Line 66 — the same cast, on the switch that arms the flipper.**

```php
// before
            'enabled' => (bool) env('SWRR_PGCAT_ENABLED', true),
// after
            'enabled' => env('SWRR_PGCAT_ENABLED', true),
```

The default stays `true` — this site has been running with the flipper armed since 2026-10-09 and
nothing here changes that. Only the reading changes: `SWRR_PGCAT_ENABLED=off` will now mean off, and
a typo will be reported instead of being silently true.

**Line 112 — the pool probe points at the wrong port.**

```php
// before
                'port' => (int) env('SWRR_PGCAT_POOL_PROBE_PORT', 5432),
// after
                'port' => (int) env('SWRR_PGCAT_POOL_PROBE_PORT', 5433),
```

Neither candidate default is this installation's port: 5432 is PostgreSQL's own, where any server
answers and the probe proves nothing, and the package sample's 6432 is pgcat's conventional default,
which this container does not use. This site's pooler listens on **5433** (`pgcat.toml`'s port and the
port every connection in `database.php` names). The probe sends a startup packet and no credentials,
so the `user` beside it is immaterial. If the pooler's documented answer-every-name behaviour makes
this signal untrustworthy to you, the alternative is to stop relying on it rather than to leave it
pointed at the wrong socket:

```php
// alternative, if the probe is not to be trusted here
                'enabled' => env('SWRR_PGCAT_POOL_PROBE_ENABLED', false),
```

**Lines 162 and 212 — two mechanisms own one mode.**

```php
// line 162: unchanged — the schedule stays armed
                'enabled' => env('SWRR_PGCAT_SCHEDULE_ENABLED', true),
// line 212: back to off, which is what the comment above it already says
                    'enabled' => env('SWRR_PGCAT_WINDOW_FLIP_ENABLED', false),
```

The comment above line 212 reads "LEFT OFF, AND DELIBERATELY SO. Turn this on and turn `enabled`
above off in the same change: two mechanisms deciding one mode is two mechanisms that can disagree,
and a flip this pair applies is deliberately not recorded as a boot convergence, so the two do not
even share a record of what they did." The code says `true`, so today both mechanisms run. Setting it
back to `false` makes the code agree with the comment and leaves the boot-time convergence — the one
whose results are recorded — owning the mode. If you would rather have the window tasks own it, invert
the two lines instead (163-side `false`, 212-side `true`) and disarm nothing else; what must not
remain is both.

**Line 175 — the cadence, only if the every-minute behaviour is wanted.**

```php
// before
                'interval_minutes' => env('SWRR_PGCAT_SCHEDULE_INTERVAL_MINUTES', 2),
// after — preserves the one-minute cadence the application's own registration has today
                'interval_minutes' => env('SWRR_PGCAT_SCHEDULE_INTERVAL_MINUTES', 1),
```

Relevant only together with 4.2: once the application's own registration goes, this key is the only
thing setting the cadence. The file's own comment still says 1 is the shipped cadence, and the
`flip_window_seconds` of 480 holds eight runs at one minute against four at two — which is the reason
to keep it at 1 unless the wider cadence is deliberate.

**Lines 87, 88, 143, 255 — dead alternates. DELETE these four lines.**

```php
87:  //'state_file' => sys_get_temp_dir() . '/pgcat-flip-state.json',
88:  //'lock_file' => sys_get_temp_dir() . '/pgcat-flip.lock',
143: //'boot_file' => env('SWRR_PGCAT_BOOT_FILE', sys_get_temp_dir() . '/container-booted-at'),
255: //'file' => env('SWRR_AUDIT_FILE', sys_get_temp_dir() . '/swrr-audit.json'),
```

They record where the state lived before 2026-10-08, and the live lines below each of them say where
it lives now. Nothing reads them, and a commented path beside a live one is the shape a reader copies
by mistake. Their history is in the commit that moved them (`e03b1ec`).

### 4.2 `site/app/Helpers/SchedulingHelpers.php` — the application's own flip registration is now a duplicate

This package registers `db:pgcat-flip` itself the first time the schedule is resolved, guards against
an entry already carrying its configured name or already running the command, and takes its cadence
from `swrr.pgcat.schedule.interval_minutes`. The application's registration predates that and is now
the entry the guard exists to tolerate.

**DELETE lines 129–224** — the docblock whose heading is "The pgcat config flipper, once a minute."
(129–201) and the method `setupPgcatFlipSchedule()` (202–224).
**DELETE line 126** — `self::setupPgcatFlipSchedule($schedule, $logFolder);`

Neither leaves a hole, and three things stay identical:

* **the log file.** The application's `$logFolder` is `storage_path('logs/scheduled_tasks')` (line
  65), and the package's default log path is `storage_path('logs/scheduled_tasks/pgcat_flip.log')` —
  the same file, which `entrypoint.sh`'s `setup_dbmgr_swrr` pre-creates for exactly that path;
* **the mutex store.** `$schedule->useCache('file')` (line 124) is set on the `Schedule` instance, so
  the package's entry inherits it — and the package names its mutex per container, which is what the
  deleted docblock argued for;
* **the command.** `db:pgcat-flip`, unchanged.

**Alternate code — the tail of `setupScheduling()`, lines 124–127 before and after:**

```php
// before                                          // after
        $schedule->useCache('file');                   $schedule->useCache('file');
                                                       }
        self::setupPgcatFlipSchedule($schedule, $logFolder);
    }
```

**Two visible differences, so they are not surprises:** the task name in `schedule:list` becomes
`pgcat_flip` (the package's `swrr.pgcat.schedule.name`) instead of
`pgcat_flip:api.lprstudenttransport.com`, and the cadence becomes the config's — set line 175 to 1
per 4.1 to keep it a minute. `$logFolder` remains used by the five other setups (lines 103–107), so
nothing else in the file changes.

### 4.3 `docker-files/production/laravel/entrypoint.sh` — the commented alternates in the provisioning function

**DELETE these eleven lines**

```
1516:    #touch /var/swrr/container-booted-at
1517:    #touch /var/swrr/pgcat-flip-state.json
1518:    #touch /var/swrr/pgcat-flip-state.json.lock
1526:    #touch /app/site0/html/storage/logs/scheduled_tasks/pgcat_flip.log
1527:    #chown www-data:www-data /app/site0/html/storage/logs/scheduled_tasks/pgcat_flip.log
1528:    #chmod g+w,o+w /app/site0/html/storage/logs/scheduled_tasks/pgcat_flip.log
1532:    #touch /app/site0/html/storage/logs/swrr-audit.json && chown lprFtpLvl01:www-data ...
1533:    #install -o www-data -g www-data -m 0664 /tmp/swrr-audit.json
1534:    #touch /tmp/swrr-audit.json && chmod o+w,g+w /tmp/swrr-audit.json && chown root:www-data ...
1535:    #touch /tmp/swrr-audit.json && chmod 0644 /tmp/swrr-audit.json && chown root:www-data ...
```

Each is superseded by a live line immediately below it: the `install -d`/`install … /dev/null` block
that replaced the three `#touch` lines, the uncommented trio for the schedule log, and the `/var/swrr`
spelling that replaced both `/tmp` attempts and the `lprFtpLvl01`-owned variant. They are a record of
what was tried; the record that matters is the commit, and four commented variants of one path beside
the live one is how the wrong path gets copied.

**Alternate code — the function with them gone:**

```bash
setup_dbmgr_swrr() {
    local SITE_PATH="$1"

    install -d -o www-data -g www-data -m 0770 /var/swrr
    install -o www-data -g www-data -m 0664 /dev/null /var/swrr/pgcat-flip-state.json.lock
    install -o www-data -g www-data -m 0664 /dev/null /var/swrr/pgcat-flip-state.json
    install -o www-data -g www-data -m 0664 /dev/null /var/swrr/container-booted-at
    install -o www-data -g www-data -m 0664 /dev/null /var/swrr/swrr-audit.json
    install -o www-data -g www-data -m 0664 /dev/null /var/swrr/swrr-audit.json.lock
    chown www-data:www-data /var/swrr/swrr* && chmod g+w,o+w /var/swrr/swrr*

    touch "$SITE_PATH"/storage/logs/scheduled_tasks/pgcat_flip.log
    chown www-data:www-data "$SITE_PATH"/storage/logs/scheduled_tasks/pgcat_flip.log
    chmod g+w,o+w "$SITE_PATH"/storage/logs/scheduled_tasks/pgcat_flip.log

    chown www-data:www-data /etc/pgcat/pgcat.toml /etc/pgcat/pgcat-readers.toml /etc/pgcat/pgcat-no-readers.toml
    chmod g+w,o+w /etc/pgcat/pgcat.toml /etc/pgcat/pgcat-readers.toml /etc/pgcat/pgcat-no-readers.toml
    chmod 640 "$phpdb_app/database.ini"
}
```

**Do not touch lines 654–656** (`chown www-data` + `chmod 640` on the same three files, at render
time). They look like the same work done twice; they are not redundant, because the provisioning
function widens the mode *after* the render step sets it restrictive, and the order is the point.

### 4.4 `docker-files/production/laravel/pool-prune-selftest.sh` — retired pool names

Its fixtures still build `reader_db_2` and `reader_db_3`, which stopped existing on 2026-10-09. The
fixture names are arbitrary, so this is a rename rather than a repair, and it keeps the assertions
meaningful (`fixture names → live names`):

| Line | Before | After |
|---|---|---|
| 90 | `is "one POOL line for reader_db_2"  "$(n "$LIST" '^POOL reader_db_2$')" 1` | `is "one POOL line for reader_db_1"  "$(n "$LIST" '^POOL reader_db_1$')" 1` |
| 91 | `is "one POOL line for reader_db_3"  "$(n "$LIST" '^POOL reader_db_3$')" 1` | delete — the fleet has one reader |
| 107 | `has  "both readers are named"    "$OUT" "reader_db_2 -> ${READER_1}.*reader_db_3 -> ${READER_2}"` | `has  "the reader is named"      "$OUT" "reader_db_1 -> ${READER_1}"` |
| 109 | `is   "reader_db_2 is gone"        "$(n "$(cat "$WORK/pgcat.toml")" '^\[pools.reader_db_2')" 0` | `is   "the pruned reader is gone" "$(n "$(cat "$WORK/pgcat.toml")" '^\[pools.reader_db_1')" 0` |
| 110 | `is   "reader_db_3 is gone"        …` | delete |
| 176 | `is "a.toml was pruned"     "$(n "$(cat "$WORK/a.toml")" '^\[pools.reader_db_2')" 0` | `is "a.toml was pruned"     "$(n "$(cat "$WORK/a.toml")" '^\[pools.reader_db_1')" 0` |

Where a fixture genuinely needs two readers, name them `reader_db_1` and `reader_db_2` — the point of
the selftest is the parser's behaviour, not which names the fleet happens to have.

### 4.5 `scripts/php/verify-db-hosts.php:142` — a stale expectation

```php
// before
    $rows[] = runCase($app, $logFile, 'read-replica-path', ['writer_db_1', 'reader_db_2', 'reader_db_3'], static function (Application $app): string {
// after
    $rows[] = runCase($app, $logFile, 'read-replica-path', ['writer_db_1', 'reader_db_1'], static function (Application $app): string {
```

Apply this whether the file is kept or deleted (2.2): a hand-run check that asserts a pool set the
deployment retired reports a failure that does not exist, and the next person to run it will chase it.

### 4.6 `docker-files/production/laravel/pgcat-health-gate.sh` and `smoke-test-image.sh` — the two gate callers

The package's light mode must carry a real query, because this is what the pooler gate exists to
prove. The alternate lines below assume `db:doctor --connection-only --real-test`, which the package
now has — the same option name the retired command used, so the gate's two sentences stay true —
and the bounded run asks the query even though it leaves the store and the supervisor out.

```bash
# pgcat-health-gate.sh:221
-#    out=$(cd "$APP_ROOT" && "$PHP_BIN" artisan db:connection-diagnostics --real-test 2>&1)
+#    out=$(cd "$APP_ROOT" && "$PHP_BIN" artisan db:doctor --connection-only --real-test 2>&1)
# and the sentences that name it: :226, :239 — "this image's db:doctor has no --real-test option …"
#                                          "db:doctor --connection-only --real-test exited ${rc}"
```

```bash
# smoke-test-image.sh:325 — the invocation only; the env block above it stays as it is
-        "$PHP_BIN" artisan db:connection-diagnostics \
+        "$PHP_BIN" artisan db:doctor --connection-only \
```

**Line 333 needs no change, and that is worth checking rather than assuming.** The smoke gate parses
the resolved connection out of the log with:

```bash
printf '  swrr.connection = %s\n' "$(sed -n 's/^  db-manager\.swrr\.connection *//p' "$log" | head -n 1)"
```

`db-manager.swrr.connection` is this package's own spelling — it is the subject and the `source` field
the package's doctor and the package's `README.md:676,704,717,792` use — so the parse keeps working
against `db:doctor`'s output. If the light mode renames that row, this line goes with it.

Its three message lines — 336, 338 and 340 — name the old command and should be reworded in the same
commit.

### 4.7 Documents that cite the retired command

Not code, but a removal that leaves them stale is the same failure in prose. Update in the same
commit as 2.1:

| File | Lines |
|---|---|
| `docs/codebuild-pipeline.md` | 49, 105 |
| `docs/db-connection-resolution.md` | 498, 500, 505, 582, 583, 586, 743, 854 — and the §12 heading at 500 |
| `docs/pgcat-issues.md` | 211 (a table row) |
| `site/.agents/skills/lpr-push/SKILL.md` | 323 |
| `site/.skills/lpr-push/instructions.md` | 317 |
| `buildspec.yml` | 22 (a comment) |
| `validate-image.sh` | 54 (a comment) |
| `CHANGELOG.md` | 3074 — **leave**; a changelog entry is history, not a citation |

---

## 5. The order, and the check after each step

Each step is independently revertible and none depends on a package release except where named.

| # | Step | Verdict | Check before moving on |
|---|---|---|---|
| 1 | `docs/` and `TOOL-REGISTRY.md` updates for 1.1, then delete `scripts/php/demo-pgcat-supervisor.php` | REMOVE | `git grep -ln 'demo-pgcat-supervisor'` returns nothing outside `CHANGELOG.md` |
| 2 | 4.1 config edits (six lines, four deletions) | EDIT | `php -l site/config/db-manager.php`; then a container run: the boot audit says nothing new, and `php artisan db:doctor` reports the connection as `pgsql_proxy` |
| 3 | 4.2 the duplicate registration, with line 175 set to 1 in the same commit | EDIT | `php artisan schedule:list` shows one `pgcat_flip` entry, once a minute; a `schedule:run` appends one line to `storage/logs/scheduled_tasks/pgcat_flip.log` |
| 4 | 4.3 the entrypoint's dead comments | EDIT | `sh -n docker-files/production/laravel/entrypoint.sh`; a container boot leaves `/var/swrr` with its six files and the pooler's three configs writable |
| 5 | 4.4 and 4.5 the retired pool names | EDIT | `bash docker-files/production/laravel/pool-prune-selftest.sh`; `php scripts/php/verify-db-hosts.php` if kept |
| 6 | this package's plan phase 5 (`db:doctor --connection-only`), then 4.6 and 2.1 | REMOVE | in the candidate image: `php artisan db:doctor --connection-only --real-test` exits 0 and prints `db-manager.swrr.connection`; then the promotion gate passes test 5 |
| 7 | this package's plan phase 8, then 2.2 | REMOVE | the package's own check reports the same pool set the deleted script asserted |

**What cannot be verified from here:** steps 2, 3, 4 and 6 need a container and a real pooler; the
checks above name what to look at rather than claiming a result. Nothing in this list was applied —
every line number and every alternate is proposed, not written into the application's tree.

## 6. Not covered by this list

Files whose removal has nothing to do with this package: the working-note strays under the
application's `docs/` (`1.txt`, `2.txt`, the git-config transcripts, the older `database*.php.txt`
copies — that last pair is worth deleting for its own reason, since a stale copy of a connection
config is the kind of file a reader mistakes for the live one), the imported Postman suite, and the
editor and agent configuration directories. This list is about redundancy this package created.

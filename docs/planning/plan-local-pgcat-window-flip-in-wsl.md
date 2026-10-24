# Plan — prove the pgcat window flip locally, with the provided app running under WSL

| | |
|---|---|
| Subject | `db:pgcat-flip` acting at a `reader_windows` boundary, in a container started minutes before it |
| Where | this machine — the Windows rig on 5432/5431/5433, the app under the WSL Linux PHP |
| App | `external/laravel-site` (the site's source, carried by `scripts/windows/build-external-laravel-site.ps1`) |
| Wrapper | `scripts/windows/wsl-linux-php.ps1` (ported out of `external/` by this plan) |
| Runbook | `docs/wsl-linux-php.md` |
| Read against | `docs/local-postgres-and-pgcat.md`, `scripts/servers-startup/README.md`, `docs/planning/plan-pgcat-both-mechanisms-armed.md` |

## What this plan is for

The window flip has been reasoned about and asserted in tests, and never watched against a real
pooler with the app in front of it. This plan sets up the smallest arrangement in which the *whole*
chain is visible — a cold rig, a reader window that opens a few minutes after the app starts, and the
pooler's own answer as the proof that reads moved and moved back.

The chain it walks:

```
the app boots (WSL)                    -> outside every window, so the resolver says writer
  db:pgcat-flip (per-minute entry)     -> swaps the pooler's file to the no-readers variant
  a read through 5433                  -> answered on 5432 by the primary
3 minutes later, the window opens      -> still inside flip_window_seconds, so the same entry acts
  a read through 5433                  -> answered on 5431 by the standby
```

Both halves are the pooler's own answers (`inet_server_port()`, `pg_is_in_recovery()`), not the
package's opinion of itself — which is what makes it a test of the flip rather than of the record the
flip writes.

## Step 0 — the facts this rests on, each one checked rather than assumed

| Fact | Where it was read | Why the plan leans on it |
|---|---|---|
| the pooler re-reads its config by itself | `autoreload = 15000` in `scripts/servers-startup/pgcat.toml` | a swap needs no supervisord, which this machine does not have |
| a command that is not `supervisorctl` is **not** a fault | `SupervisorStep::FAULT_NOT_SUPERVISORCTL` — *"Not faults: the command drives something other than supervisorctl"* | the flip's pre-swap judge passes on a machine with no supervisord |
| the flip judges the command **before** it swaps | `docs/issues/issue-pgcat-flip-force-mode-readers.md` — *"a flip refuses before it swaps the file, so nothing was replaced and no mode was recorded"* | the reload command has to resolve, or nothing else in the plan is observable |
| `statusCommand()` refuses to guess at a command line holding two commands | `src/Pgcat/SupervisorStep.php` | the no-op command has to be a single command line |
| the three paths are `config_path`, `readers_path`, `no_readers_path` | `config/db-manager.php`, the `pgcat` block | the three `external/*.toml` files are the production artefacts of exactly this trio |
| the entry acts only inside `flip_window_seconds` (480 s shipped) | `config/db-manager.php`, the boot-window block | a boundary three minutes in is inside it, so the entry and not the window tasks does the flip |
| the window is a time of day in `timezone`, on one of `reader_days` (ISO 1–7, shipped `[1,2,3,4,5]`) | `config/db-manager.php`, the windowed-fallback block | a window is silently never applied on a day the list omits, and today is a Saturday |
| the copy carries eleven names, and neither `composer.json` nor `.env*` is one | `scripts/windows/copy-site-to-external.ps1` | the app has no dependency manifest and no environment file, which is the `composer install` this plan has to arrange |
| `.env.prod` is not in the tree, and the site's is a plaintext secret | `ext/app-files-list.txt` names it; `git-externally-related.md` — *"the production one is encrypted and no plaintext `.env.prod` is tracked"* | the plan may name the file and never read it |

## Step 1 — the rig, cold

```powershell
pwsh -File scripts/teardown-pgcat.ps1     # the way back down, and the cold half of "start again"
pwsh -File scripts/setup-pgcat.ps1        # all of §2 of the runbook, with the checks in front
```

- `scripts/servers-startup/servers.local.cmd` is present, so nothing is searched for.
- The setup script ends by walking the chain itself: a read that must answer on 5431 and a write that
  must answer on 5432, failing on either one landing on the other server.
- **Verified by** its own closing pair, and by the two queries in §6 of
  `scripts/servers-startup/README.md` read by hand: `5431|t` for the read, `5432|f` for the write.

## Step 2 — the two variants the pooler will be swapped between

The flipper owns one live file and swaps it between two others. The production trio in `external/` is
not usable here — it points at RDS endpoints and carries `__LPR_DB_PASSWORD__` — so the local pair is
authored. The writer-only variant is the readers one with every `"replica"` row taken out — the three
under `sharded_db` as well as the one under `simple_db` — and `simple_db`'s `primary_reads_enabled`
turned on, because with no replica left a read has to have somewhere to go:

| Variant | `simple_db`'s shard servers | `primary_reads_enabled` | Where a read through 5433 lands |
|---|---|---|---|
| readers | the primary, and `localhost:5431` | `false` | the standby, in recovery |
| no-readers | the primary alone | `true` | the primary, because that is all there is |

The shipped comment says the same thing in prose: *"Outside of every reader_window … reads are served
by the writer instead of the weighted replica pool."* The pair is the smallest arrangement that can
tell the two states apart from outside the process.

- Files: `external/rig-pgcat/pgcat-readers.toml`, `external/rig-pgcat/pgcat-no-readers.toml` —
  `external/` is gitignored, so the test artefacts are not work this repository carries.
- The **live** file stays `scripts/servers-startup/pgcat.toml`, the one `start-pgcat.bat` runs and
  `setup-pgcat.ps1` validates: the readers variant copied from it verbatim, which is also the state
  the rig requires.
- **What this costs:** the test writes the *tracked* file, so the working tree shows it modified
  while the window is under test. `git checkout -- scripts/servers-startup/pgcat.toml` after
  `scripts/teardown-pgcat.ps1` is the restore, and it restores only what the test wrote.
- **Verified by** `Compare-Object` on the two files — they differ by the replica rows and the one
  switch, and by nothing else — and by `setup-pgcat.ps1`'s own pool-row check passing on the live
  file before the app starts.

## Step 3 — the app, built

`external/laravel-site` is the copy the build script makes, and it is source only. Three things are
missing and each is a bring-in rather than a discovery:

1. **`composer.json` and `composer.lock`** — the copy's eleven names do not include them, and the
   copied `bootstrap/providers.php` registers ten third-party providers (`JWTAuth`,
   `LaravelEfficientUuid`, `RepositoryServiceProvider`, …). A hand-written manifest requiring only
   `laravel/framework` and this package would not boot. Copy the two files from the site checkout
   named by `scripts/windows/site-path.local.cmd`.
2. **the local package**, wired as a path repository, so the app runs the working tree and not a
   published release:

   ```bash
   composer config repositories.dbmanager path ../..
   composer require uak35/laravel-weighted-dbmanager:@dev
   ```
3. **`vendor/`** — the step the operator named:

   ```bash
   composer install
   ```

   On Windows this goes through `bin/composer-ca.ps1` (the TLS scanner), and it is done on the
   Windows side because the distro has no egress (`docs/wsl-linux-php.md`, *Why the download
   happens on the Windows side*).

- **Verified by** `php artisan --version` (Laravel 12.42.0 is what the runbook measured) and
  `php artisan about` under the WSL wrapper, with `CACHE_STORE=array SESSION_DRIVER=array` on the
  line because the default preset has no `memcached`.
- If the build script has not been run yet, it is `pwsh -File scripts/windows/build-external-laravel-site.ps1`;
  it ends red on 21 files under `app/Data/ValidationObjects/refs/` until `ext/app-files-list.txt` is
  pruned, and that redness is the folder list winning over the file list rather than a failed copy.

## Step 4 — the environment file, and the window

`external/laravel-site/.env` is created here. `.env.prod` is the base it is written against and is
**not read** — it is a plaintext secret that lives in the site checkout, which is outside this
repository. What the file carries is the adjustment block:

```dotenv
# --- local rig -------------------------------------------------------------
# Against .env.prod; every row below differs for the rig and for nothing else.
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433                 # the pooler, not a server
DB_DATABASE=simple_db        # the pool name; some_db is refused by the pooler
DB_USERNAME=simple_user
DB_PASSWORD=simple_user
CACHE_STORE=array            # the Linux preset has no ext-memcached
SESSION_DRIVER=array
QUEUE_CONNECTION=sync

SWRR_PGCAT_ENABLED=true
SWRR_PGCAT_CONFIG=<repo>/scripts/servers-startup/pgcat.toml
SWRR_PGCAT_READERS=<repo>/external/rig-pgcat/pgcat-readers.toml
SWRR_PGCAT_NO_READERS=<repo>/external/rig-pgcat/pgcat-no-readers.toml
SWRR_PGCAT_BOOT_FILE=<tmp>/container-booted-at
SWRR_PGCAT_SCHEDULE_ENABLED=true
```

`<repo>` and `<tmp>` are written as tokens here; the file writes them out, and they are absolute
because the flipper compares and swaps those three paths exactly as they are spelled.

`restart_command`, `reload_command` and `use_reload` are **not** env-readable in the shipped config,
so the app's `config/db-manager.php` is the file that changes — which it has to be edited in anyway
for the window. Three edits, and the reasoning for each:

| Edit | Value | Why |
|---|---|---|
| `swrr.reader_windows` | one entry: `start` = now + 3 min, `end` = now + 10 min, in `swrr.timezone` | the boundary has to fall a few minutes after the app starts and inside the 8-minute boot window |
| `swrr.reader_days` | the whole list `[1,2,3,4,5,6,7]` | today is a Saturday, and the shipped list is Monday to Friday, so the shipped value would make the window never apply |
| `swrr.pgcat.reload_command`, `restart_command` | one command that exits `0`, e.g. `true` | there is no supervisord here; a single command line, because `statusCommand()` refuses to guess at two; `use_reload` stays `true` |

The same file is refreshed from `config/db-manager.php` before it is edited, because the copy in the
app has drifted — 333 lines against the package's 463 — and this plan wants the keys it names.

- **Verified by** `php artisan db:pgcat-flip --status` reporting the mode the resolver computes, and
  `php artisan db:pgcat-flip --status --json` naming the same window.

## Step 5 — run it, and watch the boundary

```powershell
# judge the runtime without downloading anything
pwsh -NoProfile -File scripts/windows/wsl-linux-php.ps1 -Check

# the app, in its own root, with the Linux PHP first on PATH
pwsh -NoProfile -File scripts/windows/wsl-linux-php.ps1 -Run 'cd external/laravel-site && php artisan db:pgcat-flip --status'

# the boot stamp is what the 480 s window is measured from, so a stale one has to go:
$env:SWRR_PGCAT_BOOT_FILE = $null      # and delete the file the old value named

# the cron this machine does not have
pwsh -NoProfile -File scripts/windows/wsl-linux-php.ps1 -Run 'cd external/laravel-site && php artisan schedule:work'
```

`schedule:work` is the app's substitute for a crontab: it runs the due entries every minute for as
long as it is up, which is what makes the per-minute `db:pgcat-flip` fire at all inside WSL. The
container's start is the moment the boot stamp is written, so removing the stamp before `schedule:work`
is what puts the window *inside* the boot window rather than minutes past it.

At each observation:

```bash
# the pooler's own answer — which server served the read
PGPASSWORD=simple_user psql -h 127.0.0.1 -p 5433 -U simple_user -d simple_db \
  -Atc "select inet_server_port(), pg_is_in_recovery()"

# the package's reading of itself, in the app
php artisan db:doctor
php artisan db:pgcat-flip --status --pool-probe
php artisan db:scheduler-watchdog
```

- **Verified by**, in order: `5432|f` before the boundary and `5431|t` after it; a second read a
  minute later still `5431|t` (nothing flips twice); `db:doctor`'s `pgcat mode` row naming the mode
  and the mechanism that applied it; and the state file holding `last_mode`, `last_flipped_at` and the
  `flipped_by`/`flipped_via` pair the plan before this one added.

## Step 6 — the wrapper, and the runbook

Landed with this plan rather than left as a step:

- `scripts/windows/wsl-linux-php.ps1` — moved out of `external/`. Its two roots are derived from its
  own location, so one level up from `scripts/windows/` is the repository root, and the copy in
  `external/` was resolving a level *above* the root. While it sat there it was gitignored, which also
  meant the path guards never read it; here `RepoEscapesTest` and `MachinePathsTest` do.
- `docs/wsl-linux-php.md` — the runbook, moved out of `external/` and into the tree so the guards in
  `tests/Unit/Docs/` read it like any other record. It gained what this plan needed and it did not say:
  running the *provided app* rather than this package's suites, the `composer install` the copy needs,
  and the window's `.env` rows. Two of its claims went with the move, because a `-Check` run
  contradicted them — the `bulk` preset's five extensions are not five the manifest declares, and the
  `manifest ext` line reads `require` and not `suggest`.

## Step 7 — what has to move with the plan

- `tests/Support/Handoff.php`: a `PENDING_DESCRIPTIONS` line for each of the three carried files — the
  plan document, the ported script and the moved runbook, all of them files this checkout has and the
  register does not — then `php bin/handoff.php --recorded` to re-render the note's pending block.
- The guards under `tests/Unit/Docs/` read the runbook now that it is carried: `sentences` and
  `counts` over its prose, `QuotedRecords` over anything it quotes from another record, and the two
  path guards over every path it writes.
- Nothing in `CHANGELOG.md`: no `src/` or `config/` surface moves here. This is a plan, a runbook and
  a script — documentation of the arrangement rather than the arrangement.

## Risks, and what each one would look like

| Risk | How it shows | What the plan does about it |
|---|---|---|
| the window never applies | `db:pgcat-flip --status` says `writer` for ever, and no boundary ever arrives | `reader_days` covers today, and the times are computed in `swrr.timezone`, which the plan fixes rather than reads |
| the boundary falls outside the boot window | the mode changes only if `schedule.windows` is armed, so nothing happens by default | the window opens 3 minutes in, against the shipped `flip_window_seconds` of 480 |
| the pooler has not re-read the file | reads keep answering as before for up to 15 s after the swap | `autoreload = 15000` is waited out rather than worked around, and the read is repeated |
| the flip refuses before swapping | the state file never gains a `last_mode`, and the reload command's own error is printed | the reload command resolves in WSL as one command line exiting `0` |
| the app cannot boot at all | `php artisan` fails in `bootstrap/providers.php` | step 3's manifest bring-in, and `composer install` before anything else is judged |
| the tree is left dirty | `git status` shows `scripts/servers-startup/pgcat.toml` modified | the restore in step 2, run after the rig is torn down |

## Deliberately not in this plan

- **An `-AppRoot` switch on the wrapper.** The wrapper `cd`s to the repository root and the app is
  reached with `cd external/laravel-site && …`, which is one token and no new surface. The switch is
  worth adding the day a second app has to be run through it.
- **Arming `swrr.pgcat.schedule.windows`.** It would exercise the boundary tasks, at the cost of the
  `both_armed` boot finding firing — and the record this plan reads against argues that arrangement is
  supported, not wrong. It is the next test, not this one.
- **The production artefacts in `external/`.** They name RDS endpoints and a password token, so they
  are inspected for the shape of the trio and never run.
- **Changing the package so the three paths and the two commands are env-readable.** That is a
  `src/`/`config/` change with a `CHANGELOG` entry and its own tests; the app's config file is the
  right place for a local arrangement.

## The order these are taken in

1. Step 6 (done, and it is what makes step 5 runnable).
2. Step 1 — the cold rig, and its own closing routing proof.
3. Step 2 — the two variants, checked against each other.
4. Step 3 — the app built, judged by `php artisan about`.
5. Step 4 — the `.env` and the three config edits, judged by `db:pgcat-flip --status`.
6. Step 5 — the run, and the two readings either side of the boundary.
7. Step 7 — the note re-rendered, and the tree restored.

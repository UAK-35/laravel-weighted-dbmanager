# Investigation — `boot stamp [/tmp/container-booted-at] is not writable`, and what the 09:59 config edit did

| | |
|---|---|
| Cluster / service / container | `lpr-backend-cluster-01` / `lpr-backend-sites-svc-02` / `lpr-backend-cntnr-01` |
| Task | `83f1b87db05848279d87f24a4a450403`, Fargate 1.4.0, `RUNNING` / `HEALTHY`, started 2026-10-27 09:37:44 UTC |
| Region / profile | `us-east-1` / `lpr` |
| Log group / stream | `/ecs/lpr-backend-sites-tdef-01` / `ecs/lpr-backend-cntnr-01/83f1b87db05848279d87f24a4a450403` |
| Code / config | `/app/site0/html`, `/app/site0/html/config/db-manager.php` (modified on the task at 09:59) |
| Runtime | Debian 13, PHP-FPM 8.4.15 CLI, nginx, pgcat on `/etc/pgcat/*.toml` |
| Question | why is the package trying to write this file? |

## Answer

**It is not.** The package has no writer for the boot stamp — `src/`, `bin/` and `scripts/` contain
none, and its only `unlink`s are of its own temp, state and audit files. What it does is *ask* whether
the file is writable **by the PHP process**, and print the answer as "the container cannot record when
it booted":

```php
private static function writable(string $path): bool
{
    return file_exists($path) ? is_writable($path) : self::directoryWritable(dirname($path));
}
```

The writer is the image's own `/app/entrypoint.sh`, at container start, as `root`:

```sh
BOOT_STAMP_FILE=${BOOT_STAMP_FILE:-/tmp/container-booted-at}
BOOT_FINISHED_STAMP_FILE=${BOOT_FINISHED_STAMP_FILE:-/tmp/container-booted-finished-at}
...
printf '%s\n' "$now" > "$BOOT_STAMP_FILE"
```

and the log shows it doing exactly that at 09:37:33 — more than twenty seconds after the container's
own start, and before the pooler.

So the warning is a **proxy**: it asks the application whether a file that `root` writes could be
written, and reports the answer as a fact about the container. The two are the same question only
while the writer and the reader are the same user.

## The files, as they are on the task now

| Path | Mode / owner | Size | Written by | Read by |
|---|---|---|---|---|
| `/tmp/container-booted-at` | `0644 root:root` | 11 bytes, `1793093853` (09:37:33) | `entrypoint.sh` → `stamp_container_boot_start` | the package, when `boot_file` names it |
| `/tmp/container-booted-finished-at` | `root` | — | `entrypoint.sh` → `stamp_container_boot_finished` | nothing in the package |
| `/var/swrr/container-booted-at` | `0664 www-data:www-data` | **0 bytes**, mtime 09:59 | **nobody** — created empty by hand at the edit | the package, after the edit |

`SWRR_PGCAT_BOOT_FILE` is set nowhere on the task; `/app/site0/html` holds `.env.prod` and no `.env`,
so `env('SWRR_PGCAT_BOOT_FILE')` is null in both the FPM and the CLI process and the **literal default**
in `config/db-manager.php` decides. That is why the edit below is the whole story: it changed the
fallback, so every process moved with it.

## Three states, and only the middle one was healthy

### State A — container start to 09:59: the window worked, the warning was noise

- `config/db-manager.php` still carried the shipped default, so the app read `/tmp/container-booted-at`.
- The entrypoint had stamped that same path at 09:37:33.
- `1783093853 + 480` closed the window at 09:45:33, and the state file shows the container converged
  **inside** it:

```json
{ "converged_at": "2026-10-27T09:39:04+00:00", "converged_mode": "writer", "last_run_at": "2026-10-27T09:45:04+00:00" }
```

- The warning still fired every minute, because the file is `0644 root:root` and the FPM pool runs as
  `www-data`, which cannot write it. The finding's own sentence — *"the boot window never closes: the
  entry flips on every run"* — was **false here**: the stamp was readable, and the window closed.

### State B — 09:59: the config was edited on the task

```
143:            //'boot_file' => env('SWRR_PGCAT_BOOT_FILE', sys_get_temp_dir() . '/container-booted-at'),
144:            'boot_file' => env('SWRR_PGCAT_BOOT_FILE', '/var/swrr/container-booted-at'),
```

The shipped line was commented out, the default was pointed at `/var/swrr/container-booted-at`, and an
**empty** file was created at that path. The entrypoint was not changed: it still stamps
`/tmp/container-booted-at`, and `setup_dbmgr_swrr` has its `/var/swrr/container-booted-at` lines
commented out (`#install -o www-data -g www-data -m 0664 /dev/null /var/swrr/container-booted-at`), so
nothing in the image ever writes that path.

### State C — now: no stamp, and the warning has gone quiet

`php artisan db:pgcat-flip --status --json` answers, as `root` and as `www-data` alike:

```json
"window": {
    "boot_file": "/var/swrr/container-booted-at",
    "source": "no_stamp",
    "booted_at": null,
    "window_seconds": 480,
    "deadline": null
}
```

`FlipWindow::closed()` is false for ever while `source` is `no_stamp`, so the entry never stands down —
and it did not: the state file records a flip at the **10:00 reader-window boundary**,

```json
{ "last_mode": "readers", "last_flipped_at": "2026-10-27T10:00:03+00:00", "flipped_via": "schedule" }
```

which is precisely the symptom the warning describes, arriving *after* the edit rather than before it.

And the warning stopped at 09:59:38 — the audit record is now `{"findings": []}` — because the empty
file at the new path is `0664 www-data` and therefore **writable**, which is all the check asks. So the
edit traded a noisy false alarm for a silent real fault: `no_stamp` is reported as *not judged* by
`schedule beat` and the payload, not as a failure.

## Why the two sides disagreed at all

The entrypoint's own comment states the contract and the repair in one line:

> The path is the documented default of `db-manager.swrr.pgcat.boot_file`; move both sides with
> `SWRR_PGCAT_BOOT_FILE` if it ever needs to be somewhere else. Nothing else reads it.

The edit moved **one side**, and moved it by editing the fallback rather than setting the variable the
contract names — so the entrypoint kept writing `/tmp` while the package started reading `/var/swrr`.

## The fix

**Stop the bleeding now (no deploy):** restore the shipped default — comment line 144, uncomment line
143 — or set `SWRR_PGCAT_BOOT_FILE=/tmp/container-booted-at` in the task environment. Either way the
app reads the stamp that exists (`1793093853`), the window is closed, and the entry stands down at its
next run. The `/tmp` file is intact and was never rewritten, so this loses nothing.

**Durably, pick one of two coherent states:**

| | where the stamp lives | what has to change | what it costs |
|---|---|---|---|
| **A — stay on `/tmp`** | `/tmp/container-booted-at`, `root`, `0644` | nothing; the image is already right | the proxy warning stays until the package stops asking the application to write a file it never writes |
| **B — move to `/var/swrr`** | `/var/swrr/container-booted-at`, `www-data`, `0664` | `BOOT_STAMP_FILE` **and** `SWRR_PGCAT_BOOT_FILE` both set to it, and `setup_dbmgr_swrr`'s `install …` line for it uncommented — the file must be **stamped**, not merely created | an image change, and the entrypoint must stamp it before pgcat starts |

**B** is the tidier end state — the stamp lives beside the other four files the deployment provisions,
and the write check passes because the writer and the reader are the same user — but it is an image
change and must not be half-done again. Until it is, **B is worse than A**, because an empty stamp is
silent while an unwritable one is at least loud.

**And the package should stop reporting the wrong fault.** The live task is the proof: the state that
actually breaks the window is a stamp that is missing or empty, and it is currently reported as *not
judged*. The recommendation stands from the earlier investigation: fail on what leaves
`FlipWindow::closed()` false for ever — absent, unreadable, empty, non-numeric — and let the mode and
owner travel as a note, since the package cannot know the writer's credentials.

## What was checked, and what was not

Checked on the live task: `id` (exec sessions are `root`), `ls -l` and contents of the three stamp
paths, `env | grep SWRR_` (empty), `.env` absent and `.env.prod` present, `bootstrap/cache/config.php`
absent, the `boot_file` lines in the live config with its 09:59 mtime and `lprFtpLvl01:www-data`
ownership, `/var/swrr` contents and modes, `db:pgcat-flip --status --json` as both `root` and
`www-data`, the state file and the audit record, `/app/entrypoint.sh`'s two stamp functions and its
`setup_dbmgr_swrr`, and the CloudWatch stream before and after 09:59.

Not checked: whether the 09:59 edit was applied only to this task or to the image's template, and
whether `/var/swrr` is task-local or mounted — the entrypoint's `install -d` suggests the former, and
a persisted stamp would be wrong for the next task anyway.

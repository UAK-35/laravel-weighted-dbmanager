# Investigation — `--force-mode=readers` applies, then the schedule puts it back within a minute

| | |
|---|---|
| Cluster / service / container | `lpr-backend-cluster-01` / `lpr-backend-sites-svc-02` / `lpr-backend-cntnr-01` |
| Task | `e4f1bb09d3f84a95aaa75765a8f702da`, Fargate 1.4.0, `RUNNING` / `HEALTHY`, started 09:42:41 UTC |
| Region / profile | `us-east-1` / `lpr` |
| Log group / stream | `/ecs/lpr-backend-sites-tdef-01` / `ecs/lpr-backend-cntnr-01/e4f1bb09d3f84a95aaa75765a8f702da` |
| Command | `cd /app/site0/html && php artisan db:pgcat-flip --force-mode=readers --env=prod` |
| Question | why the readers mode does not stay — it is reverted after a few seconds or a minute |

## Answer

The command works. It flips, it reports `flipped: writer → readers`, it exits 0 — and then the
**per-minute schedule flips it back on its next run**, because the forced mode is an application and
not a latch, and the only thing that would have made the entry stand down is disabled on this task.

Measured on the live task, 15:33–15:34 UTC:

| moment | `/etc/pgcat/pgcat.toml` | equals | state file |
|---|---|---|---|
| before, 15:33 | 2092 B, `www-data`, `07c90d43…` | **`pgcat-no-readers.toml`** — writer | `last_mode: writer` |
| `--force-mode=readers`, 15:33:12 | 2600 B, `root`, `19fe1701…` | **`pgcat-readers.toml`** — readers | `last_mode: readers`, `previous_mode: writer`, `flipped_via: manual`, `last_flipped_at 15:33:12` |
| +75 s, 15:34:04 | 2092 B, `www-data`, `07c90d43…` | **`pgcat-no-readers.toml`** — writer | `last_mode: writer`, `previous_mode: readers`, `flipped_via: schedule`, `last_flipped_at 15:34:04` |

**Fifty-two seconds** between the manual application and the schedule's reversal. Your own run was
caught in the same window: at 15:32:50 `db:pgcat-flip --status` read `pooler_config readers config`, and
the file's mtime was 15:33 — the schedule had just rewritten it back.

## Why, in three parts

### 1. `forceMode()` applies a mode; it does not pin one

`PgcatConfigFlipper::forceMode()` swaps the file, reads the pooler back, and writes
`last_mode` / `previous_mode` / `last_flipped_at` / `flipped_via: manual`. Nothing reads `flipped_via`
as an instruction — it is a record of who moved the pooler, not an override the rest of the package
obeys. The command's own help says what it is: *"Force a specific mode instead of consulting the
resolver"* — for that run.

The state file shows the seam: after the manual flip, `runs` stayed at 512 and `last_run_at` stayed
`15:33:04`, because those keys are the boot window's evidence and only a scheduled run writes them.
The manual flip moved the mode and the record of who moved it, nothing else.

### 2. The per-minute entry owns the mode, and the resolver says writer

`applyCurrentState()` runs every minute (`interval_minutes: 1`) and, past its guards, does one thing:
read the resolver's mode, compare it with `last_mode`, and swap when they differ — recording
`via schedule`. On this task the resolver is unambiguous:

```
reader_window   closed — reads should use the writer
```

and the configuration says why: `reader_windows` is `10:00:00–14:20:00` and `17:00:00–20:30:00` in
`timezone: UTC`, on `reader_days: [1,2,3,4,5]`. At 15:33 UTC the first window has closed and the
second has not opened, so the resolver's answer is **writer** — and the entry converges the pooler to
it. Forced readers therefore has at most one minute of life.

### 3. The bound that should have stopped the entry is disabled on this task

The entry is *supposed* to stop acting after `flip_window_seconds` (480 s) from the container's boot,
so a forced mode applied on a long-running container would stick. It cannot, here:

```
flip_window_state   unknown (no boot stamp)
```

`/var/swrr/container-booted-at` is **0 bytes**. The 09:59 config edit pointed `swrr.pgcat.boot_file`
at that path while the image's entrypoint stamps `/tmp/container-booted-at` — so the app reads a file
nothing writes. `FlipWindow::bootedAt()` returns null, `closed()` is false for ever, and
`applyCurrentState()`'s gate `if ($this->window->closed())` never fires: the entry acts on **every**
run for the container's whole life instead of standing down after its first eight minutes.

That is the difference between this task and a healthy one. With a real stamp the sequence would be:
manual readers → the next minute returns `window_closed` and does nothing → the mode persists until a
reader-window boundary moves it, and the state file keeps `flipped_via: manual` as the answer to *who*
put it there.

So the revert is a symptom of the boot-stamp regression, not of the resolver being wrong: the resolver
is right (outside a window, reads belong on the writer), and the entry is right to converge to it — it
is simply not allowed to have stopped.

## What makes readers stay

| | how | cost |
|---|---|---|
| **Restore the boot stamp** *(the actual repair)* | revert `swrr.pgcat.boot_file` to the shipped default (`/tmp/container-booted-at`, which holds `1793093853`), or stamp `/var/swrr/container-booted-at` and set both sides to it | none; it is the same fix the warning investigation asked for |
| **Disarm the schedule for a test** | `swrr.pgcat.schedule.enabled = false` (or `SWRR_PGCAT_SCHEDULE_ENABLED=false`) | no boot convergence and no boundary following while it is off |
| **A latch / override** | a package feature: a recorded manual mode the resolver honours until a boundary or an explicit clear | does not exist today; `flipped_via` is a record, not a veto |

Note what is *not* a repair: the revert is not a bug in `--force-mode`. A forced mode on an
installation whose per-minute entry is still acting is a mode that lasts one minute — that is what
"forced" costs.

## Read, not assumed

- the task's own state via `aws ecs describe-tasks` and the `--status` block, quoted above;
- `/etc/pgcat/*.toml` sizes and md5s before, immediately after, and 75 s after the forced flip — the
  three files' digests are what identify which variant is live;
- `/var/swrr/pgcat-flip-state.json` at the same three moments, which is where `via manual` becomes
  `via schedule` and `previous_mode` flips `writer → readers → writer`;
- `config/db-manager.php` for `reader_windows`, `reader_days`, `timezone`, `interval_minutes`,
  `config_path` / `readers_path` / `no_readers_path` and `boot_file`;
- `/var/swrr/container-booted-at` at 0 bytes, and `supervisorctl status "pgcat:*"` answering `RUNNING`
  with the socket at `0760 root:www-data` — so the refusal this command once had (a `0700` socket the
  application could not reach) is **not** what is happening here.

## Deliberately not investigated

- **Whether the service should be serving readers between 14:20 and 17:00.** That is the resolver's
  design and the windows are the deployment's; this investigation is about why a forced mode does not
  survive, not about the schedule of reads.
- **Whether the per-minute entry should keep converging a running container at all.** It should not —
  that is the point of the boot window — and the boot stamp is the reason it does. Covered by the two
  earlier records in this folder.

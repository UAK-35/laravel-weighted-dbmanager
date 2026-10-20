# Investigation — the pooler was not flipped from writer to readers

The weighted-db-manager never flipped pgcat from the writer config to the readers config at the
10:00 reader window; the mode was applied by hand afterwards. This is why, and what to fix.

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task | `f7bd22f8ef1a49f4bc7b8de5ff0a381e` |
| Region / profile | `us-east-1` / `lpr` |
| Log group | `/ecs/lpr-backend-sites-tdef-01` |
| Code folder | `/app/site0/html` |
| Config read | `/app/site0/html/config/db-manager.php`, `/etc/pgcat/*.toml`, `/etc/supervisor/supervisord.conf` |

## Short answer

The flipper refuses to swap the file until it has confirmed the supervisor command works, and it
makes that check with `supervisorctl`. The scheduled commands run as **`www-data`**, which cannot
reach supervisord's control socket — it is `root:root`, mode `0700`. So every automatic flip threw
`PermissionError`, swapped nothing and exited `1`. The manual flip worked because an ECS Exec
session runs as **root**, which can use the socket.

## Evidence from the task

Boot: task started `2026-10-19 22:31:42 UTC`; the reader window opened at `10:00 UTC`.

**The 10:00 boundary task** (`storage/logs/scheduled_tasks/activate_readers-10-00.log`):

```
09:52–09:59  readers waiting: probing: … 1 of 2 replicas answered      <- readiness OK (>=1 answers)
10:00:04     readers failed: the flip threw: supervisorctl could not answer for "pgcat:*" (exit 1):
             <class 'PermissionError'>, [Errno 13] Permission denied:
             /usr/lib/python3/dist-packages/supervisor/xmlrpc.py line 557
             — a flip refuses before it swaps the file, so nothing was replaced and no mode was recorded
```

It retried through the 8-minute grace (10:00–10:08) and failed the same way each time.

**The per-minute entry** (`pgcat_flip.log`): the same failure from boot onward — **1,083
`PermissionError` occurrences** and 0 `window_closed`.

**The manual flip** succeeded, and the state file records it:

```json
{ "last_flipped_at": "2026-10-20T10:34:18+00:00", "flipped_by": "pgcat-flipper",
  "converged_at": "2026-10-20T10:35:03+00:00", "converged_mode": "readers" }
```

`pgcat.toml` now equals `pgcat-readers.toml` (both pools, including `reader_db_1`).

**`db:doctor` run as `www-data`** states the fault itself:

```
FAIL  pgcat supervisor   supervisorctl could not answer for "pgcat:*" … PermissionError … Permission denied
FAIL  pgcat files        flip target [/etc/pgcat/pgcat.toml] is not writable (mode 0644, owner root:root)
```

**The mechanism:** `/etc/supervisor/supervisord.conf` has `[unix_http_server] chmod=0700` and no
`chown`, so `srwx------ root root /var/run/supervisor.sock`. The application and the schedule run
as `www-data` (`/usr/local/etc/php-fpm.d/www.conf: user = www-data`;
`/etc/supervisor/conf.d/pgcat.conf: user=www-data`).

## Two things that would still bite after the socket is fixed

1. **The flip target is now root-owned.** The manual root-run flip left `/etc/pgcat/pgcat.toml` as
   `root:root 0644`, so even with the socket reachable, `www-data` cannot write it (the atomic
   rename), and the flip would fail at the copy step — the `pgcat files` row above.
2. **The boot stamp is empty.** `/var/swrr/container-booted-at` is 0 bytes, so the boot window is
   not judged (`db:doctor` `schedule beat`: *"recorded no boot time … the boot window is not
   judged"*). The per-minute entry is therefore unbounded and retries for the container's whole
   life, which is what produced the 1.6 MB log.

## Fix

1. **Supervisord socket** (image change, `/etc/supervisor/supervisord.conf`):

   ```ini
   [unix_http_server]
   file=/var/run/supervisor.sock
   chmod=0760
   chown=root:www-data
   ```

   then `supervisorctl reload`, or redeploy so the socket is recreated.
2. **Restore the flip target to the application user:** `chown www-data:www-data
   /etc/pgcat/pgcat.toml` — and never let a root-run flip own it (the entrypoint re-chowns at
   render, but a root manual flip breaks it again).
3. **Fix the boot stamp** so `swrr.pgcat.boot_file` carries a real `date +%s`, bounding the
   per-minute entry.

## Unrelated findings seen while investigating

- `FAIL variant shape` — `/etc/pgcat/pgcat-no-readers.toml` declares pool `writer_db_1`, which
  connection `pgsql_proxy` weights in its read list, so a writer-only flip would put readers back.
- `FAIL connector` — connection `pgsql_proxy` could not be built: `Undefined array key "database"`.
- `WARN flip schedule` / `swrr.pgcat.schedule.both_armed` — the per-minute entry and the window
  tasks are both armed (one mode, two owners). Not the cause of this failure, but worth resolving.

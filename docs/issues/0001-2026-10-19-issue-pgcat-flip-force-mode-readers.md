# Issue — `db:pgcat-flip --force-mode=readers` works as root and fails as `www-data`

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task | `030552d97a024b828fea6cf14a5d6da1` |
| Region / profile | `us-east-1` / `lpr` |
| Code folder | `/app/site0/html` (main API site) |
| Config read | `/app/site0/html/config/db-manager.php`, `/etc/pgcat/*.toml`, `/etc/supervisor/supervisord.conf` |

## The command under investigation

```bash
cd /app/site0/html && php artisan db:pgcat-flip --force-mode=readers
```

## What actually happens

It depends on **which user runs it**:

| Run as | Result |
|---|---|
| `root` — the default for an ECS Exec session | **exit 0** — `flipped: writer → readers` |
| `www-data` — the application's user, and the scheduler's | **exit 1** |

Run as `www-data`, it ends with:

```
failed (mode=readers): exception during flip — error: supervisorctl could not answer
for "pgcat:*" (exit 1): <class 'PermissionError'>, [Errno 13] Permission denied:
file: /usr/lib/python3/dist-packages/supervisor/xmlrpc.py line: 557
— a flip refuses before it swaps the file, so nothing was replaced and no mode was recorded
```

## Root cause — the supervisord control socket is `root`-only

`/etc/supervisor/supervisord.conf`:

```ini
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0700                       ; and no chown
```

```
srwx------ 1 root root  /var/run/supervisor.sock
```

The flipper deliberately runs `supervisorctl status "pgcat:*"` **before** it replaces anything —
it judges the command it is about to run before swapping the file. `www-data` cannot connect to a
socket that is `root:root` mode `0700`, so that check raises `PermissionError`, the flip refuses,
changes nothing and exits `1`.

The application runs as `www-data`, which is the same user the scheduled flip uses:

- `/usr/local/etc/php-fpm.d/www.conf` → `user = www-data`, `group = www-data`
- `/etc/supervisor/conf.d/pgcat.conf` → `user=www-data`
- the scheduled `db:pgcat-flip` (registered on the Laravel schedule) runs as `www-data`.

So every scheduled run that actually needs to flip fails. A run that finds the mode already
applied makes no `supervisorctl` call at all and reports `no change` — which is why the flip can
look healthy and still be broken the moment a real flip is needed.

The package's own preflight says the same thing:

```
FAIL  pgcat supervisor   supervisorctl could not answer for "pgcat:*" (exit 1):
      <class 'PermissionError'>, [Errno 13] Permission denied …
      — a flip refuses before it swaps the file, so nothing is replaced and no mode is recorded
```

## Why it "works" from an ECS Exec shell

`aws ecs execute-command` starts a **`root`** session (`id` → `uid=0(root)`). `root` can use the
`0700 root:root` socket, so the same command succeeds there. That is exactly what hides the defect:
the interactive shell is the one user with an access the application does not have.

## The fix

Make the socket reachable by the user the flip runs as — in `/etc/supervisor/supervisord.conf`:

```ini
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0760
chown=root:www-data
```

then `supervisorctl reload`, which recreates the socket with the new mode and group. Ship it in
the image's supervisord config, or the next task reverts it.

Until that lands, running the flip as `root` is a workaround — but it also rewrites
`/etc/pgcat/*.toml` as `root:root 0644`, a file the `www-data` flip can then no longer write,
which trips `db:doctor`'s `pgcat files` row (`chmod +w`).

## Related findings seen while investigating

- `FAIL pgcat files` — `/etc/pgcat/pgcat.toml` had become `root:root 0644` after a root-run flip
  (restored to `www-data:www-data`, mode `-rw-rw--w-`).
- `FAIL variant shape` — `/etc/pgcat/pgcat-no-readers.toml` declares pool `writer_db_1`, which
  connection `pgsql_proxy` weights in its read list, so a flip to the writer-only variant would
  put readers back.

## Not the same fault

Independently of the above, the task was also serving reads against a `reader_db_1` pool that
pgcat's config no longer declared — the pool had been pruned at container start, when its RDS
endpoint did not yet resolve. That is a separate fault with its own record and its own fix; the
`supervisorctl` permission failure above blocks the flip that would otherwise apply a mode, not
the pool's presence.

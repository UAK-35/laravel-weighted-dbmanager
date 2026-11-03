# Investigation — the new image ships no pgcat binary, so `127.0.0.1:5433` refuses every request

| | |
|---|---|
| Cluster / service / container | `lpr-backend-cluster-01` / `lpr-backend-sites-svc-02` / `lpr-backend-cntnr-01` |
| Task | `9222bb3874934ad9b8d53c129ecb18dc`, Fargate 1.4.0 — `STOPPED`, `UNHEALTHY`, exit 137 |
| Started / stopped (UTC) | 2026-11-02 19:53:54 / 2026-11-02 20:07:20 — `Task stopped by user` |
| Task definition / image | `lpr-backend-sites-tdef-01:39` / `backend-image:d24c27b3370626b1fb1a40541dcc4e3489c36a5f` |
| Region / profile | `us-east-1` / `lpr` |
| Log group / stream | `/ecs/lpr-backend-sites-tdef-01` / `ecs/lpr-backend-cntnr-01/9222bb3874934ad9b8d53c129ecb18dc` |
| Code folder | `/app/site0/html` — read from the log stream, not from the stopped task |
| Measured | 2026-11-03, CloudWatch Logs Insights over the whole stream, ECS metadata, and the OCI image config/layers |

## The error

```
prod.ERROR: SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433
failed: Connection refused
        Is the server running on that host and accepting TCP/IP connections?
(Connection: pgsql_proxy, SQL: select * from "login_accounts" as "lgn" where "id" = 144 …)
```

SQLSTATE `08006` is *connection failure*, and PDO is reporting the `connect(2)` result: nothing
is listening on `127.0.0.1:5433`. No PostgreSQL handshake happened, so no pool was asked for a
connection and no database rejected anything. `pgsql_proxy` points at the container's own pgcat
on 5433; pgcat is not there.

## The timeline

| Time (UTC) | Event |
|---|---|
| 19:53:36.190 | container boot stamp written into `/tmp/container-booted-at` |
| 19:53:36.538 | `rendered config templates: pgcat.toml, pgcat-readers.toml, pgcat-no-readers.toml, database.prod.ini` |
| 19:54:23 | `Starting nginx` |
| 19:54:36.359 | `Starting supervisord` |
| 19:54:38.276 | `memcached:memcached_00 STARTING` |
| 19:54:48.504 | ten `laravel-queue-worker:*` processes `RUNNING`; no `pgcat:*` line |
| 19:54:52.619 | `container BOOT FINISH …` / `ALL STARTED` |
| 19:54:56.489 | `[WeightedDB] … directory [/etc/pgcat] is not writable … (mode 0755, owner root:root)`; boot stamp `/tmp/container-booted-at` is not writable |
| **19:55:10.057** | **first `SQLSTATE[08006] … connection to server at "127.0.0.1", port 5433 failed: Connection refused`** |
| 19:55:10–20:01:06 | `Connection refused` on every database request; 1,028 log lines carry the phrase across those minutes |
| 20:05:13 | the service starts a replacement task (`3e628fbf14a043b5977f3e560218bdbb`) |
| 20:07:20.240 | this task is stopped by user; `UNHEALTHY`, exit 137 |

The app's own health route `/backend/api/v2/health-check` answered `204` at 19:54:56 because it
is cached and never queries, so the ALB kept the task in service while every authenticated
request was a 500. The ECS health check (`/app/pooler-healthcheck.sh --quiet && curl …
https://127.0.0.1/up`) did fail — the task is `UNHEALTHY` — but its `startPeriod` is 300 s and
the retries are 30 s apart, so the task served errors for minutes before ECS replaced it.

## The cause — the image has no `pgcat` binary

The image was built at 2026-11-03 00:48:20 +05:00 (19:48:20 UTC), five minutes before the task
started. Its OCI history contains the pgcat **configuration** but not the pgcat **binary**:

### The older image (`23965683`, task definition 36, the three healthy tasks now running)

```
COPY ./docker-files/production/laravel/pgcat /app
COPY ./docker-files/production/laravel/pgcat.toml /app/templates/pgcat.toml
COPY ./docker-files/production/laravel/pgcat-readers.toml /app/templates/pgcat-readers.toml
COPY ./docker-files/production/laravel/pgcat-no-readers.toml /app/templates/pgcat-no-readers.toml
COPY ./docker-files/production/laravel/pgcat-health-gate.sh /app/pgcat-health-gate.sh
RUN /bin/sh -c chmod +x /app/pgcat-health-gate.sh
COPY ./docker-files/production/laravel/supervisor.d/pgcat.conf /etc/supervisor/conf.d/pgcat.conf
RUN /bin/sh -c mv /app/pgcat /usr/local/bin/pgcat && chmod +x /usr/local/bin/pgcat     && mkdir -p /etc/pgcat /app/templates && chown -R www-data:www-data /etc/pgcat
```

The 5,997,727-byte layer `sha256:f903723895e865aa5c287e3ff2223381b533846a2128943ca87910feeb178633`
in that image contains:

```
-rwxr-xr-x 0 0 0 17489448 Oct 29 03:39 usr/local/bin/pgcat
drwxr-xr-x 0 33 33       0 Oct 29 03:41 etc/pgcat/
-rw------- 0 0 0        0 Oct 29 03:41 app/.wh.pgcat
```

So the binary is 17,489,448 bytes at `/usr/local/bin/pgcat`; `/etc/pgcat` is owned
`www-data:www-data`; and the `/app/pgcat` source is whiteouted by the move.

### The new image (`d24c27b3`, task definition 39, the failed task)

Its history has the templates, the health gate and the supervisor config, and **no**
`COPY ./docker-files/production/laravel/pgcat /app` and **no**
`RUN … mv /app/pgcat /usr/local/bin/pgcat … chown -R www-data:www-data /etc/pgcat`.
The old pgcat layer is not in the new image at all:

```
new layer count: 122
old pgcat layer present in new: False
any ~5.99MB layer in new: False
```

The last layers of the new image are the supervisor configs, the 1.5 MB app/site layer and the
46 KB entrypoint — no binary layer. `/etc/pgcat` is therefore `root:root 0755`, exactly what
the app's boot warning reports at 19:54:56.

Both images still ship `/etc/supervisor/conf.d/pgcat.conf`:

```ini
[program:pgcat]
command=/usr/local/bin/pgcat /etc/pgcat/pgcat.toml
numprocs=1
autostart=true
autorestart=true
startsecs=3
startretries=5
user=www-data
stdout_logfile=/app/logs/pgcat.log
```

With the binary absent, supervisord's spawn fails immediately, retries five times and leaves
`pgcat:pgcat_00` in `FATAL`. Nothing binds 5433, and every PDO connect is refused. The
container's entrypoint does not make that failure visible: it starts supervisord, waits for
memcached, starts the queue workers, and prints `ALL STARTED`; the explicit
`supervisorctl start "pgcat:*"` is commented out in this entrypoint, and the only status lines
it prints are filtered to `memcached:*` and `laravel-queue-worker:*`. The pgcat log and the
supervisor log are local files (`/app/logs/pgcat.log`,
`/var/log/supervisor/supervisord.log`) and are not in CloudWatch.

The same dropped `RUN` is why `/etc/pgcat` is root-owned: the `chown -R www-data:www-data
/etc/pgcat` was part of the binary-install step. The package's boot findings at 19:54:56 are a
second symptom of the same build change, not the cause of the refusal — the flip could not
write the config either, but the missing binary is what leaves 5433 empty.

## What confirms it is the image and not this task

| | |
|---|---|
| Failed task | task definition `:39`, image `d24c27b3…`, `UNHEALTHY`, no pgcat layer |
| Healthy tasks now | task definition `:36`, image `23965683…`, three `RUNNING` / `HEALTHY`, pgcat layer present |
| Service deployment | the failed deployment was replaced; the primary is `:36` with `rollout: COMPLETED` |
| Image push time | `d24c27b3…` pushed 19:48:20 UTC; the task started 19:53:54 UTC |

The app code, the pgcat templates, the secrets, the RDS proxy and the database are not the
difference: the two images share those, and the only pgcat difference is the binary layer and
the chown step. The previous pgcat incident (`pgcat-issues.md`) was a pooler that crash-looped
on stale reader endpoints; this one is a pooler that cannot be spawned at all because its
executable is missing.

## The fix

1. **Restore the image steps in the site repository's Dockerfile** — the two lines are the
   whole repair:

   ```dockerfile
   COPY ./docker-files/production/laravel/pgcat /app
   RUN mv /app/pgcat /usr/local/bin/pgcat && chmod +x /usr/local/bin/pgcat \
       && mkdir -p /etc/pgcat /app/templates && chown -R www-data:www-data /etc/pgcat
   ```

   Rebuild, and do not promote `d24c27b3…` / task definition `:39` again. The service has
   already rolled back to `:36` / `23965683…`; keep it there until the rebuilt image is
   verified.

2. **Make the entrypoint fail closed on a missing binary.** Before `supervisord` starts (or
   immediately after), assert `/usr/local/bin/pgcat` exists and is executable, and assert
   `supervisorctl status "pgcat:*"` is `RUNNING`; print the status rather than filtering it to
   memcached and the queue workers, and exit non-zero if the pooler is not up. The current
   `ALL STARTED` is printed while the pooler is `FATAL`.

3. **Ship the pooler's own record.** `/app/logs/pgcat.log` and
   `/var/log/supervisor/supervisord.log` die with the task. A missing binary would then name
   itself (`spawnerr: can't find command '/usr/local/bin/pgcat'`) instead of being inferred
   from an empty port.

4. **Make the deploy gate mandatory for this class.** `/app/pgcat-health-gate.sh` and
   `/app/pooler-healthcheck.sh` already ask the right questions — supervisor state and a real
   connect to 5433 — and the ECS health check did mark the task `UNHEALTHY`. What did not
   happen is the task never being allowed to serve: the ALB health route is cached (`204` with
   no query), so the task stayed in service while the pooler was absent. The gate should run
   against the candidate before the service is moved, and the ALB health route should be one
   that queries the pooler (or the target group should only register a task whose
   `pooler-healthcheck.sh` passed).

5. **Keep the package's boot finding.** The `/etc/pgcat` and boot-stamp warnings are correct
   and were the only in-band evidence of the change; they should stay. The fix is in the
   image, not in the package.

## What is not established

* **The exact supervisor spawn error.** The pgcat and supervisor logs are container-local and
  not in CloudWatch; the missing binary and the empty 5433 are measured, and the spawn error
  is the only mechanism that fits them.
* **Whether the deploy gate ran against `:39`.** The task started and served; nothing in the
  log stream says whether the candidate was gated or the gate was bypassed. The image was
  pushed five minutes before the task started, so a candidate build is likely, but the deploy
  path is not in this evidence.
* **Why the Dockerfile lost the two lines.** The image history proves the lines are gone; the
  site commit `d24c27b3…` is not in this package, so the edit that removed them is not read
  here.
* **The exact number of failed requests.** 1,028 log lines contain `Connection refused`; one
  failed request writes several lines (exception, `Error-details::`, stack trace), so the line
  count is not a request count. The 500s are the requests.

## Verification

| Claim | Command | Expected |
|---|---|---|
| the failure is a refused connect | Logs Insights on the task stream: `filter @message like /Connection refused/ \| stats count() by bin(1m)` | first 19:55, last 20:01; 1,028 lines |
| the app never saw pgcat | Logs Insights: `filter @message like /Welcome to PgCat/` | no row |
| the binary is absent in `:39` | OCI config of `d24c27b3…`: `history[].created_by` | no `COPY …/pgcat /app`, no `RUN … mv /app/pgcat /usr/local/bin/pgcat` |
| the binary is present in `:36` | layer `sha256:f9037238…` of `23965683…`: `tar -tzf` | `usr/local/bin/pgcat` (17,489,448 bytes), `etc/pgcat/` |
| the new image lacks that layer | manifest of `d24c27b3…` | the digest is absent; no ~5.99 MB layer |
| the image is the deployment difference | `aws ecs describe-services …` and `describe-tasks` | primary `:36` / `23965683…`, three healthy tasks |
| the pooler config still points at the binary | `supervisor.d/pgcat.conf` in either image | `command=/usr/local/bin/pgcat /etc/pgcat/pgcat.toml` |

The stream readings are true of task `9222bb3874934ad9b8d53c129ecb18dc` between 19:53:36 and
20:06:02 UTC on 2026-11-02; the image readings are true of the two ECR image digests named
above on 2026-11-03.

## Deliberately not investigated

* **The application query and the routes that 500ed.** The failure is before any server; the
  query is only what was waiting.
* **The RDS proxy, the RDS instance and the database.** The app never opened a socket to the
  proxy; the local port was refused.
* **The Redis fallback warnings and the secondary site's `database.ini` errors** in the same
  boot log. They are real but separate, and neither closes 5433.
* **The site repository's Dockerfile and commit `d24c27b3…`.** The image history is the
  evidence here; the edit belongs to that repository.

# Issue — `AllServersDown` is one dead server away, and a reboot of the only instance pays for it

| | |
|---|---|
| Cluster / service / container | `lpr-backend-cluster-01` / `lpr-backend-sites-svc-02` / `lpr-backend-cntnr-01` |
| Task | `239c72d2f1f84b198e2b2198b3dfbe0c`, Fargate 1.4.0, `RUNNING` / `HEALTHY`, started 18:26:33 UTC |
| Region / profile | `us-east-1` / `lpr` |
| Log group / stream | `/ecs/lpr-backend-sites-tdef-01` / `ecs/lpr-backend-cntnr-01/239c72d2f1f84b198e2b2198b3dfbe0c` |
| Code folder | `/app/site0/html` (main API site) |
| Pooler | `pgcat` 1.2.0, `/etc/pgcat/pgcat.toml` |
| Measured | 2026-10-27 22:09–22:20 UTC, live ECS Exec and CloudWatch |

## The error

```
prod.ERROR: SQLSTATE[58000]: <<Unknown error>>: 7 FATAL:  could not get connection from
the pool - AllServersDown (Connection: pgsql_proxy, SQL: select * from "login_accounts"
as "lgn" where "id" = 323 and "lgn"."deleted_at" is null limit 1)
```

SQLSTATE `58000` is *system error*, and the sentence is pgcat's own. `AllServersDown` is a
pooler state, not something PostgreSQL says: the statement never reached a server. The pooler
holds one pool, `writer_db_1`, and that pool has exactly one server —
`lpr-database-3-proxy-3.proxy-c5gg0owow3pa.us-east-1.rds.amazonaws.com:5432`. When that one
server is marked bad, the pool has nothing to hand out and every request in the gap is refused.

## What happened, to the second

| Time (UTC) | Event | Cost |
|---|---|---|
| 20:30:04 | the reader window closed; the flip reloaded pgcat to writer (`flipped_via: window`) | 1 × 500 at 20:31:33 |
| 21:21:25–21:21:58 | a 51 m 53 s server session timed out; the health check found it flushing slowly, marked the only server bad, the pool reconnected inside a second | 1 × 500 at 21:21:58 |
| 21:43 | one more server marked bad, no client waiting in the gap | 0 |
| **22:00:28** | **pgcat received SIGTERM** (`Got SIGTERM, closing with 20 clients active`); supervisor stopped it and spawned pid 32588 at 22:00:33 | **13 × 500**, 22:00:28–22:00:32 |
| **22:02:05** | **CloudTrail: `RebootDBInstance` on `lpr-database-4c`, caller `lpr_dev2`** — the proxy's only target | — |
| 22:02:09 | the first client refusal | — |
| 22:02:18 | RDS records `DB instance shutdown` for `lpr-database-4c` | — |
| 22:02:25 | RDS records `DB instance restarted` | — |
| 22:02:29 | pgcat's sessions die with `Error reading message code from socket - Error Kind(UnexpectedEof)`; sessions 1:43–1:58 old | — |
| 22:02:32 | the last client refusal; a new server connection succeeds | **34 × 500**, 22:02:09–22:02:32 |
| 22:11:18 | one unrelated 500 with no pool error beside it | 1 × 500 |

The app's log has **47 HTTP 500s in the 22:00 five-minute bucket**: 13 from the pgcat restart,
34 from the database reboot. Each `AllServersDown` is three log lines (exception,
`Error-details::`, `Database query error occurred.`), which is why the raw line count is 102,
not 34. There was no alarm.

The two bursts are different faults:

* **22:00** is a full `restart`/`stop` of pgcat, not the flip's graceful reload. The supervisor
  log reads `waiting for pgcat_00 to stop`, and pgcat itself logs `SIGTERM` with 20 clients
  active. The flip uses `supervisorctl signal HUP`, which is why its 20:30 reload cost one
  request rather than thirteen.
* **22:02** is a deliberate reboot of the only database behind the proxy. The instance was down
  for ~7 seconds according to RDS and the pool was empty for ~23 seconds according to pgcat.
  Nothing in the pooler can serve through that; the pool refilled when the instance returned.

## Why one server means "all servers"

`writer_db_1` is a single-entry shard:

```
[pools.writer_db_1.shards.0]
servers = [["lpr-database-3-proxy-3.proxy-c5gg0owow3pa.us-east-1.rds.amazonaws.com", 5432, "primary"]]
```

The proxy is `available`, its `IdleClientTimeout` is 120 s, and it has **no events** in 48 h.
Its only target is `lpr-database-4c`, which is `MultiAZ: False`, `db.t3.2xlarge`, PostgreSQL
17.9. So:

1. a reboot of that instance closes every server connection pgcat holds;
2. pgcat's health check sees the EOF and marks the one server **bad**;
3. a client asking for a connection while the server is bad gets `AllServersDown` at once;
4. pgcat creates a new server connection asynchronously; that is the gap the requests pay for.

There is no second server to fall back to, so "all servers down" is a precise description of
one socket being gone. The pool does recover — 21:21 inside a second, 22:02 in 23 seconds — but
without a retry the request that arrives in the gap is a 500.

## The fix

Two different faults share one error sentence, so the fix has two halves: **retry the checkout
for the transient loss**, and **do not take the only database down without a failover**. The
dead reader in the next section is the urgent third fix, because it is scheduled to happen.

### 1. Retry `AllServersDown` for autocommit reads — the direct fix for the reported error

The failed statement was a `SELECT`, and it failed **before** any server saw it. Re-running it
is therefore safe, and it is exactly the case the application's existing retry layer was built
for. `App\Database\ConcurrencyReadRetry` already re-runs an autocommit read for a standby
conflict (40001) and sends a statement timeout (57014) to the write connection; neither covers
this class. Add a third class:

* **What to match:** SQLSTATE `58000` together with the message
  `could not get connection from the pool` (or `AllServersDown`). Match the phrase as well as
  the code, the same belt-and-braces the conflict path uses, because the code is generic.
* **What to wrap:** `SELECT`s outside a transaction, as the wrapper already does. A checkout
  failure inside a transaction still needs the transaction rolled back, not the statement
  re-run.
* **Budget:** the current conflict budget (`attempts` 3, `base_delay_ms` 25, `max_delay_ms`
  250) covers the 21:21 blip (< 1 s) and not the 22:02 reboot (23 s). Give this class its own
  budget — for example 5 attempts, 200 ms base, 2 s cap, jittered (~3.8 s total) — so the
  first seconds of a reconnect are absorbed and a real database outage still returns a 500
  after a bounded wait. A 23-second reboot cannot be hidden by a retry, and should not be.
* **Writes:** a checkout failure is safe to retry for a write too, because nothing was sent,
  but the wrapper's contract is read-only on purpose. If writes need the same treatment, use a
  connector-level reconnect or `DB::transaction($callback, $attempts)`, not a blind statement
  replay.

This is application code, not this package: the package chooses the endpoint a read lands on,
and the recovery layer is the application's (`git-externally-related.md` records that
boundary). The package's half is the reading and the flip, below.

### 2. Do not reboot the only database without a failover

`lpr-database-4c` is single-AZ and is the proxy's only target. A reboot is a real database
outage, and no pooler setting changes that. Either:

* enable Multi-AZ so a reboot/failover is absorbed by the proxy, or
* schedule the reboot in a maintenance window and accept the 500s for its duration, with the
  retry layer covering the reconnect at the end.

Do not combine it with a pgcat restart: 22:00 and 22:02 were five minutes apart and produced
47 500s between them. A config reload should use `supervisorctl signal HUP` (the flip already
does); a full `restart` drains 20 clients and costs ~13 requests.

### 3. Remove the dead reader before the next `10:00` UTC window — the urgent one

This is a separate, scheduled outage, and it is not the error above yet:

* `/etc/pgcat/pgcat-readers.toml` still declares `reader_db_1` with
  `lpr-database-4c-r7.c5gg0owow3pa.us-east-1.rds.amazonaws.com:5432`.
* That instance was shut down at 21:00:41 and **deleted at 21:02:18** (RDS events; CloudTrail
  records the `DeleteDBInstance` at 21:00:35). `getent hosts` answers nothing for the name.
* The readers file was rendered at **18:26:18**, before the deletion, so the boot-time prune
  never saw a dead address. It has not been re-rendered since.
* The application's read list still weights `reader_db_1` at **500.0** against `writer_db_1` at
  **145.0**, and the next `activate_readers` boundary is **10:00 UTC** the next weekday.

So the next window would flip pgcat to a file with one live pool and one dead one. pgcat
validates its servers at start; the shape in `pgcat-issues.md` §5 — a writer plus unresolvable
readers — is exactly the `STARTING` loop that took the pooler down in fix-236. At best 77 % of
reads are aimed at a pool that cannot connect; at worst the pooler does not come up at all.

Pick one, and ship it in the image template, not only in the live task:

* **A live replica exists:** repoint `reader_db_1` in `pgcat-readers.toml` and the connection's
  read list at it.
* **No live replica exists** (today: `describe-db-instances` lists `lpr-database-1`,
  `lpr-database-3-bkup-a` stopped, and `lpr-database-4c`): make the readers variant
  writer-only — copy `pgcat-no-readers.toml` over `pgcat-readers.toml` with the same pool
  sizing — and remove or comment the `reader_db_1` entry in `config/database.php` so the
  connection's read list matches the pools the variant declares. The flip then swaps two
  identical files and no dead pool can be activated.

Re-render or restart the container after changing the template so the running flip sees the
new file; the live `pgcat-readers.toml` is a boot-time render and will not update itself.

### 4. The flip refuses a variant it cannot serve — applied in this package

`VariantShape::unresolvedPools()` is the reading: for one variant's path, it asks every pool the
file declares and returns the pools where **none** of the declared addresses resolves. It is
deliberately narrower than `problems()`'s connection filter, because the flip's question is
whether the file can be served at all, not whether the connection's read list is complete; and
it draws the prune's line, so one live address is a pool the boot leaves in place rather than a
fault.

`PgcatConfigFlipper::unservableVariant()` is the refusal. Both entry points ask it before
anything is swapped:

* `applyCurrentState()` records the run as `skipped` and writes `last_refusal`,
  `last_refusal_at` and `last_refusal_mode` into the flip state, so a later `--status --json`
  or `/health/db` read carries the sentence rather than only the kind;
* `forceMode()` records the same three keys and deliberately writes no run and no mode — the
  forced path must not move the boot window's evidence, or claim the pooler moved when it did
  not.

The file is left as it is and the next minute tries again. A name that resolves on the next run
costs one boundary; a variant the pooler cannot serve costs every request behind it.

This is the same class as `pgcat-issues.md` §10 options 1–2, now caused by a deletion during
the task's life rather than by the image. The boot prune fixes the template at start; the flip
guard fixes it at 10:00.

### 5. Configuration readings that are wrong but not this fault

* `healthcheck_delay = 30` is **30 milliseconds**, not 30 seconds. pgcat's own default is
  `30000` (`General::default_healthcheck_delay()`), and the pooler's startup log prints
  `Healthcheck delay: 30ms`. Set it to 1000–3000 ms. It is not the cause of the 34 refusals —
  those are client requests — but it is a 1000× deviation and a reconnect/health-check storm
  during an outage. Do not set 30000: that would delay recovery after a blip.
* `max_wait_time = 600000` is 10 minutes, longer than any web request. Set it to
  5000–10000 ms so a request fails and retries instead of hanging behind an empty pool.
* The readers variant sizes the writer pool at 35 / 3 where the writer variant uses 150 / 30.
  If the readers variant becomes writer-only, keep the same sizing, or every boundary is also
  a 4× capacity cut.
* `/etc/pgcat/*.toml` are mode `0666` / `0662` (world-writable). Tighten them to `0640` or
  `0660` owned by the user the flip runs as.
* `db:doctor`'s `variant shape` row is already `FAIL` on this task: the writer-only variant
  declares `writer_db_1`, which the connection weights in its read list. That is the
  pre-existing reading issue recorded on 2026-10-22, not this fault — but it means the doctor
  is not a clean gate today, so do not read its red as this error or its green as safety.

### 6. Observability

* 47 500s in five minutes fired no alarm. Add a CloudWatch metric filter/alarm on
  `AllServersDown` and on `response status: 500`, scoped to the service's task streams.
* `/app/logs/pgcat.log` is local to the container and never shipped. The pooler's own account
  of 22:02 — the three `marked bad`, the `UnexpectedEof`, the 34 `Could not get connection` —
  dies with the task. Ship it, or copy the tail on a failed health check.
* Count requests, not log lines: one failed query writes three lines.

## What is not established

* **Who restarted pgcat at 22:00:28.** The supervisor log records the stop and the spawn but
  not the caller, and CloudTrail has no SSM data-plane event (ECS Exec sessions are not logged
  by default). It was a `restart`/`stop`, not the flip's HUP; that much the log shows.
* **Whether the 22:02 reboot was planned.** CloudTrail names `lpr_dev2`; RDS records the reboot
  but no reason. `PreferredMaintenanceWindow` is `mon:02:00-mon:03:00` UTC, so 22:02 UTC on a
  Tuesday is not the automatic maintenance window — it was an explicit call.
* **Whether the other historical events had the same shape.** A task's pgcat log dies with the
  task and is not in CloudWatch, so the 10-21, 10-26 and 10-27-10:12 events survive only as
  the app-side line.
* **Why the 21:21 socket was blackholed.** The evidence reaches "the task → VPC endpoint →
  proxy path stopped acknowledging inside a 33-second window, while the proxy and the instance
  reported nothing" and stops there. A conntrack/NAT/endpoint cause is consistent, not proven.
* **Whether the readers flip would crash pgcat or merely fail reads.** pgcat 1.2's startup
  behavior with an unresolvable server is the fix-236 `STARTING` loop; whether a HUP reload
  takes the same path on this build was not tested, because testing it would take the live
  pooler down.

## Verification

| Claim | Command | Expected |
|---|---|---|
| one server per pool | ECS Exec: `grep -n "servers =" /etc/pgcat/pgcat.toml` | one entry, `writer_db_1` |
| proxy target | `aws rds describe-db-proxy-targets --db-proxy-name lpr-database-3-proxy-3` | `lpr-database-4c`, `AVAILABLE` |
| reboot | `aws cloudtrail lookup-events --start-time 2026-10-27T21:55:00Z --end-time 2026-10-27T22:10:00Z` | `RebootDBInstance`, `lpr-database-4c`, `lpr_dev2` |
| instance state | `aws rds describe-db-instances --db-instance-identifier lpr-database-4c` | `MultiAZ: false`, `available` |
| the burst | `grep -c "Could not get connection from pool" /app/logs/pgcat.log` | 34, first `22:02:09`, last `22:02:32` |
| the dead reader | ECS Exec: `getent hosts lpr-database-4c-r7.c5gg0owow3pa.us-east-1.rds.amazonaws.com` | no answer |
| the reader's deletion | `aws rds describe-events --source-type db-instance --duration 2880` | `shutdown 21:00:41`, `deleted 21:02:18` |
| the next boundary | ECS Exec: `php artisan db:pgcat-flip --status --env=prod` | `activate_readers:10:00` in `flip schedule` |
| the app's cost | Logs Insights: `filter @message like /response status: 500/ \| stats count() by bin(5m)` | 13 in the 22:00 minute, 34 at 22:02 |

The live readings are true of task `239c72d2f1f84b198e2b2198b3dfbe0c` at 22:09–22:20 UTC on
2026-10-27. A later task has its own `/app/logs/pgcat.log`, so the counts and line numbers above
belong to that task alone.

## Deliberately not investigated

* **The failed query's own code path** — why `login_accounts` is read at that point. The failure
  happened before any server saw the statement.
* **The second API site** (`/app/site2/phpdb`), the EFS mounts, nginx and php-fpm tuning.
* **pgcat's version and any upstream fix for the single-server pool.** The reading is complete
  without it, and an upgrade is a separate change with its own rehearsal.
* **The 22:11:18 500.** It has no pool error beside it and no second occurrence in the window;
  it is recorded so the count is not silently short.

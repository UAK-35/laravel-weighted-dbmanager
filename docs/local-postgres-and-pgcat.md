# The local PostgreSQL pair, and the pgcat checkout it sits behind

This is an environment record rather than a design decision: it says what this machine runs, what
the pgcat working tree on it is, and where the two meet. It was read on 2026-10-22 from the running
servers (`show`, `pg_stat_replication`, `pg_stat_wal_receiver`), from the configuration files beside
them, and from `git` in the pgcat checkout — not from memory of how either was set up.

Two rules shape it. No credential is written here: the cluster authenticates with a password kept in
the pgpass file, and this record names the file rather than its contents. And no directory is written
in the form that resolves on one machine only — the install root, the standby's directory and the
pgcat checkout are named by role, in angle brackets, because a path copied out of a terminal into a
record is a path that stops resolving when the checkout moves and that no reader but its author can
follow.

## 1. PostgreSQL

### 1.1 The installation

| | |
| --- | --- |
| Server | PostgreSQL 17.9, `x86_64-windows`, compiled by `msvc-19.44.35223`, 64-bit |
| `server_version_num` | 170009 |
| Install root | `<postgres-install>` (the native Windows build, not a container) |
| Primary data directory | `<postgres-install>/data` |
| Primary configuration | `<postgres-install>/data/postgresql.conf` |
| Primary `pg_hba.conf` | `<postgres-install>/data/pg_hba.conf` |
| Primary log | `<postgres-install>.log` — the file `pg_ctl -l` was given, since the primary runs with `logging_collector` off |
| Primary process | a `postgres.exe` started with `pg_ctl` on `<postgres-install>/data`, not the Windows service of the same version |
| Standby data directory | `<standby-dir>`, created beside the install root |
| Standby configuration | `<standby-dir>/postgresql.conf`, with the clone's own settings in `<standby-dir>/postgresql.auto.conf` |
| Standby authentication | `<standby-dir>/pg_hba.conf` and `<standby-dir>/pg_ident.conf`, copied from the primary |
| Standby log | `<standby-dir>.log` — the file `pg_ctl -l` was given, since the standby runs with `logging_collector` off |
| Standby process | a `pg_ctl` child, not registered as a service |

The primary started at 15:55 on 2026-10-22 and has been up since; the standby was started after it
and is streaming. Only two ports are involved: 5432 is the primary, 5431 is the standby, and both
listen on `localhost` — `127.0.0.1` and `::1` — so nothing here is reachable off the machine.

The standby's number is a testing arrangement rather than a topology decision. It sits on 5431 so
that 5433 stays free for pgcat, whose own test harness builds a simulated replica on that number; a
standby holding 5433 would collide with the pooler's fixtures rather than with the pooler itself.

Two PostgreSQL 17 trees exist on this machine, and only one of them is used here. The one this
record describes is `<postgres-install>`, whose `data` directory the primary runs from. The other is
the installer's copy at `C:/Program Files/PostgreSQL/17`, and the Windows service
`postgresql-x64-17` is registered against *its* data directory, on the same port 5432, with an
automatic start type and no server running. Starting that service would therefore bring up a
different cluster rather than this one, and the two cannot hold 5432 at once — which is why the
starters in 1.7 use `pg_ctl` on this tree and refuse while the service is up.

### 1.2 Settings of the primary, port 5432

These are the values `show` returns, with the ones set away from the distribution default marked in
the configuration file itself.

| Setting | Value |
| --- | --- |
| `listen_addresses` | `localhost` |
| `port` | 5432 |
| `max_connections` | 100 |
| `shared_buffers` | 128MB |
| `dynamic_shared_memory_type` | `windows` |
| `max_wal_size` / `min_wal_size` | 1GB / 80MB |
| `wal_level` | `replica` |
| `hot_standby` | on |
| `max_wal_senders` | 10 |
| `wal_keep_size` | 0 |
| `archive_mode` | off |
| `ssl` | off |
| `password_encryption` | `scram-sha-256` |
| `timezone` / `log_timezone` | `Asia/Karachi` |
| `datestyle` | `iso, mdy` |
| `lc_messages`, `lc_monetary`, `lc_numeric`, `lc_time` | `English_Pakistan.1252` |
| `default_text_search_config` | `pg_catalog.english` |
| `work_mem` | 4MB |
| `effective_cache_size` | 4GB |
| `synchronous_commit` | on |
| `server_encoding` | UTF8 |

`wal_level = replica` with `max_wal_senders = 10` is what makes the clone in 1.5 possible at all;
`archive_mode = off` means WAL is retained only as long as a streaming client needs it, and
`wal_keep_size = 0` means nothing is held beyond that. There are no replication slots, so a standby
that stays down longer than the primary keeps the WAL it needs has to be rebuilt rather than caught
up.

### 1.3 Authentication and roles

`pg_hba.conf` is one rule set repeated for each address family, and every rule uses `password`
rather than a method pinned to one hashing scheme:

```text
host    some_db      simple_user  127.0.0.1/32   scram-sha-256
host    some_db      simple_user  ::1/128        scram-sha-256
local   all          all                        password
host    all          all      127.0.0.1/32      password
host    all          all      ::1/128           password
local   replication  all                        password
host    replication  all      127.0.0.1/32      password
host    replication  all      ::1/128           password
```

The two lines at the top are there for pgcat and for nothing else: when the pooler is the client it
answers `md5`, `SASL` and `ok`, and refuses a cleartext request, which is what the `password` rules
below ask for. Both files carry them — the primary's and the standby's — and `pg_reload_conf()`
applied them on both ports without a restart.

The password for the local cluster lives in the pgpass file at `%APPDATA%/postgresql/pgpass.conf`,
which carries entries for `localhost:5432`, `localhost:5431` and `127.0.0.1:5431` for the `postgres`
user — the standby's two were added when it moved to its testing port, so the tools can reach it
without a prompt on either spelling of the loopback address. A
`pg_basebackup` needs a superuser or a role with `REPLICATION`, and the `postgres` role is both:

| Role | Superuser | Replication | Can log in |
| --- | --- | --- | --- |
| `postgres` | yes | yes | yes |
| `admin` | no | no | yes |
| `simple_user`, `sharding_user`, `other_user` | no | no | yes |
| the predefined `pg_*` roles | no | no | variable |

There is no role named `root` on this cluster. A connection that asks for one is refused because the
role does not exist, which is a different refusal from a wrong password and reads differently in the
log.

### 1.4 Databases

| Database | Encoding | Collation / ctype |
| --- | --- | --- |
| `postgres` | UTF8 | `English_Pakistan.1252` |
| `template0`, `template1` | UTF8 | `English_Pakistan.1252` |
| `cocoindex` | UTF8 | `en_US.UTF-8` |
| `lpr_rehearsal_01` | UTF8 | `en_US.UTF-8` |
| `shard0`, `shard1`, `shard2` | UTF8 | `English_Pakistan.1252` |
| `some_db` | UTF8 | `English_Pakistan.1252` |

The four databases the shipped pgcat samples name were created for the walk in 3, along with the
roles `sharding_user`, `other_user` and `simple_user` — all owned by `postgres`, all inherited by the
standby through streaming. The three shard databases hold no tables; `some_db` holds one,
`routing_probe`, with a row written before the pooler existed and a second written through it.

### 1.5 The standby, port 5431

The standby is a physical clone, not a logical subscription: `pg_basebackup` copied the primary's
data directory into `<standby-dir>` with `--write-recovery-conf`, which wrote both `standby.signal`
and the `primary_conninfo` below. It is the same cluster at the same point in time, so every role and
database in 1.3 and 1.4 exists on it without being created there.

| | |
| --- | --- |
| `pg_is_in_recovery()` | true |
| `data_directory` | `<standby-dir>` |
| `port` | 5431, written into the standby's own `postgresql.conf` |
| `listen_addresses` | `localhost` |
| `hot_standby` | on |
| `primary_conninfo` | `user=postgres passfile=<pgpass> host=localhost port=5432 sslmode=prefer` |
| walreceiver | `streaming`, sender `localhost:5432` |
| receive / replay LSN | equal, no bytes behind |
| files from the clone | `standby.signal`, `backup_label.old`, `backup_manifest` |

The port is the one setting that was changed for this machine and for testing in particular: the
clone would otherwise have inherited 5432 and refused to start beside the primary, and 5431 was
chosen over 5433 so the free number is the one pgcat's harness wants. Nothing in the clone depends on
the number — `primary_conninfo` names the primary's port, not its own — so moving the standby again
is one line in its `postgresql.conf` plus the callers that would follow it.

The connection string carries no password: it names the pgpass file, so the secret is in one place
and rotation is that file's business. On the primary, `pg_stat_replication` shows one row —
`application_name = walreceiver`, client `::1`, `streaming`, `async`, replay lag 0.

Two consequences follow from the way it was built. Replication is asynchronous, so a commit that the
primary acknowledges is not guaranteed to be on the standby at that instant; and the standby refuses
writes itself, with a `cannot execute INSERT in a read-only transaction` error rather than a silent
no-op.

Start and stop are `pg_ctl` calls against the standby's directory, since no service was registered:

```bash
pg_ctl -D <standby-dir> -l <standby-dir>.log start
pg_ctl -D <standby-dir> -m fast stop
```

On this machine the primary's data directory is roughly 3.0 GB and the clone is roughly 2.1 GB of it,
the difference being the WAL the backup did not carry.

### 1.6 The standby's own files

The standby does not read the primary's configuration. `pg_basebackup` copied the data directory
whole, so every file the running server consults resolves inside `<standby-dir>` and none of them is
shared with the install root:

| File | What it is |
| --- | --- |
| `postgresql.conf` | the server settings — the clone's copy, with one line appended for this machine: `port = 5431`, written with a comment so a bare `pg_ctl -D <standby-dir> start` cannot collide with the primary, and so the number 5433 is left to pgcat's tests. `show config_file` answers this path. |
| `postgresql.auto.conf` | written by `pg_basebackup --write-recovery-conf`, not by hand. It holds the `primary_conninfo` of 1.5: `host=localhost port=5432`, a `passfile` rather than a password, `sslmode=prefer`. Its header says `ALTER SYSTEM` rewrites it, so a setting that has to survive a re-clone belongs in `postgresql.conf`. |
| `standby.signal` | an empty marker file. Its presence is what makes the directory a standby; deleting it and starting the server would bring the copy up as a primary. |
| `pg_hba.conf` | the authentication rules, copied at clone time — the same `password` lines for local, `127.0.0.1/32` and `::1/128`, under both `all` and `replication`. `show hba_file` answers this path. |
| `pg_ident.conf` | copied with it; no user-name mappings are in use. |
| `backup_label.old`, `backup_manifest` | what the clone left behind: the starting checkpoint and the manifest of the files it copied. |

Two details decide what a reader of those files actually sees. The first is the log: with
`logging_collector` off, the server's stderr goes wherever `-l` pointed, which is
`<standby-dir>.log` — that is the file a walreceiver that has stopped is diagnosed from.
`<standby-dir>/standby.log` also exists, from the first start, which was cut short when the console
that launched it went away; nothing writes to it now. The second is that the generated pair,
`postgresql.auto.conf` and `standby.signal`, is state rather than configuration: another
`pg_basebackup` rewrites both, so an edit made there is lost rather than promoted.

### 1.7 The starter and stopper scripts

A batch file per server, and one more per direction, sits beside the data directories. Each resolves
its own location with `%~dp0` rather than writing a path into itself, so none stops working when the
folder is moved:

| Script | What it starts, or stops |
| --- | --- |
| `start-primary.bat` | the primary on 5432, with `pg_ctl -D <postgres-install>/data -l <postgres-install>.log`. It first refuses if the Windows service `postgresql-x64-17` is running, because that service starts the other tree on the same port. |
| `stop-primary.bat` | the same data directory, with `pg_ctl -D <postgres-install>/data -m fast stop`. |
| `start-replica.bat` | the standby on 5431, with `pg_ctl -D <standby-dir> -l <standby-dir>.log`. It needs no guard: the standby's `postgresql.conf` names its own port. |
| `stop-replica.bat` | the same directory, with `pg_ctl -D <standby-dir> -m fast stop`. |
| `stop-pgcat.bat` | the pooler on 5433, by the PID that owns the listening socket — the admin `SHUTDOWN` command sends `SIGINT`, which Windows does not have. |

All of them are safe to run twice: `pg_isready` is asked before anything is started or stopped, and a
port that already answers (or already does not) ends the script with that answer rather than a second
postmaster or a `pg_ctl` error. The starters finish by asking the server itself,
`pg_is_in_recovery()`, so the role of the server on the port is read rather than assumed — a standby
reports that it is in recovery, a primary that it is not. The stoppers finish with the readiness
line, `pg_isready` against the port, and treat a port that still answers as a failure: a second
postmaster, or a child that kept the socket, is the state they exist to catch rather than to report
as success. The pooler's stopper checks the PID against `tasklist` before closing it, so a port held
by another program is refused with that program named, and it forces the process only after a
polite close has gone unanswered. `pg_ctl`'s own output is written to a file under `%TEMP%` rather
than to the console, because the postmaster inherits whatever handles `pg_ctl` was given and a
console handle left open keeps the caller's output from ever closing.

The order is the dependency's: start primary, then standby, then pooler; stop pooler, then standby,
then primary. Nothing enforces it — each script stops what it names — and the reverse order is what
keeps the standby's log from a walreceiver retrying a primary that has gone.

None of these scripts registers anything at boot: the primary has no service pointing at this tree, so
after a reboot it starts only when the script is run, and the standby behaves the same way.

All of them are copied into this package, at `scripts/servers-startup/`, where they read the
directory they sit beside from a gitignored machine file instead of from their own location — a
copy in this repository cannot be beside the data directories, and the pooler's own build and start
scripts are in the same folder.

## 2. The pgcat checkout

### 2.1 Origin, revision, and what is dirty

| | |
| --- | --- |
| Checkout | `<pgcat-checkout>` — a working tree outside this repository |
| `origin` (fetch and push) | `https://github.com/UAK-35/pgcat.git` |
| Branch | detached `HEAD`, not a named branch |
| Commit | `4a7a6a8e7a78354b889002a4db118a8e2f2d6d79` |
| That commit | "Cut 1.2.0 release (#783)", 2024-08-30 |
| Commits reachable | 545 |
| Tags fetched | none |
| Crate version | 1.2.0, Rust edition 2021 |
| Local changes | `pgcat.toml` — the replica rows and the local run settings of 2.4 — and two `#[cfg(unix)]` guards, in `src/admin.rs` and `src/messages.rs`, described in 2.5.1 |

The remote is a fork rather than the upstream `postgresml/pgcat`, and the detached HEAD sits on the
1.2.0 release commit. There is no tag in the local clone, so `git describe` answers with the
abbreviated sha. `target/debug/pgcat.exe` is a debug build of that commit, made as 2.5.1 describes,
and started as 2.5 does it listens on `127.0.0.1:5433` and answers `SHOW POOLS` on the admin
database.

### 2.2 The crate

`pgcat` is a PostgreSQL connection pooler written in Rust, with sharding and read/write splitting
built in. Its dependencies of note are `tokio` for the runtime, `bb8` for the pooling, `sqlparser`
for the read/write decision, `hyper` for the Prometheus endpoint, and `rustls` for TLS. The source is
one module per concern:

| Area | Files |
| --- | --- |
| startup and configuration | `src/main.rs`, `src/cmd_args.rs`, `src/config.rs` |
| the two protocol sides | `src/client.rs`, `src/server.rs`, `src/messages.rs`, `src/scram.rs`, `src/auth_passthrough.rs` |
| routing and sharding | `src/query_router.rs`, `src/sharding.rs` |
| pooling and health | `src/pool.rs`, `src/dns_cache.rs` |
| administration and metrics | `src/admin.rs`, `src/prometheus.rs`, `src/stats.rs`, `src/stats/` |
| plugins | `src/plugins/` — `intercept`, `prewarmer`, `query_logger`, `table_access` |
| the rest | `src/mirrors.rs`, `src/tls.rs`, `src/logger.rs`, `src/errors.rs`, `src/constants.rs`, `src/lib.rs` |

The config file is the first positional argument, and it defaults to the bare name `pgcat.toml`
(`src/cmd_args.rs`), which means the file that is read is decided by the working directory the
binary is started in.

### 2.3 Every configuration file in the checkout

| File | What starts with it | Where its servers point |
| --- | --- | --- |
| `pgcat.toml` (root) | a binary started in the checkout root, or an IDE run with no arguments | primary `127.0.0.1:5432`, replica `localhost:5431` |
| `pgcat.minimal.toml` | a by-hand run against one server | one primary, `127.0.0.1:28815`; no replica rows |
| `.circleci/pgcat.toml` | `.circleci/run_tests.sh` | primary `127.0.0.1:5432`, replica `localhost:5432`, TLS from `.circleci/` |
| `examples/docker/pgcat.toml` | `docker-compose.yml`, mounted as `/etc/pgcat/pgcat.toml` | the compose service host `postgres`, port 5432 |
| `tests/go/pgcat.toml` | the Go integration suite | replica `localhost:5432`, databases `shard0`–`shard2`, `some_db` |
| `charts/pgcat/values.yaml` | a Kubernetes install | a whole config embedded under `configuration:` |

`utilities/generate_config_docs.py` reads one of these files' comments and writes `CONFIG.md`, which
is why the root file's comments are prose rather than notes. Two further files state a config
location without being configs: `pgcat.service` is the systemd unit and names `/etc/pgcat.toml`, and
the Dockerfile family builds images whose images are given that path at run time.

### 2.4 The root `pgcat.toml` as it now stands

General:

| Setting | Value |
| --- | --- |
| `host` / `port` | `127.0.0.1` / 5433 |
| `enable_prometheus_exporter` | true, on port 9930 |
| `worker_threads` | 5 |
| `connect_timeout` | 5000 ms |
| `idle_timeout` | 30000 ms |
| `server_lifetime` | 86400000 ms |
| `healthcheck_timeout` / `healthcheck_delay` | 1000 ms / 30000 ms |
| `shutdown_timeout` | 60000 ms |
| `ban_time` | 60 s |
| `autoreload` | 15000 ms |
| `server_tls` / `verify_server_certificate` | false / false |
| admin user | `admin_user`, reached through the virtual databases `pgbouncer` and `pgcat` |

Pools:

| | `sharded_db` | `simple_db` |
| --- | --- | --- |
| `pool_mode` | transaction | transaction |
| `default_role` | any | any |
| `query_parser_enabled` | true | true |
| read/write splitting | true | true |
| `primary_reads_enabled` | true | false |
| `sharding_function` | `pg_bigint_hash` | `pg_bigint_hash` |
| `load_balancing_mode` | random | default |
| `prepared_statements_cache_size` | 500 | default |
| users | `sharding_user` (pool_size 9, `statement_timeout` 0), `other_user` (21, 15000 ms) | `simple_user` (5, minimum 3) |
| shards | 0, 1, 2 — databases `shard0`, `shard1`, `shard2` | 0 — database `some_db` |
| servers, every shard | `127.0.0.1:5432` primary, `localhost:5431` replica | the same pair |

The replica rows were the first local change to the checkout, four lines across the three shards and
the single-shard pool, and they are what makes the standby in 1.5 the thing a replica query reaches.
Three more followed so that `simple_db` can be walked end to end against this pair: the pooler moved
to `127.0.0.1:5433` so it sits on loopback beside the servers rather than on 6432 for every
interface, `simple_db` moved to transaction mode with `default_role = any`, read/write splitting on
and `primary_reads_enabled` off — which is the row that keeps a read off the primary — and its
`simple_user` / `some_db` now exist on the cluster, with `routing_probe` as the table a query can be
read and written through. The `sharded_db` pool is untouched beyond its replica rows; the databases
and roles it names exist as 1.4 records, and hold no tables.

Two details of the file are worth knowing before trusting a run of it. The pool-level plugin block
for `sharded_db` is written as `[pool.sharded_db.plugins]`, singular, while every pool is declared
under `[pools.…]` — a second top-level table, which the parser may refuse or ignore depending on how
strict it is about unknown keys. And the credentials in this file are the ones the upstream example
ships with: the roles and databases in 1.3 and 1.4 were created to match the names it states.

### 2.5 Running it against this pair

```bash
# in the checkout root — the default config is the root pgcat.toml
./target/debug/pgcat.exe pgcat.toml

# the pooler's two ports
curl --fail localhost:9930/metrics
PGPASSWORD=admin_pass psql -U admin_user -h 127.0.0.1 -p 5433 -d pgcat -c 'SHOW POOLS'
```

The client's database is the **pool** name, not the shard's database name: `-d simple_db` reaches the
pool whose shard connects to `some_db`, and `-d some_db` is refused with `No pool configured for
database`.

A read and a write can be told apart without a client that understands roles, because the pooler's
query parser decides: with splitting on and `primary_reads_enabled = false`, a `SELECT` goes to a
replica and an `INSERT` only ever goes to the primary. To prove which one answered, the server's own
answer is enough — `inet_server_port()` names the port that served the query, and
`pg_is_in_recovery()` says whether it was the standby.

### 2.5.1 Building it on this machine

A plain `cargo build` does not finish here, and the first of the three obstacles is not pgcat's own
logic:

- `ring` 0.16.20 and `rustls-webpki` 0.100.2 and 0.101.1 embed `.der` files with `include_bytes!`,
  and this machine refuses to read that extension — `Access is denied. (os error 5)` — whatever the
  file contains. The three crates are re-extracted from their `.crate` archives outside the registry,
  their `.der` files renamed to `.bin` with every `include_bytes!` that names one followed, and patched in
  from those copies with `cargo build --config 'patch.crates-io.…path=…'`; the same copies drop
  `#![deny(unused_qualifications)]` in `ring` and `#![deny(warnings)]` in `rustls-webpki`, which a
  newer rustc turns into errors.
- pgcat's own source needed two `#[cfg(unix)]` guards: the `nix` signal that implements `SHUTDOWN`
  in `src/admin.rs`, which reports failure on a platform that has no SIGINT, and `with_retries` on
  the keepalive builder in `src/messages.rs`, which socket2 only offers on Unix.
- the servers ask local connections for a cleartext password, and pgcat answers only `md5`, `SASL`
  and `ok` when it is the client — so its own server connection died on
  `Unsupported authentication mechanism: 3`. Two `pg_hba.conf` lines above the `password` ones, in
  both the primary's and the standby's file, offer `scram-sha-256` to `simple_user` on `some_db`;
  `pg_reload_conf()` applies them without a restart.

One command runs the three in order, for this machine's directories and with the checks that make
the order safe: `pwsh -File scripts/setup-pgcat.ps1`. It reads `servers.local.cmd`, reports a port
Windows has reserved before anything tries to bind one, and compares `scripts/servers-startup/pgcat.toml`
with the rows this rig needs — 5433, the standby as the replica, `primary_reads_enabled = false`, the
`simple_user` / `some_db` pair the hba rules name — before it starts anything, so a config edit is
found before a build is paid for. The two servers come up next and are read back by their ports,
the rules and the build run against that live pair, and the pooler is started last, printing its own
`SHOW POOLS`. Running it twice is safe: a server already up says so, the rules are added once, the
patched crates are reused, and the build is skipped while the pooler holds its own binary open —
cargo cannot replace a file in use. `scripts/teardown-pgcat.ps1` is the way back down: the same
machine file, then the pooler, the standby and the primary in that order, and the three ports asked
with `pg_isready` at the end, where one that still answers fails the run.

### 2.6 The number 5433, and why the standby does not hold it

`.circleci/run_tests.sh` builds its own replica: it starts `toxiproxy-server`, creates a listener on
`127.0.0.1:5433` forwarded to 5432, and later injects 300 ms of latency into it to watch a health
check fail over. That is the reason the real standby answers on 5431: with the clone on 5433 the
harness could not bind its proxy, `toxiproxy-cli create` would fail, and `set -e` would end the run
there. Moving the standby off 5433 keeps both usable at once, and the trade is deliberate rather
than tidy — the clone's port is a test convention, not part of the topology.

Two things remain true of that harness either way. It stops any local pooler with `killall pgcat`,
the detached one included; and it starts pgcat with `.circleci/pgcat.toml`, whose replica rows still
name 5432, so the proxy it shapes is not referenced by the config it ships with — either that file
has drifted from the script or the failover step is exercising less than its comment claims.

## 3. Where the two meet, and the chain walked

The chain is: a client on 5433 → pgcat's pool `simple_db` → shard 0 → primary `127.0.0.1:5432` for
writes, standby `localhost:5431` for reads. It has now been walked end to end, from a client that
knows nothing about roles:

```text
-- through the pooler, a read
$ psql -h 127.0.0.1 -p 5433 -U simple_user -d simple_db \
    -Atc "select inet_server_port(), pg_is_in_recovery()"
5431|t

-- through the pooler, a write
$ psql … -Atc "insert into routing_probe values (2,'through the pooler')
              returning inet_server_port(), pg_is_in_recovery()"
2|through the pooler|5432|f

-- the same write aimed at the standby directly
$ psql -h 127.0.0.1 -p 5431 -U simple_user -d some_db \
    -Atc "insert into routing_probe values (99,'direct to standby')"
ERROR:  cannot execute INSERT in a read-only transaction
```

Six reads in a row all answered on 5431, and the row written through the pooler is visible on both
servers afterwards — on the primary because it was written there, on the standby because streaming
replayed it. `SHOW STATS` on the admin database shows the two server rows behind `simple_db` with
their query counts apart, so the split is the pooler's own routing and not one server answering both.

What is still not true: `sharded_db` is configured with working databases and roles but nothing has
been created inside `shard0`–`shard2`, so it has no data to route; and the exporter on 9930 answers
`/metrics` with no reader here. Those are gaps beside this chain, not links in it.

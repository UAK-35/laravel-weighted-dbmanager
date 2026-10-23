# `scripts/servers-startup/` — the three test servers, on Windows

The rig this package is checked against on this machine is three processes: a PostgreSQL primary
on **5432**, a physical standby streaming from it on **5431**, and **pgcat** in front of them on
**5433** — reads to the standby, writes to the primary. This folder starts all three, stops all
three again, and builds the pooler when there is nothing to start.

Nothing here searches for an installation. The two directories that differ between machines are read
from `servers.local.cmd` beside these scripts; the file is gitignored, and its committed form is
`servers.sample.cmd`.

## 1. The machine file

```cmd
copy servers.sample.cmd servers.local.cmd
```

then replace the two tokens with this machine's directories:

| Name | What it is |
| --- | --- |
| `PG_ROOT` | the directory holding `pgsql179\` (a portable PostgreSQL tree: `bin\`, `data\`, and the server log beside them) and `standby179\` (the standby's own data directory) |
| `PGCAT_ROOT` | the pgcat checkout — the directory holding `Cargo.toml` and `target\debug\pgcat.exe` |

A fresh checkout of this package has no `servers.local.cmd`, so every script opens by refusing and
saying so. That refusal is the feature: the alternative is a script that silently starts nothing.

The one other thing these scripts need is a `pgpass.conf` under `%APPDATA%\postgresql\` with entries
for `localhost:5432`, `localhost:5431` and `127.0.0.1:5431`. It is what lets `psql` and `pg_isready`
run without a prompt, and it is where the cluster's password lives rather than in any file here.

## 2. Start order

```cmd
pwsh -File ..\setup-pgcat.ps1    rem all of the below, in this order, with the checks in front of it
start-primary.bat            rem port 5432, pg_ctl on pgsql179\data
start-replica.bat            rem port 5431, pg_ctl on standby179
pwsh -File apply-hba-scram.ps1   rem once per cluster: the two rules pgcat needs
pwsh -File build-pgcat.ps1       rem only when PGCAT_ROOT has no target\debug\pgcat.exe
start-pgcat.bat              rem 127.0.0.1:5433, with pgcat.toml from this folder
```

`..\setup-pgcat.ps1` is the list above as one command, and it is what to run when the rig's state is
not known: it reads `servers.local.cmd` once, reports a port Windows has reserved before anything
tries to bind one, compares `pgcat.toml` with the rows this rig needs, starts the two servers and
reads their ports back — a port that answers is the verdict, not an exit code — then runs the hba
rules and the build against the live pair and starts the pooler last. Every step below is still its
own script because every step has its own failure to report, and the idempotence described below is
what lets the one command be re-run: a second server is reported as already up, the rules are added
once, the crates are reused, and the build is skipped while the pooler holds its own binary open.

The two server scripts are the same files that started this rig by hand, kept here so the next
session does not have to find them: they check whether the server is already up, refuse while the
Windows service `postgresql-x64-17` is running (it serves a different cluster on the same port), and
then ask the server itself whether it is a primary or a standby.

`apply-hba-scram.ps1` and `build-pgcat.ps1` are idempotent — a cluster already carrying the rules is
left alone, and a crate already unpacked is reused — so running the list in full every time is safe.

A server that starts and then stops on `could not bind IPv4 address "127.0.0.1": Permission denied`
has not been refused by PostgreSQL: Windows has the port inside a reserved range, which Hyper-V and
WinNAT re-create at boot. `netsh interface ipv4 show excludedportrange protocol=tcp` lists the
ranges; `net stop winnat` in an elevated prompt releases them, and `net start winnat` puts the
driver back once the servers are up.

## 3. Stop order

```cmd
pwsh -File ..\teardown-pgcat.ps1   rem all of the below, in this order, then the three ports
stop-pgcat.bat               rem 127.0.0.1:5433, the PID that owns the listening socket
stop-replica.bat             rem port 5431, pg_ctl -m fast on standby179
stop-primary.bat             rem port 5432, pg_ctl -m fast on pgsql179\data
```

The reverse of the start order, because each process depends on the one after it: the pooler holds
connections to both servers, and the standby streams from the primary. Nothing enforces the order —
each script stops the server it names and nothing else — but stopping in this order is what keeps the
logs quiet, since a standby left streaming from a stopped primary spends the interval until its own
shutdown retrying a walreceiver that cannot receive.

`..\teardown-pgcat.ps1` is this list as one command, the counterpart of the setup one: it reads
`servers.local.cmd`, runs the three stops in that order, and ends by asking all three ports with
`pg_isready` — a port that still answers fails the run. Running it twice is safe: a server already
stopped is reported as nothing to stop, and the data directories are left as they are, so the setup
script starts the same three again.

Every stop reports what the start reports, in the same three parts: whether the thing was up at all
(`already stopped` is an answer, and exits `0`), what the server said when asked to stop, and the
readiness line afterwards — `pg_isready` for a server, the listening socket for the pooler. `Stopped`
is that last line and not an exit code: a port that still answers fails the run, because a second
postmaster or a child that kept the socket is exactly the state the script is there to catch. The
pooler has no shutdown command this platform can use — its admin `SHUTDOWN` sends `SIGINT`, which
Windows does not have — so `stop-pgcat.bat` names the process owning `127.0.0.1:5433`, checks that the
PID really is `pgcat.exe`, asks it to close, and forces it only if it does not.

## 4. What each file owns

| File | Role |
| --- | --- |
| `../setup-pgcat.ps1` | the one command: the start order above, with the reserved-port and pool-row checks in front of it |
| `../teardown-pgcat.ps1` | the other one command: the stop order below, followed by all three ports asked for silence |
| `servers.sample.cmd` | the committed form of the machine file: tokens where paths go |
| `start-primary.bat` | the primary on 5432, and its role printed after `pg_isready` answers |
| `stop-primary.bat` | the primary on 5432, with `pg_ctl -m fast` on `pgsql179\data`, and the port read back as silent |
| `start-replica.bat` | the standby on 5431, and `pg_is_in_recovery()` read back from it |
| `stop-replica.bat` | the standby on 5431, with `pg_ctl -m fast` on `standby179`, and the port read back as silent |
| `apply-hba-scram.ps1` | the `pg_hba.conf` rules, in the primary's and the standby's file, then `pg_reload_conf()` on both |
| `build-pgcat.ps1` | the patched crates and `cargo build`, for this platform |
| `start-pgcat.bat` | the pooler in its own window, then `SHOW POOLS` from its admin database |
| `stop-pgcat.bat` | the pooler: the PID listening on `127.0.0.1:5433`, checked against `tasklist`, closed and — only if it answers nothing — forced |
| `pgcat.toml` | the config the pooler is started with: 127.0.0.1:5433, replicas on 5431, `simple_db` splitting reads from writes |

`pgcat.toml` is the checkout's root config with the local rows in it, copied here so a run does not
depend on which directory the pooler was started from — the config path is passed explicitly.

## 5. The build script, and why a build script exists

A plain `cargo build` does not finish on this platform, and none of the reasons are pgcat's logic.
`build-pgcat.ps1` fixes the first two and records the third:

- `ring` 0.16.20 and `rustls-webpki` 0.100 and 0.101 embed `.der` files with `include_bytes!`, and
  this machine refuses to read that extension whatever the file contains (`Access is denied`, os
  error 5). The script unpacks each `.crate` from the cargo cache into `%LOCALAPPDATA%`-side
  `pgcat-patched-crates`, renames the `.der` files to `.bin`, follows every include path that names
  one, adds one crate-level `allow` for a newer rustc's lints, and points cargo at those copies with
  `--config patch…`.
- the checkout itself carries two `#[cfg(unix)]` guards (the `nix` signal behind `SHUTDOWN`, and
  socket2's `with_retries`). They are in the working tree, not in this folder.
- the cleartext-password obstacle above is `apply-hba-scram.ps1`'s job, not the build's.

The build rewrites the checkout's `Cargo.lock` — the three crates resolve to paths now — so a
`git checkout -- Cargo.lock` in the checkout is what restores it once the patch flags are no longer
used. `docs/local-postgres-and-pgcat.md` §2.5.1 is the long form of all three.

## 6. Proving the routing

```cmd
set PGPASSWORD=simple_user
"%PG_ROOT%\pgsql179\bin\psql.exe" -h 127.0.0.1 -p 5433 -U simple_user -d simple_db ^
  -Atc "select inet_server_port(), pg_is_in_recovery()"
```

`5431|t` is a read on the standby; an `INSERT … RETURNING inet_server_port()` answers `5432|f`,
because a write only ever goes to the primary. The client's database is the **pool** name
(`simple_db`), not the shard's (`some_db`); `-d some_db` is refused with `No pool configured`.

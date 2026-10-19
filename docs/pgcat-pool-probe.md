# Is a RUNNING pgcat that holds no pool a fault?

A design record for `src/Pgcat/PoolProbe.php`, the flipper's answerability repair
(`PgcatConfigFlipper::poolFault()`, `refusalAfterApply()`, `refusalFor()`, `refusedPool()`), and the
`pool_probe` reading that `/health/db` and `db:doctor` publish through
`PgcatConfigFlipper::poolProbeVerdict()`.

The flipper's self-heal had exactly one question for supervisord — is pgcat RUNNING — and a pooler
can answer that question truthfully while being the wrong thing to serve. A container stood with
`FATAL: No pool configured for database: "reader_db_2"` for half an hour: supervisord called pgcat
RUNNING, `pgcat mode` compared two files and found them in step, and every read to a pool the
application was connected to failed. Nothing in the package could see it, because the only thing
that knew was the pooler, and nobody asked. This document records what is asked instead, what each
answer is worth, and why the asking is switched off until an installation asks for it.

Everything below is implemented in `PoolProbe`, in `PgcatConfigFlipper`'s repair and
`poolProbeVerdict()`, in `DatabaseHealthController`, and in `DbDoctor::poolProbe()`. The rules
are pinned by the tests named at the end.

---

## The answer in one line

**A running pooler that refuses a pool its own configuration declares is a fault the flip repairs,
and the evidence is the pooler's own refusal; a pooler that answered nothing at all is reported and
never repaired, so a probe pointed at the wrong address cannot fail or restart a healthy
installation; the reading itself asks the pooler for its own inventory first — pgcat's admin console
lists every pool the process serves, and a declared pool missing from that list is the fault, so the
verdict does not depend on how the pooler answers an anonymous startup packet; one wave of startup
packets — every declared pool beside a control name no configuration declares, all opened at once
under a single timeout — is the fallback where that console cannot be read, and there an `answered`
pool is proof only if the control was refused; and the reading only
ever happens where an installation has armed `swrr.pgcat.pool_probe.enabled`, because it is the one
surface in the package that opens a socket.**

---

## Why this needed deciding at all

### 1. "Up" is not the same fact as "serving"

This package's other repairs all begin from a state supervisord can observe. A program that is not
RUNNING can be started, and `SupervisorStep::FAULT_NOT_RUNNING` is the whole vocabulary for it. The
state that has no observer is a program that is RUNNING and serving the wrong thing: a pooler holds a
configuration with no pool for a database the application is connected to. It is up by every measure
the supervisor has, the file on disk is one of the two variants a flip would write, and the reads
fail anyway. The package could describe this only as an application error — the pooler's own
`No pool configured for database` reaching a query — and a deploy would have had to read its logs to
learn about it.

### 2. The answer has to be the pooler's, and it has to need nothing else

The tempting answer is to connect as a client and ask. That would make the measurement depend on the
pool it is measuring: a credential, a reachable backend, and a read through the very pool that may
be absent. So the probe is the smallest exchange that makes the pooler state the fact itself. A
PostgreSQL client names a pool in its startup packet's `database` field, and a pooler that resolves
the pool *before* it authenticates answers a name it does not hold with an `ErrorResponse` saying
`No pool configured for database`. That is a refusal, it is the pooler's own sentence, and it arrives
without a password, `psql`, `PDO`, or a database connection. The console reading below sends the
pooler's own credential, but only to the pooler and only to read its list; this startup packet is
the reading that needs nothing at all, which is why it is kept as the fallback. The pooler's
admission is the evidence.

### 3. It cannot be a proof of health, and the reading has to say so

The exchange rests on the pooler deciding whether a pool exists before it asks anyone to
authenticate, and not every pgcat does. The pooler this package is deployed against answers an
`AuthenticationRequest` — `R`, with a fresh SCRAM challenge each time — to *every* name it is given:
a pool the applied configuration declares, a pool only the other variant declares, a pool commented
out of both, and a name no file contains. An authentication request therefore proves the pooler is up
and willing to talk; it does not prove the pool exists. On that pooler `missing` is never observed,
and `answered` is a statement about the pooler rather than about the pool. That limitation is written
down in `PoolProbe` beside the measurement rather than left for a reader to mistake a green row for
proof. Telling the two apart needs no credential at all: it needs a name whose absence is already
known, which the same exchange can ask, and that is what the control name is for — but the control is
the fallback's question, not the reading. A pooler that authenticates first still knows which pools
it holds, and pgcat states it: the admin console and its `SHOW POOLS` list are asked first, and where
that list can be read the startup packets are not sent at all — see *The pooler's own inventory,
asked first* below.

---

## The three answers, and what each one is worth

`PoolProbe::classify()` returns one of three, and the three call for three different things.

| answer | what the pooler said | what the package does with it |
|---|---|---|
| `answered` | it asked for a password, reported parameters, or said the backend is ready | nothing: the pool exists, or the pooler's own `SHOW POOLS` list says it does. On the fallback — a console that could not be read — the answer proves nothing until the control beside it is refused |
| `missing` | `No pool configured for database: …` | the flip repairs it; `/health/db` fails `degraded`; `db:doctor`'s row is `FAIL` |
| `silent` | nothing usable — the address refused, the read timed out, or no bytes came back | reported only: `/health/db` is `warned`, `db:doctor`'s row is `WARN`, and nothing is restarted |

A pool can also be published as `indeterminate`, which is not an answer `classify()` returns: the
pooler answered it, and it answered the control name the same way, so the answer is not evidence the
pool exists. It is neither repaired nor failed — the pooler's ordering is a property of the pooler —
but a reading containing one is never `answered`, and past the boot window it is `warned`.

`missing` is the one answer a flip acts on because it is a statement about the pooler's own
configuration — the file a flip writes — rather than about the database behind it. `silent` is the
supervisor's question at best (`not_running`) and a probe on the wrong address at worst, and neither
is repaired here: a probe that mistook a wrong port for a refusal would restart a healthy pooler
every minute for ever. The line `PoolProbe` draws is kept in the repair as well — `refusedPool()`
returns only the first `missing` pool and skips every other answer — so no number of retries can turn
silence into a refusal.

---

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | leave it to the application's error logs | the package cannot report the state, and a deploy gate cannot see it | **rejected** — this is the problem |
| B | connect as a client and run `SELECT 1` | needs a credential and a reachable backend, and goes through the pool being measured | **rejected** |
| C | ask supervisord harder | supervisord can only say the program is up, which is exactly the fact that hid this | **rejected** |
| D | parse pgcat's file and infer the pool set | the file and the running program are two different facts, and the file was already in step while the reads failed | **rejected** |
| E | fail the boot window when a pool is missing | a starting container's pooler is legitimately still coming up, so this would fail healthy deploys | **rejected** |
| F | treat `silent` as a refusal too | a probe on the wrong port would restart a healthy pooler every minute | **rejected** |
| G | a PostgreSQL v3 startup packet, read as `answered`/`missing`/`silent` | no credential, no backend, the pooler's own sentence, and a `not_running` fault left where it belongs | **chosen** |
| H | repair only `missing`, past the boot window, and make the whole read opt-in | a state nothing else can see is repaired without inventing faults or opening sockets nobody asked for | **chosen** |
| I | authenticate as a client with a pool user's credentials | a password per pool, and a login that only distinguishes pools on a pooler that resolves them after auth | **rejected** — it measures the credential rather than the pools, and not on the ordering this exists for |
| J | ask pgcat's admin console, `SHOW POOLS`, with the credentials in the pooler's own file | one credential already on disk, sent to its owner, and a list in the process's own words | **chosen** — the primary reading, with G as its fallback |
| K | leave the unprovable case green and say so in the sentence | no credential at all, but the row still cannot fail the fault it exists for | **rejected** — that is the silent fault, one sentence louder |

### B — a query through the pool it is measuring

The direct test of "can a client reach this pool" is a client reaching it, and that is the one thing
this reading must not do. It would need credentials the package does not hold, a backend that may be
the reason the pool is unusable, and a connection that the pool being absent makes impossible — so
the measurement would fail for the fault's own reason and for every unrelated one, indistinguishably.
The startup packet keeps the question on the pooler's side of the pool.

### E — fail the container when a pool is missing

A missing pool is a real fault, and it is tempting to fold it into the boot window so a deploy stops.
But the boot window exists for a container that is still starting, and a pooler a second old may not
have re-read its file yet. Failing on a missing pool inside the window would fail the deploys the
window was written to protect. So the judgement waits for `window->closed()`, the same line the
reader-window verdict draws, and inside the window the answers are still reported without a verdict
being invented about them.

### F — silence as a refusal

The cheapest way to make the reading fully covered is to repair `silent` too. It is rejected because
the two answers are about different things. A pooler that refused a pool is holding the wrong file;
a pooler that said nothing is not listening, or is not the program this probe was aimed at. Repairing
the second would let a mistyped port restart a perfectly healthy pooler on every poll, which is worse
than the fault it was meant to catch. `silent` is reported, warned about, and never acted on.

---

## The chosen mechanism, in full

### The fallback question

When the console cannot be read, `PoolProbe::ask($host, $port, $database, $user, $timeoutMs)` sends
a PostgreSQL v3 startup packet
naming the pool in its `database` field and reads the first whole message back. The type byte decides
everything but the refusal: `R` is an authentication request and `S`/`K`/`Z`/`N` follow a trusting
one, so those are `answered`; `E` is an error, and its `M` field is read for pgcat's own
`No pool configured for database` — compared case-insensitively and never reworded, because it is
pgcat's vocabulary and a paraphrase would stop recognising the fault the day the wording moved. Any
other error is a pool that exists with a different problem, which is an `answered` rather than a
`missing`. No bytes, or a read that timed out, is `silent`, with the address and the socket's own
reason beside it.

### Why the fallback is one wave

A sweep that asked each name in turn made the health endpoint's cost the size of the configuration:
with N declared pools and a pooler that accepts connections but says nothing, that was N exchanges
each waiting out `timeout_ms`, and `/health/db` got slower the more pools the installation added —
the check grew with the thing it was checking. The serial shape was the fallback's whole behaviour,
not a detail of it.

`PoolProbe::askMany()` is the same question asked once. It opens one socket per name with
`STREAM_CLIENT_ASYNC_CONNECT`, keeps every socket non-blocking, writes each startup packet as its
socket becomes writable, and reads each first message as it arrives, all inside one `stream_select()`
loop bounded by a single deadline. The deadline covers the connects, the packets and the reads; a
name still unanswered when it expires is `silent`, with the same evidence a single exchange produces.
The answers come back in the order the names were asked, so a caller maps them positionally and a
pooler's refusal lands on the pool it names rather than on a neighbour.

The sentinel travels in that same wave. It is asked even when a refusal will make it unnecessary —
the refusal is not known until the answers are in, and waiting for it before asking the sentinel
would put the second timeout back. Its answer is simply not published when a `missing` pool already
proves the ordering, which is the same shape the serial sweep produced; the difference is one
connection inside the budget rather than a second wait outside it.

What was rejected: `pcntl_fork()` or a process per pool (not available in every FPM image, and a
process tree to reap on a health route); `curl_multi` (it speaks HTTP, not the PostgreSQL handshake);
ext-parallel (a non-default extension this package would have to require); and a synchronous connect
followed by non-blocking reads (the connects are where a silent pooler spends its time, so that
shape would still be serial).

The measurement that settled it is in `PoolProbeSweepTest`, whose fixture accepts every connection
before answering any — a serial sweep cannot get past its first name there, so the test fails rather
than merely slows down. Twelve silent pools at a 500 ms budget measured 0.514 s through `askMany()`
and 12.48 s through the one-name path, on the same fixture. An installation that injected its own
`$poolProbe` keeps that closure's transport and is still asked one name at a time; the shipped path
is the batched one.

### The control name

An `answered` pool is only *proof* that the pool exists if the pooler decides whether a pool exists
before it authenticates, and that ordering is not something the pool's own answer can report: a
pooler that asks for a password first returns the same `R` for a pool it holds, a pool only the
other variant declares, and a name no file contains. So the sweep asks one more name: a name no
configuration declares, whose absence is therefore known.

`PoolProbe::controlName()` builds it — `weighted-dbmanager-control-` and sixteen random hexadecimal
characters. The suffix is random rather than fixed so an installation cannot own the control by
having a pool of that name: the question is whether the pooler can tell a name it does not hold from
one it does, and a name written into a file would be a pool like any other. The prefix is what makes
it recognisable in a report.

`PoolProbe::discriminates()` reads the control's status, and the reading is three-valued on purpose:

| control answer | what it says about the pooler | what the sweep publishes |
|---|---|---|
| `missing` | it resolves a pool before it authenticates, so the `answered` pools are pools it holds | `discriminates: true` |
| `answered` | it authenticates first and answers every name, so the `answered` pools could not be proved | `discriminates: false`, and every `answered` pool is published as `indeterminate` |
| `silent` | it said nothing usable about the control, so neither can be concluded | `discriminates: null` |

A control that was answered does not leave the pools `answered` with a caveat beside them: the same
exchange produced that answer for a name no file declares, so it would have produced it for a pool
the pooler does not hold. Every pool that answered is therefore published as `indeterminate`, with a
sentence naming the control and the pool, and `answered` is false. Past the boot window the reading
is `warned` rather than passing — a green row is a claim that the pooler is answerable for its pools,
and this is the one reading that could not make it — while `failed` stays for `missing` alone.

The control belongs to the fallback. When the console's list can be read there is nothing left for
it to settle, so it is not asked and both `discriminates` and `control` are `null`. On the fallback
it is asked only when every declared pool answered: a pool the pooler refused is already the proof —
the refusal is a statement only a pooler that reads pools first can make — so asking the control
there would be a socket spent on a fact the sweep already settled, and `control` is `null`.

### The pooler's own inventory, asked first

A control that is answered used to end the reading: the pools could not be proved, and the row said
so. But leaving it there is the fault this record exists for, only quieter — a declared pool the
pooler does not hold still fails every read to it, and a green row that says "not proof" is a green
row. The pooler knows which pools it holds, and pgcat says so itself: its admin console is the same
listener under the database name `pgcat`, authenticated with `general.admin_username` and
`general.admin_password` from the file the process is running on, and `SHOW POOLS` lists what it is
serving. `PoolProbe::adminPools()` asks exactly that, and it is the first thing `poolProbeVerdict()`
asks — one connection for the whole sweep, before any startup packet.

That ordering is the point rather than an optimisation. What a startup packet's answer means depends
on when the pooler resolves a pool, which is a property of the pooler rather than of its pools; the
console's list is the process stating what it serves, so the verdict rests on the pooler's own
inventory. Where the list can be read, `pools` is built from it — every declared pool it carries is
`answered`, every one it does not is `missing`, with a sentence naming the console — `verified` is
true, and neither the per-pool sweep nor the control is asked at all: `discriminates` and `control`
are `null`, because there was nothing left for them to settle. It is also the cheaper reading when a
pooler holds many pools: one connection rather than one per pool.

The conversation is the same protocol with one extra step: a startup packet naming `pgcat`; the
console's challenge answered with `md5` . md5(md5(password . user) . salt) for an md5 console and
with the password itself for a cleartext one; then `Q` `SHOW POOLS`, whose rows are read by the
column the console names the pool in rather than by position — `pool` on the console this package
was first measured against, `database` on upstream pgcat, whose `v1.2.0` row description opens
`database, user, pool_mode, …` (pgbouncer's word for the name a client connects with). Both names
are read, `pool` first; an answer carrying neither is refused as unreadable rather than read by
position. A console that asks for SCRAM-SHA-256 is refused
rather than guessed at — a verifier is not a password, and a probe that pretended otherwise would
be a login attempt dressed as a reading. So is a file that names no `admin_username`: pgcat's
defaults are a credential this package should not assume.

What it costs, and what it does not. It is the only place the probe sends a credential, and the
credential is the pooler's own, read out of the file the package already reads for the port and the
pool names: nothing new is configured, nothing leaves the container's exchange with its own pooler,
nothing is written, and the password never appears in `evidence`, a row or a payload — only the
console's own refusal does. The client-facing exchange still sends no password at all.

What happens when it cannot be read. A console that refuses — no credentials in the file, a password
it will not take, a SCRAM challenge, silence — drops the sweep back to the front door: one startup
packet per declared pool, then the control name, and `discriminates` and `verified` say what those
answers are worth exactly as they did before the console existed. The console's own refusal stays
published as `admin`, so a reader can see why the inventory was not the reading. An installation
whose pooler has no admin credentials is therefore unproved rather than told it is broken: the row
*warns* once the boot window has closed, which is the honest replacement for a green row. And a pool
missing from a list that *was* read is a `FAIL` exactly as a front-door refusal is, in both
`/health/db` and `db:doctor`'s row.

What it deliberately does not do: repair. A repair re-applies the file and restarts the pooler, and
the flip's reading of the front door is the one that triggers it — a pooler that keeps answering
every name would be restarted on every poll if a console reading could drive the repair, which is
the same line `silent` draws one section up. The console diagnoses; the repair stays where the
pooler's own refusal is.

### Which pools are asked about

The pool names come off the file the flip is about, not a second configuration. `PoolProbe::declaredPools()`
reads each `[pools.<name>]` header's first segment — `writer_db_1.users.0` is not a pool a client asks
for, and a file whose only headers are sub-tables still declares `writer_db_1` — so the list is the
names the applied variant claims the pooler should be serving. A variant that declares no pool asks
nothing: there is nothing the file claims the pooler holds, so there is nothing to be missing.

### Where the flip repairs it

The repair is the flip, and it runs in two places that are the same question asked at different
moments. A poll that finds the mode *already* applied asks `poolFault()` — one read, because the mode
never moved and nothing was just restarted — and, on a `missing` pool, re-applies the variant and
records the `repaired` kind. A run that has just *applied* a mode asks `refusalAfterApply()`, which is
the same read with the retry a pooler that was signalled a moment ago needs: a reload returns before
pgcat has re-read anything and a restart returns inside supervisor's `startsecs` window, so a refusal
is re-read with the same bounded attempts and delay a repair's starts use, and one that survives
escalates to `restart_command` before the pools are read back once more. The two questions are one
read, `refusalFor()`, with the policy as an argument: the count and the delay have one home
(`startAttempts()` and `startRetryDelayMs()`, which `status()` reports and the start loop is bounded
by), so the poll path and the apply path can disagree about *when* to ask but not about how often or
how long, which is the drift two loops over one read would eventually produce — and the source scan in
`PgcatConfigFlipperTest` is what keeps a second reader from arriving. Both paths record the run,
and a repair is counted in the state file as well — `pool_repairs` and `last_pool_repair_at`, printed
by `db:pgcat-flip --status` and reported as `db:doctor`'s `pool repairs` row from the same reading —
because a run's kind cannot tell a one-off from a pooler that keeps needing repair. The repair
records the pool it was refused for and the mode whose variant declares it, as `last_repair_pool`
and `last_repair_mode` beside the count, printed as rows of their own: the count opens the question
*which* pool, under *which* variant, and the table answers it without the run's own sentence.

### Where the surfaces read it

`PgcatConfigFlipper::poolProbeVerdict()` is the one reading, and both surfaces ask for it by name so
they cannot disagree. It publishes `enabled` (the switch), `applicable` (armed, applicable, and the
mode declares a pool), `answered` (true when every pool answered, null when nothing was asked),
`pools` (one entry per declared pool with its status — `answered`, `missing`, `indeterminate` or
`silent` — and the probe's sentence),
`admin` (the console's status, the pool names it listed and its own sentence — the reading itself —
or the refusal that sent the sweep to the front door, or `null` when no console was configured),
`discriminates` (true, false or null for the control's reading, which is the fallback's question and
`null` when the console answered), `control` (the control exchange, or `null` when a refusal already
proved the pooler or the console settled it), `verified` (whether an `answered` pool is proof it
exists), `judged` (whether the boot window has closed, so a surface can tell a reading it has not
judged from one it has), `host`,
`port` and `address` (the endpoint the packets were actually sent to), `failed`, `warned`, and
`reason`. It deliberately
records, restarts and throws nothing: a status reading that restarted a pooler would not be one an
endpoint could be polled with. `/health/db` embeds it as the `pool_probe` block and `db:doctor`
prints it as the `pool probe` row, and the block is published whole
rather than reduced to a boolean, because the three answers call for different work.

The endpoint is read off the probe's own answer rather than off the settings, because
the fact the two surfaces have to report is where the packet went rather than where the settings
meant it to go — an installation that injects a probe of its own is reported at that probe's
endpoint. It is `null` when nothing was asked. That matters because a probe aimed at the wrong port
is the one fault that looks exactly like a pooler that is down: both are `silent`. A row that listed
only the pool names would leave an operator with an installation whose every pool is silent and no
way to tell a stopped pooler from a mis-aimed probe, so the row and the block both name the
address, and `db:doctor` prints it after the sentence — `— probed tcp://127.0.0.1:6432`.

### What is read back, and where it is not

The pooler is asked in the repair and nowhere else, and the repair only asks where the probe is
armed — `refusedPool()` returns null with no closure behind it, which is the shipped state
(`swrr.pgcat.pool_probe.enabled` is `false`). Everywhere else a state is the commands' own: the copy,
and the supervisor command the installation configured (a reload when `use_reload` is on, otherwise a
restart) exiting `0`. The audit below is what that costs, place by place — the claim in the left
column is what a reader of that surface sees.

| the claim | the evidence it actually rests on | who reads it |
|---|---|---|
| `applyCurrentState()` → `flipped` | the copy and the supervisor command exiting `0`, when the probe is not armed | a pipeline reading `kind`, the scheduler's log, the `flip_runs` row |
| `applyCurrentState()` → `flipped`, probe armed | the same commands, when the variant declares no pool: `configuredPools()` is empty, so `refusalAfterApply()` has nothing to ask and returns null | as above |
| `forceMode()` → `flipped` | the same commands and nothing more: this path has no boot window, no `poolFault()` and no `refusalAfterApply()` | `db:pgcat-window-flip` at every boundary, and `db:pgcat-flip --force-mode` run by hand |
| the mode-unchanged repair → `repaired` | the refusal read from the pooler before the re-application, then the re-application's exit codes; the pools are *not* read again | the `pool_repairs` and `flip_runs` rows, and `--status --json` |
| `swap()`'s supervisor command | the command exiting `0`. The program's state is read *before* the swap (`SupervisorStep::inspect()`) and never after it | the run's own outcome: a restart that leaves pgcat `FATAL` reads like one that worked |

The boundary task's own `SELECT 1` is not on this list and is not a substitute for one of its rows:
`ReadinessProbe` asks the *target* a mode would point at, which is a fact about the replicas, while the
question an absent read-back leaves open is whether the pooler re-read the file it was given.

Two lines are deliberately *not* findings. The transition path that does read back — the refusal
before the restart, and the pools once more after it — reports `repaired` and exits `0` when the
pooler still refuses: the file and the mode are the flip's job and both are in place, and the standing
fault is the `pool_probe` block's to report. And `startAndWait()` does read supervisord back, up to
`start_attempts` times, because a program inside `startsecs` answers `STARTING` after both a start
that will work and one that will not — the hot-swap restart in the table has no equivalent, which is
what makes the repair path a separate one rather than a retry of the same command.

### Why it is not in `healthSummary()`

It is the one reading in the package that opens a socket, and `healthSummary()` is what
`db:replica-status` and `db:pgcat-flip --status` print. Those are diagnostics an operator runs on a
host that may be the wrong side of the pooler, so adding a connect timeout to them would change their
contract rather than add a field to this one. The two surfaces that want the answer ask for it
directly, and neither is on the flip's own hot path.

`db:pgcat-flip --status --pool-probe` is the exception, and it is an opt-in rather than a second
contract: the flag asks for the reading on that run, the answer travels beside the state report
(`pool_probe`, null on every run that did not ask), and the exit code does not move — the report
is still a report. Without the flag `--status` opens no socket, so the diagnostic stays safe on a
host that cannot reach the pooler, which is the reason it was kept out of the summary.

---

## Why only failure degrades the endpoint, and why the probe is off by default

### Only `failed` folds into the health verdict

`/health/db` computes `$allHealthy` from several readings, and of the pool probe it folds exactly one:
`failed`. That is true only when the window has closed *and* a pool is `missing` — a pool the pooler
does not hold cannot be served, so the database can answer `select 1` and still not be the one the
configuration asked for, which is the same reasoning the reader-window verdict follows one step
earlier. `warned` — a pooler that answered nothing — is reported and never folded, so a probe aimed at
the wrong address cannot take a healthy installation down. `discriminates` is a caveat on a reading
rather than a state of the installation, so it is folded into nothing either: a pooler whose console
could not be read degrades nothing, it only stops the `answered` pools from being reported as
proved. And while the window is open neither `failed` nor `warned` is set, so a healthy deploy is
unaffected.
`db:doctor` follows the same line: the row is `FAIL` on a missing pool, `WARN` on silence, and not
judged inside the boot window.

### Off by default, and read as a switch

The probe is off unless `swrr.pgcat.pool_probe.enabled` says otherwise, and the switch is read the way
every other switch in the block is, so an absent or refused value leaves the pooler's answer
unexamined rather than casting a string to a boolean. Off is the default because the reading is the
only one in the package that opens a socket on a poll path: an installation opts into it, and an
installation that has not armed it pays nothing — `db:replica-status` and `--status` stay offline
(unless the status run adds `--pool-probe`, which asks on that run only), and `/health/db` reports
that the pooler is taken at supervisord's word. The host is configured rather
than derived, because it belongs to the process that is listening while a variant file is one of the
things a flip may put in front of it. The port is not configured at all: it is read from
`[general] port` in `config_path`, the file the pooler is *running on* and the target a swap renames
over, so it is the process's own statement rather than a candidate's. That is the only source —
`PoolProbe::port()` returns null when the file names no dialable port, and the flipper then arms no
probe rather than dialing pgcat's convention (`6432`) or a setting beside the probe. A guess is the
failure this exists to prevent: a probe pointed at a port the pooler does not listen on reports the
same silence as a pooler that is down, and one that lands on a PostgreSQL server is answered for
every name in that server's words and would be read as a pooler holding them all. A `port` under
`[pools…]` is never it: that is a backend's port, and a probe sent there would be measuring a
database rather than the pooler.

---

## The tests that pin it

The measurement and its vocabulary: `PoolProbeTest::test_the_startup_packet_is_a_well_formed_postgresql_v3_open()`,
`PoolProbeTest::test_the_declared_pools_are_the_section_headers_and_nothing_else()`,
`PoolProbeTest::test_a_pool_declared_by_its_sub_tables_is_read_by_the_first_segment()`,
`PoolProbeTest::test_pgcats_own_refusal_is_the_missing_pool()`,
`PoolProbeTest::test_no_answer_at_all_is_silent_rather_than_missing()`, and
`PoolProbeTest::test_the_port_is_the_one_the_poolers_own_file_names()` — the last one
read off a file whose port is `7432`, so a reading that quietly kept the convention cannot pass it.
What the flipper builds from that reading is
`PgcatConfigFlipperTest::test_the_probe_dials_the_port_the_poolers_own_file_names()`.

The repair: `PgcatConfigFlipperTest::test_a_running_pooler_that_has_no_pool_is_repaired()`,
`PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_is_not_repaired()`,
`PgcatConfigFlipperTest::test_a_flip_verifies_the_pooler_picked_the_mode_up()`,
`PgcatConfigFlipperTest::test_a_flip_whose_pooler_did_not_pick_the_config_up_is_repaired_by_a_restart()`,
and `PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_after_a_flip_is_not_repaired()`.

The verdict: `PgcatConfigFlipperTest::test_a_pool_the_pooler_does_not_hold_fails_the_pool_probe_verdict()`,
`PgcatConfigFlipperTest::test_a_missing_pool_is_not_judged_while_the_boot_window_is_open()`,
`PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_is_warned_about_and_not_failed()`,
`PgcatConfigFlipperTest::test_a_probe_that_names_only_its_host_and_port_still_names_an_endpoint()`, and
`PgcatConfigFlipperTest::test_nothing_is_asked_of_the_pooler_without_a_probe()`.

The surfaces: `DatabaseHealthControllerTest::test_a_pool_the_pooler_holds_no_pool_for_degrades_the_payload_and_is_logged()`,
`DatabaseHealthControllerTest::test_a_pooler_that_answered_nothing_is_reported_without_degrading_the_payload()`,
`DatabaseHealthControllerTest::test_an_auth_first_pooler_is_reported_without_being_failed()`,
`DbDoctorTest::test_the_pool_probe_row_fails_when_the_pooler_holds_no_pool()`, and
`DbDoctorTest::test_the_pool_probe_row_warns_when_the_poolers_answers_are_not_proof()`.

The control name: `PoolProbeTest::test_the_control_is_a_name_no_configuration_declares()`,
`PoolProbeTest::test_the_control_distinguishes_a_pooler_that_reads_pools_from_one_that_does_not()`,
`PgcatConfigFlipperTest::test_an_auth_first_pooler_is_reported_as_indeterminate()`,
`PgcatConfigFlipperTest::test_a_control_that_did_not_answer_leaves_the_proof_unchecked()`, and
`PgcatConfigFlipperTest::test_a_refused_pool_proves_the_pooler_without_a_control()`.

The console: `PoolProbeTest::test_the_console_is_asked_with_the_poolers_own_credentials()`,
`PoolProbeTest::test_a_console_that_asks_for_no_password_is_asked_without_one()`,
`PoolProbeTest::test_a_cleartext_challenge_gets_the_password_itself()`,
`PoolProbeTest::test_a_file_without_the_consoles_credentials_is_refused_without_a_socket()`,
`PoolProbeTest::test_a_console_that_refuses_the_password_is_refused_not_silent()`,
`PoolProbeTest::test_a_console_that_asks_for_scram_is_refused()`,
`PoolProbeTest::test_a_console_that_says_nothing_is_silent()`,
`PoolProbeTest::test_an_answer_without_a_pool_column_cannot_be_read()`,
`PgcatConfigFlipperTest::test_the_admin_console_is_asked_first_and_its_list_is_the_reading()`,
`PgcatConfigFlipperTest::test_a_pool_the_console_lists_is_proof_after_all()`,
`PgcatConfigFlipperTest::test_a_console_that_refuses_the_reading_leaves_the_pools_unverified()`,
`PgcatConfigFlipperTest::test_a_console_that_cannot_be_read_falls_back_to_the_front_door()`,
`PgcatConfigFlipperTest::test_a_pool_the_console_does_not_list_is_not_judged_inside_the_boot_window()`,
`DatabaseHealthControllerTest::test_an_auth_first_pooler_is_proved_by_its_own_admin_console()`,
`DatabaseHealthControllerTest::test_a_pool_only_the_console_knows_is_missing_degrades_the_payload()`,
`DbDoctorTest::test_the_pool_probe_row_warns_when_the_poolers_answers_are_not_proof()`,
`DbDoctorTest::test_the_pool_probe_row_passes_when_the_console_lists_every_pool()`,
`DbDoctorTest::test_the_pool_probe_row_fails_when_only_the_console_knows_the_pool_is_missing()`,
`DbDoctorTest::test_the_pool_probe_row_warns_when_the_console_refuses_the_reading()`, and
`DbDoctorTest::test_an_unverified_reading_is_not_warned_about_inside_the_boot_window()`.

---

## Held against a real pgcat

Everything above is a reading of bytes a test wrote, which is the right shape for the classifier and
blind to what those bytes assume. So the repository's workflow runs one job, `pgcat-live`, that starts
upstream pgcat from its published image — `ghcr.io/postgresml/pgcat:v1.2.0`, pinned by digest in the
job rather than by the tag alone, so a moved tag cannot change what is measured — with a file mounted
at `/etc/pgcat/pgcat.toml` that declares one pool, `writer_db_1`, and the console's own credentials.
`PoolProbeAgainstPgcatTest` is then handed that file (`PGCAT_LIVE_CONFIG`) and holds the three answers
against the process. The job sets `PGCAT_LIVE_REQUIRED=1`, which turns a missing fixture into a
failure rather than a skip, and checks that PHPUnit ran the class at all: PHPUnit exits `0` when a
`--filter` matches nothing, and a green job that pinned nothing is the failure this section exists to
prevent. The test skips everywhere else, so `composer test:unit` still needs no Docker.

Two assumptions did not survive the first run, which is the case for a fixture rather than an
argument:

- **The console's pool column is `database` on upstream pgcat.** `SHOW POOLS` is built from
  `PoolStats::generate_header()`, whose first column is `database`; a reading that looked only for
  `pool` refused a console it had fully authenticated against, and the whole sweep fell back to the
  front door. `PoolProbe::POOL_COLUMNS` now reads both names, and
  `PoolProbeTest::test_pgcats_own_show_pools_names_the_pool_column_database()` pins the header
  without a container beside the live test that meets the real one.
- **`v1.2.0` authenticates before it resolves a pool.** Its `Client::startup()` answers the md5
  challenge first and calls `get_pool()` only after the password, so the pool the file declares, a
  name no file declares, and the control name are all `answered`. `missing` therefore cannot be said
  by this pooler's front door at all; on it, the console's list is the only reading that can produce
  one. That is the ordering the record states, and
  `PoolProbeAgainstPgcatTest::test_a_name_no_file_declares_is_answered_too_so_the_front_door_cannot_prove_one()`
  is where it is pinned: a release that starts refusing unknown names before it authenticates
  fails that test, and this record is revisited rather than the change arriving silently.

The fixture's three answers are these: `answered` for the pool the running file declares
(`test_a_pool_the_pooler_holds_is_answered()`); `silent` for an address that is not listening
(`test_an_address_that_is_not_listening_is_silent()`); and `missing` through the reading that can
produce it — a variant declaring `writer_db_1` and `reader_db_2` held against a process that runs a
file declaring only the first (`test_a_pool_only_the_variant_declares_is_the_missing_answer()`, which
asserts the verdict's `failed`, its per-pool statuses and the sentence naming the absent pool). The
console itself is `test_the_running_console_lists_the_pool_its_file_declares()`.

What it cannot cover is stated rather than implied. The image's console asks for md5, so the
SCRAM-challenge refusal and the cleartext branch remain covered by the scripted bytes in
`PoolProbeTest`; the pooler this package is deployed against is not the upstream build the image
ships, so the fixture pins upstream's wire behaviour and this record still owns the deployed build's.
And because the fixture's backend address is one nothing listens on, the pool exists while its shard
is down — which is the point: the probe asks what the pooler *holds*, not what it can reach.

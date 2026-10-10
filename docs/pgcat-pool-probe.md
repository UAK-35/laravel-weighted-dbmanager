# Is a RUNNING pgcat that holds no pool a fault?

A design record for `src/Pgcat/PoolProbe.php`, the flipper's answerability repair
(`PgcatConfigFlipper::poolFault()`, `refusalAfterApply()`, `refusedPool()`), and the `pool_probe`
reading that `/health/db` and `db:doctor` publish through `PgcatConfigFlipper::poolProbeVerdict()`.

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
installation; and the reading only ever happens where an installation has armed
`swrr.pgcat.pool_probe.enabled`, because it is the one surface in the package that opens a socket.**

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
without a password, `psql`, `PDO`, or a database connection. The pooler's admission is the evidence.

### 3. It cannot be a proof of health, and the reading has to say so

The exchange rests on the pooler deciding whether a pool exists before it asks anyone to
authenticate, and not every pgcat does. The pooler this package is deployed against answers an
`AuthenticationRequest` — `R`, with a fresh SCRAM challenge each time — to *every* name it is given:
a pool the applied configuration declares, a pool only the other variant declares, a pool commented
out of both, and a name no file contains. An authentication request therefore proves the pooler is up
and willing to talk; it does not prove the pool exists. On that pooler `missing` is never observed,
and `answered` is a statement about the pooler rather than about the pool. That limitation is written
down in `PoolProbe` beside the measurement rather than left for a reader to mistake a green row for
proof, and telling the two apart there needs something this exchange deliberately does not have:
credentials, or a control name whose absence is already known.

---

## The three answers, and what each one is worth

`PoolProbe::classify()` returns one of three, and the three call for three different things.

| answer | what the pooler said | what the package does with it |
|---|---|---|
| `answered` | it asked for a password, reported parameters, or said the backend is ready | nothing. The pool exists, so there is nothing to repair and nothing to report |
| `missing` | `No pool configured for database: …` | the flip repairs it; `/health/db` fails `degraded`; `db:doctor`'s row is `FAIL` |
| `silent` | nothing usable — the address refused, the read timed out, or no bytes came back | reported only: `/health/db` is `warned`, `db:doctor`'s row is `WARN`, and nothing is restarted |

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

### The question

`PoolProbe::ask($host, $port, $database, $user, $timeoutMs)` sends a PostgreSQL v3 startup packet
naming the pool in its `database` field and reads the first whole message back. The type byte decides
everything but the refusal: `R` is an authentication request and `S`/`K`/`Z`/`N` follow a trusting
one, so those are `answered`; `E` is an error, and its `M` field is read for pgcat's own
`No pool configured for database` — compared case-insensitively and never reworded, because it is
pgcat's vocabulary and a paraphrase would stop recognising the fault the day the wording moved. Any
other error is a pool that exists with a different problem, which is an `answered` rather than a
`missing`. No bytes, or a read that timed out, is `silent`, with the address and the socket's own
reason beside it.

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
escalates to `restart_command` before the pools are read back once more. Both paths record the run,
and a repair is counted in the state file as well — `pool_repairs` and `last_pool_repair_at`, printed
by `db:pgcat-flip --status` — because a run's kind cannot tell a one-off from a pooler that keeps
needing repair.

### Where the surfaces read it

`PgcatConfigFlipper::poolProbeVerdict()` is the one reading, and both surfaces ask for it by name so
they cannot disagree. It publishes `enabled` (the switch), `applicable` (armed, applicable, and the
mode declares a pool), `answered` (true when every pool answered, null when nothing was asked),
`pools` (one entry per declared pool with its three-answer status and the probe's sentence), `failed`,
`warned`, and `reason`. It deliberately records, restarts and throws nothing: a status reading that
restarted a pooler would not be one an endpoint could be polled with. `/health/db` embeds it as the
`pool_probe` block and `db:doctor` prints it as the `pool probe` row, and the block is published whole
rather than reduced to a boolean, because the three answers call for different work.

### Why it is not in `healthSummary()`

It is the one reading in the package that opens a socket, and `healthSummary()` is what
`db:replica-status` and `db:pgcat-flip --status` print. Those are diagnostics an operator runs on a
host that may be the wrong side of the pooler, so adding a connect timeout to them would change their
contract rather than add a field to this one. The two surfaces that want the answer ask for it
directly, and neither is on the flip's own hot path.

---

## Why only failure degrades the endpoint, and why the probe is off by default

### Only `failed` folds into the health verdict

`/health/db` computes `$allHealthy` from several readings, and of the pool probe it folds exactly one:
`failed`. That is true only when the window has closed *and* a pool is `missing` — a pool the pooler
does not hold cannot be served, so the database can answer `select 1` and still not be the one the
configuration asked for, which is the same reasoning the reader-window verdict follows one step
earlier. `warned` — a pooler that answered nothing — is reported and never folded, so a probe aimed at
the wrong address cannot take a healthy installation down. And while the window is open neither is
set, so a healthy deploy is unaffected. `db:doctor` follows the same line: the row is `FAIL` on a
missing pool, `WARN` on silence, and not judged inside the boot window.

### Off by default, and read as a switch

The probe is off unless `swrr.pgcat.pool_probe.enabled` says otherwise, and the switch is read the way
every other switch in the block is, so an absent or refused value leaves the pooler's answer
unexamined rather than casting a string to a boolean. Off is the default because the reading is the
only one in the package that opens a socket on a poll path: an installation opts into it, and an
installation that has not armed it pays nothing — `db:replica-status` and `--status` stay offline, and
`/health/db` reports that the pooler is taken at supervisord's word. The address is configured rather
than derived, because it belongs to the process that is listening while a variant file is one of the
things a flip may put in front of it. The port defaults to pgcat's own convention, `6432`, rather than
PostgreSQL's `5432`, because a PostgreSQL server answers every name in its own words and would be read
as a pooler holding them all — which is the mistake this record exists to keep out of the repair.

---

## The tests that pin it

The measurement and its vocabulary: `PoolProbeTest::test_the_startup_packet_is_a_well_formed_postgresql_v3_open()`,
`PoolProbeTest::test_the_declared_pools_are_the_section_headers_and_nothing_else()`,
`PoolProbeTest::test_a_pool_declared_by_its_sub_tables_is_read_by_the_first_segment()`,
`PoolProbeTest::test_pgcats_own_refusal_is_the_missing_pool()`, and
`PoolProbeTest::test_no_answer_at_all_is_silent_rather_than_missing()`.

The repair: `PgcatConfigFlipperTest::test_a_running_pooler_that_has_no_pool_is_repaired()`,
`PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_is_not_repaired()`,
`PgcatConfigFlipperTest::test_a_flip_verifies_the_pooler_picked_the_mode_up()`,
`PgcatConfigFlipperTest::test_a_flip_whose_pooler_did_not_pick_the_config_up_is_repaired_by_a_restart()`,
and `PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_after_a_flip_is_not_repaired()`.

The verdict: `PgcatConfigFlipperTest::test_a_pool_the_pooler_does_not_hold_fails_the_pool_probe_verdict()`,
`PgcatConfigFlipperTest::test_a_missing_pool_is_not_judged_while_the_boot_window_is_open()`,
`PgcatConfigFlipperTest::test_a_pooler_that_answered_nothing_is_warned_about_and_not_failed()`, and
`PgcatConfigFlipperTest::test_nothing_is_asked_of_the_pooler_without_a_probe()`.

The surfaces: `DatabaseHealthControllerTest::test_a_pool_the_pooler_holds_no_pool_for_degrades_the_payload_and_is_logged()`,
`DatabaseHealthControllerTest::test_a_pooler_that_answered_nothing_is_reported_without_degrading_the_payload()`,
and `DbDoctorTest::test_the_pool_probe_row_fails_when_the_pooler_holds_no_pool()`.

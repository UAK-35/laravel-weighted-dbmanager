# The read path's cancellations: 40001, 57014, and who answers them

This package chooses the endpoint a read lands on. The endpoint can cancel that read in two ways
that look alike to a caller and are not the same failure: SQLSTATE `40001`, a conflict with
recovery, and SQLSTATE `57014`, the endpoint's own statement timeout. This is the package's record
of both: what each cancellation is, why the read and write caps differing is what makes escalation
possible at all, what this package deliberately leaves to the application, and the readings it
publishes instead of answering them.

Every figure in this record has a recipe in [Retrieval recipes](#retrieval-recipes), marked here as
`R1` … `R9`. Each recipe is a command a reader runs against their own installation and the shape it
should print; a number in the prose with no recipe behind it is not a claim this record makes. The
figures table at the end maps each one to the recipe that produced it.

## The two cancellations

| SQLSTATE | PostgreSQL's condition | PostgreSQL's sentence | What happened | What ends it |
|---|---|---|---|---|
| `40001` | `serialization_failure` | `canceling statement due to conflict with recovery` | The read ran on a standby and its snapshot still needed a row version the primary's cleanup wanted to remove. Unless the primary was asked to hold those versions, the standby cancels the query rather than delay recovery. | Re-running it usually completes, because the cleanup that forced the conflict is done. `R3` counts these. |
| `57014` | `query_canceled` | `canceling statement due to statement timeout` | The statement exceeded the endpoint's own `statement_timeout`. Nothing about the data; the cap was reached. | Re-running it on the same endpoint reaches the same cap. It needs a larger cap, or a different endpoint. `R1` reproduces it in one session. |

`40001` also names a genuine serialization failure on a primary, and that failure's sentence is a
different one: `could not serialize access due to read/write dependencies among transactions`
(`R6`). The sentence is therefore the pattern to match, not the number. A query or a metric filter
keyed on `40001` alone counts writes that lost a race; one keyed on the phrase counts the standby
cancellations this record is about.

The distinguishing fact is what ends each one, not which is worse. Both are a failed read. A
conflict with recovery is an *interrupted* statement, and the endpoint was right to interrupt it: a
snapshot that outlives the primary's cleanup can hold the primary's bloat for as long as it runs.
A statement timeout is the cap doing its job on an endpoint whose cap was chosen to cut long reads.
What a re-run does is the practical difference: a conflict with recovery is about a moment, so the
same statement on the same standby usually completes once that cleanup has finished, while a
statement timeout meets the same cap again — nothing about the endpoint moved, so the retry is the
same read against the same limit. And inside an explicit transaction neither is re-runnable without
ending it first: PostgreSQL aborts the transaction with the statement, so the next command is
`25P02`, *current transaction is aborted*, until the caller rolls back. The same explanation is
written for the application engineer who has to write that caller in
[read-path-escalation.md](read-path-escalation.md).

## What `hot_standby_feedback` does, and what it does not

`hot_standby_feedback` is a standby's setting: when it is on, the standby tells the primary about
the oldest snapshot its queries are using, and the primary delays cleanup of row versions those
queries still need. PostgreSQL's own documentation says it can *eliminate query cancels caused by
cleanup records*, at the cost of holding dead rows on the primary for as long as the standby's
oldest query runs.

That is narrower than "prevents conflicts with recovery", and the difference matters to a reader
deciding what to do about a warning. A standby also cancels a query because a buffer pin it holds
is needed, which `hot_standby_feedback` does not prevent. So the package reports the setting as a
*reading* — on, off, or not answered — and a standby with it off is a warning rather than a proof
that reads there will fail. `R3` reads the three counters PostgreSQL keeps, and the delta between
two readings is the measured rate of each conflict class on that standby.

## Why the caps differing is what makes escalation possible

`statement_timeout` is a server setting that cuts a single statement when it has run that long on
that server. It is not in a Laravel config file: it lives in the endpoint's parameter group, or in
a role's default, and a fresh connection to the endpoint reports it (`R2`). The writer and the read
endpoints therefore each have their own cap, and the caps are independent.

The deployment this record was written against is capped at 120 s on the writer and 90 s on the
replica (`R2`, which prints `120s` and `90s`). That asymmetry is the whole reason escalation can
finish a statement a replica cut: a read cancelled at 90 s still has 30 s of headroom on the writer,
and the writer is configured to allow it. Escalation is not a feature of the connection; it is the
caller re-running the statement against another endpoint after a cancellation, and it works only
because the endpoint it lands on is configured to allow more than the endpoint that cancelled it.

Three shapes follow, and the `read caps` row reports each in its own words (`R5`):

- **The read cap is lower than the writer's.** Unequal caps are a reading rather than a fault: they
  are the configuration that makes a cancelled read recoverable somewhere else.
- **The read cap is equal to the writer's.** Escalating buys the caller no more time than re-running
  the statement where it was; the row says so.
- **The read cap is far below the writer's.** Here the difference stops being useful and becomes a
  cut the writer was configured not to make: a read this package routes there is cancelled where the
  writer would have finished it. "Far below" is less than half the writer's cap, a constant in the
  class that owns the reading (`R8`), and an uncapped read endpoint (`statement_timeout = 0`) is
  never warned about — nothing cuts a statement there. The row's repairs are the operator's: raise
  the cap on that parameter group, or take the endpoint out of the connection's `read` list.

The arithmetic is the point: with the writer at 120 s and the replica at 90 s, a statement that
needs 110 s fails on the replica and succeeds on the writer; with both at 90 s it fails on both;
with the writer at 60 s, escalating a statement that survived 50 s on the replica turns a success
into a failure. None of this is a promise that escalation succeeds — a statement that needs longer
than the writer's own cap fails there too, and this package does not know how long a statement will
run.

## Where this package stops, and what it leaves to the application

The package routes; it does not execute, and it does not re-run. Four things are deliberately the
application's:

- **Re-running, and where.** A retry layer in the connection would be re-running a statement whose
  transaction the package cannot see. A cancelled statement leaves the transaction aborted in
  PostgreSQL, so the retry would fail for a reason unrelated to the cancellation unless the caller
  ends the transaction first — a fact only the caller has.
- **The escalation target.** Whether a cancelled read is re-run against the writer, and how many
  times, is a request-layer decision. The application's own retry layer is the example, and it is
  documented in that application rather than configured here; the package ships none.
- **Pinning a read to the writer.** Laravel's `useWritePdo()` is the framework's mechanism, honoured
  by the framework's connection. The `read/write pair` row reports whether the connection declares
  the two halves a pin needs, because a pin that reaches the wrong endpoint is a silent failure;
  whether an application pins, and which statements it pins, is its own.
- **The cap values, and the alerting.** A parameter group's `statement_timeout` is a decision about
  what a read endpoint is for, and a metric or alarm on either cancellation class belongs to the
  installation's monitoring. The package neither changes a cap nor installs an alarm; `R4` is the
  recipe for counting both classes in a log, should an installation want one.

What it does instead is state what a routed read can expect before traffic arrives: the caps on both
sides, whether the standbys keep the feedback setting on, and whether the connection has the pair
that makes a pin meaningful.

## The readings

| Row | What it reads | What it says |
|---|---|---|
| `read caps` | each endpoint's own `statement_timeout`, and the writer's | The caps as a set and what they mean together: unequal caps are the reason an escalation can finish a statement the read endpoint cut, equal caps mean escalating buys nothing, and a read endpoint capped at less than half the writer's is a warning. `R5` prints the row; `R8` is the fraction. |
| `standby feedback` | each read endpoint's `hot_standby_feedback`, and whether it is in recovery at all | A standby with the setting off is a warning: it does not ask the primary to hold the row versions a query's snapshot needs, so a read chosen for it can be cancelled by a conflict with recovery. A read endpoint that is not in recovery has nothing to judge, and an answer that did not carry the setting is reported as one nobody read. |
| `read/write pair` | the connection's `read` list and `write` block | Whether Laravel can honour `useWritePdo()` on this connection at all. A reading rather than a verdict — a single-host connection is a documented installation. |

All three live in `Uak35\WeightedDbManager\Database\Weighted\EndpointReadings`, which takes one sweep
and answers all three (`R9`), because the first two are read off one connection per endpoint and
three rows each opening their own connection would be three sweeps of the same read list. The first
two are behind `db-manager.swrr.read_path.enabled` (`SWRR_READ_PATH_ENABLED`), off in the shipped
config: the sweep is one connection per endpoint, so an installation opts into it rather than
discovering it in a preflight. With the switch off the rows say the probe is not armed rather than
reporting silence as if the endpoints had been asked. `swrr.read_path.timeout_ms` bounds the connect
(`R8` names its shipped value), so a preflight cannot wait on an endpoint that is not there. The
pair reading asks nothing, so it is answered whatever the switch says.

`--connection-only` leaves the two probing rows out entirely, with the store, the supervisor and the
pooler: a build container has no endpoint to ask.

## Retrieval recipes

Each recipe assumes `psql` and a URL for each endpoint. `$WRITER_URL` and `$REPLICA_URL` are the
connection strings of the two sides; nothing here writes to a table except `R6`, which creates a
scratch table of its own. `R1`, `R2`, `R3`, `R6` and `R7` were run against a throwaway PostgreSQL
17.9 server while this record was written, and `R8` and `R9` against this tree; each printed what
the recipe below says it prints. `R4` and `R5` are the two this record could not run — one needs a
log, the other an installation with endpoints the package can open a connection to.

### R1 — `57014` in one session

```
psql "$REPLICA_URL" <<'SQL'
\set VERBOSITY verbose
SET statement_timeout = '100ms';
SELECT pg_sleep(0.5);
SQL
```

Prints `ERROR:  57014: canceling statement due to statement timeout`, and under it the `LOCATION`
line naming where in the server the cancellation was raised. `\set VERBOSITY verbose` is what shows
the SQLSTATE; without it the sentence is the same and the code is absent.

### R2 — the caps, the feedback setting, and recovery, one endpoint at a time

```
for url in "$WRITER_URL" "$REPLICA_URL"; do
  psql "$url" -Atc "select current_setting('statement_timeout'), current_setting('hot_standby_feedback'), pg_is_in_recovery()"
done
```

Prints one line per endpoint, pipe-separated, as in `120s|off|f` for the writer and `90s|off|t` for
a standby. The 120 000 ms and 90 000 ms in this record are those two lines in milliseconds;
PostgreSQL prints a duration in the largest unit that divides it exactly, which `R7` demonstrates.
`hot_standby_feedback` is meaningful on the standby only, and `pg_is_in_recovery()` is what tells
the two roles apart.

### R3 — the recovery-conflict counters

```
psql "$REPLICA_URL" -c "select datname, confl_snapshot, confl_bufferpin, confl_deadlock
                        from pg_stat_database_conflicts
                        where datname = current_database()"
```

Prints cumulative counts since the statistics were last reset, for this database on this standby.
`confl_snapshot` is the class SQLSTATE `40001` belongs to. Take the same reading twice, around the
window being judged, and the difference is the rate; a single reading is a total, not a rate.

### R4 — counting either class in a log

```
grep -c 'canceling statement due to statement timeout' "$PGLOG"
grep -c 'canceling statement due to conflict with recovery' "$PGLOG"
```

For PostgreSQL's own log. Where the log is a CloudWatch log group, the same two counts are two Logs
Insights queries, and `bin/5m` is what turns them into a rate:

```
fields @timestamp, @message
| filter @message like /canceling statement due to (statement timeout|conflict with recovery)/
| parse @message /canceling statement due to (?<kind>statement timeout|conflict with recovery)/
| stats count(*) as cancellations by bin(5m), kind
```

The two phrases are the patterns rather than the SQLSTATEs, for the reason stated above: `40001`
also names a serialization failure on a primary.

### R5 — what the package reports

```
php artisan db:doctor --connection=pgsql --json \
  | jq '.checks[] | select(.name == "read caps" or .name == "standby feedback" or .name == "read/write pair")'
```

Prints the three rows with their `verdict` and `detail`. The cap rows answer only with
`SWRR_READ_PATH_ENABLED=true`, and say the probe is not armed otherwise; the pair row is answered
either way.

### R6 — `40001` on a primary, which is the other sentence

```
psql "$WRITER_URL" -c "drop table if exists swrr_serial;
                       create table swrr_serial (id int primary key, v int);
                       insert into swrr_serial values (1, 10), (2, 20);"

( psql "$WRITER_URL" <<'SQL'
\set VERBOSITY verbose
BEGIN ISOLATION LEVEL SERIALIZABLE;
SELECT sum(v) FROM swrr_serial;
SELECT pg_sleep(1);
INSERT INTO swrr_serial VALUES (3, 30);
COMMIT;
SQL
) &

psql "$WRITER_URL" <<'SQL'
\set VERBOSITY verbose
BEGIN ISOLATION LEVEL SERIALIZABLE;
SELECT sum(v) FROM swrr_serial;
SELECT pg_sleep(2);
INSERT INTO swrr_serial VALUES (4, 40);
COMMIT;
SQL

wait
```

One of the two sessions aborts with `ERROR:  40001: could not serialize access due to read/write
dependencies among transactions`. The code is `40001`; the sentence is not the standby's, which is
why a filter reads the phrase.

### R7 — the unit a cap is printed in

```
for value in 600ms 90s 2min; do
  psql "$WRITER_URL" -Atc "set statement_timeout = '$value'; show statement_timeout"
done
```

Prints `600ms`, `90s` and `2min`: the unit is chosen by the value, not by the reader. A parser that
assumed milliseconds reads all three as something else, which is what the class's unit table and its
test pin.

### R8 — the constants behind the reading

```
grep -n 'FAR_BELOW\|DEFAULT_TIMEOUT_MS' src/Database/Weighted/EndpointReadings.php
```

Prints the fraction (0.5) and the shipped connect bound (2 000 ms), each beside the docblock that
explains it.

### R9 — the three readings, from one class

```
grep -n 'public function caps\|public function feedback\|public function pair' \
  src/Database/Weighted/EndpointReadings.php
```

Prints `caps()`, `feedback()` and `pair()`: three readings, one object, one sweep behind the first
two.

## The figures, and the recipe behind each

| Figure | As stated here | Recipe |
|---|---|---|
| The cancellations | two, `40001` and `57014` | `R1` for the timeout; `R3` and `R6` for the two sentences `40001` carries |
| `40001` and its standby sentence | `canceling statement due to conflict with recovery` | `R3` counts the class; `R6` shows the same code with a different sentence |
| `57014` and its sentence | `canceling statement due to statement timeout` | `R1` |
| The writer's cap | 120 s (120 000 ms) | `R2` |
| The replica's cap | 90 s (90 000 ms) | `R2` |
| The headroom escalation uses | 30 s | `R2`, as the difference between the two caps |
| The fraction a read cap is warned below | 0.5 (less than half) | `R8` |
| The connect bound on the sweep | 2 000 ms | `R8` |
| The three rows | `read caps`, `standby feedback`, `read/write pair` | `R9` for the methods; `R5` for the rows a run prints |

## Tests that pin the rules

| Test | Pins |
|---|---|
| `EndpointReadingsTest::test_the_two_sides_capped_differently_is_reported_as_the_reason_escalation_works` | the unequal pair as the fact it is, in the row's own sentence |
| `EndpointReadingsTest::test_equal_caps_say_that_escalating_buys_nothing` | the equal pair, and that escalation is not claimed to help |
| `EndpointReadingsTest::test_a_read_endpoint_capped_far_below_the_writer_is_a_warning` | the warning, its sentence, and both repairs it names |
| `EndpointReadingsTest::test_a_cap_below_the_writer_but_not_far_below_is_not_a_warning` | the boundary at half, and that it is not guessed at |
| `EndpointReadingsTest::test_an_uncapped_read_endpoint_is_a_reading_and_not_a_comparison` | `statement_timeout = 0` as a reading rather than a fault |
| `EndpointReadingsTest::test_the_caps_postgres_prints_are_read_in_the_unit_they_come_in` | the units of `R7` — `90s`, `2min`, `600ms` — and `0` as a cap of nothing |
| `EndpointReadingsTest::test_a_standby_without_the_feedback_setting_is_a_warning` | the conflict class's cause, and the repair that ends it |
| `EndpointReadingsTest::test_a_feedback_setting_that_did_not_arrive_is_not_read_as_off` | a warning is a value somebody's server reported, not one nobody read |
| `EndpointReadingsTest::test_the_pair_is_read_from_the_config_whether_or_not_the_probe_is_armed` | the one reading of the three that opens nothing |
| `EndpointReadingsTest::test_the_sweep_not_being_armed_is_reported_by_both_probing_rows` | the switch, and that off is stated rather than silent |
| `DbDoctorTest::test_the_read_path_rows_ask_each_endpoint_and_report_a_refusal_as_a_silence` | the rows end to end, over the real probe: an endpoint that refused is named and no verdict moves |
| `DbDoctorTest::test_a_read_path_switch_that_is_not_a_switch_is_refused_and_reads_as_off` | the switch read as a switch, so a typo cannot open a connection per endpoint |
| `DbDoctorTest::test_the_connection_only_mode_asks_nothing_outside_the_container` | the two probing rows left out of a bounded run, and the pair reading kept |

## Known limitations

- **A cap enforced elsewhere.** An application may set `statement_timeout` per session through its
  own connection options, in which case what these rows report is what a *fresh* connection to the
  endpoint is set to. That is the server's own value and the comparison is still the right one — the
  question is whether the two sides differ — but a session-level override is not visible here.
- **A standby outside the read list.** Only the endpoints this connection routes to are asked, so a
  standby kept warm elsewhere is not a second opinion.
- **Whether a conflict happens.** `hot_standby_feedback` off makes the cancellation possible, not
  certain: what decides it is the primary's vacuum and write work, which no server reports as a
  setting, and the buffer-pin class is not prevented by the setting at all.
- **The readings are one moment's.** A cap, a setting and a replica all move; these are read when
  the command runs, which is what a preflight can say and all it can say.
- **The recipes read the servers, not the deployment's intent.** `R2` says what a cap is, not why it
  was chosen; the two repairs the `read caps` row names — raise it, or stop routing reads there —
  are a decision this record does not make for an installation.
- **The application's retry contract is written down there, not ported here.** The transaction
  boundary is the part that decides whether a re-run is safe, and it is the application's; if that
  layer is ever moved into this package, the reason for the move belongs here first.

## Files

| File | What it holds |
|---|---|
| `src/Database/Weighted/EndpointReadings.php` | the three readings, the fraction a cap is warned below, and the units a cap is printed in |
| `src/Database/Weighted/SingleHostProbe.php` | one query to one host, on a connection that is purged whatever the query did — the reading's own transport |
| `src/Console/Commands/DbDoctor.php` | the three rows, the switch that arms the sweep, and the bounded mode's list |
| `tests/Unit/Weighted/EndpointReadingsTest.php` | every shape a reading comes in, driven by an injected sweep |
| `docs/db-doctor-json.md` | the object the rows travel in, and what a gate selects on |

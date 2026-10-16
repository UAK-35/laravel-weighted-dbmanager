# The two cancellations a routed read can meet, and who answers them

This package chooses the endpoint a read lands on. The endpoint can cancel it, in two ways that look
alike from a caller and mean different things — and until this record the package knew neither of
them. What follows is what the two cancellations are, what each one's sentence says, why the
application on the other side of this library had to answer them itself, where this package stops,
and what an installation can install to be told when either happens.

## The read path this is about

A connection with a `read` list and a `write` block is what makes this package's routing a decision
at all: reads are weighted across the read endpoints, and writes go to the writer. The deployment
the recording came from is that shape at its simplest — one writer behind a proxy and one read
replica, the replica weighted far higher than the writer, which is what an installation is asking
for when it says reads are cheap and should go to a replica.

The two sides are capped **differently on purpose**. In that deployment the writer's parameter group
sets `statement_timeout` to 120 000 ms and the replica's sets 90 000 ms. That difference is the
whole reason a statement that a replica cut can be escalated to the writer and finish there: the
writer is configured to allow a statement thirty seconds longer than the endpoint that cancelled it.
The caps are read out of RDS parameter groups rather than from any file this package reads — the
writer's group, and the replica's, which is also where `hot_standby_feedback` lives.

None of that is visible from Laravel's configuration. A connection names hosts; a parameter group
names what those hosts do. So a preflight that judged only the configuration described a read path
it had never asked about, and an installation could route every read at an endpoint capped at a
fraction of the writer's — a group with a 600 ms `statement_timeout` is one the same reading found —
while every row read well.

## The two cancellations, and the sentence each carries

Both arrive as a failed statement, and the SQLSTATE is what tells them apart:

| SQLSTATE | PostgreSQL's own sentence | What happened |
|---|---|---|
| `40001` | `canceling statement due to conflict with recovery` | The read ran on a standby. Applying WAL needed to remove row versions the query's snapshot was still reading, and the standby — unless the primary was asked to hold them — cancels the query rather than delay recovery. |
| `57014` | `canceling statement due to statement timeout` | The statement exceeded the endpoint's own `statement_timeout`. Nothing about the data; the cap was reached. |

`40001` is also the SQLSTATE of a genuine serialization failure on a primary, which is why the
sentence is the pattern to match rather than the number: a metric filter on `40001` alone counts
writes that lost a race, and a filter on the phrase counts the cancellations this record is about.

The distinguishing fact is not which is worse — both are a failed read — but what ends it. A
conflict with recovery is a statement that was *interrupted*, and the same statement run again
usually completes, because the vacuum that took its row versions is over. A statement timeout is
the cap doing its job, and running the same statement again on the same endpoint reaches the same
cap: what it needs is a different endpoint, not another attempt.

## Why the application had to answer them itself

`site/app/Database/ConcurrencyReadRetry.php`, behind `site/config/database-retry.php`, is where the
application answers both. As it stands today:

- `DB_READ_RETRY_ENABLED` (on) turns it on;
- **three** attempts, with a **25 ms** base delay and jitter between them;
- `fallback_to_primary` — an attempt that a read endpoint cancels is re-run against the write
  connection, which is the escalation the two caps exist to make useful;
- and it never re-runs inside a transaction, because a cancelled statement leaves the transaction
  aborted: the retry would fail for a reason that has nothing to do with the cancellation.

The field evidence for both failures is in the application's own
`docs/replica-conflict-errors.md`, and its shape is worth stating here because it is why the two
readings in this package exist. While the replica ran without `hot_standby_feedback`, the conflict
class ran at roughly **422** failed requests an hour (1 618 failures in 3 h 50 m over 6 472 matching
log lines). It stopped within a minute of the parameter being applied on 2026-10-13 and stayed at
zero for four hours while traffic continued; a much smaller statement-timeout class appeared in its
place (4 996 matching events in the hour before the change, none in the 102 minutes after, 28
timeout lines later). Two of the application's heavy reads are pinned to the write pool —
`site/app/Database/HeavyReadRouting.php`, behind `database.route_heavy_reads_to_writer`, using
Laravel's `useWritePdo()` — which is the application deciding the endpoint for the reads it knows
are long, rather than leaving the choice to this package's weighting.

## Where this package stops

It chooses the endpoint a read lands on, and it reports what that endpoint will do to the read
before traffic arrives. It does **not** re-run anything:

- a statement the application may have wrapped in a transaction is not one this library can
  re-run — the transaction is the application's, and a cancelled statement inside it leaves it
  aborted, so a retry layer in the connection would be re-running into a state it cannot see;
- `useWritePdo()` is Laravel's own mechanism, honoured by the framework's connection and not by
  this package's factory, so an application that pins a read does so with the framework's API and
  the pin is the application's own decision;
- and both cancellations are *facts about a request*, which is a layer this package does not sit
  above. It routes; it does not execute.

What it does instead is say what a routed read can expect: the caps on both sides, whether the
standbys keep the feedback setting on, and whether the connection has the pair that makes a pin
meaningful. Those are the three readings the rows below take, and they are readings rather than
verdicts: a cap far below the writer's is a warning, a standby without the setting is a warning, and
an endpoint that did not answer is named with what it said and moves nothing — reachability is the
health monitor's question, and a second row failing over it would be one fact reported twice.

## The rows

| Row | What it reads | What it says |
|---|---|---|
| `read caps` | each endpoint's own `statement_timeout`, and the writer's | The caps as a set, and what they mean together: caps that differ are the reason an escalation can finish a statement the read endpoint cut, equal caps mean escalating buys nothing, and a read endpoint capped far below the writer's — less than half — is a warning, because a read routed there is cut where the writer was configured not to cut it. |
| `standby feedback` | each read endpoint's `hot_standby_feedback`, and whether it is in recovery at all | A standby with the setting off is a warning: it does not ask the primary to hold the row versions a query's snapshot needs, so a read chosen for it can be cancelled by a conflict with recovery at any moment. A read endpoint that is not in recovery has nothing to judge — reads there reach a primary — and an answer that did not carry the setting is reported as one nobody read. |
| `read/write pair` | the connection's `read` list and `write` block | Whether Laravel can honour `useWritePdo()` on this connection at all: with both halves declared, a pinned statement is answered by the writer rather than by an endpoint the pooled reads use; with one of the two, it reaches the connection's own host, or the server every read already reaches. A reading rather than a verdict — a single-host connection is a documented installation. |

All three live in `Uak35\WeightedDbManager\Database\Weighted\EndpointReadings`, which takes one
sweep and answers all three, because the first two are read off one connection per endpoint and
three rows each opening their own connection would be three sweeps of the same read list. The first
two are behind `db-manager.swrr.read_path.enabled` (`SWRR_READ_PATH_ENABLED`), off in the shipped
config: it is one connection per endpoint, so an installation opts into it rather than discovering
it in a preflight, and with the switch off the rows say the probe is not armed instead of reporting
silence as if the endpoints had been asked. `swrr.read_path.timeout_ms` bounds the connect, so a
preflight cannot wait on an endpoint that is not there. The third asks nothing, so it is answered
whatever the switch says.

`--connection-only` leaves the two probing rows out entirely, with the store, the supervisor and the
pooler: a build container has no endpoint to ask, and a row that judged one would fail an image over
a network the image was always going to have at run time.

## The alarm an installation can install for itself

The measured deployment alerts on the cancellations through two CloudWatch log metric filters and
one alarm, installed by `docker-files/production/aws-settings/install-log-alarm.sh` and verified by
the script itself with `aws logs test-metric-filter` — a wrong pattern reports a flat zero rather
than failing, which is the reason the script proves its own patterns before it trusts them:

| Metric | Pattern | Alarm |
|---|---|---|
| `LPR/Database RecoveryConflicts` | `lpr-backend-conflict-with-recovery` — the phrase, not the SQLSTATE | `>= 1` per **300 s**, `notBreaching`, published to the replica-alerts topic |
| `StatementTimeouts` | `lpr-backend-statement-timeout` | none: the second class is watched, not paged, because its repair is a cap and a cap is a decision |

The phrase is the pattern rather than `40001` for the reason above — the SQLSTATE also names a
serialization failure on a primary, and the alarm is about a standby cancelling a read. An
installation adopting this package's read path can install the same pair; nothing here names a
topic, an account or a log group, because those are the installation's.

## Readings this record is the policy for

- The two rows above are the readings; `read caps` and `standby feedback` are the names a gate
  selects on, and `docs/db-doctor-json.md` is where the object is described.
- The warning is *not* a recommendation to raise a read endpoint's cap to the writer's. Another
  difference between the two sides in the measured deployment is deliberate: the tighter cap is the
  replica's, because a read that ran for two minutes on a replica is a read the replica was not
  there for. What the row says is that a cap far below the writer's is a cut the writer would not
  have made, and the two repairs — raise the cap, or stop routing reads there — are both the
  operator's.
- `hot_standby_feedback` off is not a fault of this package's either. It is a setting on a
  parameter group, and the row names it because the failure it causes arrives at the caller as an
  `40001` a minute later, with nothing in the connection's own configuration to explain it.

## Tests that pin the rules

| Test | Pins |
|---|---|
| `EndpointReadingsTest::test_a_read_endpoint_capped_far_below_the_writer_is_a_warning` | the warning, its sentence, and both repairs it names |
| `EndpointReadingsTest::test_the_two_sides_capped_differently_is_reported_as_the_reason_escalation_works` | the pair the recording was measured on, as the fact it is rather than a fault |
| `EndpointReadingsTest::test_the_caps_postgres_prints_are_read_in_the_unit_they_come_in` | the units a cap arrives in — `90s`, `2min`, `600ms` — and `0` as a cap of nothing |
| `EndpointReadingsTest::test_a_standby_without_the_feedback_setting_is_a_warning` | the conflict class's cause, and the repair that ends it |
| `EndpointReadingsTest::test_a_feedback_setting_that_did_not_arrive_is_not_read_as_off` | a warning is a value somebody's server reported, not one nobody read |
| `EndpointReadingsTest::test_the_pair_is_read_from_the_config_whether_or_not_the_probe_is_armed` | the one reading of the three that opens nothing |
| `EndpointReadingsTest::test_the_sweep_not_being_armed_is_reported_by_both_probing_rows` | the switch, and that off is stated rather than silent |
| `DbDoctorTest::test_the_read_path_rows_ask_each_endpoint_and_report_a_refusal_as_a_silence` | the rows end to end, over the real probe: an endpoint that refused is named and no verdict moves |
| `DbDoctorTest::test_a_read_path_switch_that_is_not_a_switch_is_refused_and_reads_as_off` | the switch read as a switch, so a typo cannot open a connection per endpoint |
| `DbDoctorTest::test_the_connection_only_mode_asks_nothing_outside_the_container` | the two probing rows left out of a bounded run, and the pair reading kept |

## Known limitations

- **A cap that is enforced elsewhere.** An application may set `statement_timeout` per session
  through its own connection options, in which case what these rows report is what a *fresh*
  connection to the endpoint is set to. That is the server's own value and the comparison is still
  the right one — the question is whether the two sides differ — but a session-level override is not
  visible here.
- **A standby outside the read list.** Only the endpoints this connection routes to are asked, so a
  standby kept warm elsewhere is not a second opinion.
- **Whether a conflict happens.** `hot_standby_feedback` off makes the cancellation possible, not
  certain: what decides it is the primary's vacuum and write work, which no server reports as a
  setting.
- **The readings are one moment's.** A store, a replica and a cap all move; these are read when the
  command runs, which is what a preflight can say and all it can say.
- **The application's retry contract is written down here, not ported.** `DB_READ_RETRY_ENABLED`,
  the three attempts and the 25 ms base are the application's today. If that layer is ever moved
  into this package, the reason for the move belongs here first: the transaction boundary is the
  part that decides whether a re-run is safe, and it is the application's.

## Files

| File | What it holds |
|---|---|
| `src/Database/Weighted/EndpointReadings.php` | the three readings and the sentences they are reported in |
| `src/Database/Weighted/SingleHostProbe.php` | one query to one host, on a connection that is purged whatever the query did — the reading's own transport |
| `src/Console/Commands/DbDoctor.php` | the three rows, the switch that arms the sweep, and the bounded mode's list |
| `tests/Unit/Weighted/EndpointReadingsTest.php` | every shape a reading comes in, driven by an injected sweep |
| `docs/db-doctor-json.md` | the object the rows travel in, and what a gate selects on |

# Escalating a cancelled read: 40001, 57014, and the caps that make it possible

This package chooses which endpoint a read lands on. It does not run the statement, and it does not
re-run it. So when a read comes back cancelled, two questions are the application's: *which
cancellation is this*, and *is there anywhere else to run it*. This page explains both, and then the
part that decides whether the second question has an answer at all — the caps the two endpoints were
given.

The evidence for what is said here — the measurements, the SQL that reproduces each cancellation, and
the rows a preflight prints — is the package's own record,
[read-path-cancellations.md](read-path-cancellations.md). This page is the explanation; that one is
the record, and where the two could drift the record wins.

## `40001` — a conflict with recovery

PostgreSQL reports SQLSTATE `40001` with the condition name `serialization_failure` and, on a
standby, the sentence:

```
ERROR:  40001: canceling statement due to conflict with recovery
```

The read was served by a replica, and its snapshot still needed a row version the primary was about
to clean up. A standby has two choices when that happens: delay recovery — refuse to apply the
primary's cleanup and keep serving the query — or cancel the query. It cancels. That is the right
call on the standby's side: a snapshot that outlives the primary's cleanup can pin dead rows there
for as long as the query runs, so the conflict is between one read and the health of the whole
replica.

`hot_standby_feedback` is the setting that makes this rarer rather than impossible. When a standby
has it on, it tells the primary the oldest snapshot its queries are using, and the primary delays
cleanup of the row versions that snapshot needs. PostgreSQL's own documentation says that eliminates
query cancels caused by cleanup records — a narrower claim than "prevents conflicts", because a
standby also cancels a query whose buffer pin is needed, and the setting does not prevent that. A
standby with the setting off is therefore a *risk*, not a certainty: what decides whether a
cancellation happens is the primary's vacuum and write work, which no setting reports.

What ends a `40001` cancellation is the cleanup finishing. The conflict is about a moment, so the
same statement on the same standby usually completes when it is run again a little later. That is
also why `40001` is not a statement about the data being wrong; it is a statement about the read
having been served from a replica at an unlucky instant.

## `57014` — the endpoint's own timeout

SQLSTATE `57014`, `query_canceled`, with the sentence:

```
ERROR:  57014: canceling statement due to statement timeout
```

This one has nothing to do with the data or the endpoint's health. The statement exceeded that
endpoint's `statement_timeout`, and the server cut it. The setting lives on the endpoint — in a
parameter group, or in a role's default — not in Laravel's configuration, and a fresh connection
reports it.

The consequence that matters: the cap has not moved, so re-running the statement on the same
endpoint reaches it again. A retry loop there does not recover the read; it spends the cap again and
fails again. This cancellation needs more time than the endpoint allows, or an endpoint that allows
more.

## The sentence, not the code

`40001` also names a genuine serialization failure on a primary, where two serializable transactions
conflict and PostgreSQL aborts one of them:

```
ERROR:  40001: could not serialize access due to read/write dependencies among transactions
```

Same code, different failure, different repair. A log filter or a metric keyed on `40001` alone
counts writes that lost a race together with standby reads that were interrupted; one keyed on the
sentence (`conflict with recovery`) counts the cancellations this page is about. That is why the
record's recipes match the phrase rather than the number, and why any alert built on them should
too.

## What escalation is

Escalation is not a feature of the connection, and this package has no code for it. It is the
caller deciding, after a cancellation, to run the same statement again against a different endpoint —
in practice the writer — and using the fact that *the two endpoints are allowed different amounts of
time*.

That is the whole mechanism, and it is why the caps matter more than the routing does. Write the
statement's own duration as `T`, the cap of the endpoint that cancelled it as `R`, and the target
endpoint's cap as `W`:

- The read is cut when `T > R`. That is the cancellation.
- The same statement on the writer completes when `T <= W`. That is the escalation.
- The room an escalation has is `W - R`. When `W <= R` that room is nothing, and the escalation is a
  second failure paid for with the first one's latency.

The deployment the record measures is capped at 120 s on the writer and 90 s on the replica. A read
that needs 110 s fails on the replica at 90 s and finishes on the writer with 10 s to spare. The
same read with both sides at 90 s fails both times. With the writer at 60 s it fails on the replica,
fails again on the writer, and the escalation has made the caller's situation strictly worse.

Two cautions about that arithmetic, because it is about caps rather than about durations:

- `T` is not identical on both endpoints. The plans can differ, the standby and the primary are
  different machines with different load, and the statement may wait on locks or IO whose time
  `statement_timeout` also counts. The caps say what each endpoint is *allowed*, which is the
  condition escalation needs; they do not promise the statement is as fast on the target.
- An escalated read is now on the primary. That is extra load on the endpoint every write already
  uses, and for a read that is not safe to serve from a replica for replication-lag reasons, it is a
  correctness decision as much as a performance one. Both are the application's to weigh.

### Why an *unequal* pair is the useful one

Three shapes, and the package's `read caps` row states each in its own words:

| Shape | What it means for a caller |
|---|---|
| `R < W` | The configuration escalation needs: a read cut at `R` can finish at `W`, and the room is `W - R`. |
| `R = W` | Escalating buys no time at all. The statement that was cut at 90 s on the read endpoint meets the same 90 s cap on the writer. |
| `R` far below `W` | The difference stops being useful and becomes a cut the writer was configured not to make: a read the package routed to the replica is cancelled where the writer would have finished it. |

"Far below" is the one judgement the row makes rather than reports, and it is deliberately a
threshold — less than half the writer's cap — because below that the pair is not a recovery path, it
is a replica that cuts reads the primary would have served. An uncapped read endpoint
(`statement_timeout = 0`) is never warned about: nothing cuts a statement there, so there is nothing
to escalate.

## What this package does, and what it does not

The package's side is the routing and the reading:

- It chooses the endpoint for a read, by weight and health, from the connection's `read` list.
- It publishes what a routed read can expect: `read caps` compares the endpoints' caps and says what
  the pair means; `standby feedback` reports `hot_standby_feedback` and whether the endpoint is in
  recovery at all; `read/write pair` says whether the connection has the two halves that make
  `useWritePdo()` meaningful. The first two are behind `db-manager.swrr.read_path.enabled`,
  off in the shipped config, because they open one connection per endpoint; the pair reading asks
  nothing and is always answered.
- It does not run the application's reads, retry them, escalate them, change a cap, pin a query, or
  install an alarm.

That last line is a design decision, not an omission. Four things are deliberately the application's,
and each has a mechanical reason:

**Re-running, and where.** A cancellation inside an explicit transaction leaves that transaction
aborted: PostgreSQL answers every following statement with `25P02`, *current transaction is aborted*,
until the caller rolls back. A retry layer inside the connection would be re-running a statement
whose transaction the package cannot see, and it would fail for a reason unrelated to the
cancellation. The caller is the only one that knows whether the transaction is still worth
continuing.

**The escalation target, and how many attempts.** Re-running against the writer costs the primary
load and may change what the caller sees (a read served from the primary sees writes the replica had
not yet applied). Whether a given read tolerates that, and how many times the caller tries before
giving up, is a request-layer decision. The package ships no retry policy to inherit and no count to
default to.

**Pinning a read to the writer.** Laravel's `useWritePdo()` is the framework's mechanism, honoured by
the framework's connection. The `read/write pair` row reports whether the connection declares the
two halves a pin needs, because a pin that reaches the wrong endpoint is a silent failure — but
whether an application pins, and which statements it pins, is its own.

**The cap values, and the alerting.** A parameter group's `statement_timeout` is a decision about
what a read endpoint is for, and an alarm on either cancellation class belongs to the installation's
monitoring. The package neither changes a cap nor installs an alarm. The record's recipes include one
for counting both classes from PostgreSQL's log, should an installation want to build that alert.

## A worked sequence

1. A read is routed to a replica whose cap is 90 s.
2. The statement reaches 90 s. The replica cancels it and answers SQLSTATE `57014` with
   *canceling statement due to statement timeout*.
3. The caller rolls back, if it was inside a transaction, and decides whether the read may run on the
   writer.
4. It re-runs the statement on the writer, whose cap is 120 s. The statement finishes at 110 s.
5. A caller that skipped step 3 gets `25P02` instead of its result. A caller that retried on the same
   replica gets `57014` again. A caller that escalated to an endpoint capped at 60 s turns a slow
   failure into a slower one.

The same shape holds for `40001` with one difference: the conflict is often over, so a re-run on the
*replica* may complete, while a `57014` re-run there cannot. And because a primary's `40001` is a
statement about a serializable transaction, re-running the statement alone is not a repair; the whole
transaction has to be retried with a fresh snapshot.

## Mistakes the package will not stop you making

- **Retrying `57014` on the endpoint that cancelled it.** The cap is unchanged, so the retry is the
  same read against the same limit.
- **Counting conflicts by SQLSTATE alone.** `40001` on the primary is a different sentence and a
  different failure.
- **Reading `hot_standby_feedback = on` as a guarantee.** It removes one class of conflict, not the
  buffer-pin class, and not the primary's own vacuum schedule.
- **Escalating a read whose staleness was the point.** The writer's answer includes writes the
  replica had not yet applied, so a read that was content to be a little behind becomes one that is
  as current as a write — which is not always an improvement for the caller.
- **Assuming a retry layer exists.** The package ships none, and the connection does not re-run
  anything.

## Where the details live

- [read-path-cancellations.md](read-path-cancellations.md) — the measurements, the two sentences,
  the recipes that reproduce them, and the figures with the recipe behind each.
- `Uak35\WeightedDbManager\Database\Weighted\EndpointReadings` — the caps, the feedback setting and
  the pair reading, all from one sweep.
- `docs/db-doctor-json.md` — the object the three rows travel in, for a pipeline that wants to read
  them rather than print them.

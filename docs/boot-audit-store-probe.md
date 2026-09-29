# When should the boot audit PING the primary store?

A design record for `swrr.audit.store_probe_seconds`.

The boot self-audit reports the settings that cannot do what they claim. One of them —
`swrr.primary_store.unreachable` — cannot be answered by reading configuration, because
it is a question about the network: *is the store that will serve reads actually there?*
Answering it needs a real `PING`, and a `PING` is a blocking call to something whose
whole problem may be that it does not answer. This document records when that call is
made, which alternatives were considered, and why they were not taken.

Everything below is implemented in `Support\BootAudit` and
`WeightedDatabaseServiceProvider::reportBootAudit()`. The rules are pinned by tests
named at the end.

---

## The answer in one line

**At most once per interval per installation.** The interval is
`swrr.audit.store_probe_seconds` (default `60`, `0` disables the probe), the timestamp
is stored in the audit record file beside the findings, and a probe whose timestamp has
nowhere to be written is skipped rather than allowed to run unthrottled.

---

## Why this needed deciding at all

Three facts, each of which rules out an otherwise obvious answer.

### 1. The probe can be the most expensive thing in the request

A `PING` to a *healthy* Redis is sub-millisecond. A `PING` to the store that this check
exists to catch costs a connect timeout, because the failure it is looking for — a host
that does not answer — is precisely the case where the client waits.

Measured on the originating application (Windows, PHP 8.4, `predis`, app boot via
`artisan --version`; Redis configured at `172.17.0.4`, unreachable from the shell that
ran the measurements):

| boot | store probe | wall time |
|---|---|---|
| no timestamp recorded → probe due | ran, failed | **10.066 s** |
| inside the interval | skipped | 5.012 s |
| inside the interval | skipped | 4.926 s |
| no timestamp, Redis answering (`127.0.0.1`) | ran, succeeded | 5.260 s |

The baseline for this application is ≈4.9–5.0 s, so the failed probe adds ≈5.1 s and a
successful one ≈0.3 s. The delta is the connect timeout, and it is bounded by the
client's configuration rather than by anything this package controls. Any answer that
runs the probe once per request is therefore an answer that turns a dead store into a
timeout on *every* request — the configuration error would become an outage.

### 2. "Once per process" is not a bound under PHP-FPM

The audit already guards itself against repeating work with a static flag, exactly as the
pgcat warning does:

```php
private static bool $bootAudited = false;   // WeightedDatabaseServiceProvider
```

Under FPM that guard is worth nothing for the probe: the application boots per request, so
a static means "once per request". The guard *looks* like it bounds the cost and does not.
This is the single fact that shaped the design — the throttle has to live somewhere that
outlives the process.

### 3. The store's own health flag cannot answer the question

`RedisAtomicStateStore::isHealthy()` is a flag the store lowers *after* an operation has
already failed. In a fresh process it is `true` regardless of whether Redis exists. An
earlier version of `db:doctor` trusted it and reported

```
PASS  store reachability  [redis(default)] is answering
```

while a direct `PING` on the same connection was refused. That is the one output a
preflight must never produce, so the flag was demoted from proof to evidence: if it has
already been lowered, the check short-circuits to `FAIL` without probing; otherwise a real
`PING` decides. The probe is the only thing that establishes the claim, which is why its
cost has to be bounded rather than the probe avoided.

---

## Deployment models, and what "once" means in each

| model | boots | a static guard bounds | the persisted interval bounds |
|---|---|---|---|
| PHP-FPM | once per request | one probe **per request** | one probe per interval per installation |
| Octane | once per worker, then long-lived | one probe per worker | same, and across workers |
| Horizon / queue workers | once per worker start | one per worker | same, and across workers |
| CLI (`artisan`, deploy scripts, `db:doctor`) | once per invocation | one per invocation | same, and across invocations |

Only the persisted form bounds all four. It is also the only form that keeps a deploy
script and the web tier from each probing independently.

---

## The candidates

| # | mechanism | what it does where it matters | verdict |
|---|---|---|---|
| A | no probe; trust `isHealthy()` | reports health it never verified | **rejected** — the false `PASS` above |
| B | probe on every boot | under FPM: one timeout per request when the store is dead | **rejected** — turns a config error into an outage |
| C | `static` once-per-process guard | under FPM: one probe per request — no bound at all | **rejected** — the guard only appears to work |
| D | probe lazily, on the first pick | picks are rare and unpredictable (connections are cached; Octane persists them) | **rejected** — silent until traffic, and reports late or never |
| E | probe in `db:doctor` only | operator-run, not automatic | **rejected as the only mechanism** — the audit exists to speak before traffic arrives |
| F | probe from the health endpoint / HTTP readiness check | requires a request; nothing hits it pre-traffic | **rejected** — same lateness as D, plus a second implementation of store logic |
| G | probe from a scheduled task or queued job | needs a scheduler or a worker to be up | **rejected** — detaches the finding from the boot that read the config, and Horizon may be the degraded part |
| H | **bound the probe by a persisted interval** | one probe per interval per installation, across processes | **chosen** |

### A — no probe at all

Attractive because the flag is already there and costs nothing. It is the original bug
restated: `isHealthy()` answers "has a call failed yet in this process?", not "is the store
reachable?", so a healthy-looking worker proves nothing about the store. The check would
report `PASS` most confidently in exactly the case it is meant to catch — a fresh process
against a store that has been gone for a week.

### B — every boot

The honest but unaffordable version. It is correct in the sense that a finding is only as
old as the last boot, but under FPM it converts `swrr.primary_store` pointing at a dead
host into a connect timeout on every request, in every worker. Worse, it is self-defeating:
the load it creates on a struggling store is proportional to traffic, so it degrades
whatever is left of the store the check is trying to protect.

### C — a `static` guard

The pattern already used in this package for the pgcat warning, and the first thing tried.
It was rejected only after the FPM model was written down: `static` state dies with the
process, and under FPM processes are requests. The guard bounds the probe within a request,
which was never the problem. Worth stating plainly because the code *looks* protected.

### D — probe on the first pick, not at boot

Deferring to the moment the store is first used sounds like it probes exactly when the
answer matters. It does not: connections are built once and cached, and under Octane a
read decision may not be made again for hours — so a store that died since boot is noticed
at whatever unpredictable moment the next pick happens, and on a quiet installation the
audit never speaks at all. The audit's stated purpose is to report *before traffic
arrives*, which rules this out on its own.

### E — leave it to `db:doctor`

The right tool for an operator-run, on-demand check, and it keeps that role. What it cannot
do is speak by itself: a release pipeline that does not call it learns nothing, and a
warning that only appears when someone thinks to ask is not a self-audit. The two answer
the same question at different moments and both keep their place —
`db:doctor` is never throttled, because a human asked.

### F — a readiness endpoint

Same lateness as D, with an extra cost: it needs the store's reachability to be measured in
a second place, and the two measurements can disagree. A readiness check also answers "can
this process serve traffic" for a load balancer, which is a different question from "was
this installation configured to do what it says".

### G — a scheduled task or queued job

Would give a periodic answer with no request-path cost, at the price of requiring a
scheduler (or a queue runner) to be up and healthy in every environment — including local
ones, where it would fire against nothing. It also separates the finding from the boot that
observed the configuration: the log line would arrive minutes later, from a different
process, with no record of what config produced it. And in the failure it is meant to catch
— infrastructure missing — the queue runner may be the thing that is degraded.

### H — a persisted interval (chosen)

Probe only when the recorded probe time is older than the interval. The state lives in the
audit record file, which the audit already reads and writes for its findings, so the
throttle and the findings share one writer, one path and one lifetime.

---

## The chosen mechanism, in full

### Gate 1 — only when there is something to reach

```php
$probeStore = $store['effective'] === 'redis' && $audit->storeProbeDue($record);
```

A deliberate in-process primary store (`swrr.primary_store = local`) has nothing to reach,
so the probe is not attempted and no reachability finding can be produced. That boot still
counts the key as *checked* (see below), so an installation that switches away from Redis
closes out a previously recorded finding instead of leaving it standing forever.

### Gate 2 — the interval

```php
if ($this->storeProbeSeconds <= 0 || !$this->recordIsWritable()) {
    return false;
}

$lastProbe = $record['store_probed_at'];

return $lastProbe === null || (time() - $lastProbe) >= $this->storeProbeSeconds;
```

- `store_probe_seconds` default `60`, from `SWRR_AUDIT_STORE_PROBE_SECONDS`.
- `0` (or negative) means **never probe** — the documented way to keep the audit to pure
  configuration checks. The finding is then neither produced nor resolved: the key is not
  checked, so a recorded finding is carried over untouched.
- An unwritable record means **no probe** rather than an unbounded one. This is deliberate:
  the file *is* the throttle, so an installation that cannot write it has no throttle, and
  the answer chosen here is to lose coverage rather than to pay a timeout per request. Such
  an installation is not left blind — `db:doctor` issues its own probe regardless of the
  record.

### Gate 3 — probed findings cannot be resolved by a boot that did not probe

```php
if ($store['effective'] === 'local' || $probeStore) {
    $checkeds[] = self::KEY_STORE_UNREACHABLE;
}
```

`report()` logs the findings produced this boot, logs a resolution once for every recorded
finding whose key is *checked* and did not appear, and remembers the rest. A key that was
not evaluated is therefore carried over in silence. Without this, the ordinary case — a
boot 0.2 s after the last probe, inside the interval — would look like a clean bill of
health and would clear the warning the whole mechanism exists to deliver.

Verified on the originating application, two consecutive boots ~1 s apart against an
unreachable store:

```
boot 1 — no timestamp recorded, probe due   →  1 line: ... could not serve a read: A connection attempt failed ... [tcp://172.17.0.4:6379]
boot 2 — inside the interval                →  0 lines, and the record still reads: swrr.pgcat.gate, swrr.primary_store.unreachable
```

### Logging shape

While a finding stands it is logged **on every boot** — a warning from three weeks ago with
nothing to say whether it is still true is worse than a repeat. A resolution is logged
**once**, because it is a transition, and it is a `warning` rather than info: from that boot
on, reads are served by a store that shares nothing between workers.

### Cost

Per installation, not per worker: the file is shared, so eight FPM workers inside one
interval issue one probe between them, not eight. The worst case is bounded by the interval
and the connect timeout rather than by request rate. `@`-silenced writes mean a failed
record write costs the finding, never the boot.

---

## The second decision: how the probe reaches Redis

The probe's *verdict* is only as trustworthy as the path it takes to Redis, and the first
implementation's was wrong in a way it reported as a connection problem.

It used the `Redis` facade. `Illuminate\Support\Facades\Redis` resolves the container key
`redis` — and while an application is still starting, that key may not be bound yet. The
facade was resolved early (a database connection is built while providers register, and
choosing a read replica asks the store), and resolving an unbound key does not fail:

> The phpredis extension ships a class called `Redis`, PHP class names are
> case-insensitive, so the container built `new \Redis()` for the unbound key — and the
> facade cached it as its root for the rest of the process.

From then on, every `Redis::connection()` in that worker threw
`Call to undefined method Redis::connection()`, which is what the audit then reported as
the store's problem:

```
FAIL  store reachability  [redis(default)] did not answer a PING (Call to undefined method Redis::connection())
```

The probe now goes through `Support\RedisAccess`, which:

1. checks `bound('redis')` **before** resolving (resolving is the call that builds the
   extension class),
2. rejects a binding that is not a connection factory, naming its actual type,
3. returns a reason string instead of throwing, and distinguishes
   `the container has no "redis" binding yet` from `Connection refused [tcp://…]` — the two
   are repaired in different places.

After the change, the same installation reports the real cause and its Redis facade is
intact:

```
FAIL  store reachability  [redis(default)] could not serve a read: A connection attempt failed ... [tcp://172.17.0.4:6379]
facade root : Illuminate\Redis\RedisManager      (was: Redis)
```

The same accessor is used by the store itself and by `db:doctor`, so all three agree about
what "the store" is.

---

## The third decision: the two states are findings, so the row can date them

Gates 2 and 3 above leave two states in which the check never runs, and for a long time both
were reported by one surface only — `db:doctor`'s `store probe` row. That was enough to *find*
them and not enough to date them. The row describes the boot that ran it; what an operator asks
about a state that has stood for a fortnight is how long it has stood, and the only thing that
can answer is the record, which remembers by finding **key**. So each state is a finding:

| state | key | level | the choice, or the fault |
|---|---|---|---|
| `swrr.audit.store_probe_seconds` is `0`, so nothing probes | `swrr.audit.store_probe_seconds.off` | `warning` | the **choice** — the documented way to keep the audit to configuration checks, and what `--strict` refuses |
| the audit record cannot be written, so the throttle is missing | `swrr.audit.file.unwritable` | `warning` | the **fault** the README sets against that choice — nothing is malformed, and nothing is checking the store either |

Both levels are the default, and the reason is the same for both: neither is a value the package
refuses to read. A refused value is a setting that cannot mean what it says (`switch values`), and
what these two describe is a setting that means exactly what it says on an installation that cannot
carry it out. That is also why the second one is a `warning` although the *row* fails on it: the row
is a preflight and a failed preflight is the point of it, while a `warning` in the audit is a state to
put in front of somebody rather than a deploy to stop.

The moot state is deliberately not keyed. A configured interval with an in-process
`swrr.primary_store` leaves the probe nothing to reach, and the reachability row already warns about
the store that makes it so — a finding would be a second line about one setting, and there is no
state for a record to remember, because nothing about the configuration changed. The row keeps
naming it (see the doctor's row for the third state) and the audit stays silent.

### The one key whose date is never written

`swrr.audit.file.unwritable` is the only finding in this package whose own record can never hold its
date: the file a date would be written to *is* the file that cannot be written, so `persist()` skips
the write and the finding is logged without being remembered. There is therefore no
`recorded unresolved since` clause the row can append to it — the row prints the sentence the log
carries and nothing more — and the key exists for the two things it can still do: a log line an alert
rule and a grep can select on, and a name for the state in the surface that describes it.

The asymmetry is the reason the key was not simply left out. A standing state with no key at all
would be the one thing this audit does not do — report a configuration the package understood
perfectly and the installation could not act on — and it would be uncountable exactly in the case
where the record is guaranteed to be silent. Its resolution sentence ("the audit record is writable
again, so the probe it throttles runs on its interval again") is written for the same reason every
other one is: it is what a boot that finds the file usable again would log, if there were anything to
resolve.

### The sentences are the audit's, and the shared row learns to finish one

`BootAudit::probeOffSentence()` and `probeUnwritableSentence()` are the single source of both texts,
because two surfaces print them — the log line and the row — and a row that words a state differently
from the line beside it is the failure the replica metadata was extracted for. They end without a
full stop on purpose: the row appends the condition that it is a warning and not a failure ("…, and
`--strict` would fail this warning") or names the file it cannot write, so a sentence ended here
would have to be reprinted rather than extended. `datedRow()` therefore finishes a sentence by
trimming whatever full stops it carries and writing one back, which is byte-for-byte the same string
for every sentence that already ended properly.

The register of rows that can name several problems is where the change is measured rather than
asserted: `store probe` used to be the one entry in it that survived the "`datedRow()` names only the
first problem" mutation, because the row assembled its own sentences instead of going through the
shared builder. Once the two states are findings it goes through the builder like every other row, so
the mutation reaches it — 19 tests fail against it now rather than 15 — and the register's `store
probe` case is no longer the only test that would notice a row regressing to its first problem.

---

## Tests that pin the rules

| rule | test | in |
|---|---|---|
| an unreachable store is probed, reported, and later resolved | `test_an_unreachable_primary_store_is_probed_reported_and_resolved` | provider |
| an unwritable record, and probing switched off, are reported rather than silent | `test_the_store_probe_row_fails_when_its_record_cannot_be_written`, `test_the_store_probe_row_warns_when_probing_is_switched_off` | doctor |
| both of them at once are named together, with the loudest as the verdict | `test_the_store_probe_row_names_a_switched_off_probe_and_an_unwritable_record`, `test_the_store_probe_row_names_the_moot_store_beside_an_unwritable_record` | doctor |
| each of the two states is dated from its own finding entry rather than the first key on file | `test_the_store_probe_row_dates_each_state_from_its_own_finding` | doctor |
| the switched-off probe is recorded under a key of its own | `test_a_switched_off_store_probe_is_recorded_under_its_own_key` | provider |
| an unwritable record is logged with its key and never remembered, because the file the date would go in is the file that cannot be written | `test_a_record_that_cannot_be_written_is_logged_and_never_remembered` | provider |
| a probe with nothing to reach is named by the row and is not a finding | `test_a_probe_with_nothing_to_reach_is_not_a_finding` | provider |
| the interval bounds the probe; without a writable record there is no probe at all | `test_the_store_probe_respects_its_interval_and_is_not_attempted_unrecorded` | provider |
| an unbound `redis` is reported without resolving it, then resolved once bound | `test_a_store_with_no_binding_at_all_is_reported_without_resolving_it` | provider |
| a deliberate in-process store is never probed, and still closes a recorded finding | `test_a_deliberate_in_process_store_is_not_probed_and_closes_the_finding` | provider |
| the accessor's guard, and that the container really would build the extension class | `RedisAccessTest` | accessor |
| a store that already failed is reported without asking Redis | `test_a_store_that_already_failed_is_reported_without_being_probed` | doctor |
| an in-process store is warned about rather than probed | `test_an_in_process_primary_store_warns_without_being_probed` | doctor |
| the doctor probes `swrr.redis_connection` and passes or fails on the answer | `test_it_fails_the_store_check_when_the_store_does_not_answer_the_ping`, `test_it_passes_the_store_check_when_the_ping_is_answered` | doctor |

---

## Known limitations

1. **The interval is check-then-stamp, not a lock.** Several workers booting in the same
   instant after the interval expires can each find the timestamp stale and each probe — up
   to N probes per interval instead of one. Left as is deliberately, and the stamp is now the
   one part of the record that is written without one: `BootAudit::persist()` does take a
   lock, but on a *companion* file and on an open descriptor, so the kernel releases it when a
   process ends however it ends and a worker killed mid-write leaves nothing behind — the
   sentinel this decision refused is not the mechanism that is there. What keeps the probe out
   of that lock is the cost of holding it: the lock would have to be held across the connect
   timeout, which is the slow case the interval exists to bound, where a duplicate probe is
   one bounded extra wait. Not probing is worse than probing twice, so the cheaper mechanism
   wins. If this is ever tightened, stamp the timestamp *before* probing rather than taking a
   lock.
2. **An unwritable record means no coverage — now visible rather than silent.** Accepted
   above as the trade (losing coverage beats a timeout per request), and reported by the
   `store probe` row of `db:doctor`, which fails on an unwritable record and warns when
   probing is switched off. The two are independent, so an installation can be in both,
   and the row names every state it finds rather than the first: switching the probe on to
   clear the warning reports an unwritable record on the same run instead of the next
   preflight. `db:doctor`'s own probe is never throttled, so it remains the answer for an
   installation whose throttle is broken. Both states carry a finding key, so the run that
   finds them is also the run that can date them — with the one exception the key documents:
   the unwritable record's own date has nowhere to be written, so its key is there for the log
   and the alert rule and its row prints no age.
3. **The resolution sentence covers two different endings.** Switching
   `swrr.primary_store` to `local` closes a recorded reachability finding with "not
   unreachable any more", which is true of the finding but not of the store. Distinguishing
   "the store recovered" from "the installation stopped using it" would need the record to
   remember the configured store, not just the key.

---

## What would change this decision

- **A cheap failure.** If a refused connection were resolved in milliseconds, per-request
  probing would be affordable and the interval would be unnecessary complexity. It is not:
  a host that does not answer is by definition the slow case.
- **If the store were contacted on every request anyway.** Then the audit would be adding
  a call to a path that already pays it, and the interval would be protecting nothing. Only
  replication pools pick per connection, which is cached.
- **A once-per-deploy hook.** The natural home for this check is a pre-traffic phase that
  runs exactly once — a release step, an init container, a boot-time probe with a
  deployment-scoped lifecycle. The interval is a stand-in for that lifecycle, arrived at
  because Laravel's boot is not a per-deploy event under FPM.
- **A shared lock with a TTL** (rather than an interval) would bound concurrent probes
  harder, if the stampede case ever measured as a problem. A lock is no longer hypothetical
  in this package — the record's own write takes one that a killed worker cannot leave behind
  — so what rules it out here is only where it would have to be held: across the connect
  timeout this interval exists to avoid.

---

## Files

| file | role |
|---|---|
| `src/Support/BootAudit.php` | the record: read, `storeProbeDue()`, report/resolve, the merge and the locked atomic write, and the one wording of the two states (`probeOffSentence()`, `probeUnwritableSentence()`) that the log and the row both print |
| `src/Providers/WeightedDatabaseServiceProvider.php` | gates the probe, produces the findings, decides what counts as checked |
| `src/Support/RedisAccess.php` | the facade-free path to Redis, and the reasons it can fail |
| `src/Database/Weighted/RedisAtomicStateStore.php` | `isHealthy()` — evidence, not proof |
| `src/Console/Commands/DbDoctor.php` | the unthrottled, operator-run probe, and the `store probe` row that reports when the audit's own probe can never run |
| `config/db-manager.php` | `swrr.audit.file`, `swrr.audit.store_probe_seconds` |

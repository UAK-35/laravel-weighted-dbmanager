# Where should a boot audit finding be visible?

A design record for `/health/db`'s `audit` block and `db:replica-status`'s `Audit:` list.

The boot self-audit logs every setting that reads as on but cannot act — a reader window
written flat, a pgcat gate switched on where pgcat cannot front anything, a formula that
silently runs as `linear` — and remembers the ones that still stand in
`swrr.audit.file`. Being logged is not the same as being seen. A log line is written by a
process, read by whoever is looking at the moment it is written, and the installations
that most need the finding are the ones that have never run `db:doctor` and are not
watching a log. This document records where a standing finding is *published*, which
alternatives were considered, and why they were not taken.

Everything below is implemented in `Support\BootAudit::reported()` — the block both surfaces
render, built on `standing()` — and in the two thin renderers over it,
`Http\Controllers\DatabaseHealthController::audit()` and `DbReplicaStatus::auditBlock()`.
The rules are pinned by the tests named at the end.

---

## The answer in one line

**Publish them on the two surfaces an operator already opens — the health payload and the
status command — and let neither of them act on them.** Both render one accessor,
`BootAudit::reported()`, so the two cannot describe the same record differently — nor
disagree about *whether* there is one — and neither moves an exit code or an HTTP status.
`db:doctor --strict` remains the only thing that fails over a finding.

`reported()` is one accessor for two questions, because a surface has to answer both: what
stands and how loudly, and, when there is nothing to report, which of the two reasons it is.
`standing()` answers the first and throws — or cannot be called at all — for the second, and
the two surfaces answering that second question separately is how they came to disagree.

The payload carries the machine-readable half of that answer as well: a closed two-level
vocabulary, a `severity` naming the loudest level standing, and per-level `counts`. Those
exist for the alert and not for the operator — the distinction between a value the package
refused and a setting that cannot act is the difference between a page and a ticket, and a
monitor should not have to walk a list and compare strings to find it out.

---

## Why this needed deciding at all

Three facts, each of which rules out an otherwise obvious answer.

### 1. A log line is an event, not a place

While a finding stands it is logged on every boot, which is the right thing for a log to
carry and useless as a place to find it. There is no query for "is this installation
currently claiming something it cannot do": the line was written by a process that has
since exited, and under FPM it was written once per request. The record exists precisely
because a boot cannot know what a previous boot said — and once the record exists, the
question of *who reads it* has an answer or it has silence.

### 2. The record already knew more than the key

The record was originally the finding's key and its context, with the sentence explaining
it existing only in the log line. A reader holding the record could say *which* setting
was wrong and not *how*, which is the least useful half: `swrr.reader_windows.refused` is
a greppable identity, not a repair. The record now keeps the sentence, the level it was
logged at and the first sighting, so a surface that reads the record and not the log can
say what the finding is, how loudly it was said, and how long it has stood — which is
what makes reading the record worth doing at all.

### 3. Both candidate surfaces already answer a question of their own

`/health/db`'s `status` and HTTP code answer *can this process serve traffic right now*,
which is what a load balancer polls. `db:replica-status`'s table answers *where are reads
going*. Neither can absorb the findings without changing what it means: the first would
start evicting instances over configuration, and the second would start failing a report.
The findings are a third question — *was this installation configured to do what it
says?* — and the decision is where it can be asked without overwriting either of those.

---

## The candidates

| # | mechanism | what it costs where it matters | verdict |
|---|---|---|---|
| A | leave them to the boot log | nothing to find them with; already the status quo | **rejected** — the defect |
| B | a dedicated `db:audit` command | one more surface to know about, and none of them are visited by default | **rejected** — the finding has to find the operator, not the other way round |
| C | a dedicated `/health/audit` endpoint | a second payload to keep in step with the health one | **rejected** — the audit is a property of the installation, which is what `/health/db` already is |
| D | fold them into the `pgcat` block | the block is one producer's snapshot; the findings are cross-cutting | **rejected** — pgcat is one of twenty-two keys |
| E | make the endpoint `degraded` and `503` while a finding stands | a load balancer evicts an instance over a setting it is not using | **rejected** — see below |
| F | make `db:replica-status` exit non-zero | scripts that print a table start failing over a config typo | **rejected** — the gate belongs to `db:doctor` |
| G | have `db:doctor` read the log instead of the record | log rotation and interleaving by workers; no keyed lookup, no first sighting | **rejected** — the record is the thing that survives the process |
| H | **publish both, from one accessor** | one accessor, two renderings, no new surface | **chosen** |

### A — leave them to the boot log

The status quo, and the defect. The audit's own docblock says it exists for the states
nothing else would ever mention; a state that is only mentioned in a log is one of them.
A log line also has no lifetime: the fix closes the finding with a resolution line in a
different process, and nothing in the log says the two are related.

### B — a command of its own

Attractive because it is cheap and it clearly separates concerns: `db:audit` would print
the standing findings and nothing else. It fails on the same point as A, one step along:
a command answers when it is run, and the operator who runs `db:replica-status` to look at
distribution while debugging a slow read is exactly the person who should be told that
`swrr.reader_windows` is being refused. The surfaces that do get seen are the ones already
in a workflow — `db:doctor` in a pipeline, `db:probe-replicas` and `db:pgcat-flip` on a
schedule — and a fifth name would be one more thing to know about, with nothing running it
for you.

### C — an endpoint of its own

`/health/audit` would keep the health payload's shape untouched, which is a real
advantage: `status` would then never be asked to mean anything new. It loses on the shape
of the question. The audit's findings are a property of *the installation*, and
`/health/db` is already the installation-level document — it carries pgcat, the followed
connection's replicas, the store and the formula for the same reason. A second endpoint means a second URL for a
dashboard to know about, a second payload that can drift from the first, and two places
where "what does this installation say about itself" is answered.

### D — fold them into the `pgcat` block

`pgcat` is a block that exists because one producer
(`PgcatConfigFlipper::healthSummary()`) is read by three surfaces, and it is scoped to one
feature: is flipping armed, and can it act. The audit is the opposite: its keys are
`swrr.pgcat.enabled.refused`, `swrr.pgcat.use_reload.refused`,
`swrr.allow_local_fallback.refused`, `swrr.pgcat.gate`, `swrr.reader_windows.refused`,
`swrr.reader_days.refused`, `swrr.reader_fallback.always_readers`,
`swrr.reader_fallback.always_writer`, `swrr.reader_fallback.unmatchable_windows`,
`swrr.primary_store.unknown`, `swrr.primary_store.unreachable`,
`swrr.default_weight_formula.unknown`, `database.read.weight.refused`,
`database.read.cpu_cores.refused` and `database.read.ram_gb.refused` — the last three being
the replica settings, which are the connection's rather than `swrr`'s, so their keys name
the path an operator edits and the connection is in the finding's context instead. Five of
them are the file preconditions a flip needs — `swrr.pgcat.config_path.unusable`,
`swrr.pgcat.readers_path.unusable`, `swrr.pgcat.no_readers_path.unusable`,
`swrr.pgcat.state_file.unusable` and `swrr.pgcat.lock_file.unusable` — one per setting, so a
source that cannot be read and a target that cannot be written are two findings with two
repairs rather than one that resolves both together. Two are the store probe's own states —
`swrr.audit.store_probe_seconds.off` for a probe switched off on purpose, and
`swrr.audit.file.unwritable` for the record that throttles it, which is the one finding whose own
record can never hold its date — and they are keyed because the row reporting them has to be able
to say how long the installation has been unable to check its store. Filing all of those under
`pgcat` would put twenty-two keys' worth of claims inside one feature's block, and a finding about
the weight formula would disappear for an installation that has no pgcat wiring at all.

### E — let the findings move the endpoint's status

The closest call, and the one worth stating in full, because the two claims read as
similar and are not.

`status` is computed from things this process can establish *now*: each connection's PDO
is opened, each replica's health flag is read, and the store's health is taken from
whether it has already failed. A finding is the opposite on both axes. It is read from a
file written by a **previous** process — so the endpoint would be publishing another
boot's opinion as its own liveness verdict, in a payload whose whole purpose is "this
instance, right now" — and it describes configuration rather than reachability, which is
the distinction the package already draws everywhere else: `pgcat.enabled = false` on a
MySQL connection is not ill health, and the endpoint's own docblock says so.

The cost of getting it wrong is asymmetric. A load balancer that evicts instances over a
refused `reader_windows` value removes capacity from a service that is answering every
request correctly, and it does so for a setting that may not even be in use on that
instance. A dashboard that shows a finding is a person deciding what to do about it, and
`db:doctor --strict` is a pipeline deciding it before the deploy leaves. Neither needs the
status code, and both would be harmed by borrowing it.

What the endpoint does with findings is therefore: embed them, count them, date them, and
leave `status` alone. `count` and `oldest` exist so a dashboard can alert on "findings
standing" or "the oldest is four days old" without walking the list, which is the pull
that replaces the eviction.

The one thing that *does* move `status` for a related reason is the store: a store that has
already failed makes reads degrade to an in-process rotation, which is a live-serving
claim, so it sets `degraded` and `503` through `store_healthy` — not through the finding
the audit records for the same condition. The two are allowed to say different things
because they are answering different questions: the store is failing *now*, and the boot
also recorded that it could not be reached when it looked.

### F — let `db:replica-status` exit non-zero

Tempting because the command is right there and its output is where the findings are
printed. It is the wrong owner of the decision: the command is a *report* — host, weight,
share, formula, store, pgcat, findings — and an operator reading it has not entered a
verify-the-installation context the way `db:doctor` tells them they have. A pipeline that
runs `db:replica-status` for a collected artefact would start failing deploys semi-randomly
over configuration, and the failure would be a table, not a verdict. `db:doctor` is the
command whose entire purpose is the verdict, whose rows are PASS/WARN/FAIL, and whose
`--strict` already turns a warning into a gate failure.

### G — read the log, not the record

`db:doctor` needs a finding's *first sighting* to date the row that setting produces, and
the record is what says a mismatch is still standing when this boot's own verdict comes
back clean. It reads the record — a keyed lookup for the setting the row is about, with a
fallback to its own verdict when the record cannot be read — and not the log. Parsing log
lines would be reading a second-hand artefact that rotation deletes, that concurrent
workers interleave, and whose format is not a contract anyone promised to keep.

### H — publish both, from one accessor (chosen)

Both surfaces read `BootAudit::standing()`. Neither derives anything of its own — not the
order, not the age, not the level — so a payload and a terminal cannot disagree about the
same record, and a rule added to one is a rule added to both.

---

## The chosen mechanism, in full

### One accessor, and who reads what

| reader | reads | why not `standing()` |
|---|---|---|
| `GET /health/db` | `standing()`, embedded as the `audit` block | — |
| `db:replica-status` | `standing()`, printed as an `Audit:` list | — |
| `db:doctor` | `BootAudit::read()['findings'][$key]` | it judges one setting at a time and needs that key's first sighting: a mismatch the record still holds can fail — or warn about — a row even when this run's own verdict is clean. `standing()` is the presentation; one key at a time is the judgement. It also adds a *clause* to a row it is already printing and falls back to its own verdict, so an unreadable record is not a claim it has to make — which is why the tolerant reader is the right one for it |
| the boot itself (`report()`) | `read()`, the tolerant reader | it is the only writer: a record it cannot read is one it cannot carry over or close out, and a diagnostic never stops a boot. So it reads "nothing recorded" and then writes over the file — the repair the two surfaces never have to do, because neither of them writes anything |

`standing()` returns, per finding: `key`, `level`, `warning`, `resolution`,
`first_reported_at`, `age_seconds`, `age`, `context` and the `scope` it was written in.
`reported()` adds the live half — `checked_at`, `scope`, `current`, and each finding's own
`current` — so both surfaces render the record and the present from the one call.

It throws `UnreadableRecord` for a file that is there and is not a record, and `read()` is the
`catch` around it. That split is what makes `available: false` with `error` *set* reachable at
all. While one tolerant reader served both callers, a half-written record arrived at the surfaces
as an empty one — so the record's own three readers could not tell an installation that had fixed
everything it was told about from one nothing could be told, and the terminal printed the good
news over the bad. The shapes refused are the file-level ones (not a file, unreadable bytes,
empty, not JSON, not a JSON object, `findings` that is not a map); entries *inside* a readable
record keep their old tolerance, because the record around them can still be read.

### Oldest first, and an age in words

The order is most of the value: a finding that has stood since last week is the one that
has been missed longest, and it is the one a reader should see first. The list is sorted by
`age_seconds` descending, with the key as a tiebreak so two findings of the same age do not
swap places between requests.

A timestamp that cannot be read — a hand-edited record can produce one — sorts **last**
rather than first, and reports `unknown age`. Both surfaces have to answer "how long has
this been wrong", and "since the beginning of time" is the one claim a missing date cannot
support.

### The endpoint's block

```json
"audit": {
    "available": true,
    "count": 1,
    "severity": "error",
    "counts": {"error": 1, "warning": 0},
    "oldest": "2026-09-21T08:15:00+00:00",
    "findings": [
        {
            "key": "swrr.reader_windows.refused",
            "level": "error",
            "warning": "reader_windows[0] is \"10:00-14:20\". Refused: each window must be an array …",
            "resolution": "swrr.reader_windows is readable again: every entry is a window …",
            "first_reported_at": "2026-09-21T08:15:00+00:00",
            "age_seconds": 345600,
            "age": "4 days",
            "context": {"rejected_windows": [{"at": "[0]", "entry": "\"10:00-14:20\""}]},
            "scope": {
                "connection": "sqlite",
                "driver": "sqlite",
                "source": "db-manager.swrr.connection",
                "app_env": "sqlite-live"
            },
            "current": {"evaluated": true, "standing": false, "scope_matches": false}
        }
    ],
    "error": null,
    "checked_at": "2026-09-30T09:41:02+00:00",
    "scope": {
        "connection": "pgsql_proxy",
        "driver": "pgsql",
        "source": "db-manager.swrr.connection",
        "app_env": "production"
    },
    "current": {
        "available": true,
        "count": 0,
        "severity": "none",
        "counts": {"error": 0, "warning": 0},
        "findings": [],
        "error": null
    }
}
```

The block is `BootAudit::reported()` as-is — the field meanings below are its contract — and
it is the same array the command decides its four states from.

| field | meaning |
|---|---|
| `available` | a record could be read. `false` has two causes — no audit is registered (the controller's dependency is optional, so the route still resolves), or the record could not be read; `error` says which |
| `count` | how many findings stand, so a dashboard does not have to walk the list to say "none" |
| `severity` | the loudest level standing — `error`, `warning`, or `none` when nothing stands. The one field an alert is written against |
| `counts` | how many findings stand at each level, both keys always present, so a rule can be `counts.error > 0` rather than a lookup that might be missing |
| `oldest` | the first sighting of the oldest finding — the same value as `findings[0].first_reported_at`, because the list is ordered |
| `findings` | `standing()` as-is, including the sentence and the level, so a reader that has the payload and not the log knows *how* the setting fails, plus the scope it was recorded in and what this process makes of it |
| `error` | present either way, `null` whenever a record was read: the exception's message when it could not be |
| `checked_at` | when the live reading below was taken |
| `scope` | the connection, its driver, the rule that named it and `app.env` this process resolved — null when nothing evaluated the live half |
| `current` | the audited settings as they read *now*, in the record's own vocabulary, with `available: false` and an `error` when there is no live reading to make |

Both failure modes are still an HTTP `200` when the database itself is serving. A record
that cannot be read is not a database that cannot answer, and the block says so rather
than the routing layer.

### Two readings, and why the second one exists

The block carries the record and the present side by side, and the present half was added
because of a defect the record alone could not show.

A record is per installation; a boot is per process. One installation has many boots — a
migration container, a queue worker, the web process — and they do not necessarily resolve the
same connection or the same environment. A deployment whose entrypoint migrates under another
environment before the web process starts is the concrete case, and it produced exactly the
payload this document is written against: a `swrr.primary_store.unreachable` finding whose
`context` named `sqlite` and a Redis host at `127.0.0.1`, published beside a payload whose live
connection was `pgsql_proxy` and whose `replicas.store_healthy` was `true`. Every field was
individually correct and the page read as a contradiction, because one boot's finding was being
read as the answering instance's.

So two things are recorded now, and they answer different questions. Each finding remembers the
**scope** it was written in — the resolved connection, its driver, the rule that named it and
`app.env` — and the block carries a **live reading**: the same producers run again in the process
answering the request, with the network probe left out, because a failed PING is a connect timeout
and a health payload is not the place to spend one. Each recorded finding then says whether this
process evaluated it, whether it still stands here, and whether the scopes agree:

| `findings[].current` | meaning |
|---|---|
| `evaluated` | `false` for a key the live half was not asked about — today only `swrr.primary_store.unreachable`, which needs the probe |
| `standing` | whether the key reads on here; `null` when `evaluated` is `false`, because *nothing asked it* is not *it reads well* |
| `scope_matches` | whether the recording boot and this one resolved the same scope; `null` when either side is unknown, because a missing half cannot support "the same" |

The record is unchanged by any of this: the finding keeps its sentence, its level, its first
sighting and its age, and the boot that finds it clean still closes it out. The live half writes
nothing, logs nothing and probes nothing, and `current.findings` lists the keys that read on now
even when the record holds none — which is the one thing the record cannot say, because a key no
boot has written down yet is invisible in it.

The block is still one accessor. `reported()` builds both halves, and the command renders both,
so a payload and a terminal cannot disagree about the scope any more than they can about the
record.

### The vocabulary an alert is written against

The two levels are not a severity ladder that happens to have two rungs; they are two
claims, and the alert that should fire for each is different:

| level | what it says | what an alert should do |
|---|---|---|
| `error` | the package **refused** a value — malformed input it will not interpret on the operator's behalf, so the installation is running without whatever that setting describes | page: something was dropped, and nobody chose to drop it |
| `warning` | the setting was understood and used, and it describes nothing that can happen — a reader fallback that cannot apply, a formula that runs as `linear`, a store that is not the one being used | ticket: a feature is quietly not applying |

`severity` is the worst of the two that is standing, so the rule is one comparison:
`severity == "error"` pages, `severity == "warning"` does not. `none` means nothing is
standing — it is a value in the summary's vocabulary and never a finding's, because no
finding is "fine". A consumer that wants to alert on the audit having *stopped reporting* has
`available` (or `error`) for that, which is a separate question from how bad what it reports
is: `available: false` reads as `severity: none` because nothing is **known** to stand, not
because nothing is wrong.

The same distinction is why the record's `severity` and the live half's `current.severity` are two
fields rather than one. A rule written against the first pages whichever instance happens to answer
for an entry another boot wrote, which is the defect the live half was added for; the second pages
only when *this* process re-derived the refused value. `findings[].current.scope_matches` is where
the difference is named: `false` means the entry is true about another scope, so it is the ticket in
the table above rather than the page. An entry the live half was not asked about reads
`evaluated: false` with `standing: null`, deliberately not "cleared" — and `current.available` is the
third case, a live half that did not run here at all. The README's cookbook carries the two rules as
paste-able patterns, run against real payloads by `ReadmeAlertingTest`.

The same two names are in the boot log, so the rule can be written against a line instead of
a polled host: every line `BootAudit` writes carries `severity` in its context beside the
`finding` key, holding the level it was written at. That is a payload field rather than the
channel's own level — how a handler spells a level is the handler's business, and `ERROR` in
the application log is every error the application logs. Candidate G below still stands
unchanged: the log is an event and the record is the state, and nothing here teaches anybody
to parse a log for the record. The decision and its candidates are in
[boot-audit-log-severity.md](boot-audit-log-severity.md).

The vocabulary is closed at those two names, and a level outside it is counted as the
quieter one, matching what the record is read as. The levels are compared exactly rather
than ranked: an entry that cannot be substantiated is never upgraded into a louder claim
than the record supports, which is the same rule that keeps an old entry from reading as an
error it might not have been.

### The command's block

```
Audit:      1 finding standing — oldest 4 days
  error  swrr.reader_windows.refused
    reader_windows[0] is "10:00-14:20". Refused: each window must be an array …
    standing 4 days — first reported 2026-09-21T08:15:00+00:00
```

...and the state the command used to answer with silence, which is the one the payload calls
`available: false` with `error` null:

```
Audit:      not registered — no record is kept, so no finding is reported here
```

The level is rendered (`error` red, `warning` yellow) because a refused value and a setting
that cannot act are different claims and the record keeps them apart. The block is printed
last, after the table and the pgcat lines, and it is printed on the **no-replicas** path
too: findings are about the installation, so "this connection has no read replicas" does
not make a misconfiguration elsewhere less true — and that path is often the run an
operator starts with.

Four states, four lines, and they are the four the payload distinguishes: `nothing standing —
no setting is recorded as reading on without being able to act`, `N finding(s) standing —
oldest …` followed by the entries, `not registered — no record is kept, so no finding is
reported here`, and `unreadable — <message>` when the record cannot be read. The last two are
the payload's `available: false`, split the way the payload splits it: `not registered` is
`error` null and `unreadable` is `error` set.

Neither of those two was a line the command used to print. Silence was the old answer for the
first, which made the two surfaces disagree about an installation: `available: false` on the
dashboard and nothing at all in the terminal, so an operator could not tell an installation
with no audit from one whose record happened to be empty. Both now come from
`BootAudit::reported()`, which is why they cannot drift apart again.

### Nothing is cached

`standing()` reads the file every time it is called. A long-lived worker — Octane, Horizon
— that resolved the list once would keep answering from the file as it was when the worker
started, which is precisely the staleness the record exists to avoid. The cost of being
right is one small file read per response, and nothing on this path writes, probes or
restarts anything.

### What neither surface does

- Neither exits non-zero: both return success, and the `Audit:` list says nothing about the
  exit code. `db:doctor --strict` is the gate (candidate F).
- Neither moves `status` or the HTTP code (candidate E).
- Neither decides whether a finding *still applies*: a record is closed out by the boot that
  checks the setting and does not find it (or, for the store, by a boot that probed), and
  these two surfaces only read what is on record. A surface that resolved findings would be
  a second writer of the record.

---

## Tests that pin the rules

| rule | test | in |
|---|---|---|
| a finding's sentence and level survive the process that logged it | `test_a_report_remembers_the_sentence_and_the_level_it_was_logged_at` | audit |
| a finding remembers the scope it was written in; a record without one reads as no scope | `test_a_report_remembers_the_scope_the_finding_was_written_in`, `test_a_record_written_before_scopes_were_kept_reads_as_no_scope` | audit |
| the block carries the live reading beside the record, and a finding from another scope is labelled | `test_the_block_carries_the_live_reading_beside_the_record` | audit |
| a key the live half did not evaluate is not called cleared | `test_a_key_the_live_half_did_not_evaluate_is_not_called_cleared` | audit |
| no live evaluator, and an evaluator that throws, both read as "not evaluated" rather than as facts | `test_a_block_with_no_live_evaluator_says_so_rather_than_inventing_one`, `test_a_live_evaluation_that_throws_leaves_the_record_readable` | audit |
| the payload labels a finding recorded in another scope instead of publishing it as this host's | `test_a_finding_recorded_in_another_scope_is_labelled_rather_than_published_as_this_host_s` | provider |
| the store's reachability is `not evaluated here`, never `cleared here` | `test_the_store_s_reachability_is_not_evaluated_here_rather_than_reported_clear` | provider |
| the terminal says the same thing about the same record, `now:` line and all | `test_the_replica_status_command_says_what_this_run_makes_of_a_recorded_finding` | provider |
| a record written before levels were kept reads as a warning, not as an invented error | `test_a_record_written_before_levels_were_remembered_reads_as_a_warning` | audit |
| oldest first; an unreadable timestamp is last, not first | `test_standing_findings_are_listed_oldest_first`, `test_a_timestamp_that_cannot_be_read_is_not_given_an_age`, `test_a_future_timestamp_reads_as_new_rather_than_negative` | audit |
| an age in words, so no reader subtracts timestamps | `test_a_finding_carries_its_age_in_words` | audit |
| the record is what closes a finding out, and an unchecked key is carried over | `test_a_finding_stops_being_remembered_once_a_boot_checks_it_clean`, `test_a_finding_this_boot_did_not_check_is_carried_over` | audit |
| a standing finding is in the payload and `status` is still `ok` | `test_the_health_endpoint_reports_a_standing_finding_without_calling_it_ill_health` | provider |
| the sentence in the payload is the sentence the log carried | (same test, asserting the log's message against the payload's `warning`) | provider |
| a sound installation reports nothing standing: `severity: none`, zero counts, `oldest` null | `test_the_health_endpoint_reports_nothing_standing_on_a_sound_installation` | provider |
| a refused value is summarised as `error`, whatever else is standing beside it | `test_a_summary_names_the_loudest_level_standing` | audit |
| settings that cannot act are summarised as `warning`, not as a page | `test_a_setting_that_cannot_act_is_not_summarised_as_something_to_page_for` | audit |
| nothing standing is `none`, which is a value in the summary's vocabulary only | `test_a_summary_of_nothing_standing_is_none` | audit |
| a level outside the two the audit logs at counts as the quieter one | `test_a_level_outside_the_vocabulary_is_counted_as_the_quieter_one` | audit |
| the payload tells a refused value apart from a setting that cannot act, and still says `status: ok` | `test_the_health_endpoint_tells_a_setting_that_cannot_act_apart_from_a_refused_value` | provider |
| the same choice is in the log: every line names the level it was written at | `assertSeverityStamped()` (over the finding, collision and resolution lines, and the two the record's write can add), in `test_a_refused_value_is_selectable_from_the_log_by_its_severity` | audit |
| a finding's context cannot overwrite the line's level | `test_the_line_names_the_level_it_was_written_at_even_when_a_finding_context_carries_the_key` | audit |
| with no record to read, the block makes no claim and `error` names the reason | `test_the_audit_block_makes_no_claim_when_there_is_no_record_to_read` | provider |
| an unreadable record is not rounded to "nothing standing" — `available: false`, `error` set, and `severity: none` because nothing is *known* to stand | `test_a_record_that_cannot_be_read_is_not_rounded_to_nothing_standing` | audit |
| each unreadable shape is named for what it is, and `standing()` throws the message the block carries | `test_each_shape_of_unreadable_record_is_named_for_what_it_is` (six data sets) | audit |
| a record that is *gone* is still nothing standing rather than a failure | `test_a_record_that_is_not_there_is_nothing_standing_rather_than_a_failure` | audit |
| the boot reads an unreadable record as nothing and writes over it, while a surface refuses to — the reader split, in one process | `test_the_boot_reads_an_unreadable_record_as_nothing_while_a_surface_refuses_to` | audit |
| the payload names the unreadable record in `audit.error` and leaves `status` alone | `test_an_unreadable_audit_record_is_named_in_the_payload_and_leaves_the_status_alone` | health |
| the terminal's `unreadable` line, with the file and the reason, and not `nothing standing` | `test_a_record_that_cannot_be_read_is_a_line_rather_than_nothing_standing` | command |
| a record that *was* read and holds nothing says so, and is not the `unreadable` line | `test_a_record_with_nothing_standing_is_not_reported_as_unreadable` | command |
| the command prints the findings, and exits `0` doing it | `test_the_replica_status_command_prints_the_standing_findings` | provider |
| the command prints them on the no-replicas path too | `test_the_replica_status_command_reports_the_audit_without_replicas` | provider |
| both surfaces say an unregistered audit, and agree on the payload's `available`/`error` pair | `test_both_surfaces_say_when_the_boot_audit_is_not_registered` | provider |
| an empty record and no record at all are told apart in the terminal | `test_the_replica_status_command_tells_an_empty_record_from_no_record` | provider |
| the doctor's own rows date a finding that is still on record | `test_the_gate_row_reports_when_a_recorded_mismatch_was_first_seen`, `test_the_gate_row_reports_a_mismatch_a_previous_boot_left_on_record`, `test_the_reader_windows_row_dates_a_refusal_the_record_still_holds` | doctor |

---

## Known limitations

1. **An installation that never wanted an audit now gets a line saying so.** Printing
   nothing was the alternative, and it was rejected: the payload answers `available: false`
   either way, so silence in the terminal did not mean "there is nothing to report" — it
   meant the two surfaces disagreed about the installation, and left "no audit registered"
   and "a record with nothing on it" indistinguishable to anyone reading the command. The
   cost is one line, in a block that already prints one line for `nothing standing`, and the
   line names a state rather than a fault: an installation that registered the manager
   without the audit is not broken, it is simply not being watched.
2. **`severity` and `available` are two questions, and a rule that reads only the first
   will miss the record.** `available: false` reads as `severity: none`, because nothing is
   known to stand rather than because nothing is wrong — so a monitor that pages on
   `severity` alone stays quiet when the audit stops being able to read its own record, and
   needs `available` or `error` as a second rule. They are also the two things `available:
   false` can mean: `error` set is a file that could not be read, `error` null is a package
   whose provider is not registered, and the repairs differ (fix the file, or install the
   provider).
3. **Neither surface is a gate.** A finding can stand through any number of deploys that
   never run `db:doctor --strict`. That is the trade candidate E refuses to make, and it
   means the payload is a *signal*, not a policy: something has to alert on `severity` for
   the finding to reach anyone who is not reading a terminal — and the alert is the host
   application's to write, because only it knows who should be woken up.
4. **The age is only as good as the record.** `first_reported_at` is the first sighting by
   any boot that writes that `swrr.audit.file`. *Deleting* the record therefore clears the
   dashboard and resets the age on the next boot that reports the same finding, without a
   resolution line in between — and a deleted record is indistinguishable from one that was
   never written, which is the honest reading: the file is where the installation keeps this,
   and an installation that removed it is not claiming anything. An *unreadable* record is the
   other case and is not rounded into it: the block says `available: false` with the reason,
   so a record nobody can open cannot be mistaken for an installation with nothing to say.
5. **The record is per installation, not per environment — and the block now says so rather
   than hiding it.** Two environments sharing an audit file still share the date, the
   resolved/unresolved state and the age; what changed is that each finding now carries the
   scope it was written in and each surface marks a finding recorded under a scope it is not
   running as. The finding is still published, because it is still true about the setting it
   names and the boot that wrote it is the only evidence of that; it is labelled, not dropped.
   A reader that wants only its own environment's findings filters on
   `findings[].current.scope_matches`, and one that wants only what is true right now reads
   `current`.
6. **The order is by first *recording*, not by the first time the installation was wrong.**
   A finding that was introduced before this audit existed, or before the record's
   directory became writable, is dated from the first boot that could write it down.
7. **Every unreadable shape is named, and one of them cannot be driven from a test.**
   `standing()` throws for six states and the suite drives five: a path with a directory on
   it, an empty file, text that is not JSON, a JSON list, and an object whose `findings` is
   not a map. The sixth is a read that fails outright — the file removed between the check
   and the read, which `persist()` does whenever a boot finds nothing left to remember — and
   there is no seam to hold a writer inside, so it stays a guard. A missing file is
   deliberately *not* one of the six: it is what an installation with nothing standing leaves
   behind, and it still reads as `available: true` with an empty list, which is a fact about
   the installation rather than a failure of the reader.
8. **A key two branches agree on keeps one of the two sentences.** The record is keyed by
   finding key, so the audit logs every finding a boot produced, names the collision as the
   package defect it is, and remembers the louder one. The payload therefore shows one
   sentence for that key, and the collision belongs to the log rather than to the surface —
   see [boot-audit-finding-keys.md](boot-audit-finding-keys.md).

## What would change this decision

- **If `/health/db` stopped being load-balancer-facing** — polled only by a dashboard —
  candidate E would open up, and findings could reasonably move the status, since nothing
  would be evicting instances over them.
- **If a finding ever described a live-serving failure.** Then `status` would be its right
  home, and the store's degradation is the working example: it moves `status` because reads
  have already fallen back, and the audit's `swrr.primary_store.unreachable` finding is a
  separate, quieter statement of the same underlying condition.
- **If the package gained a push channel.** Alerting on `severity` is a dashboard's job
  today, and the audit's store-probe record already argued against a scheduled task the
  package runs itself. A host application that alerts on the payload, or forwards the boot
  log's findings to a pager, is the supported version of that idea.
- **If a machine consumer needed more than `severity`.** An alert can be written today
  against `severity` and `counts`; a stable finding-id vocabulary (so a rule can silence a
  known finding rather than a level) or a schema version for the block would be the next
  step if a consumer ever had to hold state about the block rather than read it.
- **If a third kind of claim appeared.** The vocabulary is closed at two levels because the
  audit makes exactly two kinds of claim. A finding that is neither a refused value nor a
  setting that cannot act — a deprecation, say — would have to decide where it sits against
  the page/ticket line, and the summary would grow a rung rather than a meaning.
- **If the doctor and the displays disagreed about what "standing" means.** They read the
  same record today, by key and by `standing()` respectively. A finding that could apply in
  one environment and not another — a driver-dependent key is the near case — would have to
  decide whether the record is about the installation or about a deployment of it. That case
  arrived, and the decision recorded above is the answer: the record stays about the
  installation, and the block says which deployment each finding came from. A surface that has
  to *act* on the difference — dropping another scope's findings, or failing a gate over one —
  is the next step, and it is not taken here because the finding is still evidence.

## Files

| file | role |
|---|---|
| `src/Support/BootAudit.php` | the record, `standing()` — ordering, ages, the fields a surface renders, the scope each finding was written in — `reported()`, the block both surfaces publish and the one place either decides whether there is a record, `live()` and its annotation, which run the producers the provider injects and put the present beside the record, `standingSummary()` with the severity vocabulary, and `fold()`, where a boot's findings become that record |
| `src/Support/BootAuditFinding.php` | one finding: key, warning, resolution, context, level |
| `src/Support/UnreadableRecord.php` | the state that made the branch reachable: the record is there and is not a record, and the reason is the message both surfaces publish |
| `src/Http/Controllers/DatabaseHealthController.php` | the `audit` block — `reported()`, embedded — and the status it deliberately does not move |
| `src/Console/Commands/DbReplicaStatus.php` | the `Audit:` list in its four states, on both the table and the no-replicas path, each finding followed by its `now:` reading and the live half printed under the list |
| `src/Console/Commands/DbDoctor.php` | the gate: `recordedFinding()` reads the record by key, adds the age clause, and fails under `--strict` — and `recordedFindings()`, which the `reader windows` row uses to date each of the problems it names from its own key |
| `src/Providers/WeightedDatabaseServiceProvider.php` | the finding keys, `reportBootAudit()`, `readerRecordKeys()`, and `liveFindings()` with `auditScope()` — the live half the `BootAudit` singleton is handed, and the scope every recorded finding is stamped with |
| `tests/Unit/Support/BootAuditTest.php` | the record and the view over it, including the two readers either side of `decode()`, every unreadable shape, and the boundary a missing file sits on |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | both surfaces, and that the payload stays `ok` |
| `tests/Unit/Http/DatabaseHealthControllerTest.php` | the payload's own block: the unreadable record's `error`, and that it does not move `status` |
| `tests/Unit/Console/DbReplicaStatusTest.php` | the terminal's `Audit:` lines, including the `unreadable` one and the `nothing standing` it must not be confused with |
| `tests/Unit/Console/DbDoctorTest.php` | the gate's half: the age clause (including that a finding for another problem is not quoted) and the failing rows |
| `README.md` | the user-facing shape of the block, next to the endpoint's other sections |

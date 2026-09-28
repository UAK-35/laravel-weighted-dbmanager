# How should a pipeline read a flip's verdict?

A design record for `db:pgcat-flip --json`.

A rehearsal exists to be run *before* a deploy, which means the thing that runs it is usually a
pipeline: a CI job gating a release, a deploy step that refuses to continue while pgcat cannot
be flipped. That job needs two facts — would a flip work, and did the run fail — and until now
it could only get them by reading the report an operator reads: substring-matching `would flip`
out of a line that carries colour tags and wraps at the terminal width, and trusting that the
phrasing never changes. This document records what such a job parses now, which alternatives
were considered, and why they were not taken.

Everything below is implemented in `Console\Commands\DbFlipPgcatCommand` — `report()`, the four
`KIND_*` constants and the JSON branch of each route — over the arrays
`Pgcat\FlipResult::toArray()` and `Pgcat\DryRunResult::toArray()` already carried. The rules are
pinned by the tests named at the end.

---

## The answer in one line

**One JSON object on stdout for every route, with a fixed key set, the run's exit code inside
it, and `kind` as the verdict from a single vocabulary** — so a job asserts
`jq -e '.kind == "would_flip"'` instead of matching a sentence, and `--json` never moves the code
it is describing.

## Why this needed deciding at all

The rendered report is good at what it is for. It explains a run to a person: a colour-coded
verdict, then a column of steps with the evidence in them, then — for a rehearsal — the two
steps a flip could not take. Nothing about it is stable enough to parse, and that is not a
defect: it is a report written to be read, and the sentences in it are chosen for the reader.

A pipeline parsing it would be binding to a rendering. Three ways that breaks, in the order they
would actually happen:

1. **The line wraps.** The verdict is one `sprintf` today and would still be one sentence if it
   grew a clause — and a `grep` for a phrase that moved onto the next line finds nothing.
2. **A detail contains the phrase being matched.** The steps carry commands, paths and
   supervisor's own output; matching `would flip` against the whole output means matching against
   all of it.
3. **The sentence is edited for clarity.** Every rewording is a silent break for the job, and the
   edit is made in a file that has no idea a pipeline depends on it.

The exit code is already the right contract for the *second* fact — the matrix pins it, the README
documents it — and it stays as it is. What is missing is the first fact: the verdict and the
evidence, as data, in the same run.

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | a job parses the rendered report | binds a pipeline to a rendering, three ways to break silently | **rejected** — this is the problem |
| B | a second command, `db:pgcat-flip-report` | a second command to keep in step with the first, and the exit code would live on the wrong one | **rejected** |
| C | `--format=text\|json` | surface for one format the package has no second use for; `--json` is one flag with one meaning | **rejected** |
| D | JSON only for `--dry-run` | leaves the flip, the state report and the refusals unparseable — the routes a deploy step also branches on | **rejected** |
| E | one object for every route, fixed keys, code inside it, `--watch` refused | a new vocabulary (`kind`) to keep closed, and a key set that must not drift | **chosen** |
| F | NDJSON: one object per pass under `--watch` | no verdict for the run, only for a pass; a report consumer cannot tell which pass it read | **rejected** |
| G | a stream of step events | the verdict is the last line, so the consumer needs the protocol rather than the object | **rejected** |
| H | the object without the exit code in it | two facts to reconcile — the file the job saved and the code it got — which is the split this flag exists to remove | **rejected** |

### A — a job parses the rendered report

Rejected, but worth stating what was actually being asked for by it: nothing. Nobody chose to
parse the report; the report was the only thing there was. The interesting part is that the
parse happens to work today, which is what makes it dangerous — a job built on `grep 'would
flip'` passes review, passes CI, and breaks on the day the sentence gains a word.

### B — a second command

`db:pgcat-flip-report` would have one job and could be shaped entirely for machines. It also
splits one question across two commands: the rehearsal *is* the run that answers "would a flip
work", and if the JSON came from a different command, a deploy step would have to run both to
get a verdict and a code — or run only the report and lose the rehearsal's proof, since a report
command that performed the steps would be a rehearsal with a misleading name.

### C — `--format=text|json`

The honest generalisation: the next format (yaml for something, or a single-line form for logs)
would be a value rather than a flag. It is also a contract with more surface than the package
needs: a `--format` flag invites a format matrix in the tests, and the argument that decides
between `--format=json` and `--json` is one of cost, not of correctness. `--json` is the flag a
pipeline reads as an instruction, and there is exactly one format here. If a second one is ever
needed, `--json` becomes an alias of `--format=json` and nothing else changes.

### D — JSON only for the rehearsal

The request that produced this record was about a rehearsal, and the rehearsal is where the need
is sharpest. But the routes are not separable in practice: a deploy step that runs
`--dry-run --json` and finds `would_flip` then runs the flip — and wants the same report shape
for the flip it performed, its own `--status` check, and the refusal it may get instead of
either. One shape for every route also means one thing to document and one thing to keep closed,
rather than a mode that applies to some invocations and not others.

### E — one object for every route

Chosen. Every return path in `handle()` reports: the state report, both refusals, the disabled
flipper, the unbound container, a forced or a one-shot flip, and a rehearsal. Nothing is written
beside the object, so the whole of stdout is the report:

```json
{
    "command": "db:pgcat-flip",
    "kind": "would_flip",
    "exit_code": 0,
    "reason": "the mode has never been applied, so a first flip would run",
    "error": null,
    "mode": "readers",
    "previous_mode": null,
    "steps": [{"step": "lock", "outcome": "done", "detail": "…"}],
    "status": null
}
```

The five keys that come first are no longer this command's alone: they are `Console\JsonEnvelope`,
written in that order by `db:probe-replicas` and `db:replica-status` as well, with each command's
evidence after them — see [command-json-envelope.md](command-json-envelope.md). What is this
command's own is the evidence (`mode`, `previous_mode`, `steps`, `status`) and the `kind`
vocabulary below.

The keys are written on every route, `null` or an empty list where the route has nothing for one,
which is the same rule the health payload's `counts` follows: a rule that has to check whether a
field exists is a rule that can be written wrong once and stay wrong. `exit_code` is inside the
object because the two halves of the answer travel together — a job that saves the report and
branches on the code is reading one artefact, not two that could disagree.

Two consequences of one object per route are worth stating. A refusal is a verdict like any
other, so `--force-mode=sideways` under `--json` prints an object with `kind: refused` and the
sentence in `reason`, rather than an error line on a human channel — a job that has to tell "the
run never started" from "the run started and failed" needs the reason in the thing it parses.
And the state report carries the flipper's own `status()` array, not the table: `(not set)` is
how a table prints a `null`, and a machine has to be able to test a path for being unset without
matching an English sentence.

### F and G — a stream instead of an object

Both are the right shape for a *different* consumer: a log collector tailing a daemon, or a UI
drawing a flip as it happens. Neither gives a job the one thing it needs, which is a verdict for
the run it started — under `--watch` there is no end to be the verdict, and a step stream leaves
the verdict in the last line, so the consumer must know the protocol rather than the object.

The refusal is what keeps the contract small: `--json --watch` exits `1` with an object saying
why, exactly as `--dry-run --watch` does. Both are pairs where one flag makes the other's
promise untrue — a daemon whose every pass is a rehearsal looks like a flipping daemon, and a
daemon printing reports never reports a run.

### H — leave the code out of the object

The argument for it: the exit code is already the contract, and duplicating it invites the two to
disagree. The argument against, which decides it: a job that stores the report stores the
verdict, and a verdict that does not say what the process exited with has to be reconciled with
a number from somewhere else — in a shell, from `$?`, in a CI step from a different field of the
step's result. `--json` exists so that a run can be described in one artefact, and the code is
half the description. The duplication is not left to chance: the matrix runs twice, once for the
rendered report and once for the object, and asserts both the process's code and the object's
`exit_code` for every cell.

## The chosen mechanism, in full

### The vocabulary

`kind` is the verdict, and it is one namespace rather than two nested questions. A flip's kinds
come from `FlipResult` (`flipped`, `no_change`, `skipped`, `failed`, `window_closed`), a
rehearsal's from `DryRunResult` (`would_flip`, `would_not_flip`, `skipped`, `failed`), and four
routes answer without asking the flipper at all:

| constant | `kind` | what it means | exit |
|---|---|---|---|
| — | `flipped` | a flip applied | `0` |
| — | `no_change` | the mode on record already matched | `0` |
| — | `skipped` | another instance held the lock | `0` |
| `KIND_WINDOW_CLOSED` | `window_closed` | the container's boot window had closed, so the flipper stopped trying until it is replaced | `0` |
| `KIND_STATUS` | `status` | a state report was asked for | `0` |
| `KIND_DISABLED` | `disabled` | flipping is off, or pgcat cannot front this driver | `0` |
| — | `would_flip` | a rehearsal proved a flip would apply | `0` |
| — | `would_not_flip` | a rehearsal found nothing to do | `0` |
| — | `failed` | a step a flip needs did not work — a flip, or a rehearsal | `1` |
| `KIND_REFUSED` | `refused` | a flag combination was refused before anything was read | `1` |
| `KIND_UNBOUND` | `unbound` | the container has no pgcat flipper | `1` |

`refused` and `unbound` are separate kinds because they are separate repairs — a flag to fix
versus a deployment whose provider never registered — and because the exit-code table already
documents them as two cases. `disabled` is separate from `failed` for the opposite reason: the
flipper has said it will not act, which is a statement rather than a fault, and it exits `0`.

`window_closed` is separate for the same reason as `disabled`, one level further out: the run did
exactly what it should have, so the *command* exits `0` and a scheduler never sees a failure it
would have to interpret. What failed is the container, and `/health/db` is where that is reported
(`flip.failed`, with `pgcat.window.failed_reason` naming which of the three ways it failed). A
kind that exited non-zero would put an error line in a scheduler's log every minute for the rest of
the container's life — the one line that says why, buried by sixty repetitions an hour of the
sentence "it still has not worked".

### The four command-level kinds and the routes that produce them

Each route calls `report()` with its own evidence, and `report()` hands it to the shared envelope:

- `status` — the flipper's `status()` array under `status`, exit `0`.
- `disabled` — `disabledReason()` in `reason`, exit `0`.
- `window_closed` — the two numbers that decided it (when the container booted and how long it
  had) in `reason`, exit `0`.
- `refused` — a flag combination, or an unknown `--force-mode`, in `reason`, exit `1`.
- `unbound` — a `PgcatConfigFlipper` that is not one, in `reason`, exit `1`.

`JsonEnvelope::write()` writes with `OutputInterface::OUTPUT_RAW`, not through `line()`: a
machine-readable channel must not have angle brackets in a path or a supervisor message read as a
console tag. Pretty-printed, because a failing CI step's output is read by a person as often as by
a script, and `jq` does not care either way.

### What the flag does not change

The exit code, and the work. A rehearsal is still a rehearsal, a flip still flips and records the
same mode, a refusal still touches nothing — the JSON half of the matrix asserts the target's
bytes and the state record for every cell, so "the report is all that changed" is a claim the
suite holds rather than one the documentation makes.

### The kinds are documented, and the documentation is bound

The README carries the same table as the vocabulary above, and
`DbFlipPgcatCommandTest::test_every_json_kind_is_documented_with_its_exit_code` reads it back
out: the kinds the matrix produces must be exactly the kinds the table lists, and each row's code
must be the code the matrix asserts for the rows that produce it. It is the same guard the exit
tables have, one level in, and for the same reason — a pipeline binds to `kind`, so a kind that
appears in a report without being written down is a break nobody would notice, and a documented
kind that nothing produces is a lie in a table a job may already be written against.

## Tests that pin the rules

| Rule | Test |
|---|---|
| every cell of the exit matrix keeps its code with `--json`, and leaves the same file and record | `DbFlipPgcatCommandTest::test_the_json_report_is_the_same_run_as_one_object` (16 data sets) |
| the output is the object and nothing else | (same test — the whole output is decoded, so a rendered line beside it fails) |
| the key set is fixed and always present | (same test, asserting the key list before anything else) |
| a route that never reached the flipper says why in `reason` | (same test, for `refused`, `disabled`, `unbound`) |
| a failure names the step that failed | (same test, for `failed`) |
| a rehearsal's steps are the pipeline, with the outcomes a flip's would be | `test_the_json_rehearsal_carries_the_pipeline_and_its_outcomes` |
| the state report is the flipper's array, not the table's substitutions | `test_the_json_state_report_is_the_flippers_own_array` |
| a documented kind is a produced kind, code included | `test_every_json_kind_is_documented_with_its_exit_code` |
| `--json --watch` is refused, and never reaches the flipper | the matrix's `a JSON report cannot be a watch daemon` row, plus `test_a_refused_flag_combination_never_reaches_the_flipper` |

Mutations this decision has been checked against: the object's `exit_code` set to `0` while the
process's code is right (seven cells fail on the field), `--json` printed *beside* the rendered
report instead of replacing it (eight cells fail to decode), a key renamed in the envelope (every
cell fails on the key list), the `unbound` route losing its kind (one cell fails), and the README
kind table documenting `failed` as exiting `0` (the binding test fails).

## Known limitations

- **The steps' details are prose, and platform-shaped.** A step's detail carries real paths and a
  real command, with the separators of the platform that ran it; a job should assert on the step
  *names* and outcomes, which are part of the contract, and not on the detail strings.
- **The report is pretty-printed**, so a consumer that reads it line by line sees fragments
  rather than an object per line. Decoding the whole output is the supported way to read it.
- **`disabled` exits `0`.** A job that treats "not a rehearsal and not a flip" as an error will
  be surprised by an installation where flipping is switched off — which is a choice, and the
  case is documented as one.
- **The envelope is not versioned.** Keys are added deliberately (the test states the list), but
  a consumer that rejects unknown keys would break on an addition rather than ignore it.
- **`db:doctor` does not write this envelope.** The gap this record used to name is closed —
  `db:probe-replicas` and `db:replica-status` write the same five keys this one does, from the same
  class ([command-json-envelope.md](command-json-envelope.md)) — but the doctor's object still leads
  with `verdict`, `connection` and `counts` instead. That is a decision with [its own
  record](db-doctor-json.md): what a gate asserts of a preflight is *rows*, and its run verdict is
  one word at two scopes, so renaming it `kind` would put two names for one word on one page. A job
  that reads all four still has one thing to remember, and it is the same thing here: `command`,
  `exit_code`, and a key set that is documented and bound.

## What would change this decision

- **If a second format were needed.** Then `--format` is the flag and `--json` becomes an alias,
  which is a rename rather than a redesign — the object's shape is what the decision is about.
- **If a streaming consumer appeared.** NDJSON under `--watch`, one object per pass with the pass
  in it, is additive: the one-object-per-run contract is about runs that end.
- **If the flip's envelope moved again.** It has, once: the five keys this record defined inline are
  now `Console\JsonEnvelope`, shared with `db:probe-replicas` and `db:replica-status`
  ([command-json-envelope.md](command-json-envelope.md)), and the evidence stayed where it was. That
  was the move this bullet anticipated, and it cost one key order — the five first, evidence after —
  which a consumer does not depend on and the tests state.
- **If the doctor adopted the same envelope.** Then `verdict` becomes `kind` in its object, and the
  reason it does not today (one word at two scopes; see its record) is the thing to answer.
- **If the exit codes moved into the objects.** Today `exitCode()` lives on `FlipResult` and
  `DryRunResult` and the command adds its own four; the JSON's `exit_code` is the command's
  answer, which is right while the command owns the refusals. If the value objects ever described
  a whole run, the code would travel with the payload instead.
- **If the kinds table went stale on purpose.** A deprecated kind would need the binding test to
  accept a kind the matrix no longer produces, which means the table would have to say so.

## Files

| File | Role |
|---|---|
| `src/Console/Commands/DbFlipPgcatCommand.php` | `--json`, the four `KIND_*` constants, its `EVIDENCE` table, `report()`, and a JSON branch in each route |
| `src/Console/JsonEnvelope.php` | the five keys, the raw write and the refusals, shared with the other two commands |
| `docs/command-json-envelope.md` | the envelope as a package-wide decision, which this record's `--json` follows |
| `src/Pgcat/FlipResult.php` | `toArray()` — the flip's evidence, already in the shape the envelope carries |
| `src/Pgcat/DryRunResult.php` | `toArray()` — the rehearsal's evidence, `steps` included |
| `tests/Unit/Console/DbFlipPgcatCommandTest.php` | the JSON half of the exit matrix, the two focused reports, and the kind-table binding |
| `tests/Support/Readme.php` | reads the kind table out of the README as data |
| `README.md` | the envelope, the sample object, the `jq` examples, and the kind table |
| `docs/pgcat-flip-dry-run.md` | what a rehearsal can prove, which the JSON report reports |
| `docs/documented-exit-codes.md` | the same idea one level up: documenting a code and enforcing it |

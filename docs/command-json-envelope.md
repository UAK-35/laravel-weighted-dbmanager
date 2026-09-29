# Do the commands a pipeline branches on write the same object?

A design record for the envelope `--json` reports share.

A pipeline does not read a table. It reads two facts — what happened, and what the process exited
with — and for as long as only `db:pgcat-flip` published them as data, the other two commands a
schedule or a deploy step branches on had to be read by matching English: `grep 'No replica
answered'` against a line that wraps at the terminal width, and an exit status reconciled with a
sentence in a log. This document records the envelope those commands share, which alternatives were
considered, and why they were not taken.

Everything below is implemented in `Console\JsonEnvelope` — the key set, the per-key defaults, the
raw write and the two reports it refuses — over the commands' own `--json` routes; each command's
evidence keys are its own, declared as a class constant beside it, and the register described below
is where every command that writes a report, the key its verdict is read by, the table its words are
documented in and the words two of them may share are declared. The rules are pinned by the tests
named at the end.

---

## The answer in one line

**`Console\JsonEnvelope` writes five keys, in the same order, for every report** — `command`,
`kind`, `exit_code`, `reason`, `error` — **and each command declares its own evidence after them,
so `db:pgcat-flip`, `db:probe-replicas` and `db:replica-status` are read by one rule: the five, then
whatever that command's evidence table says is in the object.**

**The vocabulary is one declaration too.** `JsonEnvelope::REPORTS` names every command in this
package that writes a report, the key its run verdict is read by and the README table that documents
its closed vocabulary, and `JsonEnvelope::SHARED_KINDS` names the words two of them may share. So
what a consumer needs from a report is defined in a class rather than spread over the documents that
describe it: the key set and its order, the vocabulary and the spelling each command reads it by,
the word two reports share on purpose, and the rule that the exit code travels inside the object
rather than in a `$?` from somewhere else.

## Why this needed deciding at all

Two records had already reached this conclusion from opposite ends, and both left the same sentence
behind: [pgcat-flip-json.md](pgcat-flip-json.md) and [db-doctor-json.md](db-doctor-json.md) list
"A shared package envelope" among the things that would change their decision, because the surfaces
that answer "how is this installation doing" — the health payload's audit block, the flip's object,
the doctor's rows — had begun to differ in ways that were only defensible as local: `kind` beside
`verdict`, `ok`/`degraded` beside `PASS`/`WARN`/`FAIL`. Neither record could settle it alone, and
neither command could either: an envelope is what makes two reports comparable.

Two thirds of the family had nothing at all. `db:probe-replicas` is *scheduled*, and its exit code
is what a scheduler reports — the one surface where "parse nothing" is not an option, because there
is no operator in the loop at 03:00. `db:replica-status` is the run an operator starts when reads
look wrong, and it is also the surface `/health/db` is the machine-readable twin of; the endpoint
carries the same two blocks it prints, in keys nothing else used.

The cost of not deciding is not an ugly object. It is that each command, written separately, would
be a third key set to keep in step: a key added to one report and forgotten in another is exactly
the failure this package's guards exist for, and a pipeline that stores reports from a schedule
would find the difference on the day it compared them.

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | each command keeps its own object | three key sets to keep in step, and a consumer that knows one knows none of the others | **rejected** — the seam both earlier records named |
| B | copy the flip's `report()` into the two commands | one behaviour in three places: a key added to one is a report that quietly differs | **rejected** |
| C | one envelope class, evidence declared per command | a declaration per command, and an order to keep | **chosen** |
| D | the evidence nested under one `evidence` key | a level of nesting for no question a job asks, and the flip's documented paths would move | **rejected** |
| E | one universal key set, every key on every report | a sweep would carry `previous_mode: null`, and a job could not tell a field that means nothing from one that is empty | **rejected** |
| F | unify `db:doctor`'s `verdict` into `kind` as well | renames a documented contract to put two names for one word on one page | **rejected**, and named below |
| G | write the object *beside* the rendered report | a consumer parses prose out of a stream, which is the problem | **rejected** |

### A — each command keeps its own object

Rejected, but worth stating what it costs, because "each command's object is its own business" is
true of the *evidence* and false of the *envelope*. `db:probe-replicas` needs its counts and its
per-replica rows; `db:replica-status` needs the manager's summary, pgcat's snapshot and the audit
block; `db:pgcat-flip` needs the modes and the rehearsal's steps. Nothing about those is shared.
What is shared is the answer: which command, what verdict, which code, and the sentences a human
channel would have carried. Three copies of that is three chances for a job to learn that
`exit_code` is `code` on the command it did not test.

### B — copy the flip's report into the two commands

The cheapest thing that would have worked, and the one this package's own history argues against:
one behaviour in three places is what the checks list, the view casts and the exit tables all
started as. The envelope is small, and that is the argument for extracting it *now*: a six-line copy
is cheap to make and cheap to let drift, and the drift is invisible because each object still
parses.

### D — the evidence nested under one `evidence` key

The shape a generated API would have: a fixed head, a polymorphic tail. It fails on the reader it
exists for. A shell rule is `jq -e '.status.degraded == false'`, not `jq -e '.evidence.status…'`,
and the flip's report is already documented with its evidence at the top level — `steps`, `status` —
with a known limitation that says the keys are added deliberately. Nesting would move every path a
job is written against, to make the envelope tidier for a reader who does not exist.

### E — one universal key set

Every key on every report, `null` where a route has nothing for one, is the rule each report
follows *within itself*, and it is the wrong rule across commands. `previous_mode` on a sweep is
not empty, it is meaningless: a job cannot distinguish "this run has no previous mode" from "this
command has no modes", and the second is the truth. The rule that survives is the one now written
down: the five are universal because every run has them, and the evidence is what the command's own
table says it is.

### F — unify `db:doctor`'s verdict too

Recorded as rejected rather than left unsaid, because it is the obvious next question. The doctor's
run verdict is the loudest of its rows, drawn from the three strings the rows use and printed in the
same word in the table's left column — `verdict` is one word at two scopes there, and
`verdict: "FAIL"` on a row is a different fact from `verdict: "FAIL"` on the run. Renaming the run's
to `kind` would put two names for one word on one page, break a documented contract for pipelines
already written against it, and gain a job nothing: a gate that reads `db:doctor --json` branches on
the same field names it always did. The doctor's record keeps that decision.

## The chosen mechanism, in full

### The five keys, and who writes them

`JsonEnvelope::CORE` holds the five keys in the order every report writes them:

| key | what it holds |
|---|---|
| `command` | the command that produced the object, so a stored report says what it is |
| `kind` | the verdict, from the command's own closed vocabulary |
| `exit_code` | the code the process exits with, inside the object |
| `reason` | the sentence a refusal, or a route that found nothing, would otherwise have printed |
| `error` | the failure, when the verdict is one of the command's failure kinds |

`write()` fills `command` and `exit_code` from its arguments, takes the other three from the
route's report, and then appends the command's evidence. `exit_code` travels inside the object for
the reason [pgcat-flip-json.md](pgcat-flip-json.md) settled: a job that saves a report should be
reading one artefact, not a report and a status from somewhere else.

### The evidence is declared, not assembled

Each command declares its evidence as a constant of `key => the value an absent one takes`:

| command | evidence | what an absent one is |
|---|---|---|
| `db:pgcat-flip` | `mode`, `previous_mode`, `steps`, `status` | `null`, `null`, `[]`, `null` |
| `db:probe-replicas` | `connection`, `counts`, `replicas` | `null`, a zeroed map, `[]` |
| `db:replica-status` | `connection`, `status`, `pgcat`, `audit` | `null` each |

A default is the key's *shape*, not a guess: a list key defaults to `[]` because an absent list is
an empty one, and `counts` defaults to a zeroed map for the same reason — an absent count is zero,
and a job should be able to read `.counts.failed` on every route of a scheduled command without
asking whether the field is there. That is `db:doctor`'s own counts rule, arrived at independently.

The route's report is checked against the declaration before anything is written: a key the route
supplied and did not declare throws, because a mistyped one (`replica` for `replicas`) would
publish less than the route meant to while producing perfectly well-formed JSON — the one failure a
consumer cannot detect and a command test does not catch. A command that declares one of the five
core keys as its own evidence throws too; the envelope's keys have one writer.

### The verdict vocabularies are per command, and one word is shared

`kind` is closed per command, documented in the README, and bound by that command's own test: the
kinds a run can reach must be exactly the kinds the table lists, with the code each one exits. The
vocabularies agree where the *meaning* is the same, and `unbound` is the case that shows it: "the
container has no weighted manager" is the same repair whether the missing dependency is the manager
or, in the flip's case, the flipper, and both commands answer `unbound` with the same code. Nothing
else is shared by decree — `no_read_list` and `no_replicas` are different sentences about different
subjects, and naming them one word would be the kind of tidiness a job pays for.

Where each of those tables is, and which key it is read by, is `JsonEnvelope::REPORTS` — so the
question this record used to leave to a reader, whether any word appears on two reports and whether
it is the same word for the same thing, is answered in one place, and `JsonEnvelope::SHARED_KINDS`
is the part of the answer that says `unbound` is deliberate. `JsonEnvelopeTest` reads the register in
both directions: every command whose own source writes a report must appear in it, so a further
report command cannot be written unwatched, and every word two registered vocabularies share must be
declared as shared, so the rule is compared rather than remembered.

### What the flag does not change

Nothing about the run. Each command's matrix runs twice — once for the rendered report and once for
the object — and asserts the same exit code, and, where the command changes something, the same
marks, files and records either way. `--json` is not refused beside anything for these two commands,
because neither has a flag it could contradict: each has the connection argument, and the detail
`-v` prints is the object's `replicas` rather than a second channel.

## Tests that pin the rules

| Rule | Test |
|---|---|
| the five keys, their order, and the defaults a command declares | `JsonEnvelopeTest::test_every_report_leads_with_the_same_five_keys`, `test_an_absent_key_takes_the_default_the_command_declared` |
| a report cannot publish a key its command did not declare | `JsonEnvelopeTest::test_a_report_naming_an_undeclared_key_is_refused` |
| every command that writes a report is registered, with the table its verdict is read by | `JsonEnvelopeTest::test_the_register_names_every_command_that_writes_a_report` |
| a word two reports share is one the register declares as shared | `JsonEnvelopeTest::test_a_word_two_reports_share_is_one_the_register_declares` |
| a command cannot take one of the envelope's own keys | `JsonEnvelopeTest::test_a_command_cannot_take_one_of_the_envelopes_own_keys` |
| a value survives a decorated output | `JsonEnvelopeTest::test_a_value_is_written_through_a_decorated_output_unchanged` |
| every route of the sweep is the same run as one object, `-v` included | `DbProbeReplicasCommandTest::test_the_json_report_is_the_same_run_as_one_object` |
| the sweep's kinds are documented with their codes | `DbProbeReplicasCommandTest::test_every_json_kind_is_documented_with_its_exit_code` |
| every route of the distribution is the same run as one object | `DbReplicaStatusTest::test_the_json_report_is_the_same_run_as_one_object` |
| the distribution's kinds are documented with their codes | `DbReplicaStatusTest::test_the_matrix_agrees_with_the_readme_exit_table` |
| a record nobody could read is a block, not an empty one | `DbReplicaStatusTest::test_the_unreadable_record_is_a_block_the_object_carries` |
| the flip's object keeps its key set and its codes | `DbFlipPgcatCommandTest::test_the_json_report_is_the_same_run_as_one_object`, `test_every_json_kind_is_documented_with_its_exit_code` |

Each command's JSON half asserts the *whole* output is one object — the whole of stdout is decoded
— which is what fails when a rendered line is written beside the report, and each asserts the key
list by reading `JsonEnvelope::CORE` rather than restating it, so a command cannot drift out of the
shared shape while its own test stays green.

## Known limitations

- **`db:doctor --json` still names its run's verdict `verdict`.** That is a decision with its own
  record, not an oversight: see candidate F above. What the register changes about it is that the
  spelling is declared rather than assumed — `JsonEnvelope::REPORTS` carries `verdict` for that
  command — so a reader of the one place learns the whole contract, including the one report this
  class does not write.
- **`status` means two things across surfaces.** In a command's report it is the *subject's own
  array* — the flipper's snapshot, the manager's summary — and in `/health/db` it is the verdict
  string. They are different readers, and the collision is written down here rather than fixed by
  renaming one: the endpoint is not a command report, and `db:replica-status --json` carries the
  audit and pgcat blocks under the endpoint's names precisely so a job can read `.audit` in both.
- **The evidence is prose in places, and platform-shaped.** A probe's `replicas[].error` is a
  driver's message, the flip's `steps[].detail` carries real paths and commands. Assert on the
  kinds, the codes and the counts; the strings are for a person.
- **The envelope is not versioned.** Keys are added deliberately and each command's test states the
  list, but a consumer that rejects unknown keys would break on an addition rather than ignore it.
- **A kind table is bound per command, and the set of words is now held in one place.** Adding a
  kind to the sweep's vocabulary is still a change to the sweep's own table and its own test, which
  is what a per-command vocabulary is for; what is no longer true is that nothing looks at the set.
  `JsonEnvelope::REPORTS` holds the reports, `JsonEnvelope::SHARED_KINDS` holds the words two of them
  may share, and `JsonEnvelopeTest` reads every registered table to prove the second: a word two
  reports share that is not declared there fails, and so does a declared shared word that no two
  reports really share. This bullet used to be the finding — "there is no artefact that holds it".

## What would change this decision

- **If a gate wanted one key name across all four commands.** Then `verdict` becomes `kind` in
  `db:doctor`'s object, and both records' "the envelopes disagree" sentences are deleted together
  with the contract they describe. It is a breaking change for pipelines already written against
  it, which is why it is a decision rather than a cleanup.
- **If the health payload adopted the envelope.** `/health/db` answers a different question for a
  poller, and its `status` string is that answer; giving it a `kind` would make two surfaces mean
  two things by the same word, in the opposite direction to the rename above.
- **If two commands reached the same verdict word for different meanings.** Then `kind` needs a
  namespace, or the vocabulary has to be defined in one place rather than per command — the first
  case where the per-command tables would not be enough.
- **If a second format were needed.** `--format` becomes the flag and `--json` an alias, as both
  earlier records say; the envelope's shape is what the decision is about.

## Files

| File | Role |
|---|---|
| `src/Console/JsonEnvelope.php` | the envelope: `CORE`, the register of the commands that write a report and the words two of them may share, the raw write, the defaults, and the two refusals |
| `src/Console/Commands/DbFlipPgcatCommand.php` | the flip's evidence, its kinds, and a JSON branch in every route |
| `src/Console/Commands/DbProbeReplicas.php` | `--json`, five kinds, and the per-replica rows as `replicas` |
| `src/Console/Commands/DbReplicaStatus.php` | `--json`, three kinds, and the status/pgcat/audit evidence |
| `tests/Unit/Console/JsonEnvelopeTest.php` | the shared half: keys, order, defaults, the refusals, the raw write, the register both ways, and the words two reports may share |
| `tests/Unit/Console/DbProbeReplicasCommandTest.php` | the JSON half of the sweep's matrix and the kind-table binding |
| `tests/Unit/Console/DbReplicaStatusTest.php` | the routes of the distribution, the audit block, and the kind-table binding |
| `tests/Unit/Console/DbFlipPgcatCommandTest.php` | the flip's JSON matrix, which stays the reference for the shape |
| `README.md` | the envelope, the samples, the `jq` examples, and one kind table per command |
| `docs/pgcat-flip-json.md`, `docs/db-doctor-json.md` | the two decisions this follows, and the seam it leaves in the second |

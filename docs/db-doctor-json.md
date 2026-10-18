# What does a deploy gate read when the preflight is the thing it runs?

A design record for `db:doctor --json`.

The doctor is the command a release pipeline is most likely to run: `db:doctor --strict` exits `1`
when the application boots but cannot do what it is configured to do, and the README has documented
it as a release gate for as long as it has existed. That gate had one machine-readable half and one
human half. The exit code was already a contract. Everything the code was computed *from* — which
check failed, what it said, and what would repair it — existed only as a table rendered for a person,
so a pipeline that wanted more than "something is wrong" had to `grep` a row out of output that
carries colour tags and wraps at the terminal width. This document records what such a pipeline
parses now, which alternatives were considered, and why they were not taken.

Everything below is implemented in `Console\Commands\DbDoctor` — `--json`, `report()`, the `counts()`
tally and the `runVerdict()` fold that the table and the object share. The rules are pinned by the
tests named at the end.

---

## The answer in one line

**One JSON object on stdout, with a fixed key set, every row as `checks` with its own `verdict` and
its `suggestions`, the run's `counts`, the run's `verdict`, and the code the process exits with
inside it** — the same flag, envelope and rule `db:pgcat-flip --json` already keeps, so the package
has one machine-readable contract instead of one per command.

## Why this needed deciding at all

The exit code answers a smaller question than it looks like it does. `1` says *something* is wrong
with this installation; it cannot say whether the thing that is wrong is the thing the deploy just
changed. A gate that has to tell "the reader windows are refused, and that is what I deployed" from
"a Redis somewhere is unreachable, and it was yesterday" needs the rows, and it was reading them out
of a rendering.

That is the same problem `db:pgcat-flip --json` was built for, and the reasoning transfers unchanged
— a sentence is reworded for clarity, a line wraps, a detail contains the phrase being matched, and
the job that parsed it breaks silently in a file that has no idea the job exists. What is different
here is the *shape* of what a job wants to assert. A flip has one verdict for a run. A doctor has
twenty-four verdicts, one per row, each with prose evidence and sometimes a repair, and a job is as
likely to care that `store probe` **passed** as that `reader windows` failed.

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | a job parses the rendered table | binds a pipeline to a rendering, three ways to break silently | **rejected** — this is the problem |
| B | a `--format=text\|json` flag | already rejected for the flip; one format, one flag | **rejected** |
| C | a second command, `db:doctor-report` | two commands to keep in step, and the exit code would live on the one a gate does not run | **rejected** |
| D | publish the run's verdict and counts only | a flat object cannot say *which* check failed, which is the whole reason to run a preflight | **rejected** |
| E | publish only the rows that are not `PASS` | a gate cannot assert that a check it cares about passed, and the object's shape would move with the state | **rejected** |
| F | reuse the flip's vocabulary: `kind` for the run, `checks` for the rows | two words for the same three facts, one scope apart | **rejected** |
| G | leave `exit_code` out of the object | two artefacts to reconcile, which is the split the flag exists to remove | **rejected** |
| H | a per-row exit code | a verdict does not decide a code; `--strict` does, which no row can see | **rejected** |
| I | one object, fixed keys, code inside, verdicts at both scopes in one vocabulary | a key set that must not drift | **chosen** |

### B and C — a flag, or a second command

Both were decided for the flip and the decision holds here for the same reasons, plus one: a doctor
that published its rows from a *different* command would have to be run twice by a gate — once to get
a verdict and once to get a code — or run only the report and lose the checks, since a report command
that performed the checks would be the doctor under a misleading name.

### D — the run's verdict and counts, without the rows

The cheapest envelope, and it fails the request that produced this record. `{"verdict": "FAIL",
"counts": {...}, "exit_code": 1}` tells a gate exactly what the exit code already told it, in more
words. The rows are not decoration on the verdict; on a doctor they *are* the finding, and each one
is a different repair.

### E — only the rows that are not `PASS`

Tempting, because a green row carries no information an operator needs, and it would keep the object
small on a healthy installation. Rejected on two counts. A gate frequently wants to assert that a
check it depends on **passed** — `store probe` passing is a claim about whether the store is being
watched at all — and an object that omits passing rows cannot make it. And a row set whose *shape*
depends on the state is a rule a gate can write wrong once and keep: `jq -e '.checks[3].verdict ==
"PASS"'` would be reading whichever check happened to be fourth on that installation.

### F — `kind` for the run, to match the flip

The flip's `kind` is a verdict about *what happened* — `flipped`, `would_flip`, `refused`, one of twelve
values a run can reach. The doctor's run verdict is the loudest of its rows, drawn from the same three
strings the rows use, and the table already prints them in its left column. Naming the run's verdict
`kind` would put two vocabularies on one page for no gain: a gate would have to learn that a `WARN`
row can make a `WARN` run, and would have `kind` on one command and `verdict` on another meaning the
same thing.

### H — a per-row exit code

Rejected because it would publish a number nothing asserts. A verdict is a row's answer about a
check; the exit code is `gateFailed()`'s answer about the run, and it is a function of three things —
whether anything failed, whether anything warned, and whether `--strict` was passed. `FAIL` exits `1`
and `PASS` exits `0`, but `WARN` exits `1` or `0` depending on a flag no row can see, so a per-row code
would be a second rule to keep in step with `gateFailed()` for the two verdicts that are not a failure.
`--strict` and `counts` in the envelope say the same thing as data, once.

### I — one object for the whole run

Chosen. Every row is an entry, and nothing is written beside the object, so the whole of stdout is the
report:

```json
{
    "command": "db:doctor",
    "connection": "pgsql",
    "swrr_connection": null,
    "default_connection": "pgsql",
    "strict": true,
    "connection_only": false,
    "real_test": false,
    "verdict": "FAIL",
    "exit_code": 1,
    "counts": {
        "checks": 11,
        "passed": 7,
        "warnings": 1,
        "failed": 3
    },
    "checks": [
        {"name": "provider swap", "verdict": "PASS", "detail": "…", "suggestions": []},
        {"name": "pgcat gate", "verdict": "FAIL", "detail": "…", "suggestions": ["swrr.pgcat.enabled = false"]},
        {"name": "reader windows", "verdict": "FAIL", "detail": "…", "suggestions": ["swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]"]}
    ]
}
```

The keys are written on every run, an empty list where a run has nothing for one, which is the rule
the health payload's `counts` follows: a rule that has to check whether a field exists is a rule that
can be written wrong once and stay wrong. `exit_code` is inside the object because the two halves of
the answer travel together.

### The keys that name the subject, and the setting behind the connection

`connection`, `swrr_connection` and `default_connection` are not three more readings of the run: they
are what the object says it is *about*, and every other key is a fact about that subject. A vet run —
`db:doctor --config-file=path` — keeps the same envelope, the same key order and the same rules, and
names a different subject in the one position: `config_file` where the installation run writes those
three keys. That is why they belong in the key list rather than beside it, and why a gate reading
`.checks[]` or `.exit_code` does not have to know which mode produced the object. A file has no
`default_connection` to report, and an installation has no file it was judged from.

`swrr_connection` is the one of the three that is not a connection at all: it is the value
`db-manager.swrr.connection` was written with, or `null` when the setting says nothing. Without it the
object cannot tell two installations apart — left unset the package follows `database.default`, and set
to that same name it follows a connection the *operator* chose, which is the same routing and a
different thing to change. The value is read through `Support\ActiveConnection`, the place the gate,
the flipper and the health endpoint read which connection is in play, so this key cannot disagree with
them about which config key the name came from.

Beside `strict` sits `connection_only`, the other flag that changes what a run *asks* rather than how
it is reported: `true` on a `db:doctor --connection-only` run, which leaves the six rows whose
subject is outside the process out of `checks` entirely (see below). A gate that requires a row has to
be able to see that this report was never going to carry it, and a boolean is that fact.

`real_test` is the third flag of that kind, and it changes what a run asks in the other direction:
`true` on a `db:doctor --real-test` run, which puts one real query to the connection the run follows
and reports the answer in the `real query` row. It is published for the same reason the other two are
— a report that opened a connection and one that only read configuration must not be the same artefact
to a job selecting on rows — and it is a *flag* rather than a row's presence, because the row is in
every run: without the flag it passes saying no query was attempted, which is the difference between
"the query passed" and "nothing was asked" that an image with no database behind it turns on.

The three flags are the fields a job reads to know what kind of run it is holding; everything a gate
selects on — `verdict`, `exit_code`, `counts`, `checks` — means the same thing in all the combinations.

The rendered run publishes the subject too, and in the configuration's own spelling: one line under
the banner, `db-manager.swrr.connection` when the setting named the connection and `database.default`
when the package fell back to it, with the connection it followed as the value — the pair above, in the
spelling a shell gate can grep for and a reader can act on. The object is the whole of the machine
contract; the line exists because a deploy's gates read the log.

## The chosen mechanism, in full

### One word for the verdict, at two scopes

`verdict` is `PASS`, `WARN` or `FAIL` — the three strings the table prints in its left column, not a
second vocabulary for them. The run's `verdict` is the loudest row: `FAIL` if anything failed, `WARN`
if anything warned, `PASS` otherwise. That fold lives in `loudest()`, and it is the *same fold* the
rows use for themselves when one row names several problems — the table's closing summary and the
object's `verdict` therefore cannot describe a run differently from the rows above them, which is the
same argument that keeps `gateFailed()` a method rather than a copy of itself at the exit code.

The two scopes are published because they answer different questions — "is this installation broken"
and "which check is broken" — and because a gate that only has the second has to walk the list to
reach the first.

### The exit code, and the one case where it disagrees with the verdict

`exit_code` is the code the process exits with, computed by `gateFailed()` and returned unchanged, so
`--json` cannot move a contract the exit tables already bind. Under `--strict` it is `1` while nothing
failed and every row is `PASS` or `WARN`. That is the state the rendered run prints a special sentence
for, because an operator reading exit `1` with no failing row in front of them has nothing to tell
them the *gate* broke rather than the installation. The object carries the same three facts as fields
— `strict`, and both counts — so a job can make the same distinction without a sentence:

```bash
jq -e '.verdict != "FAIL"' preflight.json                                  # is anything actually broken?
jq -e '.exit_code == 1 and .verdict == "WARN" and .strict' preflight.json   # ...or is this just --strict?
```

The run's `verdict` is deliberately *not* derived from `exit_code`. A verdict read back out of the
code would have to call an installation that answered every check broken, which is the confusion the
sentence exists to prevent.

### The row list is not a fixed length

`checks` is every row the run built. That is twenty-four on an installation where `db` resolves to the
weighted manager and **ten** where it does not — the replica rows are only asked when there is a
manager to ask them of, which is a property of the rendered command that predates this flag, and
`--connection-only` removes six more by design (`connection_only` below). So `name` is the row's
identity and the thing to select on; the order and the count are the table's, and are documented as
such. `--real-test` adds no row to any of those lists: `real query` is built in every run, and the
flag decides what that row says rather than whether it exists.

`name` is the same string the table prints, so the report an operator read and the object a job parses
name the same row the same way. `suggestions` is the repairs the row offers — the value or the mode to
apply, drawn from the same source the renderer's `suggestion` column is — and an empty list where a row
has none.

Four rows can offer one, and each takes it from the class that owns the value rather than from a rule
written out a second time here: `ReaderWindows` and `ReaderDays` for the reader settings, and
`PgcatConfigFlipper` for the pgcat ones — the switch armed where pgcat cannot act, an unquoted program
name, a command left empty, and, per problem, the file preconditions `fileProblems()` reports. A row
offers a line only where the fault reduces to something exact, which for a file problem means a mode
the check found missing (`chmod +r` on a source that cannot be read, `chmod +w`/`+wx` on a path a flip
must write) or the value the published config ships for a key that is empty. A path that is simply not
there, and an empty `readers_path`/`no_readers_path`, still get no line: those values are the
installation's, and the package will not name one it would have to guess at. A line is not a verdict:
the `FAIL` stands, and is counted, whether or not one is printed under it.

### A row that has several problems names every one of them

`detail` is the row's own account of what it found, and several rows can find more than one thing:
`reader windows` reports each refused reader setting, `switch values` each switch that is not a switch,
`pgcat files` each file a flip needs and cannot use, `store probe` a probe that is switched off beside
a record that cannot be written, and the vet's `config file` the shape nobody can judge beside the
bytes the file printed while it was being read. Each problem keeps its own sentence, so a row that
named whichever problem it noticed first would still read as a complete answer — which is why the row
set is declared in `DbDoctorTest::rowsNamingSeveralProblems()` and every entry in it is given two
problems and asked for both. The register is the test: a row that names several problems, and is not
in it, is a row the register has stopped describing.

The set test beside those cases measures the answer out of the run — the record dates each problem
from its own finding, so the rows carrying more than one date are the rows naming several problems.
That measure needs the problems to be *datable*, which is why the store probe's two states had to
become findings: a state standing for weeks is a state an operator asks "since when" about, and
`swrr.audit.store_probe_seconds.off` / `swrr.audit.file.unwritable` are what makes it answerable. The
vet's `config file` row is the entry that cannot be measured that way at all: its subject is a file
that has never been booted, so nothing dates what it read — the register's case test drives that run
with `--config-file` instead of reading the installation, which is why the set test stays about the
four installation rows.

#### The rows that are one fact, and why

Because the measure can only see dated rows, "every row that can name several problems" was audited
one row at a time rather than inferred from the register. Each row of a full run, what it can be
about, and which of the two it is:

| Row | What it can be about | Disposition |
|---|---|---|
| `boot environment` | the file this boot read its settings from, and whether the values it read came from the config files or from a cache built before one of them was written | **both named, in one sentence** — they are two facts about the same reading, and the second is unreadable without the first. Undated, and no value is refused: nothing about a boot's environment is a finding the audit keeps |
| `config file` (vet) | the shape the file needs to be judged at all, and the bytes it printed while it was being read | **both named** — the shape is the file's and the bytes are what `config:cache` would write into the cached config |
| `provider swap` | a `db` that would not resolve, a provider that is not registered, a `db` that is not the weighted manager | one fact in three diagnoses: the last two are the same cause, and both `weighted factory` and `pgcat gate` name the missing provider in the same run |
| `weighted factory` | `db.factory` that could not be resolved, and one that is not the weighted factory | mutually exclusive — a binding that threw has no class to inspect |
| `connector` | the class the connection is built through with the binding that answered it, a connection nothing can build, a `db.factory` that cannot be asked at all, and — in the same sentence — the class the connection resolves to with whether an installation's `Connection::resolverFor()` replacement built it | one answer per state, and the three connector states are mutually exclusive: a connector that could be built has no error, one that could not has no class, and a factory that cannot be asked reports neither — and it is *one* sentence in each, because the class and where it came from are halves of a single fact (the framework's own, with nothing bound at `db.connector.<driver>`, against the installation's, resolved from that binding). The resolved connection is the second half of the same sentence and one of three readings: no resolver registered, so the class is the framework's own; a resolver registered and the connection built through it; or a resolver registered and the connection in use older than it, which is the only state that says a replacement is registered and *not* in effect. Where no connection could be built the conditions are absent rather than empty, and a connection build that threw is the row's fourth failing state |
| `published config` | no published file at all, one byte-identical to the sample, and one whose own `generation` — the key set it was published as — is behind this package's, ahead of it, absent, or a value that is not a whole number | mutually exclusive — a file that is not there cannot be identical to anything, and a byte-identical copy declares the sample's own generation: the row prints one sentence per run, and [config-generations.md](config-generations.md) is what each of them means |
| `pgcat gate` | the driver mismatch, and an armed flipper whose paths are unset | mutually exclusive by construction (`isMismatched()` and `isEnabled()` cannot both hold), and `pgcat files` names the paths |
| `pgcat files` | every file precondition a flip needs | **several, named all** — the register |
| `pgcat supervisor` | an empty command, an executable that does not resolve, an unquoted program, a supervisord that does not know the program | one chain: each fault is what physically prevents seeing the next, and the same verdict is what a flip refuses on |
| `replica metadata` | every replica whose metadata the resolver cannot read, and every replica left out of the pool on purpose | **several, named all**, undated: the pool's own decisions are not findings |
| `switch values` | each switch written as something that is not a switch | **several, named all** — the register |
| `reader windows` | each refused reader setting, and each window that can never be entered | **several, named all** — the register |
| `pgcat mode` | one fault in two directions: the writer-only config while a reader window is open, and the readers config outside every window | **one, named at the front of the sentence** — which end of the last window the flip missed is the operator's next question, so the direction is stated rather than left to the sentence that follows; the flipper's own reason travels as the rest of the detail, and an unreadable file is *nothing compared* rather than a pass |
| `pool probe` | the pooler refused to serve a pool the configuration declares, and the pooler that answered nothing at all | **one store of answers, read as one**: what the pooler said is a single reading with a single verdict, so the pools that did not answer are named in one sentence rather than one per pool — `missing` is judged (it is the pool the flip re-applies the file for), `silent` is only reported, and neither is judged while the boot window is still open. Its two answers are told apart by the verdict rather than by two sentences, which is why nothing here is dated |
| `pool repairs` | how many repairs this container recorded, and when the last one happened | **one reading, two facts**: the count and the time are one answer about one counter — whether the pooler was repaired at all, and how long ago — so they travel in one sentence rather than one per fact. It is a reading rather than a verdict (a repair is the self-heal working), it is undated (a repair is a run's outcome, not a state the audit keeps), and it is read from the same state file and the same derivation as `--status` |
| `store probe` | a probe switched off, a record that cannot be written, an in-process store | **several, named all** — the register |
| `store reachability` | a primary store that failed in this process, a process already degraded, an in-process store, a store that would not answer a PING | one store, one state: a store that failed is *why* the process is degraded |
| `real query` | the query that was asked and how it answered, and the state where no query was asked at all | **two mutually exclusive states in one sentence each** — either the query was put to the connection and the driver's own words came back, or nothing was connected to. Neither is dated: a database that refused a moment ago is not a finding the audit keeps, and the state that was not asked is not a finding at all |

The vet's other three rows are the installation's, by name and by sentence: a candidate's refused
replica metadata, switches and reader settings are the same problems, read from a file, and they are
dated from nothing for the same reason — a file has never been booted. Its `replica metadata` row
carries only the classifier's refusals and the `weight: 0` drains it names, not the pool arithmetic:
a file has no pool until it is booted.

### What the flag does not change

The exit code, the checks, and the filesystem. `--json` is a report rather than a mode: the same rows
are built, `gateFailed()` computes the same code, and nothing is written either way. It composes with
`--strict`, which is how a pipeline will pass it, and there is nothing to refuse — the flip has to
refuse `--json --watch` because a daemon emits one object every interval without ever reporting a run,
and a doctor has no such pair. The README's rendered sample and its JSON sample are the same run,
verdict for verdict, which is what makes the two halves of the documentation read as one example.

### The verdicts are documented, and the documentation is bound

The README carries a table of the three verdicts and what each means at both scopes, and
`DbDoctorTest::test_every_json_verdict_is_documented` reads it back out: the verdicts the report can
carry must be exactly the verdicts the table lists, with the set derived from the matrix's own profiles
rather than listed a second time in the test. It is the same guard the flip's kinds table has, for the
same reason — a gate binds to `verdict`, so a verdict that appears in a report without being written
down is a break nobody would notice.

One thing this table deliberately does *not* have is a per-verdict exit code, which is the flip's
table's second column. There is no such mapping to document (candidate H), and inventing one to make
the two tables look alike would be a table the matrix could not check.

### A bounded run is the same envelope with fewer rows

`--connection-only` is the one flag that changes *which rows exist*, and a gate has to be able to see
that: six rows — `pgcat supervisor`, `pool probe`, `read caps`, `standby feedback`, `store probe`,
`store reachability` — are left out of a bounded run entirely, because each one's subject is outside
the process: a supervisord to ask, a pooler to open a startup packet to, and a read endpoint to
connect to and read its own `statement_timeout` and `hot_standby_feedback` off. A row that reports
its own missing prerequisite as the installation's fault is worse than a row that is not printed. The
object publishes `connection_only: true` for exactly that reason, and `checks` is the full run's list
less those six, in the order a full run asks them. Nothing else about the envelope moves: the same
key set, `verdict` from the rows that were asked, and `exit_code` from `gateFailed()` over the same
rule — which is what lets one gate read a bounded report and a full one without learning a second
shape.

The `real query` row is the one reading a bounded run keeps that is about the outside world, and it is
kept precisely because it does not take it: unless `--real-test` asks, the row passes saying nothing
was connected to, so the mode's promise stands and a gate can still select the row by name. With the
flag, the query is the run's own request and the mode's banner says so — a bounded run that reached a
database without saying it had would be the same failure as a bounded report read as a complete one.

### A contract run is the same envelope with the requirements as rows

`--contract` is the other flag that changes *which rows exist*, and it does it the other way round from
`--connection-only`: nothing is left out — the list is replaced. The rows become the requirements of
[docs/deployment-contract.md](deployment-contract.md), one per key, each named exactly as the record
states it (`wiring`, `configuration`, `variants`, …, `health-endpoint`), with its verdict the loudest
verdict among the rows behind it. The detail names those rows and their verdicts and then carries the
failing ones' sentences; their repairs become the requirement's `suggestions`. Nothing is re-read, so
the object is the same object: the fixed key set, `verdict` from the requirement rows, `counts` over
the same list, and `exit_code` from `gateFailed()` — a gate that reads `jq -e '.checks[] | select(.name
== "configuration") | .verdict == "PASS"'` is asserting about a deployment fact rather than about a
mechanism.

Two of the requirement's states are worth a gate knowing. A requirement whose readings were left out by
`--connection-only` passes saying which ones, in the row's own words — the mode composes, so the object
still carries `connection_only: true`. And a requirement no row can answer passes saying it was **not
judged**: `health-endpoint` is the only one today, and it is fixed in the class rather than decided by
a run, so a gate can treat that key's `PASS` as "not judged by design" without parsing prose. Because
every row the README documents is behind at least one requirement, the contract run is not a shorter
run: no reading is lost by a pipeline moving to it, which `DeploymentContractTest` holds in both
directions. `--config-file` is refused beside `--contract`, with exit `1` and a sentence rather than a
half-honoured pair — a file is not a deployment.

## Tests that pin the rules

| Rule | Test |
|---|---|
| the whole exit matrix, twice: the object's counts are the numbers the rendered matrix asserts, its code is the cell's code, and so is the process's | `DbDoctorTest::test_the_json_report_is_the_exit_matrix_as_one_object` (14 data sets) |
| the object is all of stdout and its key set is fixed | (same test — the whole output is decoded and the key list asserted before anything else) |
| every row has the published shape, a verdict from the three, and `suggestions` as a list | (same test) |
| the object is the table as data: one installation, both reports, the row names and verdicts compared to each other | `DbDoctorTest::test_the_json_report_is_the_table_as_data` |
| the repairs are on the row that carries them, and an empty list where there are none | `DbDoctorTest::test_the_json_report_carries_the_suggestions_the_table_prints` |
| a pgcat repair travels as data too: the line the table prints is the string the object carries, and a row that can name none carries `[]` rather than omitting the key | `DbDoctorTest::test_the_json_report_carries_the_pgcat_repair_line_the_table_prints` |
| a row that can name several problems names every one of them — and one that stops at the first fails, which no other test in the file would see | `DbDoctorTest::test_a_row_that_can_name_several_problems_names_every_one_of_them` (four data sets) |
| which rows those are is measured out of the run rather than restated: the rows carrying a date per problem are the ones the register declares | `DbDoctorTest::test_the_rows_naming_several_dated_problems_are_the_ones_the_register_declares` |
| a verdict the report can carry is documented, and vice versa | `DbDoctorTest::test_every_json_verdict_is_documented` |
| a vet run keeps the envelope and swaps the subject key for `config_file`, so one gate reads both modes | `DbDoctorTest::test_the_config_file_flag_reports_through_the_json_envelope` |
| the deploy gate the README pastes ships a preflight whose named rows pass | `DoctorGateTest::test_the_gate_the_readme_pastes_ships_a_preflight_whose_named_rows_pass` |
| ...and refuses a named row that is not `PASS`, naming it | `DoctorGateTest::test_the_gate_refuses_a_named_row_that_is_not_pass` |
| ...and refuses a run whose unnamed row failed, naming the rows | `DoctorGateTest::test_the_gate_refuses_a_run_whose_unnamed_row_failed` |
| every row the gate blocks on is a row a real report carries | `DoctorGateTest::test_every_row_the_gate_names_is_a_row_a_run_produces` |
| a contract run's rows are the record's keys in its order, its tally counts requirements, and a failing reading fails the requirement that asked it — carrying that row's sentence and repair up | `DbDoctorTest::test_the_contract_flag_reports_one_row_per_requirement`, `DbDoctorTest::test_a_failing_reading_fails_the_requirement_that_asks_it` |
| a requirement no row answers passes saying it was not judged; a bounded contract run names the readings it did not ask; and the file mode is refused beside it | `DbDoctorTest::test_a_requirement_no_reading_answers_passes_and_says_it_was_not_judged`, `DbDoctorTest::test_a_bounded_contract_run_names_the_readings_it_did_not_ask`, `DbDoctorTest::test_the_contract_and_config_file_flags_are_refused_together` |
| the record's `Key` column is the class's key list, every reading is a row the README documents, and every documented row is behind a requirement | `DeploymentContractTest` (four tests) |

Mutations this decision has been checked against, run against the matrix test unless noted: the
envelope's `checks` renamed to `rows` (14 of 14 cells fail on the key list), the row's `verdict`
renamed to `status` (14 of 14 fail on the row shape), `exit_code` pinned to `0` while the process
exits correctly (10 cells fail on the field), `strict` pinned to `false` (7 cells fail), the run's
`verdict` pinned to `PASS` (12 cells fail), `suggestions` emptied (the matrix is silent — no cell's
profile carries a repair — and the dedicated suggestions test fails, which is why that test exists),
and the README's `FAIL` row relabelled `FATAL` (the vocabulary guard fails naming both sets). The rule
about a row with several problems has its own: `datedRow()` printing only the first problem's
sentence fails **24** tests, and the register's five cases and its set test are six of them — the
other eighteen are the rows' own tests, including the three `store probe` row tests and the four the
vet's `config file` row has. The store probe
used to be the case that survived this mutation, because the row built its two sentences itself
instead of asking `datedRow()` for them; once its two states were findings it built them the same way
every other row does, so the mutation reaches it and the register's case is no longer the only thing
that would notice. That is the whole reason the row's states became findings: a row that dates what
it reports is a row the same rule can bind, and one the set test can measure.

## Known limitations

- **The row set is not a fixed length.** Ten rows when `db` does not resolve, twenty-four otherwise, and
  six fewer than either in a `--connection-only` run, which is why the object publishes that flag. A gate
  must select by `name` — the row's identity, and the same string the table prints — and nothing in the
  object states how many rows there "should" be, so a gate that expects a particular row to exist has
  to decide what its absence means on its own. The README's deploy gate is the worked example of that
  decision, and `DoctorGateTest` is where it is enforced: it asserts that every row the gate blocks on
  is a row a real report carries, because a row renamed in the doctor would leave the gate matching
  nothing and passing every preflight.
- **`detail` is prose, and platform-shaped.** It carries real paths, real commands and supervisor's
  own output, with the separators of the platform that ran it. Assert on `name`, `verdict` and
  `exit_code`, which are the contract; `suggestions` is the one string-shaped value meant to be acted
  on, and it is the package's own spelling of a setting.
- **`--strict` is the only flag that changes what a *verdict means*, so the object does not record
  that a run was a gate.** `--json` produces the object, `--config-file` swaps the subject it is about,
  and `--real-test` is what the `real query` row's verdict is *about* — the one flag that can turn that
  row from "not asked" into a query's answer, and the one a reader has to match between two stored
  reports before comparing them. A report saved from a non-strict run and one from a strict run of the
  same installation differ in `strict` and `exit_code` and nowhere else, which is intended — but a gate
  that stores one and compares it to the other has to say which it ran, and whether it asked for a
  query.
- **The envelope is not versioned.** Keys are added deliberately (the test states the list), but a
  consumer that rejects unknown keys would break on an addition rather than ignore it.
- **`verdict` is upper case and the health payload's `status` is not.** The doctor's three strings are
  the table's own, and the health endpoint's `ok`/`degraded`/`misconfigured` are a different
  vocabulary for a different question asked of a different reader. A package-wide envelope settles the
  *report* half of that question and deliberately not this half: `JsonEnvelope::REPORTS` names the
  commands that write a report, and `/health/db` is not one of them — the line
  [command-json-envelope.md](command-json-envelope.md) draws between an endpoint a poller reads and a
  report a gate branches on.
- **The gap this record opened with is closed, and one difference is left on purpose.** When it was
  written, `db:probe-replicas` and `db:replica-status` had no machine-readable form at all; both now
  write the five keys `db:pgcat-flip` defines, from one class
  ([command-json-envelope.md](command-json-envelope.md)). What remains is the name of the run's
  verdict: `verdict` here, `kind` in the three command reports — and it is declared rather than
  merely noted, which is the one place this record asked for: `JsonEnvelope::REPORTS` carries
  `verdict` for this command, so what a preflight calls its run verdict is read from the register
  itself. See candidate F, and the bullet below about adopting the shared envelope.

## What would change this decision

- **If the three command reports' envelope became this one's too.** It partly has: `command`,
  `exit_code` and a documented, bound key set are in all four objects, and the five keys themselves
  are one class now ([command-json-envelope.md](command-json-envelope.md)). What a `kind` here would
  still buy a gate is one name for the run's verdict across all four commands, and what it costs is
  this record's candidate F: `verdict` is one word at two scopes on a doctor, and a row's `WARN`
  making a run's `WARN` is the sentence it would have to keep. The health payload's own
  `ok`/`degraded`/`misconfigured` is a third reading of the same question, for a poller rather than
  a gate, and it is not a command report at all.
- **If a second format were needed.** `--format` becomes the flag and `--json` an alias, as it would
  for the flip; the object's shape is what the decision is about.
- **If the row set became variable in more ways.** A row that is only asked under some
  configurations means `name` is stable but the *set* is not, and a gate that wants "every row
  passed" would be asserting something weaker than it thinks. Stating the expected row set in the
  object — a list of the checks this run intended to make — would close it, at the cost of a second
  source of truth about what a doctor checks.
- **If the checks moved into a registry.** Then the row set, its names and the object's shape could be
  generated from one place rather than three (`handle()`, the README table and this record), which is
  the direction `bin/checks.php` already takes for its own check list.
- **If exit codes moved into the objects.** The flip's record notes the same condition. Today the code
  is `gateFailed()`'s and the command returns it; if the rows ever described a whole run, the code
  would travel with the payload instead.

## Files

| File | Role |
|---|---|
| `src/Console/Commands/DbDoctor.php` | `--json`, `report()` (the envelope and the raw write), `counts()`, `runVerdict()`, `loudest()` — the fold `verdict()` and `runVerdict()` share |
| `tests/Unit/Console/DbDoctorTest.php` | the JSON half of the exit matrix, the table-versus-object comparison, the suggestions, and the verdict-vocabulary binding |
| `tests/Support/Readme.php` | the verdict table read out of the README as data |
| `README.md` | the envelope, the sample object, the `jq` examples, the deploy gate a pipeline pastes, and the verdict table |
| `tests/Unit/Docs/DoctorGateTest.php` | the gate run rather than read: a real report it must ship, two it must refuse, and the rows it names checked against the ones a run produces |
| `docs/documented-exit-codes.md` | the same idea one level up: documenting a code and enforcing it |
| `docs/pgcat-flip-json.md` | the flip's object, whose flag, key-set rule and "the code travels inside it" this follows |
| `docs/command-json-envelope.md` | the five keys as one class, shared with `db:probe-replicas` and `db:replica-status`, and why this object does not take them |

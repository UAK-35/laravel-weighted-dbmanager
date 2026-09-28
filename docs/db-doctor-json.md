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
eleven verdicts, one per row, each with prose evidence and sometimes a repair, and a job is as
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

The flip's `kind` is a verdict about *what happened* — `flipped`, `would_flip`, `refused`, one of eleven
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
    "default_connection": "pgsql",
    "strict": true,
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

### The pair of keys that name the subject

`connection` and `default_connection` are not two more readings of the run: they are what the object
says it is *about*, and every other key is a fact about that subject. A vet run —
`db:doctor --config-file=path` — keeps the same envelope, the same key order and the same rules, and
names a different subject in the one position: `config_file` where the installation run writes those
two keys. That is why the pair belongs in the key list rather than beside it, and why a gate reading
`.checks[]` or `.exit_code` does not have to know which mode produced the object. A file has no
`default_connection` to report, and an installation has no file it was judged from.

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

`checks` is every row the run built. That is eleven on an installation where `db` resolves to the
weighted manager and **six** where it does not — the replica rows are only asked when there is a
manager to ask them of, which is a property of the rendered command that predates this flag. So `name`
is the row's identity and the thing to select on; the order and the count are the table's, and are
documented as such.

`name` is the same string the table prints, so the report an operator read and the object a job parses
name the same row the same way. `suggestions` is the repairs the row offers — the setting line to
paste, drawn from the same source the renderer's `suggestion` column is — and an empty list where a row
has none.

Three rows can offer one, and each takes it from the class that owns the value rather than from a rule
written out a second time here: `ReaderWindows` and `ReaderDays` for the reader settings, and
`PgcatConfigFlipper::suggestionForGate()`/`suggestionForSupervisor()` for the pgcat ones — the switch
armed where pgcat cannot act, an unquoted program name, a command left empty. A row offers a line only
when the fault reduces to one exactly; `pgcat files` never does, because every problem it reports is a
path or a permission and the package will not name a value it would have to guess at. The line is not
a verdict: the `FAIL` stands, and is counted, whether or not one is printed under it.

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

## Tests that pin the rules

| Rule | Test |
|---|---|
| the whole exit matrix, twice: the object's counts are the numbers the rendered matrix asserts, its code is the cell's code, and so is the process's | `DbDoctorTest::test_the_json_report_is_the_exit_matrix_as_one_object` (14 data sets) |
| the object is all of stdout and its key set is fixed | (same test — the whole output is decoded and the key list asserted before anything else) |
| every row has the published shape, a verdict from the three, and `suggestions` as a list | (same test) |
| the object is the table as data: one installation, both reports, the row names and verdicts compared to each other | `DbDoctorTest::test_the_json_report_is_the_table_as_data` |
| the repairs are on the row that carries them, and an empty list where there are none | `DbDoctorTest::test_the_json_report_carries_the_suggestions_the_table_prints` |
| a pgcat repair travels as data too: the line the table prints is the string the object carries, and a row that can name none carries `[]` rather than omitting the key | `DbDoctorTest::test_the_json_report_carries_the_pgcat_repair_line_the_table_prints` |
| a verdict the report can carry is documented, and vice versa | `DbDoctorTest::test_every_json_verdict_is_documented` |
| a vet run keeps the envelope and swaps the subject key for `config_file`, so one gate reads both modes | `DbDoctorTest::test_the_config_file_flag_reports_through_the_json_envelope` |

Mutations this decision has been checked against, run against the matrix test unless noted: the
envelope's `checks` renamed to `rows` (14 of 14 cells fail on the key list), the row's `verdict`
renamed to `status` (14 of 14 fail on the row shape), `exit_code` pinned to `0` while the process
exits correctly (10 cells fail on the field), `strict` pinned to `false` (7 cells fail), the run's
`verdict` pinned to `PASS` (12 cells fail), `suggestions` emptied (the matrix is silent — no cell's
profile carries a repair — and the dedicated suggestions test fails, which is why that test exists),
and the README's `FAIL` row relabelled `FATAL` (the vocabulary guard fails naming both sets).

## Known limitations

- **The row set is not a fixed length.** Six rows when `db` does not resolve, eleven otherwise. A gate
  must select by `name`; nothing in the object states how many rows there "should" be, so a gate that
  expects a particular row to exist has to decide what its absence means on its own.
- **`detail` is prose, and platform-shaped.** It carries real paths, real commands and supervisor's
  own output, with the separators of the platform that ran it. Assert on `name`, `verdict` and
  `exit_code`, which are the contract; `suggestions` is the one string-shaped value meant to be acted
  on, and it is the package's own spelling of a setting.
- **`--strict` is the only flag that moves the verdict, so the object does not record that a run was
  a gate.** `--json` produces the object and `--config-file` swaps the subject it is about; neither
  changes a verdict. A report saved from a non-strict run and one from a strict run of the same
  installation differ in `strict` and `exit_code` and nowhere else, which is intended — but a gate that
  stores one and compares it to the other has to say which it ran.
- **The envelope is not versioned.** Keys are added deliberately (the test states the list), but a
  consumer that rejects unknown keys would break on an addition rather than ignore it.
- **`verdict` is upper case and the health payload's `status` is not.** The doctor's three strings are
  the table's own, and the health endpoint's `ok`/`degraded`/`misconfigured` are a different
  vocabulary for a different question asked of a different reader. A package-wide envelope would
  settle it; there is not one (see below).
- **The gap this record opened with is closed, and one difference is left on purpose.** When it was
  written, `db:probe-replicas` and `db:replica-status` had no machine-readable form at all; both now
  write the five keys `db:pgcat-flip` defines, from one class
  ([command-json-envelope.md](command-json-envelope.md)). What remains is the name of the run's
  verdict: `verdict` here, `kind` in the three command reports. See candidate F, and the bullet
  below about adopting the shared envelope.

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
| `README.md` | the envelope, the sample object, the `jq` examples, and the verdict table |
| `docs/documented-exit-codes.md` | the same idea one level up: documenting a code and enforcing it |
| `docs/pgcat-flip-json.md` | the flip's object, whose flag, key-set rule and "the code travels inside it" this follows |
| `docs/command-json-envelope.md` | the five keys as one class, shared with `db:probe-replicas` and `db:replica-status`, and why this object does not take them |

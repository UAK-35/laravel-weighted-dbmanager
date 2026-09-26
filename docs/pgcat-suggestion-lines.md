# Which pgcat rows should print the config line to paste?

A design record for the `suggestion` lines the `pgcat gate` and `pgcat supervisor` rows print,
and for the one pgcat row that prints none.

`db:doctor` has printed a `suggestion` line under a row since the reader-window refusal: a
refused value that names its own replacement is printed in the shape the setting reads, so the
repair is a paste rather than a translation. That rule was written for the reader settings. The
pgcat rows were the other half of the same problem, and they had it worse: their sentences
already *named* their repairs, buried where a sentence puts them —

```
FAIL  pgcat gate       … Set swrr.pgcat.enabled = false, or point database.default at the
                       pgcat-fronted connection. …
FAIL  pgcat supervisor a flip would run supervisorctl signal HUP pgcat:*, but pgcat:* is
                       unquoted: … Write supervisorctl signal HUP "pgcat:*" — the quoted
                       name is the one supervisorctl receives unchanged
FAIL  pgcat supervisor the configured supervisor command is empty, … — write
                       swrr.pgcat.restart_command (or reload_command, with use_reload on)
```

— which is fine to read and useless to act on: a `--json` gate cannot select a clause out of
`detail`, and a terminal cannot paste one. This document records what the package prints
instead, which alternatives were considered, and why they were not taken.

Everything below is implemented in `Pgcat\PgcatConfigFlipper::suggestionForGate()` and
`::suggestionForSupervisor()`, and in `DbDoctor::pgcatGate()` / `::pgcatSupervisor()`. The
rules are pinned by the tests named at the end.

---

## The answer in one line

**A pgcat row prints a `suggestion` line only when the fault reduces to an exact config value,
and the flipper — which owns the keys and the documented commands — names it.** `pgcat gate`
prints `swrr.pgcat.enabled = false` for the switch armed where pgcat cannot act. `pgcat
supervisor` prints the setting line for the two faults that reduce to a command: a program name
that needs quoting, and a command left empty. `pgcat files` prints no line at all.

---

## Why this needed deciding at all

Three facts, each of which rules out an otherwise obvious answer.

### 1. The repair was already written, one surface too far

None of the three sentences above was missing its repair — they all name one, and two of them
name it precisely enough to paste. The fault was that a *sentence* is the only place a report
could put it. The rest of the report is machine-readable on request: `--json` publishes every
row with a `verdict` and a `suggestions` list, and a deploy gate is invited to bind to them. A
gate cannot bind to `` `Set swrr.pgcat.enabled = false, or point …` `` without parsing English,
so the one repair an operator is most likely to want was the one thing the contract could not
carry.

### 2. The two pgcat rows fail for reasons that are not all values

`pgcat gate` fails for two different faults — the switch on where pgcat cannot act, and the
flipper armed without a path a flip needs — and `pgcat supervisor` for six. Exactly one of the
gate's and two of the supervisor's reduce to a value. The rest are a PATH, a permission, a
running supervisord, a `[program:]` section, or a path only the installation knows. A row that
printed a line for every fault would have to invent something for those, and the first invented
value pasted into configuration is the failure this whole area is written to avoid.

### 3. The reader rule already answers the hard part

`ReaderWindows::suggestion()` states the line: a line is printed only where the refused value
reduces to an exact replacement, and never where the package would have to guess at what was
meant — "a wrong guess pasted into configuration routes reads the wrong way round, which is the
outcome the refusal exists to prevent." A pgcat fault is the same question one key over, so the
same answer applies; the only new question was *which class* owns the spelling.

---

## The candidates

### A — leave the repair in the sentence

The status quo, and the one thing it has going for it is that the sentence already reads well.
Rejected because it leaves the `--json` contract unable to carry the one repair a job would act
on, and because `db:doctor` prints a `suggestion` column for the reader rows — two rows that
name their repairs and only one of them pasteable is a rule with an exception nobody wrote down.

### B — print the sentence's repair clause verbatim as the line

Cheap, and it would make the column non-empty everywhere. Rejected twice over: the clause is
prose (`Set … = false, or point database.default at the pgcat-fronted connection` has *two*
repairs in it, so a gate binding to the line would bind to a choice it cannot make), and the
line is supposed to be the setting *in the shape the config file uses*, which this is not.
Extracting the clause would also mean parsing the sentence, which is a second copy of the rule
waiting to drift from the first.

### C — name a path default for `pgcat files`

The sample config prints `/etc/pgcat/pgcat.toml`, `/etc/pgcat/pgcat-readers.toml` and so on, so
a line could always be printed for an unset path. Rejected: those paths belong to the pgcat
install, not to the package. The sample's values are an example of the *shape*, and the README
deliberately leaves `readers_path` and `no_readers_path` documented as `—` because there is no
value to document — a hypothetical container path, or a distro's. Pointing a flip at a file
that does not exist on this host is worse than an empty column, because it looks like an answer.

### D — ask supervisor what it *does* know, and name the program from the answer

`supervisorctl status` with no program lists every program supervisord runs, and the
`unknown_program` fault could then print the name it found — turning "supervisor does not know
`pgcat:*`" into `swrr.pgcat.restart_command = 'supervisorctl restart "pgcat_primary"'`.
Attractive, and rejected on two counts. It is a *guess about which program is pgcat*: a host
that runs several pools, or one named after its role, would get a line pointing at whichever
program came first. And it changes what the inspection does — an extra process, and a read whose
answer depends on what happens to be running — for a fault whose repair is genuinely on
supervisor's side of the fence. The row says what supervisor does not know, which is the fact
the operator acts on.

### E — suggest `database.default` instead of `enabled`

The mismatch sentence names both repairs, and for an installation whose pgcat is genuinely
wanted, turning the switch off is the wrong one — the connection is. Rejected because the
suggestion would then be a line the package cannot write: `database.default` is not a
`db-manager` key, and the value it should have is the connection's own name, which the flipper
does not know. Candidate B's problem in a different guise. The line prints the repair that is a
`swrr.pgcat` value, and the sentence keeps the other one in view.

### F — a line for every fault, using the fault's sentence as the "repair"

The suggestion column would never be empty. Rejected because it makes the column mean two
different things: sometimes a value to paste, sometimes a restatement of the row. Everything
downstream that binds to `suggestions` — a gate that applies repairs, a checklist — would have
to tell the two apart, and the one time it guessed wrong it would apply prose.

### G — a line only where the fault reduces to an exact value, named by the class that owns it (chosen)

One rule, the reader rule, applied to the pgcat rows; the line comes from the flipper, which is
where `swrr.pgcat`'s keys, the `use_reload` switch and the documented commands already live. The
three faults that qualify get a line, the seven that do not get none, and both are pinned by
tests rather than by taste.

---

## The chosen mechanism, in full

### Which faults get a line

| Row | Fault | Suggestion |
|---|---|---|
| `pgcat gate` | the switch on where pgcat cannot act | `swrr.pgcat.enabled = false` |
| `pgcat gate` | armed without a path a flip needs | none — the paths are the installation's to choose |
| `pgcat gate` | this boot is fine, a mismatch is still on record | none — the configuration has nothing to change; a boot closes the record out |
| `pgcat files` | a missing, unreadable or unwritable file, or a directory that will not take the temp file | none |
| `pgcat supervisor` | `unquoted` | `swrr.pgcat.<key> = '<the command, program name quoted>'` |
| `pgcat supervisor` | `empty` | `swrr.pgcat.<key> = '<the command that key documents>'` |
| `pgcat supervisor` | `unresolved`, `unknown_program`, `unanswered`, `errored` | none |
| `pgcat supervisor` | `not_supervisorctl`, `no_program` | none — these pass |

### The line comes from the flipper, not from the row

`PgcatConfigFlipper` already owns every fact the line needs, and owns them in one place: the key
name, the switch that chooses between the two command settings, and the two commands the
published config documents.

- `suggestionForGate()` returns the line when `isMismatched()` — the switch is on and the
  current connection's driver is not one pgcat can front. That is exactly the condition the row's
  `FAIL` branch reports, so a line appears when and only when the fault does.
- `suggestionForSupervisor(array $verdict)` takes the verdict `SupervisorStep::inspect()`
  returned — the same call the row, the flip and `--dry-run` all read — and answers from its
  `fault`. Both callers already hold a verdict, so nothing inspects twice: the read-only
  `supervisorctl status` is one process per run, as it was.

The doctor wraps neither and invents nothing. It hands over `suggestionLines()`, which drops the
lines that could not be named, so a row that has none passes `[]` rather than `null` — the JSON
contract's `suggestions` is always a list.

### The key is the one the flip reads

`use_reload` decides whether a flip runs `restart_command` or `reload_command`, and the repair has
to name the same one: a line sending the operator to the setting their flip is not using is a
repair that changes nothing. This is why the line cannot come from `SupervisorStep`, whose
`empty` sentence names *both* keys with a parenthetical — it does not know which one the flip
reads, and the flipper does.

### The value is a PHP literal

`var_export()` writes it, so a command containing either kind of quote comes back as a valid
config value rather than a truncated one — `supervisorctl restart "pgcat:*"` keeps its double
quotes inside single ones, and a program name with an apostrophe would keep that too. The
`unquoted` repair is the command `SupervisorStep` itself built for its read-only invocation,
so the value printed is the one a flip would then run, not a re-quoting written out here.

### The sentence stays

Every fault sentence is unchanged, including the two whose repair the row now also prints as a
line. `armingWarning()` and `inspect()['detail']` are read by the flip's refusal, by `--dry-run`
and, for the gate, by `/health/db`'s `warning` — none of which has a suggestion column. A
sentence shortened for the row's benefit would leave those surfaces naming a fault with no
repair. So the row prints both: the sentence for reading, the line for acting, and they agree.

---

## Tests that pin the rules

| Test | Rule |
|---|---|
| `PgcatConfigFlipperTest::test_the_gate_suggestion_is_the_switch_turned_off_where_pgcat_cannot_act` | the gate's line, and the condition it appears under |
| `PgcatConfigFlipperTest::test_the_gate_suggestion_is_null_when_the_repair_is_not_a_pgcat_value` | armed-but-incomplete, off, and ready all give none |
| `PgcatConfigFlipperTest::test_the_supervisor_suggestion_is_the_command_with_the_program_name_quoted` | the `unquoted` line, exactly |
| `PgcatConfigFlipperTest::test_the_supervisor_suggestion_names_the_key_the_flip_reads` | `use_reload` picks the key, so the line does too |
| `PgcatConfigFlipperTest::test_the_supervisor_suggestion_for_an_empty_command_is_the_command_it_documents` | the `empty` line, both keys |
| `PgcatConfigFlipperTest::test_no_suggestion_is_named_for_a_fault_a_pgcat_value_cannot_repair` | the other six faults and the two non-faults, each against a real verdict |
| `DbDoctorTest::test_the_gate_row_prints_the_switch_as_the_line_to_paste` | the row prints it, and still fails |
| `DbDoctorTest::test_the_gate_and_files_rows_print_no_line_for_a_path_only_the_installation_knows` | the two rows with nothing to name |
| `DbDoctorTest::test_the_supervisor_row_prints_the_quoted_command_to_write` | the row's half of the `unquoted` repair |
| `DbDoctorTest::test_the_supervisor_row_fails_when_the_command_is_empty` | the row's half of the `empty` repair, in the documented shape |
| `DbDoctorTest::test_the_supervisor_row_prints_no_line_for_a_fault_a_config_line_cannot_reach` | a fault repaired in a PATH, end to end |
| `DbDoctorTest::test_the_json_report_carries_the_pgcat_repair_line_the_table_prints` | the line is the object's string, and the sentence still names it |

Mutations this decision has been checked against, run against the two test classes above:

- **the `FAULT_UNQUOTED` arm returning `null`** — 3 failures: the flipper's two supervisor
  suggestion tests and `DbDoctorTest::test_the_supervisor_row_prints_the_quoted_command_to_write`.
  The empty-command case survives, which is right: it is a different arm.
- **`commandKey()` pinned to `restart_command`** — 10 failures, and not only the suggestion
  tests: the flipper's `use_reload` tests, its refused-switch test and six of the doctor's
  supervisor rows read the same key, so the mutation is caught wherever the command is read
  rather than only where the repair is printed.
- **`suggestionForGate()` returning a line unconditionally** — 7 failures: the gate's own null
  test and the files-row test, the object-versus-table test, and the four reader-window repair
  tests, whose rows would each pick up a line the installation does not have. A stray suggestion
  is loud, which is what this column being exact buys.

---

## Known limitations

- **The sentence and the line say the same thing twice.** By design, and for the reason above —
  three surfaces read the sentence and only one has a suggestion column — but a reader of the
  table sees `… Set swrr.pgcat.enabled = false, or point …` directly above
  `swrr.pgcat.enabled = false`. Nothing checks that they agree, so a future edit to one could
  contradict the other.
- **`unknown_program` is the fault most likely to deserve a line, and does not get one.** The
  name supervisor knows is discoverable (candidate D) but not that it names pgcat's pool, and the
  row stops there deliberately.
- **`pgcat files` is the only row in the whole command that never prints a line.** That is a
  property of the faults, not of the row, and it would change the moment a fault there reduced to
  a value — see below.
- **The line is offered for the fault as written, not for the installation's intent.** A gate
  that applies `suggestions` blindly will turn `swrr.pgcat.enabled` off where the operator meant
  to point `database.default` at a PostgreSQL connection instead (candidate E). The line says
  what clears the warning; it does not claim to be what was meant.
- **`suggestionForSupervisor()` takes a verdict rather than inspecting.** That keeps one
  `supervisorctl status` per run, but it also means a caller can hand it a verdict from a
  different command than the one a flip would run. Both real callers pass
  `inspect($flipper->supervisorCommand())`, and the test helper builds verdicts through the real
  `inspect()`, so the coupling is only asserted by convention.

## What would change this decision

- **If the package ever validated a path it does not own** — a `pgcat.*_path` whose directory
  exists, say, or a lock file it creates itself — then a `pgcat files` fault would reduce to a
  value and the row would print one. Nothing in the current design does that.
- **If `suggestions` became a job's input rather than its signal.** A gate that applied repairs
  automatically would need the line to be unambiguous about *which* repair it is, and candidate
  E — the second repair the mismatch sentence names — would have to be either printed as a
  second line or explicitly excluded. The contract as written is "a value to paste into the
  config file", and the row's identity says which fault it clears.
- **If supervisor grew a way to enumerate its programs** that did not depend on what is running
  when the doctor happens to be run, candidate D becomes a real repair rather than a guess.
- **If `use_reload` were removed** in favour of two independent settings, the line would stop
  needing the flipper's `commandKey()` — but it would also stop being able to say which command a
  flip runs, which is the fact it is really about.
- **If a pgcat fault were added whose remedy is a *different* pgcat key** — a path, or a
  connection mapping — the line would no longer be the fault's own setting, and `suggestionForGate()`
  would need to return a key rather than a fixed one.

## Files

| File | Role |
|---|---|
| `src/Pgcat/PgcatConfigFlipper.php` | `suggestionForGate()`, `suggestionForSupervisor()`, `commandKey()`, `documentedCommand()`, and the documented commands as constants |
| `src/Pgcat/SupervisorStep.php` | the faults, the verbatim sentences, and the quoted command a repair prints |
| `src/Console/Commands/DbDoctor.php` | `pgcatGate()` and `pgcatSupervisor()` print the line; `pgcatFiles()` deliberately does not; `suggestionLines()` |
| `tests/Unit/Pgcat/PgcatConfigFlipperTest.php` | the fault-to-line mapping |
| `tests/Unit/Console/DbDoctorTest.php` | the rows, and the object's half |
| `docs/reader-windows-refusal.md` | the rule this one follows, and where it was decided |
| `docs/pgcat-supervisor-preflight.md` | the supervisor faults the two lines are drawn from |

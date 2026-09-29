# Which pgcat rows should print the config line to paste?

A design record for the `suggestion` lines the `pgcat gate`, `pgcat supervisor` and `pgcat files`
rows print — the last one per problem, and only where the repair is something the package can state
exactly — and for the problems that get no line at all.

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

Everything below is implemented in `Pgcat\PgcatConfigFlipper::suggestionForGate()`,
`::suggestionForSupervisor()` and `::fileProblems()`, and in `DbDoctor::pgcatGate()` /
`::pgcatSupervisor()` / `::pgcatFiles()`. The rules are pinned by the tests named at the end.

---

## The answer in one line

**A pgcat row prints a `suggestion` line only when the fault reduces to something the package can
state exactly, and the flipper — which owns the keys, the documented commands and the file
preconditions — names it.** `pgcat gate` prints `swrr.pgcat.enabled = false` for the switch armed
where pgcat cannot act. `pgcat supervisor` prints the setting line for the two faults that reduce to
a command: a program name that needs quoting, and a command left empty. `pgcat files` prints one line
per problem it can repair, and those are two kinds: a **mode**, for a path this installation has
chosen and cannot use, and a **setting line**, for an empty key whose value the published config
ships. A path that is not there, and an empty `readers_path`/`no_readers_path`, still get none.

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

**Half of C was adopted later, for the three keys that are not hypothetical.** The published config
ships `config_path`, `state_file` and `lock_file` — the README's table documents a default for each
of them — so a line naming that value is the package's own rather than a path it invented, and the
objection that mattered against C (a value that could drift from the default it names) is answered
by reading the sample out of the repository in a test. `readers_path` and `no_readers_path` are the
two where the sample only ever shows an example of the *shape*, the README documents them as `—`, and
they still get no line. See "The files row, later" below.

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

**The asking half of D has since been adopted, and the line half has not.** The `pgcat
supervisor` row now runs that second command and reports the programs nearest the configured
name in its *sentence* (see [pgcat-supervisor-preflight.md](pgcat-supervisor-preflight.md),
"An unknown program asks a second question"). Both objections were addressed rather than
overridden: the ranking replaces "whichever program came first" with the relations an operator
would think of — the same name, the group it belongs to, a prefix, a name that holds it, a
close spelling — capped at three and offered as a list, and the second command runs only after
the program has been found missing, so the ordinary flip still asks one question. What did not
change is the `suggestion`: a line is written to be applied by a gate that does not read it, and
"the closest name" is a judgement about intent, which is exactly what candidate D was rejected
for offering as a value. The row now says what supervisor *is* running, which is the fact the
operator needs to work out what they meant.

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
| `pgcat files` | a source file that is not readable | `chmod +r <the path>` |
| `pgcat files` | the target file that is not writable | `chmod +w <the path>` |
| `pgcat files` | a directory a flip writes into (the target's, the state file's, the lock's) | `chmod +wx <the directory>` |
| `pgcat files` | `config_path`, `state_file` or `lock_file` is empty | `swrr.pgcat.<key> = <the value the published config ships>` |
| `pgcat files` | a source or the target that is not there | none — the file has to be put there by whatever installs pgcat |
| `pgcat files` | `readers_path` or `no_readers_path` is empty | none — the README documents those two as `—` |
| `pgcat supervisor` | `unquoted` | `swrr.pgcat.<key> = '<the command, program name quoted>'` |
| `pgcat supervisor` | `empty` | `swrr.pgcat.<key> = '<the command that key documents>'` |
| `pgcat supervisor` | `unknown_program` | none — the sentence names the running programs nearest the configured name; a ranked near miss is not a value to apply |
| `pgcat supervisor` | `unresolved`, `unanswered`, `errored` | none |
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

### The files row, later: a mode, and a published value

`pgcat files` was the row that printed nothing, and the reason was that every problem it reported was
a path or a permission. That stopped being true of every problem at once — a permission is a mode on a
known path, and an empty key is a value the published config documents — so the row was re-decided
rather than left as the exception this document was written around. Which states get a line:

| State | Line |
|---|---|
| a source file that is not readable | `chmod +r <the path>` |
| the target file that is not writable | `chmod +w <the path>` |
| a directory a flip writes into (the target's, the state file's, the lock's) | `chmod +wx <the directory>` |
| `config_path`, `state_file`, `lock_file` is empty | `swrr.pgcat.<key> = <the value the published config ships>` |
| a source or the target that is not there | none |
| `readers_path` / `no_readers_path` is empty | none |

**The mode is the bit the check found missing**, on the path the check was run against — which is why
a directory gets two of them: the temp file is written into the directory and the rename that finishes
the swap acts on it, so write without traverse still fails. It is a symbolic *add* rather than a mode,
so nothing the operator set is rewritten, and it is deliberately unscoped: `db:doctor` proves that
*this* user can read and write those paths — `pgcat-flip-dry-run.md` records that limitation for the
rehearsal, and it is the same one here — so a line naming a narrower class could print a repair that
does not clear the check it was printed for. An installation whose pgcat config carries credentials
should scope it; the row names the path and the bit, which is what a narrower repair needs.

**The setting line is the published value, resolved on this host.** `config_path` is
`/etc/pgcat/pgcat.toml` as the sample spells it, and the two state paths are the sample's
`sys_get_temp_dir() . '/pgcat-flip-…'`, evaluated. `PgcatConfigFlipper::documentedPaths()` is where they
live, for the same reason the documented commands live in constants — the repair and the default cannot
drift apart — and a test reads the published config out of the repository and compares the two.

**A line's shape says where it goes.** `chmod …` is a command; `swrr.pgcat.… = …` is a setting. A gate
that applies `suggestions` without reading them therefore has one rule to write, which is what
candidate F was rejected for and what this is not: the two kinds are distinguishable from the first
word, and both are pinned by tests rather than by taste.

**Which states are *problems* did not change.** The row reports exactly what it reported before, in
the same sentences and the same order, read from `PgcatConfigFlipper::fileProblems()` rather than from
a detector of the doctor's own — and the boot audit records the same list as findings, under one key
per setting, so a preflight can say how long a file has been unusable instead of only that it is. An
empty key is the one state the record does not carry: there is no file to judge, the package's audit
reports files rather than armings, and `db:doctor`'s two rows are where an arming without its paths is
reported — the gate names the key to fill in, and this row prints the published value as the repair
where the config ships one. Each problem is dated from its own finding, so a row with two of them
prints two dates and a problem with no entry prints none.

Checked by mutation: returning no line for `FILE_UNREADABLE`, borrowing the doctor's own path for the
published value, and auditing an empty key each fail the test named for them below.

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
| `DbDoctorTest::test_the_gate_and_files_rows_print_no_line_for_a_path_only_the_installation_knows` | the gate row, and the one files problem whose value only the installation knows |
| `PgcatConfigFlipperTest::test_the_documented_paths_are_the_ones_the_published_config_ships` | the published values are read out of the sample rather than copied beside it |
| `PgcatConfigFlipperTest::test_an_empty_key_carries_the_published_value_only_where_the_config_ships_one` | which empty keys get a line, and which two do not |
| `PgcatConfigFlipperTest::test_a_directory_that_will_not_take_a_write_carries_the_mode_that_would` | the directory line, and the same line for the two settings that share a directory |
| `PgcatConfigFlipperTest::test_a_file_that_is_not_there_gets_no_line` | a missing file gets none, on every filesystem: nothing about the environment can skip it |
| `PgcatConfigFlipperTest::test_a_file_that_cannot_be_read_carries_the_mode_that_would` | an unreadable one gets `+r` (skipped where the platform ignores mode bits) |
| `PgcatConfigFlipperTest::test_each_file_mode_problem_carries_the_bit_that_failed` | all three modes, asked of the rule rather than of a file, so they hold where mode bits are ignored |
| `PgcatConfigFlipperTest::test_an_inert_flipper_has_no_file_preconditions_at_all` | nothing to judge while the flipper is not armed |
| `DbDoctorTest::test_the_files_row_prints_one_repair_line_per_problem` | the row prints the list rather than the first problem's repair |
| `DbDoctorTest::test_the_files_row_repairs_an_empty_key_only_where_the_published_config_ships_a_value` | the same rule through the row, with three empty keys and one line |
| `DbDoctorTest::test_each_file_problem_is_dated_from_its_own_finding` | one key per setting: two problems, two dates |
| `WeightedDatabaseServiceProviderTest::test_a_file_a_flip_needs_that_cannot_be_used_is_recorded_under_its_settings_key` | the record, its context, and the resolution that closes it |
| `WeightedDatabaseServiceProviderTest::test_an_empty_pgcat_path_is_the_rows_problem_and_not_a_file_finding` | the one state the record leaves to the row |
| `WeightedDatabaseServiceProviderTest::test_switching_pgcat_off_closes_a_file_finding_out` | the other half of every resolution sentence |
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
- **the `FILE_UNREADABLE` arm returning no line** — 1 failure, and *which* test it is depends on
  the platform: on a box that honours mode bits it is the unreadable-file case, and on a box that
  ignores them — this laptop — it is
  `PgcatConfigFlipperTest::test_each_file_mode_problem_carries_the_bit_that_failed`, whose
  `a source that cannot be read` row asks the rule for the line of a *kind* and a *path* and touches
  no file at all. That row exists because the filesystem case has to skip where the environment
  cannot make a file unreadable; without it the mutation measured **0** failures here, which is how
  the gap was found rather than argued about. The `+w` and `+wx` arms are pinned the same way now,
  deterministically, and the missing-file half stays a case of its own because nothing about the
  environment can skip it.
- **the published state path spelled out instead of read from the sample** — 2 failures: the drift
  guard that compares `documentedPaths()` with the published config, and the empty-key test that
  prints the line. A repair that has drifted from the default it names fails on the guard rather
  than in front of an operator.
- **the audit recording an empty key as a file finding** — 34 failures, across the provider and
  doctor suites. That is the count that decided this rule: an installation that is armed but not yet
  pointed at its files is the *default fixture* of these tests, and a boot that logged a line for
  each unset path would log three of them per boot, forever, about a state the two rows already
  report and the flip's first use already throws on.
- **the row printing one repair for the first problem rather than one per problem** — 3 failures:
  the per-problem test, the empty-key test (whose line is not the first problem's) and the
  date-per-problem test. The order the problems arrive in is the flipper's, so a row that printed
  only the first would send an operator to one of the three files it is complaining about.

---

## Known limitations

- **The sentence and the line say the same thing twice.** By design, and for the reason above —
  three surfaces read the sentence and only one has a suggestion column — but a reader of the
  table sees `… Set swrr.pgcat.enabled = false, or point …` directly above
  `swrr.pgcat.enabled = false`. Nothing checks that they agree, so a future edit to one could
  contradict the other.
- **`unknown_program` is the fault most likely to deserve a line, and does not get one.** The
  name supervisor knows is now discovered (half of candidate D) and the row names the closest
  ones in its sentence, but "closest" is not "meant": the line is the value a gate applies
  without reading, so the ranking stays where a human reads it.
- **`pgcat files` can print the same line twice.** The row is the only one that reports several
  problems about one path — the state file and the lock in one unwritable directory print one
  `chmod +wx` each — and it keeps them separate rather than folding them, because they are two
  settings with two repairs. An operator reads that once and changes one mode.
- **A mode line names the bit, not the user.** `+r` and `+w` grant the bit to every class, which is
  what makes the line clear the check it was printed for whoever runs the flip — and also a change
  to a file that may hold credentials. Scoping it is the operator's call, and the row gives them
  what a narrower repair needs: the path and the bit.
- **An empty `state_file` or `lock_file` is the row's and not the record's.** The provider defaults
  both when they are absent, so the empty case needs the key to be set to an empty string on
  purpose — and a boot that audited it would be the only surface reporting it, which is the rule
  this record draws the other way: the audit reports files, `db:doctor` reports armings.
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
- **If a gate came to *apply* repairs rather than offer them.** The two kinds of line are told apart
  by their first word today, which is enough to read and not enough to act on safely; an applying
  gate would need the destination named — a field beside the line, or a key its shape is derived
  from — and the mode lines would need the user question settled rather than left to the operator.
- **If the boot audit came to report the arming itself** — an armed flipper missing the paths a flip
  needs — then the empty-key problems in `pgcat files` would have a finding to be dated from, and the
  rule that the record reports files rather than armings would be the thing to revisit. The count is
  in the mutations above: 34 tests pin the current silence.

## Files

| File | Role |
|---|---|
| `src/Pgcat/PgcatConfigFlipper.php` | `suggestionForGate()`, `suggestionForSupervisor()`, `commandKey()`, `documentedCommand()`, and the documented commands as constants |
| `src/Pgcat/SupervisorStep.php` | the faults, the verbatim sentences, and the quoted command a repair prints |
| `src/Console/Commands/DbDoctor.php` | `pgcatGate()`, `pgcatSupervisor()` and `pgcatFiles()` print the lines; `datedRow()` turns each problem into a sentence, a key and a repair; `suggestionLines()` |
| `src/Providers/WeightedDatabaseServiceProvider.php` | `PGCAT_FILE_KEYS`, the map from setting to finding key, and `pgcatFileFindings()` |
| `tests/Unit/Pgcat/PgcatConfigFlipperTest.php` | the fault-to-line mapping, both for the command faults and for the file preconditions |
| `tests/Unit/Console/DbDoctorTest.php` | the rows, and the object's half |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the file findings the boot records, and the resolution that closes them |
| `docs/reader-windows-refusal.md` | the rule this one follows, and where it was decided |
| `docs/pgcat-supervisor-preflight.md` | the supervisor faults the two lines are drawn from |

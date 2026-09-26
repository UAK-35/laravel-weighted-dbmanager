# Are the exit-code tables enforced, or only written down?

A design record for binding a documented exit code to the matrix that enforces it, so the
two cannot drift apart.

Everything below is implemented in `tests/Support/Readme.php`, and in one test in each file
that owns a matrix: `DbProbeReplicasCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`,
`DbDoctorTest::test_the_matrix_agrees_with_the_readme_exit_table` and
`DbFlipPgcatCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`.

## The answer in one line

The README's exit-code tables are parsed as data and compared, cell by cell, with the same
data providers the matrices are written as — so a number in the documentation that the code
does not produce fails the suite.

## Why this needed deciding at all

An exit code is a contract with whatever runs the command: a scheduler, a deploy gate, an
alert. Three commands in this package are scheduled, and each has a matrix that pins its
codes against fixtures built for the purpose. The README states the same codes in a table an
operator reads — a `what the sweep found` column and an `exit` column, a truth table over
`--strict` — and that table was maintained by hand, next to the code, in a different file.

Two rules that must agree, written twice, with nothing checking that they do. The failure
mode is not hypothetical: it is a deploy gate written against a table that says exit `1`
while the command has started exiting `0`, and nobody finds out until a release passes
that should not have.

## Which commands compute a code from more than one condition

Asked of every command the package registers, because a rule with one input does not need a
table: the question is only interesting where several states have to map onto one number.

| Command | The rule, in terms of what it decides from | Pinned by |
|---|---|---|
| `db:doctor` | anything failed, anything warned, `--strict` — three inputs into `gateFailed()` | a fourteen-cell matrix (`test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag`), the closing sentence from the same rule, and this guard |
| `db:probe-replicas` | whether the read list held a replica at all, versus whether any of them answered — two ways to reach `1` | a nine-cell matrix, and this guard |
| `db:pgcat-flip` | which branch a run takes: the guard, a refused flag combination, the flipper's kind, armed or not | a sixteen-cell matrix, plus named tests for the rules a row cannot hold — option precedence, that a refused combination never reaches the flipper, and the kind→code mapping at the value objects — and this guard. Its `--json` report documents the same codes a level in, as a table of kinds, bound by the same mechanism ([pgcat-flip-json.md](pgcat-flip-json.md)) |
| `db:replica-status` | one input: is the weighted manager bound | not a matrix. It exits `0` on every path the suite drives; the single `1` is the same guard the probe's matrix pins as its own row |

`bin/checks.php` also exits non-zero from more than one check, but it is the gate that runs the
other pins rather than a command this package ships, and its rule is one condition over an
aggregate: every check passed.

The flip is the case that needed this record's second half: its matrix existed and was not bound
to anything an operator reads. Its table is now written the way the sweep's and the preflight's
are, and read back the same way.

## The candidates

| Candidate | What it would assert | Why it was rejected |
|---|---|---|
| A. A documentation test that *runs* the command per documented row | The behaviour of each row of the table, driven from the table | Duplicates the matrix, and worse: the fixtures it needs (a pgcat installation, a reachable store, a real probe) already exist in the matrix's own file, and the row-to-fixture mapping would have to be rebuilt and maintained per documented case — a second matrix, not a guard |
| B. Compare the provider with the parsed table | That the cells the matrix enforces are the numbers the table documents, and that the two describe the same set of cases | Chosen |
| C. Assert that every documented code is one a cell produces (a set comparison) | Little: three of the probe's five rows document `0` and two document `1`, so the sets match while a specific case is documented as the wrong one | A guard that passes when a case flips is not a guard |
| D. Put the provider's key in the table | An exact one-to-one link | The README is read by operators; test keys in it are for the wrong reader, and the command's rows were deliberately not written one-to-one with the documented cases |
| E. Generate the table from the provider | That the two cannot disagree, by construction | The table is not derivable from the provider: the documented rows are the cases an operator recognises (`at least one replica answered`), and the provider's rows are the fixtures that produce them. Generating the table would move the operator-facing wording into the test file, where it would be written for the wrong reason |
| F. Leave it to review | Nothing | This is the state the package was in; the drift it allows is a wrong exit code in the one place an operator looks first |

## The chosen mechanism, in full

**Reading a table.** `Readme::table(string $heading, string $column)` finds the heading by its
text (backticks and spacing are prose; the words are the identity), then the first table under
it whose header has that column. Rows come back keyed by their own header cells, so a caller
reads a value by column name and a renamed column fails at the caller's own lookup rather
than shifting a positional index. Every way of reading nothing — no file, no heading, no such
column, a row with a different number of cells — throws, because a guard that quietly reads
nothing reports agreement with a table it never found.

The heading parameter matters for `db:doctor` in particular: that section's first table is
the ten-row description of what each check judges, not the exit-code table, so "first table
under the heading" would bind the wrong thing. Selecting by column is what makes the guard
say what it is about.

**Comparing a label.** A documented case is identified by its text in the case column, and
`Readme::plain()` is how two labels are compared: backticks stripped, whitespace collapsed,
lower-cased. A reflow of the README is a reflow, not a drift; a renamed case is a drift.

**The probe's nine cells.** The matrix has nine cells and the table has five documented cases,
deliberately: everything where some replica answered is one documented case, however many
fixtures produce it, and the connection with no read list is asked about twice (named and
defaulted). The correspondence is therefore declared, in
`DbProbeReplicasCommandTest::documentedSituations()`: provider key → the README row it is an
instance of. It is the only thing either side has to keep in step, and it is checked in both
directions — every provider key must appear in it, and the set of cases it names must be
exactly the set of rows the README has.

**The doctor's rule.** The doctor's table is a truth table over two facts (did anything fail,
did anything warn), and every cell of the matrix is a point in it, so no map is needed:
`documentedRow()` derives the documented case from the counts the matrix test already asserts,
and the same comparison runs — the cell's number in the column its `$strict` selects.

**The flip's sixteen cells.** The flip's table is eight documented cases against sixteen
cells: the three ways a flip can politely do nothing are one case, the three ways a command can
be refused are another, and the rehearsals are one case however they end. Its map lives in
`DbFlipPgcatCommandTest::documentedSituations()`, and the same two directional checks run.
One rule the table names is not a row of the matrix: `--status` winning over a rehearsal asked
for in the same run has a test of its own, because its only evidence is a line the *other*
branch did not print — while every other assertion a row can make, that nothing was touched and
nothing was recorded, is true of a rehearsal as well.

**The four failure modes.** Reverting a documented number; adding a documented case with no
cell behind it; rewording a documented case so it is no longer the one the matrix names; and
dropping a cell's assignment to a documented case. Each is a failing assertion with the case
and the two numbers in the message, not a silent drift.

**What is asserted, and what is not.** The comparison is between the *provider* and the
documentation; the provider's own agreement with the running command stays where it was, in
the matrix test. The chain is therefore complete — code ↔ provider ↔ README — and each link
fails on its own terms.

## Tests that pin the rules

| Test | Pins |
|---|---|
| `DbProbeReplicasCommandTest::test_the_matrix_agrees_with_the_readme_exit_table` | the sweep's five documented cases, their numbers, and that every one of its nine cells is an instance of one |
| `DbDoctorTest::test_the_matrix_agrees_with_the_readme_exit_table` | the doctor's three documented cases, both `--strict` columns, and that the two sets of cases are the same |
| `DbFlipPgcatCommandTest::test_the_matrix_agrees_with_the_readme_exit_table` | the flip's eight documented cases, their numbers, and that every one of its sixteen cells is an instance of one |
| `DbFlipPgcatCommandTest::test_status_wins_when_a_rehearsal_is_asked_for_at_the_same_time` | the one rule the flip's table names that a row cannot hold |

All three are checked by mutation rather than by argument: reverting `every replica failed` to
`0`, reverting `warnings, no failures` under `--strict` to `0`, reverting the flip's `a step a
flip needs did not work` to `0`, inserting an extra documented row, rewording a documented row,
and deleting one cell's assignment each produce exactly one failure naming the case.

## Known limitations

- **A reworded documented case is a failing test.** That is deliberate — the label is how the
  case is identified — but it means polishing the README's exit tables is a two-file change.
  The comparison is insensitive to backticks, spacing and case, so a reflow is not.
- **The cell counts in this record are prose, and nothing checks them.** The guard counts cells
  because it iterates the provider; this document counts them because somebody last edited it.
  The flip's count sat at "fifteen" from the day the JSON work added a sixteenth cell, and the
  sentence beside it still said *two* ways a command can be refused when there were three. Both
  files were correct about everything the suite asserts, which is what makes that drift quiet:
  the numbers are a restatement, and a restatement is not a guard.
- **Two documented behaviours of `db:pgcat-flip` are still prose.** A disabled flipper
  returning before the `--watch` loop, rather than entering it, is a claim about control flow in
  a daemon: the only way to run it is to run the loop, and a matrix row that regressed would
  hang the suite rather than fail it. It stays in the README's flag table, unpinned. The other
  is the exit code the command would use for a flag combination nobody has written yet.
- **Only tables that state a code are bound.** The README's other tables (what each check
  judges, what a rehearsal does not do) carry no exit code and are not read.
- **The parser is a parser.** It reads the first table under a heading with a named column, so
  restructuring the README so that a different table has that column first would bind the
  wrong one — which fails loudly (the labels would not match) rather than silently.
- **No behaviour is asserted twice.** The guard cannot tell that a documented code is *right*
  for the command; it can only tell that the code and the documentation agree. Correctness
  stays with the matrix.

## What would change this decision

- **If the table were generated.** A README section built from the providers would make the
  guard unnecessary, at the cost of moving operator-facing wording into the test files — the
  reason candidate E was rejected, not a reason it is wrong.
- **If a documentation linter existed.** A CI step that checked the whole README against the
  code would cover the flip's prose too, and would be the better home for this. The package
  has no such check, and adding a general one to cover one table would be the tail wagging
  the dog.
- **If the `--watch` claim had to be pinned.** A bounded child process — run the command with
  `--watch` against a disabled installation and assert it exits within a second — would pin it
  without the hang, at the cost of the test suite having to know how to boot the application
  outside itself. The package's tests drive the console kernel in-process today.
- **If the README stopped being the operator's first stop.** The `--help` text of each command
  is the other place a code could be documented; if that became the contract, the guard would
  read the command's own help instead of the README.

## Files

| File | Role |
|---|---|
| `tests/Support/Readme.php` | the tables read as data: `table()`, `row()`, `code()`, `plain()` |
| `tests/Unit/Console/DbProbeReplicasCommandTest.php` | the sweep's matrix, its provider, and the map from each cell to its documented case |
| `tests/Unit/Console/DbDoctorTest.php` | the doctor's matrix, its provider, and the case each cell's counts belong to |
| `tests/Unit/Console/DbFlipPgcatCommandTest.php` | the flip's matrix, its provider, and the map from each cell to its documented case |
| `README.md` | the three tables, and the sentences naming the test that reads each one |

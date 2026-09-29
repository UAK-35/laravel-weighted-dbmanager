# A number in a sentence is a restatement, and a restatement is not a guard

## The answer in one line

Every number the package's records state about its code is either derived from the code or
compared with it — and a record can go one step further: the counts in
[docs/documented-exit-codes.md](documented-exit-codes.md) are *rendered* from their derivations by
`bin/counts.php`, so nobody writes them at all. The rest are checked by `ProseNumbersTest` (and
three tests in `DbDoctorTest` where the claim needs the command to have run); the ones that cannot
be are named below with the reason, so the boundary is written down rather than implied.

---

## Why this needed deciding at all

The package's records are prose, deliberately: an operator reads a sentence, and a sentence is
where the *reason* for a rule survives. That style has one failure mode, and it is not a typo.

A number in a sentence is a second copy of a fact the code already holds. When the code grows a
member, the sentence is still there, still grammatical, still true as far as anything asserts —
and nothing fails. The reader is the one who finds out, at the moment they are relying on it.

That is not hypothetical here. `docs/documented-exit-codes.md` carries the record of it: the
flip's matrix sat at "fifteen" from the day the JSON work added a sixteenth cell, and a sentence
beside it still said *two* ways a command can be refused when there were three. Two records named
tests that no class declares any more. Three records called a count "prose, and nothing checks
them". Every one of those files was consistent with everything the suite asserted, which is
exactly what makes the drift quiet: the suite asserted the *behaviour*, and nobody asserted the
*sentence*.

The obvious repair — delete the numbers, keep the code — was rejected. The sentence is the point:
"the six methods whose lists are spread into one boot's findings" is how a reader learns the shape
of the surface, and a docblock full of `count(...)` is not a document. So the numbers stay, and
each one gains a reader.

## The rule

**A number a record states about this package's code is either derived from the code or compared
with it.** A record may still say the number; the sentence is a claim something checks. A member
added without the sentence being updated fails the suite instead of shipping.

Three properties make the guard worth having:

1. **The words are the subject.** Each claim is pinned to the sentence it is written in, and the
   pattern must match at least once — and *every* place it matches must state the same number, so
   a record saying "three" in one paragraph and "four" in another fails on the second. A reworded
   sentence therefore fails rather than quietly passing: a guard that has stopped finding its
   subject is worse than no guard, because it reports agreement with a claim it never read.
   Rewording a bound sentence is a two-file change, and that is the price of the number being
   checked at all.
2. **The spelling is the record's, the comparison is numeric.** The records write numbers as words
   ("sixteen cells"), and the capture is mapped through one word table — so a record that started
   writing `16` fails here rather than being skipped, and an unknown word is a failure rather than
   a skip.
3. **The expected value is computed, never written.** Every claim in `ProseNumbersTest` derives its
   number from a constant, a list, a class's own data provider, or the source file the thing is
   written in. A guard whose expectation is a literal is the restatement it is supposed to catch,
   so the test file holds no number of its own.

Values that live in the *source* rather than in a constant — how many finding lists a boot spreads,
how many `logContext()` call sites there are, how many `usable: false` verdicts the supervisor step
builds — are counted out of the file they are written in. That is a check rather than a derivation,
and it is the honest one: the taxonomy *is* those branches, so the alternative is a constant
somebody has to keep in step with them, which is the restatement this guard exists to catch.

## Rendered, or checked

Two ways to keep a number in step, and the choice between them is a choice about how much prose a
program should own:

- **Rendered.** The derivation lives in `tests/Support/DocumentedExitCounts`, and `bin/counts.php`
  writes the number into the record. `php bin/counts.php` renders; `php bin/counts.php --check`
  compares and writes nothing; `bin/checks.php` runs the check, so drift fails the gate. This is
  where the counts `docs/documented-exit-codes.md` states live, because they are the ones that had
  already drifted.

  "Rendered" is about the number, not about the file: two of these counts are written in two files
  — the record, and the docblock of the map in `DbFlipPgcatCommandTest` that the sentence is about
  — and both are rendered from one derivation. The docblock is the copy that had drifted, which is
  the argument for rendering wherever a number is written rather than only in the record.
- **Checked.** The derivation lives in `ProseNumbersTest`, and the record is asserted to agree with
  it. The suite fails when it does not, and the repair is an edit to the record. Everything else in
  the table below.

Both read one table — `ProseNumbersTest::proseNumbers()` is the checked claims merged with the
rendered ones — because the renderer and the guard are the same rule read in two directions: the
guard says what the record *must* say, the renderer writes it. A claim that moves from checked to
rendered moves between two files rather than acquiring a second copy of its derivation.

The renderer is only as safe as its patterns, and that is the property the guard makes true: it
asserts that *every* place a pattern matches states the derived number, so a pattern that also
matched an unrelated sentence — a historical "fifteen" in the same record — would already be a
failing test. What the renderer rewrites is therefore exactly what the suite has proven to be a
claim about the code.

A rendered number is still a number in a sentence, so the words stay words: the vocabulary is
`tests/Support/NumberWords`, and the renderer writes "nine", never "9".

## The claims that are bound

The first column is the claim's name in `ProseNumbersTest`, verbatim, so the table and the guard
can be compared rather than trusted: `test_every_claim_the_guard_makes_is_written_down_in_the_record`
asserts that these are exactly the claims the guard makes, and that every bolded number beside a
claim is the number the guard derives for it. That is what keeps this record from *becoming* the
thing it describes — a prose copy of a list that somebody has to remember to update.

| Claim | The sentence, as the record writes it | What it counts, and what the number is derived from |
|---|---|---|
| the commands the provider registers, as README.md counts them | "All **four** are registered by the provider" | the command classes in `src/Console/Commands/` |
| the shapes the log-severity decision was weighed against, as README.md counts them | "the **seven** shapes it was weighed against" | the candidate headings of `boot-audit-log-severity.md`, less the chosen one |
| the audit's finding keys, as boot-audit-surfaces.md counts them | "pgcat is one of **twenty-two** keys", "would put **twenty-two** keys' worth …" | the provider's `KEY_*` constants |
| the finding lists one boot spreads, as boot-audit-finding-keys.md counts them | "the **eight** methods whose lists are spread into one boot's findings" | the `...$this->` spreads in `reportBootAudit()` |
| the assembly methods the provider spreads today, as boot-audit-finding-keys.md counts them | "it spreads **eight** today" | the `...$this->` spreads in `reportBootAudit()` |
| the findings in the richest boot, as boot-audit-finding-keys.md counts them | "**eight** findings, **eight** keys, the exact set" | `WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS` |
| the keys in the richest boot, as boot-audit-finding-keys.md counts them | "**eight** findings, **eight** keys, the exact set" | `WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS` |
| the replica settings the audit refuses, as boot-audit-finding-keys.md counts them | "all **three** replica settings unreadable" | the provider's `REPLICA_METADATA` table |
| the boot lines that carry a severity, as boot-audit-log-severity.md counts them | "`logContext()`, the **five** call sites" | the `self::logContext(` calls in `BootAudit.php` |
| the commands whose exit code this record is about, as it counts them | "**Four** commands in this package are scheduled", "All **four** are checked by mutation rather than by argument", "the **four** tables, and the sentences naming the test that reads each one" | the matrices `DocumentedExitCounts` names — which commands have one is a decision, and the record counts the list |
| the doctor's exit matrix, as documented-exit-codes.md counts its cells | "a **fourteen**-cell matrix" | `DbDoctorTest::exitCodeProvider()` |
| the probe's exit matrix, as documented-exit-codes.md counts its cells | "a **nine**-cell matrix" | `DbProbeReplicasCommandTest::exitCodeProvider()` |
| the flip's exit matrix, as documented-exit-codes.md counts its cells | "a **seventeen**-cell matrix" | `DbFlipPgcatCommandTest::exitCodeProvider()` |
| the replica-status exit matrix, as documented-exit-codes.md counts its cells | "its **six**-cell matrix" | `DbReplicaStatusTest::exitCodeProvider()` |
| the routes the replica-status table documents, as documented-exit-codes.md counts them | "the **three** routes its table documents" | the distinct cases `DbReplicaStatusTest::documentedSituations()` names |
| the probe's documented cases, as documented-exit-codes.md counts them | "the table has **five** documented cases", "the sweep's **five** documented cases", "the probe's **five** rows document `0`" | the distinct cases `DbProbeReplicasCommandTest::documentedSituations()` names |
| the probe's rows that document a zero, as documented-exit-codes.md counts them | "**two** of the probe's five rows document `0`" | the README's `db:probe-replicas` exit table, rows whose code is 0 |
| the probe's rows that document a one, as documented-exit-codes.md counts them | "the probe's five rows document `0` and **three** document `1`" | the README's `db:probe-replicas` exit table, rows whose code is 1 |
| the flip's documented cases, as documented-exit-codes.md counts them | "The flip's table is **nine** documented cases", "the flip's **nine** documented cases" | the distinct cases `DbFlipPgcatCommandTest::documentedSituations()` names |
| the ways a flip can politely do nothing, as documented-exit-codes.md counts them | "the **three** ways a flip can politely do nothing are one case" | the cells `DbFlipPgcatCommandTest::documentedSituations()` assigns to the README row `a flip applied, nothing to do, or skipped by another instance` — the row is wider than the phrase sounds, and which row the phrase names is declared in `DocumentedExitCounts::FLIP_WAYS` |
| the ways a command can be refused, as documented-exit-codes.md counts them | "the **three** ways a command can be refused are another" | the cells assigned to the row `a flag combination that is refused` |
| the ways a flip can politely do nothing, as DbFlipPgcatCommandTest counts them | "the **three** ways a flip can politely do nothing are one documented case" | the same cells, stated in the map's own docblock — the copy that had drifted to "two" |
| the ways a command can be refused, as DbFlipPgcatCommandTest counts them | "the **three** ways a command can be refused are another" | the same cells, stated in the map's own docblock |
| the doctor's documented cases, as documented-exit-codes.md counts them | "the doctor's **three** documented cases" | the README's `db:doctor` exit table, which the doctor's matrix is written as |
| the checks the doctor judges, as documented-exit-codes.md counts the README table | "the **eleven**-row description of what each check judges" | the README's `db:doctor` check table |
| the rows a doctor asks, as db-doctor-json.md counts them in its opening claim | "A doctor has **eleven** verdicts, one per row" | the README's `db:doctor` check table |
| the rows a doctor asks on a run that resolves, as db-doctor-json.md counts them in its limit | "resolve, **eleven** otherwise" | the README's `db:doctor` check table |
| the kinds a flip run can reach, as db-doctor-json.md counts them | "one of **eleven** values a run can reach" | the README's `kind` table |
| the reasons a replica is excluded, as pool-exclusions.md counts them | "the reason vocabulary is closed at **three**" | `WeightResolver::EXCLUSION_REASONS` |
| the supervisor faults a flip refuses on, as pgcat-supervisor-preflight.md counts them | "catches all **six** faults this record lists" | the `usable: false` verdicts in `SupervisorStep::inspect()`, with the declared `FAULT_*` constants asserted to be exactly the faults it builds |
| the keys the envelope writes, as command-json-envelope.md counts them | "writes **five** keys, in the same order, for every report", "holds the **five** keys in the order", "one of the **five** core keys" | `JsonEnvelope::CORE` |
| the metadata floors, as replica-metadata-refusal.md counts them | "the **three** `*_FLOOR` constants" | the `*_FLOOR` constants on `ReplicaMetadata` |
| the classifier's cases, as replica-metadata-refusal.md counts them | "`ReplicaMetadataTest` (**nine** cases)" | the test methods `ReplicaMetadataTest` declares |
| the refusal keys for the switches, as switch-values.md counts them | "**Three** keys, not one `switches.refused`" | the provider's `SWITCHES` table |

The claims that need the command to have run are bound in `DbDoctorTest`, where the fixtures are,
and each one is pinned to the sentence with the same mechanism: the number is read out of the
record and compared with what the run produced.

| Claim | The sentence | What it counts |
|---|---|---|
| `/(\w+) things can be wrong/` in `README.md` | "**Eleven** things can be wrong" | the rows the command builds, against the README's own table |
| `/That is (\w+) on an installation where \`db\` resolves/` in `docs/db-doctor-json.md` | "That is **eleven** on an installation where …" | the rows the weighted run built |
| `/and \*\*(\w+)\*\* where it does not/` in `docs/db-doctor-json.md` | "… and **six** where it does not" | the rows a run built with `db` replaced |
| `/(\w+) configuration rows/` in `README.md` | "leaves the **six** configuration rows" | the rows a run built with `db` replaced |
| `/(\w+) rows can offer one/` in `docs/db-doctor-json.md` | "**Three** rows can offer one" | the rows an actual run marks with a non-empty `suggestions` |

Both halves select by name rather than by count, and both compare *lists* before they compare
lengths, because a count can be right while the list is wrong — and it is the names a reader or a
gate selects on.

## The same defect with a name instead of a number

A record that names the test pinning a rule is making the same kind of claim as one that states a
count: a second copy of a fact the code holds, and one nobody was reading. Twenty-two of those names
had rotted — a test renamed when the rule it pins grew a case, a class credited for a test that had
moved — and every citation sent the reader to an empty search. The repair for the numbers is the
repair for the names: `DocCitationsTest` reads the records as data, and every `test_…` a record
cites is checked against the classes under `tests/` that declare it — and, where the record names
the class, against *that* class, because a name that resolves to the wrong class is worse than one
that does not resolve: the reader finds a test, runs it, and learns about a different rule.

The two guards are one idea, applied to the two things a sentence restates. A count is compared with
the list it counts; a name is compared with the class that declares it. Both are pinned to the
sentence they were written in, both fail loudly when the sentence is reworded, and both read the
records rather than trusting them.

## What is deliberately not bound, and why

- **Numbers about a shape rather than a list.** "One accessor, two renderings", "the two halves",
  "three surfaces", "the two names are two readers, not two opinions" — these describe a design.
  There is no list in the code to compare with, and inventing one would be writing the answer down
  twice for a second reader to keep in step.
- **A relationship between two records.** The README's `--json` section names the candidates the
  doctor's decision shares with the flip's record, and the ones that are the doctor's own. Both
  records letter their candidates independently, so the overlap is a judgement about which ideas
  recur rather than a count of headings — and the lists it is a judgement about are in the two
  records, where the reader can see them.
- **Numbers about the test suite.** "Six of the doctor's supervisor rows read the same key",
  "seven shapes", "2 failures" — about fixtures, mutations and files under `tests/`. They change
  with the suite, and the sentence that names them is read by whoever is reading the case.
- **A partition rather than a total.** `pgcat-supervisor-preflight.md` says "three of the four
  faults are repaired at the thing the command *is*… the fourth is repaired at a name" and, later,
  "the other four faults get no line". The totals are bound (six refused faults, six listed); which
  fault "reduces to a command" is a judgement `suggestionForSupervisor()` makes, and a sentence
  that restates that judgement is prose about a rule rather than a count of a list.
- **Numbers about a historical state.** "the **four** assembly methods it had then happened not to
  produce the same key twice" is a sentence about a version of the provider that no longer exists.
  Only the present-tense half — "it spreads six today" — is bound. The same rule is why
  `documented-exit-codes.md`'s known limitations can say "still said *two* ways a command can be
  refused when there were three": that sentence describes the state before the guard, and the
  present-tense sentence it describes is the one that is bound. It is also why the two claims above
  anchor on the words *after* the number ("are another"), since the historical sentence states the
  same phrase a few lines further down.
- **A count of a list the sentence itself writes.** "The four failure modes" names its four in the
  same paragraph, and so does "two documented behaviours ... are still prose" in
  `documented-exit-codes.md`. A generator of those numbers would be counting the sentence's own
  commas, which is not a fact about the code — the list is the claim, and the count is how the
  sentence opens.
- **How an argument is put.** A record explains a decision with numbers that count nothing in the
  code: `documented-exit-codes.md`'s "two ways to reach `1`", "three inputs into `gateFailed()`",
  "one condition", "two facts", "two directional checks", "one documented case however many
  fixtures produce it"; `command-json-envelope.md`'s "two thirds of the family", "one word at two
  scopes", "two names for one word on one page". Each describes the shape of a rule or of a
  comparison — how many conditions go into one decision, how many directions a check runs in — and
  there is no list in the code behind the number; the list, where there is one, is the sentence's
  own. What *was* a list — cells, documented cases, cells per documented case, README rows, rows per
  code, the envelope's keys — is in the table above, all of it rendered from the provider, the table
  or the constant it counts.
- **Numbers about a budget rather than a structure.** "one PING every …", "every 30 s" in the
  schedule example, "eight FPM workers" in a sizing example, "three weeks" in an incident story.
  These are about a sized installation, not about this package's members.
- **Numbers about an external system.** The supervisor's own fault strings, pgcat's own
  vocabulary, the platform's separators: restating them is the package's contract with something
  it does not own, and the claim that matters is that the string is passed through unchanged.

If a number in the first list later gains a list behind it, it belongs in the bound table above.
That move is the repair; leaving it in this list is a decision, and this list is where it is
written down.

## Tests that pin the rules

| Test | Pins |
|---|---|
| `ProseNumbersTest::test_the_number_a_record_states_is_the_number_the_code_has` | every claim in the bound table: the sentence still exists, and every statement of it is the number the code has |
| `ProseNumbersTest::test_the_three_switch_tables_list_the_same_settings` | the three tables a switch lives in — the provider's `SWITCHES`, the row's `SWITCH_KEYS`, its `SWITCH_CONSEQUENCES` — name the same settings |
| `DbDoctorTest::test_the_readme_check_table_is_the_rows_the_command_builds` | the README check table by row name, the two counts stated about it, and that the unweighted rows are a subset |
| `DbDoctorTest::test_the_record_says_which_rows_can_carry_a_repair` | the rows an actual run marks with a `suggestion`, against the record's count and its named classes |
| `DocCitationsTest::test_every_test_a_record_cites_is_one_this_package_declares` | every `test_…` the records cite, and the class each one credits |
| `bin/counts.php --check`, run as a check by `bin/checks.php` | the rendered half: every count in `docs/documented-exit-codes.md` is the number its derivation has |

Checked by mutation as well as by argument: adding a `KEY_*` constant without the sentence, dropping
a `...$this` spread from `reportBootAudit()`, renaming a sentence the guard is pinned to, and adding
a switch to one table only each fail with a message naming the record and the sentence.

## Files

| File | What it holds |
|---|---|
| `tests/Unit/Docs/ProseNumbersTest.php` | the claim table, the number words, the derivations, and the two records-independent rules |
| `tests/Unit/Console/DbDoctorTest.php` | the two claims that need the command to run, and `numberClaim()` |
| `tests/Support/Readme.php` | the README read as data — the tables the claims count |
| `tests/Unit/Docs/DocCitationsTest.php` | the name half: the records' test citations against the classes that declare them |
| `tests/Support/DocumentedExitCounts.php` | the rendered counts: what `docs/documented-exit-codes.md` states, and the derivation of each |
| `tests/Support/NumberWords.php` | the vocabulary, in both directions: the word a record has, and the word to write |
| `bin/counts.php` | the renderer: writes the records from the derivations, or checks them without writing |
| `bin/checks.php` | the gate: runs `bin/counts.php --check`, so an unrendered record fails before a release |
| the records in the bound table | the sentences themselves; each keeps its wording, because the guard is pinned to it |

## Known limitations

- **A reworded sentence is a failing test.** Deliberate — the pattern is the claim's identity — but
  it means polishing a bound sentence is a two-file change. The failure message quotes the pattern
  that stopped matching, so the repair is mechanical.
- **A pattern can be made to match nothing meaningful.** Pinning `/(\w+) rows/` to a record would
  satisfy the guard and check nothing, because the sentence would be about a different list. The
  defence is the one used here: each pattern carries the noun the number counts (`documented
  cases`, `keys`, `faults`), and a claim whose pattern would match an unrelated sentence is written
  narrower rather than wider. The one place that mattered is the flip's refused count, which a
  record states twice — once as a claim, once as the history of the claim drifting — so the pattern
  carries the words that follow the number, and the historical sentence is excluded by wording
  rather than by being a second kind of thing.
- **The two claims in `DbDoctorTest` count from fixtures, not from every state a row can reach.**
  The union of rows carrying a suggestion is taken from three states that produce one, so a row that
  could offer a line under a state no test walks would not be counted. That is the same fixture
  limit the row tests have, and the record's claim is about the rows the command *can* mark.
- **A count can agree while the thing counted is wrong.** This guard compares a sentence with the
  code's list; it cannot tell that the list itself is right. Correctness stays with the tests named
  in each record.
- **The sources counted out of a file are counted by pattern.** `spreadCount()` looks for
  `...$this->` inside `reportBootAudit()`, and the call-site count looks for `self::logContext(`. A
  refactor that changed the idiom would fail this guard loudly rather than silently undercount,
  which is the failure direction that matters.

## What would change this decision

- **A completeness check, rather than a boundary.** The guard asserts that every number a claim
  *names* is the number the code has; it cannot tell that a number in a record is named by no claim
  at all, which is how the two bold headings and the flip's "ways" counts stayed prose while the
  sentences under them were rendered. That is findable — every number word in a record, checked
  against the claims' captures — but it needs the other half: the number words that are prose, named
  one by one, because a check that fails on "one input" is a check somebody switches off. The
  bullets above are that list for the records with the most prose; turning them into a table a guard
  reads would make the boundary enforced rather than described. When the audit is written, the
  headings and the "ways" counts are what it will find first — they were found by hand here.
- **A generator, rather than a guard.** If the records were assembled from the code — the claims
  rendered rather than checked — the sentences could not drift because nobody would write them. It
  is more machinery than this package has, and it would cost the prose its reason: the interesting
  half of "the six methods whose lists are spread into one boot's findings" is not the six.
- **A record that states a number this guard cannot derive.** The honest move is to add the
  derivation to the code — a constant, a data provider, a list — because a sentence worth writing
  down is worth a reader. If that is not worth it, the number belongs in this record's second list,
  with the reason.

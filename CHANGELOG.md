# Release Notes

## Unreleased

## 0.2.0-alpha2 - 2026-10-06

BREAKING: parts of this release change what an installation reads at run time rather than only how
the package is built, and each is named below.

`/health/db` answers `degraded`, HTTP 503, in a state it used to answer `ok` to — a pooler left on
the writer-only config inside an open reader window — so a monitor, a load-balancer health check or a
deploy gate pointed at that endpoint changes its answer for the same installation. The boot audit's
record line is triaged by `kept` and `keys_this_boot_read` rather than by the `discarded` and
`keys_on_disk` an alert rule written against the old line selects on.

Neither is a renamed class, a renamed config key or a removed method — `--weigh`'s public-API and
config signals are a minor — and the rest of this section is either additive or tooling this package
does not ship: `bin/`, `tests/`, `RELEASING.md`, `CHANGELOG.md` and the `*.tsv` records are all
`export-ignore`d, so a change there cannot reach an installation.

### Added

- **Nothing reported whether anything would move the pooler at a reader-window boundary, and now a
  preflight does.** The per-minute entry bounds *converging at boot* — on purpose, so a container
  that could not come up looks broken rather than busy — and it is a no-op for the rest of its life.
  A window opens at 10:00, the resolver moves to `readers`, nothing moves pgcat with it, and every
  surface reads well: `switch values` covers pgcat's three switches, `reader windows` judges the
  setting the resolver reads, and `pgcat files` and `pgcat supervisor` judge a flip that runs. None
  of them was asked whether one is *scheduled*, and the two settings that decide it —
  `swrr.pgcat.schedule.enabled` and `swrr.pgcat.schedule.windows.enabled` — were the pair nothing
  reported at all: a value that is neither on nor off was logged once at registration and read by
  no preflight.

  `db:doctor`'s `flip schedule` row is that reading, and both halves come from the class that owns
  them (`FlipSchedule::options()`, `WindowFlipSchedule::settings()`), so the row cannot disagree
  with the entry that runs and the tasks that register. It **fails** on either switch written as
  something that is not on or off, naming the value, the accepted spellings and the value the
  setting falls back to. It *warns* when the reader-window tasks are on and no boundary is scheduled
  — every window unusable, or shorter than the lead and the grace — and when the flipper is armed
  with neither mechanism on, which leaves pgcat's file where the last write put it while the
  resolver's mode moves on. On a run that reads cleanly it names which mechanism answers a boundary,
  and both on is reported as what it is rather than as a fault. `DbDoctorTest` pins the states and
  both refusals.

- **The tools this package installs are written down once, and three readers are held to the one
  copy.** The vendor paths existed twice and nothing compared them: `composer.json`'s scripts named
  the tools bare, for Composer to resolve against `vendor/bin`, and `bin/checks.php` held the same
  four as `$root . '/vendor/…'` strings. A package that moved its binary, or a tool that started
  arriving from another package, would then leave `composer lint` and `composer checks` running two
  builds of one tool — findable only by reading both files and noticing.

  `bin/tools.php` is the manifest: the composer package each tool ships in, the constraint
  `composer.json`'s `require-dev` asks that package to be at, the entry file PHP runs, and one
  sentence on what the tool is for. `bin/tool.php` runs the composer scripts from it, so `composer
  lint`, `composer test:lint`, `composer test:types` and `composer test:unit` name a tool rather
  than a path. `bin/checks.php` reads the same records, both for the paths its own commands run and
  for a new `tools` check that reports each installation against the pin its manifest states — the
  half a test cannot see, because what is installed is the machine's rather than the tree's.
  `ToolTableTest` holds the manifest to the README's tools table and to `require-dev`, cell by cell
  and in both directions for the pins, so a constraint raised in one file and left alone in the
  other stops the suite instead of quietly running the older tool.

- **A pooler out of step with the reader windows is now a failure, not a silent one — inside a
  window and outside one.** The boot window answers whether a container came up; it cannot answer whether the
  pooler is still tracking the day. A container booted outside a reader window converges to
  writer-only and looks perfect — one run, `converged: true`, `window.failed: false` — and then
  10:00 arrives, the windows move the resolver to `readers`, and nothing moves pgcat with it. Every
  read reaches the writer while `/health/db` answers `ok`, because a pooler that answers is not a
  pooler that is right. The mirror image is the same failure: with the readers config left in the
  file outside a window, every read through the pooler reaches a replica during the hours the
  fallback exists to keep them on the writer — and it is the same evidence, that a boundary flip
  stopped landing.

  **What this asks of an installation, and why this release says it is breaking:** a monitor, a
  load-balancer health check or a deploy gate pointed at `/health/db` now fails where it used to
  pass, for the disagreements below. Whether that endpoint should gate a deployment is a decision to
  make rather than one the package can make for you.

  `PgcatConfigFlipper::readerWindowVerdict()` is the second answer, carried in the flipper's own
  snapshot as `reader_window` and reported by `/health/db` as a top-level block that also feeds the
  status: `expected` is what the resolver says now, `applied` is what pgcat's file actually holds,
  and `failed` marks either disagreement — the file holding the variant the windows are not
  asking for, whichever way round it is. `applied` is read off pgcat's file (`appliedMode()`) rather than off
  the state file's `last_mode`, deliberately: a flip that aborted before it recorded anything still
  left a file behind, and `last_mode` is exactly the field that is empty in that case — the shape
  the 2026-10-02 container arrived in, where a per-minute `schedule:run` was aborting on a missing
  cache lock before it ever reached the flipper, and a `last_mode`-based check would have answered
  "unknown" while the pooler was provably writer-only.  The verdict is symmetric on purpose. The file and the windows are two statements about one
  routing decision, and a stale one is stale whichever way round the disagreement is: readers left
  in the file *outside* a window mean the mode the windows asked for at the end of the last window
  is the one that never arrived, and the file is the evidence of that. A file matching neither
  variant (an operator's edit, a half-written copy) is `in_step: null` rather than an invented
  verdict, and the check is not judged at all on an inert flipper or a permissive resolver, where
  `applicable` says so.

- **The reader windows are scheduled tasks, and a mode change waits until the thing it points at
  answers.** The per-minute flip converges the pool at container start and then stops at the end of
  the boot window, deliberately — that is what makes a container that could not come up look broken
  rather than busy. It is also why nothing moves the pool at 10:00: a container booted at 03:00 has
  converged to writer-only and, by the time the first window opens, is no longer allowed to change
  its mind.

  `Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule` is the other half. The provider registers two
  tasks per window in `swrr.reader_windows` — `activate_readers:{start}` and
  `deactivate_readers:{end}` — the first time the container's `Schedule` is resolved, each firing
  once a minute from `lead_seconds` before its boundary to `grace_seconds` after it. Every firing
  runs the new `db:pgcat-window-flip`, which resolves the boundary nearest the run, asks the mode's
  target with one `SELECT 1` on a throwaway connection
  (`Uak35\WeightedDbManager\Database\Weighted\ReadinessProbe`, built on the `SingleHostProbe` that
  `db:probe-replicas` now shares rather than keeping a copy of), and applies the mode through the
  flipper's forced path — no boot window, and no recorded run, so a mode applied at 10:00 cannot
  move the convergence verdict `/health/db` reports. A target that is not answering yet is asked
  again on the following minutes rather than given up for the day, and one that never answers
  leaves the pool where it was and exits `1`, which is the one outcome a scheduler has to see.

  `db-manager.swrr.pgcat.schedule.windows` is off by default, and an installation that turns it on
  should turn `db-manager.swrr.pgcat.schedule.enabled` off in the same change: two mechanisms
  deciding one mode can disagree. The README documents the settings, the exit code of every route
  and the kind table, which `DbWindowFlipCommandTest` reads back — and it is the matrix that
  earned the command its row in `docs/documented-exit-codes.md`, because a task that runs eight
  times per boundary must not put eight error lines in a log for doing its job.

  Each event also carries a `when()` filter built from its own window, evaluated by `schedule:run`
  before the command is spawned. A cron minute field cannot hold "52 to 8 of the next hour", so a
  range that crosses one is written as the union of the minutes on both sides and its expression
  matches twenty-five firings a day where the boundary is for seventeen — minute 52 of hour 9 is
  also matched by minute 0 of hour 9, because cron multiplies the two fields. The filter is what
  narrows it back: a surplus minute costs a closure call and nothing else — no process, no probe,
  no log line — where before it was a whole process whose only job was to report itself early or
  expired. `WindowFlipScheduleTest` pins the filter's arithmetic (including a range that wraps past
  midnight) and its per-event wiring, and the expression's own wider reach is still pinned beside
  it.

- **The flip schedules itself, with a cadence the installation chooses.** `db:pgcat-flip` was the
  package's command and the application's entry to write: the provider now registers it, the first
  time the container's `Schedule` is resolved, so there is no entry to write into
  `routes/console.php` and none to forget to write. `Uak35\WeightedDbManager\Pgcat\FlipSchedule`
  holds the entry and `swrr.pgcat.schedule` holds the settings (`enabled`, `interval_minutes`,
  `name`, `log`), and two Laravel conveniences are deliberately absent because the flipper is
  container-local: `onOneServer()` would let one container flip per minute and skip every other,
  and the mutex `withoutOverlapping()` names by itself is `sha1(expression + command)` — the same
  name in every container when the cache store is shared. The mutex is scoped to the container
  instead, and the authoritative serialisation stays the flipper's own `flock`.

  `interval_minutes` is a trade rather than a saving, and the record says so: what bounds the
  attempts is `flip_window_seconds`, not the cadence — eight minutes holds eight runs at one
  minute and two at five. A value the minute field cannot hold (zero, a negative, a non-number, or
  more than `59`, past which a step *wraps* rather than meaning what it says) resolves to the
  documented default rather than being written into an expression that means something else.
  `FlipScheduleTest` pins the cadence, the switch, the refusal, the mutex name and the duplicate
  guard, and the wrap itself is asserted through the cron library's own `nextRunDate()` rather than
  taken on trust.

- **The inventory publishes a public API page, so the rows a release weighs are also the page a
  consumer reads.** `API.md` is rendered from `files.tsv`, `methods.tsv` and `surface.tsv` by
  `php bin/api.php`: every class with its public methods and their argument shapes, the config keys
  and environment variables the package reads, and the constants and properties a host can name —
  grouped by namespace, and by the file that declares it for the configuration. Nothing asks the
  tree a second time, so the page cannot describe a different package from the one the release
  weighed; a run refuses when the three files do not describe one tree, and when there is no record
  to render rather than publishing an empty API. `--check` is the read-only half and the command a
  pipeline can call.

  `PublicApiReportTest` runs the generator backwards: the bytes on disk are compared with the bytes
  the record produces, every class and every method row has to have reached the page, and the counts
  the page prints have to be the record's own — so a row that stopped being rendered fails a test
  rather than living on a page until somebody notices. The README's layout lists the page and the
  command, and `docs/inventory-surface-rows.md` records the decision, which is the candidate that
  record had been holding open.

- **The audit's live half has alert rules, and both halves are run rather than described.**
  `audit.severity` is the record — what the installation's boots reported — and `audit.current` is the
  same settings read in the process answering the request, which the payload has carried since the two
  readings were published. What was missing was the rule an operator writes against the second one:
  `severity == "error"` pages an instance for an entry another boot wrote, and the two cases it has to
  tell apart — a finding this process re-derived, and one recorded in another scope — were only
  visible to somebody who walked `findings[].current` themselves.

  The README's cookbook now has the live half as its own table and two paste-able rules: the `jq`
  gate that pages only on `audit.current.severity`, so a record-only entry stays a ticket, and a
  `jq -r` triage line that prints the scope each non-standing finding was written in, which is what an
  operator needs before anything is done about it. `docs/boot-audit-surfaces.md` records why the
  record's `severity` and the live half's are two fields rather than one.

  Both rules are run rather than read. `ReadmeAlertingTest` builds two real payloads — one whose
  record holds a foreign-scope reader-window refusal while the live half is clean, one whose record
  holds a fallback refusal this process re-derives — and asserts the gate stays quiet on the first and
  pages on the second, so a rule that regressed to the record alone fails here instead of paging the
  wrong host. The new case needs the audit singleton rebuilt per payload, which is why the fixture now
  forgets it.

- **The gate reads the records as prose, and fails when a sentence boundary has lost its space.**
  `…written by the release.Diffing it is…` is a word the language does not have, and it is what an
  edit that eats a space leaves at the one place a reader cannot recover it from: nothing else in
  the  gate has an opinion about a paragraph — `php -l` reads code, the suite asserts behaviour, and the
  records' whole prose is one copy-paste away from the shape at all times. The new `sentences`
  check in `bin/checks.php` reads `README.md`, `RELEASING.md` and every
  `docs/*.md` — the same set the citations guard reads — and reports a terminator touching the
  capitalised word that begins the next sentence, with the file, the line and the join, so the
  repair is in the report rather than in a hunt.

  The width of the rule is a measurement rather than a preference. "A capital after a dot" reads
  `production.ERROR` twice in these records and `/var/run/postgresql/.s.PGSQL.5432` in the
  changelog — names, not sentences — so the capital has to be followed by a lowercase letter, and
  that is the price: a fusion whose next word is all capitals (`release.PHP`) is not reported. What
  is read is prose, so fenced blocks are dropped first — a fence holds `$_.Subject` and
  `'. '.ClassName`, which a sentence rule reports — and the lines are blanked rather than removed,
  so a finding's line number is the file's own. The CHANGELOG is not read at all: everything below
  its `## Unreleased` heading is a published record and RELEASING.md leaves every released section
  byte for byte alone, so a finding there would have no repair that is not an edit to a release.

  Twenty fixtures run before the records are, because a detector that has stopped matching reports
  an empty result — the rule the write-back register already follows: nine fusions (`.Diffing`,
  `?The`, `!Writing`, a closing bracket, a quotation, an inline code span, emphasis, a version's
  last digit, and prose after a fence) and eleven shapes it has to leave alone, the dotted config
  key, the socket path, a filename, a version, a URL and a wrapped sentence among them, plus the
  all-capitals fusion it cannot tell from a name. Four mutations are measured against it: a fused
  sentence injected into `README.md` fails the check naming the line, and un-skipping the fences,
  widening the rule to any capital, or dropping `?` and `!` each fails its own fixture by name. The
  tree is byte-identical afterwards.

- **The inventory generator can describe any git ref, so a tag with no written record can be
  backfilled.** `files.tsv`, `methods.tsv` and `surface.tsv` are the one artefact a release
  writes and the next release reads, and until now the only way to write them was from the
  working tree: the rows came off the disk and the stamp was the latest tag, a pair that is
  right only while the checkout *is* the tagged tree. That is the state a release commit is in,
  and not the state a tag whose record was never written is in — it is behind the checkout by
  however many unreleased commits, so a hand run describes the tree in front of it and names
  the tag, and so describes neither. `php bin/inventory.php --at=REF` reads its rows out of git
  — `ls-tree` and a `show` per file, the two calls the surface's tag diff makes — and stamps
  what it writes with the ref's own tag, with the release tag on the commit it names, or with
  `(no tag)` when no release names it. The rows and the stamp then come from one tree, which is
  what makes them evidence rather than a claim. A ref is any ref — a tag, a branch, a commit,
  `HEAD~3` — an uncommitted edit in the checkout is invisible to it, and `--check --at=REF` is
  the read-only half: one record compared with the tree a past tag holds, which is how a set of
  tags that predate the file is audited in a loop.

  Reading a ref is also the one case where the record already on disk can belong to a different
  release, and one slot cannot hold two, so a run is refused when a stamp on disk is not the
  release it describes — the refusal names the stamp it read and the one it would write, and
  the files carrying it. The harm there is quiet rather than loud: a substituted record is not
  an error anybody sees, it is a second opinion that stops being weighed, which is why the
  escape (`--force`) has to be asked for. A file that is not there carries no stamp to disagree
  with, so a half-written set is completed rather than refused — an interrupted release's two
  files, and the two-file inventory every tree written before `surface.tsv` existed, which is
  the state this package's own repository is in. The flags that could not change anything are
  usage errors rather than silent no-ops: an `--at=` naming no ref, and a `--force` on a run
  that writes nothing, which is what a `--check` is and what a plain run without it already
  does.

  Measured against eight mutations: the ref reader reading the checkout, the stamp coming from
  the latest tag instead of the ref, the refusal disabled, a missing file counted as a differing
  stamp, `--at` parsed and then ignored for the rows, the drift header no longer naming the tree
  the ref holds, the empty `--at=` guard removed, and the `--force` guard removed — each fails its
  own test by name, and the tree is byte-identical afterwards. [RELEASING.md](RELEASING.md#the-inventory) gains the subsection,
  [README.md](README.md) the line among the `bin/` scripts, and
  [docs/inventory-surface-rows.md](docs/inventory-surface-rows.md) the limitation that rebuilding
  a past tag's rows still leaves one record in one slot.

- **The weighing's two quiet halves are pinned by the headings and the keys they weigh, so a
  severity cannot be edited by accident.** `changelogSignal()` maps a `###` heading onto a rung —
  `Added`/`Changed`/`Deprecated` a minor, `Fixed`/`Security` a patch — and three of those six rows
  were exercised by nothing: turning `### Changed` into a patch, `### Deprecated` into a patch or
  `### Security` into a minor left the whole suite green, and the table is the policy's own statement
  of what a heading is worth, so a row nothing reads is a severity that moves by accident. The same
  was true of the two ways a release note can shout louder than its heading — a `### Breaking
  changes` heading, and a `BREAKING` marker in the body, which is the spelling a changelog may use
  in place of one — and of the surface's config half, where a dropped key was pinned at the evidence
  line and not at the severity it produces.

  The four cases drive `bin/release.php` in a real repository rather than a helper: one walks every
  heading the policy names from a `v0.4.0` tag and asserts both the bump line *and* the next version,
  one weighs the two breaking spellings, one writes a heading the policy does not know and asserts
  that it weighs a patch and says so (`the Unreleased section has no \`###\` heading the policy knows`
  beside `not a Keep a Changelog heading, so read as a patch: ### Notes`, which is also the case that
  tells an unknown vocabulary from an empty section), and one drops a config key under a `feat!:`
  commit and asserts the `breaking config 1 change(s)` row *and* the `patch CHANGELOG ### Fixed — 1
  entry` row, so the config half of the surface is pinned at its severity and not only at the
  sentence that names it. Six mutations are measured against them — `### Changed` weighed a patch,
  `### Deprecated` a patch, `### Security` a minor, the `BREAKING` marker read as a heading rather
  than as a claim, an unknown heading read as empty, and the config half's severity read as a patch —
  and each fails its own case.
- **A sentence elsewhere in the README that restates `db:pgcat-flip`'s exit table is now compared
  with the row it paraphrases, so a paraphrase cannot drift from the contract it restates.** The
  table under [Flipping](README.md#flipping-dbpgcat-flip) is the contract and it is bound to the
  matrix — but it is not the only place the README states a code: the rehearsal recipe says a
  `--dry-run` gate exits `0` when a flip would happen and `1` when a step a flip needs did not work,
  the boot-window bullet says a closed window exits `0`, and the pgcat snapshot's row for a disarmed
  flipper says it exits `0` without entering the watch loop. Each is written for the reader who is
  *in* that section — `--dry-run` is a deploy gate, so its reader is writing a pipeline — and each
  is a restatement, which means a number that changed in the table could stay wrong in three places
  nothing was reading. The failure is quiet from both ends: the sentence still reads as an argument
  about what a scheduler should do, and the table it forwards to is still right.

  `DbFlipPgcatCommandTest::paraphrases()` declares each sentence with the row it restates, and
  `test_the_prose_that_restates_the_table_states_the_same_codes` reads the section it lives in,
  matches the sentence with its whitespace collapsed, and compares the code it writes with the code
  the row documents. The number is the only part of a phrase not written literally, so a reworded
  sentence fails here rather than leaving the claim unchecked — the failure mode `ProseNumbersTest`
  exists for one record over — while a reflowed one is a reflow. The comparison is against the table
  rather than the matrix, because the table is what an operator reads and it is already bound to the
  cells: the chain reads sentence → table → matrix, and each link was measured. A wrong code in the
  table fails both guards by name (two failures); a wrong code in a sentence fails this one alone;
  and rewording a sentence fails it with the phrase printed, so a sentence that has moved is a
  change to the test as well as to the README.

- **The surface says when one name is declared by more than one file, which is the one change it
  cannot see.** The surface is keyed by name, so two files declaring one config key is a single entry
  in it, from the first of them read, and the second file's declaration is in neither map — so a change
  to that second declaration moves nothing the public-API signal can observe: the name is held by the
  file that did not change, at both ends of the diff. The plan was silent about that in exactly the way
  it is silent when nothing moved, which is the difference this closes. This package is in that state:
  `config:timezone` comes from both `config/app.php` and `config/db-manager.php`, so dropping it from
  the second file is a change no signal reports. `--weigh` now carries a note naming the names and the
  files behind each one — up to three, then a count — and pointing at the inventory, whose rows carry
  the file they came from: that is what writes both declarations down. Its verdict is a name-keyed one
  too, for the same reason the tag diff's is, and the note says so rather than promising a signal that
  cannot deliver — the file is what keeps the two apart, and finding the change in it is `git diff`
  away.

  The note belongs to the plan; `bin/blame.php`, which prints the weighing's notes verbatim, answers
  the same question for the one name it was asked about instead, in a `Shadowed names` section — one
  fact, said once per reader, in the terms each of them is answering in. The registries are kept per
  side rather than per surface: folding the tree's and the tag's into one would report every symbol in
  the package as declared twice, by the same file both times.

  A second fold of the same shape did show up under the audit: `surfaceRowDiff()` keys the inventory's
  rows by `kind:symbol` without the file, so a key two files return is one name there as well and the
  inventory's *verdict* cannot see one of the declarations going either. That one is kept — a name is
  what a consumer imports, and the file column is what makes a row a row — so it is documented rather
  than changed, and the note is the thing that says it out loud.

  Pinned by three cases in `BumpWeighingTest`
  (`test_a_key_two_files_declare_is_the_change_no_surface_signal_can_see`,
  `test_a_surface_whose_names_all_come_from_one_file_says_nothing`,
  `test_the_note_names_three_names_and_counts_the_rest`) and two in `BlameTest`
  (`test_a_name_two_files_declare_says_which_file_the_surface_holds`,
  `test_a_name_one_file_declares_is_not_shadowed`). Seven mutations are measured against it: an
  unfilled registry fails three tests, one registry for both sides fails three, recording only the last
  file fails three, reporting every symbol fails one, showing two names instead of three fails one,
  never printing the plan's note fails two, blame reporting a name one file declares fails one, and
  dropping the `nothing to say` early return fails one: the quiet case asserts the note's absence by
  the sentence's own opening line rather than by one clause of it, so a note that fired on every
  release fails something.

- **The inventory records what a consumer can name besides the files and the methods — config keys,
  env vars, public constants, enum cases and public properties — in a third file, `surface.tsv`.**
  The inventory is the one versioning signal that does not need a tag: `bin/release.php` diffs the
  public surface of `src/` and `config/` against the last tag, so a tree with no tag at all has
  nothing for that signal to see a removal in, and the weighing says so ("the notes decide"). What
  it could not see even with a tag is the *moment*: a config key removed in an unreleased commit and
  a key removed before the tag are one diff to it. A stored row is its own "before", which is why
  these are written down at all — an installation that sets `swrr.…` learns a key is gone when the
  key stops being read, and there was nothing in the package that could tell it sooner. The rows are
  `kind`, `symbol`, `file`, one per line like the other two files, and the kind is the word the
  report uses, so a removal reads as `removed public constant Fixture\Thing::VERSION`.

  A third file changes what a missing one means, so the inventory is now weighed **as a whole or not
  at all**: a file that is not there cannot say whether the rows it should hold were never written or
  were just removed, and reading it as an empty one would report every config key and constant in the
  tree as added since the last release — a minor nobody asked for. A tree whose inventory predates
  this file is told its inventory is *incomplete* and the notes and the tag diff carry the weighing,
  which is the state this repository is in until the next release writes all three.  `bin/release.php`
  writes and commits them together, stamped with the tag it creates, and `bin/inventory.php --check`
  compares and reports all three.

  Writing the rows exposed a fault in the symbol reader they go through: a declared type stands
  between the visibility and the variable it modifies (`public string $label`), and the reader walked
  back from the variable and stopped at the type name — so every **typed** property was invisible, to
  this file *and* to the public-API signal that shares the reader. Fourteen are visible in this
  package now, and a typed property in a consumer's class is caught as a removal where it used to be
  invisible to both. Pinned by three cases in `InventoryTest`
  (`test_the_surface_rows_record_the_keys_and_members_a_tag_diff_cannot`,
  `test_check_reports_a_constant_the_tree_no_longer_declares`,
  `test_check_reports_a_config_key_the_file_no_longer_returns`) and two in `BumpWeighingTest`
  (`test_a_constant_removed_with_no_tag_at_all_is_witnessed_by_the_inventory_alone`,
  `test_an_inventory_missing_one_of_its_files_is_incomplete_and_never_moves_the_bump`), and each rule
  is measured against its own regression: not reporting a removed row fails one test, reading an
  incomplete inventory as an empty one fails one, and restoring the typed-property behaviour fails
  two.

- **`php bin/blame.php SYMBOL` reports which of the four signals names a class, a method, a constant
  or a config key, and what that signal contributed to the bump.** The plan answers "what version is
  next"; this answers the question a contributor has afterwards — a `feat:` commit with no changelog
  entry, a changelog entry with no surface change, a public method that vanished — and the two cannot
  disagree about the bump, because the command calls the same `weigh()` the plan does, from the same
  tree. Each signal is reported as `caught` or `quiet` with the severity it weighed, and each half of
  the surface is asked twice: once about the lines it changed, and once about whether it holds the
  name at all. That second question is the one that stops a symbol being *unchanged since the last
  tag* from looking like a symbol nothing could find — the two are the same silence otherwise.

  Named and responsible are kept apart, which is the distinction the report is built on: a `docs:`
  commit that mentions the symbol is `caught` at a patch while the `### Added` heading above it
  weighs the minor, so the contribution paragraph says the bump came from elsewhere and lists what did
  carry it. The notes are searched as entries rather than as headings — a heading is what weighs and
  the entry is what names — and a wrapped entry is joined into one before it is matched, so a mention
  on its third line is not reported as a fragment that starts mid-word. The match is a plain
  `contains`, not a pattern: quoting, a leading `\` and a trailing `()` are stripped and nothing else
  is, so `weight` finds `weighed`, `weighting` and `weigh()` at once. There are no preconditions — no
  branch, no dirty-tree rail, no CI, because a question about one symbol is asked mid-edit — and the
  exit code is the scripted form of the answer: `0` something names it, `1` nothing does, `2` a usage
  error.

  What a weighing *is* now lives in a file of its own. `bin/weighing.php` holds `parseVersion()`,
  `bumpFor()`, `weigh()`, the four readers, and the changelog section helpers, moved out of
  `bin/release.php` byte for byte with no function name declared in both. That is what makes the two
  commands one policy rather than two copies of one: a change to how the surface is diffed or the
  notes are weighed cannot reach one and miss the other. `bin/release.php` keeps the command — the
  arguments, the preconditions, the plan and the tag — and its own behaviour is unchanged. Pinned by
  sixteen cases in `BlameTest`, from a symbol two signals name at the top severity to the four ways
  the name can be typed and the three exit codes, and each rule is measured against its own
  regression: reading any naming as responsibility fails one test, searching the notes as headings
  only fails one, deriving the diagnosis from the evidence rather than from the two surface maps
  fails two, dropping the two surfaces from the exit code fails four, taking the name exactly as
  typed fails one, and ignoring an unknown option fails one.

- **A release is refused when the public surface changed and the Unreleased notes do not account
  for it, so a change cannot ship with no release note.** Every other rail is satisfied by a
  section that is merely not empty, and the notes are the one signal a reader ever sees: a new
  public method filed under `### Fixed` weighs a patch, `--weigh` takes the bump from the surface
  signal instead, and the changelog then announces a fix while a consumer gained something to use.
  The comparison is the notes' own severity against the loudest signal that reads the surface, so
  a removal needs a `### Removed` entry and the refusal says which heading to use — the severity
  comparison rather than a search of the entries for symbol names, because a release note that has
  to spell every class it mentions is one authors route around. The refusal names the symbols, so
  the missing note can be written from it, and the surface includes the inventory, which is the
  only witness to a row the tree no longer backs on a release with a tag. `--allow-silent-notes`
  releases anyway and prints what it let through, like every other escape here; `--dry-run` reports
  the state and refuses nothing, like the dirty-tree and CI rails. On this package the rail is
  silent — `### Added` and the surface are both a minor — and it changes no other release's
  outcome. Pinned by nine cases in `SilentNotesTest`.

### Fixed

- **The notes said the per-minute flip would undo a window task's work, and it cannot.**
  `config/db-manager.php` and the README's reader-window section both told a reader that the two
  mechanisms "can disagree" and that the per-minute entry "would undo a window task's work the next
  time it fires". It would not: `applyCurrentState()` checks the boot window before it takes its
  lock, so once a container has converged every later run returns `window_closed` without reading or
  writing a file — and inside the boot window the two read the same resolver, with the flipper's own
  `flock` serialising them. What the per-minute entry has left after the boot window is a second
  process a minute and a second line in the log; what the tasks have is the mode applied through
  `forceMode()`, which records no run. The advice to keep one of them stands — one mechanism owning
  the mode is easier to reason about, and only one of them can describe a mode change as a
  convergence — and the reason it gives is now the true one.

- **A backup file an editor leaves beside a script in `bin/` is ignorable.** An editor that writes
  `checks.php.bak` beside the file it is editing leaves it under `bin/`, where nothing names it and a
  later `git add bin` picks it up with the script. The `.gitignore` now carries `/bin/*.bak` beside
  the rules for the other artifacts this repository produces. Nothing here writes such a file
  itself — the pattern is for the editor — so it changes no run of anything and only removes the
  chance of one arriving by accident.

- **`PUSHING.md` tells a reader to `cd` to where the package is.** The `git push` block named
  `LPR\side-projects\laravel-weighted-dbmanager`, a directory the package left when it moved to
  `LPR\packages\laravel-weighted-dbmanager`. The block is written to be pasted as it stands, so a
  path that is not there is a paste that fails before anything is pushed; it now names the directory
  the package is at, and no other path in the file's commands moves.

- **The package's own app skeleton names the provider that exists.** `config/app.php` replaces
  Laravel's `DatabaseServiceProvider` with this package's, and the class it named was
  `\App\Providers\WeightedDatabaseServiceProvider` — a provider of the application the package was
  built beside, which resolves nowhere here: `composer.json` autoloads one prefix,
  `Uak35\WeightedDbManager\` from `src/`, and nothing maps `App\`. The replacement now names
  `\Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider`. Nothing in this repository
  loads the file — `bin/` and `tests/` run the package through its own autoloader and never boot the
  skeleton — so no gate here reads it, which is the shape a dangling reference takes when it is an
  application config rather than a class the suite exercises.

- **The `BREAKING` marker is read where a changelog writes one, instead of wherever the word
  appears.** `changelogSignal()` asked `str_contains($unreleased, 'BREAKING')`, and the notes in
  this repository are the case that found it: the entry above this one, which documents the marker,
  quotes the word mid-sentence, so the notes that *describe* the marker marked their own Unreleased
  section breaking. On this tree that read as `minor  (weighed: a breaking change, which is a minor
  while the package is pre-1.0)` from a claim nobody had made — and the day a 1.0 line exists, the
  same sentence is the major the weighing is there to refuse, which is what makes a rule of this
  rather than a rewording.

  `breakingClaim()` is that rule. The marker weighs where a note makes a claim of it — at the start
  of a line's prose, after the bullet, the emphasis or the quote it opens with — and not where it is
  being named: a code span and a fenced block are removed with the word they show, so an entry is
  stripped of what it is exhibiting and kept for what it says. The half it cannot do is read the
  sentence, and that half is deliberate: a claim written mid-sentence weighs nothing, and the remedy
  is the one a marker has always had — give it its own line. `RELEASING.md`'s signal table now says
  so beside the heading the marker is an alternative to.

  Measured on this tree: `php bin/release.php --weigh --dry-run --branch=dev` answered
  `minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)` before the
  change, with a `breaking CHANGELOG` row, and `minor  (weighed: a minor change)` after it, with
  that row gone and the `### Added` heading the loudest one. The version is 0.2.0 either
  way, because what moved is the claim the plan makes about the notes. Pinned by two cases in
  `BumpWeighingTest`: `test_a_note_that_only_names_the_marker_weighs_the_heading_it_sits_under`
  drives the three mentions that used to trip it — a quoted word mid-sentence, a bullet naming it,
  and a fenced example — and asserts the patch their headings weigh, and
  `test_the_marker_weighs_breaking_under_the_decoration_a_note_writes_it_with` drives the three
  claim spellings that must still weigh a major, so the rule cannot narrow the marker to a bare line
  nobody writes. Restoring `str_contains()` fails the first case by name.

- **A method's recorded shape counts its arguments, one part each, instead of the whole list as
  one.** `parameterShape()` splits a parameter list on a comma at nesting depth zero, and inside a
  parameter list the depth is one — the opening parenthesis is what set it — so no comma ever split
  anything: every method was recorded as one part, `1/1` for a signature whose every argument was
  required and `0/1` for one with a default anywhere. That number is what `describeChange()` reads
  to tell an added required argument, which breaks every caller, from an added optional one, so the
  sentence it printed — "needs N required argument(s), was M" — was about a value that could not
  move. The fix is the depth the comma is compared against, and the separator the parts are joined
  with so a shape reads the way the declaration was written. Forty-three rows of `methods.tsv`
  changed and neither of the other two files did, which is the shape of the defect: it was the
  method list and nothing else.

  Rendering the new API page is what surfaced it — a page makes a wrong number visible where a
  signal that compares two rows cannot tell a wrong count from a changed method — and the reader is
  now pinned directly by `SurfaceReaderTest`, because a fixture package can only say that a row
  changed, and a wrong count is a plausible string.

- **`db:doctor --config-file` judges a candidate read list, so a weight the next boot would refuse
  cannot pass the vet.** The vet read one `config/db-manager.php` and reported the switches and
  reader windows it would be refused for; the read list whose replicas are weighted lives in
  `config/database.php`, so the file a deploy actually changes was the one the mode could not look
  at. `--config-file` now accepts either shape: a `swrr` block is judged for its switches and reader
  windows, a `connections` block for the read list of the connection named on the command line
  (`pgsql` by default), and a file holding both for both.

  The new row is the installation row's rule rather than a second one. It asks
  `Support\ReplicaMetadata::refusals()` — the pure classifier the boot audit refuses on and the
  `replica metadata` row already reads — about the connection's `read` list, so a weight, core count
  or memory figure the next boot would refuse fails the vet here, with the same sentence and the
  same named replica. A `weight: 0` drain is named on the pass, as it is there, because the package
  means it. What the candidate row does *not* report is the pool's arithmetic — no size, no total
  weight, no exclusion — because a file has no pool until it is booted; the vet's "refusals, not
  warnings" line is drawn at the resolver, and this row is on the refusals side of it. A
  `connections` block that does not define the connection the run named fails the `config file` row,
  naming the connections the file does define rather than passing a read list it never found.

  Pinned by four cases in `DbDoctorTest` — the refusal of a candidate read list, the pass that names
  a drain, the wrong connection, and the JSON envelope for the new shape — and documented in
  `docs/replica-metadata-refusal.md` and the README's vetting section.

- **The weighing refuses a release on a half-written inventory instead of silently weighing three
  signals.** The fourth signal is the only one that carries the *file* a declaration came from, so
  it is the only thing that can witness a removal a second file's declaration hides — and the only
  thing that can witness anything at all on a tree with no tag. An inventory with one of its three
  files missing was reported as incomplete and skipped, which is under-weighing, and that is the
  one direction a version signal must never be wrong in: this repository's own `files.tsv` and
  `methods.tsv` sat stamped `v0.2.0-alpha1` with no `surface.tsv` beside them, so every weighing
  the package's own development did ran with the second opinion silent and said so only in a note.

  The rule is narrow on purpose, and the narrowness is the decision. A *partly present* record is
  the one state that is unambiguously a lost file: at least one file carries a stamp, so a previous
  release demonstrably published the record, and a file missing from it cannot be "never written".
  An inventory that is *entirely* absent is a tree that has never written one — what every checkout
  is before the release that writes them, and what a first release is — so refusing it would make
  the record a precondition of ever creating it. A stale stamp is repaired by the release that
  proceeds, so refusing it would block an ordinary between-releases state. The refusal names the
  missing file and the one command that repairs it — `php bin/inventory.php --at=<the stamp the
  record carries>` — because a plain `bin/inventory.php` writes the working tree's rows and stamps
  them with a tag that never held them, which is the hand-regeneration the stamp rule exists to
  catch. A dry run reports the state instead of refusing, like every other rail, because a plan
  publishes nothing.

  Pinned by four cases in `BumpWeighingTest` — the refusal on a real run, the same state on a
  plan, the entirely absent record that still releases, and the named repair driven end to end
  until the refused release proceeds with the record weighed — and the limit is stated rather
  than hidden: deleting all three files produces the absent state, which is reported and not
  refused, since the files alone cannot tell that from a tree that never adopted the
  inventory.
  `bin/release.php` and `bin/weighing.php` carry the rule,
  [RELEASING.md](RELEASING.md#a-record-that-is-partly-there-stops-the-release) the procedure, and
  [docs/inventory-surface-rows.md](docs/inventory-surface-rows.md) the decision and its limit.

- **The audit block publishes the live reading beside the record, so a finding written by a boot on
  another connection or environment is labelled instead of read as this host's.** `/health/db`
  embedded `BootAudit::reported()` and nothing else, and the record is per installation while a
  boot is per process: a deployment whose entrypoint migrates under another environment before its
  web process starts writes findings that name *its* connection and *its* Redis host. The payload
  published them beside a `pinned.connection` of `pgsql_proxy` with `replicas.store_healthy` true —
  a `swrr.primary_store.unreachable` sentence about SQLite and `127.0.0.1` on an instance that was
  serving reads from PostgreSQL. Every field was individually correct. The block was answering one
  boot's question with another boot's answer, and nothing on the page said so.

  Two things are recorded now. Each finding remembers the scope it was written in — the resolved
  connection, its driver, the rule that named it and `app.env` — and `reported()` carries a *second*
  reading taken while building the response: the same six producers a boot runs, in the same order,
  handed to `BootAudit` by the provider as a closure, so the class that owns the block still knows
  no producer and the provider that owns the settings still knows no rendering. `primaryStoreFindings()`
  is asked with the probe off, because a reachability check is a connect timeout and a health payload
  is not the place to spend one: the store key is therefore absent from the live findings and absent
  from `evaluated`, so `findings[].current.evaluated` is false and `standing` is null — *nothing
  asked it* is not *it reads well*, and the two are separate fields for exactly that reason.
  `scope_matches` compares the recording boot's scope with this one's, and is null when either side
  is unknown: a record written before scopes were kept, or a live reading that could not run.

  The record is untouched by all of it. The finding keeps its sentence, its level, its first sighting
  and its age, and the boot that finds it clean still closes it out; the live half writes nothing,
  logs nothing and probes nothing, and costs a handful of configuration reads and stats per request.
  `db:replica-status` renders both halves too, each finding followed by its `now:` line and the live
  reading printed under the list — including the one thing the record cannot say, a key that reads on
  now and that no boot has written down yet. No `status`, no HTTP code and no exit code moved.

  Pinned by six cases in `BootAuditTest` (the scope a report remembers, a record that carries none,
  the live reading beside the record, a key that was not evaluated, no evaluator at all, and an
  evaluator that throws) and three in the provider suite (a finding from another scope labelled in
  the payload, the store's reachability reported as not evaluated, and the terminal saying the same
  thing about the same record).
  [docs/boot-audit-surfaces.md](docs/boot-audit-surfaces.md) gains the two-readings section, and its
  limitation 5 now says the block labels what it used to hide; the README's audit section documents
  the new fields.

- **The package's own inventory record is whole at `v0.2.0-alpha1`: the third file it was missing
  is written from that tag's own tree, so the fourth signal weighs here instead of being skipped.**
  `files.tsv` and `methods.tsv` at the package root were stamped `v0.2.0-alpha1` and `surface.tsv`
  was never written — the incomplete state the weighing reports and steps around, which cost every
  `composer release --weigh` run in this repository the inventory's second opinion. `php
  bin/inventory.php --at=v0.2.0-alpha1` builds it from the tree that ref holds, so the rows and the
  stamp come from one tree and a row can only describe what that release published; it is the
  backfill case `--at` was added for, and the one run of it that completes a record rather than
  replacing one. The record is now 34 files, 167 public methods and 165 keys and members — 48 config
  keys, 71 constants, 32 env vars and 14 public properties, the typed properties the surface reader
  had been blind to. The generator's header and `docs/inventory-surface-rows.md` no longer say this
  repository carries a two-file set, and `php bin/inventory.php --check --at=v0.2.0-alpha1` reports
  the set current where it reported the missing file.

- **`db:replica-status`'s one non-zero exit was documented and pinned by nothing, and the record
  described it as another command's row.** The command's rule is one input — is the weighted manager
  bound — so `docs/documented-exit-codes.md` listed it as "not a matrix", pointing at
  `db:probe-replicas`'s `unbound` cell for its single `1`. That cell pins the *probe's* guard, not this
  one, and this one returns from two places: `--json` writes an object and its code, the terminal
  writes the sentence and its code, and only the first was ever driven. The run an operator meets —
  `ERROR  WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.` and
  exit `1` — was in the README's table and asserted nowhere.

  The provider is an exit matrix now, six cells derived from the three routes rather than written a
  second time, and every cell runs the real command: the code, the sentence the route carries, and —
  on `--json` — the `exit_code` inside the object. `documentedSituations()` maps each cell to the
  README row it is an instance of, which is the table's other claim: one `exit` column, so both
  channels exit the same code. `DocumentedExitCounts::MATRICES` names the test file, so the record's
  own count of the commands it is about is rendered from it — three became four, and so did the tables
  and the mutation list, in the record and in the counted rows of `docs/prose-numbers.md`. Both places
  an operator reads were saying the code never moves; the class docblock and the README's
  `db:replica-status` section now name the one exception, and the guard's branch carries the reason
  in a comment. The renamed `test_the_matrix_agrees_with_the_readme_exit_table` is the name the
  other three matrices' guards use, and the two documents that named the old test were updated.
  Pinned by `test_the_exit_code_is_a_function_of_the_route_and_the_channel` and the renamed guard —
  13 tests in the file, from 8. Three mutations are measured against it: reverting the README's
  `unbound` row to `0` fails one test naming the case, reverting the provider's `unbound` exit fails
  four (both of that route's cells, the object's own row, and the agreement test), and returning
  `SUCCESS` from the terminal guard fails one — the new cell.

- **`db:doctor --config-file` reported one problem at a time, so a candidate file that was both
  unusable and noisy was reported as unusable and nothing else.** The vet's `config file` row picked a
  single sentence per state: a file that returned a string instead of an array was named for that, and
  the bytes it printed while it was being read — the ones `config:cache` writes into the cached config,
  which is the whole reason the row mentions them — were dropped, because the row returned before it
  reached the branch that names them. An operator fixed the shape and heard about the output on the
  next run of the pipeline, which is the shape the `reader windows`, `pgcat files` and `store probe`
  rows lost when they learned to name every problem they have. This row is assembled the same way now:
  the shape first, then what the file printed, each in its own sentence, with the loudest problem
  setting the verdict. The vet's other two rows already used that assembly, so nothing about their
  sentences or their lack of dates moved.

  Two things fell out of writing the test. A candidate that prints and *then* throws used to lose the
  bytes as well — the buffer this command opens is read now, before the flush that closes it, so the
  throw and the output are named together. And that flush emptied *every* open output buffer rather
  than the ones this read opened, which under a test runner is the runner's own: it stops at the depth
  the read started at, so a candidate file that throws cannot close somebody else's output. Pinned by
  three cases in `DbDoctorTest` — the shape beside the bytes, the bytes printed before a throw, and the
  entry the register declares, which drives the vet's run rather than the installation fixture's because
  the vet's row is the one whose problems are undated by design. Four mutations are measured against
  it: giving `datedRow()` only the first problem fails twenty-four tests (the register's own case
  among them), never reporting the bytes a candidate printed fails four, dropping the buffer this
  command opened fails one, and emptying every buffer rather than the ones this read opened fails one.

  Every other row was audited one at a time for the same shape, since the register's measure can only
  see rows whose problems are dated. None of them drops a problem: the rows with two states are
  mutually exclusive in the state the first one reports (`weighted factory`, `published config`,
  `pgcat gate`, `replica metadata`), progressive readings of one thing (`store reachability`), one
  chain nobody can shorten (`pgcat supervisor`), or a fact another row names in the same run
  (`provider swap`). `replica metadata` names several problems already — one per replica — and stays
  out of the register because the resolver's pool decisions are not findings to date. The table with
  each row and its reason is in [docs/db-doctor-json.md](docs/db-doctor-json.md).

- **A release after a dev tag was weighed from that tag, so promoting the version it announced was
  refused — and `--weigh` answered with a version that skipped it.** A prerelease does not *hold* the
  rung in its name, it announces it: `0.5.0-alpha1` is a prerelease of `0.5.0`, so a round of changes
  cut after it belongs to a 0.5.0 line rather than to a version anyone has released. Both halves of
  the release read the latest tag as the base instead. `--weigh` counted `0.5.0-alpha1 + patch` as
  `0.5.1`; with a `v1.0.0` released and a `2.0.0-alpha1` in flight it counted `2.0.0-alpha1 + major` as
  **3.0.0**, two lines ahead of the change and a version that leaves the 2.0.0 the alpha was cut for
  unreleased. The gate read it the same way: `0.5.0` over `v0.2.0` is the minor the round's notes ask
  for, but against `0.5.0-alpha1` it reads as a patch, so a release the policy is satisfied by was
  refused and `--minor` — 0.6.0, the same skip — printed as the way out. The rung of a declared
  version is now measured from the newest *release* at or below the line being developed
  (`precedingRelease()`), and `--weigh` offers the promotion a dev tag announced, stepping past it
  only when the changes since the tag ask for more than the promotion itself climbs — and then from
  the release, so the fourth row of `RELEASING.md`'s table is answered with 2.0.0 rather than with a
  version above the tag. A lane cut again is measured the same way, so `0.5.0-alpha2` after a round of
  features is no longer refused for being one patch step from `alpha1`, and a first release whose only
  tag is a dev tag is declared rather than refused. The plan names the base it used, since it shows
  the dev tag as `latest tag` beside a rung measured from a version that is not it. Pinned by six
  cases in `PrereleaseTest` — the promotion at a minor and at a major, `--weigh` naming the promotion
  rather than stepping past it, a second dev tag in a lane, the first release behind a dev tag, and
  the promotion that is *still* refused because it is a smaller step than the changes ask for. Each
  rule is measured against its own regression: measuring the rung against the dev tag again fails
  four of them, stepping `--weigh` from the dev tag fails three, and offering the promotion as the
  prerelease itself fails four.

- **`bin/inventory.php`'s rewrite warned about discarding a record it had never read.** The warning
  compares the files on disk against the tree and names the changes a rewrite is about to forget,
  and it did that whenever *either* file had been read — so a pair that is half there reported
  every row of the missing side as `added public method ...`, under a sentence claiming this rewrite
  discarded them. That state is not hypothetical: both files are written in one call and only the
  second can fail, so an interrupted release leaves exactly it. The file that is not there recorded
  nothing, so the guard is now "both, or neither" — a half-written pair is completed without a word
  about a record, and the rows it *did* write are reported as before. Pinned by
  `test_a_pair_that_is_half_there_discards_nothing_it_did_not_read`, which fails on the old
  condition, and by seven more cases for the generator itself: `--root=PATH` writing into the
  package it names and leaving the script's own alone, `--check` following that root rather than the
  script's, both files named when neither is there, a row too long to print cut at 68 characters and
  ended with an ellipsis, `-h` as the same run as `--help`, an unknown option *after* a valid one
  still exiting `2` and writing nothing, and the second file failing to write while the first is
  already on disk. Sixteen tests on the generator are twenty-four, and the shape of the new ones is
  measured rather than assumed: ignoring `--root` fails three of them, removing the row cut one, and
  writing `methods.tsv` before `files.tsv` two.

- **`db:doctor`'s `store probe` row dates each of its two states from its own boot finding, so a
  preflight can say how long the installation has been unable to check its store rather than only
  that it is.** Both faults the row reports are the same kind of thing and it is the kind a row
  cannot express: a probe switched off (`swrr.audit.store_probe_seconds = 0`, the documented way to
  keep the audit to configuration checks) and a record the probe cannot be remembered in. Neither is
  a malformed value — the package reads both settings perfectly and the installation cannot carry one
  of them out — and both *stand*: a row describes this boot, the record describes every boot since,
  and "since when" is the whole question about a state that has been true for weeks. Each is a
  finding of its own now — `swrr.audit.store_probe_seconds.off` and `swrr.audit.file.unwritable` — so
  the row dates each from its own entry, the way every refused reader setting already does, and the
  register of rows that can name several problems covers it: `store probe` was the one case in that
  register that survived the "first problem only" mutation, because the row built its two sentences
  itself instead of asking the shared row builder for them, and once the states are findings it builds
  them the way every other row does and the mutation reaches it. Both are `warning` level: the first
  because switching the probe off is the choice the README names and `--strict` is the surface that
  refuses it, the second because it is the fault the README sets against that choice and nothing about
  it is malformed either.

  The second key is the one finding in this package whose own record can never hold its date: the file
  a date would be written to is the file that cannot be written, so `persist()` skips the write and the
  operator gets the log line. It is keyed anyway — a line with a key is one an alert rule and a grep
  can select on, and the row is where the state is described — and the asymmetry is written down at
  the constant rather than left to be discovered, because the alternative was a standing state
  reported by no key at all in exactly the case where the record is guaranteed to be silent. The
  third state the row names — a probe that is switched on with an in-process `swrr.primary_store` —
  deliberately keeps **no** key: there is nothing it could have been checking, and the reachability
  row already warns about the store that makes it so.

  The sentences are written once, in the class that owns the probe and writes them into the log
  (`BootAudit::probeOffSentence()` and `probeUnwritableSentence()`), so the row cannot describe a state
  differently from the line beside it — the same reason `ReplicaMetadata` was extracted; and
  `datedRow()` now finishes a sentence by trimming whatever full stops it carries and writing one back,
  because the store probe's two end where the row appends its own `--strict` aside or names the file.
  Pinned by three cases in `WeightedDatabaseServiceProviderTest`
  (`test_a_switched_off_store_probe_is_recorded_under_its_own_key`,
  `test_a_record_that_cannot_be_written_is_logged_and_never_remembered`,
  `test_a_probe_with_nothing_to_reach_is_not_a_finding`) and by
  `DbDoctorTest::test_the_store_probe_row_dates_each_state_from_its_own_finding`, and
  mutation-checked: `datedRow()` printing only the first problem now fails 19 tests rather than the
  15 it did before this change, the store probe's own three row tests among them. The suite's default
  boot stopped being an opted-out installation with it — `store_probe_seconds` was `0` in
  `TestCase`, which is a standing finding of its own now, so the fixture's boot is a probe that is on
  and has already run, and the tests that are about the probe set the interval and backdate the stamp
  themselves.

- **`db:doctor`'s `pgcat files` row prints the repair for every problem it reports, rather than
  naming three unusable files in sentences and leaving the `suggestion` column empty.** The row is
  the only one that can carry several problems at once, and it was the one that never printed a
  line: every fault it reported was a path or a permission, which is exactly what the reader-window
  rule excludes — the package would have to guess at a value only the installation knows. That
  stopped being true of every problem at once. A permission is a **mode** on a path this
  installation has already chosen, and an empty `config_path`/`state_file`/`lock_file` is a value
  the published config ships, so each problem is now dated from its own finding and repaired
  individually: `chmod +r` for a source the row cannot read, `chmod +w` for a file it cannot write,
  and `chmod +wx` for a directory, because the atomic swap writes `{target}.tmp.{pid}` into it *and*
  renames the result over the target, so write without traverse still fails. The mode is a symbolic
  *add* on the path the check was run against rather than a whole mode, so nothing the operator set
  is rewritten, and it is deliberately unscoped — the row proves that *this* user can use the file,
  so a line naming a narrower class could print a repair that does not clear the check it was
  printed for. An empty key prints the setting line with the value the published config ships, which
  is the half of candidate C of `docs/pgcat-suggestion-lines.md` that survived review: a value the
  package itself publishes is its own to repair, so `swrr.pgcat.config_path = '/etc/pgcat/pgcat.toml'`
  and the two state paths as the sample's `sys_get_temp_dir()` spells them on this host are printed,
  while `readers_path` and `no_readers_path` — documented as `—` — still get none. A file that is not
  there gets none either, because that file has to be put there by whatever installs pgcat, and
  nothing about which states are *problems* changed: the sentences, their order and the list they
  come from are the same, and the sentence still accompanies the line because the flip's refusal,
  `--dry-run` and `/health/db`'s `warning` read the sentence and have no suggestion column.

  The row and the record read one list. `PgcatConfigFlipper::fileProblems()` is where the
  preconditions are computed — the flipper already owned the keys, the documented commands and the
  paths a flip touches, so it owns these too — and `db:doctor` renders each problem into the row
  while the boot audit records the same problems as findings, one key per setting:
  `swrr.pgcat.config_path.unusable`, `swrr.pgcat.readers_path.unusable`,
  `swrr.pgcat.no_readers_path.unusable`, `swrr.pgcat.state_file.unusable` and
  `swrr.pgcat.lock_file.unusable`. They are `warning` level rather than `error`, because an unusable
  path is a flip that will throw when the window next opens rather than an installation serving
  reads the wrong way; each carries the setting, the path and the state it was found in, and each
  has a resolution sentence for the boot that finds the file usable again and for the boot that
  finds pgcat off, so a record closes out on the repair rather than waiting to be deleted. An
  *empty* key is the one state the record leaves to the row, and deliberately: the provider defaults
  `state_file` and `lock_file`, so emptiness has to be written on purpose, and a boot that logged
  each unset path would write three lines per boot about a state the two `pgcat` rows already report
  and the flip's first use already throws on. The audit reports files, `db:doctor` reports armings.

  Pinned by seven cases in `PgcatConfigFlipperTest` —
  `test_the_documented_paths_are_the_ones_the_published_config_ships`, which reads the values out of
  the published config rather than copying them beside it,
  `test_an_empty_key_carries_the_published_value_only_where_the_config_ships_one`,
  `test_a_directory_that_will_not_take_a_write_carries_the_mode_that_would`,
  `test_a_file_that_is_not_there_gets_no_line`,
  `test_a_file_that_cannot_be_read_carries_the_mode_that_would`,
  `test_each_file_mode_problem_carries_the_bit_that_failed` and
  `test_an_inert_flipper_has_no_file_preconditions_at_all` — by three in `DbDoctorTest`
  (`test_the_files_row_prints_one_repair_line_per_problem`,
  `test_the_files_row_repairs_an_empty_key_only_where_the_published_config_ships_a_value`,
  `test_each_file_problem_is_dated_from_its_own_finding`) and by three in
  `WeightedDatabaseServiceProviderTest`
  (`test_a_file_a_flip_needs_that_cannot_be_used_is_recorded_under_its_settings_key`,
  `test_an_empty_pgcat_path_is_the_rows_problem_and_not_a_file_finding`,
  `test_switching_pgcat_off_closes_a_file_finding_out`), and mutation-checked against its own
  failures: returning no line for an unreadable source fails one test — which of them depends on
  the platform, the filesystem case where mode bits are honoured and the mode table where they are
  not — and that table exists because the first run of this mutation on this laptop measured
  **zero** failures, with `+r` covered only by a case that has to skip there; declaring the state
  path instead of reading it from the sample fails two, the drift guard and the empty-key line;
  recording an empty key as a file finding fails 34; and a row that printed only the first problem's
  repair fails three.

- **`db:doctor`'s `replica metadata` row names a drained replica in every branch now, rather than
  only in the one that passes.** The row reports two kinds of thing and they are not the same kind: a
  *value* the resolver cannot read, which is what fails a deploy, and a *replica* the pool does not
  hold, which is what an operator acts on. The disable — `weight: 0`, the documented way to take a
  replica out — was named only where nothing else was wrong, because the row's other two branches
  return before the pool is described at all: a weight the resolver could not read returned a
  sentence about the value while the replica somebody had switched off went unmentioned until the
  deploy *after* that repair, and a pool in which every replica is disabled printed `every replica on
  [pgsql] resolves to weight 0 — reads cannot be routed` without saying which replicas they were.
  Both branches now carry the same clause, spelled once: `Also drained on purpose: 10.1.0.2:5432 —
  weight is 0, which is how the read list takes a replica out of the pool.` The rule underneath all
  three branches is asserted as a property rather than case by case —
  `DbDoctorTest::test_the_metadata_row_names_every_replica_the_pool_does_not_hold` runs five read
  lists, asks the resolver which replicas its pool does not hold, and requires the row to name every
  one of them whatever it left over and whatever verdict the row reached — and two cases pin the
  wording: `test_the_metadata_row_names_a_drained_replica_beside_the_value_it_cannot_read` and
  `test_the_metadata_row_names_every_replica_when_every_one_is_drained`. Removing the clause from the
  refusal branch fails four tests and from the empty-pool branch two, so neither branch can go back
  to reporting a value while the replica that is not answering reads goes unnamed.

- **The boot audit's record is now safe under genuinely concurrent boots rather than merely loud
  about them.** The record is one file shared by every boot of an installation, and a boot writes it
  from the copy it read near its start — with the store probe in between, which is a network call, so
  the window can be a connect timeout wide. Two boots in flight therefore both wrote from the same
  starting point, and the later one **discarded** whatever the earlier recorded: an entry nothing had
  taken out of the record yet, dropped by a write that never knew it was there. The rename that made
  the write atomic protected the *reader*; the writer was the same hazard one level up, and it was
  reported — a line naming the entries about to go — rather than prevented. It is prevented now, by
  two mechanisms that are both needed. The write re-reads the record as it is about to be replaced
  and **merges**: the keys this boot produced are written over what is there; a key this boot
  evaluated and found clean is cleared only while the entry is still the one this boot read, and one
  another boot substantiated in the meantime is kept and named, because a boot that has just logged a
  setting failing is not contradicted by a copy read before that; every other key is taken as the
  file holds it; and the probe stamp keeps the later of the two, so a merge cannot cost the
  installation a probe it did not need. And the read-merge-write is taken under an exclusive
  `flock(LOCK_EX)` on a **companion** file beside the record — a companion because the write replaces
  the record by renaming a temp over it, so a descriptor opened on the record would be holding a file
  nothing will ever open again, and two boots either side of a rename would each hold "the record"
  while excluding nothing. Which lock it is matters as much as the exclusion: `flock` lives on an open
  descriptor, so the kernel releases it when a process ends however it ends, and a worker killed
  mid-write leaves an empty file beside the record — which is not a lock, says nothing and stops
  nothing — where the sentinel the store-probe decision refused would have waited for somebody to
  find and delete it. A boot does not wait for the lock indefinitely either: eight attempts, 15 ms
  apart, and then the merge goes ahead from the freshest read it can take and says it could not
  serialise, because recording the settings this boot checked matters more than recording them alone.
  Where the record cannot be written at all, none of the write is attempted — no re-read, no merge and
  no eight futile attempts at a lock a read-only directory would refuse, since the merge's result has
  nowhere to go and a write that never happened has not "merged without serialising": the boot pays a
  stat for the check, and the finding it logged is what an operator gets. The change is pinned by
  seven new tests in `BootAuditTest` — the merge, the kept verdict, the lock taken and left free for
  the next boot, a lock file a killed worker left behind, a lock this boot cannot take, the re-read
  taken inside the lock, and the probe stamp — and mutation-checked against its own failures: writing
  this boot's own copy instead of the merged one fails four of them, reading the file before taking
  the lock fails one, taking the stamp from this boot's copy fails one, and giving up on the write
  when the lock cannot be taken fails one.

  The mechanism is measured rather than argued, too. `BootAuditConcurrencyTest` runs four real boots
  of one record — as **processes**, because a lock is only worth measuring between processes: two
  descriptors the same process opened collide whether or not the writers share a path, so a
  same-process test passes against a design that excludes nothing. Every boot is released at the
  same wall-clock instant and times two windows around the shipped lock closures: the read of the
  record to the rename that replaced it, and the part of that the lock is held for. What it asserts
  is the relationship and never a number — the hold stays far below the boot stage it sits between,
  so a lock taken at boot rather than at the write fails the run — in two regimes that fail for
  different reasons: a boot stage between the read and the write is what a stale copy would be
  written back over, which is the merge's half, and with no stage at all every boot is inside its
  critical section at the same instant, so the reads all happen before any of the writes and the run
  is the lock's. Run against that harness, the two mutations that separate the mechanisms each fail
  their half: writing this boot's own copy instead of the merged one loses three of the four keys
  *in silence*, in both regimes, and giving each boot a lock path of its own fails both runs on the
  boot that wrote its key and could not find it.

- **`PUSHING.md`'s credential section is two paragraphs again.** The sentence that closes the
  `glab` half of it — `the token does not.` — had run into the sentence after it, so the advice
  about a personal access token read as its continuation. Nothing was reworded, and the shape is
  worth naming because nothing here reads sentence shape: the guards over these records read
  claims — a cited test name, a count, a fenced block — so a fused pair of sentences is exactly
  what the suite cannot see.

### Changed

- **Two of the boot audit's log lines are about the record's write instead of about a lost entry,
  and an alert rule written against the old one needs updating.** "The audit record changed while
  this boot was running …" no longer says `discarding N entries` — the entry is not discarded any
  more, the write keeps it — and the context keys it is triaged by are `kept` and
  `keys_this_boot_read` rather than `discarded` and `keys_on_disk`. A lock the write could not take
  is the second line, and its context carries `lock`, `attempts` and `waited_ms`. Both are logged at
  `error`, both name their subject in the context, and the README's triage table says which key tells
  which kind of page apart.

  **What this asks of an installation:** an alert rule, a dashboard query or a log grep that selects
  on `discarded` or `keys_on_disk` selects on nothing after the upgrade, because the keys are `kept`
  and `keys_this_boot_read` and the sentence that carried `discarding N entries` is gone with them.

## 0.2.0-alpha1 - 2026-09-28

### Added

- **A write-back scan is now one of the checks, so a keyed structure read from disk and written back
  whole fails the gate instead of a reader noticing.** The shape is the quiet one: a file is read as a
  record or a state file, a key or two are set on the copy in memory, and the whole structure goes
  back — every key the reader did not know about is gone, the diff shows the key that was added, and
  nothing shows the keys that went. Both places in this package where it has mattered were found by
  someone reading the code rather than by a run failing: the flip's state file was once replaced by
  whichever four keys the flip happened to carry, which erased `converged_at` and moved the boot
  window with it, and the boot audit's record is one file shared by every boot of an installation.
  `bin/checks.php` now scans `src/` and `config/` for the shape in the AST rather than as text: a
  write is `file_put_contents()` or the `fwrite()` of a handle opened in the same method, its target
  is what a `rename()` in the same method finishes on rather than the temp it names, the path has to
  be one the class reads, and the payload has to be a whole value — an encoded structure, or a
  variable, element or array holding one — so a log line written into a file that is also read is not
  a finding. One form needs no explanation, and it is the one the flip already uses:
  `file_put_contents($this->stateFile, json_encode([...$this->readState(), 'last_mode' => $mode]))`
  survives a key that arrived between the read and the write, so a merge with a read taken at the
  write site is exempt. Every other write-back is named in the `STRUCTURE_WRITE_BACKS` register at the
  top of the check, with the reason the copy being replaced is still the truth — the flip's config
  rollback, its state file through `writeState()`, and the audit record, which re-reads and *reports*
  a record another boot left rather than merging it. A register entry the code has left behind fails
  the check too, because a declaration that outlives the code it described would excuse the next write
  at that spot. And because a detector that has stopped seeing the shape reports an empty result, it
  is run against fixtures of its own — the two ways this package has lost a key, the merges that are
  the safe form of both, including one whose read is two calls deep, the atomic temp-then-rename
  write, a handle opened and written with, a config file rebuilt around an encoded structure, a copy
  to another file, a scratch file nobody reads, a line of prose, and a helper that writes what its
  caller read — before it is pointed at the tree, so it fails by name rather than passing everything.
  The scan has been mutation-checked against its own failures: a dropped register entry fails naming
  every site it should have covered, an entry the code no longer has fails naming it, a dropped work
  in the detector — the merge exemption, the temp resolution, the handle resolution, the encoder that
  makes a rebuilt config recognisable, reading a path out of a `require` — fails the fixture that pins
  it, and a file with the bug shape dropped into the scanned tree fails by file, line and payload.

- **A deploy gate for `db:doctor --json` now ships in the README, and the guard that keeps it honest is
  a test.** The preflight's object was documented with `jq` one-liners for a person at a prompt, and
  what a pipeline needs is the other thing: one script to paste that names the rows its deploy blocks
  on and refuses to ship when one of those rows is not `PASS` — a `WARN` on a row you named is a row
  you said must pass — or when some row `FAIL`ed, which the run's own `verdict` folds, because the row
  list is not a fixed length and a pipeline cannot name a row it has never seen. It decides on the
  object and not the exit code: the command's status is discarded on purpose, so a preflight that never
  got written is an empty file, and `jq` refuses that as loudly as a failing row does. A gate written
  in `jq` is a promise about field names, and one failure would otherwise never be seen: `select` over
  a row name no row carries matches nothing, so a row renamed in the doctor leaves the gate passing
  every preflight, the broken one included. So `tests/Unit/Docs/DoctorGateTest.php` treats the block as
  code — the fenced block is read out of the README, its `jq` program is extracted and **run** against
  a report this command really produced: one it must ship, one whose named row is `WARN` and one whose
  unnamed row `FAIL`ed, each refusal asserted to name the row it refused on — and every row the gate
  names is asserted to be a row a real report carries. The gate has been mutation-checked against its
  own failures: a row name no run carries fails the row check, a dropped `select` fails the named-row
  refusal, an unread run `verdict` fails the unnamed-row refusal, and a gate that refuses everything
  fails the clean case.

- **The vocabulary every report is written in is now declared in one place, which is the half of the
  report contract this package's own records had been leaving open.** `docs/db-doctor-json.md` and
  `docs/pgcat-flip-json.md` each ended by naming a package-wide envelope as the thing that would
  change their decision, and the doctor's record said what it would want from one: a single place
  that defines the vocabulary. `Console\JsonEnvelope` already held the other two halves — the five
  keys every report leads with, and the rule that the exit code travels inside the object rather
  than in a `$?` beside it — and now holds the third. `JsonEnvelope::REPORTS` names every command in
  the package that writes a report, the key its run verdict is read by, and the README table its
  closed vocabulary is documented in; `JsonEnvelope::SHARED_KINDS` declares the words two of those
  vocabularies may legitimately share, which is how `unbound` stops being a convention and becomes
  the declared case. `db:doctor` is in that register rather than beside it: its object is still
  written by hand and still names the run's verdict `verdict` — candidate F of
  `docs/command-json-envelope.md` is why, and a rename is still the one-line answer for a gate that
  wants one name — but the spelling is now declared in the same place as the other reports', so
  learning what each of them calls its verdict is reading one document rather than three records and
  an exception. The register is read back out of the package rather than restated: the tests parse
  the table each entry names with the column each entry names, and find the commands that write a
  report by reading `src/Console/Commands/` for the two ways one is written — through the envelope,
  or by hand in the single case that is. A further report command therefore cannot be written
  without an entry, an entry cannot name a command that writes no report or a table that is not
  there, and a word two reports happen to share has to be declared as shared — which turns the
  limitation the envelope's own record carried (nothing compared the vocabularies, because no
  artefact held the set of words) into a comparison instead of a note. Nothing about a run changes:
  the keys, the codes, the kinds and their tables are exactly what they were, and the register is a
  declaration of them.

- **`db:probe-replicas` and `db:replica-status` report as data, in the envelope `db:pgcat-flip`
  defined — extracted into one class so the three commands a pipeline branches on are read by one
  rule.** Both earlier JSON records ended by naming this as the thing that would change their
decision: "a package-wide envelope would be the better home for the vocabulary, and it would want one
place that defines it". It has one now. `Console\JsonEnvelope` owns the five keys every report leads
with — `command`, `kind`, `exit_code`, `reason`, `error`, in that order — and each command declares
its own evidence as a constant of `key => the value an absent one takes`: the flip's `mode`,
`previous_mode`, `steps` and `status`; the sweep's `connection`, its `counts` (`probed`, `answered`,
`failed`) and its `replicas`, one entry per attempted replica carrying the reason a failed one
failed — the half of a probe that is otherwise invisible, because the probe's own connection is
purged after every attempt; the distribution's `status` (the manager's own `healthSummary()`),
`pgcat` and `audit`, which are the two blocks `/health/db` embeds under those same two names, so a
job reading a terminal report, a saved one and the endpoint is reading one vocabulary. A default is
the key's *shape* rather than a guess — a list key defaults to `[]`, `counts` to a zeroed map, so
`.counts.failed` is readable on every route of a command that runs on a schedule every thirty
seconds. The envelope also refuses the two ways a command can write its own report wrong, both of
which would otherwise be invisible: a route supplying a key its command did not declare (a mistyped
`replica` publishes less than the route meant to while producing perfectly well-formed JSON), and a
command declaring one of the five core keys as its own evidence. `kind` stays the command's own
closed vocabulary — the sweep's five are the five cases its exit table documents, one to one,
because a case that exits differently has to be a different verdict — and the word `unbound` is
shared deliberately: "the container has no weighted manager" is the same repair whether the missing
dependency is the manager or the flipper. Each command's kind table is now in the README beside its
exit table and bound the same way, by a test that reads it back and asserts the codes. Nothing about
a run changes: every matrix runs twice, once for the rendered report and once for the object, and
asserts the same code, marks, files and records — `--json` is not refused beside anything here,
because neither command has a flag it could contradict, and the detail `-v` prints is the object's
`replicas` rather than a second channel. `db:doctor --json` deliberately keeps its own envelope
(`verdict`, rows) — its record's candidate F is the reason, and the new record answers it rather
than leaving it implied. The decision is recorded in
[docs/command-json-envelope.md](docs/command-json-envelope.md), and the two older records' "a shared
envelope" bullets now point at it.

- **Every count `docs/documented-exit-codes.md` states is now rendered from the provider that owns
  it, including the ones stated a second time — and one of those second copies had been wrong for
  as long as the map existed.** The renderer below covered the counts that were in the record's
  sentences and in the tables; what it did not cover was every *place* a count is written. Three
  were left: the two bold headings that open the probe's and the flip's paragraphs ("**The probe's
  nine cells.**") restated their cell counts without being bound to anything — the sentence right
  under each one was rendered, the heading above it was prose — and the flip's paragraph states the
  probe's arithmetic for its own table, that sixteen cells are eight documented cases, by naming the
  groups: "the three ways a flip can politely do nothing are one case, the three ways a command can
  be refused are another". Those numbers are not a second fact either: they are how many cells
  `DbFlipPgcatCommandTest::documentedSituations()` assigns to one README row, so the phrase each one
  is a shorthand for is declared once, in `DocumentedExitCounts::FLIP_WAYS`, and the count is read
  from the map — a phrase the declaration does not know, or a row no cell is assigned to, fails
  rather than passing quietly. The same two counts are written in a *second* file: the docblock of
  the map itself, which said **two** ways a command can be refused while the map assigned three
  cells to that row — a number that had been wrong for as long as the map existed, in the file a
  reader of the map opens, with the record a reader of the record opens saying three. A claim is one
  file and one pattern, so a count written twice is two claims over one derivation, which is also
  why the renderer writes two files here: `bin/counts.php` now fixes a docblock as well as a record,
  and its first run over the map did exactly that (`two -> three`). Two consequences worth naming:
  the record's refused phrase is anchored on the words that follow the number, because the record
  states the same phrase again a few lines down describing the state *before* the guard — a
  historical "two" the renderer must not touch — and the pattern allows the whitespace where the
  record's own line wrap falls, so a claim is pinned to the record's wording rather than to its
  column width. `docs/prose-numbers.md` gained the four rows, and its boundary lists now say why
  the historical statement is prose, what the first "ways" phrase actually names (the row is wider
  than the phrase sounds, and which row it is, is the declaration), and that a number written in two
  files is rendered wherever it is written rather than only where the record is.

- **The counts `docs/documented-exit-codes.md` states are rendered from the providers that own
  them, and the first render found one of them had been wrong since it was written.** The record
  was the one that named this problem — the flip's matrix sat at "fifteen" from the day the JSON
  work added a sixteenth cell — and the previous entry below answered it by *checking* every count
  against its derivation. Checking still leaves the number written by hand, and one of them was
  wrong in a way the check could not see: the candidate-C row said the probe's table documents `0`
  on three rows and `1` on two, and the README's table has it the other way round. It reads as a
  real argument, because the sentence's point — the sets of codes match while a case is documented
  as the wrong one — holds either way, and nothing had compared the split with the table's own
  `exit` column. So the counts this record states are no longer written at all:
  `tests/Support/DocumentedExitCounts` derives each one — the three matrices from
  `exitCodeProvider()`, the documented-case counts from the maps from each cell to its README row,
  the doctor's tables from the README, and the probe's split by code from the table's `exit` column
  — and `bin/counts.php` renders them into the record. `php bin/counts.php` writes,
  `php bin/counts.php --check` compares and writes nothing, and `bin/checks.php` gained a check that
  runs it, so a matrix that grows a cell fails the gate instead of leaving a sentence behind. The
  renderer is safe because the guard makes it so: `ProseNumbersTest` asserts that *every* place a
  pattern matches states the derived number, so a pattern that also matched an unrelated sentence
  would already be a failing test — and the patterns are anchored on words around the number, never
  on a number another claim owns, so a rendered count cannot unbound a neighbouring claim's
  occurrence. Nothing is written unless every claim resolves: a reworded sentence, an unreadable
  record or two claims on one word is reported and the run stops, because a half-rendered record is
  worse than a stale one. The vocabulary moved to `tests/Support/NumberWords`, in both directions —
  the guard reads a record's word, the renderer writes it — and a rewrite keeps the record's own
  capitalisation, which is how "Three keys, not one `switches.refused`" stays a sentence that opens
  with a number. `ProseNumbersTest` now merges the rendered claims with the checked ones, so the two
  halves read one table rather than two that can disagree, and `docs/prose-numbers.md` records which
  records are rendered, which are checked, and the three numbers in this record that are
  deliberately neither (the failure modes and the two prose behaviours count a list their own
  sentence writes; the historical counts are about a version of the package that no longer exists).

- **Every number the records state about the code is now derived from it or compared with it, so a
  count that is true when it is written cannot quietly stop being true.** A number in a sentence is
  a second copy of a fact the code holds, and nothing in the suite read the sentences: the flip's
  matrix sat at "fifteen" from the day the JSON work added a sixteenth cell, a sentence beside it
  still said *two* ways a command can be refused when there were three, and three records carried
  counts and test names that later work had overtaken — each one consistent with everything the
  suite asserted, which is what made the drift quiet. The repair is not to delete the numbers
  ("the six methods whose lists are spread into one boot's findings" is how a reader learns the
  shape of the surface) but to give each one a reader: `tests/Unit/Docs/ProseNumbersTest.php` pins
  every claim to the sentence it is written in and compares it with the number the code has —
  counted from a constant, a class's own data provider, the source file the thing is implemented
  in, or a record's own candidate headings — and the two claims that need the command to have run
  (the README's check table, and the rows that can carry a repair) are bound the same way in
  `DbDoctorTest`, where the fixtures are. Three properties make it a guard rather than a
  restatement: the pattern must still match, so a reworded sentence fails instead of passing
  vacuously; every statement of a claim is compared, so a record saying "three" in one paragraph
  and "four" in another fails on the second; and no expectation in the guard is a literal. Two
  cross-table claims that records had been carrying as "nothing checks them" are now compared as
  well — the provider's `SWITCHES`, the doctor's `SWITCH_KEYS` and its `SWITCH_CONSEQUENCES` must
  name the same settings, so a switch added to one table cannot become a finding with no row or a
  row whose key nothing writes. The same defect with a name instead of a number is guarded too, and
  it had already bitten: twenty-two of the tests the records cite did not exist under those names,
  so the reader checking a claim was sent to an empty search. `tests/Unit/Docs/DocCitationsTest.php`
  reads the records as data and checks every `test_…` citation against the classes that declare it —
  and, where the record names the class, against *that* class, because a name that resolves to the
  wrong class sends the reader to a real test that pins a different rule. The record of the rule, the claim table, and the numbers that are
  deliberately *not* bound (a design shape no list holds, a partition that is a judgement, a
  historical state, a budget for a sized installation) are in
  [docs/prose-numbers.md](docs/prose-numbers.md); the guard reads that table back, so a claim added
  here without being written down there fails, and a number typed into the record that the code
  disagrees with fails with the sentence named.

- **The resolver's metadata floors are three constants, so the report and the arithmetic read one
  boundary instead of two that happened to match.** `WeightResolver::resolveWeight()` clamped a
  weight, a core count and a memory figure with `max(0, ConfigValue::int(…))`,
  `max(1, ConfigValue::int(…, 1))` and `max(0.0, ConfigValue::float(…))`, and the classifier that
  decides whether a written value is the one routing uses stated the same three boundaries a second
  time — one rule with two readers, agreeing only by inspection. A report that restates the
  arithmetic it is judging is a report that can go on passing after the arithmetic moves, which is
  exactly the hole `docs/replica-metadata-refusal.md` had been carrying as "no test can catch this
  without reading the resolver". The boundaries are now `ReplicaMetadata::WEIGHT_FLOOR`,
  `CORES_FLOOR` and `RAM_FLOOR`, public constants that the resolver's own `max()` reads, so where a
  boundary is has one definition and the row reads the resolver's numbers rather than its own. They
  are declared in the classifier rather than in `WeightResolver` because the boot audit classifies a
  read list without the manager ever being resolved — and because the resolver already reads the
  classifier, for the refusals and for a replica's identity, so the reverse direction would be a
  cycle for a number. The weight floor is also the value that means *disabled*, so `refusals()` and
  `disables()` now read that one boundary from both of its sides and are non-overlapping by
  construction: a weight is refused exactly when the resolver reads it as the floor and it is not
  the disable the read list means, which is the property the resolver reads to say why a replica
  left its pool. Declaring the floors once is not enough on its own — a constant can still be
  ignored — so a new test drives the resolver to each constant and asks both halves about `floor -
  1` and about `floor`, written against the weight the floor *produces* rather than against the
  replica sitting on it, because a clamp moved by one would move both of those together and an
  equality between them would keep passing. The one number in `resolveWeight()` that is deliberately
  *not* a floor — the formula's final `max(1, $weight)`, a replica that declares hardware is never
  weighted nothing — stays a literal, because nothing outside that method has an opinion about it.

- **The resolver reports the replicas it does not use, and why, so an exclusion is read rather than
  inferred from a shorter list.** A pool is a shorter list than the read config that produced it,
  and three different things take a replica out of it: `weight: 0`, which the read list means as a
  disable; a weight the package refuses to read, which `ConfigValue` falls back to `0` for and which
  therefore removes the replica as well; and the health filter the caller supplied. `resolve()`
  returned the survivors and nothing else, so `db:doctor`'s `replica metadata` row classified the
  read list a second time to work out which replicas were missing, and `pickReplica()`'s warning on
  an empty pool read `All replicas in cool-down; resetting health circuits` with the connection in
  its context and not one replica named — a log line about a shorter list, written by the code that
  knew every entry in it. `resolveWithExclusions()` now returns the pair: the pool, and every
  replica it did not use with its config, its stable `host:port`, the weight it would have carried,
  one of three reasons and the sentence for that reason. The pair *partitions* the read list — every
  configured replica appears exactly once across the two — which is what turns "the pool is one
  shorter than the config" into a question with an answer instead of an inference. The reasons are a
  closed vocabulary, because a reason a reader has to interpret is a reason nothing can select on:
  `refused` for a weight the package will not read, `disabled` for `weight: 0` written on purpose,
  and `filtered` for the caller's own health filter. Which of the first two a departure was is not a
  second rule: a weight the resolver reads as `0` is either the documented disable or a value it
  refuses, never both and never neither, so the resolver reads the refusals and what falls through
  is the drain — a fact the classifier's tests assert over every spelling of `0` and everything that
  is not a number. The configuration half is cached with the pool and the filter half is computed per
  call, so a filtered exclusion is never remembered as a property of the installation and a
  preflight cannot fail on a replica that is momentarily in cool-down; `resolve()` is unchanged for
  the read path and is now the pair's first half, out of the same cached computation either way.
  `WeightedDatabaseManager::poolExclusions()` exposes the configuration half as the deliberate
  counterpart of `replicaStatus()` — that method is the pool, this is what it does not hold, and the
  two together are the read list — and `db:doctor`'s row now reads both instead of re-deriving
  anything. Reading the consequence rather than predicting it is what removed `ReplicaMetadata`'s
  `leaves` flag (a prediction of the pool's shape, made by the classifier rather than by the thing
  that acts) and `DbDoctor::disabledReplicas()` (a second reader of a decision the resolver had
  already made). Pinned by five resolver tests (each reason, the partition, the ordering, the cache,
  and `resolve()` as the first half), the manager's two halves of a read list, the cool-down boundary
  that keeps a failing replica out of the configuration answer, and the row's existing cases, whose
  clause now states what happened to a refused value. The decision, its candidates and the
  alternatives are in `docs/pool-exclusions.md`.

- **Replica metadata the resolver cannot read is refused at boot, so a pool that quietly lost a
  replica says so.** The resolver reads `weight` through `max(0, ConfigValue::int(…))` and
  `cpu_cores`/`ram_gb` through floors of its own, and `ConfigValue` falls back rather than
  throwing — so `'weight' => 'heavy'` is read as `0`, which is how a replica is *disabled*, and
  `buildPool()` drops it. The read list described a replica the pool no longer held, and nothing
  said which one went: `db:doctor`'s `replica metadata` row failed on it, but only when somebody
  ran a preflight, which is usually long after the reads got thinner. Every boot now reads the
  configured replicas through that same rule and refuses every value the resolver does not read as
  written, at `error` level — input the package will not interpret on the operator's behalf, the
  same claim `swrr.reader_windows` and the three switches make — under one key per setting, because
  the three read differently and cost differently: `database.read.weight.refused` for the setting
  that removes a replica from the pool, and `database.read.cpu_cores.refused` /
  `database.read.ram_gb.refused` for the two that leave it there, sized as something the read list
  does not describe. The keys are the setting's own path
  (`database.connections.*.read.*.weight`) rather than one of the `swrr.*` names, because the read
  list belongs to the connection and that is what an operator greps for; the connection the package
  followed is in the finding's context instead, since a key carrying the name would resolve the
  wrong warning the day the name changed. Each key is checked clean or not on every boot, so a
  resolution closes it out on the boot that reads a readable value — the value fixed, the replica
  dropped from the read list, or the whole list removed — and the sentence is written to be true of
  all three. Nothing throws and nothing is repaired: the value is still read exactly the way the
  resolver reads it, so an installation with a typo boots and serves, which is the boundary that
  keeps this from being the throw candidate `docs/replica-metadata-refusal.md` had already
  rejected, and it is why the refusal reports the shrink rather than preventing it. The reading
  moved into `Support\ReplicaMetadata`, which both halves now call — the row and the boot refusal —
  so a log line and a preflight cannot describe one value two ways, and the sentence is built
  there too. The replica's identity moved with it: `ReplicaMetadata::key()` is the one spelling of
  "which replica", read by the health monitor's failure counts, the resolver's pool keys and a boot
  naming a replica the pool is about to drop, where the previous two copies (`WeightResolver` and
  `WeightedDatabaseManager` each built `host:port` themselves) would have become three. The row's
  own rule is unchanged — `weight: 0` is still a drain rather than a fault, a negative or truncated
  weight is still reported as the value it was read as, and a cores or memory value still says the
  replica stayed — which is what the row's existing tests prove about the class they now go
  through. Pinned by `ReplicaMetadataTest` (the reading, the documented disable, the identity) and
  by boot tests for the key, the level, the sentence, the resolution, and the configuration that
  must *not* be refused.

- **`db:doctor --config-file` vets a candidate `config/db-manager.php` without booting the
  package, so a pipeline can refuse a config before it is deployed.** The installation run
  answers questions about a *running* installation — the provider swap, the weighted factory,
  the published config, the gate, the files, the supervisor step, the store — and every one of
  those would be answered about the installation the command happens to be running in rather
  than the file being considered: a pipeline vetting a branch has the old configuration
  installed, so a report that mixed the two would judge the candidate with the incumbent's
  values and call it a review. `--config-file=path` asks the other question and reads the file
  and nothing else — no container binding, no repository, no boot audit, no database and no
  Redis. It prints three rows. `config file` is the file itself: readable, an array, holding a
  `swrr` block, and printing nothing while it is read; each wrong shape names the mistake — a
  file that throws is a row rather than a stack trace, what a file prints is captured in an
  isolated closure and reported as a `WARN` because `config:cache` would write it into the
  cached file too, and an array carrying `pgcat`/`reader_windows` at the top level is named as
  the `swrr` block passed instead of the file that returns it. `switch values` and
  `reader windows` then report the *refusals* — the values the package will not read — reusing
  the installation rows' own rules rather than copies of them: `Support\SwitchValue` and
  `Support\ReaderWindows`/`ReaderDays` own the reading, the sentences come from the shared
  `switchProblems()`/`readerRefusals()`, and `PgcatConfigFlipper::switchReadingsIn()` classifies
  its two switches from a block rather than from the flipper's own config, so a value this mode
  passes is a value the next boot will not refuse. It reports refusals only, and deliberately
  not the "reads as on but can never act" warnings: those need the resolver built over the
  value — a window whose start is not before its end, a day list with no day in 1…7 — which is
  a fact about a running installation's routing. Nothing is dated either, because a boot record
  holds findings about *this* installation and a candidate has never been booted. The JSON
  envelope is the one installation mode already emits, with the subject key naming what was
  judged — `config_file` here, `connection` and `default_connection` there — and every other
  key in the same order, so one gate can read both. `DbDoctorTest` covers the mode end to end:
  the refusals a candidate holds, that it judges the file rather than the installation, a config
  that reads clean, a deliberate opt-out told apart from a refusal, that it refuses only what
  the file wrote, an unreadable path, a file that is not a config, the `swrr` block passed as
  the file, a file that printed and was still judged, and the JSON envelope.

- **The `pgcat supervisor` row discovers what supervisord actually runs when it does not know the configured program, and names the near miss.** Three of the four faults a flip's last step can have are repaired at the thing the command *is* — install the binary, quote the name, start or unmask supervisord — and the fourth is repaired at a *name*: supervisor does not know `pgcat:*`, and nothing on that host could tell the operator what it should be instead. So that fault, and only that fault, now asks a second read-only question. After `supervisorctl status "pgcat:*"` answers `no such group`, the step derives the bare `supervisorctl status` from the same binary and reads what supervisord is running. The answer is parsed the way supervisor writes it — one line per program, accepted only when its second word is one of supervisor's states, because the same command prints a socket error whose second word is `no` and `supervisord` is a plausible program name — and the running names are ranked against the configured one: the same name, the group that name belongs to (`pgcat` and `pgcat:pgcat_00`), a prefix (`pgcat-1`), a name that holds it (`lpr-pgcat-a`), and last an edit distance within a third of the longer name. A name with nothing in common is not offered, since pointing an operator at a program that was never going to match is a guess dressed as an answer, and the sentence then says so and names the `[program:]` section instead. At most three are named, the group line comes with them when there is one to give (`or the whole group "pgcat_x:*"`), and the wording covers one and several: "the closest is …", "the closest are …". A command that works is unaffected — the second question runs only once the first has already been found missing, so the ordinary flip and the ordinary `db:doctor` still spawn one process. The refusal an operator reads now carries the name to write, which is the half of a `[program:]` repair this host can see; `inspect()`'s verdict gains `running`, `near_misses`, `discovery_command`, `discovery_exit` and `discovery_answer` for a surface that would rather read the list than the sentence. The `suggestion` column stays empty for this fault, and deliberately: the row's sentence offers a ranked near miss to a human, while a suggestion line is a value a gate applies without reading it — the two halves of the fault are documented in `docs/pgcat-suggestion-lines.md`, where the ask was adopted without the line.

- **The README has an alerting cookbook: the rules to paste, and how to tell an installation's fault from the package's once a page has fired.** The package documented *that* something has to alert — the audit's `severity`, the endpoint's `status`, the degradation note — and left the alert itself to the reader, which is the right advice and no help at 03:00. The new section is the whole of it. Three log rules written against the payload rather than the message, because every line the boot audit writes carries `severity` and `finding` in it and how a level is spelled is the handler's business: a refused value standing is a page, a setting that reads as on but cannot act is a ticket, and the resolution line — `warning` with `resolved: true` — is where the page stops. The same rule is given as a CloudWatch metric filter for a JSON channel, as a Loki query, and as the substring match that the default line handler makes of it, which is also the one a reader can test by hand. Then the endpoint: the status code answers one question (did a real query come back on the pinned connection), and six readings it deliberately does not move are spelled out — `pinned.checked` switched off, a worker serving from the in-process store, an unhealthy primary store, the audit's own two conditions, and pgcat armed where it can never act — with the whole lot as one paste-able `curl | jq -e` gate for a cron job, a sidecar or a deploy step. The closing table is the question the cookbook exists for: a refused value and a defect in the package both arrive at `severity: "error"`, and the triage is mechanical — a `finding` key names an owner, and the payload either carries a setting's own evidence or the `levels` / `discarded` keys that mean two branches collided or two boots wrote one record, while a line with no `finding` at all is the store's. The recipes are run rather than reviewed: `ReadmeAlertingTest` extracts the gate from the fenced block and runs it through `jq` against real `/health/db` payloads — healthy, failing, and one per condition the fixture can reach — and resolves every field path the section names against a real payload, so a renamed field is a build failure instead of a rule that silently never fires.

- **The release suite drives the rails it was silent about, and RELEASING.md's table now names the test that proves each row.** Five rails had no test at all — a tree that is not a git repository, a missing `CHANGELOG.md` or `composer.json`, a changelog with no `## Unreleased` heading, a dirty tree, and a shell with no terminal to answer the one question the script asks — and the documented behaviours around two of them had none either: `--dry-run` warning about a dirty tree instead of refusing, and `--allow-dirty` releasing the files a release *writes* without sweeping the uncommitted edit into the tag, which is the rail's own defect one flag away if it ever did. The steps of a release *itself* are covered too, because they fail differently from a rail: a rail refuses before anything is written, while a `CHANGELOG.md`, inventory or `composer.json` that cannot be written, an index git will not take, or a push that cannot run leaves the promoted notes on disk — and the push is the one failure that leaves a version behind, because the commit and the tag happen first. Every one of those is a state of the tree rather than an argument, so each is produced rather than mocked: the fixture gained `drop()` and `makeReadOnly()`, and git is asked to fail the way it fails (a path taken by a directory, a lock file, a remote that is not there). The inventory paths got the case neither half had: a **stale** `files.tsv`/`methods.tsv` on a release that *proceeds*, which is where the file's fate is decided — a release replaces it with the stamp of the tag it just cut, so "stale" is a state the next run recovers from. RELEASING.md's rails table grew a `Proved by` column, and `ReleasingDoc`/`ReleasingDocTest` read the table and the suite back: a row with no test in it, or a test name that no class under `tests/` declares, fails the build — the table is the contract, so a rename is a doc failure rather than a row that quietly stops proving anything.

- **`bin/release.php` refuses to tag a commit CI has not verified.** A tag is a version, and
  publishing one is not undoable, so the commit a release is cut from has to be one the
  workflow has built and passed: HEAD must be the tip of the remote branch, and the run for
  it — a push run on that branch — must have finished green. The rail asks two questions in
  that order because they fail differently: a commit that was never pushed is not one CI can
  have an opinion about, and answering "no run found" for it would send a reader to the
  wrong place. Only runs for a push on the branch being released count, because one commit
  carries several — the `v0.1.0-alpha1` commit has one for `dev` and one for the tag, and
  the tag run reports its head branch as the *tag name* — and a pull request's run does not
  vouch for the branch tip even though it runs the same steps. A run still going is not a
  pass either, and a failure beside a success is not verified, because this rail is only
  allowed to be wrong in one direction. The commit the script then goes on to create is
  bookkeeping (the CHANGELOG, the inventory, the branch alias) and cannot have a run of its
  own yet — it reaches the remote in the push at the end — so the workflow's `v*` tag trigger
  is what records it. A dry run reports the state instead of refusing, because "push this
  first" is the answer the plan exists to give, and the plan's `ci` line says which state it
  found. The question goes to `gh run list --commit <sha>`, which is the same data branch
  protection reads; `RELEASE_CI_COMMAND` overrides the command, for a machine whose `gh`
  lives somewhere else and for a test that has to answer without a network.
- **`--skip-ci` releases without asking.** Nothing verified means no tag, so a machine with
  no `gh`, no token or no network needs an explicit way through rather than a rail that
  quietly reads nothing. It asks nothing at all — it will release on a repository with no
  remote — and the plan prints `not checked (--skip-ci)`, so a release that skipped the
  question says so where the version is being read.

### Changed

- **`db:pgcat-flip`'s `--json` object now writes the envelope's five keys first, with its evidence
  after them.** The keys are the same nine and the values are unchanged; the order is `command`,
  `kind`, `exit_code`, `reason`, `error`, then `mode`, `previous_mode`, `steps`, `status`. The order
  is now a package-wide rule rather than this command's own, because two more commands write the
  same five in the same positions — and it is the reason the envelope can be one class: a reader
  looking at a failing step's output sees the verdict and the code before anything else, whichever
  command produced the object. JSON object order carries no meaning to a consumer, and a consumer
  that did depend on it was depending on something no document promised; the test states the list,
  read from `JsonEnvelope::CORE` rather than restated, and the README's sample and
  [docs/pgcat-flip-json.md](docs/pgcat-flip-json.md) were updated to match.

- **`db:replica-status` prints pgcat's two lines on the route where the connection has no replicas.**
  That route used to print the warning and the audit block and nothing else, which meant the object
  could not carry pgcat's state without the terminal saying something different from the report —
  and pgcat's state is a fact about the installation, not about the replicas being described. Both
  channels now carry the same blocks on every route that read the manager.

- **`--weigh --dry-run` reports the next bump from an empty `## Unreleased`, and says why a
  release of the same tree still refuses.** The empty-notes rail is about what a release
  *publishes* — the notes are the version's record, and a version with none is one nobody can
  read — while the weighing is a question about the *changes*, of which the notes are only one
  of four signals. A plan publishes nothing, so it is now let past the rail to answer the
  second question: it prints the weighed bump whatever the section holds, its `CHANGELOG` plan
  line reads `nothing to promote — ## Unreleased is empty, so a real run refuses here; this plan
  weighs the changes in it instead`, and the weighing table names the empty section instead of
  the generic `no ### heading the policy knows` — an empty section and one written in a
  vocabulary the policy cannot weigh are now distinguishable. Every run that could tag still
  refuses, and the refusal names the flag that reports instead. `--minor --dry-run` is
  unaffected: a declared bump is a check on the weighing rather than a question the changes
  answer, so it is refused like any other.
- **`bin/release.php` ends on one line, and the same line on every run.** The closing note
  used to branch on whether the tree already had a tag, reading that as proof the package had
  been submitted to packagist.org: a first release was told the submission comes first, later
  ones were told Packagist would pick the tag up. Neither is true of every run — a package can
  be tagged repeatedly without ever being submitted, which is a state a tag cannot reveal,
  because a tag on GitHub and a version on Packagist are two different events. The note now
  names the tag being released and both ways the gap closes — submit the package if it never
  was, or trigger a crawl — and points at RELEASING.md's *Publishing* section for which one
  applies. (The 0.1.0-alpha1 entry below describes the branching this replaces.)
- **`GET /health/db` answers `ok` only when one real query has answered.** The endpoint's
  verdict used to be the state store's: a Redis store that replied, together with replica
  lists that were healthy or not declared at all, produced `200 "status":"ok"` — observed
  while application queries were failing with `SQLSTATE[08006]`, because the connection it
  followed declares no `read` list for the replica probe to open. The controller now runs
  one statement — `select 1` — on the connection `ActiveConnection::resolve()` names
  (`swrr.connection`, else `database.default`, so it is the connection the pgcat gate and
  `db:doctor` already judge), through the application's own path so a failure is the failure
  a request would have had, and reports it as a new `pinned` block: connection, driver, the
  config key the name came from, the query, `checked`, the latency and the driver's own
  error. `status` is `ok` only when that query came back; the `replicas` and `audit` blocks
  keep their meaning and no longer decide it. `swrr.health.pinned_query`
  (`SWRR_HEALTH_PINNED_QUERY`, default **on**) turns the query off for the one environment
  where it is not a question worth asking — a fixture or an inspection with no database
  behind the connection — and `pinned.checked` is what distinguishes *no query was asked*
  from *the query passed*. A switch written as neither on nor off is refused, resolves to
  the documented default, and is reported as `pinned.refused`, so a typo cannot be what
  silences the check. Anything that treats this endpoint as a signal — a deploy gate does —
  can now read the status rather than the store, and a caller that needs to know how much was
  asked reads `pinned.checked` beside it.

### Fixed

- **A pgcat that is down is now repaired by the flip instead of refusing it, so the one case where the
  file swap *is* the fix is no longer the one case the flip declines to perform.** `supervisorctl
  status` exits non-zero as soon as a program it was asked about is not RUNNING, so `pgcat:pgcat_00
  FATAL   Exited too quickly` reached the preflight looking exactly like a supervisorctl that could
  not reach supervisord at all: `errored`, `usable: false`, the flip refusing before it touched the
  file. A crash-looping pgcat is crashing on the config that is in place, so the variant the flip
  would write is the repair — and the refusal left a dead pooler holding the config that killed it,
  with nothing on the host willing to replace it. The answer is now its own verdict (`not_running`),
  and it is one the flip proceeds on: it carries the state the answer named (`STARTING`, `BACKOFF`,
  `EXITED`, `FATAL`, with `RUNNING` winning whenever any program of the group is up) and the command
  the repair needs. That command is `start`, not the configured `reload_command`/`restart_command`:
  `signal HUP` and `restart` act on a program that is already up and supervisor answers `ERROR (not
  running)` for one that is not, which is why a flip that found pgcat FATAL would previously have
  replaced a file and changed nothing. `SupervisorStep::startCommand()` derives it from the command
  the flip was already judged on, so the binary, the group name and the quoting cannot drift apart
  from it. The ordering is inverted on purpose for this verdict — file first, then `start`, then the
  state read back up to `swrr.pgcat.start_attempts` times with `swrr.pgcat.start_retry_delay_ms`
  between them, because a program inside supervisor's `startsecs` window answers `STARTING` after a
  start that will succeed and a crash loop answers the same thing after one that will not. If the
  attempts run out the new file stays, since rolling it back would restore exactly the config pgcat
  could not start on, and no mode is recorded — so the next poll writes the same file again and
  tries again, which is the whole self-healing loop. A supervisorctl that cannot reach supervisord
  is still a refusal, and the `errored` branch keeps its own tests: a socket that is not there names
  no program, so there is no state to read and no start that could help.

- **`/health/db` reported on every connection profile in the host application instead of the one
  the package follows, so an unrelated profile could make a healthy installation look degraded.**
  The controller walked `database.connections` and summarised each key, which is the host app's
  inventory rather than the package's subject: the endpoint exists to answer "is the connection I
  route reads and writes through healthy", and the rest of the payload — the replica weights, the
  share %, the store, the formula — was never per-connection data anyway, it is `healthSummary()`
  read once per name. On an installation whose `config/database.php` holds eight profiles that
  produced eight summaries and, worse, eight probes: every connection with a declared `read` list
  was opened with `getPdo()`, so a replica behind a legacy mirror, a per-tenant profile or any
  other connection the package does not follow would fail and turn `status` into `degraded` on a
  machine whose PostgreSQL path was answering, with the failure in `errors` under a connection
  nothing on the page was about. The payload now resolves `ActiveConnection::resolve()` once —
  `db-manager.swrr.connection`, else `database.default`, the same call the pgcat gate, the
  flipper's snapshot, `db:replica-status` and `db:doctor` make — and both halves of the report use
  that one name, so what is summarised and what is queried cannot drift apart. `replicas` keeps
  its shape (a map keyed by connection name) and holds that single entry, so a gate reading
  `.replicas.<name>` is unaffected; with nothing named at all there is no summary to write and the
  map is empty, which is the same fact `pinned` already reports as a failure. Two tests fail
  against the old behaviour — an eight-profile installation whose other seven declare `read` lists
  nothing answers on still reports one connection, `ok`, and an empty `errors`; and the subject is
  `swrr.connection` rather than `database.default` when the two differ — and the two `latency_ms`
  assertions stopped being `assertIsFloat`, which failed whenever a probe rounded to a whole
  millisecond and JSON handed the number back as an int.

- **An unreadable audit record was reported as an installation with nothing standing, because the state the two surfaces had a branch for could not be reached.** `BootAudit::reported()`'s failure branch could only be entered by a `standing()` that threw, and `standing()` could not: every unreadable file — truncated by a `kill` between the write and the rename, hand-edited, or left by a version whose schema differed — was rounded to "nothing recorded", so `/health/db` answered `available: true, count: 0` with `severity: none` and `db:replica-status` printed `nothing standing`, both asserting it about a file the reader had just failed to open. `db:replica-status`'s `Audit: unreadable — <message>` line and the payload's `audit.error` field were therefore defensive code by construction: two surfaces carrying the one branch nothing could drive, while the state it describes is the one an operator most needs named, because a record nobody can read is not a record that says the installation is fine. `standing()` now refuses the rounding — it throws `UnreadableRecord` naming which shape the file is: a path with something other than a file on it, bytes that could not be read, an empty file, text that is not JSON, a JSON list rather than the object a record is, or an object whose `findings` is not the map it claims to hold. The tolerance stays where it belongs: `read()`, the boot's reader, catches it and still reads "nothing recorded", because a record a boot cannot read is one it cannot carry over or close out, the boot is the only writer, and a diagnostic must never stop an application from booting. The split is one parse in one place — `decode()` — with the boot and the surfaces either side of it, and a *missing* file is not one of the six: it is what `persist()` leaves behind when a boot finds nothing left to remember, so it is the one state that is a fact about the installation rather than a failure of the reader, and it still reports `available: true` with an empty list. `reported()` catches the throw into `available: false` with the reason in `error`, so the block finally distinguishes the three states it always had the fields for: nothing registered (`available: false`, `error` null), a record that could not be read (`available: false`, `error` naming it), and a record that was read and holds nothing (`available: true`, `count: 0`). Nothing was added to the surfaces — the terminal line and the payload field were already written and are now reachable — and each is covered by a test that fails if the tolerant reading comes back: six unreadable shapes named in `BootAuditTest`, the boot-writes-while-a-surface-refuses case in one process, the payload's `audit.error` with the check that it leaves `status` alone, and `tests/Unit/Console/DbReplicaStatusTest.php` for the command's four `Audit:` lines.

- **Reads served by the writer outside a reader window kept the address declared in `write[]`.**
  The windowed fallback merged `ConfigValue::assoc($config['write'])` into the connection config,
  and `write` is a *list* of server entries — Laravel's shape for a write connection, and the shape
  a real installation declares — so `assoc()`, which builds a map, returned `['0' => [$entry]]`
  instead of the entry. The address therefore arrived at key `0`, `read` and `write` were dropped by
  the merge, and a connection that declares its address only inside those lists (no top-level
  `host`) reached the connector with no host and no port at all. Laravel took its without-hosts
  path, the connector built `pgsql:dbname=''`, and libpq dialled its default address — a local
  socket, port 5432 — instead of the configured one, so every read outside every reader window
  failed with `SQLSTATE[08006] connection to server on socket "/var/run/postgresql/.s.PGSQL.5432"`
  while writes, which the framework merges itself, kept working: an application that serves
  `/health-check` and answers on the writer, and fails on the first read of an authenticated
  request. Both branches that fall back to the writer now narrow the write side to one entry with
  `pickUnweighted()` — the same pick the read pool already used, and the one the framework's own
  `getReadWriteConfig()` makes with `Arr::random()` — so a list of writers and a single write map
  resolve identically. The branch for a connection with no read list is fixed the same way, and it
  is a no-op without a write config. A merge that still produces neither `host` nor `unix_socket`
  now logs a `[WeightedDB]` warning rather than failing, because omitting both on purpose, to reach
  a local socket, is a legitimate configuration. The suite did not see any of this: both
  writer-fallback fixtures declare `write` as a single map, the one shape `assoc()` wraps
  correctly, and the new test declares the list shape instead.

## 0.1.0-alpha3 (pre-release) - 2026-09-27

### Fixed

- **Reads served by the writer outside a reader window kept the address declared in `write[]`.**
  The windowed fallback merged `ConfigValue::assoc($config['write'])` into the connection config,
  and `write` is a *list* of server entries — Laravel's shape for a write connection, and the shape
  a real installation declares — so `assoc()`, which builds a map, returned `['0' => [$entry]]`
  instead of the entry. The address therefore arrived at key `0`, `read` and `write` were dropped by
  the merge, and a connection that declares its address only inside those lists (no top-level
  `host`) reached the connector with no host and no port at all. Laravel took its without-hosts
  path, the connector built `pgsql:dbname=''`, and libpq dialled its default address — a local
  socket, port 5432 — instead of the configured one, so every read outside every reader window
  failed with `SQLSTATE[08006] connection to server on socket "/var/run/postgresql/.s.PGSQL.5432"`
  while writes, which the framework merges itself, kept working: an application that serves
  `/health-check` and answers on the writer, and fails on the first read of an authenticated
  request. Both branches that fall back to the writer now narrow the write side to one entry with
  `pickUnweighted()` — the same pick the read pool already used, and the one the framework's own
  `getReadWriteConfig()` makes with `Arr::random()` — so a list of writers and a single write map
  resolve identically. The branch for a connection with no read list is fixed the same way, and it
  is a no-op without a write config. A merge that still produces neither `host` nor `unix_socket`
  now logs a `[WeightedDB]` warning rather than failing, because omitting both on purpose, to reach
  a local socket, is a legitimate configuration. The suite did not see any of this: both
  writer-fallback fixtures declare `write` as a single map, the one shape `assoc()` wraps
  correctly, and the new test declares the list shape instead.

## 0.1.0-alpha2 - 2026-09-27
Published without changelog - a mistake - will fix

## 0.1.0-alpha1 - 2026-09-27

### Added

- **`swrr.connection` names the connection the pgcat surfaces follow, for an installation
  whose PostgreSQL path is not `database.default`.** The pgcat gate, `PgcatConfigFlipper`,
  `/health/db`, `db:replica-status` and `db:doctor` all read `database.default` and treated
  it as the connection queries run on. That is wrong for an application that reaches
  PostgreSQL through a *non-default* connection — one named by its own config (say
  `app.default_api_connection`, env `API_DB_CONNECTION`) while `database.default` stays on a
  peripheral database — so the gate warned `pgcat will never act: connection "sqlite" uses
  driver "sqlite"` on a boot that was correctly configured, every boot. `swrr.connection`
  (env `SWRR_CONNECTION`) names the connection instead; left unset the package follows
  `database.default` exactly as before, so existing installations and their tests are
  unaffected. The resolution lives in one place, `Support\ActiveConnection::resolve()`, so
  the gate, the flipper's health snapshot and the doctor cannot disagree about which
  connection is in play.

### Changed

- **The pgcat mismatch sentence names the setting the connection came from.** It always
  said `… Set swrr.pgcat.enabled = false, or point database.default at the pgcat-fronted
  connection`, which sends an operator to the wrong key when the connection was chosen by
  `swrr.connection`. It now names whichever key decided the connection, and `db:doctor`'s
  banner reads `followed connection is …` (the JSON key stays `default_connection`, so a
  gate that already reads it keeps working).

### Fixed

- **The release script closed with a promise that does not hold on a package's first
  release — `Packagist picks the tag up from there`, printed before there is anything for
  Packagist to pick up.** A pushed tag publishes nothing until the package has been
  submitted at packagist.org, so the first release now says the submission comes first,
  and that every tag is picked up from there afterwards. Later releases keep the plain
  line, so the caveat appears on the run where it is news rather than on every one.
- **The closing note promised that Packagist would pick the tag up, and said nothing about
  what to do when it does not — which is the case a release can actually end in.** A tag on
  GitHub and a version on Packagist are two different events: a push that succeeded says
  nothing about whether a version was published, and the usual causes are a hook that did
  not fire or a package that was never submitted. Both exits now name the way to close the
  gap — trigger a crawl by hand — where before only the exit that left the push to the
  reader mentioned Packagist at all, and the one that had just pushed said nothing.

## 0.0.1-alpha1 - 2026-09-26

First working release. The package was ported out of the originating application
verbatim; the following defects came with it and are now fixed — along with one
found in the release tooling written alongside it. Each one is covered by tests.

### Fixed

- **An on/off setting written as something the package could not read as on or off was cast
  into a decision nobody heard about — and on `swrr.pgcat.enabled` that decision armed a file
  swap the operator had asked for the opposite of.** `swrr.allow_local_fallback`,
  `swrr.pgcat.enabled` and `swrr.pgcat.use_reload` were read with `ConfigValue::bool()`,
  whose docblock says what PHP's own rule does: `(bool) 'false'` is `true`. So `'false'`,
  `'off'` and `'no'` — the three ways an operator actually writes *off* — were every one of
  them read as **on**, and `'maybe'`, `''` and `2`, which are not on or off in any reading,
  were decided silently as well. On `swrr.pgcat.enabled` that is not a cosmetic slip: the
  switch arms a runtime file swap, so a typo in it made the flipper read `pgcat.toml`, write
  a variant over it, and signal `supervisorctl` — a change to a running database proxy, for
  the opposite of what was written. Each switch is now read from a closed list of spellings
  (`true`/`false`, `1`/`0`, `'on'`/`'off'`, `'yes'`/`'no'`, case- and space-insensitive), and
  a value that is none of them is **refused** rather than cast: it resolves to the value its
  own documentation prints, and every boot that sees it logs it at `error` level under a
  finding key of its own — `swrr.pgcat.enabled.refused`, `swrr.pgcat.use_reload.refused`,
  `swrr.allow_local_fallback.refused`. The rule, its candidates and the alternatives are in
  [docs/switch-values.md](docs/switch-values.md).
- **A finding could be replaced by another before it was ever logged.** `BootAudit::report()`
  folded its findings into a map — the record is keyed by finding key, and that key is what
  makes "still standing" and "resolved" comparable across boots — and only then logged the
  entries that survived, so when two findings arrived under one key the first was gone
  before anyone read it: a problem the installation has, reported by nobody, while the
  payload still looked complete, and with the key's recorded age quoted beside a sentence
  that had not stood that long. Neither of those can happen now. The fold happens *while*
  logging (`fold()`), so every finding a boot produced is logged in the order it produced
  them, and a shared key logs an extra `error` line naming the key, the two levels, how many
  findings the boot produced, and which sentence the record keeps: the louder of the two
  whichever order they arrive in — a monitor pages on that level, so it cannot be handed the
  quieter half — and the first when they are equally loud. The collision is logged and not
  remembered, because the record's entries are states an operator repairs and a defect in the
  package has no resolution sentence anybody could trigger; nothing about it stops a boot.
  The provider's own assembly, which is where a shared key would come from, is pinned in its
  richest misconfiguration: five findings, five keys, and no collision line among them. The
  reasoning, and the alternatives that were rejected, are in
  [docs/boot-audit-finding-keys.md](docs/boot-audit-finding-keys.md).
- **A boot's recorded findings could be replaced by another boot's before anything read
  them.** `report()` replaces the whole record, and that file is shared by every boot of an
  installation — which is what makes the probe cheap and this write contended — while the
  atomic rename it writes through only protects a concurrent *reader*. Two boots in flight
  together each write from the copy they read, so the later one discarded whatever the
  earlier had recorded: an entry nothing had taken out of the file, gone because the write
  never knew it was there. The record is now read once more as it is about to be replaced,
  and the entries another boot added — the ones this write does not carry — are named in an
  `error` line rather than dropped in silence. Reported, not refused, and not locked:
  refusing would leave the settings that boot checked unrecorded over a race, and a lock left
  behind by a killed worker would stop the record being written at all — the mechanism the
  store-probe decision already rejected for this file. The sibling hazard, two findings
  *within* one boot claiming one key, is `fold()`; this is the same loss across boots.
- **Weighted routing never ran.** Laravel chooses the read replica inside
  `Illuminate\Database\Connectors\ConnectionFactory::getReadConfig()`, using
  `Arr::random()` on the `read` list. `DatabaseManager` — the class the manager
  extends — has no read-selection hook at all, so the ported `getReadConfig()`
  override was unreachable and its `mergeReadWriteConfig()` call had no parent
  method to resolve to. The provider now binds `db.factory` to
  `WeightedConnectionFactory`, which delegates every read-PDO decision to
  `WeightedDatabaseManager::readConfigFor()`.

- **The formula config key was unreadable.** The provider read
  `swrr.default_$defaultWeightFormula` and cast it with `(float)` before passing
  it to `setDefaultWeightFormula(string)`, so `DB_WEIGHT_FORMULA` never took
  effect and every installation ran `linear`. The key is now
  `swrr.default_weight_formula` and the value stays a string.

- **Disagreeing factor defaults.** The provider fell back to a RAM factor of
  `0.5` and the manager to `0.8`, while the shipped config and the README said
  `3.375`. All three now say  `3.375` (`3.0` for CPU), and the provider docblock
  matches.

- **A scalar `swrr.reader_days` sent every read to the writer.** `.env` values
  arrive as strings, and the provider passed them through `(array)`, so `"3"`
  became `['3']` — which never matched `TimeWindowResolver`'s strict
  `in_array($dow, $readerDays, true)`. A bare scalar now means that single day.
  Windows and days are also normalised before they reach the resolver, so a
  malformed `reader_windows` entry can no longer disable reader windows — it is
  refused and reported instead, below.


- **`db:probe-replicas` could not run.** It called
  `WeightedDatabaseManager::getConfiguration()`, which does not exist, and
  `DB::connectUsing($config, Closure)` against the real signature
  `connectUsing(string $name, array $config, bool $force = false)`. The manager
  now exposes `connectionConfig()`, and each probe is a throwaway single-host
  connection that is purged afterwards — the pooled `read`/`write` lists are
  dropped so a probe cannot be routed back through the pool it is measuring.

- **`LocalStateStore` ignored the configured key prefix.** `poolKey()` hardcoded
  `swrr`, so local keys diverged from `RedisAtomicStateStore` whenever
  `SWRR_KEY_PREFIX` was customised. The prefix is now a constructor argument
  wired from `swrr.key_prefix`.

- **Silent degradation.** Falling back to the in-process store happened with no
  trace: no log, and `healthSummary()` still reported the primary store as if it
  were serving. Degrading now logs an `error` once per process (and a `warning`
  when the primary recovers), the fallback is a real injected store instead of a
  process-global `static`, `healthSummary()` reports `degraded` / `primary_store`
  / the store actually serving, and `LocalStateStore::name()` says
  `local(in-process, uncoordinated)` so `db:replica-status` and `/health/db`
  show it.

- **The store resolved the `Redis` facade before the application could bind it,
  and poisoned it for the whole process.** A connection is built while providers
  register (any application touching the schema in `register()` does that), which
  asks the store for a replica before `Illuminate\Redis\RedisServiceProvider` has
  bound `redis`. Resolving that unbound key does not fail — the phpredis extension
  ships a class called `Redis` and PHP class names are case-insensitive, so the
  container builds the extension class — and the facade caches whatever it is
  handed as its root. Every later `Redis::connection()` in that worker then threw
  `Call to undefined method Redis::connection()`, including from Horizon, queues,
  the cache and session drivers, and the boot audit could only report the store as
  unreachable with that artefact as its reason. The store now reaches Redis through
  the container (`Support\RedisAccess`), which checks `bound('redis')` before
  resolving, rejects a binding that cannot open connections by shape, and reports
  what it actually found.

- **A pick made before Redis existed counted as a store failure.** Three of them
  marked the store unhealthy and that worker served from the in-process store for
  the rest of its life, even once `redis` was bound. A store that cannot be
  resolved at all now throws `Exceptions\RedisUnavailable` instead: the caller
  still falls back for that call, the consecutive-failure counter is untouched, and
  one `warning` records it per process (under PHP-FPM every request would otherwise
  log the same line).

- **`db:doctor`'s store row was reporting a facade artefact as a bad server.** It
  probes through the same accessor now, so `the container has no "redis" binding
  yet` and `Connection refused [tcp://…]` are distinguished from each other — they
  are repaired in different places.

- **A flat string in `swrr.reader_windows` silently inverted the fallback.** The way a
  window is written by hand — `'10:00-14:20'` — is not the shape the resolver reads
  (`['start' => '10:00:00', 'end' => '14:20:00']`), and every entry that was not an array
  used to be dropped without a word. Dropping all of them left the resolver reading an
  empty list, which is its documented *permissive* mode: reads used the replica pool at
  every hour while the setting said the writer covered the margins, and nothing anywhere
  said otherwise. Those entries are now refused rather than interpreted or quietly
  dropped — `Support\ReaderWindows` names the entry and the shape it should have had
  (`reader_windows[0] is "10:00-14:20"`), and the whole value being a single string is
  reported the same way one level up. The boot audit logs the refusal at `error` level
  under the finding key `swrr.reader_windows.refused` — the first reader finding that is
  not a `warning`, so `BootAuditFinding` gained a `level` and `BootAudit::report()`
  honours it — and `db:doctor` gained a `reader windows` row that `FAIL`s on it, which
  makes `--strict` fail a deploy. Well-formed entries beside a refused one still apply,
  and the message says how many, so a typo costs the entry rather than the fallback.
  Nothing throws: an installation with a typo boots and serves, it just stops doing so
  silently. The decision and the rejected alternatives are recorded in
  `docs/reader-windows-refusal.md`.

- **A comma-separated `swrr.reader_days` silently inverted the fallback the same way.**
  `'1,2,3'` is how a list is written in `.env` and not the shape this setting reads — a
  day number, or an array of day numbers — and every entry that was neither an int nor a
  numeric string used to be dropped without a word. Dropping all of them left no day at
  all, and no day leaves the resolver in its documented *permissive* mode: reads used the
  replica pool all week while the setting said they stopped outside it, which is the
  defect above one key over. It is now refused the same way a flat `reader_windows`
  string is: `Support\ReaderDays` gained the matching `split()` classifier, which names
  the entry and its shape (`reader_days[1] is "1,2,3"`) and reports a whole value written
  as one string one level up, the boot audit logs it at `error` level under the finding
  key `swrr.reader_days.refused`, and `db:doctor`'s `reader windows` row `FAIL`s on it,
  so `--strict` rejects the deploy. A bare scalar stays accepted — `'3'` is one day,
  which can be read exactly one way — and a list whose other entries are days keeps them
  and says how many. What the resolver does with an unreadable day list is unchanged, and
  it is the permissive side: the refusal is a report, not a routing decision.

- **The reader audit reports every finding it can, not the first one it finds.**
  `readerFallbackFindings()` returned the first branch that applied, so a boot that saw two
  problems named one of them: a flat `reader_windows` string beside a comma-separated
  `reader_days` was one log line, and the second mistake waited for the next boot — or for
  whoever went looking through the log. It now collects them all, refusals first, under
  their own keys, and each is closed out by whichever boot sees its own fix. Two things are
  deliberately not two findings. A finding *key* is one finding — the record keeps one entry
  per key, and `BootAudit` now logs a collision instead of letting a second finding replace
  the first unread, below — and the two causes that meet at `always_writer` (no day
  in 1…7, no window that can ever be entered) are therefore one finding naming both. And a
  finding that does not apply is not reported: permissive mode decides the mode before a
  window is read, so when no day can be matched there is nothing about the windows to say.
  The day half of a double refusal also stopped claiming "no reader_windows are
  configured" when they were configured and refused — it now reports what the pair leaves
  behind.
- **`db:doctor`'s `replica metadata` row reported a pool that had quietly got smaller as the
  installation.** The row's question is "is the pool the read list describes", but it answered it
  from `replicaStatus()` — the pool the resolver *kept* — and the resolver reads a weight through
  `max(0, ConfigValue::int(…))` and a core count through `max(1, …)`. So `'weight' => 'heavy'` (a
  typo, a YAML slip, an unquoted `.env` value) was read as `0`, `0` is how a replica is disabled,
  and the replica left the pool: the row then printed `2 replicas on [pgsql], total weight 80` on
  an installation with three, with nothing to say which replica went or why — the exact failure
  the row exists to catch, reported as health. It now reads the *configured* replicas and fails,
  naming each replica (`[host:port]`, through the manager's own key) and quoting the value:
  `weight is "heavy", which the resolver reads as 0`. A cores or memory value is the same failure
  with the difference stated — the replica stays in the pool, weighted as something other than
  what the read list says — because the resolver substitutes for those rather than dropping the
  replica. The rule is the resolver's own arithmetic rather than a second opinion about it: a
  value that is not a number, or a number under the floor that key is read at (`weight` 0,
  `cpu_cores` 1, `ram_gb` 0). That makes three near-misses reportable for the first time: a
  negative weight, which `max(0, …)` clamps to the disable value its author never wrote; a
  `weight: 0.5` that `ConfigValue::int()` truncates to 0; and a `weight: null`, a key present with
  nothing in it, which no longer reads as "no metadata" — the absent case is still the row's
  warning. `weight: 0` itself is the one difference the package means, so that replica does not
  fail the row; it is **named**, because "1 replica, total weight 10" on an installation with two
  is the same quiet shrink through the front door. The row also stops blaming the symptom when
  every replica's weight is unreadable: `every replica resolves to weight 0` was true and useless
  there, since "every replica is drained" and "every replica's weight is a typo" are one picture
  without the values. Eight tests, one of which pins exactly that — the unreadable value is named
  and the symptom sentence is not.
- **`bin/inventory.php --check` described a file that was already right, and named the wrong
  cause when the rows were right but the bytes were not.** Two faults in the one report that
  exists to catch drift, both found by writing down what it is supposed to say. The verdict is
  about the *pair* of files — `syncInventory()` fails the pair when either differs — but the loop
  that explained it read both files out again and described each one, with no test of whether
  that file had actually differed: a tree with one stale row printed `files.tsv: differs only in
  line endings (rewrite it to pin LF)` beside the real finding, sending a reader to open a file
  with nothing wrong with it. And a file whose rows were all correct was reported as a line-ending
  difference, which is impossible by the time that message is reachable: `--check` normalises CRLF
  before comparing (so a CRLF checkout is *current*, as it should be), which leaves only blank
  lines and trailing space to explain a bytes-only difference. The report now skips the files
  that match the document it is comparing against — compared exactly the way the verdict compares
  them, so the two cannot disagree — and the one message that is left says what it actually is:
  `the rows are right but the bytes are not: a blank line or a trailing space somewhere`. Sixteen
  tests cover the generator for the first time: drift caught and named per file, a missing file
  reported as missing rather than described, a stamp naming another tag, a CRLF checkout accepted,
  the discarded-change warning with its three-bullet cap, and the three exit codes.

### Changed

- **`db:replica-status` says when the boot audit is not registered, instead of printing
  nothing.** The health payload has always answered `available: false` — with `error` null —
  for an installation that has no boot audit, while the command, the other surface that
  publishes the record, printed no `Audit:` line at all. The two could therefore disagree
  about an installation, and the terminal could not tell *no audit registered* from *a record
  with nothing on it*: silence was the answer for both. One accessor now settles it,
  `BootAudit::reported()` — the block the payload embeds, unchanged, and the single place
  either surface decides whether there is a record to read — and the command renders its four
  states from it: findings standing, nothing standing, `not registered — no record is kept,
  so no finding is reported here`, and `unreadable`. The block used to be assembled in the
  controller while the command asked the container for itself, which is exactly how one state
  can arrive on one surface and not the other; the field meanings, including the two ways
  `available` is false and why `error` is present either way, are now documented on the
  accessor rather than twice. Nothing else moved: the payload is byte-identical, no exit code
  or HTTP status changed, and the line names a state rather than a fault — an installation
  that registered the manager without the audit is not broken, it is simply not being
  watched. Two tests pin it, the same state through both surfaces and an empty record told
  apart from no record. The reversal it records — this was a documented limitation, with
  silence as the accepted cost — is in
  [docs/boot-audit-surfaces.md](docs/boot-audit-surfaces.md).
- **The pgcat rows print the config line to paste, where the fault reduces to one.** `pgcat gate`
  and `pgcat supervisor` already named their repairs — the mismatch sentence says `Set
  swrr.pgcat.enabled = false, or point database.default at the pgcat-fronted connection`, and the
  unquoted-program sentence says `Write supervisorctl signal HUP "pgcat:*"` — but a sentence is
  the only place a report could put one, and a `--json` gate cannot select a clause out of
  `detail`. Both rows now carry a `suggestion` line in the shape the config file uses:
  `swrr.pgcat.enabled = false` for the switch armed where pgcat cannot act, and
  `swrr.pgcat.<restart|reload>_command = '<command>'` for the two supervisor faults that reduce to
  one — an unquoted program name (where the value is the command `SupervisorStep` itself built,
  with the name quoted) and a command left empty (where it is the command that setting documents).
  `pgcat files` prints none, and is the only row in the command that never does: every problem it
  reports is a path or a permission, the right value is whatever this installation's pgcat and
  supervisor actually use, and a plausible-looking path pasted into configuration would replace
  the operator's intent with this tool's guess. The sentences are unchanged — the flip's refusal,
  `--dry-run` and `/health/db` read them and have no suggestion column — so the row prints the
  fault for reading and the line for acting, and the two agree. The decision, and the candidates
  including naming a path default and asking supervisor for the program it does know, are in
  [docs/pgcat-suggestion-lines.md](docs/pgcat-suggestion-lines.md).
- **The sample config no longer casts the two `.env`-driven switches.**
  `(bool) env('SWRR_PGCAT_ENABLED', false)` became `env('SWRR_PGCAT_ENABLED', false)`, and
  `allow_local_fallback` likewise. This is what makes the refusal above reachable at all: the
  cast sat upstream of the package, so an installation that set `SWRR_PGCAT_ENABLED=off` had
  already been turned into `true` before any reader could see it — and no downstream decision
  could tell the typo from a deliberate on. `env()` already answers a real bool for
  `'true'`/`'false'` and passes everything else through as written, which is precisely what
  the switch reader wants, so nothing about the documented defaults moved.
- **`PgcatConfigFlipper`'s absent-key fallbacks now match the values `config/db-manager.php`
  ships.** The flipper fell back to `enabled => true` and `use_reload => false` when those
  keys were absent, while the published config prints `false` and `true` for them. Writing the
  refusal's sentence exposed the disagreement: it has to say *which* value the switch now
  holds, and "the value this setting documents" would have been false against the file the
  operator is reading. Both now fall back to the shipped value, which is only reachable for a
  flipper built without those keys — an installation whose published config predates the
  `pgcat` block, or a hand-built one — and in both directions it is the safer and the gentler
  choice: an installation with no pgcat configuration block is now inert rather than armed
  against paths it does not have, and a flip reloads rather than restarting when `use_reload`
  is absent.
- **`db:doctor` has an eleventh row, `switch values`.** The preflight half of the refusal
  above: it fails when any of the three switches is written as something that is not on or
  off, names every one it refuses rather than the first, dates each from *its own* finding
  key, and prints no `suggestion` line — re-spelling `'flase'` would be guessing at what was
  meant, which is the mistake the refusal exists to prevent. On a pass it still says something,
  naming the value of each switch, because "no refusals" is worth reading. The `reader windows`
  row and this one now share the method that builds them (`datedRow()`), so the two cannot
  make their claims about verdicts, suggestions and dates in two different ways.
- **`WeightedDatabaseManager::replicaKey()` is public.** It was already *the* spelling of "which
  replica" — the health monitor keys failures by it and `replicaStatus()` prints its two halves —
  and the preflight needs it in the one case the status table cannot answer: a replica the pool has
  dropped, which is precisely the replica the `replica metadata` row now names. A second spelling
  of a replica's identity in the doctor would have been a second thing to keep in step with the
  health record.
- **`db:doctor`'s `reader windows` row names every reader problem it finds, and dates each
  from its own finding.** The row reported the problem its code reached first and stopped,
  so an installation that had written both `reader_windows` as a flat string and
  `reader_days` as `'1,2,3'` heard about the days only on the *next* preflight — a second
  deploy for a sentence that fitted on the first row, and a report that disagreed with the
  boot audit, which has always been logging both. The row now names every problem it finds,
  in the audit's order, with the verdict being the loudest problem in the list (a refusal
  beside a warning is still a `FAIL`) and one `suggestion` line per repair, since two
  refused settings have two replacements to paste. Each problem is also dated from *its own*
  finding: the record holds one entry per finding key, so `recorded unresolved since …` is
  looked up for the problem being reported rather than for the first entry on file — which
  would have answered "since when" with another problem's date, a claim about when the
  installation started being wrong made about the wrong thing. `recordedFinding()` still
  reads one key for the rows that have one; the reader row reads the whole record
  (`recordedFindings()`) and looks each problem up by the key it came from.
- **`db:probe-replicas` now says in its exit code whether the sweep did its job.** The
  command marked what it found and then exited `0` whatever happened, so a scheduled run
  whose every probe failed — the case the command exists for — reported success to the
  scheduler. It now exits `1` when the sweep reached no replica at all, and `0` when it
  reached one, when the connection has no `read` list (nothing was asked of it), and on a
  *partial* failure, because marking a replica that is down is what the health monitor is
  for and the exit code of a scheduled job has to mean "the job ran". The two ways a sweep
  can come back empty are named separately in the run's closing line — `Nothing could be
  probed: the read list on [x] holds no replica array …` versus `No replica answered: 2
  probed, all failed — nothing was marked healthy.` — because they are repaired
  differently: one is `read` written in a shape that holds no replica, the other is
  replicas that cannot be reached. A `read` that is a single config map rather than a list
  of them is read as one replica, which is what routing already does with it: counted as no
  replica at all, such an installation — whose single replica serves every read — would have
  exited `1` on every scheduled run, and an exit code that is always non-zero is an exit code
  nobody reads.
- **`db:doctor --strict` now says when the flag is what failed the run.** A warned run
  under `--strict` exits `1` while every row printed before it is `PASS` or `WARN`, and the
  closing line used to read `No failures — review the warnings above.` — true of the rows,
  and useless next to a non-zero exit code an operator is about to act on. That line now
  names the cause it can see from the counts: `No failures — but --strict counts the 1
  warning above as failures: this run exits 1 as a release gate, not because the
  installation is broken.` The exit rule itself moved into one method, `gateFailed()`, which
  both the exit code and the summary read, so the sentence an operator gets beside the code
  cannot describe a different rule from the one that produced it. A run *without* the flag
  keeps the old sentence and asserts that it does not blame it.
- **A pgcat flip judges its supervisor command before it replaces the file, and puts the
  previous file back if the command fails anyway.** The flip used to end in two steps whose
  order created a state nobody can see: the variant was renamed over `pgcat.toml`, and only
  then did the flip find out whether the command that makes pgcat pick it up worked. When it
  did not, the new file stayed — disk on the new mode, the running pgcat processes on the
  old one — until the next unrelated pgcat restart, which came up in a mode no window asked
  for. `Pgcat\SupervisorStep::inspect()` is now one verdict for all of it: the flip refuses
  the swap on it (nothing copied, nothing run, no mode recorded, so the next poll retries and
  the flip goes through by itself once the command is fixed), `db:doctor`'s `pgcat
  supervisor` row reports the same sentence, and `--dry-run` prints it as a step. The known
  ways a command can be unusable are all caught before the file moves — an empty command, an
  executable that does not resolve, an unquoted program name the shell may rewrite, a program
  supervisord does not know, and a supervisorctl that never answers. A command that passes
  the check and fails anyway (a race, or a pgcat that will not come back up) is answered by
  the rollback: the previous variant goes back, so what is on disk is what pgcat is running,
  and the error says which of the three outcomes happened — put back, nothing to put back, or
  **not** put back, which is the one that needs a human. The check costs one read-only
  process per flip attempt, and only when a flip is about to happen. Both the ordering and
  the rollback are mutation-tested (moving the check after the copy fails five tests;
  dropping the rollback fails two).
- `db:replica-status` and `db:probe-replicas` default to the `pgsql` connection,
  matching `WeightedDatabaseManager::replicaStatus()`.
- `db:replica-status` prints the store that is actually serving, and warns when
  the process has degraded.
- `WeightedDatabaseServiceProvider::boot()` now calls `parent::boot()`, which is
  what points Eloquent at the manager (`Model::setConnectionResolver`) and hands
  models the event dispatcher.
- pgcat flipping is now PostgreSQL-only. The provider hands `PgcatConfigFlipper`
  the current connection (`database.default`) and its driver, and the flipper
  disables itself for any other driver: `status()['enabled']` is `false` while
  `configured_enabled` still shows what `swrr.pgcat.enabled` says, and both
  `applyCurrentState()` and `forceMode()` return a no-change result that names
  the offending driver — before the lock, the file swap and supervisorctl. A
  MySQL, MariaDB, SQLite or SQL Server installation therefore needs no pgcat
  wiring at all. `status()` also gained `connection`, `driver` and
  `driver_supported`, and `db:pgcat-flip --status` prints them.
- pgcat applicability is now reported on every health surface from one place.
  `PgcatConfigFlipper::healthSummary()` is that place — `status()` spreads it, so
  the two cannot drift — and `/health/db` embeds it as a `pgcat` block while
  `db:replica-status` prints it as a `Pgcat:` line. `DatabaseHealthController`
  takes the flipper as an optional constructor argument, so it still resolves (with
  a `null` block) when the provider is absent. A flipper that cannot act does not
  make `/health/db` unhealthy: on a non-PostgreSQL connection there is nothing to
  flip.

- Static analysis tightened from PHPStan level 6 to **level `max`** over `src`,
  with no baseline file and no ignore entries — all 124 level-10 findings are
  fixed rather than silenced. `STATIC-ANALYSIS.md` records the staged burn-down,
  the measured per-level numbers and the behaviour changes that came with it.
- Config reads are narrowed instead of cast. `Support\ConfigValue`
  (`string`/`int`/`float`/`bool`/`assoc`/`assocList`) keeps PHP's cast semantics
  for scalars but returns the caller's default for anything else, so an array in
  a config file no longer becomes the literal `"Array"`. The manager collapsed
  nine repeated casts into `weightTunables()`, the provider's container closures
  are typed (`function (Application $app)`) and resolve the config repository
  through `$app->make(Repository::class)`, and Laravel's own untyped seams
  (`DatabaseManager::configuration()`, `ConnectionFactory::getReadConfig()`) are
  wrapped where the package promises `array<string, mixed>`.
- A `db.factory` binding that is not a `WeightedConnectionFactory` now raises a
  `RuntimeException` naming the binding when `db` is resolved, instead of a type
  error from inside the constructor. Weighted routing only happens inside the
  factory, so a host app that replaces the binding has to be told.
- `WeightedDatabaseManager::healthSummary()`'s docblock now declares the real
  `replicas` array shape — it said `list<array{...}>`, which left every field
  `mixed` for static analysis and for anything reading the summary.
- The supported PHP version is now one number in every place that states it.
  `composer.json` keeps `"php": "^8.4"` as the floor, the README requirement
  reads 8.4+ instead of 8.2+, the CI matrix runs 8.4 only (its 8.2 and 8.3 legs
  could never have got past `composer install`), and `phpstan.neon.dist` pins
  `phpVersion: 80400` so analysis cannot start depending on APIs newer than the
  floor.
- Releases are tag-driven. `composer release` (`bin/release.php`) derives the
  next version from the latest git tag — `0.0.0` when there are none, so the
  first release is `0.0.1` — promotes the CHANGELOG's `## Unreleased` heading to
  `## X.Y.Z - date` with a compare link, updates the branch alias when a release
  opens a new line, then commits and tags. `--dry-run` prints the whole plan and
  writes nothing. It refuses to run on a dirty tree, off the release branch, for
  an existing tag, for a version that is not newer, or with nothing to promote.
  There is still no version in `composer.json` and no version file: the tag is
  the version. `RELEASING.md` documents the policy and the safety rails.
- `extra.branch-alias` maps both dev lanes to the line being developed: `dev-main`,
  which holds releases, and `dev-dev`, which holds the prereleases cut ahead of them.
  They are two installable names for one line, so a consumer requiring the branch
  gets a version constraint that resolves either way, and the release script keeps
  both of them on that line when a release opens a new one.
- CI pins the root package version. `actions/checkout` now uses
  `fetch-depth: 0` and the workflow exports `COMPOSER_ROOT_VERSION` for the
  branch under test, so Composer never falls back to its `1.0.0` guess and the
  "could not detect the root package version" warning is gone. The dependency
  cache key hashes `composer.json` instead of a `composer.lock` this library
  does not commit, which was freezing the cache forever.
- CI runs for both lanes and for `v*` tags, and runs the whole gate. Both lanes are
  released from — a version with no suffix from `main`, a prerelease from `dev` — and
  a `branches` filter on its own excludes tag refs, which left every published
  version with no CI record of its own. It runs `composer checks`, the same eight
  checks a release is cut behind locally, rather than the three `composer test`
  covers. On a tag build the version pin stands aside: there the ref name is the tag,
  so it would export `dev-v0.0.1-alpha1` — a string Composer accepts and which means
  nothing — and override the version the build is actually about.
- Local checks run through one script: `composer checks` (`bin/checks.php`) runs
  `php -l` over every file, an independent AST parse of the same files,
  `composer validate --strict`, `check-platform-reqs`, the workflow YAML,
  PHPStan, Pint and PHPUnit, prints a single pass/fail summary and exits
  non-zero if anything failed. `composer test` still runs exactly the three
  gates CI enforces. The script invokes each tool as
  `PHP_BINARY <vendor entry file>` rather than through the `vendor/bin` shims,
  so the same command works on Windows and Unix, and reports an uninstalled
  tool as a skip instead of failing the run.

- **A release is weighed, not declared.** `composer release` computes the bump from the
  changes instead of taking `--patch`, which is gone: passing it now exits `2` with a
  pointer to `--weigh`, the flag that reads the Unreleased notes, the commits since the
  last tag, the public surface of `src/` and `config/`, and the inventory the last release
  wrote, and takes the loudest bump they call for. `--minor`, `--major` and
  `--version=X.Y.Z` still declare the bump, but are now refused when they undersell what
  the changes ask for — shipping a breaking change as a patch is the accident this policy
  exists to prevent — while overshooting is allowed with a note, and `--ignore-policy`
  stays the loud way past it. Every signal can only raise the bump and never lower it, so
  one that goes quiet costs a second opinion and nothing else. The plan prints the
  weighing, so the reason a number was chosen is read rather than guessed; the policy
  itself, the four signals and the inventory's stale-is-skipped rule are written out in
  [RELEASING.md](RELEASING.md). The weighing and the gate are pinned against a real
  repository with real tags: each signal that can raise a bump, the 0.x softening, the
  stale-inventory skip and every way a declared bump can undersell the changes are cut as
  releases in a temporary package and read back out of the plan.

### Added

- **The release script can cut prereleases, and refuses the spellings Composer cannot read.**
  A version may now carry a suffix — `-alphaN`, `-betaN`, `-rcN`, or a bare `-dev` — and
  those are exactly the forms Composer's own parser accepts. `0.0.1-dev.1` is *not* one of
  them: SemVer would take it, Composer rejects it, and a tag Composer cannot parse fails
  **quietly** rather than loudly — it never becomes a version anybody can install. So it is
  refused where it is typed, with exit code 2 rather than 1, instead of being written and
  discovered later. The dot is refused for the same reason: Composer reads `-alpha.1` and
  `-alpha1` as one version, so two tags carrying the two spellings would be one version
  published twice. A numbered dev lane is therefore spelled `-alphaN`, because `dev` takes
  no number at all.
  The suffix also decides the branch, in both directions: a prerelease precedes the release
  it is named after, so it is cut from `dev`, and a version with no suffix is cut from
  `main`, with `--branch` overriding either. Promotion is unchanged — the notes move, a fresh
  `## Unreleased` is left above them, and the inventory is stamped with the prerelease tag —
  because a prerelease is a version like any other. A refusal on a tag that already exists
  now also names the next free number in that lane, counted from the tags rather than from
  the number that was typed, so a refused dev tag does not become a guessing game. Pinned by
  `tests/Unit/Release/PrereleaseTest.php`, whose last test asks Composer's own parser whether
  every form this script writes is one it accepts.
- **The boot log names the severity of what it reports, so a log-based monitor can page on a
  refused value without polling `/health/db`.** The audit's whole reason for having two levels
  is that they are two claims — a refused value is input the installation is running without,
  worth waking somebody for, while a setting that reads as on and cannot act is a feature
  quietly not applying — and the endpoint publishes that choice as one field, `severity`. The
  log did not: the level was only in the channel's own record, where how it is spelled belongs
  to whatever handler is configured (`production.ERROR` in the default line log, `level_name`
  under a JSON formatter, a priority number in syslog), and where `ERROR` is every error the
  application ever logs. Every line `BootAudit` writes now carries `severity` in its context,
  beside the `finding` key, holding the level it was written at — so a refused value is
  selected with `severity == "error"`, the same comparison the endpoint answers, and the
  finding is logged on every boot while it stands, so no state beyond the last line per key is
  needed. The line that closes a finding is written at `warning` whatever the finding stood
  at, with `resolved: true`, so a pager stops on the very line that repairs it instead of
  being handed one more `error`; the resolution keeps being a transition, and the level it
  cleared at stays in the record and in the lines it wrote while it stood. The line's own
  level wins the key, so a finding cannot make its line disagree with the loudness it was
  logged at. The rule, its four lines and the seven alternatives — including naming the key
  `level`, carrying the closed finding's level on its resolution line, and a dedicated channel
  — are in [docs/boot-audit-log-severity.md](docs/boot-audit-log-severity.md).
- **`Support\SwitchValue` — an on/off setting read as the value it was written as.** The
  classifier behind the fix above: `read()` returns the switch and the value that could not be
  read, `resolve()` answers the same question without a default (for a report that only wants
  to know whether a value is readable), `ACCEPTED` is the shape every refusal quotes, and
  `describeRefused()` turns settings into the one clause a log line and a table cell both
  print. It knows nothing about which setting it is describing — a default is a fact about a
  setting, not about switches — so each caller passes the value its own documentation prints.
  Its contract is pinned in `tests/Unit/Support/SwitchValueTest.php`.
- **`db:pgcat-flip --json` — one object on stdout, for a pipeline to assert on instead of
  parsing the rendered report.** A CI job that runs a rehearsal had to match `would flip` out of
  a sentence that carries colour tags and wraps at the terminal width, and read the code from a
  second place; it now gets both in one object, with each route of the command reporting rather
  than only the rehearsal. The keys are fixed and always present — `null`, or an empty list,
  where a route has nothing for one — so a rule never has to guard for a missing field, and
  `exit_code` is inside the object because a job that saves the report should not have to
  reconcile it with a number from somewhere else. `kind` is the verdict, from one vocabulary:
  a flip's (`flipped`, `no_change`, `skipped`, `failed`),  a rehearsal's (`would_flip`,
  `would_not_flip`), or one of the four the command answers without asking the flipper
  (`status`, `disabled`, `refused`, `unbound`, drawn from this command's own `KIND_*`
  constants). `steps` is a rehearsal's pipeline with each step's outcome, which is the evidence
  the verdict rests on; a `status` report carries the flipper's own array rather than the
  table's `(not set)` substitutions, because a machine has to be able to test a path for being
  unset. The flag moves no exit code and touches no file: the whole exit matrix runs twice,
  once rendered and once as an object, and asserts the code, the target's bytes and the state
  record for every cell. `--json --watch` is refused the way `--dry-run --watch` is — a report a
  machine reads is one object, and a daemon would print one every interval without ever
  reporting a verdict for the run. The kinds and their codes are documented in the README and
  read back out of it by
  `DbFlipPgcatCommandTest::test_every_json_kind_is_documented_with_its_exit_code`, the same
  guard the exit tables have. The decision, and the eight candidates including NDJSON per pass
  and a `--format` flag, are in
  [docs/pgcat-flip-json.md](docs/pgcat-flip-json.md).
- **`db:doctor --json` — the preflight as one object, so a deploy gate asserts on rows,
  verdicts and suggestions instead of parsing the table.** The doctor is the command a release
  pipeline is most likely to run, and it was the one that could only be read by a person: ten
  rows of prose with a colour-coded verdict in the left column, a `suggestion` line under the
  rows that carry one, and a closing sentence explaining the code. `--json` is the same flag,
  envelope and rule `db:pgcat-flip --json` keeps — one object on stdout and nothing else, a
  fixed key set, the code inside it — so the package has one machine-readable contract rather
  than one per command. Every row is a `checks` entry with its `name` (the row's identity, and
  the same string the table prints), its `verdict` (`PASS`, `WARN` or `FAIL` — the table's own
  three strings rather than a second vocabulary for the same fact), its `detail`, and its
  `suggestions`: the repairs a row offers, which until now existed only as a line standing in
  the renderer's name column. `counts` carries the tally the summary line prints, and the run's
  `verdict` is the loudest row rather than a second opinion about the run — deliberately not
  read back out of `exit_code`, because under `--strict` a warning exits `1` while every row is
  `PASS` or `WARN`. That is the one case where the two disagree, and the fields that join them
  travel beside both: `strict` and `counts`. The flag moves no exit code and runs no different
  checks: the whole exit matrix is asserted twice, once per report, with the object's counts,
  code and row list read against the numbers the rendered matrix asserts for its rows — and a
  test reports a single installation both ways and compares the two row lists to each other,
  which is the claim the matrix cannot make by comparing both against one provider. The verdicts
  are documented in the README and read back out of it by
  `DbDoctorTest::test_every_json_verdict_is_documented`, the same guard the flip's kinds table
  has. The decision is in [docs/db-doctor-json.md](docs/db-doctor-json.md).
- **The flip's exit code is pinned the way the other two commands' are, and documented as a
  table.** `db:pgcat-flip` is the third command whose exit code is computed from more than one
  condition — which branch a run takes is decided by the guard, a refused flag combination, the
  flipper's kind and whether it is armed at all — and its matrix stopped short of two states that
  now have rows: the container answering the flipper's name with something that is not a flipper
  (`1`), and a rehearsal of a forced flip, which is the flip `--force-mode` would have applied
  (`0`). Its mapping was prose in the README; it is now the same table shape as
  `db:probe-replicas` and `db:doctor`, read back out of the README by
  `DbFlipPgcatCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`, with
  `DbFlipPgcatCommandTest::documentedSituations()` as the link between the sixteen cells and the
  eight documented cases. `--status` winning over a rehearsal asked for in the same run gets a
  test of its own, because its only evidence is the line the other branch did not print.
  `docs/documented-exit-codes.md` now also records which of the package's four commands has a
  multi-condition exit and what pins each: `db:replica-status` has one condition, and no matrix.

- **The exit-code matrices now assert themselves against the README's own tables.** Each
  matrix pins what its command does, and the table an operator schedules against was
  maintained by hand beside it — so the enforced rule and the documented rule could disagree
  with nothing to notice. `tests/Support/Readme.php` reads a table out of README.md as data
  (heading, then the first table under it with the column a caller names), and each matrix
  gains a test that asserts every cell's code against the number the table names for the case
  that cell is an instance of, in both directions: a documented case with no cell behind it
  fails, and a cell that does not name the documented case it belongs to fails. The probe
  matrix declares the correspondence as a map (nine cells, five documented cases), the
  doctor's derives it from the counts it already asserts — did anything fail, did anything
  warn — against the table's three rows. Reverting a table cell, adding a documented case,
  rewording one, or dropping one cell's assignment is a failing test, not a silent drift.

- **`db:doctor` prints the repair for a refused `reader_windows` / `reader_days`, not only
  the shape it should have had.** The row named the mistake and described the accepted
  shape, which left the operator to translate one into the other — and for `reader_days` the
  accepted shape is an array that a sentence can only describe. The row now prints the exact
  setting line to paste, under the row and never counted as a check:
  `suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]`.
  `ReaderWindows::suggestion()` and `ReaderDays::suggestion()` re-spell only a value that
  names its own replacement — the flat range, the comma-written day list, a window written
  without its outer list, and well-formed entries repeated exactly as written — and answer
  `null` for everything else, so no plausible-looking repair is printed for an overnight
  range (`'22:00-06:00'`, which this resolver cannot express), an unparseable bound
  (`'9:00-14:20'`, which normalises to midnight) or a name (`'mon'`). The verdict, the counts
  and the exit code are unchanged: the suggestion is the value re-spelled, not a fourth
  verdict. Which values get one is pinned by data providers in `ReaderWindowsTest` and
  `ReaderDaysTest`, and the printed line by `DbDoctorTest`.

- **The health payload's audit block is now alertable without reading it.** A finding's
  `level` is only usable by a consumer that walks the list and compares strings, and the
  distinction an alert actually needs is the one the levels already carry: a value the
  package *refused* is malformed input the installation is running without, while a setting
  that merely reads as on but cannot act is a feature quietly not applying. The block now
  carries `severity` — the loudest level standing, one of `error`, `warning` or `none`
  (`BootAudit::SEVERITY_*`) — and `counts`, the number of findings at each level with both
  keys always present, so a monitor can page on `severity == "error"` and open a ticket on
  `severity == "warning"` in one comparison each. A level outside those two — a hand-edited
  record can hold anything — is counted as the quieter one, the same rule that keeps an
  entry written before levels were kept from reading as an error it might not have been.
  `error` is now always present as well, `null` whenever a record was read, which is what
  tells the two reasons for `available: false` apart: a provider that is not registered
  (`null`) from a record that could not be read (the message).

- **Both schedulable commands now have their exit code pinned as a matrix.**
  `db:pgcat-flip` and `db:probe-replicas` are run by a scheduler, so their exit codes are
  contracts with whatever runs them — and what is true of a code is only what somebody
  wrote down. Each gets a data-provider test that builds every state from a real fixture
  (a pgcat installation in a temp directory for the flip, a connection that really answers
  a probe for the sweep) and asserts, per row, the code, the line an operator reads beside
  it, the sentence the run must *not* claim, and what the row left behind: the target
  file's bytes and the mode record for a flip, the `host:port` results that reached the
  health monitor and the throwaway connection's purge for a probe. `tests/Support/PgcatInstallation.php`
  builds the first fixture, `tests/Support/FakeWeightedManager.php` the second — the real
  manager with the calls a probe reports through recorded, so the probe still really
  connects and really runs its `SELECT 1`. The flags that decide a run without performing
  it (`--dry-run`, `--force-mode`, `--status`, `-v`) are rows too, as are the runs that never
  reach the work at all, which are asserted on what the work was asked for.

- **A `store probe` row in `db:doctor`, for the check that can never run.** The store
  reachability finding is produced by a boot-time `PING` that is throttled by the audit
  record, and two states skip it in silence: `swrr.audit.store_probe_seconds = 0`, and
  an audit record that cannot be written (the record *is* the throttle, so without it
  the audit skips the probe rather than issue one per request). In both, no store
  finding is produced or resolved, and the reachability row's `PASS` means "this boot
  did not check" rather than "the store answered". The new row reports the interval,
  the record path and its writability, and where that leaves things: `PASS` when a
  probe can run, `WARN` when probing is switched off or the primary store is in-process
  (moot, not missing), `FAIL` when the record cannot be written. A row that can be about
  more than one of those names all of them rather than the first it reaches — switched
  off *and* unable to write the record is one deploy's worth of reading, not two — and
  takes the loudest as its verdict, so a choice that has been made unwritable reads as
  the failure it is. The interval and the
  writability come from `BootAudit::storeProbeStatus()`, the same source
  `storeProbeDue()` reads, so the row cannot disagree with the runtime about whether a
  probe happens; `WeightedDatabaseServiceProvider::storeSelection()` is public for the
  same reason.

- **`db:doctor`'s `pgcat gate` row reads the boot record, not only its own verdict.**
  The row judged the configuration in front of it, so a preflight could call an
  installation clean while the record standing on disk said a mismatch had been seen
  and never closed out — and when the gate was checked with `pgsql` while
  `database.default` was mismatched, it reported a state the installation was not in.
  It now reports both halves: its own verdict, and `recorded unresolved since …` for a
  mismatch an earlier boot logged, first-seen timestamp included, which is what
  distinguishes this deploy's own change from one it inherited. A record naming a
  connection the run does not inspect warns rather than passing silently, and
  `--strict` fails it. The finding key is public on the provider so the doctor reads
  the same record by the same name.

- **The boot-time pgcat warning now has a resolution half.** It logged the
  mismatch — `swrr.pgcat.enabled` on for a connection pgcat cannot front — and
  then nothing, so a log that reported the problem never said it was over.
  Correcting it means editing configuration, which is read once per process, so
  the boot that sees the fix is a different process: no static guard can tell it
  there is anything to resolve. The unresolved mismatch is now written beside the
  flipper's state file (`pgcat-flip-state.json` → `pgcat-flip-state-gate.json`, so
  it inherits that path's persistence), and the boot that finds the gate open while
  that record stands logs `Pgcat flipping is active, so the earlier mismatch no
  longer applies` and deletes it. A resolution is a transition: it is logged once,
  an installation that was never mismatched never writes the file and never claims
  a correction, and both writes are silenced and never fatal — `db:doctor`'s
  `pgcat files` row reports an unwritable directory where someone can act on it.
- **`db:doctor` can now prove the flip's last step works, without taking it.** The swap
  is two halves — the file and the command — and only the file was checked. The new
  `pgcat supervisor` row judges the command the flip will actually run
  (`PgcatConfigFlipper::supervisorCommand()`, the same method `swap()` uses): it resolves
  the executable the way a shell would (an absolute path, or a name searched on `PATH`
  with PATHEXT extensions on Windows), fails a pattern character in the program name
  that the shell may rewrite, and then asks supervisor — read-only — whether that
  program is one it knows, reporting its state when it is. The inspection runs the
  flip's own command with `status` in the verb position (`supervisorctl status
  "pgcat:*"`), so nothing is restarted, signalled or stopped; the tests pin that by
  asserting on the commands that reached the runner. All four ways the step fails —
  unresolvable binary, rewritten program name, supervisorctl that cannot answer,
  supervisor that does not know the program — are `FAIL`s, because each of them leaves
  the new file already in place. The row is moot while the flipper is not armed, exactly
  like `pgcat files`.
- **The shipped supervisor commands now name pgcat the way supervisorctl needs to hear
  it.** `supervisorctl restart pgcat` became `supervisorctl restart "pgcat:*"` — and the
  same for the reload — in the sample configuration and in the flipper's own fallback
  defaults. The name has to match the supervisor group or program the operator runs,
  and the quotes are what stop the shell from expanding the wildcard before supervisorctl
  receives it.
- **`db:pgcat-flip --dry-run` rehearses a flip instead of taking it.** The flip ends in two
  irreversible steps — the rename over `pgcat.toml` and the supervisor command — and the
  only way to know whether a window boundary would work was to let it arrive. The new flag
  performs every step that leaves nothing behind and reports the rest, so the answer comes
  before the window: the source config is read for real, the temp file the swap writes is
  written for real in the target's own directory (proving the one thing `db:doctor` judges
  from metadata and never proves) and removed again, the lock is taken and released, and the
  rename, the supervisor command and the state write are printed as what a flip *would* do.
  The state file is not written — recording a mode that was never applied would make the
  next real flip believe it had happened — but its writability is reported from metadata,
  because a flip's write to it is silenced and an unwritable one means a flip repeats on
  every poll. `--dry-run` exits `0` when a flip would happen and when there is nothing to do,
  and `1` only when a step a flip needs did not work, so a deploy can use it as a preflight;
  `--force-mode` combines with it, `--status` wins when both are passed, and `--watch` is
  refused because a rehearsal loop looks exactly like a flipping one.
- **The boot audit's standing findings are readable away from the log.** The record kept
  a finding's key and context, while the sentence explaining it existed only in the line
  each boot wrote — so an installation could carry a setting that reads as on but cannot
  act for weeks, with nothing but a log to say which one or why. The record now keeps
  that sentence and the level it was logged at, and `BootAudit::standing()` presents the
  findings oldest first, each with an age in words. `GET /health/db` carries them in an
  `audit` block (availability, count, oldest timestamp, findings) and `db:replica-status`
  prints the same list — including on its no-replicas path, because a finding is about
  the installation rather than one connection. Neither changes its own contract: the
  endpoint's `status` still means live reachability, since a configuration that cannot
  act is not a database that cannot answer, and the command still exits `0` because
  `db:doctor --strict` is the release gate. Records written before the level was kept
  still read, as warnings with no sentence. The surfaces that were rejected — a command of
  its own, a second endpoint, and a status code that would evict an instance over
  configuration — are recorded in `docs/boot-audit-surfaces.md`, next to the store-probe
  timing and the reader-windows refusal.

- **The release remembers what it shipped.** The tree at the last tag is written down as
  two TSV files at the package root — `files.tsv`, one row per file under `src/` or
  `config/`, and `methods.tsv`, one row per public method with its required and total
  argument counts and the shape of each parameter — and the next release reads them as a
  fourth versioning signal beside the notes, the commits and the tag diff. The release
  commit rewrites both, stamped with the tag it is creating, so the record names where it
  was taken; a file stamped with anything else is reported as **stale** and skipped rather
  than trusted, because believing a lazily refreshed inventory is exactly how a breaking
  change ships as a patch. What this covers is the case the tag diff cannot: a history
  with no useful tag to compare against still has a written list of the files and methods
  it had, so a rename or a signature that changed shape is seen rather than remembered.
  `php bin/inventory.php` regenerates both by hand — that is for looking, since the
  release script is the intended writer — and its `--check` compares without writing and
  is deliberately not a CI gate: an inventory kept in step with every commit can never
  witness a change, and witnessing one is the only thing it is for. The format is TSV so
  a row is one `explode("\t")` with no quoting rule to get wrong, and one symbol per line
  so a rename reads as two lines of `git diff`.

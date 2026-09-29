# What should a "why did the bump move" command report?

A design record for `bin/blame.php`, the command that answers about one symbol.

`php bin/release.php --weigh` decides the bump from four signals and prints all four with the
evidence behind them. That is the right shape for the question it is asked — *what is the next
version* — and the wrong shape for the question that follows it: *I changed one thing; did it
count?* A plan showing four signals and twenty lines of evidence is not an answer to that, and
reading it by eye is exactly the work a command should be doing. This document is about what that
command reports, and about the two rules it is built on.

Everything below is implemented in `bin/blame.php`, which shares its weighing with
`bin/release.php` through `bin/weighing.php`, and is documented for a reader in the [weighing
section of RELEASING.md](../RELEASING.md#which-signal-caught-it).

---

## The answer in one line

**Which signal names the symbol and at what severity, whether that signal is the one the bump
came from, and which of the two surfaces holds it at each end — all of it from the plan's own
weighing, with a plain `contains` for the match.**

---

## Why this needed deciding at all

### 1. The weighing is aggregate, and the question is per-change

The bump is a maximum over four signals, and the maximum is exactly what loses the information.
A tree can weigh a minor because of a `### Added` entry that has nothing to do with the method
just written; a tree can weigh a patch while the surface signal is reporting a renamed class
nobody looked at. The version number cannot tell those apart, and neither can the plan's summary,
because both are about the whole set rather than about one name in it.

### 2. "Named" and "responsible" are not the same

A `docs:` commit whose subject mentions `Configuration::boot()` is *named* — the commits signal
quotes it as evidence — and it is not *responsible*: the signal weighed a patch, and something
else weighed the minor that moved the version. If the report said "the commits caught this" and
stopped, it would be technically true and actively misleading, which is the failure mode worth
designing against. So the two are separate sentences, and the second one names what did carry the
bump.

### 3. A quiet signal has three meanings, and only one of them is a miss

A signal that names nothing may be saying that the symbol does not exist, that the symbol exists
and nothing about it changed, or that the signal never ran at all — the public-API signal has no
base without a tag, and the notes signal has nothing to read without a `## Unreleased` heading.
All three look identical in a list of `quiet` lines, and the answer a contributor needs is
usually the second: they changed a method body, the surface did not move, and the honest answer is
"nothing that reads this package's shape had anything to say about it". So the report asks the two
surface maps directly rather than inferring presence from the evidence.

---

## The candidates

| # | shape | what it costs | verdict |
|---|---|---|---|
| A | print the plan and let the reader grep it | the plan is the aggregate the question is trying to escape; every question re-renders twenty lines to answer one, and the reader still has to know which of the four lines is theirs | **rejected** — it is the status quo with a wrapper |
| B | read the query as a pattern, or as a fully qualified name with `::` parsed out | a query is typed from memory, and a regex is a footgun nobody asked for; parsing a name into class-and-member means guessing at the spelling being asked about, which is the thing in doubt | **rejected** — `str_contains` is predictable, and the report can afford to be dumb |
| C | re-derive the weighing inside the command | the two commands would be two policies: a change to how the surface is diffed, or how the notes are weighed, reaches one and misses the other, and the disagreement would be invisible until a release took the wrong bump | **rejected** — the shared half moved to `bin/weighing.php` instead (below) |
| D | search only the signals' evidence | a symbol that is at the last tag and unchanged since it produces no evidence at all, so it would be indistinguishable from a symbol that does not exist — and "nothing changed about it" is the answer most questions deserve | **rejected** — the two surface maps are read as well, and the diagnosis is derived from them |
| E | keep `bin/release.php`'s preconditions | a question about one symbol is asked mid-edit, on a dirty tree, on a branch that is not the release branch, with no CI run for the commit yet. Every one of those would be a refusal in the middle of a question that has nothing to do with releasing | **rejected** — no preconditions, and it changes nothing on disk |
| F | one shared weighing, evidence matched, maps read (chosen) | the command can only be as good as the weighing it shares, and the shared half is one more file to keep in step with the release flow | **chosen** |

---

## The rule the two commands share: one weighing, not two

Candidate C is the reason `bin/weighing.php` exists. `parseVersion()`, `bumpFor()`, `weigh()`, the
four readers, `surfaceSignals()` with its two halves, `inventorySignal()`, and the changelog
section helpers moved out of `bin/release.php` **byte for byte**, and no function name is declared
in both files. Three consequences are deliberate:

- **The plan and the blame cannot disagree about the bump.** `bin/blame.php` calls the same
  `weigh()` with the same arguments — the same CHANGELOG body, the same tag, the same base — so
  "this signal carried the bump" is a statement about the release decision rather than a second
  opinion about it.
- **The extraction is not a refactor anyone has to trust.** The moved block was verified by its
  first line, its last non-blank line and the line that followed it, and the two files were
  compared for duplicate function names; `php -l` and the whole suite are the gate.
- **What stayed in `bin/release.php` stayed for a reason.** `bump()` and `aliasFor()` are about
  numbering a release rather than about weighing one, so they are the command's; so are the
  arguments, the preconditions, the plan and the tag. The split is *what a weighing is* versus
  *what this command does with one*, which is the line that keeps the shared half from growing
  back into the release script.

Two helpers were added to the shared file rather than duplicated into the newcomer:
`workingSurfaceAll()` and `taggedSurfaceAll()` read both halves of the surface at once, and
`surfaceParts()` answers "what is the surface" once — the weighing diffs it half by half and the
blame asks the same halves whether they hold one name.

---

## The reading: maps, not evidence

The diagnosis paragraph is derived from the two surface maps, not from the evidence the signals
produced, and that distinction is the whole of candidate D. A surface signal's evidence only
exists for a symbol that *moved*; the maps hold every symbol at both ends, so they can say which
of the four things happened:

| The maps say | The report says |
|---|---|
| in neither | not a class, method, constant, case, property, config key or env var of this package — unless a line of evidence names it, which is a path, a commit subject or a stored inventory row |
| only at the tag | a removal, which the surface signal reads as breaking however the notes describe it |
| only in the tree | an addition, which is a minor and can never be breaking on its own; with no tag, there was no base to diff against at all |
| in both, same description | unchanged, so the surface and the inventory have nothing to say about it |
| in both, description moved | the shape changed rather than the presence, and the changed symbols are named |

Three smaller decisions came out of building it:

- **The notes are searched as entries, not as headings.** A heading is what weighs and the entry
  is what names the symbol, so both are reported: the heading as the signal's evidence, and the
  entry quoted with the heading it sits under. Without that, a symbol named in a `### Added` entry
  would come back as "the notes do not name this", which is the kind of confidently wrong answer
  that costs a tool its users.
- **A wrapped entry is joined before it is matched.** A changelog bullet that runs to three lines
  was first matched a line at a time, and a name on the third line was reported as a fragment
  starting mid-word — a quote that reads as a different bullet. Blocks are now built the way a
  reader sees them: one bullet, its wrapped lines joined, and a blank line closing it.
- **A long line is cut around the query, not at the head.** Fully qualified names are the case
  this is for: twenty-five bullets cut at a hundred characters show the namespace twenty-five
  times and hide the one part that differs. The window follows the query when the query does not
  fit, and stays on the head when it does.

---

## Tests that pin the rules

| Test | Pins |
|---|---|
| `BlameTest::test_it_names_the_signal_that_caught_a_symbol` | a name two signals share at the top severity: both are `caught`, the notes mention is quoted with its heading, and the contribution sentence names both |
| `BlameTest::test_a_signal_that_names_it_below_the_top_did_not_contribute` | named and responsible kept apart: the commits signal is `caught` at a patch, the bump came from the notes, and the report says so and lists what carried it |
| `BlameTest::test_a_name_no_signal_and_no_surface_holds_exits_one` | the exit-1 answer, and the paragraph for a name that is not a public symbol |
| `BlameTest::test_a_symbol_that_did_not_change_is_held_but_names_no_signal` | a symbol present at both ends that nothing moved is held by both maps and exits `0` — the answer that is not a miss |
| `BlameTest::test_a_removal_is_named_and_contributed_with_the_0x_caveat` | a removed constant reads as breaking, contributes, and the bump is the minor the 0.x policy asks for |
| `BlameTest::test_the_inventory_can_be_the_only_signal_that_names_it` | a stored row the tree does not back: the inventory alone names it, and the surface holds it at neither end |
| `BlameTest::test_a_changelog_with_no_unreleased_heading_is_reported_rather_than_read_as_quiet` | the third meaning of a quiet signal — nothing to read rather than nothing found |
| `BlameTest::test_quotes_a_leading_slash_and_a_trailing_pair_are_all_the_same_query` | the three spellings of one name reduce to one query, byte for byte |
| `BlameTest::test_a_fragment_finds_every_symbol_that_holds_it` | the match is a `contains`: half a name finds a method and a property together |
| `BlameTest::test_naming_nothing_is_a_usage_error_and_exits_two` | no argument is a usage error, not an empty report |
| `BlameTest::test_an_unknown_option_is_a_usage_error_and_exits_two` | an argument the command does not take is refused rather than ignored — `--weigh` especially, since it looks like it should change the answer |
| `BlameTest::test_two_names_is_a_usage_error_and_exits_two` | one symbol per run |
| `BlameTest::test_a_root_with_no_path_is_a_usage_error_and_exits_two` | `--root=` with nothing after it |
| `BlameTest::test_help_exits_zero_and_asks_nothing` | `--help` is a run with no report |
| `BlameTest::test_a_root_with_no_surface_is_not_a_package_and_exits_one` | a root with neither `src/` nor `config/` stops before weighing an empty tree |
| `BlameTest::test_root_answers_about_the_checkout_it_names` | `--root` is honoured, and the tag in the header is the one in the checkout asked about |

The extraction has no test of its own and needs none: every release test drives the real pair of
files, so a name that moved and was not shared, or a helper that landed in the wrong half, fails
the release suite rather than the blame suite.

Mutations these were measured against, each applied to `bin/blame.php` and run against
`BlameTest`: reading any naming as responsibility fails one test (the case where the commits
signal names it below the top), searching the notes as headings only fails one (the case where
the entry is what names the symbol), deriving the diagnosis from the evidence rather than from the
two maps fails two (both the unchanged case and the below-the-top case), dropping the two surfaces
from the exit code fails four, taking the name exactly as typed fails one, and ignoring an unknown
option fails one.

---

## Known limitations

1. **The surface is `src/` and `config/`.** A name in `bin/`, in `tests/`, or in the package's own
   prose is not a public symbol, so it can only be reached through the notes and the commits. This
   is the same boundary the weighing itself uses, and widening it here would mean the command
   answering about symbols the release decision cannot see.
2. **A `contains` match is not a qualified match.** Asking about `Thing` finds `Things`,
   `ThingFactory` and `Thing` together, and asking about a class finds every member of it. That is
   the intended behaviour — the query is a fragment — but it means a very short name is a sweep
   rather than a lookup, and the report says how many symbols it found rather than pretending to
   have found one.
3. **The head of a wrapped line is not shown.** Cutting a long bullet around the query loses the
   words before it. The alternative — printing the whole entry — makes a bullet that runs for
   three lines unreadable in a list of six, which is the shape this report is for.

---

## What would change this decision

- **A fifth signal.** `weigh()` returns a list, and both commands iterate it, so adding one to the
  shared file appears in the plan and the blame at once. What would have to change is the notes
  special case: the `CHANGELOG` source is asked for its entries as well as its evidence, and a
  second signal that weighs prose rather than symbols would want the same treatment. The place to
  generalise is `notesBlocks()` rather than the loop.
- **A query that has to be qualified.** If a `contains` match turns out to be too blunt in
  practice, the repair is a different *matcher* behind `names()` and nothing else — the report,
  the exit codes and the diagnosis are all written against the answer, not against the match.
- **A weighing the blame cannot share.** If the plan ever grows a signal that depends on state the
  blame cannot obtain — a network read, an environment only a release has — the two would have to
  disagree, and the shared file is where that has to be decided rather than in the newcomer.

---

## Files

| File | Role |
|---|---|
| `bin/blame.php` | the command: the arguments, the report, the diagnosis, the exit codes |
| `bin/weighing.php` | the shared half: the versions, the four signals, the weighing, the surface halves, the changelog section |
| `bin/release.php` | the command: the arguments, the preconditions, the plan, the tag — and the same `weigh()` |
| `bin/surface.php` | the symbol reader, the surface differ and the inventory format both of them use |
| `tests/Unit/Release/BlameTest.php` | the cases above, driven through the real script |
| `tests/Support/ReleaseRepo.php` | the throwaway package the blame, the release and the inventory are all driven against |
| `RELEASING.md` | the reader-facing section, [Which signal caught it](../RELEASING.md#which-signal-caught-it) |

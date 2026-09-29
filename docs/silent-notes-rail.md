# When should a release insist the notes account for a change?

A design record for the notes rail in `bin/release.php`, and for `--allow-silent-notes`.

`--weigh` reads four signals and takes the loudest, so the version is right even when the
changelog is not: a new public method filed under `### Fixed` weighs a patch, the surface signal
weighs the minor, and the release ships at `0.2.0` with a `## 0.2.0` section whose only entry says
a defect was fixed. Every rail the script had is satisfied by that section — it is not empty — and
every rail it had is about the *version*, which moved correctly. This document is about the gap
between "there are notes" and "the notes are about this", and about how a script decides the second
without reading prose.

Everything below is implemented in `bin/release.php`, with the one list of what counts as the
public surface in `bin/weighing.php`, and is documented for a reader in the [safety rails of
RELEASING.md](../RELEASING.md#safety-rails).

---

## The answer in one line

**A release is refused when the loudest signal that reads the public surface weighs more than the
release notes do — severity against severity, over the two halves of the tag diff and the
inventory — unless `--allow-silent-notes` overrides it; a dry run reports it instead.**

---

## Why this needed deciding at all

### 1. The notes are the only signal a person ever reads

The other three signals exist to decide a number. The notes are *published*: they become the
section a consumer opens to find out what changed, and the version beside them is a claim they are
supposed to explain. So an entry that is missing is not a smaller version of a correct release, and
it is not recoverable later — the tag is cut, the release is on Packagist, and the sentence that
should have been there is in a commit nobody reads.

That asymmetry is why this is a rail rather than a warning, and why the escape has to be said out
loud in the plan: nothing downstream will ever surface it.

### 2. The bump is not the note, and one rail was doing both jobs

The weighing already takes the surface into account, which is why a removal shipped with `### Fixed`
notes still gets a minor version rather than a patch. It is tempting to read that as the notes being
*covered* — the version accounts for the change — but the reader of `## 0.2.0` sees the entry, not
the diff. The two are the same question only if nobody reads the changelog.

### 3. "Accounts for it" has to be decidable without parsing prose

The honest reading is "an entry that mentions the symbol", and it is not implementable: a release
note that has to spell `Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider::KEY_*`
for each member it covers is a note authors stop writing, and the rail would then be refusing the
releases that are documented *best* — the well-described ones whose entries are paragraphs rather
than symbol lists. So the question had to be re-asked as one about the notes' own vocabulary.

---

## The candidates

| # | shape | what it costs | verdict |
|---|---|---|---|
| A | nothing — the existing rails | the `### Fixed`-beside-a-new-method case ships, and it is a state every other rail accepts | **rejected** — it is the state this record exists to fix |
| B | require the Unreleased section to be non-empty, and name the surface changes in the refusal | it catches only the case that already refuses, so the better message would arrive on a release that was stopping anyway, while the one that matters goes out | **rejected** — a sharpened message is not a rail |
| C | search the entries for the changed symbol names | decideable and honest about *which* entry covers *which* change, and unusable: prose would have to spell every FQN, and an entry written as a sentence would read as silence | **rejected** — it fails the releases that are documented best |
| D | every signal against the notes, commits included | a `feat:` commit is a minor by the policy's own table, so it would refuse whenever the notes are a patch — including for a `feat:` that adds nothing public, which the policy deliberately counts as a capability change | **rejected** — a commit subject is not evidence that a consumer gained anything |
| E | the loudest surface signal against the notes, by severity (chosen) | an entry of the right weight covers a change it does not name, and a well-argued removal filed under `### Changed` is refused and redirected | **chosen** |

---

## The comparison is severity, not prose

`### Added`, `### Changed`, `### Deprecated` and `### Removed` are already the vocabulary the
changelog and the policy share: the headings are what `--weigh` reads, and what an author picks
when filing an entry. Comparing the two severities therefore needs no new concepts, and it produces
the right refusal for each case:

| The surface weighed | The notes weigh | Outcome |
|---|---|---|
| breaking (a removal, a new required argument) | breaking (`### Removed`) | released — the entry claims the break |
| breaking | minor (`### Changed`) | **refused**, pointed at `### Removed` |
| minor (a new method, a new config key) | minor (`### Added`) | released |
| minor | patch (only `### Fixed`) | **refused**, and the symbols are named |
| patch (nothing public moved) | anything | released — there is nothing to account for |

So the rule is "the notes must be at least as loud as the surface", and the only override is
`--allow-silent-notes`, which releases anyway and prints the pair of severities it let through, the
way `--ignore-policy` and `--skip-ci` print theirs.

`--dry-run` reports the state and refuses nothing, which is the same shape the dirty-tree and CI
rails use: a plan exists to say whether the release would go through, so "write the note first" is
an answer a plan is the right place for.

---

## What counts as the public surface

The two halves of the tag diff are the obvious half of the answer — `public API` for `src/` and
`config` for `config/` — and they are not all of it. The inventory holds the rows a tag diff cannot
see: a config key, a constant or a property removed since the last release is a removal to the
stored rows and nothing at all to a comparison of two tags, and the inventory signal reports it as
breaking. A rail that read only the tag diff would let that release through with `### Fixed` above
it, which is precisely the accident.

So the list belongs in one place. `surfaceSources()` in `bin/weighing.php` is that place, next to
the signals themselves, and `surfaceLabels()` is where the two halves get their names — so the
weighing, the blame report and this rail cannot come to different conclusions about which signals
are the surface. The `inventory` and `CHANGELOG` source strings moved into `inventorySource()` and
`notesSource()` for the same reason: a source spelled in two files is a source that can be renamed
in one of them, and this rail reads the difference between them.

One threshold follows from the same place. A `patch` from a surface-reading signal is *not* a change
to a symbol: it is the inventory saying its file is stale or missing, or a file that moved with its
symbol intact. The refusal lists only the signals above a patch, so its list is exactly the symbols
somebody has to write a note about, and the two are not confused in the plan either.

---

## Tests that pin the rules

| Test | Pins |
|---|---|
| `SilentNotesTest::test_an_addition_the_notes_do_not_account_for_is_refused` | the case the rail exists for: `### Fixed` beside an added public method, refused with the symbols named, no tag, and the notes where they were |
| `SilentNotesTest::test_a_removal_the_notes_call_a_fix_is_refused` | a removal weighed as breaking against patch notes, with the surface severity in the refusal |
| `SilentNotesTest::test_a_removal_filed_under_changed_is_refused_with_the_heading_to_use` | the severity comparison rather than a bare non-empty check: `### Changed` is a minor, so it does not cover a removal, and the message says `### Removed` |
| `SilentNotesTest::test_the_inventory_alone_can_trip_the_rail` | the inventory as part of the surface: a stored row the tree does not back trips the rail while the tag diff is quiet |
| `SilentNotesTest::test_notes_that_account_for_the_change_let_the_release_through` | an entry of the right weight is all it takes: the release is cut and the plan says nothing |
| `SilentNotesTest::test_a_tree_with_no_surface_change_is_not_a_finding` | a docs-only or internal release is left alone — the rail cannot simply require loud notes |
| `SilentNotesTest::test_an_entry_of_the_right_weight_covers_a_change_it_does_not_name` | the documented limit, asserted so it cannot be tightened by accident |
| `SilentNotesTest::test_allow_silent_notes_releases_anyway_and_says_so` | the escape is taken from both sides: the release is cut *and* the plan names what it let through |
| `SilentNotesTest::test_a_dry_run_warns_instead_of_refusing` | a plan refuses nothing, and the warning is worded as one |

Mutations these were measured against, each applied and run against `SilentNotesTest`: reading only
the tag diff as the surface fails one test (the inventory witness), treating any non-empty notes as
an account fails six, letting a dry run refuse fails one, ignoring the override fails one, dropping
the symbols from the refusal fails four, and saying nothing in the plan fails two.

---

## Known limitations

1. **An entry of the right weight covers a change it does not name.** A `### Added` entry about
   something else silences the rail for a new method. This is candidate C's cost, taken on purpose,
   and the case is asserted rather than left implicit.
2. **A well-argued removal under `### Changed` is refused.** The message says which heading the
   change belongs under, and `--allow-silent-notes` is one flag away, but an author who considered
   `### Changed` the better description is told to move it. That is the price of the comparison
   being about headings at all, and the alternative — reading the entries — is candidate C.
3. **A first release has no tag diff.** With no tag there is no base, so the rail's surface is the
   inventory alone, and a package releasing for the first time with no inventory written and no
   notes has nothing to trip it. The empty-notes rail still refuses that release, so what is lost
   is the *sharper* refusal rather than the release being stopped.
4. **The rail reads severities, so it cannot count.** Three surface changes and one `### Added`
   entry is a covered release. Requiring a bullet per symbol would be candidate C with a new name.

---

## What would change this decision

- **A real signal for "the entries are about these symbols".** If the notes ever gained a
  machine-readable form — an entry per symbol, or a link from a symbol to its entry — the rail would
  move to candidate C and the limitation above would go. Nothing about the rail's placement, its
  escape or its exit code would change.
- **A heavier surface with no heading to file it under.** If the policy ever weighs something the
  Keep a Changelog headings cannot describe, the comparison needs a fourth rung rather than a new
  rail.
- **A release nobody reads the notes of.** An internal package, or one whose consumers pin exact
  versions, could reasonably turn this off for good. That would be a default in `composer.json`
  rather than a flag, and it would have to be visible in the plan — an override nobody can see is a
  rail that was removed.

---

## Files

| File | Role |
|---|---|
| `bin/release.php` | the rail, the refusal, the plan note, the option, and `weighedAcross()` / `surfaceReason()` |
| `bin/weighing.php` | `surfaceSources()`, `surfaceLabels()`, `notesSource()`, `inventorySource()` — one list of what the surface is |
| `tests/Unit/Release/SilentNotesTest.php` | the seven cases above, driven through the real script |
| `tests/Support/ReleaseRepo.php` | the throwaway package the release, the blame and the inventory are all driven against |
| `RELEASING.md` | the rail's row in the safety table, and the `--allow-silent-notes` paragraph |
| `README.md` | the release narrative, which now says the notes have to account for the surface |

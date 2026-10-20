# Release v0.2.0-alpha6's CI ran, and it was red

## What was asked

Whether the `Release v0.2.0-alpha6` CI actions were skipped, and if the run was red, what made
it red — then make CI green.

The first half of that has a definite answer, and it is not the one the question assumed: **the
workflow ran.** GitHub created two runs for the push at `2026-10-20T13:15:26Z`, one for the tag
`v0.2.0-alpha6` and one for `dev`, and both were **red**.

## The evidence

| Run | Ref | Commit | Result |
|---|---|---|---|
| [39428847545](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39428847545) | tag `v0.2.0-alpha6` | `57ba1e8` | **failure** |
| [39428848335](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39428848335) | `dev` | `26fe1b0` | **failure** |

The tag object was made at `2026-10-20 03:53 +05:00` and pushed with the branch about fourteen
hours later, so the run that measured the release was created long after the commit it points at.

Both runs have the same two jobs, and the live one is not the problem:

- `pgcat pool probe (live)` — **success** on the tag run, every step green, including the
  pinned image `pgcat:v1.2.0@sha256:627761…` and the filter that refuses "no tests executed".
- `PHP 8.4` — **failure**, at `Run the check suite` (`composer checks`).

The suite itself: 13 checks, 10 passed, 2 failed, 1 skipped.

```
syntax       PASS
ast          PASS
writeback    PASS
schema       PASS
platform     PASS
interpreter  SKIP     this checkout has no .agents/machine.local.json
tools        PASS     pint v1.32.1, phpstan 2.3.1, phpunit 11.5.57, yaml-lint v7.4.21
yaml         PASS
counts       PASS     54 count(s) across 12 record(s)
sentences    PASS
phpstan      FAIL     [ERROR] Found 1 error
pint         PASS
tests        FAIL     Tests: 1560, Assertions: 10371, Failures: 9, Skipped: 18
```

## Why it was red, in three separate causes

### 1. The tag's commit does not carry the files its own handoff note names

This is the largest group: seven of the nine test failures, all of them the note and skills
guards reading a tree that is not the tree the tag froze.

```
HANDOFF.md names paths this package does not have:
  - .agents/skills/lpr-issue/SKILL.md (line 236)
  - docs/investigations/investigation-pgcat-both-mechanisms-armed-warning.md (line 237)
  - docs/issues/issue-pgcat-flip-force-mode-readers.md (line 238)
  - .agents/skills/lpr-issue/SKILL.md (line 494)
```

`git ls-tree 57ba1e8 .agents/skills/` lists `pkg-commit`, `pkg-deploy` and `pkg-push` only, and
`git ls-tree 57ba1e8 docs/issues/` is empty. The commit carries a note that names three documents
and one skill it does not contain — and the pending table in that same note lists the documents as
pending work, in a version that is supposed to be published.

The same defect shows up as `SkillDescriptionTest`: the skills index in the commit lists
`lpr-issue`, and `.agents/skills/` has no such directory.

Why the release did not catch it: the release's note guard runs `HandoffTest` **in the working
tree**, not in the commit it is about to tag.
`bin/release.php` plans it as "HandoffTest at the commit this tags", and it reads the note at the
new commit, but the test's path checks answer `file_exists()` — and untracked files were still on
disk when the release ran. `HandoffTest` was therefore green locally for a note the commit could
not justify, and the first machine to see the commit without the working tree was the runner.

The release also never noticed the untracked files: its dirty check asks git with
`--untracked-files=no`, so a checkout holding new, uncommitted records is "clean" to it. It then
commits only the files it writes (`CHANGELOG.md`, `HANDOFF.md`, the inventory, `API.md`,
`composer.json`), leaving the pending documents behind, and tags.

The state was repaired on `dev` afterwards — `80b60ff` committed the three pgcat records and
`26fe1b0` replaced `lpr-issue` with the two `wdm-*` skills — but the tag still points at the
commit before those fixes.

### 2. CI installed static analysis the local gate had never run

```
phpstan  FAIL  [ERROR] Found 1 error
Strict comparison using !== between
array{database: string, status: non-empty-string, evidence: non-empty-string} and null
will always evaluate to true.
Line PgcatConfigFlipper.php:2144
```

The tag's `PgcatConfigFlipper` guards a control answer with
`if ($discriminates === false && $control !== null)` while its PHPDoc says `$control` is an array
shape that is never null, so phpstan 2.3.1 rejects the comparison as always true. The gate record
in `HANDOFF.md` was taken with `phpstan 2.2.14`, which did not.

The repository commits no `composer.lock` (it is a library), so `composer install` in CI resolves
`require-dev` afresh: `^2.0` became 2.3.1, `^1.25` became 1.32.1, `^11.5` became 11.5.57. A gate
that passes locally on 2.2.14 and fails on the runner on 2.3.1 is not a gate a tag can be cut
behind. The pins are being added in the working tree (`composer.json` and `bin/tools.php` name
1.32.1, 2.3.1 and 11.5.57 exactly), and this checkout now has those versions installed — which is
why the same analysis is green here.

### 3. Two runner-only failures that no commit since has fixed

Both the tag run and the `dev` run carry these, and neither was touched by a later commit:

- `DbDoctorTest::test_the_write_paths_row_names_every_path_a_flip_writes_beside_the_mode_found`
  — the write-paths row itself passed its label and path assertions; the assertion that failed is
  `assertSame(0, $exit)`, so the *doctor run* exited 1 because some other row failed on the
  runner. It does not reproduce in this Windows checkout.
- `HandoffNoteTest::test_the_release_moves_the_notes_tag_rows_into_its_own_commit` and
  `…test_the_absent_row_names_the_release_a_prerelease_announces` — the fixture asserts the tag
  rows at 333–335 and the runner changed 329–331, and the "notes weigh as" row the runner produced
  lacks the `, v0.1.0-alpha2` suffix the test expects. Both tests read the package's own
  `HANDOFF.md` as the fixture's note, so they are sensitive to that note's shape on the machine
  that runs them.

These are the residue that a green tag still has to answer, and they are the reason a re-run of
the existing run would not have helped.

## What makes a tag green

The tag cannot be made green by re-running it: `v0.2.0-alpha6` is an immutable pointer at
`57ba1e8`, and that tree is what failed. There are only two ways forward, and they are not equal.

1. **Cut the next release from a clean, verified commit.** With the tree's work committed, its
   records present, and the pinned toolchain green, a new tag gets its own run — and the alpha6 run
   stays red as history, which is what a published version's run should do.
2. **Move `v0.2.0-alpha6`.** Deleting and re-pushing the tag rewrites a version Packagist may
   already have crawled. It is the only way the *existing* tag's run turns green, and it is the
   option `RELEASING.md` warns about: a version a consumer can install is not one to retarget.

The gate defect to fix either way is the same, and it is in `bin/release.php`:

- the dirty check must ask for untracked files as well (`git status --porcelain
  --untracked-files=no` is why a tree holding three uncommitted records looked clean), so a release
  refuses a tree whose records are not committed; and
- the note guard must read the commit it tags rather than the checkout — either by running the
  guard in a clean detached worktree at the new commit, or by asking git whether every path the
  note names is carried by that commit, so `file_exists()` on the developer's disk cannot answer
  for a version.

## Commands used

```bash
gh run list --repo UAK-35/laravel-weighted-dbmanager --limit 15
gh run view 39428847545 --log-failed
gh run view 39428848335 --log-failed
git ls-tree --name-only 57ba1e8 .agents/skills/
git ls-tree --name-only 57ba1e8 docs/issues/
git show 57ba1e8:HANDOFF.md
```

## What was done

The gate defect was repaired rather than the tag: `bin/release.php` now asks `git ls-files --others`
as well and refuses a tree holding files no commit carries, before anything is written, naming them.
`--dry-run` warns and prints the same list, and `--allow-dirty` stays the loud escape. That closes
the loop the alpha6 release went through: the note's pending table is derived from exactly that set,
so the working tree cannot hold a record the version will not contain without the release saying so.
The development tools were pinned to the versions CI installs (pint 1.32.1, phpstan 2.3.1, phpunit
11.5.57), because `require-dev` ranges are resolved afresh on a runner and the gate record had been
taken on an older phpstan.

Both are held by tests: `test_a_file_no_commit_carries_has_to_be_committed_first`,
`test_the_dry_run_warns_about_files_no_commit_carries`, a row in the exit-path census, and the rail
table in `RELEASING.md`, which `ReleasingDocTest` checks names tests that exist.

## Outcome

`v0.2.0-alpha7` was cut from `dev` at `2a771cc` and pushed with the branch, and **both runs are
green**:

| Run | Ref | Result |
|---|---|---|
| [39469491120](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39469491120) | tag `v0.2.0-alpha7` | **success** — 13 checks, 12 passed, 1 skipped (the interpreter, absent on a runner) |
| [39469492648](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39469492648) | `dev` | **success** |

The tag run's own numbers: `phpstan` PASS, `pint` PASS over 164 files, `tests` PASS with 1589 tests
and 10514 assertions (18 skipped), and `pgcat pool probe (live)` PASS against the pinned image. The
`v0.2.0-alpha6` run stays red, which is what a published version's own run should say about the tree
it froze.

The two runner-only failures did not recur, and no code was changed for either. Both read the tree
the release had just refreshed — one is the fixture that takes the package's own `HANDOFF.md` as its
note, the other a doctor row that exits on a row other than the one it names — which puts them in
the same family as the note paths rather than in a family of their own. That is an inference from a
single green commit, not a repro; the next tag cut from a tree that is *not* a release commit is
what would settle it.

# Releasing

The version of this package is the **git tag**, and nothing else.

There is no `version` field in `composer.json` and no version file. A number
stored in the repository is a second source of truth that drifts from the tag —
and Packagist derives every published version from tags anyway, so the file
would be decorative at best:

> The version field is present, it is recommended to leave it out if the package
> is published on Packagist.
>
> — `composer validate --strict`

So releasing is three steps: work out the next version from the latest tag and
the changes since it, promote the CHANGELOG, tag it.

## The short version

```bash
composer release -- --weigh --dry-run    # print the plan, write nothing
composer release -- --weigh              # promote CHANGELOG, commit, tag
git push origin main --follow-tags       # Packagist publishes from the tag
```

`composer release` is `php bin/release.php`. The `--` is how Composer passes
flags through to the script. `--weigh` reads the changes that are about to be
released — the Unreleased notes, the commits since the last tag, the public
surface of `src/` and `config/`, and the [inventory](#the-inventory) the last
release wrote — weighs them against the [versioning policy](#versioning-policy)
and *takes* the bump. That is why it is not called `--detect`: it does not only
report the next version, it decides it.

## What the script does

| Step | Detail |
|---|---|
| Base version | The most recent `v*` tag that is an **ancestor of HEAD** — `git describe`, and when that finds nothing, the same question asked of `git tag --merged HEAD` — so a release is built on what the branch actually contains, never on a tag cut on a branch it cannot reach |
| Bump | `--weigh` lets the policy pick it; `--minor`, `--major` or `--version=X.Y.Z` declare it instead, and are refused when they undersell the changes |
| Next version | The base plus the bump, or exactly `--version=X.Y.Z`; the suffix may be a prerelease |
| Branch | `main` for a release, `dev` for a prerelease — the suffix decides, and `--branch=NAME` overrides |
| CI | HEAD has to be the tip of the remote branch and the workflow's run for it — a push run on that branch — has to have finished and passed. `--skip-ci` tags anyway |
| No tags yet | The base is `0.0.0`, so the bump applies to it directly: a weighed minor is **0.1.0**, a weighed patch **0.0.1** |
| CHANGELOG | Promotes `## Unreleased` to `## X.Y.Z - YYYY-MM-DD` and puts a fresh, empty `## Unreleased` section above it. Heading style (bracketed or not) follows the file |
| Compare links | When the changelog has an `[Unreleased]:` link reference, it is repointed at the new tag and a `[X.Y.Z]` compare link is added |
| Branch alias | Both dev-lane aliases (`dev-main`, `dev-dev`) are kept on the line being developed (see below) |
| Commit | `Release vX.Y.Z`, touching only `CHANGELOG.md` and, when it moves, `composer.json` |
| Tag | Annotated: `git tag -a vX.Y.Z -m vX.Y.Z` |
| Inventory | `files.tsv`, `methods.tsv` and `surface.tsv` are rewritten in the same commit, stamped with the tag being created (see [the inventory](#the-inventory)) |

`--dry-run` prints all of the above, the CHANGELOG head after promotion, and the
exact git commands it would run — without writing, committing or tagging
anything. It is safe to run on a dirty tree.

It is also the only run that answers `--weigh` with an empty `## Unreleased`: a
plan publishes nothing, so the rail above does not apply to it, and the bump is
weighed from the commits, the public surface and the inventory instead. The plan's
`CHANGELOG` line says which question it answered, and the refusal a real run
prints names the flag — so neither the bump nor the reason it is not a release is
left to be inferred.

## What the release does to CHANGELOG.md

One section is edited, and only one: the topmost `## Unreleased`. Whatever the
bump was — weighed or declared — the change is the same, because the bump only
decides the number in the heading.

| Before | After |
|---|---|
| `## Unreleased` | A new, empty `## Unreleased` heading goes above it |
| the notes under it, e.g. `### Added` / `### Changed` / `### Fixed` with their bullets | the same notes, now under `## X.Y.Z - YYYY-MM-DD`, untouched and still categorised by the same `###` headings |
| `[Unreleased]: .../compare/vA.B.C...HEAD` | repointed to `.../compare/vX.Y.Z...HEAD`, plus a new `[X.Y.Z]: .../compare/vA.B.C...vX.Y.Z` line appended |
| every released section below | left byte-for-byte alone |

The one-way street matters: promotion **moves** the notes, it does not copy them.
The empty `## Unreleased` left at the top is where the next release accumulates its
entries, and the `###` headings you write there are what `--weigh` reads back —
an entry filed under `### Removed` is the notes declaring a breaking change, and
no bump flag can talk the script out of it.

## The inventory

`files.tsv`, `methods.tsv` and `surface.tsv` sit at the package root. `bin/release.php`
writes them in the release commit, next to the CHANGELOG, and the next release reads
them as its fourth signal. They are what a tag is not always able to be: a
written-down list of what was shipped, so a renamed file, a method that changed shape
or a config key that was dropped can be seen rather than remembered — including where
the history gives no useful tag to diff against.

| File | One row per | Columns |
|---|---|---|
| `files.tsv` | file under `src/` or `config/` | `name`, `path`, `symbol` — the class the file declares, or `(none)` for a config file |
| `methods.tsv` | public method | `method`, `file`, `class`, `signature` — the signature being required/total argument counts and the shape of each parameter |
| `surface.tsv` | config key, env var, public constant, enum case or public property | `kind`, `symbol`, `file` — the kind being the word the report uses, so a removal reads as `removed public constant Acme\Thing::VERSION` |

The third file is the one that does not need a tag. The public-API signal diffs the
tree against the last tag, so a tree with one — or with none — has nothing for it to
see a removal in, and the rows a consumer sets rather than imports are exactly the ones
nobody notices went: an installation that sets `swrr.…` finds out when the key stops
being read. A stored row is its own "before", which is why these are written down.

TSV rather than JSON, and not for speed: a row is `explode("\t", $line)`, there is
no quoting rule to get wrong, and one symbol per line means `git diff` shows a
rename as two lines a person can read.

All three files open with two `#` lines — what the file is, and which tag it
*describes*. The stamp is the whole safety property:

- it names the tag being released from → the rows are evidence, and the tree is
  weighed against them;
- it names anything else → the file was regenerated at some other moment, which
  makes "nothing changed" and "not refreshed" indistinguishable on disk. It is
  reported as **stale** and skipped, and the tag diff stays the authority.

That is the one direction a versioning signal must never be wrong in: believing a
lazily refreshed inventory would ship a breaking change as a patch. Losing one
costs a second opinion and nothing else, because no signal ever lowers the bump.

Regenerating by hand is for looking — `php bin/inventory.php` rewrites all three
files with the latest tag and prints the evidence that rewrite discarded:

```bash
php bin/inventory.php            # write all three files, stamp them with the latest tag
php bin/inventory.php --check    # compare, write nothing, exit 1 when out of step
```

| Exit | Means |
|---|---|
| `0` | written, or already current under `--check` |
| `1` | out of step under `--check`, nothing could be written, or the path is not a package root |
| `2` | usage error |

`--check` names the files that differ and why — rows that are gone and rows that
appear, a file it has never seen, a file that is not there at all, and the one
difference that must never be waved through: a stamp naming a release other than
the one this tree is being described as. A file whose rows are right but whose
bytes are not — a stray blank line, a trailing space — is reported as exactly that,
and a CRLF checkout of the right rows is *current* rather than drift, because the
comparison normalises line endings before it compares anything.

A rewrite that discards something is loud about it — up to three of the changes it
was about to forget, then a count — because the rows it is replacing are the only
record those changes left. It reports that and exits `0`: the tree is the
authority, so the write goes ahead, and saying so is the whole of the remedy.

Only a pair that was read whole can witness a change, so an inventory with one file
missing is completed in silence: the file that is not there recorded nothing to lose,
and the rows of the other one are written without being described as discarded. That
is the state an interrupted release leaves — the two files are written by one call and
only the second can fail — so the next run of either writer finishes the pair rather
than reporting a loss nobody suffered.

The rules about the third file are pinned by name:
`InventoryTest::test_the_surface_rows_record_the_keys_and_members_a_tag_diff_cannot`
holds the rows — and that a *typed* public property is one of them, which is what a
reader that stopped at the type name could not see —
`BumpWeighingTest::test_a_constant_removed_with_no_tag_at_all_is_witnessed_by_the_inventory_alone`
holds the weighing it exists for, and
`BumpWeighingTest::test_an_inventory_missing_one_of_its_files_is_incomplete_and_never_moves_the_bump`
holds the whole-or-nothing rule.

Do **not** wire `--check` into CI. An inventory kept in step with every commit can
never witness a change, and witnessing one is the only thing it is for.

## Prerelease (dev) tags

A version may carry a suffix — `-alphaN`, `-betaN`, `-rcN`, or a bare `-dev` — and the
suffixes this script writes are the ones Composer's own parser reads. That is not
tidiness:

| Written here | Composer | |
|---|---|---|
| `0.0.1-alpha1`, `-alpha2`, … | `0.0.1.0-alpha1`, stability `alpha` | a numbered lane, so a dev line can be cut again and again |
| `0.0.1-betaN`, `0.0.1-rcN` | `beta`, `RC` | the same lane, promoted |
| `0.0.1-dev` | stability `dev` | read, and takes **no number** |
| `0.0.1-alpha.1` | **rejected**: the dot is a second spelling | Composer reads it as `0.0.1.0-alpha1` too, so a tag carrying it and one carrying `-alpha1` would be one version published twice — and the dotted one blocks the undotted one once it exists |
| `0.0.1-dev.1` | **rejected**: `Invalid version string` | the spelling that reads most naturally is the one Composer refuses |
| `0.0.1-nightly`, `-pre.1`, `-snapshot.1` | **rejected** | Composer reads a fixed vocabulary, not arbitrary identifiers |
| `0.0.1-RC1`, `-p1`, `0.0.1.1` | accepted by Composer | not written here — narrower than Composer is safe, wider is not |

A tag Composer cannot parse is a tag **nobody can install**, and it fails *quietly*: the
tag simply never becomes a version. So the suffix is refused when it is typed, with exit
code 2 rather than 1 — the argument itself is wrong, so nothing was attempted — instead of
being written and found later. A numbered dev lane is therefore spelled `-alphaN`: `dev`
takes no number at all, and the number is written without a dot so that one version has
one spelling.

The suffix also decides the branch, in both directions:

```bash
php bin/release.php --version=0.0.1-alpha1 --ignore-policy --yes    # cut from dev
php bin/release.php --weigh --yes                                   # cut from main
```

A prerelease *precedes* the release it is named after, so it comes from the branch still
being developed; a version with no suffix is a release, and comes from the branch that
holds released versions. `--branch=NAME` overrides either, and the plan prints the branch
it expects before it touches anything.

Everything after that is a release: the notes are promoted, a fresh `## Unreleased` is
left above them, the inventory is rewritten and stamped with the prerelease tag, and the
run commits and tags. A prerelease is a version like any other — it is the numbering that
differs.

Consumers have to opt in, because a prerelease is not matched by `^0.0.1` at the default
`minimum-stability`: they need `"minimum-stability": "alpha"` in their own `composer.json`,
or a constraint that says so — `"^0.0.1@alpha"`.

One consequence of that ordering is worth stating, because it reads as a bug until it is
said out loud: **a prerelease cannot be cut once its release exists.** `0.0.1-alpha1` is
not newer than `0.0.1`, so the rail below refuses it. The dev tags for a line have to come
first — and a tag that has already been pushed is tagged again rather than moved (see
[never move a published tag](#never-move-a-published-tag)).

### The numbering

Nothing remembers the lane number. Asked to cut a tag that already exists, the script
counts the tags in that lane and names the next free one — which is why the count comes
from the tags rather than from the number that was typed:

```
✗ Tag v0.0.1-alpha1 already exists — a published tag is never moved or reused.

The next free number in that lane is 0.0.1-alpha2.
```

## Safety rails

Each of these stops the release with exit code 1, and each is proved by the test in the last
column — the test names a state of the tree, and the state is made rather than mocked, so a
row without one is a rail nobody has driven:

| Rail | Why | Proved by |
|---|---|---|
| Not a git repository | Tags are the version; there is nothing to release into | `test_a_tree_that_is_not_a_repository_is_refused` |
| `HEAD` is not the expected branch | A release is cut from `main`, a prerelease from `dev` — not from wherever you happen to be (see [prerelease tags](#prerelease-dev-tags)) | `test_a_release_cannot_be_cut_from_dev`, `test_a_prerelease_cannot_be_cut_from_main` |
| Tracked files are dirty | The tag must point at exactly what was reviewed — commit first. `--dry-run` only warns | `test_tracked_files_have_to_be_committed_first`, `test_the_dry_run_warns_about_a_dirty_tree_instead_of_refusing` |
| CI has not verified the commit being released | A tag is a version and publishing one is not undoable, so the commit a release is cut from has to be one the workflow built and passed: HEAD must be the tip of the remote branch, and its run must have finished green. A commit that was never pushed is not one CI can have an opinion about, a run still going is not a pass, and a failure beside a success is not a pass either. `--skip-ci` overrides it; `--dry-run` reports it instead of refusing | `test_a_failed_run_refuses_the_tag`, `test_a_run_that_has_not_finished_refuses_the_tag`, `test_a_dry_run_reports_the_state_without_refusing`, `test_skip_ci_tags_without_asking_anything` |
| Target tag already exists | A published tag is never reused or moved (see below) | `test_the_refusal_names_the_next_free_number_in_the_lane` |
| The version is not newer | A release cannot go backwards | `test_a_version_that_is_not_newer_is_refused` |
| Not `X.Y.Z` or a Composer-legal prerelease | A tag Composer cannot parse is one nobody can install, and it fails quietly rather than loudly (see [prerelease tags](#prerelease-dev-tags)). This one exits **2**, not 1 | `test_the_suffix_grammar_is_the_one_composer_can_read`, `test_a_refused_suffix_is_refused_before_the_branch_is_checked` |
| No `## Unreleased` section | There is nothing to promote | `test_a_changelog_with_no_unreleased_heading_is_refused` |
| The Unreleased section is empty | Refused, with no override. The notes are what a release publishes and what a reader upgrades on, so there is nothing to release without them. `--weigh --dry-run` is the one run let past it — and it is not a release: it reports the bump the other signals weigh and prints, in the plan, that a real run refuses here. The two answers differ because they answer different questions: the bump is read off the changes, the version is what a reader gets | `test_a_dev_tag_with_no_notes_is_refused_and_has_no_override`, `test_a_weighing_dry_run_reports_the_bump_while_a_release_of_it_refuses` |
| The declared bump is smaller than `--weigh`'s | Shipping a breaking change as a patch is the accident this policy exists to prevent. `--ignore-policy` overrides it, and the plan says so | `test_a_declared_bump_below_the_weighed_one_is_refused`, `test_ignore_policy_releases_anyway_and_says_so` |
| Non-interactive shell | It asks before committing and tagging. `--yes` (or `--dry-run`) is required when stdin is not a terminal | `test_a_non_interactive_shell_is_refused_without_yes` |

Every test named in this file is checked to exist, so a renamed test is a doc failure
rather than a row that quietly stops proving anything.

`--allow-dirty` exists for the first release of an already-populated repository,
where the import and the release are the same commit. Both escapes are tested from the
side that matters: `test_allow_dirty_releases_the_written_files_and_leaves_the_edit_out`
asserts that the tag holds the reviewed file and not the uncommitted edit, because an
`--allow-dirty` that swept the tree into the tag would be the rail's own defect one flag
away.

`--skip-ci` is the same kind of escape for the newest rail. It is not a variance that
still consults CI: it releases on a repository with no remote at all, which is what a
machine with no `gh`, no token or no network needs — and "nothing verified" otherwise
means no tag, because the rail is only allowed to be wrong in one direction. The plan
prints `not checked (--skip-ci)` so a release that skipped it says so.

The question itself is `gh run list --commit <sha> --limit 20 --json
name,status,conclusion,event,headBranch`, asked of the commit being released and read for
the runs that are a **push on the branch being released** — a run for the tag, or for a
branch that also received the commit, says nothing about this one. `RELEASE_CI_COMMAND`
replaces that command (`%SHA%` is where the commit goes), for a machine whose `gh` lives
somewhere else, or one that has to answer without a network.

`--patch` is not a rail but a removal: it is no longer an option at all, and
passing it exits `2` with a pointer to `--weigh`. A patch release is what
`--weigh` computes when nothing louder is found
(`test_patch_is_refused_with_a_pointer_to_weigh`).

`--allow-empty` is gone the same way, and for the opposite reason: it used to let
a release through with an empty Unreleased section, which is the one signal that
cannot be read off anything else. Passing it now exits `2` rather than being
ignored, because a flag that silently does nothing is worse than one that is
refused.

An out-of-date `files.tsv`, `methods.tsv` or `surface.tsv` is not a rail either. It is reported
in the plan and then ignored: a release should not be blocked by a file that is
neither the version nor the tree. Ignored is not forgotten, though — a release that
proceeds **replaces** the file with the stamp of the tag it just cut, so "stale" is a
state the next run recovers from rather than one it is stuck in
(`test_a_stale_inventory_is_reported_and_skipped`,
`test_a_missing_inventory_never_moves_the_bump`,
`test_a_stale_inventory_is_replaced_by_the_release_that_proceeds`).

A rail is not the only way a release stops, and the difference is worth knowing before
reading an exit code as "nothing happened". A step of the release *itself* can fail — the
CHANGELOG, the inventory or `composer.json` cannot be written, git will not take the index,
or the push cannot run. A rail refuses before anything is written; these do not, so the
tree may already have a promoted CHANGELOG in it. What never happens is a new tag, because
the commit and the tag are the last two steps: an unpublished failure leaves no version
behind, and the remedy is to look at the tree and run again
(`test_a_changelog_that_cannot_be_written_is_refused`,
`test_a_composer_json_that_cannot_be_written_is_refused_after_the_notes_are_promoted`,
`test_an_inventory_that_cannot_be_written_is_refused`,
`test_an_index_that_cannot_be_locked_refuses_the_add`,
`test_a_push_that_cannot_run_is_reported_after_the_tag_was_cut`). The push is the one
exception to "no version behind": it runs after the tag, so a push that cannot run leaves the
tag local and the next run refuses on the notes the release consumed — the push is the step
to repeat.

## Versioning policy

Semantic versioning, with the 0.x caveat that a **minor** bump may contain
breaking changes — the package is pre-1.0 and documents breaking changes in the
CHANGELOG rather than pretending otherwise.

| Change | Bump |
|---|---|
| A fix, a doc correction, a test | patch |
| A new command, config key, or health field | minor |
| A renamed/removed public class, method or config key | minor while 0.x, major after 1.0.0 |

### How the bump is weighed

`--weigh` reads four signals, keeps the loudest, and prints all four in the plan
with the evidence behind them:

| Signal | What it reads |
|---|---|
| CHANGELOG | The `###` headings of `## Unreleased`. `### Removed` is breaking; `### Added`, `### Changed` and `### Deprecated` are a minor; `### Fixed` and `### Security` are a patch. A `### Breaking changes` heading, or the uppercase `BREAKING` marker, is breaking |
| commits | The subjects and footers of the commits since the last tag, read as Conventional Commits: `feat` is a minor, `fix`, `docs`, `test`, `chore`, `refactor`, `perf`, `style`, `build`, `ci` and `revert` are a patch, and a `!` or a `BREAKING CHANGE:` footer is breaking. Release commits and merges are skipped |
| public API | `src/` and `config/` at HEAD against the last tag: a class, public method, constant, enum case, public property, config key or env var that disappeared is breaking; one that appeared is a minor; a method that gained a required argument is breaking |
| inventory | `files.tsv`, `methods.tsv` and `surface.tsv` as the last release wrote them, against the tree now — read only when their stamp names the tag being released from, and reported as *stale* and skipped when it does not (see [the inventory](#the-inventory)) |

A commit with no `type:` prefix reads as a patch. It cannot raise the bump by
accident, and it cannot lower one either — the notes and the surface still say
what they say.

The inventory is the one signal that does not need a tag, and the one that is
ever skipped: it is skipped when its stamp does not name the tag being released
from, and it is skipped whole when one of its files is not there at all. No signal
may lower the bump — each is read on its own and the loudest wins — so a skipped or
absent inventory costs a second opinion and nothing else.

The whole-or-nothing rule is what makes a file that was added later safe to weigh. A
file that is not there cannot say whether the rows it should hold were never written
or were just removed, and reading it as an empty one would report every config key and
constant in the tree as added since the last release — a minor the change did not ask
for. So a tree whose inventory predates `surface.tsv` is told its inventory is
incomplete, and the tag diff and the notes carry the weighing until the next release
writes all three.

With no tag yet there is nothing to diff against, so only the notes are read —
which is why the first release is `0.1.0` when its notes have anything under
`### Added` or `### Changed`, and `0.0.1` when they are all `### Fixed`.

The notes are the signal you control, so they are also the answer to a weighing
you disagree with: an entry that is really a fix belongs under `### Fixed`, and
a change nobody meant to make public belongs behind a `private` modifier. Moving
the entry is the fix; there is no flag that lowers the policy.

### Which signal caught it

The plan says what the bump is. `php bin/blame.php` says where it came from: it takes
one name and reports which of the four signals names it and whether that signal is the
one the bump came from.

```bash
php bin/blame.php Uak35\WeightedDbManager\WeightedServiceProvider
php bin/blame.php WeightedServiceProvider::boot
php bin/blame.php configuration
```

The weighing it reads is the plan's own — the same call, from the same tree — so the two
cannot disagree about the bump. Each signal is reported as `caught` or `quiet` with the
severity it weighed, and the two surface halves are asked twice: once about the lines
they changed, and once about whether they hold the name at all. A symbol that is at the
last tag and unchanged since it is in neither, and that is the answer too — the bump moved
for another reason, which the report names.

Named and responsible are not the same thing, and the report keeps them apart: a `docs:`
commit that mentions the symbol is `caught` at a patch while the `### Added` heading above
it weighs the minor, so the contribution paragraph says the bump came from elsewhere and
lists what did carry it. The notes are searched as entries rather than as headings — a
heading is what weighs and the entry is what names — and an entry is quoted with the
heading it sits under, because that is the part that carried a weight.

The match is a plain `contains`, not a pattern. Quoting, a leading `\` and a trailing `()`
are stripped, and nothing else is: `weight` finds `weighed`, `weighting` and `weigh()` at
once, which is what somebody holding half a name wants. Nothing resembling the name is an
answer rather than an error — and it is the exit code too, so a script can branch on it:

| Exit | Means |
|---|---|
| `0` | a signal, or one of the two surfaces, names it |
| `1` | nothing does |
| `2` | usage error |

Unlike `bin/release.php` it has no preconditions: it does not read the branch, refuse a
dirty tree or ask CI, because a question about one symbol is asked mid-edit. It writes
nothing, and it works with no tag at all — with none the commits and public-API signals
have no base, which the report says rather than diffing against nothing.

### Declaring the bump instead

`--minor`, `--major` and `--version=X.Y.Z` name the bump yourself. They are held
to the same floor: declaring one smaller than `--weigh`'s answer stops the
release with exit code 1 and prints the signal that forbade it.

```
✗ These changes call for a major release, but --minor was declared.

  CHANGELOG (breaking):
    • ### Removed — 1 entry
```

A declared bump *larger* than the policy's is allowed — semver permits it, and a
line you want to move on is your call — but the plan notes it. `--ignore-policy`
is the escape hatch for a weighing that misread the tree; it releases anyway and
says what it skipped, so the plan is never silently wrong.

### The branch alias

Both dev lanes are aliased to `X.Y.x-dev`: `dev-main`, which holds releases, and
`dev-dev`, which holds the prereleases cut ahead of them. They are two installable
names for one line, so a consumer requiring `^0.1` can install either. The release
script keeps both honest:

- releasing `X.Y.Z` with `Z > 0` leaves them alone — it is a patch on the same
  line;
- releasing `X.Y.0` points them at `X.Y.x-dev`, because the trunk is now that
  line.

Only those two keys are touched, and only their values: the rest of `composer.json`
is left byte-for-byte alone. An alias the dev lanes do not own — a maintenance
line's, say — names a *different* line, so rewriting it would be wrong rather than
thorough, and the script names the two keys it owns instead.

Today both read `0.0.x-dev` (a fresh checkout has none: see the git history).
Releasing `0.0.1` is a patch on that same line, so both are left alone — it is
`0.1.0` that would move them.

### Never move a published tag

If a tag is wrong, tag the correction — do not delete and re-create it. Packagist
caches what it saw, and a moved tag makes dependency resolution disagree with
what was published; Composer's own guidance is that a broken tag is ignored
entirely rather than re-read.

Before a tag is pushed, deleting it locally is harmless:

```bash
git tag -d v0.1.1
```

## The first release

The repository had no tags, so there was nothing for the commits or the public
surface to be compared against and the notes decided alone. They describe a
package that already has features (`### Added`, `### Changed`), so `--weigh`
computes **0.1.0**. The first tag is **v0.0.1**, declared rather than weighed:

```bash
composer release -- --version=0.0.1 --ignore-policy    # tag v0.0.1
git push origin main --follow-tags
```

The declaration is not the accident the policy exists to prevent. A first
release is the one bump the weighing cannot make, because there is no previous
version for it to be a change *from*: `--weigh` compares the notes against
`0.0.0`, where every feature is new by construction, so it reports the shape of
the notes rather than the size of the step. `--version` is what says it, and the
plan prints the override where it prints every other weighing decision:

```
  note: --ignore-policy: releasing 0.0.1, below the minor the changes call for
```

From the second release on there is a real base to compare against and the bump
is weighed like any other, with `--ignore-policy` back to being the escape hatch
for a weighing that misread the tree.

The first release is also the first time the inventory is written: `files.tsv`,
`methods.tsv` and `surface.tsv` land in the same commit, stamped `v0.0.1`, and from
the second release on they are a signal like any other.

Dev tags ahead of it are cut from `dev` rather than `main`, and are named with
`--version=0.0.1-alphaN` — see [prerelease tags](#prerelease-dev-tags). They change
nothing else: the notes are promoted and the inventory stamped exactly as above, under the
prerelease tag.

Then publish it once — see [publishing](#publishing). Until that is done, the branches
are still installable from the repository URL: `dev-main` for the release line,
`dev-dev` for the dev lane, each resolving to the `X.Y.x-dev` both aliases name.

## Publishing

`bin/release.php` creates the tag. Publishing it is a one-time setup at Packagist, plus
whatever keeps it in step afterwards.

The script's last line says so, and says the same thing on every run: it names the tag it
just cut and both ways the gap closes — submit the package if it never was, or trigger a
crawl — because whether either has happened is a fact about Packagist that a tag on GitHub
cannot reveal. Which section below applies is the reader's to pick.

The order matters, and it is short:

1. **Submit the repository once** — [the first submission](#the-first-submission). Nothing
   is published before this, whatever the tags say.
2. **Install Packagist's GitHub hook** — [making later tags publish
   themselves](#making-later-tags-publish-themselves). Without it a tag waits for the next
   weekly crawl, which is not the same day it was pushed.
3. **Confirm the version is being served** — [confirming the published
   version](#confirming-the-published-version). A tag on GitHub and a version on Packagist
   are two different events, and the crawl is what closes the gap between them.
4. **Tell consumers a prerelease needs an opt-in** — [the minimum-stability
   opt-in](#the-minimum-stability-opt-in). A published version nobody's constraints can
   match is published and still not installed.

### The first submission

Sign in at [packagist.org](https://packagist.org) **with the GitHub account that owns the
repository** — `UAK-35` for this package — then submit
`https://github.com/UAK-35/laravel-weighted-dbmanager` at
[packagist.org/packages/submit](https://packagist.org/packages/submit).

Packagist crawls immediately when JavaScript is enabled, and a first submission reads the
repository's **whole history**, so a tag already pushed is picked up there rather than
only by the pushes that come after it. The name it publishes is
`uak35/laravel-weighted-dbmanager`, from `composer.json` — which is already held to
`composer validate --strict` by `composer checks`.

### The vendor is already claimed

`uak35` is an existing vendor on Packagist: `uak35/laravel-response-compression`,
maintained by this same GitHub account. Packagist protects a vendor once a package exists
in it — publishing into it requires being a maintainer of at least one package already
there. That is satisfied here, which is why the submission needs no special permission. It
does mean the login has to be that account: a different one fails on submit rather than
succeeding quietly.

(An unclaimed vendor — `uak-35`, say — would skip the check entirely, at the cost of
renaming the package.)

### What appears, and why

| Version | Where it comes from |
|---|---|
| `dev-main` | the default branch |
| `dev-dev` | the dev lane |
| `0.0.x-dev` | `extra.branch-alias`, which names both of them (see [the branch alias](#the-branch-alias)) |
| `v0.0.1-alpha1` | the annotated tag, read as `0.0.1.0-alpha1` at stability `alpha` |

### The minimum-stability opt-in

Publishing is not the same as installing. A default `composer.json` resolves against
**stable** versions, so a `-alpha1` tag can be published, listed and still unmatched: a
plain `^0.1` constraint skips it and installs the last stable release instead — or nothing,
on a package whose only versions are prereleases. The opt-in belongs to the consumer,
because their project's stability floor is theirs to set, and there are two ways to write
it:

```json
{ "minimum-stability": "alpha" }
```

or scoped to this one package, so the rest of the project stays on stable:

```bash
composer require uak35/laravel-weighted-dbmanager:'^0.1.0@alpha'
```

The lanes Composer reads are ordered `dev` < `alpha` < `beta` < `RC` < `stable`;
`minimum-stability` sets the floor by hand and `prefer-stable` keeps a stable version
preferred whenever one also qualifies. Nothing in this repository can relax those rules
for someone else's build — a prerelease is a version a consumer has to ask for by name.

### Making later tags publish themselves

**Without the hook, a tag is invisible until the next weekly crawl.** Packagist re-reads a
repository on two occasions only: when a GitHub hook says something was pushed, and on its
own schedule, which is **once a week**. A tag pushed with no hook installed can therefore
be absent from `repo.packagist.org` for up to seven days — and it is not merely unlisted in
the meantime: the `p2` endpoint Composer actually resolves against has no such version, so
`composer require uak35/laravel-weighted-dbmanager:v0.1.0-alpha1` fails as if the tag had
never been cut. A dev tag feels the wait worst, because that lane exists to be cut again and
again and its whole point is to be installable the afternoon it is pushed.

Use Packagist's GitHub integration from the package page: it installs the hook, and it asks
for hook-configuration access on the repository while doing so. If the package list
afterwards warns that a package is not automatically synced, trigger a manual account sync
from your profile — an archived repository cannot be hooked at all, because it is read-only
through GitHub's API, and it will stay on the weekly crawl. When the hook has not fired,
[triggering a crawl by hand](#triggering-a-crawl-by-hand) is the lever that does not wait.

To add the hook by hand instead, these are Packagist's own values:

```
Payload URL    https://packagist.org/api/github?username=<your packagist username>
Content type   application/json
Secret         your Packagist API token, from your profile page
Events         just the push event
```

### Triggering a crawl by hand

The hook is the automation; this is the lever for when it did not run, or was never added.
It asks Packagist to re-read the repository now, which is also what makes a just-pushed tag
appear without waiting for the next push:

```bash
# Username UAK — the account that owns the uak35 vendor. The token is on the
# same profile page as the username.
read -rsp 'Packagist API token: ' PACKAGIST_TOKEN; echo

curl -sS -XPOST \
  -H 'content-type: application/json' \
  "https://packagist.org/api/update-package?username=UAK&apiToken=${PACKAGIST_TOKEN}" \
  -d '{"repository":{"url":"https://github.com/UAK-35/laravel-weighted-dbmanager"}}'

unset PACKAGIST_TOKEN
```

A working call answers `{"status":"success"}`. It is not silent when it fails: verified
against the live endpoint, bad credentials answer `403` with

```
{"status":"error","message":"Missing or invalid username/apiToken in request"}
```

That token goes in the **query string** — both here and in the webhook — so a URL written
by hand with the real values in it puts it in your shell history and in the logs of
anything that proxies the request. `read -rsp` is what the sample above uses for that
reason: it echoes nothing and leaves nothing in history, and the credential is dropped
again after the call. It is an account-wide token that can publish every package under the
vendor, so treat it as a password rather than a URL parameter.

### Confirming the published version

```
curl -s https://repo.packagist.org/p2/uak35/laravel-weighted-dbmanager.json \
  | jq -r '.packages["uak35/laravel-weighted-dbmanager"][] | [.version, .version_normalized] | @tsv'
composer show --all uak35/laravel-weighted-dbmanager
```

Both answer `404` before the submission, and afterwards the first lists the versions in
the table above. The `p2` endpoint is what Composer resolves against, so it is the one
that answers the question; the search index behind `composer show` refreshes every five
minutes, so a package can be installable before it is findable.

A `dev-main` version with no tag beside it is a crawl that has not finished — press
"Update" on the package page, or [trigger a crawl by hand](#triggering-a-crawl-by-hand).
Nothing at all under `uak35/` is the vendor protection, which means the login is not the
account that owns the vendor.

### A published version is immutable

A version Packagist has served cannot be changed — not by deleting the tag, not by
re-creating it at a corrected commit, and not by force-pushing the branch behind it.
Packagist caches the `composer.json` it read and the version it derived, and a moved tag
makes dependency resolution disagree with the published metadata; Composer's own guidance
is to ignore a tag that moved rather than re-read it, so the repair is invisible to the
tool it was meant for. By the time anyone notices, the version is already in somebody's
`composer.lock`.

So a bad published version is fixed the way a bad commit is: cut the next tag. The mistake
is corrected *forward*, never rewritten. [Never move a published
tag](#never-move-a-published-tag) states the rule for the tag on GitHub; this is the same
rule one step later, with the sharper consequence — after the push, `git tag -d` and a
re-tag publish nothing at all, because there is no new version for Packagist to read and
no mechanism that replaces one it already served.

## The root version in CI

Composer has to know the version of the *root* package to resolve dependencies,
and it resolves it in this order: the `version` field, `COMPOSER_ROOT_VERSION`,
then a VCS guess from tags and branches, and finally a fallback of `1.0.0` —
which is what produced this warning before the workflow pinned it:

```
Composer could not detect the root package (uak35/laravel-weighted-dbmanager)
version, defaulting to '1.0.0'.
```

The workflow therefore does two things: `actions/checkout` with
`fetch-depth: 0`, so the tag/branch guess has full history to work from, and a
step that exports `COMPOSER_ROOT_VERSION` for the branch under test (`dev-main`
on `main`, `dev-dev` on `dev`, `dev-<branch>` on a pull request, with slashes
normalised to dashes, because a slash is not valid in a version). The env var wins
over the guess, so the root version is the same on every run.

That step is skipped on a tag build, where the question does not arise: the ref
name is the tag, so the same line would pin `dev-v0.0.1-alpha1` — a string Composer
accepts and which means nothing — and *override* the one version the build is
actually about. Left unset, the guess answers with the tag, which a prerelease tag
can now do because a prerelease tag is a version Composer can read.

The workflow runs for both lanes and for `v*` tags. Both lanes matter because both
are released from — a version with no suffix from `main`, a prerelease from `dev` —
and the tag trigger matters because a `branches` filter alone excludes tag refs, so
without it every published version would carry no CI record of its own. What it runs
is `composer checks`, the same gate a release is cut behind locally, rather than the
three checks `composer test` covers.

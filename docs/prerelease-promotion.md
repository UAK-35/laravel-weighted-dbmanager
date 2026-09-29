# What version follows a dev tag?

A design record for the promotion rule in `bin/release.php`, and for `precedingRelease()` /
`releasePart()`.

`bin/release.php` reads its base version from the latest tag, and a dev tag is a tag. That one
identity was wrong in both directions at once. A prerelease does not *hold* the rung in its name, it
announces it: `0.5.0-alpha1` is a prerelease of `0.5.0`, cut from the branch developing that version,
and no consumer can install it as a release. Counting from it anyway made the promotion of the
version it announced read as a patch step, and made `--weigh` step past that version entirely.

Everything below is implemented in `bin/release.php` — `precedingRelease()` and `releasePart()` for
the base, the promotion branch of `--weigh`, and the plan note that names which base was used — and is
documented for a reader under [promoting a dev
tag](../RELEASING.md#promoting-a-dev-tag).

---

## The answer in one line

**The rung of a declared version is measured from the newest *release* at or below the line being
developed, and `--weigh` offers the release a dev tag announced — stepping past it, from that same
release, only when the changes since the tag ask for more than the promotion itself climbs.**

---

## Why this needed deciding at all

### 1. A dev tag is the only base the script had, and it is not a release

`$base` was `ltrim($latestTag, 'vV')`, one string used for four different questions: what a candidate
must be newer than, what the plan prints as the tag this release is built on, what `bump()` counts
from, and what `kindBetween()` measures the declared rung against. Only the first of those is
answered by the tag rather than by the release — "newer than 0.5.0-alpha1" is exactly right — and the
other three are answered by a version that was never published.

### 2. What it cost, measured

Each row is a throwaway package driven through the real script (the fixture in
`tests/Support/ReleaseRepo.php`), and the `--weigh` and declared columns are what it printed before
the rule below existed:

| Tree | `--weigh` said | Declaring the promotion | What should happen |
|---|---|---|---|
| `v0.2.0`, `v0.5.0-alpha1`, then a round of fixes | `0.5.1` | **allowed** | `0.5.0` |
| `v0.2.0`, `v0.5.0-alpha1`, then a round of features | `0.6.0` | **refused** — “call for a minor, but `--version=0.5.0` was declared”, with `--minor` (0.6.0) named as the way out | `0.5.0` |
| `v1.0.0`, `v2.0.0-alpha1`, then a breaking change | **`3.0.0`** | **refused** — “call for a major” | `2.0.0` |
| `v0.2.0`, `v0.5.0-alpha1`, then a round of features, cut again | would refuse `0.5.0-alpha2` | — | `0.5.0-alpha2` |
| no release, `v1.0.0-alpha1`, then a round of features | `0.1.0` | refused | `1.0.0` |

Two things are visible in it. The version that satisfies the policy — `0.5.0` over `v0.2.0` is the
minor the notes ask for, `2.0.0` over `v1.0.0` is the major — was *refused*, because against
`0.5.0-alpha1` it is a patch. And the escape the refusal printed made it worse: `--weigh` stepped
`bump()` from the dev tag, so a major above `2.0.0-alpha1` is `3.0.0` — two lines ahead of the change
and a version that leaves the `2.0.0` the alpha was cut for unreleased. The worst of it is the third
row, which is not a near miss: `3.0.0` is what a tool that believed `2.0.0` had shipped would say, and
it says it about the one case where the answer is most obvious to the person cutting the release.

### 3. Rung and step are different questions

A rung is relative to the last release that exists. A step is relative to where the branch is. The
promotion makes them different versions — `0.5.0` is a *minor* over `v0.2.0` and no step at all from
`0.5.0-alpha1` — and the script had one variable for both. So there are two now: `$rungBase` (the last
release, for the gate and the plan’s `declared as` line) and `$base` (the tag, for “newer than” and the
step a *flag* asks for). `--weigh` counts from `$rungBase` and offers `releasePart($base)` first.

Lowering the base can only *raise* the rung a declared version is measured at — a base with a smaller
minor makes “the minor moved” likelier, and a base with a smaller major makes “the major moved”
likelier — so no release the old reading allowed is refused by this one. The suite is the second
witness to that: all 119 release tests that predate the rule pass untouched, including the ones whose
base is a dev tag for reasons that have nothing to do with the rung.

---

## The candidates

**A — keep the tag as the base and document `--ignore-policy` for a promotion.** No code, and the
first-release section of `RELEASING.md` already tells an author to declare that bump. Rejected: it
leaves `--weigh` answering `3.0.0` for a `2.0.0` line, which is not a version anyone would accept as
a weighing, and it makes the documented first-release advice into the general rule — every dev line
would have to be released through the override meant for a weighing that misread the tree.

**B — measure the rung from the last release, but keep `--weigh` stepping from the tag.** Fixes the
refusal and nothing else: `--weigh` still says `0.5.1` and `3.0.0`. Rejected as half the bug, and as
the half that is harder to notice, since a version that is *newer* than the tag passes every rail.

**C — take the rung from the last release and offer the promotion (taken).** `--weigh` names the
version the line is being developed for; the gate is measured from the release the line left. What
keeps it from being a licence to ship anything is the second half of the rule: the promotion is
offered only while it still clears what the changes ask for.

---

## What the promotion does not license

`--weigh` prefers the promotion, it does not assume it. When the changes since the tag ask for more
than the promotion climbs, the promotion is refused and the step is taken from the last release:

```
✗ These changes call for a major release, but --version=1.0.1 was declared.

  commits (breaking):
  config (breaking):
  inventory (breaking):
```

`v1.0.0`, `v1.0.1-alpha1`, then a breaking change: `1.0.1` over `v1.0.0` is a patch, so the release
stops, and `--weigh` answers `2.0.0`. That step cannot come out *older* than the tag it replaces,
which is what makes the fallback safe rather than a second bug: a promotion only fails the check by
being a smaller rung than the change set, and a bump of that rung or above from the last release is
newer than any version between the two lines. When the lines are one apart — a patch promotion
against a minor requirement — stepping from the release gives `X.Y.0` where stepping from the tag
would have given `X.Y+1.0`; both are newer, and the release's answer is the one the policy asked
for.

---

## Tests that pin the rules

| Test | Pins |
|---|---|
| `PrereleaseTest::test_a_promotion_is_measured_from_the_last_release_not_from_the_dev_tag` | the refusal that started this: `0.5.0` after a round of features, allowed, with the plan’s `bump` line and the note naming `v0.2.0` as the base it measured from |
| `PrereleaseTest::test_weigh_names_the_release_a_dev_tag_announced_instead_of_stepping_past_it` | `--weigh` over `0.5.0-alpha1` answers `0.5.0`, not `0.5.1`, and a plan writes nothing |
| `PrereleaseTest::test_a_dev_tag_on_a_major_line_does_not_inflate_the_step_the_changes_ask_for` | the `3.0.0`: a breaking change after `2.0.0-alpha1` over `v1.0.0` is `2.0.0`, and the gate lets the declared `2.0.0` through without an override |
| `PrereleaseTest::test_a_promotion_below_what_the_changes_ask_for_is_still_refused` | the rule as a floor: `1.0.1` over `v1.0.0` with a breaking change is refused, and `--weigh` answers `2.0.0` |
| `PrereleaseTest::test_a_second_dev_tag_in_a_lane_is_measured_from_the_last_release_too` | the same base one commit later: `0.5.0-alpha2` after a round of features is allowed, where the tag-versus-tag reading refuses it |
| `PrereleaseTest::test_a_first_release_after_a_dev_tag_is_declared_and_not_refused` | no release at all: the rung is measured from `0.0.0`, `--weigh` names `1.0.0`, and the plan says “none yet, so this is a first release” |

Mutations these were measured against, each applied and run against `PrereleaseTest`: measuring the
rung against the dev tag again fails four of them, stepping `--weigh` from the dev tag fails three,
and making `releasePart()` an identity — offering the prerelease itself as the promotion — fails
four — and the fourth is a test that predates this change: offered the prerelease itself as the
promotion, `--weigh` names the tag that is already there, the “a published tag is never moved or
reused” rail refuses it, and a plan that printed nothing fails an assertion about what it said.

---

## Known limitations

1. **A flag can still skip the promotion.** `--patch` over `0.5.0-alpha1` is `0.5.1`, and `--minor` is
   `0.6.0`, because a flag names the arithmetic it asks for and the step it names is taken from the
   tag the branch is on. That is deliberate — a `--minor` that answered `0.3.0` from an older release
   would be a version older than the tag it is meant to move past — and the promotion’s roads are
   `--weigh` and `--version=0.5.0`, which is now allowed rather than refused.
2. **The base is a question about the line, not about the repository.** `precedingRelease()` reads the
   newest release at or below the line being developed, so a maintenance branch that can reach a
   newer release on another merged line is not held to it. That is the right answer for a
   maintenance release and it is *reasoned*, not pinned: no test drives a tree with two release lines
   reachable whose tags sort against each other.
3. **A promotion still has to satisfy the notes rail.** A breaking change that lands while the line
   is in alpha needs a `### Removed` entry or `--allow-silent-notes`, exactly as it would on a
   stable line. The promotion rule is about the version, and it does not make the release quieter.
4. **`--weigh` cannot cut a dev tag.** On `dev` it answers with a release, which the branch rail then
   refuses, so the next `alpha2` is still a version somebody types. The lane numbering is a separate
   rule, and this one does not reach it.

---

## What would change this decision

- **A `--weigh` that could cut a dev tag.** If it ever answered “which version next” on `dev` too,
  the promotion rule would need a sibling for the lane: the answer there is `0.5.0-alpha2`, not the
  release, and the same “announced rather than held” reasoning decides it.
- **A policy that wants the promotion refused rather than offered.** If prereleases were ever treated
  as releases that happen to be uninstallable, candidate A becomes right and this record is the
  argument against it — but nothing else about the release would change, since the plan already names
  the base it measured from.
- **A release line that ships prereleases to consumers as its normal form.** Then “the release it
  announced” stops being the version to aim at, and the promotion would want to be a flag
  (`--promote`) rather than the default answer to `--weigh`.

---

## Files

| File | Role |
|---|---|
| `bin/release.php` | `precedingRelease()`, `releasePart()`, the promotion branch of `--weigh`, the rung base in the gate, and the plan note that names it |
| `bin/weighing.php` | `bumpFor()`’s 0.x caveat and the four signals — unchanged, and the reason the base is still passed to `weigh()` as the tag |
| `tests/Unit/Release/PrereleaseTest.php` | the six cases above, driven through the real script |
| `tests/Support/ReleaseRepo.php` | the throwaway package every one of them is driven against |
| `RELEASING.md` | the table row, and the [promoting a dev tag](../RELEASING.md#promoting-a-dev-tag) section with the five-row state table |
| `README.md` | the release narrative, which now says a dev tag is weighed as the version it announced |

# Handoff — the state of this package, taken 2026-10-22

This is a snapshot for whoever opens a thread in **this** repository next: what is written
but uncommitted, what is committed but unpushed, what is not in any release a consumer can
install, and the facts a fresh thread would otherwise spend its first ten minutes
re-deriving.

`tests/Unit/Docs/HandoffTest.php` holds this file to the tree, reading it through
`tests/Support/Handoff.php`. The register it reads in is the commit section 2 names as `HEAD`:
every number below is a claim about *that* commit rather than about whatever the branch is
doing when the suite runs. So the test does not mind that the branch has moved on — it fails
when what the note says has been **contradicted**: a path it names is gone, the commit or the
tag it names is gone or reads differently, the tag section 3 calls the latest has been
superseded, or a file it describes disagrees with it. What the test cannot ask is listed at the
end of section 5. A **release** used to be the one event that had to re-take this file, because
section 3's table is a claim about *now*: it names the newest tag, and cutting one falsified that
row in every version of the note written before it — which is what happened to the release this
cycle was cut from, and section 3 says exactly how. Since `44a33da` the release moves those rows
itself, into the commit it makes, so a tag no longer falsifies a note taken before it and what a
re-take is for is section 1 and the register.

Re-read the three commands it was built from before acting on it:

```bash
# from the package root
git status --short                                  # what is uncommitted, and how it is staged
git log --oneline origin/dev..HEAD                  # what is unpushed
php bin/release.php --weigh --branch=dev --dry-run  # what a release would do right now
```

Every number below that the register holds — sections 2 and 3 — was read off this machine when thisnote was re-taken, on 2026-10-22, from the refs this checkout holds; the remote was asked directly (`git ls-remote`, which named the same commit the local `origin/dev` ref does) rather than trusted
from the local ref. Section 1 was re-taken on 2026-10-22, the date above, and it is the one part of
this file that describes *now* rather than the commit it is read against — which this time means the
pending set is empty and the batch this note is taken over is the register's own commit, `98c80df`, so
it is the paragraph under that table rather than a row of it that names the work.

---

## 1. Pending — written, green, uncommitted

**Nothing is pending: the set is empty, and the table under the paragraph that renders it is its two
header rows.** The working tree carries no file the register does not — which is the question the block
asks, `git ls-files --cached --others --exclude-standard` minus `git ls-tree -r` of the register — so
the batch this note lands is a set of modifications to paths a commit already carries, and a
modification is not a row here.

**The batch this note is taken over is the pool-repair ledger, the readiness probe and the doctor's
split pool-probe row, and it is the register's own commit, `98c80df`.** Every repair a container records is now an entry — when, the pool, the mode, and
whether the restart cleared it — instead of only a count and one moment, published as
`pool_repairs.ledger` by `/health/db`, as `ledger` by `windowStatus()`, and as one `repair_N` row per
entry by `db:pgcat-flip --status`. Beside it the controller gained the second action a load balancer
wants, `readiness()`: `status` and `pool_probe` and nothing else, `503` on exactly the state `index()`
fails that block for, and cheap because it runs no query, opens no database connection, reads no state
store and writes no log line. Beside those, `db:doctor`'s `pool probe` row is now `pool probe armed`
and `pool probe answer`, on a `PgcatConfigFlipper::poolProbeArming()` that reads the switch and the
pooler's file without opening a socket — so the bounded run answers the first and leaves out the
second, and the preflight says the same thing at a quarter of the cost. It touches the flipper, its
command and the endpoint, their three tests plus the doctor's and the contract's, and the records that
describe them: `README.md`, `docs/pool-repair-rate.md`, `docs/pgcat-pool-probe.md`,
`docs/health-payload.md`, `docs/db-doctor-json.md`, `docs/deployment-contract.md`,
`docs/pgcat-fleet-inspection.md`, `docs/prose-numbers.md`, and the `## Unreleased` entries that weigh
them. Nothing under `bin/` or `config/` moves, the test support files move only where the note itself
is declared, and no untracked file arrives with it.

**The batch under this one is the pair that answered the suite's two red tests, and it was committed
before this note rather than pending in it.** `83a539b` makes the note's latest-tag guard and the page
guard read the tags this branch carries — `git tag --merged HEAD`, the question `RELEASING.md` builds a
release from, `bin/surface.php`'s `latestTag()` asks, and `bin/release.php`'s rung takes — instead of
every tag the repository has, which is what `v0.2.0-alpha8` had turned red on: that tag is cut on
`release/v0.2.0-alpha8`, two commits `dev` does not contain, so the newest tag anywhere is a release
this line has not taken while the page and the note described `v0.2.0-alpha7`, which is the one it has.
`eee4258` pins the reading where history can no longer decide it — a fixture repository with a tag
behind a branch `main` cannot reach, and the pending tag asserted on both lists — because the difference
between the two lists disappears the day that release branch is merged.

Everything the notes before this one carried is committed on `dev` and unpushed, and section 2 lists
those commits. Section 3's `### Added` is where the prose of a landed batch belongs — a capability a
consumer can install rather than a file somebody has to be told about — and the changelog is where this
cycle's landings are written: the port plan's status guard, the connector boot finding, `/health/db`'s
`connector` key and its two schedule settings, the pooler's mode record with its two writers and the
finding that retired with them, and the two tag readings above. Three paragraphs the note before this
one wrote about the shape of a variant, the deployment contract and the connector describe work a
released section of the changelog carries; what has changed since is section 3's table.

One repair landed before this note, and it is the one this cycle's releases were red on: `bin/release.php`
now moves this file's three tag rows — the tags on the branch, the latest of them and the suffixless
version that is not there — into the release commit, because a tag falsifies all three of them at once
and the push that publishes it comes after. The version in each row moves and the sentence around it is
left as it was, the three ways a note can be unmovable are refusals before anything is written, and the
failing sentence a release's own commit has landed red on twice is section 3.

**All of it but the batch above is committed, and none of it is pushed.** The nineteen commits past `origin/dev` are
section 2's listing rather than a list restated here: the three records of application-side work with the
plan beside them, the third recording skill, the prose-number guard's completeness half, the
quoted-records guard, the doctor record's citation, the connector row's tests, the Laravel plugin's
settings, the handoff note's one-command blocks, the note taken at the alpha7 commits, the two
`/health/db` readings, the pooler's mode record with the finding it retired, the port plan's status
guard, the published config's fresh-install sentence, the payload's key tables read as a set, the Unreleased section's two
`### Fixed` headings merged into one, and this note's two tag readings — each with the changelog entry
that weighs it.

The gate is green with this note in the tree, and the run that says so is the one recorded under
`.agents/gate.json`: the summary and the per-check timings below are that run's, written by
`php bin/checks.php --record`, which renders this block from the record it just wrote — the record and
the quote are one step, so no order of the two leaves the note describing a run it did not take. The
suite's own totals are in its row — tests, assertions and skips, read off PHPUnit's line — and the day
and the interpreter are the record's rather than this sentence's. That day is read against the day in
this note's title, so a note re-taken later cannot go on carrying a run from before it: a re-take that
moves the title forces the gate that verifies it.

<!-- handoff:gate -->
```
php bin/checks.php --record
  --> exit 0: 13 checks: 13 passed, 0 failed, 0 skipped — 682.7s
      recorded 2026-10-22, PHP 8.4.26
      syntax     PASS    19.1s  168 files, no syntax errors
      ast        PASS     1.8s  168 files parsed
      writeback  PASS     0.5s  3 write-back(s) in the shipped tree, every one declared; the detector agreed …
      schema     PASS     2.7s  composer.json is valid; composer.lock is not part of this repository, so it is not checked
      platform   PASS     2.6s  20 platform requirements satisfied
      interpreter PASS     0.1s  PHP 8.4.26 at the interpreter the record names, which satisfies ^8.4
      tools      PASS     0.0s  4 installed: pint v1.32.1, phpstan 2.3.1, phpunit 11.5.57, yaml-lint v7.4.21
      yaml       PASS     1.9s  [OK] All 1 YAML files contain valid syntax.
      counts     PASS     0.6s  56 count(s) across 12 record(s), all in step with their derivations.
      sentences  PASS     0.0s  10088 prose line(s) in 34 record(s), no fused sentence; the detector agreed w…
      phpstan    PASS    24.6s  [OK] No errors
      pint       PASS     6.5s  PASS … 169 files
      tests      PASS   622.2s  Tests: 1631, Assertions: 10954, Skipped: 10.
```
<!-- /handoff:gate -->

The batches the notes before this one called pending are section 3's `### Added` now rather than files
here.

**The release.** `2a771cc` — *Release v0.2.0-alpha7* — is what `bin/release.php` wrote: the entries
under `## Unreleased` promoted into `## 0.2.0-alpha7 - 2026-10-20`, the three `.tsv` records rewritten
with the stamp of the new tag, `API.md` re-rendered from the record it writes, and this file's three
tag rows moved to the version being cut. It was cut with the CI rail skipped: the commit under it,
`563ed95`, had never been pushed and had no run, and the last pushed commit, `b49f3ac`, was red over a
stale `surface.tsv` line — so the tag's own run is the first thing that checked the tree it points
at, and it is green. `docs/issues/issue-release-v0-2-0-alpha7-ci-ungated.md` is the record of that.
The tag is an annotated one (`7b4560c` the tag object, `2a771cc` the commit it peels to) and both are
on the remote, pushed with `git push origin dev --follow-tags` from PowerShell 7 as `PUSHING.md` and
`@pkg-push` require.

**The two repairs before it.** Both were found on the runner rather than on the machine that wrote
them, and both carry the `### Fixed` entries the release then promoted. `929094b` reads section 2 at
the pair the note's own table names rather than at the live `origin/dev`, so a push can no longer make
a committed note stale — and with it, nothing unpushed became a state the renderer writes rather than
one it refuses, which is the state a push and a release both leave. `8742456` binds the pool probe's
five tests to the store they judge: four of them read a run's exit code without arming the store
`db:doctor`'s `store reachability` row PINGs, so on a workstation with a Redis answering they passed
by accident and on the runner all four failed while every row they assert on read correctly.

**What this note lands with.** The register commit it describes is `e8ce7c1` — *docs: the note is
re-taken at 6e1fb0c, and the gate it quotes is this run* — the re-take that moved this file to the
commit under it without touching a line of `src/`. Under it sits `6e1fb0c` — *fix: the Unreleased
section carries one `### Fixed`, not two* — the commit that merged the second `### Fixed` heading a
batch had appended under `## Unreleased` back into the first, so the section states one count per
heading and the weighing reports four fixed entries rather than `3` and `1` on two lines. Under that
sits `eee4258`, the tag readings pinned on a repository that is not this checkout — a fixture with a
tag behind a branch `main` cannot reach, which is the history this checkout may stop having the day
the alpha8 release branch is merged — and under that `83a539b`, the reading itself. Under those sit the
sixteen commits section 2 lists behind them.
The note is prose only, which is what a re-take is: the rendered blocks come out of git, the inventory
check and the last recorded gate run, and nothing else in it claims a fact a command could not answer.
A release reads it too: `bin/release.php` refreshes the five blocks at the register this note names —
`bin/handoff.php --recorded`, which is a render and not a re-take — and then runs `HandoffTest` against
the commit it just made, with the tag being created passed to it as the one pending tag. A red guard is
refused before the tag exists, the release unmakes its own commit with `git reset --soft` and leaves the
files staged, so a note that would be red at a release commit is a version nobody is handed rather than
a runner finding out after the push.

### The records carry the stamp of the tag they were written at

The three `.tsv` records describe the tree `v0.2.0-alpha7` was cut from, and they say so in their
header line — the release rewrites them, which is why the record of one tag is the record of no later
tree. That is the check reading the stamp and the rows: against the register this note names it exits
1 on one line per record, because the tree that commit carries is not the tree the records were
written at; and against this checkout it exits 1 on two of the three, because the branch has moved
twenty commits past the tag the release wrote them at — the stamp names `v0.2.0-alpha7`, which is
the tag section 3 calls latest, and the rows under it are that tag's tree rather than this one:

<!-- handoff:records -->
```
php bin/inventory.php --check --at=98c80df
  --> exit 1: the inventory is out of step with the tree at 98c80df
      files.tsv: 1 line(s) stale
      methods.tsv: 1 line(s) stale
      surface.tsv: 1 line(s) stale

php bin/inventory.php --check
  --> exit 0: inventory is current — 53 files, 262 public methods, 306 key(s)/member(s), described as v0.2.0-alpha9
```
<!-- /handoff:records -->

Both lines are rendered by `php bin/handoff.php` and run by the guard rather than trusted, so the
counts in them are the check's own reading of this tree rather than a session's: a source file added
moves them the next time the note is taken, and nothing here has to be re-typed. The split is the
intended state rather than a finding: those three files describe the tree **at a tag**, the next
release rewrites them and commits them, and the check's own message offers exactly those two answers
(*"Run php bin/inventory.php to write them and commit them, or leave them alone and let the next
release refresh them"*). Do not "fix" them by hand as a favour — a record written at some other
moment describes a tree no tag points at, which is the thing the fourth weighing signal reads.

The size of the pending set is read off the tree rather than stated here: `git status --short` prints
the whole of it against the commit section 2 names as `HEAD` — the tracked paths that changed and the
files no commit carries — because a count of uncommitted paths is a fact about one working tree, and
a committed file cannot be asked for it.

The table under it is **rendered rather than written**, as are the gate summary above it, the
transcript of the records and section 2's table and the listing under it. Its rows are the files this
checkout has that the register does not carry (`git ls-files --cached --others --exclude-standard`
minus `git ls-tree -r` of the register), one row apiece, with the prose column declared in
`tests/Support/Handoff.php` beside the renderer — the one input git cannot answer. Only a file no
commit carries is on it, which is why this take's table is its header rows and nothing else: the batch
it lands was committed before it, and a tracked path's modification is not a file the register lacks,
while a file the register already carries is a file somebody has committed. `php bin/handoff.php` writes all five blocks — three from git, one from the inventory check
and one from the recorded run — and the note's own guard renders the same five and asserts the file
says what they say, so a re-take cannot state a set of files, a count or a listing the tree does not
have. A file the register does not carry and that has no line declared for it is refused by name
rather than rendered blank, which is the same sentence the guard gives: name it, or take it out of
the tree.

<!-- handoff:pending -->
| File | What it is |
|---|---|
<!-- /handoff:pending -->

The pgcat work this cycle's release carried was described by the note before this one and is released
by it, and the changelog is where its prose lives now rather than here: the entries the tag carries
are its record. What is still a boundary rather than a record — what is released and what
an application runs — is section 3's, because none of it reaches an application until the pin in
section 4 moves.

---

## 2. Unpushed — the commits on `dev` no remote has

<!-- handoff:unpushed -->
| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `98c80df` — *feat: each pool repair is readable as a ledger, and readiness answers the probe alone* |
| `origin/dev` | `3dc70db` — *docs: the alpha6 CI record carries its outcome, and the green run that answers it* |
| Unpushed | 19 commits — the ones `git log --oneline origin/dev..HEAD` prints |
| Since the last tag | chore 1, docs 6, feat 6, fix 2, test 4 |
<!-- /handoff:unpushed -->

The `HEAD` row is the basis every number in this file is read against, and the table above it is
rendered: the register and the remote tip are commits, and the count and the breakdown are what
`git log --oneline origin/dev..HEAD` says between them. The tip of `origin/dev` was asked of the
remote directly (`git ls-remote`, not the local ref) when this section was taken, which named the
same commit the local ref does.

The register is nineteen commits ahead of `origin/dev`, and the table above is where they are counted: the
records of application-side work and the plan beside them, the third recording skill, the two halves the
prose-number pass added, the doctor record's citation, the connector row's tests, the Laravel plugin's
settings, the handoff note's one-command blocks, the note taken at the alpha7 commits, `/health/db`'s two
schedule settings and its `connector` key, the pooler's mode record with the both-armed finding retired,
the port plan's status guard, the published config's fresh-install sentence, the payload's key tables
read as a set, the Unreleased section's two `### Fixed` headings merged into one, and this note's two
tag readings — the release commit itself, `2a771cc`, and the alpha6 record, `3dc70db`, are on the
remote already.

Unpushed at the register means what it says, nineteen commits no remote has — which is the state the
release this cycle cut was *not* in: `v0.2.0-alpha7` was cut with the CI rail skipped, from a commit that
was not the tip of `origin/dev`, and the paragraph above the release section records that. The `ci` line
of a plan is what refuses over it until the push below happens: `bin/release.php` refuses to tag while
`HEAD` is not the tip of `origin/dev`, and it ends by pushing the commit and the tag together, so every
commit the release made is one a consumer can fetch. This note is the commit *after* the register, and
it travels on the push below.

<!-- handoff:log -->
The ten most recent are the ones a reader is most likely to be standing in the middle of:

```
98c80df  feat: each pool repair is readable as a ledger, and readiness answers the probe alone
e8ce7c1  docs: the note is re-taken at 6e1fb0c, and the gate it quotes is this run
6e1fb0c  fix: the Unreleased section carries one `### Fixed`, not two
eee4258  test: the two tag readings are pinned on a repository that has both kinds of tag
83a539b  test: the two guards that read every tag in the repository now read the tags this branch carries
15fc867  docs: the payload's key tables are compared as a set, so their order is not the contract
971d20e  docs: the published config row's fresh-install answer is one sentence in the four records that state it
d6bff09  feat: the port plan's status table is read as data, and held to the tree
a9b70da  feat: the pooler's mode is one record with two writers, and the both-armed finding is retired
eb3a48a  feat: /health/db publishes the connector that builds the followed connection
```
<!-- /handoff:log -->

The fence above is a ceiling rather than the whole range: `git log` is asked for the whole of it and the
block carries what it prints up to that ceiling, so a range longer than the ceiling leaves its oldest
commits to `git log --oneline origin/dev..HEAD`, which is the whole of it in every state. At this
register the range is nineteen commits, past the ten the block holds, so nine of them are left to
that command and the ten a reader is most likely to be standing in are the ones the block prints.

One consequence of that state is worth naming before the next release: the interlock the release checks
is *unsatisfied* at this register — `HEAD` is nineteen commits past `origin/dev` — so the next version
needs the push below first, and its Unreleased section carries twenty-four entries where the release this
cycle cut promoted the four before it. Section 3's plan is a transcript taken before this cycle's tags;
a run against this register would plan a minor above `v0.2.0-alpha7`, and the `### Removed` entry among
those twenty-four is what asks for it.

The push itself goes through PowerShell 7 and nothing else; `PUSHING.md` is the runbook, and
`@pkg-push` wraps it:

```powershell
# from the package root
git push origin dev --follow-tags
```

---

## 3. Unreleased — built, tested, and in nothing a consumer can install

| Field | At the snapshot |
|---|---|
| Tags on the branch | `v0.0.1-alpha1`, `v0.1.0-alpha1`, `v0.1.0-alpha3`, `v0.2.0-alpha1`, `v0.2.0-alpha2`, `v0.2.0-alpha3`, `v0.2.0-alpha4`, `v0.2.0-alpha5`, `v0.2.0-alpha6`, `v0.2.0-alpha7`, `v0.2.0-alpha9` |
| The latest of them | `v0.2.0-alpha9` |
| A version with no suffix | none — `v0.2.0` does not exist |
| `## Unreleased` at the basis | 24 entries — 13 added, 4 fixed, 6 changed, 1 removed |
| The notes weigh as | `breaking` — the `### Removed` heading is the loudest one the notes use, and it is a minor rather than a major while the package is pre-1.0 |

The absent version is the exact sentence that decides whether the next one may be a prerelease, and it
may: a suffixless version above the tag just cut is still the lane ahead. It is the **smallest** such
version — the release a prerelease announces, or the next patch above a release — and from `44a33da` a
release writes it rather than a re-take, which is the same rule read backwards: this row said `v0.2.0`
while the newest tag was `v0.2.0-alpha3`. The headings the weighing reads are the `### Added`,
`### Changed`, `### Fixed` and `### Removed` a Keep a Changelog section writes, and the section carries
thirteen entries under `### Added`, six under `### Changed`, four under `### Fixed` and one under
`### Removed` at the register, which `bin/weighing.php` reads as `breaking` — the bump a `### Removed`
entry asks for, which a 0.x line takes as a minor. The register is therefore a branch whose release
would publish the port plan's status guard, the pooler's mode record and the finding it retired,
`/health/db`'s `connector` key with its two schedule settings, the connector boot finding, the three
records of application-side work with the third recording skill beside them, the two halves the
prose-number pass added, and this note's two tag readings.

### The release that cut this tag, and the three rows it could not write

`v0.2.0-alpha3` was cut and pushed on 2026-10-11 from `8742456`, and the commit it made is red — the
same way `v0.2.0-alpha2`'s was. The failure is one row of this note — the one the guard reaches first —
on both runs the push started
(the branch run `38166456798` and the tag run `38166456799`):

```
HANDOFF.md says v0.2.0-alpha2 is the latest tag, and v0.2.0-alpha3 is — so the lane the note
describes has been released past, and section 3 has to be re-taken before the next tag is cut.
```

Nothing about the released code is wrong: every other check passed, and the tree the tag names is the
approved one. What was wrong is the order — the note is re-taken *after* a tag in this cycle, and the
three rows above are the ones a tag falsifies, so the release's own commit always landed red on the
third of them. What the *tag* saw cannot be repaired, because a published tag is a version a consumer
may already hold and moving it is not a fix; what the branch could do about it, it now does. `44a33da`
gives `bin/release.php` those three rows: it moves them to the version it is cutting, in the release
commit, before the tag exists, so the next release's commit carries a note that names the tag under it
and CI reads a note rather than a stale one. Only the version in a row moves, and the three ways a note
can be unmovable — a row read by a label the note does not write, a row with no version in it, and one
label carried by two rows — stop the release with the tree as it was. `HandoffNoteTest` holds all of it,
including that the three lines it changes are the three `Handoff::tagRowLines()` reads.

### The release the weighing would ask for now

Asked on 2026-10-17, with the repairs in the tree, what a release would do, it weighed a `patch` — the
two `### Fixed` entries the section carried then — and planned a prerelease of the lane below the tag
this note calls latest, declared rather than weighed because the rung those changes called for was the
suffixless one:

```bash
php bin/release.php --version=0.2.0-alpha5 --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha4
  bump          minor  (declared as --version=0.2.0-alpha5; the changes call for patch)
  next version  0.2.0-alpha5  (tag v0.2.0-alpha5)
  prerelease    alpha — Composer stability "alpha", so a consumer has to opt in
  CHANGELOG     ## Unreleased -> ## 0.2.0-alpha5 - 2026-10-17, new Unreleased section above
  release notes 2 bullet(s) in the promoted section
  inventory     49 file(s), 246 method(s), 280 key(s)/member(s), described as v0.2.0-alpha5  (fresh, weighed against v0.2.0-alpha4)
  api page      47 class(es), 246 public method(s), described as v0.2.0-alpha5
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  handoff note  HANDOFF.md — Tags on the branch, The latest of them moved to v0.2.0-alpha5
  note guard    HandoffTest at the commit this tags — nothing is tagged if it is red
  commit        Release v0.2.0-alpha5
  tag           git tag -a v0.2.0-alpha5 -m "v0.2.0-alpha5"
  push          git push origin dev --follow-tags
```

Two lines of it are the state this note describes rather than a step of a release. `ci` is the
interlock the unpushed commits leave: a real run refuses to tag until they are on `origin/dev`, which
is section 2's job and the `pkg-push` runbook's rather than this note's. `branch` is `(dirty)` because
the re-take is measured in the working tree it was taken in; the note commit is what makes it clean.
The `handoff note` line says what the release would move in this file, and it names two rows rather
than three: the absent version this note already carries is `v0.2.0`, so the row is moved to the
version it holds.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers in
it that *are* checkable are held, though they are two different readings: the entries the notes carry
**at the register** (the table at the top of this section — twenty-four) and the state of the three records
(section 1, whose transcript the guard runs). Its own `2 bullet(s)` is the weighing's answer about the
working tree, which no guard here holds.

### What is actually in the unreleased section

Twenty-one entries at the register, and they are this cycle's landings rather than a repair of the
release before it: the port plan's status table read as data and held to the tree, the connector boot
finding with the `/health/db` key beside it, three records of application-side work and the third
recording skill, the prose-number guard's completeness half and the guard that holds one record's
quotes to another's words, the pending table's readings, `/health/db`'s two schedule settings and the
`swrr.audit.finding_seconds` bound beside them, the pooler's mode record shared by its two writers with
the both-armed finding retired — and, at the end, the two tag readings this note lands. One entry is
under `### Removed`: `swrr.pgcat.schedule.both_armed` and the constant that named it, which is why the
section weighs `breaking` while the next version may still be a minor.

Everything the release before this one carried is in `v0.2.0-alpha7`: the schedule that can be watched,
the api page a past tree renders, the published config that says which key set it is, the paths a flip
cycle writes, the supervisor's three failures, the read path with a silent schedule's causes and the
bounded preflight, the shape of the file a flip would swap in, the deployment contract, the connector a
connection is built through, the release that moves this file's tag rows, and the two repairs CI found
for it. What those change for a consumer is written in the released section rather than repeated here.

### The boundary flip, in a tag and in no installation

The one change in the released work that a monitor or a deploy gate can feel is the **boundary flip**:
`db:pgcat-window-flip` (`src/Console/Commands/DbWindowFlipCommand.php`) and the reader windows as
scheduled tasks the package registers are in `v0.2.0-alpha2` and carried forward into
`v0.2.0-alpha3`, so the released lane has nothing to add to them. The pooler left on the writer-only
config inside an open reader window answers `degraded` at HTTP 503, and so does the same fault from
the other end — the readers config left in place outside every window — because what the verdict reads
is whether the file and the windows disagree, not which of the two is ahead. Which end a failure is at,
rather than whether there is one, is this cycle's: `direction` (`inside_window`, `outside_window`) and
the `db:doctor` row that prints it came with `v0.2.0-alpha3`, like the pool probe's `repaired` kind and
the two surfaces that read it.

---

## 4. Unpacked — the application side has none of it

A tag in this package changes nothing an application runs until the application's pin moves. The
consumer here is the LPR app, and the last reading of its pin — taken by hand on 2026-10-06, before
`v0.2.0-alpha2` existed — was:

```
site/composer.json  →  "uak35/laravel-weighted-dbmanager": "v0.2.0-alpha1"
```

That is a fact about **another repository**, which this one's test cannot ask about and this
thread did not re-read: it is written here as the last reading rather than as today's pin, and
the pin may have moved since. So everything section 3 calls released — the boundary flip
included — is in a tag and in no installation until that pin moves, which is the difference
between "the flip is implemented", "the flip is released" and "the flip is deployed" that a fresh
thread can otherwise spend a long time establishing.

What publishing this release still needs, and what is done:

1. the release itself — cut, tagged and pushed as `v0.2.0-alpha3`, on a commit the CI rail had
   verified, without `--skip-ci`: done;
2. this note, re-taken at the register the release left — and at the twenty commits this cycle has
   landed on top of it, because a release is the state of the branch and not only of the tag —   and
   landed as a commit of its own on `dev`: this commit, the commit the register is followed by;
3. raise the consumer's pin, in the application repository, to the new tag;
4. the application-side switches and schedule entries a flip needs, which live in that repository
   and are not part of this package.

Steps 3 and 4 are **outside this repository**. Nothing here can do them, and a change in this
repository is not an installation change until each of them happens.

---

## 5. Working here — the facts that do not change

| Field | Working here |
|---|---|
| PHP | The binary `.agents/machine.local.json` records as `php.exe` (8.4.26). It is **not on `PATH`** in the shell, so a bare `php` does not resolve — which no longer decides which interpreter a command runs on: every script under `bin/` re-runs itself under the recorded one, so start it with whatever `php` this shell has and the work happens on the recorded interpreter. |
| Composer | 2.10.3. `composer.phar` sits in the package root and is **gitignored**, so a test must never shell out to composer. |
| Git identities | `origin` is HTTPS (`https://github.com/UAK-35/laravel-weighted-dbmanager.git`), `commit.gpgsign=true`, `user.signingkey=9CE840D871DE7A62`, and `core.hooksPath` is unset — this repository has **no** pre-commit gate of its own. |
| Skills | `.agents/skills/{pkg-commit,pkg-push,pkg-deploy,wdm-issue,wdm-investigation,wdm-plan}/SKILL.md`, indexed by `.agents/README.md`. Hand-maintained — no `.skills/` sources, no generator, no `verify.py` — so a file there is the only copy. |
| Checks | `php bin/checks.php --list` names thirteen; use `--only=` subsets. The full run and the whole `tests/` tree both exceed what a single agent command is allowed to take, so run one file or one directory at a time. The whole tree takes about a quarter of an hour on the machine that wrote this note, which is long enough for the JSON report `DbReplicaStatusTest` compares against a second reading of the file to land either side of a second boundary and fail on `checked_at` — a clock rather than a reading. `interpreter` is the one that reads the machine record — it fails on a `php.exe` that is gone, one that starts and cannot read the script it would be run on, one that reports another version, or one below the requirement the record carries. |
| Style gate | `php bin/tool.php pint --test` (PSR-12). |
| Static analysis | `phpstan.neon.dist`: level `max`, over `src/` only — a file under `tests/` is style-checked but not analysed. |
| In a consumer's tarball | `docs/` |
| Left out of it | `tests/`, `.agents/`, `git/commit_paths.py`, `bin/api.php`, `bin/checks.php`, `bin/counts.php`, `bin/publish-config.php`, `bin/release.php`, `bin/surface.php`, `bin/weighing.php`, `bin/inventory.php`, `bin/blame.php`, `bin/tools.php`, `bin/tool.php`, `bin/interpreter.php`, `bin/handoff.php`, `bin/composer-link.cmd`, `bin/composer-ca.ps1`, `CHANGELOG.md`, `RELEASING.md`, `PUSHING.md`, `STATIC-ANALYSIS.md`, `HANDOFF.md`, `AGENTS.md`, `BEHAVE.mdc`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `.gitattributes`, `.gitignore`, `.editorconfig`, `.idea`, `files.tsv`, `methods.tsv`, `surface.tsv` |
| Text search | `rg` (ripgrep 15.2.0) is on `PATH`; `grep` works too. |
| Machine record | `.agents/machine.local.json` — the facts above as the machine running the suite holds them, compared by `tests/Unit/Docs/HandoffTest.php`; gitignored, so a clone without the file skips that comparison. |

The two tarball rows are read out of `.gitattributes` as it stands, and this is the one row in
this table that is a **decision** rather than an observation: every script under `bin/` is
export-ignored, so a consumer's copy carries none of them, and the row above names `docs/` as what
this tree contributes to one.

The three that were the exception are the tools that write what a release reads —
`bin/api.php` renders `API.md` from the inventory, `bin/counts.php` renders the counts the records
state, `bin/publish-config.php` copies the sample config — and they were left shipping as observed
rather than decided. Holding them back is the decision, and it moves the published README with it:
the section that describes the `bin/` group is now a checkout's tools, and the commands that run
one are marked as a checkout's rather than an installation's.

`tests/Unit/Docs/DocCitationsTest.php` treats a `test_…` name in a record as a citation, and
refuses one no class declares. Its corpus is `README.md`, `RELEASING.md` and `docs/*.md` — this
file is not in it, which is why the test named at the top of this one reads it instead.

The table above is held rather than trusted. `tests/Unit/Docs/HandoffTest.php` compares each row
with `.agents/machine.local.json`, the machine's own copy of those facts, and
`tests/Unit/Docs/HandoffEnvironmentTest.php` compares the rows that describe a machine with the
machine this suite is running on: the named `php.exe` run for its own `PHP_VERSION`, a bare `php`
attempted so that the row's claim about `PATH` is measured rather than assumed, `php composer.phar
--version`, `rg --version`, `git remote get-url origin` and the three git settings — where `git
config` exiting 1 is the "unset" the row claims — `pint.json`'s preset through `bin/tool.php`, and
the maintenance sentence: no `.skills/` sources, no generator, and no Python among the files a commit
would carry except the commit path under `git/`, which is a tool rather than a generator. Where a row
describes a machine that is not this one the comparison skips rather than
fails, which is what a fresh clone and CI's runner see — the same shape as the record itself:
absent is a skip, present and different is a failure.

What follows was measured rather than argued: the reading's two directions and the renderer's two
refusals were driven by control, each edit restored afterwards — byte for byte, or by re-rendering —
and the tag row's teeth were measured by CI itself, because no control can be run for the one row a
tag falsifies. That row is the real thing twice over. The run for the release commit this note is
taken after failed with *"HANDOFF.md says v0.2.0-alpha2 is the latest tag, and v0.2.0-alpha3 is — so
the lane the note describes has been released past, and section 3 has to be re-taken before the next
tag is cut."*, and the run for `v0.2.0-alpha2`'s own commit failed with the same sentence one version
back (*"…says v0.2.0-alpha1 is the latest tag, and v0.2.0-alpha2 is…"*). A row a release commit could
not write was a row the tag it cuts was always red on, which is the finding section 3 carries — and
since `44a33da` the release writes it: the row goes into the release commit, so the teeth above are
now this note's guard against a tree the release has already left, which is what `HandoffNoteTest`
drives.

The records reading has two directions and both were measured. `exit 1` written into the transcript
line that quotes the check of a current tree — the note claiming records out of step where the command
exits 0 — fails `HandoffTest` with *"HANDOFF.md line 88 quotes `php bin/inventory.php --check` and
writes exit 1 beside it, and the command exits 0."*; `exit 0` written over the failing line — the note
claiming a current tree where the check of the tree the release was cut on exits 1 — fails it with
*"HANDOFF.md line 85 quotes `php bin/inventory.php --check --at=8742456` and writes exit 0 beside it,
and the command exits 1."* The second direction is the one the reading was written for: the fixed `1`
this replaced could not tell a note that had been taken at a released register from one that had
gone stale, and it refused the first.

The renderer's teeth were measured the same way, and the edit was restored by re-rendering rather
than by hand. A count in a rendered block edited to disagree with git — `| Unpushed | 0 commits …`
written `| Unpushed | 1 commit …` — is refused by `php bin/handoff.php --check`, which prints *"  unpushed
out of step"* and *"1 block(s) out of step — run `php bin/handoff.php` to render them."* and exits 1,
and by the block test with *"HANDOFF.md's `unpushed` block is not what git says it is. The block is an
output rather than a claim: run `php bin/handoff.php` and it follows the tree, so neither a set of
files nor a count in it can be stated by whoever re-takes the note."*; running the renderer put the
block back and left the note byte-identical to the file the suite reads.

The other direction is the file the register does not carry and that no words are declared for, which
is the control the empty table needs. With one untracked file at the root —
`handoff-control-undeclared.md`, not ignored, so it is part of the pending set — `--check` exits 1 with
*"The blocks could not be rendered: The register does not carry handoff-control-undeclared.md and no
words are declared for it. A prose column cannot be derived, so add one line about it to
Handoff::PENDING_DESCRIPTIONS — or take the file out of the tree, since a file that is not work is one
.gitignore should carry."*, the block test errors on the same sentence rather than rendering a row with
a blank prose column, and the test that holds the paths no commit carries against the paths section 1
names fails with *"This checkout holds 1 file(s) that the commit section 2 names as `HEAD` does not
carry, and section 1 names 0 of them. A file nothing names is work the next thread cannot see:
- handoff-control-undeclared.md"*. That is the reading that makes an empty table safe rather than
blind: a file nobody names fails whether the table has rows or not. Removing the file put the note
back in step, and the table was the two header rows it was meant to be while the pending set was
empty — which is the state this take leaves it in, the register being the commit that landed the last
batch.

### What the test above cannot ask

Three claims in this file are a reader's to judge, and are named here so that a green suite is
not mistaken for a checked file:

* **the remote**. The tip of `origin/dev` was asked of GitHub on the day; the test holds the
  commit that answer named, and the commits between it and the basis, but not the ref it sits
  on — a fetch that moved `origin/dev` would not fail it.
* **the plan and the evidence blocks**. The release plan in section 3 and the runs quoted in
  section 5 were runs against this working tree. Their checkable arithmetic is held — the entries the
  notes carry at the register, and the commands section 1's transcript quotes, which the guard now
  runs — and the plans themselves are not.
* **the pinned version in section 4**, which is a fact about the application repository — and,
  this cycle, a reading taken six days before the release it names.

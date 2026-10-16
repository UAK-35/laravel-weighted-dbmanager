# Handoff — the state of this package, taken 2026-10-16

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
end of section 5. A **release** is the one event that has to re-take this file, because section
3's table is a claim about *now*: it names the newest tag, and cutting one falsifies that row in
every version of the note written before it — which is what happened to the release this cycle
was cut from, and section 3 says exactly how.

Re-read the three commands it was built from before acting on it:

```bash
# from the package root
git status --short                                  # what is uncommitted, and how it is staged
git log --oneline origin/dev..HEAD                  # what is unpushed
php bin/release.php --weigh --branch=dev --dry-run  # what a release would do right now
```

Every number below that the register holds — sections 2 and 3 — was read off this machine on
2026-10-15, with the remote asked directly (`git ls-remote`, which named the same commit the local
ref does) rather than trusted from the local `origin/dev` ref. Section 1 was re-taken on 2026-10-16,
the date above, and it is the one part of this file that describes *now* rather than the commit it is
read against — which this time means a batch that is written and green and not yet committed.

---

## 1. Pending — written, green, uncommitted

**Three batches are pending, and nothing in any of them is committed: the bounded preflight, the
causes of a silent schedule, and the read path's two cancellations.** The first is the doctor's
newest row and its newest mode. `boot environment` reports the file this boot read its settings from
(`environmentFilePath()`, so a `loadEnvironmentFrom()` name is reported rather than a hardcoded
`.env`) and whether the configuration is cached — warning when the cache predates the file, which is
the shape an image that caches at build time and mounts its environment at run time has — and the
object publishes `swrr_connection` beside the `default_connection` it followed, because unset and
set-to-the-default are the same routing and two different things to change. `--connection-only`
then leaves four rows out entirely — the store's two (`store probe`, `store reachability`), the
supervisor's (`pgcat supervisor`) and the pooler's (`pool probe`) — because a row that cannot be
answered reports the container's own missing prerequisite, so an image was failed over a directory
the image never owned.

The second is what a `stale` verdict cannot see. `stale` answers whether the per-minute entry is
producing runs, and its sentence names the cause that is nearly always the right one — nothing is
running it — while three others leave the state file it reads unchanged: the store the runs' overlap
mutexes live in and whether it answered a read, how many entries on the built schedule run
`db:pgcat-flip`, and the file the boot window is measured from with the mode and owner it was found
with. All three are read beside the verdict — under `causes` in `db:scheduler-watchdog`, and on
`db:doctor`'s `schedule beat` row — and none of them moves an exit code, because they are readings
rather than verdicts.

The third is the read path. This package chooses the endpoint a read lands on, and that endpoint can
cancel the read as `40001` — a standby cancelling the query rather than delay recovery for the row
versions its snapshot needed — or `57014`, its own `statement_timeout` being reached. Neither is
visible from Laravel's configuration, because a connection names hosts and a parameter group names
what they do, so an installation could route every read at an endpoint capped at a fraction of the
writer's while every row read well. Three rows read what a routed read can expect — `read caps`,
`standby feedback` and `read/write pair` — the first two behind `swrr.read_path.enabled`, off in the
shipped config because they are one connection per endpoint, and the third asking nothing at all;
and the contract an application answers the two cancellations with, with the measurement it came
from, is `docs/read-path-cancellations.md`.

**Six files are new** — the six rows of the table below. For the schedule's causes:
`src/Pgcat/ScheduleCauses.php`, `tests/Support/FakeCache.php` and
`tests/Unit/Pgcat/ScheduleCausesTest.php`. For the read path:
`src/Database/Weighted/EndpointReadings.php`, `tests/Unit/Weighted/EndpointReadingsTest.php` and
`docs/read-path-cancellations.md`. The edits are `src/Console/Commands/DbDoctor.php` (the
`boot environment` row, the bounded mode's list, the `schedule beat` readings and the three read-path
rows with the switch that arms them), `src/Database/Weighted/SingleHostProbe.php` (the read path's own
transport: one query to one host, on a connection purged whatever the query did),
`src/Pgcat/FlipSchedule.php` (one reading of "how many entries run this command", shared by the
duplicate guard and the report), `src/Pgcat/PgcatConfigFlipper.php` (its mode-and-owner reading made
public, so a stamp quotes the same facts the file rows do),
`src/Console/Commands/DbSchedulerWatchdog.php` (the causes under the verdict and on the rendered line,
with the store named rather than asked), `config/db-manager.php` (the read path's switch and its
bound), and the suites and records each of them is read through: `tests/Unit/Console/DbDoctorTest.php`,
`tests/Unit/Console/DbSchedulerWatchdogTest.php`, `tests/Unit/Pgcat/FlipScheduleTest.php`,
`tests/Support/Handoff.php` for the words above, `README.md`, `docs/db-doctor-json.md`,
`docs/documented-exit-codes.md`, `docs/prose-numbers.md`, `docs/scheduler-watchdog.md`,
`docs/switch-values.md` and three `## Unreleased` entries in `CHANGELOG.md`.

**Nothing in those batches is committed yet.** Every commit this note *lands* is on `dev` with the commit
before it: the readings and the port plan (`3f25180`), the schedule watchdog (`af1fddb`), the api page
a past tree renders (`e06098b`), the published config's generation (`bbfd422`), the paths a flip cycle
writes (`f782eb0`), the supervisor's three failures (`3c2e61f`), the two mechanisms that can move the
pooler being reported by name (`5af2df5`) and the note that describes them (`fbec6d6`), each with the
changelog entry that weighs it. The batches above sit on top of all of it, written and green —
`DbDoctorTest` 179 tests, `DbSchedulerWatchdogTest` 33, `ScheduleCausesTest` 15 with one skip on this
workstation, `EndpointReadingsTest` 27, `tests/Unit/Docs` green — and waiting for a commit; the schedule
watchdog the note before this one called pending is section 3's `### Added` now rather than a file
here.

**The release.** `33afd4e` — *Release v0.2.0-alpha3* — is what `bin/release.php` wrote: the sixteen
entries under `## Unreleased` promoted into `## 0.2.0-alpha3 - 2026-10-11` (six `### Added`, six
`### Changed`, four `### Fixed`), the three `.tsv` records rewritten with the stamp of the new tag,
and `API.md` re-rendered from the record it writes. It was cut on `8742456` and not on `--skip-ci`:
the script's CI rail refuses to tag a commit no run has verified, and `8742456`'s run — `38166276765`,
success in 1m9s — is the one it read. The tag is an annotated one (`8b6aa3da` the tag object,
`33afd4e` the commit it peels to) and both are on the remote, pushed with `git push origin dev
--follow-tags` from PowerShell 7 as `PUSHING.md` and `@pkg-push` require. The commit the release made
is red on CI's runner, and that is a finding rather than an accident — section 3 has it, because it is
about the one row a release falsifies.

**The two repairs before it.** Both were found on the runner rather than on the machine that wrote
them, and both carry the `### Fixed` entries the release then promoted. `929094b` reads section 2 at
the pair the note's own table names rather than at the live `origin/dev`, so a push can no longer make
a committed note stale — and with it, nothing unpushed became a state the renderer writes rather than
one it refuses, which is the state a push and a release both leave. `8742456` binds the pool probe's
five tests to the store they judge: four of them read a run's exit code without arming the store
`db:doctor`'s `store reachability` row PINGs, so on a workstation with a Redis answering they passed
by accident and on the runner all four failed while every row they assert on read correctly.

**What this note lands with.** Nothing beside it: the register commit it describes is `5af2df5`, and
what that one changes is section 3's `### Added` — two mechanisms that can own the pooler's mode at
once are now reported by name rather than passed over by `db:doctor`'s `flip schedule` row, which is a
sentence in `FlipSchedule`, a boot finding (`swrr.pgcat.schedule.both_armed`) and a warning that
`--strict` fails — with the two `### Fixed` entries the published copy's own comments had contradicted.
The note itself is prose only, which is what a re-take is: the rendered blocks come out of git and
nothing else in it claims a fact a command could not answer.

### The records carry the stamp of the tag they were written at

The three `.tsv` records describe the tree `v0.2.0-alpha3` was cut from, and they say so in their
header line — the release rewrites them, which is why the tree at the register is current and the
tree the release was cut on is not. That is the check reading the stamp rather than the rows: against
the commit the release was cut on it exits 1 and names all three, one stale line each — and against
this checkout, which carries work no tag has, it exits 1 as well, naming the source files and the
settings this cycle added, which the release's own records do not carry yet:

```
php bin/inventory.php --check --at=8742456
  --> exit 1: files.tsv, methods.tsv and surface.tsv, 1 line(s) stale each — the stamp

php bin/inventory.php --check
  --> exit 1: the inventory is out of step with this tree — 5, 30 and 30 line(s) stale in files.tsv,
      methods.tsv and surface.tsv, each naming the row it does not hold
```

Both lines are run by the guard rather than trusted, which is the change above. The split is the
intended state rather than a finding: those three files describe the tree **at a tag**, the next
release rewrites them and commits them, and the check's own message offers exactly those two answers
(*"Run php bin/inventory.php to write them and commit them, or leave them alone and let the next
release refresh them"*). Do not "fix" them by hand as a favour — a record written at some other
moment describes a tree no tag points at, which is the thing the fourth weighing signal reads.

The size of the pending set is read off the tree rather than stated here: `git status --short` prints
the whole of it against the commit section 2 names as `HEAD` — the tracked paths that changed and the
files no commit carries — because a count of uncommitted paths is a fact about one working tree, and
a committed file cannot be asked for it.

The table under it is **rendered rather than written**, as are section 2's table and the listing under
it. Its rows are the files this checkout has that the register does not carry (`git ls-files --cached
--others --exclude-standard` minus `git ls-tree -r` of the register), one row apiece, with the prose
column declared in `tests/Support/Handoff.php` beside the renderer — the one input git cannot answer.
It carries six rows this time — the files the batches above add, one row apiece — and a row in it is
the register saying it does not carry that file. `php bin/handoff.php` writes all three blocks from git,
and the note's own guard renders the same three and asserts the file says what they say, so a re-take
cannot state a set of files, a count or a listing the tree does not have. A file the register does not
carry and that has no line declared for it is refused by name rather than rendered blank, which is the
same sentence the guard gives: name it, or take it out of the tree.

<!-- handoff:pending -->
| File | What it is |
|---|---|
| `docs/read-path-cancellations.md` | the record for them: the two cancellations and the SQLSTATE that tells them apart, where this package stops rather than re-running a statement, the alarm an installation can install for itself, and the measurement the readings came from |
| `src/Database/Weighted/EndpointReadings.php` | the three readings a routed read can expect, taken in one sweep: the caps on both sides, whether a standby keeps `hot_standby_feedback` on, and whether the connection declares the `read`/`write` pair a pinned statement needs |
| `src/Pgcat/ScheduleCauses.php` | the three readings beside a silent schedule's verdict — the store its runs' mutexes live in and whether it answered, how many entries run the flip, and the boot stamp with the mode and owner it was found with |
| `tests/Support/FakeCache.php` | the cache binding those readings are driven against: it hands out a repository that answers or refuses to hand out one at all, and it records which store was asked for |
| `tests/Unit/Pgcat/ScheduleCausesTest.php` | the suite for them — an entry on a real schedule, a store that answers and one that does not, and a stamp whose mode the platform either respects or ignores |
| `tests/Unit/Weighted/EndpointReadingsTest.php` | the suite for them, driven by an injected sweep so no row opens a socket — every shape a cap arrives in (`90s`, `2min`, `600ms`, and `0` as a cap of nothing), a standby with the setting off beside an answer that did not carry it, and the pair read whatever the switch says |
<!-- /handoff:pending -->

The pgcat work this cycle's release carries was described by the note before this one and is released
by this one, and the changelog is where its prose lives now rather than here: the sixteen entries the
tag carries are its record. What is still a boundary rather than a record — what is released and what
an application runs — is section 3's, because none of it reaches an application until the pin in
section 4 moves.

---

## 2. Unpushed — the commits on `dev` no remote has

<!-- handoff:unpushed -->
| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `5af2df5` — *feat: two mechanisms armed at once are reported by name, not passed over* |
| `origin/dev` | `2744a13` — *docs: the handoff note is re-taken at the release commit, and its records reading is its transcript* |
| Unpushed | 11 commits — the ones `git log --oneline origin/dev..HEAD` prints |
| Since the last tag | docs 4, feat 6, fix 1 |
<!-- /handoff:unpushed -->

The `HEAD` row is the basis every number in this file is read against, and the table above it is
rendered: the register and the remote tip are commits, and the count and the breakdown are what
`git log --oneline origin/dev..HEAD` says between them. The tip of `origin/dev` was asked of the
remote directly (`git ls-remote`, not the local ref) when this section was taken, which named the
same commit the local ref does.

The register is eleven commits ahead of `origin/dev`: the guarded commit path that moved out of the
consuming application's `git/commit_paths.py` and into this repository's own `git/`, the readings and
the port plan, the three re-takes this cycle's notes were taken in, and this cycle's six
capabilities — the schedule that can be watched, the api page a past tree renders, the published
config that says which key set it is, the file preconditions that cover a whole flip cycle, the
supervisor's three failures, and the two mechanisms that can move the pooler being reported by name.
Unpushed at the register
means the tip its own release was pushed from, which is
why the row reads what it does and not a backlog — `bin/release.php` refuses to tag while `HEAD` is
not the tip of `origin/dev`, and it ends by pushing the commit and the tag together, so every commit
the release made is one a consumer can fetch. This note is the commit *after* the register, and it
travels on the push below.

<!-- handoff:log -->
The ten most recent are the ones a reader is most likely to be standing in the middle of:

```
5af2df5  feat: two mechanisms armed at once are reported by name, not passed over
5ce2422  docs: the handoff note is re-taken at the supervisor's three failures
3c2e61f  feat: a supervisor failure names which of three things went wrong
6dc5198  docs: the handoff note is re-taken at the flip cycle's file preconditions
f782eb0  feat: the paths a flip cycle writes are judged beside the ones a flip step names
eb884fe  docs: the handoff note is re-taken at the published config's generation
bbfd422  feat: a published config says which key set it is, and the doctor reads the difference
e06098b  feat: the API page of a past tree is read out of git, so a release's page is a question
af1fddb  feat: a schedule that stops producing runs is a fault, and four surfaces say so
3f25180  docs: the application is read file by file and by commit, and the port plan follows
```
<!-- /handoff:log -->

The fence is a ceiling rather than the whole range: `git log` is asked for the whole of it and the
block carries what it prints up to that ceiling, so a range longer than the ceiling leaves its oldest
commits to `git log --oneline origin/dev..HEAD`, which is the whole of it in every state. At this
register the range is one commit longer than the ceiling, so the listing stops at `3f25180` and
`2306b43` is the oldest of the eleven that it does not carry.

One consequence of that state is worth naming before the next release: the interlock the release
checks is *satisfied* at this register, which is what let the tag be cut — so what stands between this
branch and a `v0.2.0` is the Unreleased section, which the release emptied, rather than anything
unpushed.

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
| Tags on the branch | `v0.0.1-alpha1`, `v0.1.0-alpha1`, `v0.1.0-alpha3`, `v0.2.0-alpha1`, `v0.2.0-alpha2`, `v0.2.0-alpha3` |
| The latest of them | `v0.2.0-alpha3` |
| A version with no suffix | none — `v0.2.0` does not exist |
| `## Unreleased` at the basis | 10 entries — 6 added, 4 fixed, 0 changed |
| The notes weigh as | minor — six capabilities, which is the rung they take, and four repairs beside them |

The absent version is the exact sentence that decides whether the next one may be a prerelease, and it
may: `v0.2.0` does not exist, so a suffixless version is still the lane ahead. The headings the
weighing reads are the `### Added`, `### Fixed` and `### Changed` a Keep a Changelog section writes,
and the section carries `### Added` six times and `### Fixed` four times at the register, which the
weighing reads as `minor` — six capabilities the rung is for, and the four repairs that landed beside
them. The register is therefore a branch whose release would publish the schedule that can be
watched, the api page a past tree renders, the published config that says which key set it is, the
file preconditions that cover a whole flip cycle, the supervisor's three failures, the two mechanisms
that can own the pooler's mode being reported by name, and the four repairs the release before it did
not carry — as `0.2.0`,
the first version with no suffix, which is the lane the absent version leaves open.

### The release that cut this tag, and the row it could not write

`v0.2.0-alpha3` was cut and pushed on 2026-10-11 from `8742456`, and the commit it made is red — the
same way `v0.2.0-alpha2`'s was. The failure is one row of this note, on both runs the push started
(the branch run `38166456798` and the tag run `38166456799`):

```
HANDOFF.md says v0.2.0-alpha2 is the latest tag, and v0.2.0-alpha3 is — so the lane the note
describes has been released past, and section 3 has to be re-taken before the next tag is cut.
```

Nothing about the released code is wrong: every other check passed, and the tree the tag names is the
approved one. What is wrong is the order — the note is re-taken *after* a tag in this cycle, and the
table above is the one row a tag falsifies, so the release's own commit always lands red on it. The
repair is the re-take that is this commit, which is what a reader of the branch sees; what the *tag*
sees cannot be repaired, because a published tag is a version a consumer may already hold and moving
it is not a fix. The next release should not need the repair: `bin/release.php` knows the version it is
cutting before it writes the release commit, so it can write this table's `Tags on the branch` and
`The latest of them` rows for that version into the release commit itself, and since the tag and the
branch ref land in one push, both runs would see a tag that exists. That is a change to the release
script and to `RELEASING.md` rather than something this note can decide; the interlock is the release's
own and this row is the only one it cannot satisfy as things stand.

### The release the weighing would ask for now

Asked on 2026-10-15 what a release would do, it plans a `minor` and promotes ten bullets — the six
capabilities and the four repairs the section carries:

```bash
php bin/release.php --weigh --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha3
  bump          minor  (weighed: a minor change)
  next version  0.2.0  (tag v0.2.0)
  CHANGELOG     ## Unreleased -> ## 0.2.0 - 2026-10-15, new Unreleased section above
  release notes 10 bullet(s) in the promoted section
  inventory     43 file(s), 221 method(s), 257 key(s)/member(s), described as v0.2.0  (fresh, weighed against v0.2.0-alpha3)
  api page      41 class(es), 221 public method(s), described as v0.2.0
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0
  tag           git tag -a v0.2.0 -m "v0.2.0"
  push          git push origin dev --follow-tags
```

Three lines of it are the state this note describes rather than a step of a release. `ci` is the
interlock the eleven unpushed commits leave: a real run refuses to tag until they are on `origin/dev`,
which is section 2's job and the `pkg-push` runbook's rather than this note's. `CHANGELOG` is the ten
entries section 3's table counts, which the promotion would move into `0.2.0 - 2026-10-15`. `branch`
is `(dirty)` because the re-take is measured in the working tree it was taken in; the note commit is
what makes it clean.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers in
it that *are* checkable are held, though they are two different readings now: the entries the notes
carry **at the register** (the table at the top of this section — none) and the state of the three
records (section 1, whose transcript the guard now runs). Its own `10 bullet(s)` is the weighing's
answer about the working tree, which no guard here holds.

### What is actually in the unreleased section

Ten entries at the register, and they are this cycle's: six `### Added` for the schedule that can
be watched, the api page a past tree renders, the published config that says which key set it is, the
paths a flip cycle writes, the supervisor's three failures and the two mechanisms that can own the
pooler's mode being reported by name, and four `### Fixed` for the repairs the release before this one
left — the guarded commit path, and the records reading that holds the commands section 1 quotes —
beside the two the register itself settled: the published copy's `swrr.pgcat.schedule.enabled`, whose
shipped default quietly prevented the inheritance every surface promised, and the rule its
`interval_minutes` falls back by. The sixteen entries the tag carries — six `### Added`, six
`### Changed`, four `### Fixed` — are the pgcat cycle's work and the guard repairs before them, and the
promotion would carry all ten of these with them.

What those sixteen change for a consumer is written in the released section rather than repeated here.
What is *not* in them is anything the three `.tsv` records, `API.md` or the changelog cannot account
for: the records are the tree at the tag, the page is rendered from the record, and the weighing's own
lines are the surface the cycle moved — `public API` and `config` weighing `minor` on the changes the
prerelease carried, and nothing removed, renamed or added since.

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
2. this note, re-taken at the register the release left — and at the three commits this cycle landed
   on top of it, because a release is the state of the branch and not only of the tag —   and landed as
   a commit of its own on `dev`: this commit, the commit the register is followed by;
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
| Skills | `.agents/skills/{pkg-commit,pkg-push,pkg-deploy}/SKILL.md`, indexed by `.agents/README.md`. Hand-maintained — no `.skills/` sources, no generator, no `verify.py` — so a file there is the only copy. |
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
back (*"…says v0.2.0-alpha1 is the latest tag, and v0.2.0-alpha2 is…"*). A row a release commit
cannot write is a row the tag it cuts is always red on, which is the finding section 3 carries.

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
empty — the rows it carries now are the batch above, which is the first time it has had any since.

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

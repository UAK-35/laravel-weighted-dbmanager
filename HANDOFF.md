# Handoff — the state of this package, taken 2026-10-07

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
end of section 5, and a **release** is the one event that has to re-take this file before the
tag is cut, because section 3's table is a claim about now.

Re-read the three commands it was built from before acting on it:

```bash
# from the package root
git status --short                                  # what is uncommitted, and how it is staged
git log --oneline origin/dev..HEAD                  # what is unpushed
php bin/release.php --weigh --branch=dev --dry-run  # what a release would do right now
```

Every number below was read off this machine on 2026-10-07, with the remote asked directly
(`git ls-remote`) rather than trusted from the local `origin/dev` ref.

---

## 1. Pending — written, green, uncommitted

Three batches are in the working tree and none of them is in the commit section 2 names as
`HEAD`. Twenty-three paths differ from that commit: nineteen tracked paths changed, and four new
files the register does not carry at all. They are unrelated work that happens to share a checkout
— a guard for the half of `AGENTS.md` section 0 about *place on a machine*, the composer scripts
the batch before it left pointing at a file a consumer's copy does not have, and this note's own
re-take with the guard that holds its pending list in both directions — so a commit has to take
them one at a time.

| File | What it is |
|---|---|
| `tests/Support/MachinePaths.php` | the reading of the other half of section 0: a path that names a machine — a drive, an account or a share — rather than a directory every machine of its kind has |
| `tests/Unit/Support/MachinePathsTest.php` | the tests that hold that reading to the shapes it must report and the shapes it must leave alone |
| `tests/Support/ComposerScripts.php` | the reading of the gap the last batch left: a lifecycle event that reaches a path `.gitattributes` holds back, with `@name` resolved and the directories above a path asked about too |
| `tests/Unit/Support/ComposerScriptsTest.php` | the tests for it, exercised at the shape the two deleted events had |

Nineteen tracked paths are changed rather than added, thirteen of them for the first batch. Every one
of those edits is the same fix. `AGENTS.md` section 0 names `tests/Unit/Support/MachinePathsTest.php`
beside the climb reading and gives both a row in the authority table; `.agents/README.md` and the
three `pkg-*` skills stop spelling this checkout's own directory out, and so do `PUSHING.md` and the
`cd` line in `bin/composer-link.cmd`; the backslash example in `src/Pgcat/SupervisorStep.php`,
`tests/Unit/Pgcat/SupervisorStepTest.php` and `docs/pgcat-supervisor-preflight.md` now names a
program's install root instead of one written on somebody's disk; `tests/Support/RepoEscapes.php`
and `tests/Unit/Support/RepoEscapesTest.php` say which reading tolerates which shape; and
`CHANGELOG.md` carries the batch's `### Added` entry — the one entry the working tree's Unreleased
section holds, and what makes section 3's weighing read `minor`.

The second batch is the other three edited files and the two new ones above. `composer.json` loses
its `post-install-cmd` and `post-update-cmd` events, which both ran `bin/publish-config.php` — a file
`bin/` is `export-ignore`d whole, so an install from the dist tarball ran a file that is not in the
copy it was installing. `bin/publish-config.php` and `README.md` are the prose that described it: an
application wires the artisan tag, and the checkout's script is a command a human types.

The third batch is this note's own re-take, and the two files that hold it. `tests/Support/Handoff.php`
gained `uncarried()`, `uncommitted()` and `pendingCounts()` — the paths the tree has that no commit
does, the size of that set, and the size section 1 states — and `tests/Unit/Docs/HandoffTest.php`
holds each against the other, in both directions: every path the tree has and the register does not
is named in the table above, and the counts the sentence states are the tree's.

**Not committed, on purpose: the commits in section 2 are the rule and its guard, and all three of these
batches are what has been written since.** Three unrelated batches are three commits rather than
one. The package has no `core.hooksPath` (so stock `.git/hooks`, and no gate of this repository's own
runs on commit), `commit.gpgsign=true` with `user.signingkey=9CE840D871DE7A62`, and the message
shape `bin/weighing.php` reads back is the one `@pkg-commit` documents. The three subjects in that
shape would be:

```
feat: a path that names a machine is a finding in the files a commit carries
fix: the two install events that ran a script a consumer's copy does not have
test: the pending list in the handoff note is held in both directions
```

### Also pending: the records carry the stamp of the last release

The three `.tsv` records describe the tree `v0.2.0-alpha2` was cut from, and they say so in their
header line. At the commit section 2 names — which is not that release — `php bin/inventory.php
--check --at=<HEAD>` exits 1 and names all three, one stale line each: the stamp. That is the
check reading the stamp rather than the rows. Against this checkout, the same command without
`--at` reports the inventory current:

```
php bin/inventory.php --check --at=6f5cb6c
  --> exit 1: files.tsv, methods.tsv and surface.tsv, 1 line(s) stale each — the stamp

php bin/inventory.php --check
  --> • inventory is current — 39 files, 198 public methods, 224 key(s)/member(s),
      described as v0.2.0-alpha2
```

The split is the intended state rather than a finding: those three files describe the tree **at a
tag**, the next release rewrites them and commits them, and the check's own message offers
exactly those two answers (*"Run php bin/inventory.php to write them and commit them, or leave
them alone and let the next release refresh them"*). Do not "fix" them by hand as a favour — a
record written at some other moment describes a tree no tag points at, which is the thing the
fourth weighing signal reads.

---

## 2. Unpushed — the commits on `dev` no remote has

| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `6f5cb6c` — *test: the handoff note's environment table is held to the machine's own record* |
| `origin/dev` | `0907fce8fb351c62863320cd78a0ec6e1cbf84dd` — *fix: the published API page is re-rendered for the tag the release cut* |
| Unpushed | 7 commits — the ones from the table's `HEAD` back to the commit above it |
| Since the last tag | chore 2, docs 2, feat 1, fix 1, test 1 |

The `HEAD` row is the basis every number in this file is read against. The tip of `origin/dev`
was asked of the remote directly (`git ls-remote`, not the local ref) on the day — and again when
this section was re-taken, which named the same commit; the commits between the two are what is
unpushed.

The seven most recent are the ones a reader is most likely to be standing in the middle of:

```
6f5cb6c  test: the handoff note's environment table is held to the machine's own record
343d645  chore: the package stops shipping the scripts that write its records
bcc64ea  docs: the handoff note is re-taken at the rule and its guard
7cbd43d  docs: the rule that the work is this folder, and nothing outside it
b4c7dcf  feat: a walk refuses a record's path that climbs out of the package
b8f3b5e  fix: the README and the config publisher stop pointing above the package
4f0516f  chore: a copy of the machine record beside it cannot be committed
```

They are also the whole range — `git log --oneline origin/dev..HEAD` has nothing the fence does
not.

Everything on `dev` is ahead of the remote, and that has one consequence worth naming: it is
the **interlock on the next release**. `bin/release.php` refuses to tag while HEAD is not the
tip of `origin/dev` — the plan in section 3 says so in its `ci` line — so either the branch goes
up first or the release is declared with `--skip-ci`. It is not an error to be worked around: it
is the check that the thing being tagged is the thing a consumer can fetch.

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
| Tags on the branch | `v0.0.1-alpha1`, `v0.1.0-alpha1`, `v0.1.0-alpha3`, `v0.2.0-alpha1`, `v0.2.0-alpha2` |
| The latest of them | `v0.2.0-alpha2` |
| A version with no suffix | none — `v0.2.0` does not exist |
| `## Unreleased` at the basis | 0 entries — 0 added, 0 fixed, 0 changed |
| The notes weigh as | patch — an empty section weighs as nothing |

The absent version is the exact sentence that decides whether the next one may be a prerelease,
and it may. The headings the weighing reads are the `### Added`, `### Fixed` and `### Changed` a
Keep a Changelog section writes; the section carries none of them, so every count is zero and the
severity is the one the weighing gives an empty section. The marker is the one a note claims
rather than the word alone, which is why the last release could be declared breaking from a
section that carries four headings and no `Removed` one.

### The release the weighing would ask for, and the line that refuses it

`v0.2.0-alpha2` was cut and pushed on 2026-10-06. Since then one entry has been written under
`## Unreleased` — in the working tree rather than at the register, so the section the table above
holds is still empty and the one the weighing reads is not. Asked on 2026-10-07 what a release
would do, `exit 0`:

```bash
php bin/release.php --weigh --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha2
  bump          minor  (weighed: a minor change)
  next version  0.2.0  (tag v0.2.0)
  CHANGELOG     ## Unreleased -> ## 0.2.0 - 2026-10-07, new Unreleased section above
  release notes 1 bullet(s) in the promoted section
  inventory     39 file(s), 198 method(s), 224 key(s)/member(s)  (fresh, weighed against v0.2.0-alpha2)
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0
  tag           git tag -a v0.2.0 -m "v0.2.0"
  push          git push origin dev --follow-tags
```

One line is unsatisfied, and it is the check working rather than an obstacle: `ci` (section 2 is
unpushed, so the commit being released is not one a consumer can fetch). The CHANGELOG line was
the second of the two until the working tree's section gained its entry: at the register the
section is empty, and an empty section is nothing to publish, which is why a release declared
against that commit refuses there. The `minor` comes from both signals: the notes, whose
`### Added` is the loudest heading they carry (the weighing reports `### Added — 1 entry, the
loudest heading the notes use`), and the eight commits since the last tag — `test 1, chore 2,
docs 2, feat 1, fix 2` — in which the `feat:` of section 2 is a new capability. The public API, the config and
the inventory all weigh `patch`: *nothing removed, renamed or added since the last tag*.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers
in it that *are* checkable are held, though they are two different readings now: the entries the
notes carry **at the register** (the table at the top of this section — none) and the state of the
three records (section 1). Its own `1 bullet(s)` is the weighing's answer about the working tree,
which no guard here holds.

### What is actually in the unreleased section

One entry, and it is not this batch's: the machine-path guard — a reading that refuses a path
naming the drive, the account or the share it was written on, held beside the walk that refuses
one climbing above the root, with every path it reported rewritten to name nobody. At the register
the section is empty; in the working tree it carries that `### Added` entry, uncommitted like the
files it describes.

The seven commits in section 2 are the rule at the root, the walk that reads it, the two records
whose path pointed outside the package, the ignore rule for the machine record, this note's own
re-take, the decision that a consumer's copy carries none of the tools under `bin/`, and the guard
that holds section 5's environment rows to the machine record. What they change for a consumer is
`README.md` alone — prose about which commands are a checkout's — because `AGENTS.md`, `tests/`,
`.gitattributes`, `.gitignore` and `bin/` are all `export-ignore`d. The weighing agrees with that
reading rather than with a summary of it: `public API` and `config` are *nothing removed, renamed
or added since the last tag*, and the inventory is current.

The thing to know about is the **boundary flip**, which is released and not installed:
`db:pgcat-window-flip` (`src/Console/Commands/DbWindowFlipCommand.php`) and the reader windows as
scheduled tasks the package registers are all in `v0.2.0-alpha2` — cut, tagged and pushed — so
this cycle has nothing to add to them, and they reach no application until the pin in section 4
moves. The pooler left on the writer-only config inside an open reader window that now answers
`degraded` at HTTP 503 is part of the same release, and it is the one change in it that a monitor
or a deploy gate can feel.

---

## 4. Unpacked — the application side has none of it

A tag in this package changes nothing an application runs until the application's pin moves. The
consumer here is the LPR app, and the last reading of its pin — taken by hand on 2026-10-06,
before `v0.2.0-alpha2` existed — was:

```
site/composer.json  →  "uak35/laravel-weighted-dbmanager": "v0.2.0-alpha1"
```

That is a fact about **another repository**, which this one's test cannot ask about and this
thread did not re-read: it is written here as the last reading rather than as today's pin, and
the pin may have moved since. So everything section 3 calls released — the boundary flip
included — is in a tag and in no installation until that pin moves, which is the difference
between "the flip is implemented", "the flip is released" and "the flip is deployed" that a fresh
thread can otherwise spend a long time establishing.

What a release of this cycle would still need, none of which is done:

1. commit the three batches in the working tree (section 1), as three commits rather than one;
2. push the seven commits on `dev` (section 2), or declare the run with `--skip-ci`;
3. land the `## Unreleased` entry the working tree carries — it is part of the first batch, and at
   the register the section is empty, so a release declared against that commit would have nothing
   to publish;
4. raise the consumer's pin, in the application repository, to the new tag;
5. the application-side switches and schedule entries that a flip needs, which live in that
   repository and are not part of this package.

Steps 4 and 5 are **outside this repository**. Nothing here can do them, and a change in this
repository is not an installation change until each of them happens.

---

## 5. Working here — the facts that do not change

| Field | Working here |
|---|---|
| PHP | The binary `.agents/machine.local.json` records as `php.exe` (8.4.26). It is **not on `PATH`** in the shell, so a command that just says `php` fails; set a variable and use it. |
| Composer | 2.10.3. `composer.phar` sits in the package root and is **gitignored**, so a test must never shell out to composer. |
| Git identities | `origin` is HTTPS (`https://github.com/UAK-35/laravel-weighted-dbmanager.git`), `commit.gpgsign=true`, `user.signingkey=9CE840D871DE7A62`, and `core.hooksPath` is unset — this repository has **no** pre-commit gate of its own. |
| Skills | `.agents/skills/{pkg-commit,pkg-push,pkg-deploy}/SKILL.md`, indexed by `.agents/README.md`. Hand-maintained — no `.skills/` sources, no generator, no `verify.py` — so a file there is the only copy. |
| Checks | `php bin/checks.php --list` names twelve; use `--only=` subsets. The full run and the whole `tests/` tree both exceed what a single agent command is allowed to take, so run one file or one directory at a time. |
| Style gate | `php bin/tool.php pint --test` (PSR-12). |
| Static analysis | `phpstan.neon.dist`: level `max`, over `src/` only — a file under `tests/` is style-checked but not analysed. |
| In a consumer's tarball | `docs/` |
| Left out of it | `tests/`, `.agents/`, `bin/api.php`, `bin/checks.php`, `bin/counts.php`, `bin/publish-config.php`, `bin/release.php`, `bin/surface.php`, `bin/weighing.php`, `bin/inventory.php`, `bin/blame.php`, `bin/tools.php`, `bin/tool.php`, `bin/composer-link.cmd`, `bin/composer-ca.ps1`, `CHANGELOG.md`, `RELEASING.md`, `PUSHING.md`, `STATIC-ANALYSIS.md`, `HANDOFF.md`, `AGENTS.md`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `.gitattributes`, `.gitignore`, `.editorconfig`, `.idea`, `files.tsv`, `methods.tsv`, `surface.tsv` |
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
the maintenance sentence: no `.skills/` sources, no generator, no Python among the files a commit
would carry. Where a row describes a machine that is not this one the comparison skips rather than
fails, which is what a fresh clone and CI's runner see — the same shape as the record itself:
absent is a skip, present and different is a failure.

Two of the note's own claims were checked by control rather than taken on trust. Editing section
3's count row to `3 entries — 3 added, 0 fixed, 0 changed` fails `HandoffTest` with *"HANDOFF.md
says the Unreleased section carries added 3, changed 0, fixed 0; the weighing counts added 0,
changed 0, fixed 0"*, and editing this table's Composer row to 2.10.4 fails the environment test,
which names the row's line and both versions — *"…puts Composer at 2.10.4, and the phar it names
reports 2.10.3"*. Both edits were restored byte for byte afterwards, which is why the file the
suite reads is the file described here.

### What the test above cannot ask

Three claims in this file are a reader's to judge, and are named here so that a green suite is
not mistaken for a checked file:

* **the remote**. The tip of `origin/dev` was asked of GitHub on the day; the test holds the
  commit that answer named, and the commits between it and the basis, but not the ref it sits
  on — a fetch that moved `origin/dev` would not fail it.
* **the plan and the evidence blocks**. The release plan in section 3 and the runs quoted in
  section 5 were runs against this working tree, as is the record check section 1 states as its
  transcript. Their checkable arithmetic is held (the entries the notes carry, the records being
  out of step), and the plans themselves are not.
* **the pinned version in section 4**, which is a fact about the application repository — and,
  this cycle, a reading taken a day before the release it names.

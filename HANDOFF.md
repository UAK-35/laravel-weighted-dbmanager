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

Three batches that were pending when this note was last taken — and the reading that lets it be
taken at all — are commits now, and this re-take is the fourth. They are the pgcat *direction*, the
end of the last reader window a stale pooler is at, with the records and the changelog entry that
describe it; the release script, which renders the published API page from the record it writes; and
`AGENTS.md`, which now sends a session to `.agents/machine.local.json` for the interpreter every
command here starts with. Two paths differ from the commit section 2 names as `HEAD`: two tracked
paths changed, and zero new files.

What this section's register (`f92abd2`) does not carry — which is the question its table is read as, and not the same one as what is uncommitted — is the two release-suite files the release batch wrote, and both of them are in `f10c318`:

| File | What it is |
|---|---|
| `tests/Unit/Release/DryRunTest.php` | committed in `f10c318`: a `--weigh --dry-run` held to the tree, the refs and `HEAD` it left alone, read against the same command run for real |
| `tests/Unit/Release/PublishedPageTest.php` | committed in `f10c318`: a fixture release held to the page it publishes — the bytes the record renders, the stamp both of them name, and the commit the tag points at |

`src/Support/BootAudit.php` and `src/Providers/WeightedDatabaseServiceProvider.php` are a concurrent
thread's work in flight, nine lines between them, and this note did not read the change. What it can
say about them is what it measured rather than what they do: they are in the tree, and the gate is
red on them — `phpstan` on `src/Support/BootAudit.php` (a property assignment whose closure has the
wrong signature, and a closure called with more parameters than it takes) and `pint` on
`src/Providers/WeightedDatabaseServiceProvider.php` (`method_argument_space`) — so a thread that runs
`bin/checks.php` and sees red should look here first. They travel with the thread whose work they are,
and a release is blocked by them twice over: the plan refuses on a dirty tree while they sit here, and
the CI rail would read the red `phpstan` and `pint` if they were committed as they stand. Neither is
this thread's to finish.

The three commits this section used to describe:

```
feat: the reader-window verdict names which end of the window disagreed   3f05cff
fix: the release script renders the page its record publishes             f10c318
docs: a session starts by reading the machine record for its interpreter  4f4967d
```

**The four batches were four commits rather than one**, because they are unrelated work that happens
to share a checkout — and the note rides with the last of them rather than the first, because its
pending counts are a claim about this working tree and the only state they can be true in is the one
after everything else has landed. The package has no `core.hooksPath` (so stock `.git/hooks`, and no
gate of this repository's own runs on commit), `commit.gpgsign=true` with
`user.signingkey=9CE840D871DE7A62`, and the message shape `bin/weighing.php` reads back is the one
`@pkg-commit` documents.

### Also pending: the records carry the stamp of the last release

The three `.tsv` records describe the tree `v0.2.0-alpha2` was cut from, and they say so in their
header line. At the commit section 2 names — which is not that release — `php bin/inventory.php
--check --at=<HEAD>` exits 1 and names all three, one stale line each: the stamp. That is the
check reading the stamp rather than the rows. Against this checkout, the same command without
`--at` reports the inventory current:

```
php bin/inventory.php --check --at=f92abd2
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
| HEAD | `f92abd2` — *test: the pending list in the handoff note is held in both directions* |
| `origin/dev` | `0907fce8fb351c62863320cd78a0ec6e1cbf84dd` — *fix: the published API page is re-rendered for the tag the release cut* |
| Unpushed | 10 commits — the ones from the table's `HEAD` back to the commit above it |
| Since the last tag | chore 2, docs 2, feat 2, fix 2, test 2 |

The `HEAD` row is the basis every number in this file is read against. The tip of `origin/dev`
was asked of the remote directly (`git ls-remote`, not the local ref) on the day — and again when
this section was re-taken, which named the same commit; the commits between the two are what is
unpushed.

The ten most recent are the ones a reader is most likely to be standing in the middle of:

```
f92abd2  test: the pending list in the handoff note is held in both directions
e5c4a7c  fix: the two install events that ran a script a consumer's copy does not have
928808e  feat: a path that names a machine is a finding in the files a commit carries
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
| `## Unreleased` at the basis | 1 entry — 1 added, 0 fixed, 0 changed |
| The notes weigh as | minor — the one `### Added` entry is the loudest heading the notes use |

The absent version is the exact sentence that decides whether the next one may be a prerelease,
and it may. The headings the weighing reads are the `### Added`, `### Fixed` and `### Changed` a
Keep a Changelog section writes; the section carries one of them — the machine-path guard's own
entry — so `### Added` is the loudest heading it has and the severity is the one the weighing gives
that. The marker is the one a note claims rather than the word alone, which is why the last release
could be declared breaking from a section that carries four headings and no `Removed` one.

### The release the weighing would ask for, and the line that refuses it

`v0.2.0-alpha2` was cut and pushed on 2026-10-06. Since then two entries have been written under
`## Unreleased` — one at the register and one in the working tree — so the section the table above
holds carries the first and the one the weighing reads carries both. Asked on 2026-10-07 what a
release would do, `exit 0`:

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
  release notes 2 bullet(s) in the promoted section
  inventory     39 file(s), 198 method(s), 224 key(s)/member(s)  (fresh, weighed against v0.2.0-alpha2)
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0
  tag           git tag -a v0.2.0 -m "v0.2.0"
  push          git push origin dev --follow-tags
```

One line is unsatisfied, and it is the check working rather than an obstacle: `ci` (section 2 is
unpushed, so the commit being released is not one a consumer can fetch). The CHANGELOG line is
satisfied twice over now — the register carries the machine-path entry, and the working tree carries
that and this batch's — where, at the last taking, the section was empty at the register and a
release declared against that commit had nothing to publish. The `minor` comes from both signals:
the notes, whose `### Added` is the loudest heading they carry (the weighing reports `### Added —
2 entries, the loudest heading the notes use`), and the eleven commits since the last tag — `test 2, fix 3,
feat 2, chore 2, docs 2` — in which the two `feat:` commits are new capabilities. The public API,
the config and the inventory all weigh `patch`: *nothing removed, renamed or added since the last
tag*.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers
in it that *are* checkable are held, though they are two different readings now: the entries the
notes carry **at the register** (the table at the top of this section — one) and the state of the
three records (section 1). Its own `2 bullet(s)` is the weighing's answer about the working tree,
which no guard here holds.

### What is actually in the unreleased section

Two entries, and the register carries one of them: the machine-path guard — a reading that refuses
a path naming the drive, the account or the share it was written on, held beside the walk that
refuses one climbing above the root, with every path it reported rewritten to name nobody. The
working tree adds the pgcat entry the paragraph below is about, uncommitted like the files it
describes.

The ten commits in section 2 are the rule at the root, the walk that reads it, the two records
whose path pointed outside the package, the ignore rule for the machine record, this note's own
re-take, the decision that a consumer's copy carries none of the tools under `bin/`, the guard that
holds section 5's environment rows to the machine record, the reading that refuses a path naming
the machine it was written on, the two install events that ran a script a consumer's copy does not
have, and the guard that holds this pending list in both directions. What they change for a
consumer is `README.md` alone — prose about which commands are a checkout's — because `AGENTS.md`,
`tests/`, `.gitattributes`, `.gitignore` and `bin/` are all `export-ignore`d. The weighing agrees with that
reading rather than with a summary of it: `public API` and `config` are *nothing removed, renamed
or added since the last tag*, and the inventory is current.

The thing to know about is the **boundary flip**, which is released and not installed:
`db:pgcat-window-flip` (`src/Console/Commands/DbWindowFlipCommand.php`) and the reader windows as
scheduled tasks the package registers are all in `v0.2.0-alpha2` — cut, tagged and pushed — so
this cycle has nothing to add to them, and they reach no application until the pin in section 4
moves. The pooler left on the writer-only config inside an open reader window answers `degraded` at
HTTP 503, and so does the same fault from the other end — the readers config left in place outside
every window — because what the verdict reads is whether the file and the windows disagree, not
which of the two is ahead; either way round is part of that release, and it is the one change in it
that a monitor or a deploy gate can feel. Which end a failure is at, rather than whether there is
one, is this cycle's rather than `v0.2.0-alpha2`'s: `direction` (`inside_window`,
`outside_window`) and the `db:doctor` row that prints it are in the working tree, uncommitted like
the entry that describes them.

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

1. commit the four batches in the working tree (section 1), as four commits rather than one;
2. push the ten commits on `dev` (section 2), or declare the run with `--skip-ci`;
3. land the `## Unreleased` entry the working tree carries and the register does not — the pgcat
   one: the register already carries the machine-path entry, so a release declared against that
   commit would publish that entry alone;
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

Four of the note's own claims were checked by control rather than taken on trust. Editing section
3's count row to `3 entries — 3 added, 0 fixed, 0 changed` fails `HandoffTest` with *"HANDOFF.md
says the Unreleased section carries added 3, changed 0, fixed 0; the weighing counts added 1,
changed 0, fixed 0"*, and editing it to `1 entry — 2 added, 0 fixed, 0 changed` fails it with
*"…carries added 2, changed 0, fixed 0; the weighing counts added 1, changed 0, fixed 0"*; editing
section 1's counts sentence to state *one* file where the tree has two fails it at line 34 with
*"…says 21 path(s) differ from the register, 19 of them tracked paths that changed and 1 file(s) it
does not carry; this checkout has 21, 19 and 2"*; and editing this table's Composer row to 2.10.4
fails the environment test, which names the row's line and both versions — *"HANDOFF.md line 323
puts Composer at 2.10.4, and the phar it names reports 2.10.3"*. The second and third are the two
readings that had to learn the singular, and each still fails on a count the tree does not have —
which is what makes the reading the change widened a reading rather than a shrug.
Every edit was restored byte for byte afterwards, which is why the file the suite reads is the file
described here.

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

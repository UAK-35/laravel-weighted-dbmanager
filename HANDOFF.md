# Handoff — the state of this package, taken 2026-10-06

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
cd E:\_WORKS\lpr\work\packages\laravel-weighted-dbmanager

git status --short                                  # what is uncommitted, and how it is staged
git log --oneline origin/dev..HEAD                  # what is unpushed
php bin/release.php --weigh --branch=dev --dry-run  # what a release would do right now
```

Every number below was read off this machine on 2026-10-06, with the remote asked directly
(`git ls-remote`) rather than trusted from the local `origin/dev` ref.

---

## 1. Pending — written, green, uncommitted

Four new files, written and green but committed nowhere: none of them is in the tree the commit
section 2 names holds.

| File | What it is |
|---|---|
| `tests/Support/Handoff.php` | the reader: this note's tables, its listing, the paths it names and the tags it lists, parsed as data |
| `tests/Unit/Docs/HandoffTest.php` | the tests that hold this file to the tree |
| `tests/Support/SkillDescriptions.php` | the reader: `.agents/skills/*/SKILL.md` frontmatter and `.agents/README.md`'s skills table, parsed as data |
| `tests/Unit/Docs/SkillDescriptionTest.php` | the tests that hold every skill description and every index row to the commands this package actually has |

Two tracked files are edited rather than added: `.agents/README.md` gained the paragraph that
points at this note, and `.gitattributes` the `/HANDOFF.md export-ignore` line that keeps a
snapshot out of a consumer's tarball. Neither edit is committed either.

**Not committed, on purpose: nothing has been committed in this thread.** The package has no
`core.hooksPath` (so stock `.git/hooks`, and no gate of this repository's own runs on commit),
`commit.gpgsign=true` with `user.signingkey=9CE840D871DE7A62`, and the message shape
`bin/weighing.php` reads back is the one `@pkg-commit` documents. Subjects in that shape would
be:

```
test: a skill description is held to the commands this package has
test: the handoff note is held to the tree it describes
```

### This note is the first two of them

They make the paragraph at the top true. The note was the one document here that nothing read,
and it had already rotted in three places, none of them visible in a diff: the command in its
opening block did not run, the paths it said the dist tarball holds back were wrong, and three
of the eleven commit subjects it listed were truncated. All three were found by writing the
reader rather than by re-reading the file — which is the argument for the reader.

### The skill descriptions are the second two

These exist because a skill description is the one piece of prose a tool reads *before*
choosing a skill, and nothing compared it with the package: a description could advertise a
`bin/` script, a `db:` command, a `composer` script or a `@skill` that had since been renamed,
and the reader who followed it would find nothing.

What it reads, and the four name shapes it resolves them against:

* `@handle` → a directory under `.agents/skills/`;
* `bin/…` → a file in `bin/`;
* `db:…` → a `$signature` declared under `src/Console/Commands/`;
* `composer …` → a key in `composer.json`'s `scripts`, or one of Composer's own subcommands
  (written down in the reader's `COMPOSER_OWN`, because composer is not on `PATH` here and
  `composer.phar` is gitignored — so a test cannot ask composer anything).

It is strict by design: a `SKILL.md` with no frontmatter, a frontmatter `name` that disagrees
with its folder, a description that is empty, an index with no skills table, a row whose cells
disagree with the header, a console command whose `$signature` cannot be read, and *no skills
at all* each raise rather than pass. Two real-rot controls were run against it, both by
renaming a file and restoring it: a renamed `bin/` script fails
`test_the_index_names_only_things_this_package_has`, and a renamed skill folder fails with one
`RuntimeException` per skill naming both the declared and the actual folder.

Evidence, on the files as they stand:

```
php bin/tool.php phpunit tests/Unit/Docs/SkillDescriptionTest.php
  --> OK (12 tests, 19 assertions), exit 0

php bin/checks.php --only=counts,sentences,phpstan,pint
  --> 4/4 PASS, exit 0
```

### Also pending: the three inventory records are stale, by design

`php bin/inventory.php --check --at=<the commit section 2 names>` exits 1 and says so. The same
command without `--at` — against this checkout — reported, on the day:

| Record | Out of step with this checkout |
|---|---|
| `files.tsv` | 5 line(s) new, 0 gone |
| `methods.tsv` | 33 line(s) new, 2 gone |
| `surface.tsv` | 59 line(s) new, 0 gone |

The three magnitudes are the run's transcript rather than a claim anything holds; what the test
holds is the state they evidence, that the records are out of step with the tree at the commit
section 2 names. Both readings are of the same standing, and it is the intended state rather
than a finding: those three files describe the tree **at a tag**, the next release rewrites
them and commits them, and the check's own message offers exactly those two answers (*"Run php
bin/inventory.php to write them and commit them, or leave them alone and let the next release
refresh them"*). Do not "fix" them by hand as a favour — that produces a record describing a
tree no tag points at, which is the thing the fourth weighing signal reads.

---

## 2. Unpushed — the commits on `dev` no remote has

| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `10ad014` — *feat: the package carries its own commit, push and release skills* |
| `origin/dev` | `262bfa3bf56938b00bd16e25b38882c969597131` — *Release v0.2.0-alpha1* |
| Unpushed | 34 commits — the ones from the table's `HEAD` back to the commit above it |
| Since the last tag | feat 14, fix 15, docs 3, test 2 |

The `HEAD` row is the basis every number in this file is read against. The tip of `origin/dev`
was asked of the remote directly (`git ls-remote`, not the local ref) on the day; the commits
between the two are what is unpushed.

The eleven most recent are the ones a reader is most likely to be standing in the middle of:

```
10ad014  feat: the package carries its own commit, push and release skills
0685c8e  feat: the preflight says whether anything will move the pooler at a boundary
443dd83  fix: a backup file an editor leaves beside a script in bin/ is ignorable
4ba50bc  fix: PUSHING.md tells a reader to cd to where the package is now
fb8dcb4  fix: the package's app skeleton names the provider that exists, not the one it was built beside
886497a  docs: the notes carry the breaking change where an installation meets it, and stop counting the entries
1470df1  feat: the tools this package installs are written down once, and three readers are held to the one copy
00992c6  fix: the BREAKING marker weighs where a note claims it, not where a note names it
bf356de  feat: a pooler out of step with an open reader window is a failure, not a silent one
eca9f23  feat: the reader windows are scheduled tasks, and a mode change waits until the thing it points at answers
388e784  feat: the flip schedules itself, so the package owns the entry and the cadence is a setting
```

`git log --oneline origin/dev..HEAD` has the rest.

Everything on `dev` is ahead of the remote, and that has one consequence worth naming: it is
the **interlock on the next release**. `bin/release.php` refuses to tag while HEAD is not the
tip of `origin/dev`, so either the branch goes up first or the release is declared with
`--skip-ci`. It is not an error to be worked around — it is the check that the thing being
tagged is the thing a consumer can fetch.

The push itself goes through PowerShell 7 and nothing else; `PUSHING.md` is the runbook, and
`@pkg-push` wraps it:

```powershell
cd E:\_WORKS\lpr\work\packages\laravel-weighted-dbmanager

git push origin dev --follow-tags
```

---

## 3. Unreleased — built, tested, and in nothing a consumer can install

| Field | At the snapshot |
|---|---|
| Tags on the branch | `v0.0.1-alpha1`, `v0.1.0-alpha1`, `v0.1.0-alpha3`, `v0.2.0-alpha1` |
| The latest of them | `v0.2.0-alpha1` |
| A version with no suffix | none — `v0.2.0` does not exist |
| `## Unreleased` at the basis | 35 entries — 15 Added, 19 Fixed, 1 Changed |
| The notes weigh as | breaking — the package's own `changelogSignal()` |

The absent version is the exact sentence that decides whether the next one may be a
prerelease, and it may. The four headings the weighing reads are the `### Added`, `### Fixed`
and `### Changed` a Keep a Changelog section writes, and the marker is the one a note claims
rather than the word alone.

### The pre-release is declared, measured, and legal

Left to itself the weighing resolves to a **release**, not a prerelease — and it says so
twice, because it is not only a version but a branch. `--weigh --branch=dev --dry-run` names
`0.2.0`, and bare `--weigh` refuses outright:

```
✗ Releases are cut from main, but HEAD is on dev. Switch branch (or pass --branch=dev).
```

The suffix is what decides both, so it is the *absence* of one that makes this a `main`
release. A prerelease therefore has to be **declared**, which is what `--version=` is for.
Both runs measured on 2026-10-06, `exit 0`:

```bash
php bin/release.php --weigh --branch=dev --dry-run         # the weighing's own answer: 0.2.0
php bin/release.php --version=0.2.0-alpha2 --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha1
  bump          minor  (declared as --version=0.2.0-alpha2; the changes call for minor)
  next version  0.2.0-alpha2  (tag v0.2.0-alpha2)
  prerelease    alpha — Composer stability "alpha", so a consumer has to opt in
  CHANGELOG     ## Unreleased -> ## 0.2.0-alpha2 - 2026-10-06, new Unreleased section above
  release notes 35 bullet(s) in the promoted section
  inventory     39 file(s), 198 method(s), 224 key(s)/member(s)  (fresh, weighed against v0.2.0-alpha1)
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0-alpha2
  tag           git tag -a v0.2.0-alpha2 -m "v0.2.0-alpha2"
  push          git push origin dev --follow-tags
```

**The only unsatisfied line is `ci`.** The escape is either the push in section 2, or the flag
that says the rail was considered and skipped on purpose:

```bash
php bin/release.php --version=0.2.0-alpha2 --branch=dev --skip-ci
```

`--skip-ci` is not silent about it: the plan then reads `not checked (--skip-ci)`. Two weighing
notes are also printed and are worth expecting rather than reading as failures — a
`config:name`/`config:timezone` surface-name collision (two files declare each name, so a
name-keyed verdict sees only the first), and the statement that the rung is measured from the
last *release* rather than from the dev tag the branch is on.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The two
numbers in it that *are* checkable are held: the entries the Unreleased notes carry (the table
at the top of this section), and the three records being out of step (section 1).

### What is actually in the unreleased section

The headline is the **boundary flip**, which the package now owns end to end:

* `db:pgcat-window-flip --mode=readers|writer --at=HH:MM[:SS] [--json]`
  (`src/Console/Commands/DbWindowFlipCommand.php`);
* the reader windows as *scheduled tasks* the package registers, rather than an entry the
  application has to write (`src/Pgcat/WindowFlipSchedule.php`, `src/Pgcat/FlipSchedule.php`,
  `src/Pgcat/FlipWindow.php`, registered from `src/Providers/WeightedDatabaseServiceProvider.php`
  inside `afterResolving(Schedule::class)`);
* a pooler left on the writer-only config inside an open reader window is now a **failure**,
  not a silent one: `/health/db` answers `degraded` at HTTP 503 in a state it used to answer
  `ok` to;
* the boot audit's record line is triaged by `kept` / `keys_this_boot_read` rather than
  `discarded` / `keys_on_disk`.

`CHANGELOG.md`'s `## Unreleased` is marked **BREAKING** and carries 15 `### Added`, 19
`### Fixed` and 1 `### Changed` entries — the counts in the table at the top of this section,
read out of the section the commit section 2 names holds by the package's own
`changelogSignal()`, so they are the weighing's arithmetic rather than counted by hand.

---

## 4. Unpacked — the application side has none of it

A tag in this package changes nothing an application runs until the application's pin moves.
The consumer here is the LPR app, and today it reads:

```
site/composer.json  →  "uak35/laravel-weighted-dbmanager": "v0.2.0-alpha1"
```

That is a fact about **another repository**, which this one's test cannot ask about; it was read
by hand. So everything in section 3 — the whole boundary-flip machinery included — is **built,
tested and shipped nowhere**: it is on `dev` in this repository, in no tag, and therefore in no
installation. That is the gap this note exists to make visible, and it is the difference
between "the flip is implemented" and "the flip is deployed" that a fresh thread can otherwise
spend a long time establishing.

What a real release would still need, none of which is done:

1. commit the pending guard (section 1);
2. push `dev` (section 2), or declare the run with `--skip-ci`;
3. run `bin/release.php` for real — which also rewrites and commits the three `.tsv` records;
4. raise the consumer's pin, in the application repository, to the new tag;
5. the application-side switches and schedule entries that a flip needs, which live in that
   repository and are not part of this package.

Steps 4 and 5 are **outside this repository**. Nothing here can do them, and a change in this
repository is not an installation change until each of them happens.

---

## 5. Working here — the facts that do not change

| Field | Working here |
|---|---|
| PHP | `E:\_F_DRV\_PHP\PHP8426x64\php.exe` (8.4.26). It is **not on `PATH`** in the shell, so a command that just says `php` fails; set a variable and use it. |
| Composer | 2.10.3. `composer.phar` sits in the package root and is **gitignored**, so a test must never shell out to composer. |
| Git identities | `origin` is HTTPS (`https://github.com/UAK-35/laravel-weighted-dbmanager.git`), `commit.gpgsign=true`, `user.signingkey=9CE840D871DE7A62`, and `core.hooksPath` is unset — this repository has **no** pre-commit gate of its own. |
| Skills | `.agents/skills/{pkg-commit,pkg-push,pkg-deploy}/SKILL.md`, indexed by `.agents/README.md`. Hand-maintained — no `.skills/` sources, no generator, no `verify.py` — so a file there is the only copy. |
| Checks | `php bin/checks.php --list` names twelve; use `--only=` subsets. The full run and the whole `tests/` tree both exceed what a single agent command is allowed to take, so run one file or one directory at a time. |
| Style gate | `php bin/tool.php pint --test` (PSR-12). |
| Static analysis | `phpstan.neon.dist`: level `max`, over `src/` only — a file under `tests/` is style-checked but not analysed. |
| In a consumer's tarball | `docs/`, `bin/api.php`, `bin/counts.php`, `bin/publish-config.php` |
| Left out of it | `tests/`, `.agents/`, `bin/checks.php`, `bin/release.php`, `bin/surface.php`, `bin/weighing.php`, `bin/inventory.php`, `bin/blame.php`, `bin/tools.php`, `bin/tool.php`, `bin/composer-link.cmd`, `bin/composer-ca.ps1`, `CHANGELOG.md`, `RELEASING.md`, `PUSHING.md`, `STATIC-ANALYSIS.md`, `HANDOFF.md`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `.gitattributes`, `.gitignore`, `.editorconfig`, `.idea`, `files.tsv`, `methods.tsv`, `surface.tsv` |
| Text search | `rg` (ripgrep 15.2.0) is on `PATH`; `grep` works too. |
| Machine record | `.agents/machine.local.json` — the facts above as the machine running the suite holds them, compared by `tests/Unit/Docs/HandoffTest.php`; gitignored, so a clone without the file skips that comparison. |

The two tarball rows are read out of `.gitattributes` as it stands, and the split is a decision
now rather than an observation: the development skills under `.agents/` and the two Composer
wrappers in `bin/` are export-ignored, so a consumer receives neither. What still ships from
`bin/` is the three scripts that were never listed — `bin/api.php`, `bin/counts.php`,
`bin/publish-config.php`, all three of them developer tools — recorded here as observed;
changing it is a one-line edit to `.gitattributes`.

`tests/Unit/Docs/DocCitationsTest.php` treats a `test_…` name in a record as a citation, and
refuses one no class declares. Its corpus is `README.md`, `RELEASING.md` and `docs/*.md` — this
file is not in it, which is why the test named at the top of this one reads it instead.

### What the test above cannot ask

Three claims in this file are a reader's to judge, and are named here so that a green suite is
not mistaken for a checked file:

* **the remote**. The tip of `origin/dev` was asked of GitHub on the day; the test holds the
  commit that answer named, and the commits between it and the basis, but not the ref it sits
  on — a fetch that moved `origin/dev` would not fail it.
* **the plan and the evidence blocks**. The release plan in section 3, the two commands' output
  in section 1 and the test result quoted there were runs against this working tree. Their
  checkable arithmetic is held (the entries the notes carry, the records being out of step, the
  counts section 1 states as its transcript are not), and the plans themselves are not.
* **the pinned version in section 4**, which is a fact about the application repository.

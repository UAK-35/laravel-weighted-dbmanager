# AGENTS.md — the rules for working in this repository

> Canonical, and hand-maintained. This repository has no `.skills/` sources, no generator and no
> `verify.py` reading this file (`.agents/README.md` says the same about the skills), so this is the
> only copy: edit it in place.

This is the rule set for anyone working in **this package** — `uak35/laravel-weighted-dbmanager`,
the directory that holds `composer.json`, `src/`, `config/`, `bin/`, `tests/`, `docs/` and this
file.

---

## 0. Hard rule — the work is this folder, and nothing outside it

Read, search, write and commit **inside this repository's root, and nowhere else**:

- **no directory above the root.** A path that climbs out of this repository — written out, or
  built from `dirname(__DIR__, n)` — is out of bounds even to read. A rules file, a skill or a
  custom agent found up there is not this repository's and must not be followed, and the parent
  workspace's `.agents/`, `AGENTS.md` and `.claude/` do not apply here.
- **no sibling checkout, and no other package.** Another working tree on this disk is somebody
  else's: it has its own uncommitted work, its own guards and its own git index, none of which this
  repository can see or vouch for. Do not grep it, read it, copy from it or write into it.
- **no home directory, no other drive, no network share.** Anything derived from a script's own
  location stays here, and a file that names this machine belongs in `.gitignore` rather than in a
  commit.
- **a fact that lives outside the root is brought in deliberately.** Ask for it, or have the file,
  the path or the contents quoted to you — discovery by walking is the failure this rule prevents.

Why this is a rule rather than a preference: an edit that lands in a neighbouring repository is
**silent**. The tree that received it looks unchanged to its own guards (a file somebody else's
rules never enumerate), the tree that wrote it looks clean, and nothing in either suite reports it.
A machine file written into the package next door is that failure — it made the receiving package's
own guards red while leaving the writer's tree green.

### What holds it here

`tests/Unit/Support/RepoEscapesTest.php` reads the files a commit would carry and *walks* every path
they write, from the directory of the file that wrote it. A token is a finding only when the walk
goes above the root, which is what lets the records keep their own links upward while a climb cannot
be written at the root at all; `dirname(__DIR__, n)` is read the same way, because a guard that can
be walked around by writing the same location in the other language is a guard that will be. The
reading itself is `tests/Support/RepoEscapes.php`, and it is the only listing of "the files a commit
would carry" this package has.

The other half of that rule — the clause about a home directory, another drive or a network share — is
`tests/Unit/Support/MachinePathsTest.php`, over the same listing of files `RepoEscapes` produces. A
path is a finding there when it names a drive, an account or a share rather than a directory every
machine of its kind has; it is excused when it names nobody, and the excused shapes are written down
in `tests/Support/MachinePaths.php` beside the patterns: the Windows directory, a program's install
root, a CI runner's home, a tilde, the IDE's marker, and an ellipsis standing for the rest of a path.
The two failures are different questions — a climb leaks a *layout* and stops resolving when the
checkout moves, an absolute path leaks a *machine* and resolves for ever, for one person — which is
why the two readings are two files rather than two rules in one.

---

## 1. Where the authority is

This file states the scope rule and the load order. Everything else is written down once, in the
file that owns it, and is not restated here — a copy would drift and a guard would not notice:

| What | Where |
| --- | --- |
| what this package is, how the wiring works, the layout, the testing | `README.md` (also the consumer-facing record) |
| the public API surface | `API.md` |
| cutting a version: the bump, the changelog, the tag | `RELEASING.md`, and `bin/release.php` + `bin/weighing.php` |
| getting commits and annotated tags to GitHub from Windows | `PUSHING.md` |
| static analysis: what is read and why | `STATIC-ANALYSIS.md` |
| the design records, one decision each | `docs/` |
| the state of the work when it was last taken | `HANDOFF.md`, held to the tree by `tests/Unit/Docs/HandoffTest.php` |
| the rule above, and the paths that would break it — a climb, and a machine | `tests/Support/RepoEscapes.php`, `tests/Unit/Support/RepoEscapesTest.php`, `tests/Support/MachinePaths.php`, `tests/Unit/Support/MachinePathsTest.php` |
| the skills for working here, and what each one wraps | `.agents/README.md`, `.agents/skills/` |

The programs are in `bin/`: `checks.php` (the gate), `tool.php` and `tools.php` (how a tool is run),
`release.php`, `weighing.php`, `inventory.php`, `surface.php`, `api.php`, `counts.php`, `blame.php`
and `publish-config.php`. The published config is `config/db-manager.php` with `config/app.php`.

---

## 2. Load order

1. this file — the scope rule above, and what follows;
2. `README.md` — what the package is and how to work on it;
3. `.agents/README.md` — the skills, and the two runbooks they wrap;
4. `HANDOFF.md` — the state of the work, as of the day it was taken, before you trust it as "now".

---

## 3. Before you finish

- run the gate — `bin/checks.php` (`composer checks` runs the same program) — and treat a red check
  as the answer rather than as an obstacle;
- the suite is `composer test` (lint, static analysis, unit tests), or `composer test:unit` on its
  own;
- on Windows, Composer over an intercepting TLS scanner goes through `bin/composer-ca.ps1` rather
  than a bare `composer` — `docs/composer-tls-windows.md` says why;
- `README.md`, `RELEASING.md` and every record under `docs/` are read by the guards in
  `tests/Unit/Docs/`: the counts they state, the tests they cite and the exit codes they list are
  compared with the code, so a number or a name written in prose is a claim rather than a comment.

---

## 4. Commits and pushes

Follow the skills rather than inventing a shape: `.agents/skills/pkg-commit/SKILL.md` for a commit
(the message `bin/weighing.php` reads back, the `## Unreleased` entry that decides the bump, the
signature, and the files that are never committed) and `.agents/skills/pkg-push/SKILL.md` for
getting it to GitHub from PowerShell 7. A release is `.agents/skills/pkg-deploy/SKILL.md` over
`RELEASING.md`.

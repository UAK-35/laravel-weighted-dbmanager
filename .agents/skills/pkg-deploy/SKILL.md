---
name: pkg-deploy
description: Publish a version of this package - weigh the bump, promote the CHANGELOG, tag it, push the tag, confirm Packagist knows it, and hand the consumer the pin to raise. Invoke on @pkg-deploy mentions, a request to release or publish this package, or a request to point an application at a newer version.
---

# `pkg-deploy` — releasing and distributing the package

For a Composer library, "deploy" is two things and only the first is inside this repository:
**publish a version** (cut it, tag it, get the tag onto the remote so Packagist derives it),
then **install it where it is used** (a consumer raises its constraint and updates). The
second half happens in a different repository — name it, do not do it from here.

Everything in this skill that runs here runs from the package root.

[RELEASING.md](../../RELEASING.md) is the authority on what a release is and what the script
does at each step. The version of this package is the **git tag** and nothing else — there is
no `version` field and no version file, because a number stored in the repository is a second
source of truth that drifts from the tag.

## 1. Weigh it — always the dry run first

```bash
composer release -- --weigh --dry-run     # print the plan; write nothing
```

`--weigh` is not a report: it *decides* the bump from the `## Unreleased` notes, the commits
since the last tag and the public surface of `src/` and `config/`, then the run takes it. The
dry run prints the bump, the next version, the branch it would cut from, the CHANGELOG head
after promotion, and the exact git commands — without writing, committing or tagging. It is
safe on a dirty tree.

Read the plan's notes before continuing:

* a **breaking change** is any bullet under `### Removed` in the Unreleased notes, plus
  anything the surface diff sees disappear — while the package is pre-1.0 a breaking change
  weighs as a **minor**, not a major;
* a **public-surface change with no note** is reported (`--allow-silent-notes` overrides it,
  loudly) — the repair is a CHANGELOG entry, not a flag;
* a **`files.tsv` / `methods.tsv` / `surface.tsv`** stamped for a tag other than the base is
  reported **stale** and skipped; that costs a second opinion and nothing else, because no
  signal ever lowers the bump.

## 2. Cut it

```bash
composer release -- --weigh               # promote CHANGELOG, commit, tag (annotated)
```

Useful flags, from `php bin/release.php --help`:

| flag | for |
|---|---|
| `--minor` / `--major` / `--version=X.Y.Z` | declare the bump instead of weighing it. Never smaller than `--weigh`'s answer — a refused bump exits 1 and names what forbade it |
| `-y`, `--yes` | do not ask for confirmation |
| `--dry-run` | the plan only |
| `--push` | push the branch with `--follow-tags` when done |
| `--branch=NAME` | release from this branch (default `main`, or `dev` for a prerelease) |
| `--skip-ci` | tag anyway when the **CI rail** cannot be satisfied |
| `--allow-dirty` | allow uncommitted changes to tracked files |

**The CI rail is the one flag to think about.** A tag is a version, so the script refuses to
cut one on a commit nothing verified: HEAD has to be the tip of the remote branch, and the
workflow's push run for it has to have finished and passed. The release commit itself is
bookkeeping and cannot have a run of its own yet — the workflow's `v*` tag trigger records
that one after the push. `--skip-ci` overrides the rail, and the plan says so: read what it
skipped rather than assuming.

**Prerelease or release?** The suffix decides the branch — a prerelease (`-alpha1`, `-beta1`,
`-rc1`, `-dev`) is cut from `dev`, a release from `main` — and the suffix must be one Composer
can read. `0.0.1-dev.1` and `0.0.1-alpha.1` are refused, because two tags carrying the two
spellings would be one version published twice.

## 3. Publish it — the tag has to travel

A release is not published until its tag is on the remote. That is `pkg-push`'s job, and the
one command is:

```powershell
git push origin main --follow-tags      # from PowerShell 7, never MSYS
```

Cutting and pushing in one step is fine on this layout (`--push` runs exactly that), and
[PUSHING.md](../../PUSHING.md) is where the refusals and the workflow-scope trap are written
down.

## 4. Confirm it — three layers, cheapest first

```bash
git rev-parse v0.2.0^{commit}                    # locally: what you expect
GIT_TERMINAL_PROMPT=0 git ls-remote --tags origin  # on the remote: the `^{}` line must match
composer show --all uak35/laravel-weighted-dbmanager  # on Packagist: the version it knows
```

An annotated tag is listed twice on the remote — the tag object and its peeled commit — and
only the `^{}` line matters. A tag on the remote that is absent from Packagist is a webhook
problem rather than a push problem.

## 5. Distribute it — the consumer's half, in another repository

This package is developed here and consumed elsewhere. Pointing a consumer at the new version
means raising its constraint and updating the lock so the two agree — in the LPR application
that is `site/composer.json` plus an update through its Windows Composer wrapper
(`scripts\windows\composer.ps1 update uak35/laravel-weighted-dbmanager`), because a plain
`composer update` there fails against the local TLS scanner and the Linux-only extension
deltas. Do that from **that** repository, under its own rules, and leave its `composer.lock`
content-hash in step with its manifest — a manifest edited without its lock is a state that
repository's own guards refuse.

## What cannot be undone

A published tag is not moved. Packagist caches what it saw, and a moved tag is a version that
means two different trees. If a release is wrong, **tag the correction** — and if a tag has to
go before it is pushed, `git tag -d vX.Y.Z` locally is harmless because nothing has seen it.

Never release, tag or push unless the user asked for it.

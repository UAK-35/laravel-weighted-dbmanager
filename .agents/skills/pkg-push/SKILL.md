---
name: pkg-push
description: Get commits and release tags from this package onto GitHub, and prove the tag landed - the HTTPS remote, the PowerShell 7 shell MSYS cannot replace, the workflow-scope refusal, and the three-layer verification of a tag. Invoke on @pkg-push mentions, a push that was refused, or a tag that has to be confirmed on the remote.
---

# `pkg-push` — getting this package onto its remote

Run everything from the package root.

`origin` is **HTTPS** — `https://github.com/UAK-35/laravel-weighted-dbmanager.git` — for fetch
and for push. [PUSHING.md](../../PUSHING.md) is the long form: what was checked on this
machine, and which of those checks have since moved. Read it before a first push.

## Never push from MSYS / Git Bash

The global `core.sshCommand` points at **Windows OpenSSH**, which asks for a key passphrase
on the console; a non-interactive tool shell has no console, so the command waits and then
fails with `Could not read from remote repository`. It is inert while the remote is HTTPS —
a credential helper is consulted instead of ssh — but the rule is the same either way. Push
from PowerShell 7:

```powershell
git push origin main --follow-tags
```

`--follow-tags` carries the **annotated** tags that point at the commits being pushed, and
`bin/release.php` creates exactly those (`git tag -a vX.Y.Z -m vX.Y.Z`). A lightweight tag is
left behind silently, which is how a release ends up on the branch but not on the remote.

## Preflight — know what will travel

```bash
git status -sb                       # is the branch ahead, and of what
git log origin/main..HEAD --oneline  # the commits that will be published
git diff --stat origin/main..HEAD    # how big the change is
```

A change that is not **committed** does not travel, however the tree looks. And a push
publishes commits, not files: check the commit, not the working tree.

## Two refusals worth recognising before they happen

* **A non-fast-forward** means the remote moved. The repair is `git pull --rebase` and a
  plain push, or — only when the remote is known to be behind your intent —
  `git push --force-with-lease origin main --follow-tags`. `--force-with-lease` refuses if
  the remote moved since your last fetch, which is the one case a bare `--force` silently
  discards somebody else's commit. **Never `--force`.**
* **A workflow file.** A credential that may push commits is not automatically allowed to
  change `.github/workflows/*`, because a workflow runs with the repository's secrets.
  GitHub refuses the **whole** push, naming the credential (`a Personal Access Token`,
  `an OAuth App` or `a GitHub App`) — and which one it names decides the fix. The table in
  [PUSHING.md](../../PUSHING.md) maps each to its setting; the one trap is that
  `gh auth refresh -s workflow` only works for the token `gh` obtained itself, not for a
  fine-grained PAT, whose permissions live on the token rather than in `gh`'s keyring.
  Nothing landed in that rejection: the branch, the tags and every other commit in the same
  command are still local.

## Verify the tag landed — three layers, cheapest first

The repository is public, so the first two need no credential at all.

```bash
# 1. locally — know what you expect
git rev-parse v0.2.0^{commit}          # the commit the tag points at
git log --oneline -1

# 2. on the remote — the same commit
GIT_TERMINAL_PROMPT=0 git ls-remote --tags origin
```

An annotated tag is listed **twice**: the tag object, and its peeled commit marked `^{}`.
The two ids are meant to differ, and the `^{}` line is the one that must equal the
`rev-parse` from step 1. This is also the check that catches a branch that moved on while
its tag stayed behind — the failure `--follow-tags` exists to prevent.

```bash
# 3. on Packagist — a tag absent here is a publishing problem, not a push problem
composer show --all uak35/laravel-weighted-dbmanager
```

Packagist derives every version from tags, so a tag that is on the remote and missing there
is a webhook or submission problem; the last section of [RELEASING.md](../../RELEASING.md)
is where connecting it is written down.

`GIT_TERMINAL_PROMPT=0` is what turns the credential wait into an immediate error, which is
what makes these commands testable here at all: a public repository answers them with no
credential, and a private one or a dead credential fails in the way
[PUSHING.md](../../PUSHING.md) describes.

## Do not push unless asked

Pushing publishes. It is the user's call, and cutting a version and pushing it is `pkg-deploy`.

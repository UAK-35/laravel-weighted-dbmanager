# Pushing this package (`main`) — what authenticates here, why MSYS fails, and how to verify the tag landed

`origin` is **`https://github.com/UAK-35/laravel-weighted-dbmanager.git`** — HTTPS, for
fetch and for push. A release is not published until its tag is on that remote;
[RELEASING.md](RELEASING.md) covers what the tag *is*, this file covers getting it there
from this machine.

## What was checked, and what was not

Everything marked ✓ was read off this machine on 2026-09-27. **The push itself was not
exercised** — the GitHub CLI's stored token was already invalid — so the recipe below is
assembled from verified parts and has no verified end-to-end push behind it yet. It is a
runbook to follow with your eyes open, not a transcript of one that worked.

| Claim | Evidence |
|---|---|
| `origin` is HTTPS | `git remote -v` → `https://github.com/UAK-35/laravel-weighted-dbmanager.git` |
| the repository is public | `GIT_TERMINAL_PROMPT=0 git -c credential.helper= ls-remote --heads origin` answered `566cdbe… refs/heads/main` — with **every** credential helper disabled, so there was no credential available to have been used ✓ |
| no tags on the remote yet | the same call listed no `refs/tags/*` ✓ |
| HTTPS credentials come from Git Credential Manager | `git config --show-origin --get-all credential.helper` → `manager`, from both `C:/Program Files/Git/etc/gitconfig` and `~/.gitconfig` ✓ |
| the `gh` CLI is installed but its token is dead | `gh --version` → 2.101.0; `gh auth status` → `X … The token in keyring is invalid`; `gh api repos/UAK-35/laravel-weighted-dbmanager` → HTTP **401 Bad credentials** ✓ |
| `glab` is irrelevant here | not on `PATH`, and its keyring token authenticates to gitlab.com — not github.com ✓ |
| no repo-local ssh setting | `git config --local --get core.sshCommand` → unset ✓ |
| ssh is globally redirected to Windows OpenSSH | `core.sshCommand = C:/Windows/System32/OpenSSH/ssh.exe`, from `~/.gitconfig` ✓ |

Two rows were re-read later the same day, and they are the two that moved:

* **the `gh` credential authenticates now** — `gh auth status` answers `✓ Logged in to
github.com account UAK-35 (keyring)` with `Token: github_pat_…`, a **fine-grained** PAT.
  Which matters below, because the scope a push needs is a permission on that token rather
  than something `gh` can add to it.
* **the remote is no longer tagless** — `refs/heads/dev`, `refs/heads/main`,
  `refs/tags/v0.0.1-alpha1` and `refs/tags/v0.1.0-alpha1` are all there, so the row that says
  no tags existed describes the morning this table was taken, not today.

The last row is the one that decides who wins a negotiation, and it is **global**: it
applies to every repository on this machine, this one included. It is inert here only
because `origin` is HTTPS and no ssh process is ever started.

## The push

Never from MSYS / Git Bash. Run it from PowerShell 7, whose console can answer a prompt:

```powershell
cd E:\_WORKS\lpr\work\LPR\side-projects\laravel-weighted-dbmanager

git push origin main --follow-tags
```

`--follow-tags` carries the **annotated** tags that point at the commits being pushed.
`bin/release.php` creates exactly those (`git tag -a vX.Y.Z -m vX.Y.Z`), so the release tag
travels with the release commit and no second command is needed.

### The import is a child of the remote's `Initial commit`

The remote's `main` is `566cdbe` (`Initial commit`, LICENSE + README), and the package
import was placed **on top of it** — `git reset --soft 566cdbe` puts `main` back on that
commit and leaves the whole tree staged, which is how a tree that already existed locally
becomes its child without a rebase. So `main` and `origin/main` share an ancestor, the first
push is a fast-forward, and the plain command above is the whole of it. **No force is
involved, and nothing on the remote is replaced.**

The alternative is to cut the import as a **root commit**, which shares no ancestor with
`origin/main`; a plain push of that is rejected as a non-fast-forward and the first push
has to be

```powershell
git push --force-with-lease origin main --follow-tags
```

`--force-with-lease` rather than `--force` on purpose: it refuses if the remote has moved
since your last fetch, which is the one case where a force silently discards somebody else's
commit. That variant **replaces `566cdbe`**, so its history is not preserved.

Either way the tag must travel in the same command as the branch, or the two can end up
disagreeing about what was released.

### `--push` is fine with this layout

`bin/release.php --push` runs `git push <remote> <branch> --follow-tags` — a plain push,
which is exactly what the fast-forward layout needs:

```powershell
php bin/release.php --version=0.0.1 --ignore-policy --yes --push
```

It is only the root-commit variant that `--push` cannot express, because it has no way to
ask for a force. With this layout, cutting and pushing in one step is correct.

### If you prefer `gh` to Git Credential Manager

`gh`'s token has to be alive first — it is not, today:

```powershell
gh auth refresh -h github.com
git -c credential.helper= -c "credential.helper=!C:/Program Files/GitHub CLI/gh.exe auth git-credential" push origin main --follow-tags
```

The empty `-c credential.helper=` resets the helper list so **only** `gh`'s helper runs;
the `!`-prefixed value means git executes `gh auth git-credential`, which supplies the
keyring token without printing it, and **nothing is written to git config**. This is the
GitHub equivalent of the `glab` trick used elsewhere on this machine — the shape transfers,
the token does not.A personal access token is the last resort, and only because it is the one path with no
stored credential at all:

```powershell
git -c credential.helper= push https://<PAT>@github.com/UAK-35/laravel-weighted-dbmanager.git main --follow-tags
```

It puts the secret in your shell history and in any process listing while it runs. Prefer
either of the two above — and note which kind of token `gh` is holding here today, because
the next section turns on it: `gh auth status` shows `github_pat_…`, a **fine-grained**
token, whose permissions live on the token rather than in `gh`'s keyring.

### A push that changes a workflow file needs the `workflow` scope

A credential that may push commits is not automatically allowed to change a **workflow
file**, because a workflow runs with the repository's secrets. GitHub therefore refuses the
whole push when any commit in it creates or updates `.github/workflows/main.yml` and the
credential does not carry the scope. **Not reproduced here** — that needs a token to
lack the scope and a workflow edit to push — but this is the shape, from GitHub's own message
and the reports of it:

```
remote: refusing to allow a Personal Access Token to create or update workflow `.github/workflows/main.yml` without `workflow` scope
To https://github.com/UAK-35/laravel-weighted-dbmanager.git
 ! [remote rejected] main -> main (refusing to allow a Personal Access Token to create or update workflow `.github/workflows/main.yml` without `workflow` scope)
error: failed to push some refs to 'https://github.com/UAK-35/laravel-weighted-dbmanager.git'
```

Three things are worth reading off that, and the first is the one that decides the fix.

**1. The message names the credential.** The same refusal comes in three spellings —
`a Personal Access Token`, `an OAuth App` (the token `gh` stores after its own browser
sign-in) and `a GitHub App` — and which one it says is the only thing that tells you which
row of the table below applies. `gh auth status` settles it when the message is ambiguous:
its `Token:` line shows `github_pat_` for a **fine-grained** PAT, `ghp_` for a **classic**
one, and `gho_` for an OAuth token.

**2. Nothing landed.** This is a rejection of the ref, not a partial push: the branch, the
tags `--follow-tags` was carrying, and every other commit in the same command are still
local. A release that ends with this has published nothing, however much of its output said
otherwise.

**3. It is the commit that matters, not the release.** `bin/release.php` writes
`CHANGELOG.md`, `composer.json` and the two inventories, never `.github/`, so a release push
is only refused when the branch it is on also carries a workflow change that has not
travelled yet — the two go in one command, so one cannot be refused without the other.

| What the message called it | What to change | Where |
|---|---|---|
| `a Personal Access Token`, and `gh auth status` shows `github_pat_…` | that token's **Workflows** repository permission → *Read and write* | the token itself, at **Settings → Developer settings → Personal access tokens → Fine-grained tokens** — its permissions are not in `gh`'s keyring, so nothing local can add them |
| `a Personal Access Token`, and the token is classic (`ghp_…`) | the **`workflow`** scope | re-create the token with `workflow` ticked: a classic token's scopes are chosen once, at creation |
| `an OAuth App` (`gho_…` — the token `gh` stored from its own sign-in) | the **`workflow`** scope on it | `gh auth refresh -h github.com -s workflow`, which opens a browser to widen the token `gh` itself obtained |
| `a GitHub App` | the installation's workflow permission | the app's settings, by whoever owns it |

That third row is the only place `gh auth refresh` belongs, and the distinction is easy to
get wrong: it asks GitHub to widen a credential **`gh` obtained itself**, by sending you
through the sign-in flow again. A PAT was not obtained that way — a fine-grained one cannot
even have its permissions listed by `gh auth status` — so refreshing there re-authenticates
rather than granting the permission, and the push is refused again with the same sentence.

**If you would rather not widen the credential**, the workflow change can still land:
GitHub's web editor is authenticated as *you* rather than as the token that pushes, so
committing the edit through the repository's UI needs no scope from this machine. Either
way, keep the two apart — one push carrying both a CI change and a release means the
rejection holds the release back, and the release is the half that cannot be cheaply redone.

## Why MSYS / Git Bash fails

None of this bites while `origin` is HTTPS, but it is why the same rules are repeated for
every repository on this machine — and it starts biting the moment anyone rewrites `origin`
to `git@github.com:...`.

| Symptom | What it looks like | What it actually is |
|---|---|---|
| `git push` hangs, then `fatal: Could not read from remote repository` | no ssh output at all, no prompt | the global `core.sshCommand` starts **Windows OpenSSH**, which asks for the key passphrase on the *console*. A non-interactive shell has no console, so the prompt is never seen and the command waits |
| `Permission denied (publickey)` with `-o BatchMode=yes` | an unregistered key | the key **is** registered; it simply cannot be decrypted without the passphrase |
| `GIT_SSH` set, but the wrapper never ran and its log file was never created | 120s timeout, then the generic fatal | `GIT_SSH` **loses to `core.sshCommand`**: git's order is `GIT_SSH_COMMAND` > `core.sshCommand` > `GIT_SSH` > `ssh`. Only `GIT_SSH_COMMAND` overrides the setting |
| `CreateProcessW failed error:193` / `ssh_askpass: posix_spawnp: Unknown error` | askpass "not working" | MSYS ssh cannot spawn a **shell-script** askpass when **git** is its parent. The same script works when ssh is started from a shell |

The HTTPS equivalent of that first row is what actually limits this shell today: Git
Credential Manager is configured and would help, but it wants a window to do it, and a
non-interactive tool shell has no window to give it. `GIT_TERMINAL_PROMPT=0` turns the wait
into an immediate error, which is the difference between a runbook you can test and one
that hangs:

```bash
GIT_TERMINAL_PROMPT=0 git ls-remote --heads --tags origin
```

That is how the public-repository row above was established — and the control is what makes
it evidence: the same command against a repository that does not exist fails with

```
fatal: could not read Username for 'https://github.com': terminal prompts disabled
```

So a public repository answers that call and a private one cannot, which turns the command
into the diagnostic for the two failures that look identical from a plain `git push`:
*this repository is private* and *my credential is dead*.

## Verifying the tag landed

Three layers, cheapest first. The first two need no credentials at all — the repository is
public, so GitHub answers them unauthenticated.

Everything below needs no credential at all, which is why it is the first thing to reach
for when a push has just been refused: a tag that is on the remote and absent from Packagist
is a publishing problem rather than a push problem, and a tag that is nowhere is a push that
never landed.

**1. Locally, before pushing — know what you expect.**

```powershell
git rev-parse v0.0.1^{commit}    # the commit the tag actually points at
git log --oneline -1
```

**2. On the remote — the tag exists, and points at that same commit.**

```powershell
git ls-remote --tags origin
```

An annotated tag is listed **twice** — the tag object and its peeled commit, marked `^{}`:

```
<tag-object-id>   refs/tags/v0.0.1
<commit-id>       refs/tags/v0.0.1^{}
```

The two ids are supposed to differ, and the second one is the only line that matters. It
must equal the `git rev-parse v0.0.1^{commit}` from above. This is also the check that
catches the failure `--follow-tags` exists to prevent: a branch that moved on while its tag
stayed behind.

`git status -sb` should show the branch no longer `[ahead N]`.

**3. On Packagist — the tag is published, once the repository is submitted.**

```powershell
composer show --all uak35/laravel-weighted-dbmanager
```

or the raw metadata Packagist serves, which names every version it knows:

```
https://repo.packagist.org/p2/uak35/laravel-weighted-dbmanager.json
```

A tag that is on the remote but absent from both of those is a webhook/update problem, not
a push problem — Packagist derives versions from tags, so there is nothing else it could be
waiting for. Submitting the repository and connecting the hook is the last section of
[RELEASING.md](RELEASING.md).

`gh api repos/UAK-35/laravel-weighted-dbmanager/git/refs/tags/v0.0.1` answers the same
question, but only with a live token — and the credential section is where that is settled.
The unauthenticated `ls-remote` is still the check to reach for first: it needs nothing from
the keyring, so its answer cannot be about which credential was used.

## Traps worth remembering

- **The repository is public, so a push has no staging area.** Every push is immediately
  visible, and a force push to `main` is immediately visible too.
- **Branch protection can refuse the force.** If GitHub protects `main`, the first push is
  rejected regardless of credentials, and the fix belongs in the repository settings rather
  than in this file.
- **Never move a published tag.** If a tag is wrong, tag the correction — Packagist caches
  what it saw. Before a push, deleting it locally is harmless: `git tag -d v0.0.1`.
- **`--follow-tags` carries annotated tags only.** A lightweight tag is left behind
  silently, which is why `bin/release.php` creates annotated ones.
- **Pushing publishes commits, not working-tree files.** A change that is not committed
  does not travel, however the tree looks locally.
- **`MSYS_NO_PATHCONV=1`** is for Windows `aws` CLI calls that take a leading-slash value.
  It has nothing to do with a push from this repository.

## What this file does not cover

GitHub's review and branch-protection settings, how Packagist is first connected (the last
section of [RELEASING.md](RELEASING.md)), and anything about the originating application's
pipeline: CodeBuild, ECR and the Go websocket service are separate machines and separate
repositories.

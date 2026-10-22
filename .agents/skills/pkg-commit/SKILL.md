---
name: pkg-commit
description: Commit a change inside the laravel-weighted-dbmanager package - the message shape the weighing pass reads back, the CHANGELOG entry that decides the bump, the signature, and the two files that must never be committed. Invoke on @pkg-commit mentions, a request to commit work done in this package, or a request for a commit message here.
---

# `pkg-commit` — committing inside `laravel-weighted-dbmanager`

This skill is scoped to the package repository — the directory that holds this package's
`composer.json`. Everything below runs from there. The consuming application is a **different repository** with its own commit rules; a
change that belongs there does not belong in a commit made here.

## The message is read by the release tool

A commit message here is not only for a reader. `bin/weighing.php` weighs the changes that
are about to be released from three signals — the `## Unreleased` notes, the commits since
the last tag (read as conventional subjects: `feat:`, `fix:`, `docs:`, `test:`), and the
public surface of `src/` and `config/`. `bin/release.php --weigh` takes the bump they ask
for, so a subject that names the wrong kind of change is a version that is cut wrong.

The shape that has been used here:

```
feat: the preflight says whether anything will move the pooler at a boundary

<why, in prose: the state that was silent, what the change makes true, and what it
was measured against>

Measured, not assumed:

- `php bin/checks.php --only=counts,sentences,phpstan,pint` — 4 passed (…).
- `php bin/tool.php phpunit tests/Unit/Console/DbDoctorTest.php` — OK (…).

Deliberately left alone: <what a reader would expect to change and does not>.

[skip ci/cd build]

🤖 Generated with Codebuff
Co-Authored-By: Codebuff <noreply@codebuff.com>
```

* **`feat:` / `fix:` / `docs:` / `test:` / `refactor:`** — the prefix the weighing pass reads.
* **`Measured, not assumed:`** — one bullet per check, with the command and its result. A
  claim without the command that produced it is the thing this line exists to prevent.
* **`[skip ci/cd build]`** — this trailer is a promise that the change needs no build. It is
  read on the LPR side by the CodeBuild webhook filter, not here; omit it when the change
  should trigger one.
* Keep the body wrapped at roughly 100 columns, like the rest of this repository's prose.

## The CHANGELOG entry is part of the commit

`CHANGELOG.md` has the `## Unreleased` section at the top, with `### Added`, `### Changed`,
`### Fixed` and `### Removed` beneath it. `--weigh` reads those headings back:

* an entry under **`### Removed`** is the notes declaring a **breaking change**, and no bump
  flag can talk the script out of it;
* an entry whose heading is missing is a change the weighing pass cannot see;
* a public-surface change with no matching note is reported by the plan
  (`--allow-silent-notes` overrides it, deliberately loudly).

So the entry and the code go in one commit. A commit that changes `src/` or `config/` and
leaves the notes alone is a release that cannot be weighed.

## A tree holding several concerns is several commits

The unit is the concern rather than the file. Two files changed by one thought belong in one
commit, and one file carrying two thoughts belongs in two — each holding that file in the
state its own commit needs (HEAD plus the one hunk), materialized before staging.

When the notes were authored first, they are the grouping: one bullet per commit, and the
bullets accumulate so that each commit's `## Unreleased` is a superset of the previous one.
Nothing has to be written twice that way, and the history reads as the list a reader already
saw.

Before the first commit, save the authored copies and `git show <rev>:<path>` for every file
that will serve more than one concern. An intermediate state is not recoverable afterwards,
and reconstructing it from the finished file is how a commit ends up holding a hunk that
belongs to a later one.

Granularity is the user's call when the tree admits more than one honest split. Ask whether
they want one commit per concern, per bullet or a coarser grouping, rather than choosing a
shape and defending it.

Two lines that keep a split honest:

* **No broad stage.** `git add -A` publishes another hand's work as yours. Name the paths.
* **An index that already carries paths is unstaged, not reverted.**
  `git restore --staged -- <paths>` is an index write and nothing else; it leaves the working
  tree alone, which is why it is allowed where a checkout is not.

## The checks to run before committing

This repository has **no pre-commit hook of its own** (`core.hooksPath` is unset and
`.git/hooks` is stock), so nothing runs these for you:

```bash
php bin/checks.php --only=counts,sentences,pint,phpstan   # the fast gate; the full run is all 13
php bin/counts.php --check                                # the prose numbers in the records
php bin/tool.php phpunit <file-or-dir>                    # the tests the change touches
```

`bin/checks.php --list` names every check it can run. A change to a documented number — a
matrix cell, a README table row, a test case — is rewritten by `bin/counts.php`, not by
hand, and `ProseNumbersTest` fails on a record that states a number the code does not have.

## When a check is red

A red check is the answer rather than an obstacle. Fix it with the tool that owns the file —
`bin/tool.php pint <path>` for style, `bin/counts.php` for a stated number — and never with a
skip flag, a narrower `--only`, or an assertion edited until it agrees.

A failure that predates the change is the one exception, and it is established rather than
assumed: reproduce it at the previous commit in a throwaway worktree. If it reproduces there,
it is the environment's and not the change's. Then say so in the message under
`Measured, not assumed:` — what failed, the commit it was reproduced at, and what made it
pass — because a green run obtained by adding an entry to `PATH` is a fact the next reader
needs. Remove the worktree afterwards (`git worktree remove --force`, then
`git worktree prune`).

## Committing

```bash
git add <the paths you changed>            # never `git add -A`: other work shares this tree
git status --short                         # confirm the set is exactly yours
git commit -m "$(cat <<'EOF'
<subject>

<body>

Measured, not assumed:

- <command> — <result>

[skip ci/cd build]

🤖 Generated with Codebuff
Co-Authored-By: Codebuff <noreply@codebuff.com>
EOF
)"
```

Commits are **signed** here (`commit.gpgsign=true`, the key in `user.signingkey`), and the
package has no ref hook that refuses an unsigned one — so check it yourself:

```bash
git log --format='%h %G? %s' -3          # every row should read `G`, never `N`
```

This package carries its own guarded commit path, `git/commit_paths.py` (Python 3), run from
the package root:

```bash
python git/commit_paths.py -F <message-file> -- <package-relative paths>
```

It commits the working-tree content of exactly the paths you name rather than whatever is
staged, so another hand's staged work is left untouched, and it sets the index to the commit
afterwards so a later plain `git commit` cannot publish the old bytes back over it.
`--dry-run` builds and checks the commit without making it. It is a checkout's tool —
`.gitattributes` holds it — and it is exported out of a consumer's tarball.

## Reading the commit back

An exit status says a commit was made, not that it holds what was intended. Four reads, one
line each:

```bash
git show --name-only --format="" <sha>   # exactly the paths named, plus CHANGELOG.md
git show <sha>:CHANGELOG.md              # the notes that belong to this commit alone
git status --short                       # empty: no authored state was left behind
git log --format='%h %G? %s' -1          # `G`, never `N`
```

For a split, read the whole set: each commit carries its own paths and its own notes, and the
last commit's `CHANGELOG.md` is identical to the file that was authored. That comparison is
the one that catches a hunk that went into the wrong commit, which no per-commit check can
see.

## Two files that are never committed

* **`.freebuff/project-id`** — machine state from the agent harness, and `.gitignore`
  carries `/.freebuff/project-id` for it. If it shows up in `git status`, leave it alone.
* **`/bin/*.bak`** — an editor's backup beside a script. Ignored, and never added.

## Do not commit unless asked

Writing the message is a normal part of the work; making the commit is the user's call
unless they asked for it, and pushing is a separate step (`pkg-push`).

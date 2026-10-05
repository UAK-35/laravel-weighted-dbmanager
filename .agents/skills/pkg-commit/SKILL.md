---
name: pkg-commit
description: Commit a change inside the laravel-weighted-dbmanager package - the message shape the weighing pass reads back, the CHANGELOG entry that decides the bump, the signature, and the two files that must never be committed. Invoke on @pkg-commit mentions, a request to commit work done in this package, or a request for a commit message here.
---

# `pkg-commit` — committing inside `laravel-weighted-dbmanager`

This skill is scoped to the package repository:

    E:\_WORKS\lpr\work\packages\laravel-weighted-dbmanager

Everything below runs from that directory. The consuming application
(`E:\_WORKS\lpr\work\LPR\site`) is a **different repository** with its own commit rules; a
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

## The checks to run before committing

This repository has **no pre-commit hook of its own** (`core.hooksPath` is unset and
`.git/hooks` is stock), so nothing runs these for you:

```bash
php bin/checks.php --only=counts,sentences,pint,phpstan   # the fast gate; the full run is all 12
php bin/counts.php --check                                # the prose numbers in the records
php bin/tool.php phpunit <file-or-dir>                    # the tests the change touches
```

`bin/checks.php --list` names every check it can run. A change to a documented number — a
matrix cell, a README table row, a test case — is rewritten by `bin/counts.php`, not by
hand, and `ProseNumbersTest` fails on a record that states a number the code does not have.

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

If you are working from the LPR checkout, where this package is a sibling directory, the
guarded path is `python git/commit_paths.py --repo ../../packages/laravel-weighted-dbmanager
-F <message-file> -- <package-relative paths>`: it commits the working tree rather than
whatever was staged, and it repairs the index afterwards.

## Two files that are never committed

* **`.freebuff/project-id`** — machine state from the agent harness, and `.gitignore`
  carries `/.freebuff/project-id` for it. If it shows up in `git status`, leave it alone.
* **`/bin/*.bak`** — an editor's backup beside a script. Ignored, and never added.

## Do not commit unless asked

Writing the message is a normal part of the work; making the commit is the user's call
unless they asked for it, and pushing is a separate step (`pkg-push`).

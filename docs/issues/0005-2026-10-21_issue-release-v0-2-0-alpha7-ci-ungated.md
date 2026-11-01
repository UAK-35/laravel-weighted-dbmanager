# Release v0.2.0-alpha7's CI ran — after the tag, and the rail that gates a release was skipped

## What was asked

Whether the `Release v0.2.0-alpha7` workflow was skipped, or whether it ran and was red — and, if it
was red, to make CI green.

The first half has a definite answer, and it is neither of the two the question expected: **the
workflow ran, and every job in it was green.** What did not happen is the release being *gated* by
it. The tag object was written at `2026-10-20 22:49:40 +05:00` and pushed in the same push as
`dev`; the run that verified it was created twenty seconds later and finished at
`2026-10-20T17:51:32Z`, roughly a minute and fifty seconds after the version existed. At the moment
the release was cut, the commit under it had never been pushed and had no CI record at all, and the
last commit the remote held was red.

Both halves matter, and only the second is a fault.

## The runs

The four runs below are `PHP Composer` runs, read with `gh run list --repo
UAK-35/laravel-weighted-dbmanager`.

| Run | Ref | Commit | Created | Result |
|---|---|---|---|---|
| [39468453058](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39468453058) | `dev` | `b49f3ac` | `17:42:45Z` | **failure** |
| — | — | `1b5e95a` | — | **no run exists** |
| — | — | `563ed95` | — | **no run exists** |
| [39469491120](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39469491120) | tag `v0.2.0-alpha7` | `2a771cc` | `17:50:00Z` | **success** (1m32s) |
| [39469492648](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39469492648) | `dev` | `2a771cc` | `17:50:01Z` | **success** (1m33s) |
| [39469994533](https://github.com/UAK-35/laravel-weighted-dbmanager/actions/runs/39469994533) | `dev` | `3dc70db` | `17:53:34Z` | **success** (1m15s) |

Each blank row was asked for by full commit id — `gh run list --commit
563ed9569f4de4adbccdd3ac59e19bbef5d6512f`, and the same for `1b5e95a` — and answered nothing, which
is what "CI has never seen this commit" looks like rather than a run that was skipped or cancelled.

The remote holds no GitHub Release object for this version; `gh release list` is empty. Publishing
here is a tag, so the tag push is the only event that can start a workflow and the tag's run is the
only CI record a version has.

## The tag run, check by check

The logged summary of run `39469491120`, verbatim apart from the truncation the log itself applies:

```text
13 checks: 12 passed, 0 failed, 1 skipped — 65.3s
syntax     PASS     6.7s  164 files, no syntax errors
ast        PASS     0.6s  164 files parsed
writeback  PASS     0.2s  3 write-back(s) in the shipped tree, every one declared; the detector agreed …
schema     PASS     0.2s  composer.json is valid; composer.lock is not part of this repository, so it is not checked
platform   PASS     0.1s  20 platform requirements satisfied
interpreter SKIP     0.0s  this checkout has no .agents/machine.local.json
tools      PASS     0.0s  4 installed: pint v1.32.1, phpstan 2.3.1, phpunit 11.5.57, yaml-lint v7.4.21
yaml       PASS     0.1s  [OK] All 1 YAML files contain valid syntax.
counts     PASS     0.1s  55 count(s) across 12 record(s), all in step with their derivations.
sentences  PASS     0.0s  9718 prose line(s) in 33 record(s), no fused sentence; the detector agreed wi…
phpstan    PASS     6.0s  [OK] No errors
pint       PASS     8.1s  PASS … 164 files
tests      PASS    43.2s  Tests: 1589, Assertions: 10514, Skipped: 18.
```

The `interpreter` skip is the designed one: the check reads `.agents/machine.local.json`, that file is
gitignored, and a runner has none — which is why `bin/checks.php` records the check as skipped
rather than failing CI over a machine's own record.

The second job, `pgcat pool probe (live)`, is green in 30s: the pinned image
`ghcr.io/postgresml/pgcat:v1.2.0@sha256:627761…` came up on `127.0.0.1:6432`, the probe saw it, and
the `grep -Eq '^OK \([1-9][0-9]* test'` guard held the run to at least one test rather than a
"no tests executed" pass.

## What was red, eight minutes before the tag

Run `39468453058` measured `b49f3ac`, committed at `22:42:23 +05:00` and pushed twenty-two seconds
later. It failed one check, `tests`, on one test:
`HandoffTest::test_the_records_are_what_the_commands_section_1_quotes_say`.

```text
The records are out of step without naming files.tsv, which is a record the note lists.
Failed asserting that '✗ The inventory is out of step with the tree: surface.tsv: 1 line(s) stale: 0 gone, 1 new; new: const | Uak35\WeightedDbManager\Pgcat\SupervisorStep::FAULT_UNREACH… Run php bin/inventory.php to write them and commit them, or leave them alone and let the next release refresh them.' [UTF-8](length: 284) contains "files.tsv" [ASCII](length: 9).
```

The commit added `SupervisorStep::FAULT_UNREACHABLE` and did not regenerate `surface.tsv`, so one
const line was missing from that record. The note's transcript is run rather than read, and it held
two checks:

```text
php bin/inventory.php --check --at=455b3af
  --> exit 1: the inventory is out of step with the tree at 455b3af
      files.tsv: 1 line(s) stale
      methods.tsv: 1 line(s) stale
      surface.tsv: 1 line(s) stale

php bin/inventory.php --check
  --> exit 1: the inventory is out of step with the tree
      surface.tsv: 1 line(s) stale
```

Both quotes were honest: the historical ref really was three records stale, and the tree really was
one. The guard requires every quoted failing check to name all three records — `files.tsv`,
`methods.tsv` and `surface.tsv` — a requirement the `--at=` quote meets and the tree's own does not,
so the assertion on `files.tsv` failed against an output that named only `surface.tsv`. That is why
the log reads as if `files.tsv` were the problem: the stale record was `surface.tsv`, and the
sentence beside the red check names a record the output did not print.

The repair was made by the release commit, not by a fix at `b49f3ac`. `2a771cc` rewrote the three
`.tsv` records, and that is the whole difference on `surface.tsv`:

```diff
-# surface.tsv — generated by bin/inventory.php — describes the tree at v0.2.0-alpha6
+# surface.tsv — generated by bin/inventory.php — describes the tree at v0.2.0-alpha7
@@
+const	Uak35\WeightedDbManager\Pgcat\SupervisorStep::FAULT_UNREACHABLE	src/Pgcat/SupervisorStep.php
```

So the tree the tag points at is green, and the reason is that the release regenerated the record
`b49f3ac` had left stale — not that a commit CI had seen was green.

## Why the release went ahead

`bin/release.php` is unchanged between `b49f3ac` and `HEAD` — `git diff --name-only b49f3ac HEAD`
lists no `bin/release.php` — so the rail read here is the one the release ran: the commit it was cut
from is `563ed95`, and the release commit touched only `API.md`, `CHANGELOG.md`, `HANDOFF.md` and the
three `.tsv` records.

The rail, read from `ciVerdict()` in `bin/release.php`:

1. `--skip-ci` returns `skipped` immediately, before anything is asked;
2. otherwise HEAD has to equal `git ls-remote <remote> refs/heads/<branch>`, or the release refuses
   as unpushed;
3. and `gh run list --commit <HEAD>` has to hold a completed, successful push run whose `headBranch`
   is the branch being released — a tag run reports the tag name as its head branch, and the rail
   filters it out.

At `22:48:58 +05:00`, when `2a771cc` was committed, HEAD was `563ed95` and the remote's `dev` tip was
`b49f3ac`. `563ed95` had no run at all, so step 3 could not pass; and HEAD was not the remote tip, so
step 2 could not pass either. The green branch of the rail was unreachable, and the release was cut
anyway. That leaves exactly one state it can have been in: skipped.

No plan output survives to confirm it — `bin/release.php --dry-run` prints the `ci` line and nothing
records it — so the skip is inferred from the rail's own code and the absence of a run for the commit
being released, which is the only pair of facts that admits it. A `RELEASE_CI_COMMAND` override could
in principle have answered instead, but nothing in the tree sets one.

## What that means for the version

`v0.2.0-alpha7` is a green version: the suite and the live probe both pass on the tree the tag peels
to. It is not a *gated* version: the run that cleared it was started by the push that published it,
so the tag existed before anything had checked the commit it points at. A consumer installing the
tag gets a tree that passed, one minute fifty seconds after it was published.

The distinction is the one the rail exists to draw. Its comment says it plainly: "a tag is a version,
so it is not cut on a commit nothing verified". For this tag, the commit was verified — afterwards.

## What would make the next release gated

Either of these closes it, and they are not exclusive:

1. **Push first, wait, then release without the skip.** The rail already reads the exact commit CI
   built: push `dev`, let the run finish, and the release is cut on a commit that equals the remote
   tip and has a green push run. This is the documented path; the skip is for a machine with no
   `gh`, no token or no network, not for a commit nobody has pushed.
2. **Make the skip answer the push question.** `--skip-ci` skips the CI verdict; whether HEAD is on
   the remote is a `git ls-remote` question and not a CI one. Asking it even under the skip would
   have refused this release, and the same refusal is available on a machine whose `gh` is missing
   but whose network is not — the case the skip exists for. Where the remote cannot be read at all,
   the skip would still answer `skipped`, which is what it is for.

Until one of them lands, the release plan's `ci` line is the only place the difference is visible,
and a skipped line looks exactly like a green one in the tag's result.

## What has not run at all

Seven commits sit on local `dev` that the remote does not carry, and no run exists for any of them:

```text
fa29fb8 feat: the handoff note's blocks are readings, and the run that quotes them is one command
1ae30ba chore: the Laravel plugin's settings are the IDE's, not the package's
f068e83 test: the connector row carries the framework's refusal and both halves of the state
7228276 fix: the doctor record cites words the flip record writes, and a guard reads the rest
3c304b5 test: the prose-number guard reads the numbers no claim reads
954ec4d docs: the recording skills answer for WDM, and a third one records a plan
30c6cd4 docs: three records of application-side work, and the plan that follows one
```

`origin/dev` is `3dc70db`, whose run is green. A push of the seven produces one run for the head
commit, not one per commit, so what CI would measure is `fa29fb8`'s tree — and the uncommitted work
beside it is not part of any tree CI can see.

## Why it can read as "not executed"

Three views make the same green run look like a missing one:

- the tag run's `headBranch` is the tag name, so an Actions list filtered to `dev` does not show it;
- the tag run and the branch run carry the same title and the same commit, so the pair reads as one
  run — the branch's — and the tag appears to have none;
- between the red `b49f3ac` run and the tag there are two commits with no runs at all, so a history
  view goes red, then silent, then straight to the release.

## Commands used

```bash
gh run list --repo UAK-35/laravel-weighted-dbmanager --limit 20
gh run view 39469491120 --repo UAK-35/laravel-weighted-dbmanager
gh run view 39469491120 --repo UAK-35/laravel-weighted-dbmanager --log
gh run view 39468453058 --repo UAK-35/laravel-weighted-dbmanager --log-failed
gh run list --repo UAK-35/laravel-weighted-dbmanager --commit <full sha>
gh api repos/UAK-35/laravel-weighted-dbmanager/actions/workflows
git ls-remote --tags origin
git for-each-ref --sort=-creatordate --format='%(refname:short) %(*objectname:short)' refs/tags
git log --format='%h %ci %s' -6 2a771cc
git show --stat 2a771cc
git diff 2a771cc~1 2a771cc -- surface.tsv
git show b49f3ac:surface.tsv
```

## What was done

No code was changed: the run the question is about was already green, and nothing in the release's
tree is red. The fault the investigation did find is in the process rather than in the tree — a
release cut with the CI rail skipped, on a commit no run had seen — and the two repairs that would
prevent a repeat are stated above rather than applied, because changing what `--skip-ci` means is a
decision about the release contract and not a bug fix.

The working tree's own gate is green with this record in it — thirteen checks, 742.2s — which answers
for this tree rather than for the push: the head `fa29fb8` would publish is this tree without the
uncommitted batch beside it, and no run has read either.

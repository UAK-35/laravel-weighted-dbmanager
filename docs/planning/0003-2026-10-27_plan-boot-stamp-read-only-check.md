# Plan — the boot stamp is read, never written: replace the writability check with a readability one

| | |
|---|---|
| Subject | `swrr.pgcat.boot_file` is judged by the package as a path it must write, and it never writes it |
| Records read | `docs/investigations/0005-2026-10-27_inv-boot-stamp-vs-container-boot-stamp.md`, `docs/investigations/0006-2026-10-27_inv-boot-stamp-unwritable-warning.md` |
| Code to change | `src/Pgcat/PgcatConfigFlipper.php`, `src/Pgcat/FlipWindow.php`, `src/Providers/WeightedDatabaseServiceProvider.php`, `src/Pgcat/ScheduleCauses.php`, `src/Console/Commands/DbDoctor.php` (sentences only) |
| Records to change | `README.md`, `docs/boot-audit-surfaces.md`, `docs/deployment-contract.md`, `config/db-manager.php`, `CHANGELOG.md` |
| Contract this plan writes down | the boot stamp is written **once**, by the deployment, at container start, and never again |

## The premise, stated so the code can be held to it

The stamp is immutable. The deployment writes `date +%s` into it once per container start — before the
pooler is started, so it exists even in the failure the window exists to catch — and nothing rewrites
it. The package's entire relationship with the file is a **read**.

That makes the current check a category error rather than a strictness setting. `PgcatConfigFlipper`
judges the stamp with `writable()`, which is `is_writable()` on the file (or on its directory when the
file is absent), and reports the answer as a fact about the container: *"the container cannot record
when it booted, and the boot window never closes"*. The two questions are different, and the live ECS
task is the proof of both halves:

| live state | what actually happened | what the package said |
|---|---|---|
| `/tmp/container-booted-at`, `0644 root:root`, stamped 09:37:33 | the window closed on time and the container converged at `09:39:04` | **warned every minute** that the container could not record its boot |
| `/var/swrr/container-booted-at`, `0664 www-data`, **empty** | `source: no_stamp`, so `closed()` was never true and the entry flipped again at `10:00:03` | **silent**, because the empty file is writable |

So the check is wrong in both directions at once: it alarms on a working installation and says nothing
about a broken one.

## Step 1 — the stamp leaves the written-path set and becomes a read path

`PgcatConfigFlipper` already owns the vocabulary this needs. `UNNAMED_WRITTEN_PATHS` is a table of
settings, and every one of them is judged as a writer by a loop in `fileProblems()`. The stamp is moved
out of it, and the `audit_lock` row stays — the package does write that one.

| Change | Where | Detail |
|---|---|---|
| the stamp stops being a written path | `PgcatConfigFlipper::UNNAMED_WRITTEN_PATHS` | delete the `boot_file` row; `audit_lock` is the only entry left |
| a read-path table is added beside it | `PgcatConfigFlipper` | one row — setting `swrr.pgcat.boot_file`, label `boot stamp`, path from `$this->window->bootFile()` — read by both the fault list and any inventory that wants to name it |
| the fault becomes a read fault | `fileProblems()`, after the named sources | judge `!is_file()` → `FILE_MISSING`; `!is_readable()` → `FILE_UNREADABLE`; never `writable()` |
| the inventory drops it | `writePaths()` | the stamp is no longer a path this package must be able to write, so `db:doctor`'s `write paths` stops naming it; the `pgcat files` row still does, as a read |
| the sentences say read | the row's `cost` text | *"the boot stamp cannot be read, so the flip window is never judged and the per-minute entry never stands down: it keeps acting at every boundary instead of standing down after its first minutes"* |

The kinds are already there — `missing` and `unreadable` are two of the five `FILE_*` constants, used
by the reader and writer sources today — so no vocabulary is invented and the finding's published
`state` field becomes one of them.

`SWRR_PGCAT_BOOT_FILE` is not renamed, and neither is the finding key `swrr.pgcat.boot_file.unusable`.
A key a dashboard matches is not worth churning for a word: the *condition* is what was wrong.

**Verified by** `PgcatConfigFlipperTest`: a stamp that is `0644 root:root` and readable produces no
problem (the live task's shape); one that is `0600 root` produces `unreadable`; an absent one produces
`missing`; and `writePaths()` no longer carries the label `boot stamp`.

## Step 2 — an empty stamp is a fault, not silence

This is the half the live task exposed and the first half does not cover: an empty file is *readable*,
so a readability check passes it, and `FlipWindow::bootedAt()` folds it into `null` — which the whole
codebase treats as "not judged". The window then never closes and nothing says so.

Add to `FlipWindow` one method that answers *why* there is no stamp, leaving `bootedAt()`, `closed()`
and the published `source` values (`stamped` / `no_stamp`) exactly as they are:

```php
public const STAMP_EMPTY = 'empty';
public const STAMP_UNPARSEABLE = 'unparseable';

/** null when the file holds a usable stamp, or why it does not. */
public function stampProblem(): ?string
```

- absent or unreadable → `null` here, because Step 1 already reports those as `missing`/`unreadable`
  and one fault must not be said twice;
- present and readable, but empty or all whitespace → `empty`;
- present and readable, holding something that is not a positive number → `unparseable`.

The same finding fires for those two, so a container in the live task's second state fails its
preflight instead of passing it quietly. `bootedAt()` keeps returning `null` for them, so the window
semantics do not move and `/health/db`'s block is unchanged.

**Decision, and the recommendation is yes:** fire on `empty` and `unparseable`. The alternative —
leaving them silent because the file is readable — reproduces the exact state that made this
investigation necessary. A dev run with no file at all stays *not judged*, so nothing local starts
failing over a stamp nobody was ever going to write.

**Verified by** `FlipWindowTest` for the three new answers, and `PgcatConfigFlipperTest` for a stamp
that exists, is readable, and is empty → the finding names `empty`.

## Step 3 — every surface that repeats the old claim

The same false sentence is printed by four surfaces, and the records that describe them are held to
the code by `ProseNumbersTest` and the doc guards, so the moves are not optional.

| Surface | Change |
|---|---|
| `WeightedDatabaseServiceProvider::KEY_PGCAT_BOOT_FILE_UNUSABLE` | the docblock says the stamp is what the window reads and cannot be written; it becomes: written once by the deployment, read here, and unreadable or empty is the fault |
| the finding's resolution text | *"the boot stamp is writable where swrr.pgcat.boot_file points"* → *"the boot stamp is readable where swrr.pgcat.boot_file points — the container stamps it once at start and never writes it again"* |
| `ScheduleCauses::bootStampReading()` | the reading names an empty setting today; it also names a file that is there and holds nothing, since that is the state a stopped window is diagnosed from |
| `DbDoctor`'s `pgcat files` and `write paths` rows | sentences only: the first already says "missing, unreadable or unwritable" of the files it reads, and gains "or holding no stamp"; the second stops listing the stamp |
| `docs/boot-audit-surfaces.md` | the sentence that counts how many of the fault list's paths *"are not pgcat's at all"* loses the stamp and keeps the audit lock, and the sentence about the paths this package must write is re-derived |
| `docs/deployment-contract.md` | the boot-stamp row states the contract: **written once at container start, by the deployment, and never rewritten; the package only reads it** |
| `README.md` | the `write paths` row drops the stamp, the `pgcat files` row says read, and the boot-window section says the stamp is a read-only input |
| `config/db-manager.php` | the comment above `boot_file` states the immutability — one write per container start, nothing in this package writes or rewrites it, and a container that cannot read it is the fault the preflight reports |

The two counting sentences are rendered by `bin/counts.php` rather than retyped, and the number comes
out of the table's own length.

**Verified by** `php bin/counts.php --check`, `ProseNumbersTest`, and the two guards over the records —
`DocCitationsTest` and `QuotedRecordsTest` — because the deployment contract's new sentence is a quote
of nothing and the findings' sentences are the code's, not another record's.

## Step 4 — the notes

One `### Fixed` entry: the stamp is judged by the question the package can answer — can it be read —
so a stamp its writer left unwritable and a stamp nothing wrote are told apart, and the live task's
`0644 root:root` stamp stops warning while an empty one starts failing. One `### Changed` entry: the
finding's published `state` becomes `missing`, `unreadable`, `empty` or `unparseable` instead of
`unwritable`, and `db:doctor`'s `write paths` no longer lists the stamp. The immutability contract is
stated in the `### Fixed` entry rather than its own heading, because it is the premise of the repair
rather than a feature.

**Weighing:** `fix` and `changed` are both a patch by the policy, and the public surface does not
move — no class, method, constant or config key is added or removed, only a published *value*. The
weighing pass is run before release, not as part of this change.

**Verified by** `php bin/release.php --weigh --dry-run --branch=dev`, which must report a bump no
louder than the notes call for and must not report a silent surface change.

## Step 5 — the order, and what each step is held to

| # | Step | Held to |
|---|---|---|
| 1 | the read-path table and the fault (Step 1) | `php bin/tool.php phpunit tests/Unit/Pgcat/PgcatConfigFlipperTest.php` |
| 2 | `stampProblem()` and the empty case (Step 2) | `php bin/tool.php phpunit tests/Unit/Pgcat/FlipWindowTest.php`, and the same flipper test |
| 3 | the surfaces and the sentences (Step 3) | `php bin/tool.php phpunit tests/Unit/Console/DbDoctorTest.php --filter 'pgcat files\|write paths'`, `tests/Unit/Pgcat/ScheduleCausesTest.php`, `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` and `tests/Unit/Support/BootAuditTest.php` for the finding |
| 4 | the records and their counts | `php bin/counts.php --check`, `php bin/tool.php phpunit --filter 'ProseNumbersTest\|DocCitationsTest\|QuotedRecordsTest'` |
| 5 | the notes and the whole gate | `php bin/checks.php`, then the live confirmation below |

**The live confirmation, on the task this plan came from:** `php artisan db:doctor --env=prod` must
stop reporting the stamp at `/tmp/container-booted-at` (it is `0644 root:root`, and readable), and a
run with `boot_file` pointed at the empty `/var/swrr/container-booted-at` must **fail**, naming
`empty`. That pair is the whole point of the change, in one command each.

## Risks, and the one that needs a decision

| Risk | What it looks like | What the plan does |
|---|---|---|
| a published `state` value changes | a dashboard matching `"state":"unwritable"` stops matching | the `### Changed` entry names it; the key and the `setting`/`path` fields are untouched |
| the writability signal is lost | the incident where a stamp directory stopped taking the write is no longer reported as such | with one write per start, that incident arrives as `missing` — the file was not written — which is the same fault named more directly, and the record's own `WARNING: … could not be written` line stays the entrypoint's |
| the empty check trips a dev environment | a hand-made empty file fails a local preflight | only a file that exists is judged; a checkout with no stamp at all stays `not judged` |
| the fault list is read on every boot | an extra `is_file`/`is_readable`/size check | three stat-level calls, no full read; the stamp is a few bytes when it is there |
| a second file appears “to be safe” | the deployment's stamp and a package-owned copy that drift apart | deliberately excluded below: one file, one writer, one clock |

## Deliberately not in this plan

- **A second stamp file, or a package-owned one.** Two stamps are two clocks, and the window has one
  meaning.
- **Changing `bootedAt()`, `closed()` or the published `source` vocabulary.** The repair is where the
  *fault* is reported, not what the window computes; moving `source` would move `/health/db`'s block
  for every reader of it.
- **Judging the deployment's write.** The package cannot know the writer's credentials, and with one
  write per start there is nothing to judge after the fact.
- **Moving the stamp to `/var/swrr`.** That remains the deployment's decision and an image change; this
  plan makes the package correct on either path, which is what it can own.
- **Renaming `SWRR_PGCAT_BOOT_FILE` or the finding key.** A published name is a contract; the wrong
  half of this one was the question, not the word.

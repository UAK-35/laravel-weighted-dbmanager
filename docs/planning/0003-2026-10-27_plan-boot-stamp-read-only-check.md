# Plan — validate the immutable boot stamp as a readable, non-empty input

Created: 2026-10-27. Updated: 2026-11-04 (Asia/Karachi).
Status: planned; implementation remains outstanding. Source re-read at `41f761c` on 2026-11-04.

This update incorporates the requirement that the configured boot file must exist and be non-empty,
closes the previous plan's contradictory treatment of missing files, and corrects its release/API
assumptions. It updates this existing plan rather than creating a duplicate.

## Required behavior

The server's startup process writes `swrr.pgcat.boot_file` once when the container starts. The
application reads that file to calculate the boot window. A stamp readable by the application is
valid even when the application cannot write it. The package must never create, truncate, rewrite,
chmod or chown the stamp, or use the current PHP process time as a replacement boot time.

The reported live warning names `/tmp/container-booted-at` with mode `0644`, owner `root:root`.
That mode ordinarily permits the application to read it, which is the permission the package needs.
Ownership or an absent write bit alone must produce no boot-file warning.

When pgcat flipping is armed and a non-empty boot-file path is configured, apply these checks in
order and report one reason for that path:

| Condition | Result | Warning state |
|---|---|---|
| Configured path is absent or is not a regular file | Warn that the startup stamp file is missing or the path is not a file | `missing` |
| File exists but the application cannot read it, including an actual failed read | Warn that the application cannot read the boot stamp | `unreadable` |
| Read succeeds but the contents are empty or only whitespace | Warn that no boot timestamp was recorded | `empty` |
| Non-empty contents contain no usable timestamp under the existing parser's rules | Warn that the contents are not a usable boot timestamp | `unparseable` |
| File is readable and contains a usable timestamp, regardless of writability | No standing boot-file warning; calculate the normal window | none |

Presence, readability and non-empty contents are mandatory for a configured path. Do not let an
absent file pass because its parent directory is writable, or let an empty file pass because
`is_readable()` is true. Read and trim the contents; file size alone does not reject whitespace or
prove that reading succeeded.

Keep the existing opt-out behavior when pgcat flipping is disabled or unsupported, or no boot-file
path is configured. An intentionally unconfigured path differs from a configured path whose file is
missing. A local run that configures a missing path will now warn, as required by the presence check.

## Why the warning is wrong today

`src/Pgcat/PgcatConfigFlipper.php` puts `boot_file` in `UNNAMED_WRITTEN_PATHS` beside the audit lock.
Its `fileProblems()` loop calls the write-permission helper and emits the quoted warning. The provider
turns that file problem into the standing boot-audit finding. `FlipWindow::bootedAt()` independently
reads the stamp, so a readable but unwritable file can already close the window correctly while the
warning falsely says it cannot.

The reverse case is also wrong: a writable empty file passes the write check, but the window has no
boot time to use. Earlier incident evidence is recorded in
`docs/investigations/0005-2026-10-27_inv-boot-stamp-vs-container-boot-stamp.md` and
`docs/investigations/0006-2026-10-27_inv-boot-stamp-unwritable-warning.md`; those are historical readings,
not a fresh inspection of the live server on the date of this update.

## Implementation steps

1. **Give the stamp one shared reader and classifier.** In `src/Pgcat/FlipWindow.php`, factor the
   actual file read and existing timestamp parsing into one internal reading used by `bootedAt()`
   and a diagnostic accessor such as `stampProblem()`. Classify missing/non-file, unreadable,
   empty/whitespace and unparseable separately. Handle `file_get_contents()` returning false even
   after a successful permission check; a race or ACL must become a diagnostic rather than an
   uncaught warning or exception. Preserve the existing positive numeric first-token parsing,
   including its trailing-newline/comment compatibility. Preserve the boot time, deadline,
   `closed()` arithmetic and `stamped`/`no_stamp` payload vocabulary. Do not cache or substitute a
   process start time. Verify with `tests/Unit/Pgcat/FlipWindowTest.php`.

2. **Remove the stamp from the package's write requirements.** In
   `src/Pgcat/PgcatConfigFlipper.php`, remove `boot_file` from `UNNAMED_WRITTEN_PATHS` and from
   `writePaths()`. Retain the write checks for the flip target, state, lock and audit files. Add the
   stamp to the file-problem/read-input handling and consume the shared classifier from step 1.
   Retain `boot_file => swrr.pgcat.boot_file` in `fileSettingPaths()` even though it no longer comes
   from the written-path table; provider context must still name the correct setting. Do not invent
   a general filesystem abstraction just for this input. Verify the missing, unreadable, empty and
   healthy cases, and the unchanged audit-lock checks, in
   `tests/Unit/Pgcat/PgcatConfigFlipperTest.php`.

3. **Keep the warning and correct every surface.** Preserve finding key
   `swrr.pgcat.boot_file.unusable`, its warning level, existing audit cadence, and the context fields
   `setting`, `path` and `state`. Update the provider's documentation and `fileResolution()` in
   `src/Providers/WeightedDatabaseServiceProvider.php`. The warning must name a read/input failure,
   and recovery must say a usable stamp can be read again. A previously recorded `unwritable`
   finding must resolve on a readable, non-empty valid stamp and stay resolved on subsequent boots.
   `src/Pgcat/ScheduleCauses.php` must report missing, unreadable, empty and invalid contents
   consistently, without presenting writability as a requirement or advising an application to
   re-stamp the file. Update affected sentences in `src/Console/Commands/DbDoctor.php` and verify the
   audit block exposed by `src/Http/Controllers/DatabaseHealthController.php`. Use existing
   diagnostic status/strict-mode behavior; no new endpoint status policy is part of this repair.

4. **Make remediation match the fault.** A read failure may suggest granting the application's
   actual runtime user read access. Never suggest `chmod +w` for the stamp. Missing and empty stamps
   require investigation of the server's startup writer or configured path; the application must
   not touch the file to manufacture a passing check. Keep mode and owner as diagnostic facts.

5. **Update the contract and generated records.** Update the `boot_file` comment in
   `config/db-manager.php`, the relevant README boot-window, `pgcat files` and `write paths` text,
   `docs/boot-audit-surfaces.md`, and `docs/deployment-contract.md`. Update
   `src/Support/DeploymentContract.php` together with the deployment-contract record if its
   statement changes. State that startup creates the stamp once and the runtime requires an
   existing, readable file with non-empty usable contents. Run `php bin/counts.php` to render owned
   counts, then `php bin/counts.php --check`. Add a `CHANGELOG.md` fix entry and describe the
   diagnostic state changes and missing/empty-file checks. Keep historical incident transcripts
   unchanged.

6. **Validate before release, then verify in the deployment.** Run the focused tests below, then
   the complete gate on a stable working tree. Only after implementation is complete, follow the
   release workflow and evaluate the bump from the actual change. A proposed public accessor or
   constant is an API addition: the earlier plan's claim that no public surface changes was wrong.
   Do not hand-edit the release inventory snapshots or assert that the bump must be patch-only.
   Plan creation itself does not publish or deploy the fix.

## Regression cases and acceptance criteria

| Case | Expected behavior |
|---|---|
| Existing valid stamp readable but unwritable to the application | No boot-file finding; deadline and closed-window behavior still work |
| Parent directory writable, stamp absent | `missing` warning; no file created |
| Path names a directory | `missing`/not-a-file diagnostic; no attempt to read it as a stamp |
| Existing stamp unreadable to the application | `unreadable` warning with the path and permission facts |
| Read fails after the preliminary access check | A read diagnostic, no crash and no fabricated timestamp |
| Zero-byte file | `empty` warning |
| File containing only spaces/newlines | `empty` warning |
| Non-empty invalid contents, zero or negative timestamp | `unparseable` warning under the existing positive-time rule |
| Valid timestamp with trailing newline or supported comment | Same boot time and deadline as before |
| Old `unwritable` finding followed by valid read-only input | Correct recovery message once; no recurring boot-file warning |
| Invalid input followed by valid input | Existing finding resolves without a package write to the stamp |
| Other mutable package paths become unwritable | Their existing warnings remain effective |
| Flipping disabled, unsupported driver, or no configured stamp path | Existing inactive/not-judged behavior |

For the valid read-only case, assert that bytes, modification time, owner and permissions remain
unchanged by repeated audits and polls, and that `writePaths()` excludes the stamp while
`fileSettingPaths()` retains its correct configuration name. Test expiry using an injected clock,
not an eight-minute sleep. Permission-sensitive tests must run as the application user on Linux;
`chmod` under root or Windows does not establish that the runtime user lacks access. Where the host
cannot express the permission case, skip it explicitly and retain the Linux acceptance check.

Focused implementation verification, from this package's root, using the PHP interpreter recorded
in `.agents/machine.local.json` in place of the `php` spelling below:

```bash
php bin/tool.php phpunit tests/Unit/Pgcat/FlipWindowTest.php
php bin/tool.php phpunit tests/Unit/Pgcat/PgcatConfigFlipperTest.php
php bin/tool.php phpunit tests/Unit/Pgcat/ScheduleCausesTest.php
php bin/tool.php phpunit tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php
php bin/tool.php phpunit tests/Unit/Console/DbDoctorTest.php
php bin/tool.php phpunit tests/Unit/Http/DatabaseHealthControllerTest.php
php bin/tool.php phpunit tests/Unit/Support/BootAuditTest.php
php bin/tool.php phpunit tests/Unit/Docs
php bin/counts.php --check
php bin/checks.php
```

The deployment acceptance check belongs to the application/server owner after installation of the
fixed package. Run `php artisan db:doctor --strict --env=prod` as the same user that runs the
scheduler. A readable, valid `0644 root:root` startup stamp must produce no boot-file problem and
must allow the window to close at the original startup timestamp plus its configured duration.
Other unrelated doctor failures can still make that command nonzero; inspect its boot-file row.

In an isolated test container or test configuration, repeat with a configured missing path, an
unreadable file, a zero-byte file, whitespace-only contents and invalid contents. Each must produce
the corresponding boot-file warning, with an unchanged file and no application-created replacement.
Do not remove or truncate the live container's stamp to run these negative checks. Observe repeated
scheduled boots after the healthy case to confirm the false write-permission warning has stopped.

## Compatibility and scope

Configured missing and empty files intentionally become observable faults, even when their
containing directory or file is writable. The finding name stays stable; consumers matching only
`state=unwritable` must accommodate the corrected states. Without a usable timestamp the window
continues its existing `no_stamp`/not-judged behavior; diagnostics now explain why instead of
silently accepting an empty file. Making that state fatal to container readiness would be a
separate policy change.

This plan changes the package's validation contract. It does not request permission changes on the
live file, a second boot stamp, a mutable package-owned copy, a path relocation, automatic
re-stamping, or a change to how the server writes the original startup timestamp.

## Implementation note (2026-11-04)

Status: implemented in the package source repository at `41f761c`; the full gate is green. This note
updates the plan in place rather than creating a second copy.

**Step 1 - one shared reader and classifier (`src/Pgcat/FlipWindow.php`).** `readStamp()` is now the
single read: `bootedAt()` returns its `booted` value, and `stampProblem()` returns
`{state, reason}` for `STAMP_MISSING`, `STAMP_UNREADABLE`, `STAMP_EMPTY` or `STAMP_UNPARSEABLE`.
`bootFile === ''` is the documented opt-out and is not a problem; a path that names a directory is
`missing`; `is_readable()` false *and* a `file_get_contents()` that returns false after it both read
as `unreadable`; empty/whitespace is `empty`; a non-numeric, zero or negative first token is
`unparseable`. The positive first-token parsing (trailing newline and comment compatible) is
unchanged, as are the boot time, deadline, `closed()`/`contains()` arithmetic, `stamped`/`no_stamp`,
and the no-caching/no-process-time rule. `toArray()` keeps its exact key set.

**Step 2 - the stamp left the write requirements (`src/Pgcat/PgcatConfigFlipper.php`).** `boot_file`
is gone from `UNNAMED_WRITTEN_PATHS` and therefore from `writePaths()`; the table now holds only
`audit_lock`, and `writtenPaths()` asks the record-writable condition unconditionally. `fileProblems()`
adds the stamp as a read input and consumes `FlipWindow::stampProblem()`, so the four states are the
finding's `kind`/`state`. `fileSettingPaths()` still returns `boot_file => swrr.pgcat.boot_file`
(explicitly, since the table no longer carries it) plus `audit_lock => swrr.audit.file.lock`. The
audit-lock write checks are unchanged. The stamp's repair line is `chmod +r` for `unreadable` and
none for the other three: there is no `chmod +w` and no instruction to create or re-stamp it.

**Step 3 - the warning and every surface.** Finding key `swrr.pgcat.boot_file.unusable`, its warning
level, cadence and `setting`/`path`/`state` context are preserved; `state` is now one of the four
read states rather than `unwritable`. `WeightedDatabaseServiceProvider`'s key docblock and
`fileResolution('boot_file')` say a usable stamp can be read again. `ScheduleCauses::bootStampReading()`
uses the same classifier, distinguishes the four states, reports a healthy stamp as readable with a
usable boot time (writability is not required), and never advises re-stamping. `db:doctor`'s `pgcat
files`/`write paths`/`schedule beat` sentences and the health payload (which reads the audit block)
were updated with the same reading.

**Step 4 - remediation matches the fault.** A read failure suggests granting the runtime user read
access; missing, empty and unparseable stamps suggest investigating the startup writer or the
configured path. Mode and owner stay diagnostic facts, and no surface proposes `chmod +w`.

**Step 5 - contract and records.** `config/db-manager.php`'s `boot_file` comment, the README boot
window and `pgcat files`/`write paths` text, `docs/boot-audit-surfaces.md` (the "not pgcat's" count is
now one, derived from the table), `docs/deployment-contract.md` (the state directory no longer lists
the stamp), `docs/prose-numbers.md` and the `CHANGELOG.md` `### Fixed` entry were updated.
`php bin/counts.php` rendered the owned counts; `--check` is clean. Historical investigation
transcripts were left unchanged.

**Step 6 - validation.** The focused suite passes, and so does the complete gate on a stable tree. The
new public surface (`FlipWindow::stampProblem()` and the four `STAMP_*` constants) is an API addition,
so the next release will refresh the inventory and `API.md`; the plan's earlier "patch only" claim was
wrong and is not repeated.

Measured on this checkout:

- `FlipWindowTest` + `PgcatConfigFlipperTest` + `ScheduleCausesTest` + `DbDoctorTest` - OK (436 tests,
  4,214 assertions, 3 skipped).
- `WeightedDatabaseServiceProviderTest` + `DatabaseHealthControllerTest` + `BootAuditTest` - OK (208
  tests, 1,247 assertions).
- `tests/Unit/Docs` - OK (164 tests, 1,248 assertions).
- `php bin/checks.php` - 13 checks passed, `Tests: 1722, Assertions: 11575, Skipped: 10`.

The regression matrix is covered as follows: a readable unwritable stamp has no finding and still
closes (`FlipWindowTest`); a writable directory with an absent stamp is `missing` and creates nothing
(`PgcatConfigFlipperTest`); a directory path is `missing`; an unreadable stamp is `unreadable` with
its facts (skipped where the platform ignores mode bits); empty, whitespace and invalid contents are
`empty`/`unparseable`; a valid trailing newline/comment keeps the same boot time and deadline; an old
`unwritable` finding resolves through the new provider resolution; the audit lock's write checks are
unchanged; and flipping off, an unsupported driver or an empty path keep the inactive behaviour.
`writePaths()` excludes the stamp while `fileSettingPaths()` retains its setting.

**Not done, and named rather than implied:** the live deployment acceptance is the application/
server owner's - `db:doctor --strict --env=prod` as the scheduler user against the real
`0644 root:root` stamp, and the negative cases in an isolated container. The permission-sensitive
tests skip visibly where `chmod` is inert (this Windows checkout) and remain for the Linux image. The
`file_get_contents()`-after-`is_readable()` race cannot be driven from a test, so it stays a guard;
a consumer matching only `state=unwritable` must now accept the four corrected states. No live file
was changed, and the package still writes nothing to the stamp.
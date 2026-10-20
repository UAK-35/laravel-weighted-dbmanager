# Plan — keep both pgcat scheduling mechanisms armed, and retire the both-armed finding

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task read | `030552d97a024b828fea6cf14a5d6da1` |
| Region / profile | `us-east-1` / `lpr` |
| Log group | `/ecs/lpr-backend-sites-tdef-01` |
| Code folder | `/app/site0/html` |
| Config read | `/app/site0/html/config/db-manager.php`, `/etc/pgcat/*.toml` |
| Record this plans against | `docs/investigations/investigation-pgcat-both-mechanisms-armed-warning.md` |

## What this plan is for

The `swrr.pgcat.schedule.both_armed` boot finding says one mode has two owners, and the record it
is written in recommends silencing it by configuration — disarm the per-minute entry
(`SWRR_PGCAT_SCHEDULE_ENABLED=false`) and keep the reader-window tasks on. This plan checks that
premise before acting on it, finds that the two mechanisms are **not** interchangeable and that the
recommended disarm costs more than the log lines it removes, and lays out the code changes that stop
the finding while **both** switches stay on.

The finding as the boot audit writes it:

```
[WeightedDB] two mechanisms own the pooler's mode: swrr.pgcat.schedule.enabled and
swrr.pgcat.schedule.windows.enabled are both on. They read the same resolver, so no
boundary is contended — this entry stops at the end of the boot window (480s after the
container starts) — but they do not share a record of what they did: a mode the window
tasks apply goes through the flipper's forced path, which is not written as a boot
convergence, so the readings that describe the entry's convergence describe a mechanism
that has stopped acting. Arm one and disarm the other in the same change: this is a
warning rather than a failure because an installation may be halfway through that.
```

## Step 0 — the two mechanisms, side by side

The question asked was whether they do the same job. They do not: one owns the container's first
few minutes, the other owns the day.

| | `db:pgcat-flip` — the per-minute entry | `db:pgcat-window-flip` — the window tasks |
|---|---|---|
| Class | `src/Pgcat/FlipSchedule.php` | `src/Pgcat/WindowFlipSchedule.php` |
| Job | *converging at boot*: a container that came up on a stale or wrong pgcat file is repaired | *following the day*: readers in as each window opens, out as it closes |
| May act | every minute, **bounded by the boot stamp** (`swrr.pgcat.flip_window_seconds`, 480 s) | every minute **inside a lead/grace range** around each boundary; no boot window |
| Path | `PgcatConfigFlipper::applyCurrentState()` | `PgcatConfigFlipper::forceMode()` — the forced path |
| Record | `runs`, `last_kind`, `converged_at` — the evidence a deployment gate reads | deliberately no run, so a 10:00 application cannot move the boot verdict |
| Registered | by default, following `swrr.pgcat.enabled` | off by default; the config says to disarm the entry in the same change |

The package already says the two are not substitutes, in `WindowFlipSchedule`'s own docblock: the
per-minute entry's bound

> is the right bound for *converging at boot* and the wrong one for *following the day*: a container
> booted at 03:00 converges to writer-only and then has no way to move to readers when the 10:00
> window opens, because nothing is allowed to change its mind any more.

That is a description of two jobs, not of two implementations of one. So the answer to "different
functionality, different aspects" is **yes** — and the consequence is that the disarm the record
recommends does not choose between two owners of one job. It removes one of the two jobs.

## Step 1 — what the disarm actually costs

Three things, and the third is the one the record does not name.

1. **Boot repair stops.** A container booted at 03:00 with the wrong file stays wrong until 09:52 —
   that boundary's lead window — because `applyCurrentState()` is the only path that acts outside a
   boundary.
2. **The failure sentence becomes permanent and un-actionable.** `windowStatus()` (in
   `src/Pgcat/PgcatConfigFlipper.php`) reports `runs === 0` as
   `the flip never ran inside this container's window: db:pgcat-flip is not being scheduled, or it
   cannot reach the flipper`. With the entry disarmed, that is true by configuration rather than a
   fault, so the surface keeps stating a repair nobody can make.
3. **`/health/db` reports `degraded` for ever.** `converged_at` is written only by `recordRun()`,
   which only `applyCurrentState()` calls. `windowStatus()` computes
   `converged = convergedAt !== null && $window->contains($convergedAt)` and
   `failed = $closed && !$converged`; `DatabaseHealthController` folds `!$flip['failed']` into
   `$allHealthy` and publishes `ok` only when it holds. So eight minutes after every boot the
   endpoint reports `degraded`, with a reason that is true and unreachable.

Note the asymmetry that shows this is an oversight rather than a decision: `ScheduleBeat::verdict()`
**does** carve the disarmed entry out (`stale: null`, with the sentence *the per-minute entry is not
scheduled … so nothing here is judged*), and `windowStatus()` has no such carve-out. So the disarm
trades ~250 log lines a minute for a red health endpoint and a lost repair.

**Decision: do not disarm.** The finding is not a configuration problem.

## Step 2 — the plan

The finding's sentence claims the two *do not share a record of what they did*. That is true of
`runs`, `last_kind` and `converged_at`, and **already false** of `last_mode`, `previous_mode`,
`last_flipped_at` and `flipped_by` — because `writeLastMode()` is called from both
`applyCurrentState()` and `forceMode()`. The shared record exists; it does not name the mechanism,
and the surfaces read the other half of it. Four changes, in the order they should land.

### 2.1 Name the mechanism in the record both paths already write

| | |
|---|---|
| File | `src/Pgcat/PgcatConfigFlipper.php` |
| Change | add a key beside `flipped_by` — `flipped_via` — carrying `schedule`, `window` or `manual`, written by `writeLastMode()`. Leave `flipped_by` alone: it is a published field, quoted in another record's JSON example. `forceMode()` takes the value as a parameter, because the call sites know it and the flipper does not. |
| Call sites | `applyCurrentState()` passes `schedule`; `DbWindowFlipCommand` passes `window`; `DbFlipPgcatCommand --force-mode` passes `manual`. |
| Verified by | the state file's JSON after a scheduled run, a window run and a manual one — three values, one per mechanism; and `PgcatConfigFlipperTest`, which already asserts the state a run leaves. |

### 2.2 Read the shared half where the mode is reported

| | |
|---|---|
| Files | `src/Pgcat/PgcatConfigFlipper.php` (`windowStatus()`), `src/Http/Controllers/DatabaseHealthController.php` (`flipWindowVerdict()`) |
| Change | report *what the pooler is in* from `last_mode` + `last_flipped_at` + `flipped_via`, and keep `runs` and `converged_at` as the **boot-window verdict only**. The rule that a 10:00 application must not move the convergence verdict is preserved untouched — the two readings are reported side by side rather than merged. |
| Why this also fixes the trap | a container whose pooler is in step stops being a failure because one particular mechanism moved it, so the disarm in Step 1 is no longer a health-check outage even if somebody does it. |
| Verified by | `DatabaseHealthControllerTest` / `DbDoctorTest` for a container whose `converged_at` is absent and whose `last_flipped_at` names a window run inside its boot window; the verdict must be `ok`, and the reason it is not `failed` must name the mechanism. |

### 2.3 Retire the finding

| | |
|---|---|
| Files | `src/Pgcat/FlipSchedule.php` (`bothArmedSentence()`), `src/Providers/WeightedDatabaseServiceProvider.php` (`KEY_FLIP_MECHANISMS_CONTENDED`, `flipMechanismFindings()`), `src/Console/Commands/DbDoctor.php` (the `flip schedule` warning) |
| Change | with the record shared, the sentence has no claim left to make, so the finding is removed rather than suppressed: the key, its producer, its two call sites and the doctor warning go. Both switches stay on, and nothing is hidden — the `flip schedule` row still names which mechanism answers a boundary, and `--strict` stops failing a supported arrangement. |
| Follows | `README.md`'s `flip schedule` row, `docs/boot-audit-surfaces.md`'s key list, and the **bound count**: *pgcat is one of twenty-five keys* is derived from the provider's `KEY_*` constants, so deleting one makes it twenty-four — `bin/counts.php` renders it and `ProseNumbersTest` verifies it. |
| Verified by | `FlipScheduleTest::test_both_mechanisms_armed_are_reported_by_both_keys`, `DbDoctorTest::test_the_flip_schedule_row_warns_when_both_mechanisms_are_armed` and `WeightedDatabaseServiceProviderTest`'s key list — all three move. |

### 2.4 Bound the re-logging of a standing finding

This is the change that actually answers *no per-request findings*, and it is independent of 2.1–2.3.

| | |
|---|---|
| File | `src/Support/BootAudit.php` |
| Change | `fold()` logs every finding on every boot by design, and under PHP-FPM a boot is a request. The package already solved exactly this for the one finding that needed the network — `swrr.audit.store_probe_seconds` plus `store_probed_at` in the record. Generalise it: a `last_logged_at` per finding key in the same record and an interval (`swrr.audit.finding_seconds`), so a standing finding costs one line per interval per installation however many requests arrive. The **first sighting** and the single `resolved: true` line stay as they are, so the alerting behaviour the README documents holds. |
| Why a package change rather than config | there is no audit switch today — `swrr.audit` declares `file` and `store_probe_seconds` only — so the flood has no configuration remedy at all. |
| Verified by | `BootAuditTest`: two boots inside the interval produce one line; a boot after the interval produces a second; first sighting and resolution still produce exactly one line each; `store_probe_seconds` keeps its own independent bound. |

## Step 3 — what has to move with the plan

- `FlipScheduleTest`, `DbDoctorTest`, `WeightedDatabaseServiceProviderTest`, `BootAuditTest`, and
  whichever health-endpoint test covers `flipWindowVerdict()`.
- `README.md`'s `flip schedule` row and the two prose sentences that state the pair.
- `docs/boot-audit-surfaces.md`'s key list, and `docs/prose-numbers.md`'s bound count if the derived
  number is stated there.
- `CHANGELOG.md`: `### Changed` for the reading, `### Fixed` for the endpoint that can no longer be
  permanently `degraded`, `### Removed` for the retired key.
- The record this plans against is left as it stands — it is the evidence that the disarm was
  recommended, and a later reader needs to see why it was not taken.

## Step 4 — deliberately not in this plan

- **Disarming either mechanism.** Step 1 is the reason.
- **Narrowing the finding's condition** (fire only when a boundary falls inside the boot window, or
  only when the tasks are armed but schedule no boundary). Cheap, and rejected: the sentence is
  *true* whenever both are armed, and the package's own design says a true finding must not be
  silenced. If a condition is to change it is by deleting the claim, not by hiding it.
- **Collapsing to one mechanism.** It is the other way to satisfy *one owner*, and it is the
  opposite migration to the one the record recommends: keep the per-minute entry and drop the tasks.
  It only works if `applyCurrentState()`'s boot bound is relaxed for transitions, leaving the verdict
  window-bounded — otherwise the single remaining owner still cannot follow the day. Worth its own
  plan if the package decides one owner is a requirement rather than an observation.
- **Touching the resolver.** Both mechanisms read the same resolver, nothing contends a boundary, and
  no change here alters which mode the application follows.

## Step 5 — order, and what each step is verified by

1. 2.4 (self-contained, and it removes the flood on its own) — `BootAuditTest`, then a gate run.
2. 2.1 and 2.2 together (the record and the reading that consumes it) — the flipper and health tests.
3. 2.3 last, with the docs and the rendered count — `bin/counts.php --check`, `ProseNumbersTest`,
   and the three named tests.

Landing 2.4 alone is a complete, releasable change: it needs no decision about which mechanism owns
the mode, and it is the change the operator asked for.

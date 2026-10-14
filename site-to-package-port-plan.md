# The site-to-package port plan

What to port into this package from the consuming application's database-facing material, in
phases, and nothing else. The evidence behind every phase — the files, the commits, the production
failures and the readings they came from, reconciled — is in
[all-externally-related.md](all-externally-related.md); this file says only what to do, in what
order, and how to know it is done.

Each phase names the material it comes **from**, the **work**, what is **done** when it lands, and
the **check** that proves it. Relations are A (calls this package), B (configures or pins it), C
(evidence about it), D (does its job at the app layer), E (its environment).

Ordering: phases 1–4 are this package's own configuration surface — each is a reading it already
half-takes, made whole. Phase 5 is what makes those readings usable where the application gates its
images, and is what retires a duplicate command. Phases 6–8 are the runtime questions the read path
and the pooler raise. Phase 9 writes the deployment contract down. Phase 10 is the other
repository's list and needs no edit here. The three small items are each one commit and can land
whenever a phase beside them is open.

---

## Phase 1 — the published config can say how old it is

*From (B):* `site/config/db-manager.php` at `v0.2.0-alpha3` (`89a76bc`, `cc25625`), and the
thirteen-keyed-line diff against this package's sample — the `(bool)` casts in front of two switches,
`swrr.connection`'s fallback chain ending at the literal name `sqlite`, and the absent
`stale_after_missed_runs`.

**Work.** A schema generation in the sample config — one number, owned by the class that reads it —
and a `db:doctor` row that compares the published file's value with the package's: equal is silent,
older is a warning naming the release it was published at, and absent is a warning that says the file
predates the key. A warning and not a failure, deliberately: running an older published config is not
a fault, it is a fact a preflight should state. Then a short record under `docs/` on what a publisher
must not do to the keys this package reads as switches — the casts and the connection fallback chain
are the worked example, and they are the application's lines to remove.

**Done when.** A fresh install that has never published is silent; a config published from an older
release is reported by name; this package's own sample reads as current; the row appears in
`db:doctor --json`; and the record is registered like every other.

**Check.** `php bin/tool.php phpunit tests/Unit/Console/DbDoctorTest.php` plus the JSON fixture the
doctor's key set is pinned by; `php bin/counts.php --check`; `tests/Unit/Docs`.

---

## Phase 2 — one preflight for everything this package must write

*From (C, E):* `300d676` — a production container that passed every other gate and could not flip,
logging that its flip target and its audit record were not writable.

**Work.** One reading — a `db:doctor` row and a single boot-audit finding — listing every path this
package must be able to write while the flipper is armed: `config_path`, `readers_path`,
`no_readers_path`, `state_file`, `lock_file`, `boot_file`, `audit.file` and the record's `.lock`
companion, each with the mode and owner found, and each with the sentence that says what an
unwritable one costs (a flip that cannot get its copy into place; a probe skipped because its
throttle cannot be written). Aggregate rather than add another thing that reports only when it fails,
and reuse `PgcatConfigFlipper`'s existing unwritable constants so a path judged unwritable there is
judged unwritable here.

**Done when.** A fixture tree with a read-only variant reports each path, the mode found and what it
costs, in one row; a writable tree is silent; the row is in `db:doctor --json`.

**Check.** The doctor and flipper suites; `php bin/checks.php --only=counts,sentences,pint,phpstan`.

---

## Phase 3 — a supervisor failure names its cause, not a timeout

*From (C, E):* the same deploy — `supervisorctl status "pgcat:*" did not answer in time: The Process
class relies on proc_open, which is not available on your PHP installation`, reported as
`rename refused never reached` — and `23061be`, the image change that put `proc_open` in
`disable_functions` in the first place.

**Work.** Distinguish three states where there is one message today: the process cannot be spawned at
all because `proc_open` is disabled (report the value and the ini line to change), the binary is not
on `PATH`, and a genuine timeout. Surface the same reading in `db:doctor`, so an image build catches
it before a deploy's rehearsal does — the container with this defect passes every other gate, which
is what made it a rollback rather than a failed build.

**Done when.** The supervisor step's failure sentence says which of the three it is, and the doctor
reports the same without running a flip.

**Check.** The supervisor and flip suites, plus the doctor suite with one new test arming a
disabled-function stub.

---

## Phase 4 — the one-mechanism invariant, and the two defaults the published copy exposes

*From (B):* `8bd2f9d` — the schedule and the window tasks both armed by default in the published
copy, against this package's own comment saying to arm one and disarm the other, and the three
contradictions that commit names between the file's comments and its code.

**Work.** An audit finding and a doctor row for two mechanisms owning one mode: name both keys, say
what they share (the boot convergence record) and keep it a warning, because an installation may be
mid-migration between them. While that file is open, settle the two things the copy exposes: whether
`schedule.enabled` still inherits from `pgcat.enabled` as its comment promises, and whether an
unparseable `interval_minutes` resolving to 1 while the default is 2 is intended — the comments
promise one behaviour and the code has another.

**Done when.** An installation with both armed is reported by name; a clean one is silent; and the
config's comments and its code agree on the cadence and the inheritance.

**Check.** Doctor, audit and configuration suites.

---

## Phase 5 — `db:doctor` gets a mode a build container can run

*From (D):* `site/app/Console/Commands/DbConnectionDiagnosticsCommand.php` — "`db:doctor` … also
probes the store and asks supervisorctl, so it cannot run everywhere an image is built" — its tests,
the two image gates that already run it (`smoke-test-image.sh`, `validate-image.sh`), the deploy's
pooler gate that runs it with `--real-test`, and the configuration bootstrapper that makes a cached
config sticky.

**Work.** A bounded mode — `--connection-only`: the same command with the probes that need a store, a
supervisor or a socket left out — plus the two facts this package's doctor does not print: **the
environment file this boot read** and **whether the configuration is cached**. Publish
`swrr.connection` beside the setting it came from, so *unset* and *set to the default* stay
distinguishable. The exit contract follows the application's, which is already this package's: 1 for
the states the armed configuration cannot work in, 0 otherwise, with `--strict` failing warnings.

**Done when.** `db:doctor --connection-only --json` runs with no store, no supervisor and no
reachable database, and exits 1 for a pgcat switch armed over a `sqlite` connection; the application's
own probe command can then be retired, which is the point of the phase.

**Check.** The doctor suite, plus one new test that runs the mode with the store unarmed and the
supervisor absent.

---

## Phase 6 — the schedule's silences get a cause, not just a verdict

*From (A, E):* `lpr-schedules` (the `APP_ENV` trap that once aborted `schedule:run` before the flip
ran), `site/app/Helpers/SchedulingHelpers.php` (a second minutely registration, under a schedule that
uses a file store for its mutex), and the pending watchdog work that reports the entry stopped.

**Work.** Add the causes the verdict cannot see, each reporting what it read rather than pass or
fail: the schedule's mutex store is reachable; exactly one entry carries `swrr.pgcat.schedule.name`
(the package's own registration beside the application's); and the boot-stamp file is readable and
writable by the user the schedule runs as — the `/var/swrr` ownership contract.

**Done when.** All three are silent on an installation that never armed the schedule, and each names
its reading on one that has.

**Check.** The watchdog suite plus `DbDoctorTest`.

---

## Phase 7 — the read path: what a routed read can expect

*From (C, D, E):* `docs/replica-conflict-errors.md`, both parameter groups (writer 120 000 ms,
replica 90 000 ms, `hot_standby_feedback` off until 2026-10-13), the ~422 failures an hour that
stopped when it was turned on, `site/config/database-retry.php` with
`site/app/Database/ConcurrencyReadRetry.php`, `HeavyReadRouting`'s `useWritePdo()` invariant, and
`install-log-alarm.sh`'s two filter names.

**Work.** Three doctor rows and one record. The rows: each read endpoint's own `statement_timeout`,
with unequal caps reported as a fact ("a read cut here can be answered by the write endpoint, whose
cap is the longer one") and a cap far below the writer's as a warning; whether a standby has
`hot_standby_feedback` on; and whether the *pinned* connection has a `read`/`write` pair at all, since
`useWritePdo()` is honoured only there. The record: the two SQLSTATEs and the sentence each carries,
why re-running is the conflict's answer while the server's cap is not, the application's contract as
it stands (`DB_READ_RETRY_ENABLED`, three attempts, 25 ms base, `fallback_to_primary`, never inside a
transaction), where this package stops — it chooses the endpoint, it does not re-run a statement the
application may have wrapped in a transaction — and the filter names an installation can install for
itself.

**Done when.** The rows report silence, not failure, where an endpoint is unreachable, and the record
is registered in the counts and sentences records.

**Check.** The doctor suite; `tests/Unit/Docs`; `php bin/counts.php --check`.

---

## Phase 8 — the variants a flip swaps between, and the pools the connection names

*From (A, B, E):* `entrypoint.sh`'s rendering (two variants from one template) and its prune of
unresolvable pools from all three, `pgcat-boot-hardening.md` §3.1's statement that one pair must be
identical and another must not, the F-05 defect and the 2026-10-09 disagreement between the pooler
configuration and the connection, `pool-prune-selftest.sh`'s fixture discipline, and
`scripts/php/verify-db-hosts.php`'s stale hand-run assertion.

**Work.** Extend what `db:pgcat-flip --dry-run` already rehearses, and give `db:doctor` the same
reading, to the *shape* of what is being swapped to: each configured path exists and parses; the
writer-only variant declares no reader pools; the configuration in place declares the pools the
pinned connection names; and any pool pruned at boot is reported rather than merely absent — because
a flip to a variant declaring a pruned pool re-introduces the failure the prune exists for. Copy the
selftest's discipline: a stub with a strict allowlist, and a case for the no-op that looks like
success.

**Done when.** A rehearsal against a fixture whose writer-only variant carries a reader pool fails
with a sentence naming the path and the pool; a tree whose configuration lacks a pool the connection
names fails the same way; and a consistent tree passes.

**Check.** The dry-run suite (`DbFlipPgcatDryRunTest`) and the flipper suite.

---

## Phase 9 — one record for the deployment, under `docs/`

*From (A, B, E):* `pgcat-boot-hardening.md`, `pgcat-issues.md`, `deploy-troubleshooting.md`,
`pgcat-Dockerfile`, `deploy-ecs.sh`, `pooler-healthcheck.sh`, the `pgcat-health-gate.sh` /
`flip-rehearsal.sh` pair, `entrypoint.sh`'s provisioning, and the `lpr-deploy` skill's gate table.

**Work.** Write down what a deployment of this package has to have: the three rendered variants and
the prune-before-start rule; the pooler's pinned and compiled version, since a pgcat upgrade is a
change to this package's runtime; the boot stamp and where it comes from; the per-minute entry, its
window and its `flock`; a writable state directory; a spawnable supervisor; the container health
check that asks supervisor and the port rather than a cached route; the two deploy tests that read
this package, with their exit codes and what a missing command in the image means; the store the
weighted state lives in and what its absence means; that the database's read caps and the write cap
need not match — which is what makes an escalation to the writer possible; and that the health
endpoint is a machine's surface rather than the container's own. Cite the application's records as
the field evidence they are, by name, and cite nothing that carries a machine's own path.

**Done when.** The record is in `docs/`, registered in the counts and sentences records, and its
citations resolve — the bar every other record here meets.

**Check.** `tests/Unit/Docs` in full, plus `php bin/counts.php --check`.

---

## Phase 10 — the application-side list, for the other repository's session

No package edit; written here so the two halves stay comparable. Republish `config/db-manager.php`:
drop the two `(bool)` casts, keep exactly one of the schedule and the window tasks armed, set the
pool probe's port to the pooler's own, and take up `stale_after_missed_runs` when this package
releases it. Decide whether the application's own diagnostics command survives phase 5. Repair or
retire the two hand-run checks that still name retired pools. Leave the read retry where it is. And
one that is genuinely theirs to guard: the application's `docs/` cite this package's files by line
number, so a package edit silently makes those citations wrong.

---

## Small items, one commit each

* **The committed machine-record sample.** The application commits its sample in token form
  (`__PHP_EXE__`, `__PHP_DIR__`, `__PWSH_EXE__`, `__PACKAGE_DIR__`, `__COMPOSER_PHAR__`, `__RG_EXE__`,
  `__GIT_SIGNING_KEY__`) after its own guard found six real paths in it, and this package keeps
  `.agents/machine.local.json` ignored with no committed sample at all — so a fresh clone has nothing
  to copy from. Commit `.agents/sample.machine.local.json` in token form and have the machine-path
  guard's excused shapes cover it explicitly.
* **A row naming the connector in use.** The application replaces the framework's PostgreSQL
  connector, and every connection this package builds goes through a connector class; a doctor row
  that prints which one was resolved makes that visible without reading either repository's code.
* **A path with a space.** The application's own allowlist could never excuse a file whose name
  contains a space, because git terminates a `+++` header path with a tab that was kept as part of the
  key. Run the same question against this package's readers of paths in prose and add a test if the
  terminator is not stripped.

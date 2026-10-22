# Release Notes

## Unreleased

## 0.2.0-alpha10 - 2026-10-22

### Added

- **`db:doctor` asks whether the boot audit's record has actually been written, not only whether it
  could be.** `store probe` reads the interval, the record's mode and the store, and every one of
  those can be right while nothing writes the record at all — the schedule stops, or the runs write a
  state directory this installation does not read — and the silence is total, because the findings
  standing in the record are read out of that same file and `/health/db` reports what its own boot
  found. `store_probed_at` is the one field a healthy installation moves on its own, so the new
  `probe stamp` row reads its age and fails past five missed cadences: five minutes at the shipped
  probe interval and one-minute entry, five hours on an installation that probes hourly, where a
  cadence is the longer of `swrr.audit.store_probe_seconds` and
  `swrr.pgcat.schedule.interval_minutes` — the same five misses `stale_after_missed_runs` ships for
  the beat, so "late" means one thing in both rows. The age is the one the record held *before this
  run's own boot wrote anything*, which is what `BootAudit::storeProbeStamp()` keeps: a boot that
  finds the probe due sends it and stamps the record, and `db:doctor` runs after that boot, so
  judging the file as it stands would report every installation as fresh and would clear the fault
  the row exists to report. A process that never reported reads the file, because it wrote nothing.
  The states that remove the expectation are named rather than judged — probing switched off, a
  record that cannot be written, an in-process store, a flip schedule that is not armed, and a record
  holding no stamp at all, which is a fresh installation rather than a stale one — and none of them
  is dated: the state the row reports is the *absence* of the writes that would carry a date. The
  doctor's table gains one row, and the README, `docs/db-doctor-json.md`,
  `docs/documented-exit-codes.md` and `docs/prose-numbers.md` name the new count.

### Fixed

- **A finding whose own record is the file that cannot be written is carried beside it instead of
  losing its date.** `swrr.audit.file.unwritable` is the one finding in this package whose own record
  can be the file that cannot be written: a read-only record is repaired before the boot decides
  anything, so on the installations where that works the finding is not produced at all, and the
  state that remains is a file this account may not change — another owner, an ACL — or a directory
  that takes no file. The record is two things at once, the installation's memory of what stands and
  the stamp that throttles the probe, and only the second is what a boot without a writable file
  trades away. So a boot that cannot write the record now writes the record it *would* have written
  beside it, as `{file}.carry.json`, and a later read takes the carry in place of the record while
  the carry is the newer of the two: the key keeps its date, the row's `recorded unresolved since`
  clause has something to print, and the boot that finds the setting clean logs the resolution the
  carry made possible — a repair in between that the record alone could never have closed. The carry
  is retired by the first boot that writes the record, and it is the record's own name plus
  `.carry.json`, so a record moved to another path takes its carry with it. A directory that takes no
  file still beats it, and that is the one state where the log line is the whole of what an operator
  gets. `bin/checks.php` declares the carry's write as a structural write-back: the copy it replaces
  is the previous boot's carry and is older by construction, which is the one place in this package
  where dropping the file being replaced is intended.

## 0.2.0-alpha9 - 2026-10-22

### Added

- **The port plan's status table is held to the tree.** The section that says which commit each phase
  landed as is the one reading in the plan about this repository rather than about work to do, and
  nothing read it: seven characters of prose stop naming anything the moment history is rewritten, and
  no diff of the plan shows it because the text did not change. `PortPlanStatusTest` reads the table
  through a new `Tests\Support\PortPlan` — which raises rather than returning an empty reading when
  the heading, a column or a row's shape is not what it expects — and asserts it in three directions:
  every `## Phase N` heading has a row and every numbered row names a phase that still exists; every
  commit in the `Landed in` column, and the one the section's heading is taken at, resolves here; and
  each of them is an ancestor of `HEAD`, since a commit on a branch nobody merged has not landed
  anywhere the table can point at. The plan names the guard beside the table, so the next thread that
  re-takes the section knows what will fail if it edits the table without the tree.

- **A connection the package follows and cannot build is a boot finding, not only a doctor row.**
  `db:doctor`'s `connector` row fails when the followed connection names no driver, names one no
  connector is registered for, or is built by a connector whose connection build throws — and it
  answers only when somebody runs it. The boot now asks the same reading,
  `ConnectorInUse::resolve()`, and records `swrr.connector.unusable` with the framework's own
  sentence and the cause (`no_connector` or `build_failed`) in its context, so the first boot after
  a bad deploy says what the first failing query does not: which driver, or which class, could not
  build the connection — and the record dates how long it has been that way. It is a warning rather
  than an error, because `error` is the level for a value the package refuses to interpret and this
  is a failure to build rather than a value it substitutes for. A replaced connector that works is
  no finding at all: binding `db.connector.<driver>` is the framework's extension point and
  `db:doctor` passes it too, so the audit does not warn on the installations doing the right thing.
  A `db.factory` that cannot be asked stays `weighted factory`'s to report, so the finding is silent
  there rather than counting one cause twice — and the key is left out of the checked set on those
  boots, so a finding an earlier boot recorded is carried over rather than cleared. The reading
  costs one connector and one connection object per boot and connects to nothing, the same price the
  row pays for the same answer.

- **`/health/db` publishes `connector` — the class that builds the followed connection's PDO.**
  `db:doctor`'s `connector` row answers whether an installation bound `db.connector.<driver>` or
  registered `Connection::resolverFor()`, and neither is written in `config/database.php`, so the
  answer lived in a preflight run from a terminal — which is not where an installation is watched.
  The endpoint now carries the same reading, `ConnectorInUse::resolve()`, the call that row makes,
  so the two surfaces cannot name different classes for one installation. Every key is present and
  `null` in the states it does not describe, so a monitor compares `binding` and `resolver` with
  `null` rather than interpreting a class name, and `connection_class` beside `resolver_applied` is
  the one state no configuration shows: a resolver registered after the connection in use was built.
  A replacement never moves `status` — it is the framework's extension point and `db:doctor` passes
  it too — and a connector that cannot be built is already a `pinned` failure. The reading
  constructs the connection object and resolves no PDO closure, so it opens no socket.

- **Three records of application-side work are added, and the plan that came out of one of them.**
  `docs/investigations/investigation-ecs-rev31-rollback.md` follows the two `lpr-backend-sites`
  tasks that stopped minutes after they started to the revision they were rolled back from — the
  log-path permission fatal and the unwritable pooler state directory their boots produced, and the
  drain arithmetic that makes each rollout slow. `docs/issues/issue-pgcat-hanging-query-wire-capture.md`
  carries the `python3` proxy that decodes a hanging query's wire traffic in both directions, and the
  `SHOW POOLS` reading that tells a pooler-side wait from a server-side one.
  `docs/issues/issue-supervisord-socket-unreachable.md` records the control socket every flip refused
  on, the `FAULT_UNREACHABLE` fault that names it, and the `chmod=0760` with `chown=root:www-data`
  repair. `docs/planning/plan-pgcat-both-mechanisms-armed.md` is the plan for keeping both pgcat
  scheduling mechanisms armed while the `swrr.pgcat.schedule.both_armed` finding is retired: why the
  per-minute entry and the reader-window tasks are not substitutes, what disarming the entry costs
  `/health/db`, and the four code changes with what verifies each. Nothing under `src/` or `config/`
  moves.

- **A third recording skill, `wdm-plan`, and the two that came before it answer for WDM rather than
  LPR.** `wdm-plan` activates on a `/wdm-plan` marker, or asks *"Is this an WDM plan?"* for a prompt
  containing "plan a", "plan the" or "need a plan", then writes the response into `docs/planning/`
  — the folder the both-armed plan above landed in. `wdm-issue` and `wdm-investigation` asked *"Is
  this an LPR issue?"*, naming the application repository in a package anybody installs, and now
  ask about a WDM issue or investigation. Both also say what a record costs the handoff note: a line
  in `Handoff::PENDING_DESCRIPTIONS`, `--reaffirm=<path>` to record the reading those words were
  written against, and `--recorded` to re-render the note's pending block. Nothing under `src/` or
  `config/` moves, and none of the three skills ships in the tarball.

- **The prose-number guard has a completeness half: every number a record marks is either read by a
  claim or declared prose.** `ProseNumbersTest` could hold a number a claim names to the code, and
  could not say that a number in a record is named by no claim at all — which is how two bold
  headings and a `ways` count stayed prose while the sentence under them was rendered.
  `ProseNumberAuditTest` reads the mark instead: a record bolds a number when it is stating a count,
  so every marked number a claim does not capture has to be a row of
  `docs/prose-numbers.md`'s prose table, and every row has to still match a mark and give one of the
  categories that record declares. Two directions fail, which is what makes the table a boundary
  rather than a list of excuses. The audit also found a claim the records had been stating all along
  — `switch-values.md`'s "Only **three** settings are covered" counts the provider's `SWITCHES`
  table — so that sentence is bound in `ProseNumbersTest` rather than excused.

- **A guard holds every sentence one record quotes from another to the record it names.**
  `QuotedRecordsTest` reads every `"…"` span beside a backticked `.md` path across the four root
  records and every `.md` under `docs/` — `CHANGELOG.md` is out, because a changelog quotes the
  sentence a version published rather than a record on disk. An ellipsis means "the rest of the
  sentence" and the fragments have to appear in that order, emphasis and quote marks are dropped
  from both sides, and a quote shorter than a dozen characters is not read at all. A paraphrase is
  therefore not a quote: the reading is exact words, which is the reading a citation makes.

- **The handoff note's prose column is a reading, and a stale one is refused.** Section 1's pending
  table is the one part of a rendered block git cannot answer, so its words are declared in
  `Handoff::PENDING_DESCRIPTIONS` — and each line now carries the digest of the file it was written
  against. A pending file that changed since leaves the table refusing that row by name rather than
  printing a sentence nobody re-read, and `php bin/handoff.php --reaffirm` (or `--reaffirm=<path>` for
  one row) is the repair: it keeps the words, takes the reading the file has now, and rewrites the
  declaration block, so a re-reading is something somebody ran rather than a hash that drifted.

- **`/health/db`'s `schedule` block carries the two settings its tolerance is made of.**
  `db:scheduler-watchdog` has always printed `missed_runs` and `interval_minutes` beside its verdict,
  and the endpoint published only the product — `stale_after_seconds` — so a monitor could read
  `schedule.failed` and say a beat was late without being able to say late by how many runs of what
  cadence, or to check the bound against the settings it came from. The block now carries both, in
  the command's order and read through the command's two readers, so the endpoint and the healthcheck
  cannot print two cadences for one configuration. They are `null` only where there is no flipper,
  the value the command's evidence takes for the same absence, and they are present when nothing is
  judged: a disarmed entry still has a cadence and a count written down. `DatabaseHealthControllerTest`
  holds the pair to the command's report and holds `stale_after_seconds` to their product, so the
  three numbers in the block cannot drift apart.

- **`swrr.audit.finding_seconds` bounds how often one standing finding is logged.** A finding is
  remembered while it stands and was logged on every boot, and under PHP-FPM a boot is a request: a
  configuration state that cannot change without an edit and a restart was ~250 lines a minute on
  the deployment this was measured on. The record now carries `last_logged_at` beside the
  `first_reported_at` the age is read from, and a finding is logged again only once the interval has
  passed — the same shape as the store probe's throttle, and for the same reason. The two
  transitions are deliberately not bounded: the boot that first sees a finding logs it whatever the
  interval says, and the single `resolved: true` line that closes it is written once. Default 60
  seconds; `0` lifts the bound and logs every boot.

- **`/health/db`'s shape is pinned to two tables, so a block or a key cannot move without them.**
  Each field of the payload had a test, and the object they hang on had none: renaming a block or
  adding a key to one passed the whole suite, and the readers this payload exists for — an alert
  rule comparing `flip.failed`, a gate's `jq -e '.pinned.ok'`, a dashboard column bound to
  `pool_repairs.count` — do not fail on a rename. They read `null`, which this payload uses for the
  documented nothing, and report a quiet installation. So the paths are written down twice, in the
  README under "Every block, and every key in it" and in the new record `docs/health-payload.md`
  under "Every path", and `DatabaseHealthControllerTest`'s shape guard builds the richest payload
  the fixture can and compares all three. The two tables are compared with each other first, so an
  edit to one of them alone fails rather than half-updating a contract, and the payload is checked in
  both directions: a path it publishes and neither table names, a path a table names and it has
  stopped publishing, and a key set that has drifted inside one path. A `keys` cell is read as a set,
  because insertion order is a property of the array literal rather than of the contract; an em dash
  marks a path whose value is a scalar, a list, or an object written down under another heading —
  `flip.window` and `pgcat.window` are one array, documented key by key under "The window block" —
  and `<connection>` is the one name the payload chooses at runtime, `replicas` being keyed by the
  connection the package follows. The record states which shapes are deliberately open and why, so an
  em dash is a decision rather than a gap.

- **Each recorded pool repair is readable as a small ledger, not only as the last one.**
  `pool_repairs` says how many repairs a container has made and `last_pool_repair_at` when the newest
  happened, which is one fact about the last repair and nothing about the ones before it — three
  repairs on one pool that each cleared and three that moved between pools with none clearing read
  the same on either. Every repair now writes an entry to `pool_repair_ledger` in the state file —
  when, the pool it was refused for, the mode whose variant declares that pool, and `cleared` — and
  the three readers publish it: `windowStatus()` carries it as `ledger` beside the count and the
  `last_repair_*` keys, `/health/db` as `pool_repairs.ledger`, and `db:pgcat-flip --status` as one
  `repair_N` row per entry, newest first, beside a `last_repair_cleared` row for the newest one.
  `cleared` is measured rather than assumed, which is the half the count could not carry: the path
  that found the mode already applied now reads the pools back after its swap, the way the path that
  had just applied one already did, so an entry says whether the pooler answered for the pools
  *afterwards*. It is one read on a path that has just restarted a pooler and returns on that read's
  first attempt when the restart worked, so the retries are paid only while the pooler is still
  refusing — the state worth measuring. `null` is a third state beside `true` and `false`, for an
  entry written before the answer was measured or a hand-edited value that is not a boolean, because
  "nobody asked" is not "it was asked and did not answer". The ledger keeps the newest twenty
  entries rather than pruning by the rate's window: it is history a person reads, so its bound is a
  payload size, and widening `window_seconds` does not reach past them. A stored entry whose moment
  is not a non-empty string is dropped on the way in and on the way out rather than costing the
  repairs legible beside it — the rule every hand-editable fact in that file follows.

- **A readiness probe that returns the pool probe and nothing else.** `DatabaseHealthController::readiness()`
  answers `status` and `pool_probe` for a load balancer or a kubelet: `503` only when
  `pool_probe.failed` is true — a pool the pooler refuses is a pod that cannot serve the reads routed
  to it — and `200` for every other state, which is the rule `index()` already applies to that block,
  read from the same call so the two surfaces cannot disagree. It is a second action rather than a
  flag because the cost is the difference: no query, no database connection, no replica summary, no
  state store, no boot audit, no schedule, and no log line — a probe polled every few seconds would
  turn one failure into a flood — with one console read when `swrr.pgcat.pool_probe.enabled` is armed
  and none when it is not. An installation that has not armed the probe answers `ok` with
  `applicable: false`, and a pooler that answered nothing stays `warned` rather than failed, so a
  probe aimed at the wrong address cannot evict a pod that serves. `DatabaseHealthControllerTest`
  pins the two-key body, the degraded and warned answers, the unarmed answer, and the cheapness the
  only way it can be pinned: the same fixture whose database cannot be opened, where the full
  endpoint answers `503` and the probe answers `200`.

### Changed

- **`db:doctor`'s `pool probe` row is two rows: `pool probe armed` and `pool probe answer`.** The row
  answered two questions with one socket — whether anything can ask the pooler at all, and what the
  pooler said for each pool — so a build container could only ever leave the whole question out of a
  `--connection-only` run. Arming is a switch and a dialable `[general] port` in the file the pooler
  runs on, and `PgcatConfigFlipper::poolProbeArming()` reads that declaration without opening a
  connection, so the new row is asked in every mode, including the bounded one. The exchange, the
  pooler's own `SHOW POOLS` list, the startup-packet fallback and every verdict stay in `pool probe
  answer`, which is the row `--connection-only` leaves out and which says `not asked` rather than
  vanishing from a run a gate selects it by name in. Every state that is not a missing prerequisite
  is still a pass in both rows — an inert flipper, a probe switched off, a variant that declares no
  pool — and the contract's `pooler` and `health-check` requirements name both, so the README's table,
  [docs/db-doctor-json.md](docs/db-doctor-json.md) and the requirement's own sentence say the same
  thing. A gate that selected the row by its old name selects `pool probe answer`.

- **The `published config` row's fresh-install state is written the same way in the three places that
  state it.** The port plan's phase 1 said a fresh install that has never published is silent, while
  the row warns that the package sample is in use — its documented policy since before the generation
  reading existed. The plan, the README's row and [docs/config-generations.md](docs/config-generations.md)
  now say one thing: a copy that is missing, or still byte-identical to the sample, is answered before
  the generation is read, so a fresh install is the sample-in-use warning and adds no second verdict,
  and the comparison only ever judges a file the installation has written itself. Nothing under
  `src/` moves: the row always behaved this way, and the sentence was the half that had drifted. The
  ordering is asserted now as well — a published copy still byte-identical to the sample warns with
  the sample's own, current, generation in front of it — so the early answer is a test rather than a
  reading of the method.

- **The gate record and the note that quotes it are written by one command.** `php bin/checks.php
  --record` writes `.agents/gate.json` and then renders the note's `gate` block from the record it
  just wrote, so the two halves of that quote cannot be taken separately — a note taken before a
  ten-minute run and the run recorded after it is a note quoting the record before last. Only that
  block moves, at the register the note already names, because a gate run is not a re-take. A render
  that fails fails the command even when every check passed, since the note then still describes the
  run before it.
- **`php bin/handoff.php --block=NAME[,NAME]`** renders only the regions named, at the snapshot the
  note already records — a render rather than a re-take, so no other row moves — and refuses a name
  that is not a block with exit 2. It is the narrow render `--record` uses for the block it just
  rewrote.
- **The gate record may not predate the note that quotes it.** The note is dated in its title, and
  the guard now reads the recorded run's day against that date: a note re-taken on a later day can no
  longer go on carrying the run from before it, and the refusal names both days and the command that
  writes them together. A run taken the same day as its take still passes, and a note re-taken to fix
  a typo on the day of its run is not made to pay for another gate.
- **The `connector` row's second failure is held to the framework's own sentence.** A connection that
  resolves a connector and still cannot be built is the state where the row has to say both things:
  the class that would build it, and the framework's refusal after the colon. The new test binds a
  connector for a driver the framework has no connection class for, so `createConnector()` answers and
  the build that follows refuses, and it reads the sentence the row quotes out of that same factory
  rather than comparing it with a paraphrase. The behaviour is unchanged — this is the test that stops
  a rewording from passing.

### Fixed

- **The two guards that read every tag in the repository now read the tags this branch carries.**
  `PublicApiReportTest` and `HANDOFF.md`'s latest-row guard both took the newest tag *anywhere* as
  the release a checkout is standing on, so a release cut on a branch `dev` cannot reach — a real
  tag, and not a release this line has taken — failed both on a tree that was right: the page
  described `v0.2.0-alpha7` while `v0.2.0-alpha8` sat on `release/v0.2.0-alpha8`, and the note's
  section 3 said the same of its three tag rows. `Handoff::branchTagNames()` reads
  `git tag --merged HEAD` — the question `RELEASING.md` builds a release from, `bin/surface.php`'s
  `latestTag()` asks, and
  `BumpWeighingTest::test_a_tag_this_branch_cannot_reach_is_not_the_base` pins for the base version
  — and the page guard asks it directly. The existence check is untouched: a tag the note writes
  still has to resolve in the repository, wherever it was cut, and the page's own freshness is
  still read against the record, so a checkout that lags a release it *does* carry is red as
  before.

- **`docs/db-doctor-json.md` no longer quotes a phrase `docs/pgcat-flip-json.md` does not contain.**
  The citation said "the code travels inside it"; the record says "code inside it", and the two had
  drifted apart with no diff to show it — which is what the new guard reads.

- **The pooler's mode record names the mechanism that applied it, and the two mechanisms may be
  armed together without a warning.** `PgcatConfigFlipper::writeLastMode()` now writes `flipped_via`
  — `schedule`, `window` or `manual` — beside the `last_mode` and `last_flipped_at` both writers
  already leave there, and `windowStatus()` publishes the reading as
  `applied`/`applied_at`/`applied_mode`/`applied_via`. That is what the retired boot finding was
  about: the per-minute entry records `converged_at` and stops at the end of the boot window, a
  reader-window task applies a mode through the forced path and records no run, so a surface reading
  the convergence described a mechanism that had stopped acting. The two now share the record, so
  the finding has nothing left to make — `db:doctor`'s `flip schedule` row **passes** when both are
  armed and says what each is doing, and the pair is a supported arrangement rather than halfway
  through a migration. Arming both is what buys a container its first minutes *and* the day.

- **`/health/db` no longer reports `degraded` for ever on a container whose pooler a boundary task is
  keeping in step.** `converged_at` is written only by the per-minute entry, so with the reader-window
  tasks armed and the entry disarmed — the migration this package used to document — `window.failed`
  was true from eight minutes after every boot, for the rest of the container's life, with a reason
  that was true and nothing could repair. `failed` is the pair now: a container that reached no
  usable mode by either mechanism inside its window. The reason it is not failed names both
  mechanisms, and the applied reading names the one that last moved the pooler.

### Removed

- **The boot finding `swrr.pgcat.schedule.both_armed`, and the constant that named it.** Two
  mechanisms armed at once is a supported arrangement now that they share the mode record, so the
  pair is no longer a state to warn about: `--strict` stops failing it and `db:doctor`'s
  `flip schedule` row reports a pass where it reported a warning. A record holding the key from an
  earlier boot is closed out rather than left standing — the boot names it as retired, finds nothing,
  and writes the one `resolved: true` line any key that stopped applying is closed with.

## 0.2.0-alpha7 - 2026-10-20

### Added

- **Two skills record an LPR fault and an LPR investigation as a markdown document.** `wdm-issue`
  fires on a `/wdm-issue` marker, or asks *"Is this an LPR issue?"* for a prompt that mentions a
  problem or an issue; `wdm-investigation` fires on a `/wdm-investigation` marker, or asks *"Is
  this an LPR investigation?"* for a prompt that says investigate or "find out why". Each answers
  the prompt first and then writes the response into the folder its subject owns —
  `docs/issues/` for a fault with a fix, `docs/investigations/` for a why-or-how that has none —
  with the evidence, the root cause and the fix a reader who was not in the session can act on.
  Both live under `.agents/`, so a consumer's tarball carries neither, and the change also
  corrects the skills index, which listed an `lpr-issue` skill whose folder was not in the tree.
  Nothing under `src/` or `config/` moves.

### Fixed

- **A release refuses a tree holding files no commit carries, so a tag cannot publish a handoff note
  that names records the version does not contain.** `v0.2.0-alpha6` was cut from a checkout with three
  uncommitted records and a skill rename beside it: `bin/release.php` asked git for tracked changes
  only, so the tree read as clean, the note's pending table was rendered from exactly the set a clean
  checkout lacks, and the note's guard — run in that same working tree — could not tell the difference.
  The runner could, and the tag's own check suite failed on four paths the commit does not carry and a
  skills index naming a skill the commit had removed. The release now asks `git ls-files --others` as
  well and refuses before anything is written, naming the files; `--dry-run` warns and lists them, and
  `--allow-dirty` remains the loud escape. The development tools are pinned exactly (`pint` 1.32.1,
  `phpstan` 2.3.1, `phpunit` 11.5.57) as well, because a `require-dev` range is resolved afresh on the
  runner and a gate green on an older phpstan is not a gate a tag can be cut behind. Recorded in
  `docs/issues/issue-release-v0-2-0-alpha6-ci-red.md`.

- **A supervisor that cannot be reached on its control socket is named, with its repair, rather than
  reported as a timeout.** `SupervisorStep` already told three refusals apart — `proc_open` disabled,
  the binary not on `PATH`, the step's own timeout — but a socket that is missing, refused, or one the
  schedule's user may not open arrived as the generic `errored` and a `could not answer` sentence. It
  is now its own fault (`unreachable`) with a sentence that names the socket and the two lines in
  `[unix_http_server]` — `chmod=0760` with `chown=root:www-data` — that let the application's group
  reach it, or says supervisord is not running to answer on it. That matters because a flip judges the
  supervisor command *before* it swaps anything: a socket only `root` may open makes every flip refuse
  before it can repair a pool, which is the state a live task was measured in. The `pgcat supervisor`
  row prints the same sentence, so `db:doctor` names it before a deploy rehearses a flip, and the
  deployment contract's `supervisor` requirement now states the socket.

- **A flip that just applied a mode now reads the pooler back, so a signal pgcat ignored is a
  restart rather than a silent read outage.** `forceMode()` — the path `db:pgcat-window-flip` takes
  at every window boundary, and `db:pgcat-flip --force-mode` when an operator runs it — wrote the
  variant and signalled supervisord, and asked nothing about whether the pooler had re-read it. The
  mode moved at, say, 10:00, and every read to a pool the new variant declares failed while `pgcat
  mode` compared two files and found them in step: nothing re-applies the file afterwards, because
  the mode on record already matches. It asks the same question the scheduled flip asks after it
  applies a mode (`refusalAfterApply()`): the pools the mode's variant declares, read with the
  bounded retry and delay a pooler that was just signalled needs, and a `missing` pool escalates to
  `restart_command` before the pools are read once more. A pooler that answered nothing is still
  never restarted, so a probe pointed at the wrong port cannot take a healthy pooler down at a
  boundary. The run reports `repaired` rather than `flipped` and exits `0` either way, counts the
  repair in the same `pool_repairs` reading a boot-window repair lands in, and still records no run
  — `runs` and `converged_at` are the boot window's evidence, and a mode applied at 10:00 must not
  be able to move the convergence verdict a deployment gate reads.

## 0.2.0-alpha6 - 2026-10-19

### Added

- **The pool probe's verdict is a Prometheus metric, so a missing pool can page before a deploy
  fails.** `php artisan db:pgcat-metrics` prints `swrr_pgcat_pool_present{pool="…"}` for every pool
  the applied variant declares — `1` when the pooler holds it, `0` when it refused to serve it — beside the
  verdict's own flags (`failed`, `warned`, `judged`, `enabled`, `applicable`) and the address the
  packets went to. The reason it is a metric rather than a preflight: `db:doctor`'s `pool probe` row
  and `/health/db`'s `pool_probe` block answer the same question, and both reach an operator when a
  release is being promoted or from an HTTP scrape outside the container — so a pool the pooler
  stopped holding an hour ago is discovered by the deploy that has to decide whether to promote it.
  A pool the reading could not prove — `indeterminate`, where the pooler also answered the sentinel
  name no configuration declares, or `silent` — has **no sample** rather than a value, and
  `swrr_pgcat_pool_probe_unverified` counts those: a `1` there would be the green reading the
  sentinel exists to prevent, and a `0` would page for a pool that is very likely present while
  making a probe aimed at the wrong port look exactly like a pooler that lost its configuration. The
  body is `PgcatConfigFlipper::poolProbeVerdict()` rendered by `PoolProbeMetrics`, a pure function
  over the array the endpoint and the row already read, so the three cannot disagree about what the
  pooler holds. Stdout is the exposition on every route, including a container with no flipper
  (`enabled 0`); `--strict` is the deploy step's reader and exits `1` on `failed`, `warned` or an
  unbound flipper, with the same body a scrape archives. The command is registered by the provider
  like the rest, and the alert rules, the textfile-collector line and the shapes it rejected are in
  `docs/pgcat-pool-metrics.md`.
- **The interface `pinned.host_ip` reads is now a setting, and the published config's generation is
  3.** The field shipped fixed to `eth1`, which is the right answer on a host with a secondary
  interface and the wrong one on Docker's default bridge — there the interface is `eth0`, and every
  such installation published `null` for a name it had no way to change from configuration.
  `swrr.health.host_interface` (`SWRR_HEALTH_HOST_INTERFACE`) now names it, with `eth1` as the
  documented default: the name the field carried before the key existed, so a published copy that
  predates it keeps behaving as it did. The resolution is forgiving in one direction and strict in
  the other — a value that is empty, whitespace-only or not a string falls back to `eth1` rather than
  silencing the field, while a readable name is used as it is, and a name this host does not carry
  answers `null` exactly as an absent interface does. Nothing is validated against a list and
  nothing is guessed from the hostname or from another interface: the kernel's answer decides. The
  address still never moves `status`, and it is still published whether or not
  `swrr.health.pinned_query` left the query on. Adding the key raises `ConfigSchema::GENERATION` from
  2 to 3 and the sample with it; `v0.2.0` is named for both generations it ships, because the number
  describes a key set rather than a release. A copy published at generation 2 reads `eth1` from its
  own default and is told to republish, which is the one cost of the change and the reason it was
  written down as a promotion before it was taken.
- **`/health/db` says which host answered it: `pinned.host_ip`.** The payload named the connection
  it was about — `pinned.connection`, `pinned.source` — and whether that connection could run
  `select 1`, but it could not name the process that answered. Behind a load balancer, a rolling
  deploy or a pooler per replica, "the database is reachable" and "the database is reachable from
  *this* container" are different claims, and two responses with no host identity left an operator
  comparing them with nothing to compare. The pinned block now carries the IPv4 address of `eth1`
  on the serving host, read by `HostAddress::ipv4()` with PHP's own `net_get_interfaces()` — no
  shell, no `ip`, no extension, one syscall per response, so a health route opens no process it
  does not already open. The field is `null` when the address does not exist — interface absent
  (Docker's default bridge is `eth0`), down, IPv6-only, link-local only, or the runtime lists the
  function in `disable_functions` — and that `null` is a reading rather than a failure: it never
  feeds `status`, never appears in `errors`, and never writes a log line. A warning from the call
  is suppressed and its `false` return becomes the `null` case, because a Laravel error handler
  would otherwise turn a missing address into a 500. Link-local `169.254.0.0/16` is skipped rather
  than published as an address nothing routes to; where an interface carries several IPv4
  addresses the first is the primary, and neither the hostname nor another interface is ever
  consulted in its place. The key is appended after `error` so no documented order moves, and it
  is composed independently of `swrr.health.pinned_query`: switching the query off leaves
  `checked: false` beside a `host_ip` that is still there, because the address describes the host
  rather than the statement. There is no new setting: the interface is fixed to `eth1` by the
  field's contract, and the promotion for deployments that serve on `eth0` is recorded as a
  config-generation change rather than taken now. `DatabaseHealthController` takes the reading
  through an optional injectable closure, which is how the suite asserts the payload without
  asserting the interfaces of the machine it runs on.

### Changed

- **The front-door pool sweep asks every declared pool in one wave, under one timeout budget.** When
  the console cannot be read, the flipper used `PoolProbe::ask()` once per declared pool and once
  more for the sentinel, so a pooler that accepted and said nothing cost `swrr.pgcat.pool_probe.timeout_ms`
  per pool: `/health/db` grew slower with every pool an installation declared, and a health check
  that scales with the configuration it checks is the one reading that cannot be polled on a large
  pooler. `PoolProbe::askMany()` opens one non-blocking connection per name with
  `STREAM_CLIENT_ASYNC_CONNECT` and writes each startup packet and reads each first message through
  one `stream_select()` loop sharing a single deadline, so the connects are concurrent and the
  silent case waits out the budget once. Answers come back in the order asked; a name still silent
  at the deadline is `silent` with the same evidence a single exchange produces. The sentinel travels
  in the same wave and is published only where no refusal already settled the ordering — one
  connection inside the budget, not a second wait after it. `PgcatConfigFlipper` asks the batch
  through `PoolProbe::askMany()` by default; an installation that injected its own `$poolProbe`
  keeps that transport and is asked one name at a time. Measured against the suite's barrier fixture:
  twelve silent pools at a 500 ms budget took 0.514 s in one wave and 12.48 s through the one-name
  path.
- **The handoff note's refusal of a pending-set count no longer stops at the number vocabulary.**
  `HANDOFF.md`'s size sentence names `git status --short` rather than a number, because a count of
  uncommitted paths is a fact about one working tree; `HandoffTest` held it to that by asking
  `NumberWords::knows()` about each word before a counted noun. That table stops at thirty on
  purpose, so the refusal was blind to exactly the shapes a large batch invites — `thirty-five
  files`, which the table cannot spell, and `35 files`, which records never spell at all. The
  decision is that the vocabulary does not track working-tree size: it stays the renderer's rule for
  bounded code facts, and `NumberWords::looksLikeNumber()` reads a count by shape instead — a
  numeral, a known word, a compound or a scale word past the table. A large pending set is still
  stated the way a small one is: the rendered table names every path the register does not carry,
  the sentence names the derivation, and no number appears at any size.
- **The read-path cancellations record is now backed by recipes rather than by history.**
  `docs/read-path-cancellations.md` led with the two SQLSTATEs but stated its figures as facts about
  one deployment — a cap pair, a warning fraction, a fleet of cancellation counts — with nothing a
  reader of *this* package could run to see any of them. It is rewritten around the two cancellations
  (`40001` with `serialization_failure` and its standby sentence, `57014` with `query_canceled` and
  the timeout sentence), the reason the read and write caps differing is what makes escalation
  possible, and the four things this package deliberately leaves to the application — re-running and
  where, the escalation target, `useWritePdo()` pinning, the caps and the alerting. Every figure now
  carries a numbered recipe a reader runs against their own endpoints (`R1` … `R9`), from a one-session
  `57014` and a two-session `40001` to the conflict counters in `pg_stat_database_conflicts`, the row
  `db:doctor` prints, and the constants the warning and the connect bound come from; the counts of one
  installation's log lines are gone, because no reader could repeat them. Five of the recipes were run
  against a throwaway PostgreSQL 17.9 server as the record was written, and the record says so.
- **The rehearsal's step list says why the pool read is reported rather than performed.** The
  record sorted the steps a `db:pgcat-flip --dry-run` reports into the ones it performs and the
  ones it only describes, and gave one reason for the sort — whether the rehearsal can take the
  effect back. A second exists and the record did not name it: whether taking the step now
  measures the state a flip leaves. The pool read passes the first test, because it opens a
  socket per declared pool and reads one message on each and a rehearsal could put that back,
  and fails the second, because a flip reads the pools back after the rename and the signal,
  from the variant that is by then in place; asked now, the read would put the pools of a file
  the pooler has not been given in front of it and collect a refusal the flip is about to make
  untrue. The record names the two questions, gives the pool read its own row, and says which of
  the two positions the installation is in: armed, where a refused pool is repaired by a restart
  on the file just written, or unarmed, where the transition is taken on supervisord's word.
  Only a refusal is repaired at all, and a pooler that answered nothing is reported and left
  alone.
- **The flip's two pool questions are now one read with a policy, and the repair's bounds have one
  home.** `poolFault()` — a poll that finds the mode already applied — and `refusalAfterApply()` — a
  run that has just applied one — were two loops over `refusedPool()`, each reading `start_attempts`
  and `start_retry_delay_ms` for itself; `startAndWait()` read them a third time and `status()` a
  fourth, which is how a report starts describing a bound the run does not use. Both are now
  `refusalFor($mode, $attempts, $delayMs)`, called with the policy the question deserves — one read
  and no wait for the poll, the bounded retry for the apply — and `startAttempts()` and
  `startRetryDelayMs()` are the only readers of those two settings, with the start loop and the
  reported status taking their answer from the same pair. Nothing observable changes: the poll path
  still asks once and sleeps nothing however the retry is bounded, the apply path still uses the
  configured attempts and delay, and the single-reader rule is held by a source scan in
  `PgcatConfigFlipperTest` that reads either quote style, beside the existing assertions that each
  path sleeps the configured delay.
- **A read-only audit record is repaired, not abandoned for ever.** A record the process cannot
  write because its mode refuses it — the state a token pushed from a generator or a volume restored
  from another host arrives in, and the one that made `persist()` skip the whole read-merge-write
  from then on and report `swrr.audit.file.unwritable` on every boot — is now made writable and
  written, which is the repair its resolution sentence has always described. `repairRecord()` runs
  before anything is decided, and the provider asks it before the probe and before the findings are
  built, so the throttle and the surfaces see the record as it now is rather than the one the
  previous boot left. The repair clears the mode on the existing file and reads it back rather than
  trusting the call, which is why a filesystem that ignores it — a FAT mount, a share, an ACL — still
  lands in the old state: the finding is logged and no write is attempted. `BootAuditTest` and the
  provider's probe test hold the repair against a real `chmod()`; the persistent-failure tests
  now break the parent directory instead of the file's mode, so they still describe the case they are
  named for, and the row, the resolution sentence and the three audit records say the repair
  happens.
- **The files this package writes are handed back writable by one helper, so the next process can
  write them whatever account it runs as.** The audit record's release already left 0666 behind,
  but only the record: its `.lock`, the flip's state file and the flip's lock stayed under the
  writer's umask, and a 0644 file is one the *other* account — the per-minute entry and a shell
  `db:pgcat-flip` are the ordinary pair — cannot open for writing at all. `Support\WritableFile`
  is now the single call, `handBack()`, chmod-ing the existing file, clearing the stat cache and
  reading the mode back rather than trusting the call, so a filesystem that ignores it still gets
  the state an operator is told about. The lock files are handed back when they are *opened*
  rather than when they are released, so a run killed while holding one still leaves the next run
  able to take it. A file another account left read-only is also repaired *before* the open or the
  write, so the run that meets one still locks and still records rather than losing a silenced
  write, and the state file is handed back again only when its write actually landed. The
  same class carries `canWrite()` — the file when there is one, else the directory that would
  have to take it — which `BootAudit::recordIsWritable()` and
  `PgcatConfigFlipper::stateFileWritable()` had each written out for themselves; the two readings
  can no longer drift about the one fact they share. The flip's target config is deliberately
  untouched: it is the file pgcat runs, and its mode is the deployment's rather than this
  package's.

### Added

- **`db:pgcat-flip --status` can ask the running pooler, while the default report still opens no
  socket.** `--status` is the diagnostic an operator runs on a host that may be the wrong side of
  the pooler, so `PgcatConfigFlipper::poolProbeVerdict()` was deliberately kept out of it — and an
  installation that wants that reading had `db:doctor` and `/health/db` only, with no way to ask
  the question by hand. `--pool-probe` is the opt-in: a `--status --pool-probe` run asks the pools
  the resolver's variant declares (through the same verdict the doctor's row and the endpoint's
  block print), adds a row per pool, the sentinel exchange and the console's answer where there was
  one, and — under `--json` — carries the verdict under `pool_probe`, `null` on every run that did
  not ask. The flag needs `--status` and is refused without it rather than ignored, because a run
  printing no report has nowhere to put the answer; it changes the report and not the exit code,
  since a report is what `--status` is. The rendered default report is unchanged — no socket, no
  new rows — and the JSON gains only the `pool_probe` key with `null` in it, the same fixed-key
  rule every other route already follows.
- **The probe's wire assumptions are held by a live pgcat from its published image, and the first
  thing the fixture caught was the console's pool column.** Every reading in `PoolProbe` was a
  reading of bytes a test wrote: the shape of a startup packet, the sentence a refusal carries, the
  md5 answer to a console's challenge, and the header of `SHOW POOLS`. That is the right shape for
  the classifier and cannot notice a pooler that does not answer the way the test does. A
  `pgcat-live` job now starts `ghcr.io/postgresml/pgcat:v1.2.0` pinned by digest with a file that
  declares one pool and the console's own credentials, hands it to `PoolProbeAgainstPgcatTest` as
  `PGCAT_LIVE_CONFIG`, and holds the three answers against the process: `answered` for the pool the
  running file declares, `silent` for an address that is not listening, and `missing` through the
  console's list where a variant declares one pool more than the process holds. The job sets
  `PGCAT_LIVE_REQUIRED=1` and checks that the filtered run actually executed the class, so a
  fixture that never arrived or a filter that matched nothing cannot be a green run. Two assumptions
  did not survive: upstream's `SHOW POOLS` names the pool column `database`, not `pool` — the row
  description opens `database, user, pool_mode, …` — so a console it had fully authenticated against
  was refused as unreadable and every sweep fell back to the front door; `POOL_COLUMNS` now reads
  both names, `pool` first. And `v1.2.0` authenticates before it resolves a pool, so the fixture
  pins that ordering too: a release that starts refusing unknown names before it authenticates
  fails the test and the record is revisited, rather than the change arriving silently. The test
  skips without the fixture, so `composer test:unit` still needs no Docker.
- **The pool-answerability reading is the pooler's own inventory, so the `pool probe` row cannot
  pass the fault it exists for.** The startup packet only says what a pooler is willing to answer,
  and that depends on when it resolves a pool: the pooler this package is deployed against
  authenticates first and returns the same `R` for a pool it holds, a pool only the other variant
  declares and a name no file contains, so `answered` proved nothing and `db:doctor` passed with a
  sentence saying so — the one fault this row exists for, a declared pool the pooler does not hold,
  left green. `PoolProbe::adminPools()` is now the first question: it opens the same address under
  pgcat's `pgcat` database, answers the console's cleartext or md5 challenge with the
  `[general] admin_username` and `admin_password` of the file the process is running on, and reads
  `SHOW POOLS` by column name — the process stating which pools it serves. `poolProbeVerdict()`
  builds `pools` from that list where it can be read, marks every declared pool it does not carry
  as `missing`, sets `verified`, and sends no startup packet at all; `discriminates` and `control`
  are null because there is nothing left for them to settle. Only a console that cannot be read — no
  credentials in the file, a refusal, a SCRAM challenge, silence — falls back to the per-pool sweep
  and its control name, whose refusal is then the proof. The console's answer is published as
  `admin` beside `verified` (whether an answered pool is proof) and `judged` (whether the boot
  window has closed); `/health/db`'s `pool_probe` block carries all three, and the row *warns*
  rather than passes when the console could not be read, while a pool the console says it does not
  hold fails exactly as a front-door refusal does. The client-facing exchange still sends no
  password, no new setting is introduced, the credential is the pooler's own and stays on the
  connection it opened, and it never appears in a payload or a row. The decision, the rejected
  alternatives and the repair line the console deliberately does not cross are in
  [docs/pgcat-pool-probe.md](docs/pgcat-pool-probe.md#the-poolers-own-inventory-asked-first).
- **A repair records which pool it was refused for and which mode's variant declares it, and
  `db:pgcat-flip --status` prints both.** The count said a repair happened and the stamp said when,
  and neither said *what* — the pool was in the refusal sentence of the run that saw it, which is a
  log line an operator arrives at after the fact, and the mode was inferable from the file only
  while the file was still the one that run wrote. `recordRun()` now writes `last_repair_pool` and
  `last_repair_mode` where it already wrote the count and the time, so both call sites — the poll
  that found the mode already applied and the flip that applied one a moment before — record them
  without knowing they do; the pool is the name the refusing probe gave, and a repair with none
  writes null rather than carrying the previous repair's pool into a new record. `repairFacts()` is
  still the one derivation, so `poolRepairStatus()` returns `pool` and `mode` beside `count` and
  `last_at`, and `windowStatus()` carries them as `last_repair_pool` / `last_repair_mode` for the
  two rows `--status` prints (`reader_db_2`, `readers`). A state file written before this reads the
  new fields as null and its count and time unchanged, which is the shape a container upgrades in
  until its next repair. `/health/db` is unchanged: its `pool_repairs` block is a rate, and the
  warning's subject is the pooler rather than one pool.
- **`/health/db` warns when this container has needed the pooler repaired faster than a threshold,
  and it can say how many repairs fell inside a window because the flip now dates them.** A repair
  is the self-heal working, so the count `db:pgcat-flip --status` prints cannot separate a container
  that repaired itself once at boot from a pooler being re-applied on run after run, and `last_kind`
  reads `repaired` in both. `recordRun()` now appends the moment of every repair to
  `pool_repair_times` in the state file — pruning what is older than the configured window, so the
  array is bounded by the setting that measures it — and `PgcatConfigFlipper::poolRepairRate()`
  counts the moments inside that window and says `warned` when they are more than the threshold. The
  endpoint publishes it as `pool_repairs`, beside `pool_probe`: `window_seconds`, `max_repairs` and
  `in_window` beside `count` and `last_at`, and a `reason` that names the count and the line it was
  judged against. It moves no status code, because a repair is history and the blocks that fail this
  endpoint are the faults standing now; `judged` is false and `in_window` null for a state file that
  carries a count and no times, because reading that as "none recently" is the comfortable answer
  rather than the true one. The shipped line is more than three repairs in an hour
  (`swrr.pgcat.pool_repairs.*`), the sample gains those two keys and so raises the published
  config's generation to 2, and the decision, its retention and the shapes it rejected are in
  [docs/pool-repair-rate.md](docs/pool-repair-rate.md).
- **The pool probe's two surfaces name the endpoint they actually asked.** `poolProbeVerdict()` now
  carries `host`, `port` and `address` — taken from the probe's own answer, not from the settings,
  so an installation that injects a probe is reported at that probe's endpoint — and `/health/db`'s `pool_probe` block publishes them beside the pool answers. The
  `db:doctor` row ends with `— probed tcp://host:port` for every row that asked something, and
  appends nothing to the two that answer without a socket. A probe aimed at the wrong port is the
  one fault that looks exactly like a pooler that is down: both are `silent`, and a row that listed
  only the pool names left an operator with an installation whose every pool was silent and no way
  to tell a stopped pooler from a mis-aimed probe. Now the address the packets went to is printed
  with the silence.
- **`PoolProbe` can read a port out of a pooler's own file.** `PoolProbe::port()` answers the
  `[general] port` from a `pgcat.toml`'s text, and null when the section names none — there is no
  convention to fall back to, because a guessed port is what an installation arms the probe on
  while the pooler listens elsewhere. The reading is pure: a text in and an integer or null out, no
  file opened and no socket spent, which is what lets it be asked from the path that asks the
  pooler. A `port` under `[pools…]` is a backend's and is ignored, the first `[general]` section
  decides, and a port outside the dialable range is the file's mistake rather than an address.
  `PoolProbeTest` reads `7432` out of a text whose pool section names `9999`, and null out of a
  section with no port, a pool-only file, an empty string and a `port = 70000`.
- **`db:doctor` reports the pool repair count and the last repair, from the same reading
  `db:pgcat-flip --status` prints.** The two facts were built for the status table, which is run
  when somebody already suspects the pooler; the preflight is read whether or not anybody does, and
  a deploy gates on it. The new `pool repairs` row passes in every state it can find — a repair is
  a run that found the pooler refusing a pool the applied file declares and re-applied the file, so
  failing on it would fail an installation for the self-heal having done its job; the standing
  fault, where there is one now, is `pool probe`'s, which asks the pooler rather than reading its
  history. What the row adds is the difference `last_kind` cannot carry: two repairs a minute apart
  and two a week apart both leave the last run reading `repaired`. `PgcatConfigFlipper::poolRepairStatus()`
  is the one derivation, and `windowStatus()` builds its `pool_repairs` and `last_pool_repair_at`
  from it, so `--status` and the row cannot count one container's repairs differently; the row says
  the count is history rather than a live reading, because a run past the boot window returns before
  it takes the lock. It reads the flip's state file and opens nothing, so a `--connection-only` run
  answers it, and `--json` carries it in `checks` like every other row. The README's preflight table,
  the contract's `pooler` requirement and the row counts in the records move with it.
- **`docs/read-path-escalation.md` explains the cancellations to the engineer who has to handle
  them.** The record states the facts and the recipes behind them, but it is written for a reader
  deciding what this package should do; the caller that receives a cancelled read is a different
  reader with a different question. The new page is that explanation: what `40001` and `57014`
  each mean, why the sentence rather than the code is the thing to match, why escalation is a
  caller-side re-run that works only because the target endpoint's cap is higher — with the
  arithmetic, the three cap shapes and the cost of each — and the four decisions the package
  deliberately leaves to the application, each with the reason the package cannot make it. It
  links the record rather than restating its figures.
- **The pool probe tells an auth-first pooler's blanket answer from a pool that exists.** An
  `answered` pool was documented as `R` — an authentication request — for every name it was given,
  so on the pooler this package was measured against the reading proved that the pooler was
  reachable and willing to talk and nothing about the pool. `PoolProbe::controlName()` now supplies
  the one name that settles it: `weighted-dbmanager-control-` and sixteen random hexadecimal
  characters, a name no configuration declares, whose absence is therefore known — random rather
  than fixed so an installation cannot own the control by having a pool of that name.
  `PoolProbe::discriminates()` reads its answer as three-valued: a refusal means the pooler resolves
  a pool before it authenticates, so each `answered` pool exists; an answer means it authenticates
  first and answers every name, so the pools could not be proved; silence concludes nothing. The
  sweep in `poolProbeVerdict()` asks it once, only when every declared pool answered — a refused
  pool is already the proof, and asking again would be a socket spent on a settled fact — and
  publishes `discriminates` and `control` beside the pools, so `/health/db`'s `pool_probe` block and
  `db:doctor`'s `pool probe` row can say which question they were able to ask. `db:doctor` still
  passes for an auth-first pooler, because its ordering is a property of the pooler rather than a
  fault in the installation, but the sentence now says the answers are not proof the pools exist
  instead of leaving a green row to be read as one. Nothing about the repair changes: a pool such a
  pooler answers for cannot be told from one it holds, so `missing` is never observed there and the
  flip has nothing to act on.
- **`db:doctor` names every path this package must be able to write, with the mode found and what an
  unwritable one costs, in one row.** `pgcat files` answers a fault — a source it cannot read, a
  target that is missing, a directory that will not take the copy — so the paths that are writable
  are the ones nothing described until one of them failed, and an installation that moves its state
  directory had no reading to confirm the new one was the one being judged. `write paths` is that
  inventory: the flip target and the directory its temp file and rename act in, the two variants the
  deployment renders, the state and lock a flip records itself in, the boot stamp the window is
  measured from, and the audit record with the `.lock` companion it is serialised on. Each entry
  names the path whose mode decides the write — the file when it is there, the directory that will
  have to take it when it is not — the mode and owner it was found with, and, for one that cannot be
  written, the cost the existing rows already state (a copy that never gets into place, a boot window
  that never closes, a probe skipped because its throttle cannot be written, two boots merging one
  record). The list is `PgcatConfigFlipper::writePaths()`'s, so the row and the class that writes the
  paths cannot disagree about which ones they are; the row fails naming every blocked path with the
  writable ones beside it, passes listing the set it judged, and names nothing while the flipper is
  inert. It prints no suggestion: `pgcat files` owns the `chmod` lines and the findings they are
  dated from, and one fault offered two lines a gate could apply is the duplication that rule exists
  to stop.

### Fixed

- **An auth-first pooler can no longer be read as healthy.** `poolProbeVerdict()` asked the control
  name and published `discriminates: false` beside pools still marked `answered`, so `/health/db`
  reported `answered: true` and `db:doctor` printed a row whose `answered` was a green word for a
  question the sweep had just said it could not answer. Every `answered` pool is now published as
  `indeterminate` — with a sentence naming the sentinel and the pool — `answered` is false whenever
  one is present, and past the boot window the reading is `warned` rather than passing. `failed`
  still belongs to `missing` alone, so a pooler's ordering neither fails nor restarts an
  installation: it stops the reading from becoming a claim. The console path is unchanged — a list
  that names the pool is still proof, and only a console that could not be read falls back to the
  sentinel.
- **A pool's name is the first segment of its header path, read as a path rather than as a prefix.**
  The reader matched a `[pools…]` line with a lookahead that stopped the name at the first dot, so
  `[pools.writer_db_1.users.0]` was read as `writer_db_1` only because the pattern happened to end
  there — a nested segment named like a pool, or a later change to that pattern, could have turned
  `users.0` or `shards.0` into a name a startup packet asks for. It now parses the header's path and
  takes segment zero, quoted in either TOML string form or bare, so a sub-table cannot become a pool
  by construction; a header with no closing bracket declares nothing rather than being read up to
  its first space. `VariantShape`'s section check accepts the same three key forms, so the reader and
  the shape report cannot disagree about which lines name a pool. A test built from the pooler's own
  file — a `[general]` block, one pool with `users.0` and `shards.0` sub-tables, and a second pool
  declared by those sub-tables alone — pins one name per pool and no sub-table among them.

### Removed

- **The pool probe's port is no longer a setting: `swrr.pgcat.pool_probe.port` and
  `SWRR_PGCAT_POOL_PROBE_PORT` are gone.** The key let an installation arm the probe on a port its
  pooler never stated, and a file that named none fell back to pgcat's convention, `6432` — so an
  installation whose pooler listens elsewhere, or whose file could not be read, dialed an address
  that reported silence as if the pooler were down. There is no replacement to set: `PoolProbe::port()`
  reads `[general] port` from `config_path`, the file the process is running on, and answers null
  when it names no dialable port — so a published copy still naming the key should drop it, and an
  installation whose probe stops being armed after this release is one whose pooler file names no
  port. The flipper then builds no probe, so nothing is asked and the surfaces say the probe is not
  armed instead of reporting a silence the pooler never produced. The host is still configured,
  because `[general] host` is a bind address (`0.0.0.0`) rather than one a client can dial, and a
  variant's port is still never read: it moves with a flip.

## 0.2.0-alpha5 - 2026-10-18

### Fixed

- **A release's own commit is green on the note it writes: the records transcript is read at the stamp
  the release wrote, and the tag row is read as the branch it names.** Both were claims a release
  could not make come true in the one commit it is allowed to make them in. `bin/release.php` stamps
  the three records with the tag it is cutting and then renders the note that quotes
  `bin/inventory.php --check` — before the tag exists — so the one line the check compared was the
  stamp, it reported `1 line(s) stale` in each record, and the note committed `exit 1` beside a
  command that exits 0 the moment the tag is pushed. The commit went red on that block, and cold it
  also went red on `v0.2.0-alpha4` being named in a row read as "behind the register": the release
  writes the tag it just cut into its own commit, which is *after* the register a note taken before it
  names, so the row was true about the branch and false about that commit. `--check` now takes the
  reading at a stamp this repository does not have yet — newer than the latest tag, and only while
  every row reproduces the tree, so a stamp naming an older release is still drift and so is a lost
  row — and the note's guard asks whether each tag it names is reachable from `HEAD`, which is what
  "on the branch" says. The transcript is therefore byte-identical before and after the tag exists,
  which is the property a note published in the same commit as the tag needs.

- **The API page's control is the release that shipped a stale page, not "the one before the
  newest".** `PublicApiReportTest` asserts that the tag it reads as a control published a page its own
  tree does not render — true of `v0.2.0-alpha2`, and true of the tag before the newest only until a
  good release followed it. Cutting `v0.2.0-alpha4` moved the control onto `v0.2.0-alpha3`, whose page
  is correct, so the test failed on a page that was right and would have failed after every future
  release. The tag is named, and a checkout without it says so rather than asserting on an empty
  string.

## 0.2.0-alpha4 - 2026-10-17

### Added

- **The bounded preflight can prove the connection answers: `db:doctor --real-test` puts one real
  query to the connection the run follows, and the run prints the setting a container gate parses.**
  `--connection-only` asks what a build container can answer, and every row it leaves in place is a
  *configuration* reading — a file, a class, a value — so an installation can read exactly as
  documented while nothing behind the connection answers. The flag is the one reading in the run that
  leaves the process: `select 1`, asked through `DB::connection()` so a read/write connection goes
  through the weighted factory exactly as a request would, reported with the connection's name and
  the milliseconds it took, and with the driver's own sentence — SQLSTATE included — when it fails,
  which is the shape a deploy gate matches on. `Support\PinnedQuery` is where the query, its path and
  the shape of its answer live, and `/health/db` was moved onto it in the same change: the endpoint
  and the flag are two claims about one database, and a second spelling of "connect and run one
  statement" would let them disagree about one installation and both be right about their own
  reading. The row is built in **every** run and says *not asked* when the flag is absent, because a
  row a gate selects by name must never be mistakable for a passing one and because
  `--connection-only`'s promise of a run with no reachable database is kept by the flag being absent
  rather than by the row being dropped — so the two compose, and
  `db:doctor --connection-only --real-test` is what the deployment's pooler gate runs. The object
  publishes `real_test` beside `connection_only` for the same reason those two are published at all:
  a report that reached a database and one that only read configuration must not be the same
  artefact to a job selecting on fields. Under the banner the run also prints what it inspected in
  the configuration's own spelling — `db-manager.swrr.connection`, or `database.default` where the
  setting named nothing, as a two-space `key  value` line — because that is the line the deployment's
  smoke gate greps its resolved connection out of, and prose a shell cannot read was the one thing
  standing between that gate and this package's own command.

- **A release verifies its own commit with the handoff note's guard before it tags it.** The release
  already refused to tag a commit CI had not run, and it moved the note's three tag rows into its own
  commit — but nothing read the note at the commit a tag points at, so a release commit could be red on
  the note and the first reader to find out was a runner after the push. `bin/release.php` now renders
  the note's five blocks at the register the note already names (`bin/handoff.php --recorded`, which
  keeps the register rows where a re-take would move them) and then runs `HandoffTest` against the
  commit it just made, telling it which tag is pending so the two claims about tags existing can be
  read at a tree where that tag does not exist yet. A red guard is refused with the suite's own
  failure, the commit is unmade with `git reset --soft` and its files stay staged, and no tag is
  created — so no version is left behind. The guard runs between the commit and the tag rather than
  before the commit because the commit is what the tag will point at, and a note read on the working
  tree would be a note nobody was about to tag. `RELEASING.md` names the two steps, `HandoffTest` holds
  the pending-tag allowance to the tag the release passes it, and `HandoffNoteTest` holds the refusal
  to leaving no tag and no commit.

- **The handoff note's gate summary is rendered from a recorded run, so a re-take stops copying the
  check totals, the timings and the suite's numbers out of a terminal.** Section 1 stated the last
  gate run by hand — how many checks, tests, assertions and skips it reported, what the run and the
  suite took, and which case files carried how many tests — and no comparison could hold any of it:
  the prose could only be compared with prose, so a number that was never re-read described a run
  that never happened. `bin/checks.php --record` now writes the run it just printed to
  `.agents/gate.json` — the day and interpreter, the exit code, the whole run's seconds and one row
  per check with its verdict, seconds and note — and refuses `--only`, because a note quoting a
  subset of the gate would be claiming the rest were not there. `bin/handoff.php` renders section 1's
  `gate` block from that record, and `HandoffTest` holds the record to the shape a run leaves: every
  check the gate declares, one verdict apiece, all green, a duration that can hold its rows, and a
  sentence above the block that states no number at all. The per-suite counts are gone with it: the
  suite's own totals are in its row, read off PHPUnit's line, and the block is regenerated by a run
  rather than re-typed by a session.

- **The handoff note's inventory transcript is rendered rather than quoted, so a count in section 1
  is the check's own reading.** Section 1 quoted `php bin/inventory.php --check` twice — at the
  register and at the checkout — with the stale-line counts typed in beside each run, so adding one
  source file left the note stating a count the tree no longer had and the repair was a session
  running the command and copying the output back. The transcript is now a rendered block beside the
  gate's:
  `Handoff::renderedRecords()` runs both commands, quotes each exit code with the tool's own verdict
  and one `N line(s) stale` per record, and `bin/handoff.php` writes it at the register the note
  names. The guard compares the block with the renderer's answer and still runs the commands the
  block quotes, so the fence and the command cannot disagree — and a note taken at a release's own
  commit quotes `exit 0` rather than claiming a disagreement that is not there. The counts are kept
  to the tool's `N line(s) stale` reading rather than the drifted rows it truncates; the note's prose
  says where those are read.

- **`db:doctor`'s `connector` row reads the resolved connection: it names the class a connection
  resolves to, and reports whether an installation's replaced connection class reached it.** The row named
  the connector — the class the framework's `createConnector()` takes from `db.connector.<driver>` —
  and stopped there, because that is what the factory can answer. A connection has a second
  extension point the factory never sees: `Connection::resolverFor($driver, …)` is a global registry
  `createConnection()` consults **before** its own class map, so an installation can replace the
  class every connection of a driver is built as without a binding, a config key or anything else
  that shows up in a repository. So the reading now also builds the connection `ActiveConnection`
  follows and reports the class it resolves to, plus whether a resolver is registered for the driver
  and whether the connection in use was built through it. Building it is what makes the third state
  visible and is the reason the row reads the object rather than the registry: a resolver registered
  after the application built its connection leaves the application holding the framework's class,
  and only the resolved object tells the two apart — `resolver_applied` is `false` exactly there.
  The connection is built from the followed connection's config with the read list removed, because
  `createConnection()` decides the class from the driver and the registry and nothing else, while
  asking the factory for the read/write pair would make the weighted factory choose a replica and
  read the store — which is the one thing `--connection-only` promises not to do; where a connection
  had already been built in the process, that object is the one reported, since it is the one the
  application is using. A connection whose connector exists but whose own build throws now fails the
  row with the framework's sentence, because the installation cannot build the connection it
  follows. Deliberately a pass in every other state, for the same reason a replaced connector is:
  both registries are the framework's own extension points, and what an operator needs is to see
  which class is in effect. `Support\ConnectorInUse` carries the reading (five new keys: the class,
  its driver, the build error, the resolver's driver and whether it applied), `README.md` and
  `docs/db-doctor-json.md` state it per row, and `ConnectorInUseTest` pins the class, the resolver
  case and the built-before-the-resolver case against a connection the manager really built.

- **The deployment contract is machine-checked: `db:doctor --contract` reports its requirements as
  rows.** [docs/deployment-contract.md](docs/deployment-contract.md) states what a deployment of this
  package has to have — the package wired in, a configuration it reads as written, the three rendered
  variants and the prune before pgcat starts, the pinned pooler, the boot stamp, one mechanism with
  its window and its lock, the writable state directory, the supervisor, the container health check,
  the store, the read caps and the health endpoint — but its requirements were named after prose, and
  a pipeline that wanted to fail a deployment over them carried a list of `db:doctor` row names in a
  script of its own. That list is the contract, and a list in a script is a copy of a rule. Now the
  mapping has one home, `Support\DeploymentContract`, and `--contract` asks the same installation run
  for it: one row per requirement, named by the key the record states it with (`wiring`,
  `configuration`, `variants`, `prune`, `pooler`, `boot-stamp`, `mechanism`, `state-dir`,
  `supervisor`, `health-check`, `store`, `read-caps`, `health-endpoint`), its verdict the loudest
  verdict among the rows behind it, its detail naming each of them and carrying their own sentences
  and repairs up. Nothing is re-read and no reading is added — and every row the preflight documents
  is behind at least one requirement, so moving a gate to `--contract` cannot silently stop asking a
  question; that coverage is what makes the mode a complete run rather than a shorter one. The exit
  rule is `gateFailed()` over the requirements' tally, `--strict`, `--json` (same envelope, requirement
  rows in `checks`) and `--connection-only` compose unchanged, and a requirement whose readings that
  mode left out says which ones. A reading the run built and did **not put** counts with the ones it
  never asked rather than with the ones it did: `real query` is in `checks` in every run and says it
  was not asked unless `--real-test` asked, so a requirement that listed it among its asked readings
  would report `none of the readings asked failed` about a query nobody put — the one sentence a
  contract row must never produce. A reading the run built and did **not put** counts with the ones it
  never asked rather than with the ones it did: `real query` is in `checks` in every run and says it
  was not asked unless `--real-test` asked, so a requirement that listed it among its asked readings
  would report `none of the readings asked failed` about a query nobody put — the one sentence a
  contract row must never produce. A requirement no row can answer — the monitor that reads
  `/health/db` — passes saying it was **not judged** rather than inventing a pass, which is the same
  reading `connector` gives when `db.factory` cannot be asked. `--config-file` is refused beside it,
  because a file is not a deployment. `tests/Unit/Docs/DeploymentContractTest.php` holds the record's
  `Key` column to the class's key list and the class's readings to the README's row table in both
  directions, so a row the doctor grows has to be placed in the contract before a deploy can use the
  mode.

- **`/health/db` carries the scheduler watchdog's three readings under `schedule.causes`.** The
  endpoint already published the beat, the tolerance and the verdict; a silent schedule's three
  causes — the store its runs' mutexes live in (named, never asked), how many entries run
  `db:pgcat-flip`, and the boot stamp with its mode and owner — were only in
  `db:scheduler-watchdog`'s report, so a monitor and a healthcheck could explain one silence with
  different evidence, or the monitor with none. Both now read them through `Pgcat\ScheduleCauses`
  and print the same sentences, including the one that says the store was not asked and that
  `db:doctor` is where it is; the test compares the endpoint's array with the command's field for
  field rather than trusting two renderings to agree. The readings never move the status or the
  exit code — the beat's verdict still does — and they ride into the degraded log line because its
  context already carries the `schedule` block. An installation whose entry is not scheduled gets
  an empty list, and the endpoint resolves no schedule for it, which is the same property that
  keeps a reading from registering an entry nobody asked for. The entry count is why an armed
  installation's request resolves the schedule at all; that is the one cost the endpoint takes on,
  and it is a schedule built from configuration with no store read, no socket and no write.

- **The connector a connection is actually built through is named by `db:doctor`'s `connector` row.**
  Laravel builds every connection's PDO through a connector chosen from the connection's own `driver`,
  and `Illuminate\Database\Connectors\ConnectionFactory::createConnector()` takes a
  `db.connector.<driver>` container binding **before** its own map — so an application that binds
  `db.connector.pgsql` has replaced the framework's PostgreSQL connector for every connection of that
  driver, and nothing in `config/database.php` says so. That class is usually load-bearing rather than
  decorative: it is where an application adds the option path its own connection config is written
  with, so an installation can read exactly as this package documents, boot cleanly, and build every
  connection through a class nobody looking at a config file can see. The reading is
  `Uak35\WeightedDbManager\Support\ConnectorInUse`, and it asks the rule the way the framework does —
  the binding first, the framework's map second — rather than restating the map, so it cannot describe
  a factory this installation does not have. The row names the class, says whether it is the
  framework's own or the installation's, and names the binding that answered; a replaced connector is
  a **pass**, deliberately, because that binding is the framework's own extension point and the
  ordinary way an application adds to it, and a row that warned on it would warn on the installations
  doing the right thing. It **fails** where nothing can be built at all: the connection
  `ActiveConnection` follows is not declared (the framework's own *A driver must be specified.*) or
  declares a driver no connector is registered for (*Unsupported driver [x].*) — a mistyped name or
  driver, which no other row reports, since the gate only asks what pgcat can front and only while it
  is armed — and the sentence names the setting the connection's name came from, so the repair is a
  value rather than a search. Where `db.factory` cannot be asked at all (resolving it threw, or it
  resolved to something that is not a `ConnectionFactory`) the row says the reading was not taken and
  leaves that binding to `weighted factory`, because one cause reported twice reads as two problems.
  It constructs nothing but the connector itself, which is what `createConnector()` does and the only
  way the question is answerable — the framework's own connectors open nothing — and no connection,
  socket or file is touched. Asked in a `--connection-only` run, because it reads configuration and a
  binding and nothing outside the process, and it prints no `suggestion`: the repair is a binding, not
  a config line. The doctor's row count, its `--connection-only` narrative and the four records that
  state those numbers move with it.

- **What a deployment of this package has to have is written down, in
  [docs/deployment-contract.md](docs/deployment-contract.md).** The package installs its code and
  nothing it *needs*: not the pooler whose configuration a flip swaps, not the supervisord that is
  asked to reload it, not the directory the flip's state is written into, and not the store the
  weighted state is read from. Those are the deployment's, and until now they were written down only
  in the consuming application's records — a design record about the pooler's boot, a troubleshooting
  record of what failed in the field, and the shell scripts that do it. The record states the shape a
  deployment has to have for this package's own readings to be honest, what each missing piece costs
  (a flip that cannot get its copy into place, a pooler that comes back serving pools the connection
  does not name, a health check that reports a container healthy while the pooler behind it serves
  nothing), and the row or command that asks about it: the three rendered variants and the
  prune-before-start rule, the pinned and compiled pooler, the boot stamp, the per-minute entry with
  its window and its `flock`, the writable state directory, a spawnable supervisor, the container
  health check, the store and what its absence means, the read caps that need not match the write
  cap, and the two deploy gates that read this package — `flip-rehearsal.sh`, which branches on the
  `kind` in `db:pgcat-flip --dry-run --json` and so makes that report a contract in another
  repository, and `pgcat-health-gate.sh`, whose one real query goes through the connection this
  package weights. Its citations name the application's files rather than quote them, because this
  package's suite reads its own tree; the record states that as its first limitation rather than
  leaving a reader to assume a guard holds it.

- **The file a flip would swap *in* is judged before the swap: `db:doctor`'s `variant shape` row and a
  `variant shape` step in `db:pgcat-flip --dry-run`.** Every pgcat reading before this one is about
  an *ability* — the paths resolve, the files are readable, the command runs, the pooler answers —
  and none of them is about the file's content. A variant can be readable, writable and swapped in
  cleanly and still be the wrong shape: a `[pools.<name>]` header no pool name can be read out of (a
  truncated template, a `[pools]` with no pool below it), a variant that declares no pool the pinned
  connection writes through, a readers variant missing a pool that connection's `read` list weights,
  a writer-only variant that still declares one of the readers it exists to leave out, or a file in
  place that is half a rendered variant — a pool commented out leaves all of the read pools or none
  of them, and half of them is the mixture. pgcat validates its servers when it starts, so a flip to
  any of those is a pooler that does not come back, and until now the package found out by watching
  it fail to answer.
  `Uak35\WeightedDbManager\Pgcat\VariantShape` reads the three configured files and the pool names
  the pinned connection is written with — its own `database`, and each `read` entry's, off the same
  reader the pool probe uses — and holds one against the other. A name **no** configured variant
  declares is deliberately not judged, because Laravel's `database` is a database name on every
  connection and a pool name only where a pooler is in front, so failing it would fail the
  configuration that works; a path that cannot be read is skipped rather than reported, because
  `pgcat files` owns it and the flip's own `read source` step fails on the source a run is about to
  swap; and the summary counts the files it did read. The one warning is a pool whose addresses do
  not resolve *from here*: the container's boot prunes a pool it cannot resolve, so a flip to that
  file puts back the failure the prune exists for — and it warns rather than fails because resolution
  is a fact about the host that asks, where a build container, a runner or a release engineer's
  laptop is not the network the pooler will start in. Nothing swaps a variant while the flipper is
  inert, so the row passes without judging there, and it prints no `suggestion`: the repair is a pool
  name, a renderer or a resolver, and none of them is a value this line can name. The reading is one
  class with two surfaces — the row, which is answered in a `--connection-only` run because it reads
  files and asks the running pooler nothing, and the rehearsal's step, which runs before the temp
  write so a rehearsal that would refuse has not written into the target's directory.

- **The two cancellations a routed read can meet are now read before traffic arrives: `read caps`,
  `standby feedback` and `read/write pair`.** This package chooses the endpoint a read lands on, and
  that endpoint can cancel the read in two ways a caller cannot tell apart: `40001`, *canceling
  statement due to conflict with recovery* — the read ran on a standby that would not delay recovery
  for the row versions its snapshot needed — and `57014`, *canceling statement due to statement
  timeout*, which is an endpoint's own cap being reached, and needs a different endpoint rather than
  another attempt. Neither is visible from Laravel's configuration, because a connection names hosts
  and a parameter group names what they do: an installation could route every read at an endpoint
  capped at a fraction of the writer's (`600ms` beside the writer's `120s` is one such group) and every
  row still read well. `Uak35\WeightedDbManager\Database\Weighted\EndpointReadings` takes one sweep of
  the connection's `read` list and the writer and answers all three: each endpoint's own
  `statement_timeout` as a set — unequal caps being the reason a statement a read endpoint cut can be
  escalated to the writer and finish there, and a read endpoint capped below half the writer's a
  warning — each read endpoint's `hot_standby_feedback`, off on a standby being the cause of the whole
  conflict class, and the connection's `read` list and `write` block, which is whether Laravel can
  honour a pinned `useWritePdo()` read at all. The two probing rows are behind `swrr.read_path.enabled`
  (`SWRR_READ_PATH_ENABLED`), off in the shipped config because they are one connection per endpoint,
  and `swrr.read_path.timeout_ms` bounds the connect, so a preflight cannot be made to wait on an
  endpoint that is not there. An endpoint that refused, timed out or did not answer moves no verdict:
  it is named with what it said, because reachability is the health monitor's question. They are
  *readings* — the switch read as a switch, so a typo cannot arm a connection per endpoint — and
  nothing here re-runs a statement, because a retry inside a connection cannot see whether the
  caller's transaction is aborted. `--connection-only` leaves the two probing rows out with the store,
  the supervisor and the pooler, and the contract an application answers these two with, with the
  measurement it came from, is `docs/read-path-cancellations.md`.

- **A silent schedule now says which of its causes was read: the store its runs' mutexes live in, how
  many entries run the flip, and the boot stamp.** `stale` answers whether the per-minute entry has
  stopped, and its sentence names the cause that is nearly always the right one — nothing is running
  it. Three others leave the same state file behind and are invisible in it, because none of them is
  about the file the verdict reads. An installation whose shared cache does not answer throws inside
  `withoutOverlapping()` before the entry runs, and because that throw is inside `schedule:run` the
  entries after it in the same run do not run either — so the schedule is dead while its cron is
  perfectly healthy. A second entry for `db:pgcat-flip` (the snippet the README used to hand out,
  beside the registration the provider adds) is two runs a minute, and the framework's mutex cannot
  serialise them because each entry names its own. And a boot stamp that cannot be *read* is reported
  by `FlipWindow::bootedAt()` exactly as one that was never written, so the window is not judged — and
  neither is the beat inside it. `Uak35\WeightedDbManager\Pgcat\ScheduleCauses` reads all three: the
  store's name and whether it answered a read, the two counts, and the file's existence with the mode
  and owner it was found with. `db:doctor`'s `schedule beat` row and `db:scheduler-watchdog`'s report
  (under `causes`, and as one sentence after the verdict on the rendered line, which is the shape a
  person reads at 3 a.m.) carry them beside the verdict. They are *readings* rather than verdicts:
  none of them moves an exit code, and an installation whose entry is not scheduled reads nothing at
  all — its schedule is not even resolved, because resolving it is what registers this package's
  entry. The healthcheck leaves one of them half-taken on purpose: a check that runs every minute
  answers from the state file and asks nothing that can time out, so it names the store and says
  which run takes the answer — `db:doctor`, which asks it, and does not when `--connection-only` has
  bounded the run.

- **A preflight a build container can run: `db:doctor --connection-only` asks everything about this
  boot, this configuration and these files, and asks the store, the supervisor and the pooler
  nothing.** Six of the doctor's rows are questions about things a build container does not have —
  `store probe` and `store reachability` (the store), `pgcat supervisor` (a running supervisord),
  `pool probe` (the running pooler) and the read path's own pair `read caps` and `standby feedback`
  (the read endpoints, which the row opens a connection to) — and a row that cannot be answered does
  not go quiet: it reports the container's own missing prerequisite, so an image whose state
  directory is mounted at run time failed a build over a directory the image never owned. The flag
  leaves those six out entirely — not passed, not printed as not-judged — and everything else is
  unchanged: the same rows in the same order, the same sentences, the same exit rule (`gateFailed()`)
  and the same envelope, so `db:doctor --connection-only --strict` exits `1` for a pgcat switch armed
  over a `sqlite` connection with no store, no supervisor and no reachable database. The run says
  which rows it left out — the banner names them, and the object publishes `connection_only` —
  because a shorter report read as a complete one is a preflight claiming six questions passed that
  were never asked.

- **Two facts about the boot, and the trap they make visible: a `boot environment` row, and the
  connection setting published beside the connection it produced.** The doctor's new first row
  reports the file this boot read its settings from (`environmentFilePath()`, so a
  `loadEnvironmentFrom()` name is reported rather than a hardcoded `.env`) and whether the
  configuration is cached (`configurationIsCached()`), with the cache it compared. A cache built
  before the environment file was last written *warns* — `php artisan config:clear` is the repair, and
  `--strict` is how a deploy refuses to ship a configuration nothing obeys — which is the shape an
  image that caches at build time and mounts its `.env` at run time has, and which no other row can
  see: a cached config is a perfectly readable config, and every row below it was judging the cached
  values. The object also publishes `swrr_connection` — the value `db-manager.swrr.connection` was
  written with, `null` when it says nothing — beside the `default_connection` it followed, because
  unset and set-to-the-default are the same routing and two different things to change.

- **Two mechanisms armed at once are reported by name, with a finding of their own to date the
  pair.** `swrr.pgcat.schedule.enabled` converges the pool at container start and is a no-op for the
  rest of the container's life; `swrr.pgcat.schedule.windows.enabled` moves it at each reader-window
  boundary, for as long as the container lives. Read the same resolver they cannot both act on one
  boundary — the per-minute entry checks the boot window before it takes its lock and returns
  `window_closed` once a container has converged — so an installation with both on is not racing, and
  `db:doctor`'s `flip schedule` row used to say so and pass. What that reading left out is the part
  neither setting shows on its own: the two do not share a *record* of what they did. A mode the
  tasks apply goes through the flipper's forced path (`forceMode()`), which is deliberately not
  written as a boot convergence, so `db:doctor`'s `pgcat mode` row and `/health/db` describe the
  entry's convergence while the tasks are the thing moving the file, for ever. The row now *warns*,
  naming both keys in the same sentence the boot audit logs (`FlipSchedule::bothArmedSentence()`, so
  the two surfaces cannot phrase one reading two ways), and the state is a boot finding of its own —
  `swrr.pgcat.schedule.both_armed` — so the row dates the pair rather than only reporting it. It is a
  warning rather than a failure because neither arrangement is malformed: the documented migration to
  the tasks is to disarm this entry in the same change that arms them, and a preflight that failed
  the state in the middle of that change would refuse exactly the installation doing the right thing
  slowly. `--strict` fails it, which is what makes it a release gate rather than a line nobody reads.

- **A schedule that stops is now a fault rather than a silence, and `db:scheduler-watchdog` is what
  a container's own healthcheck runs to catch it.** The per-minute flip is a deliberate no-op after
  the boot window closes, so the flip's state file stopped moving eight minutes into a container's
  life: a container that converged and then lost its cron line kept serving, kept answering
  `select 1`, kept reporting the mode the last run applied, and read *converged* on every field of
  `/health/db` until somebody noticed — which took 85 minutes on the deployment this was written
  for. Every run of the entry now stamps a beat in that file, including the runs that decide
  nothing, and four surfaces report it: `db:doctor`'s new `schedule beat` row fails when the beat
  has stopped, `/health/db` publishes a `schedule` block and returns `503`, and
  `php artisan db:scheduler-watchdog` exits `1` for a container healthcheck — it opens no socket,
  takes no lock and writes nothing, so it can be asked every minute. The tolerance is
  `swrr.pgcat.schedule.stale_after_missed_runs` (`SWRR_PGCAT_SCHEDULE_STALE_AFTER_MISSED_RUNS`,
  default `5`), counted in *missed runs* rather than seconds so it follows the cadence: five minutes
  at the shipped one-minute interval, twenty-five at five. `0` switches the watch off, which
  `--strict` refuses — the setting that makes a dead cron invisible must not also make the command
  that exists to notice one pass for ever. A route that was never judged (the entry is not
  scheduled, the container is still inside its boot window, or its boot was never stamped) says so
  in words and exits `0`, because "nothing is watched" and "watched, and it is well" must not be
  the same silence.

- **A supervisor failure says which of three ways it failed, so an image build catches a container
  that cannot spawn a process at all.** A production container had `proc_open` in
  `disable_functions`, passed every other gate, and could not flip: the supervisor step reported
  `rename refused never reached`, its detail said the command `did not answer in time`, and the
  answer it printed was the Process class saying it relies on `proc_open`. The three states behind
  that one sentence are now told apart, because each is repaired somewhere else — an ini file, a
  `PATH`, the daemon or the timeout. `proc_open` unavailable is a fault of its own
  (`SupervisorStep::FAULT_UNSPAWNABLE`), raised before anything is spawned and naming the
  `disable_functions` value this process read and the line to change; a shell that cannot find the
  binary is `unresolved` with the exit code `127` rather than a timeout; and the timeout keeps its
  sentence with the seconds it waited. `db:doctor`'s `pgcat supervisor` row prints the step's own
  verdict, so an image build reports the same thing a deploy's rehearsal would — without running a
  flip, and on a machine whose PHP *can* spawn processes, since the reading is injectable.

- **The paths a flip *cycle* writes are judged beside the ones a flip step names, and every file
  finding carries the mode and owner it was found with.** A production container passed every other
  gate and could not flip: its target and its audit record were not writable, and nothing said so
  until the moment traffic was supposed to switch. `db:doctor`'s `pgcat files` row and the boot
  audit's file findings read one list — `PgcatConfigFlipper::fileProblems()`, the class that owns the
  paths — and that list now covers what a *cycle* writes rather than only what a *step* reads: the
  boot stamp `swrr.pgcat.boot_file` points at, whose directory has to accept the write the
  container's entrypoint makes (an unwritable one leaves `FlipWindow::closed()` false forever, so the
  per-minute entry keeps flipping instead of standing down after its window), and the `.lock`
  companion the audit record is locked on, which nothing judged before and which fails silently — the
  record is replaced by a rename, so the lock lives beside it, and an untakeable one lets two boots
  write the record at once (`swrr.audit.file.lock.unusable`, one finding of its own). The record
  *file* itself is deliberately not on the list: `store probe` and `swrr.audit.file.unwritable`
  already judge it, in the sentence the object that owns the record writes, and a second row printing
  it would be one fault reported twice. The lock is judged only while the record is usable, for the
  same reason. Every problem's sentence now ends with the facts the check already held — `mode 0644,
  owner app:www-data`, numeric ids where the platform cannot name an account — because an unwritable
  path is one question away from *by whom*, and the repair differs depending on whether the mode is
  narrow or the owner is somebody else.

- **A published `config/db-manager.php` now says which key set it is, and `db:doctor` says when that
  key set is behind the package it runs.** The sample config is published *into* an application once
  and then edited there — nothing writes over it again unless somebody asks for `--force` — so that
  file is the one thing in an installation that ages without moving: a key added to the sample
  afterwards is a key the application never receives, and the installation keeps reading the
  documented default for it while the changelog describes a release that does something else. Nothing
  is malformed, no row fails, and every reading of the file is of a key set that stopped moving at the
  moment it was published. `config/db-manager.php` therefore carries one whole number, a top-level
  `generation`, and `Support\ConfigSchema` owns it: the key name, the number this package ships, the
  release each generation was introduced in, and the three sentences a copy behind it is reported
  with. It is raised when the *keys* move — a key added, removed or moved — and never for a changed
  default, a reworded comment or a new env var behind a key that already exists, because none of
  those is something a published copy has to be republished to keep working; a version cannot answer
  the question either, since `v0.2.0-alpha3` names a release rather than the keys in a file somebody
  may have edited thirty times since. `db:doctor`'s `published config` row compares the copy's number
  with the package's and stays a *warning*: running an older published config is not a fault, it is a
  fact a preflight states. The reading is of the file an application actually has — `config()` is the
  merge of the sample and the published copy, so a file that predates the key would read as the
  package's own generation, which is the silence it exists to break — and the four states are told
  apart rather than collapsed: a copy at this generation passes whatever the installer decided to put
  in it, a copy behind warns with both generations and both releases, the cost (the keys added since
  are read from their defaults) and the repair, a copy that carries no such key is reported as one
  published before the key existed, a copy *ahead* of the package says its extra keys are ignored,
  and a value that is not a whole number is a value to fix rather than a copy to republish.
  `docs/config-generations.md` is the policy record beside it, and the worked example is the one a
  publisher is most likely to write: what must not be done to the keys this package reads as switches
  — a `(bool)` cast in front of one reads `'off'` as on, and a `swrr.connection` chain ending at
  another driver's name arms pgcat against a connection it cannot act on.

- **The API page of a past tree can be read out of git, so a release's published page is a question
  rather than a checkout.** `API.md` is one file and it describes the tree in front of it, so a past
  release's page has no second copy to write beside it — replacing the page with another tree's
  publishes a consumer-facing document that disagrees with the record on disk, which is the failure
  the page exists to make impossible. `php bin/api.php --at=REF` therefore renders instead of
  writing: the rows are rebuilt out of the tree a tag, a branch or a commit holds, exactly as
  `bin/inventory.php --at=REF` reads them, the stamp is the release that ref is, and the bytes go to
  stdout with a note on stderr — so a redirect gets a page and the checkout is left alone. The
  read-only half is the question a release's own commit has to answer: `--check --at=REF` compares
  the page on disk with the one that tree renders — green on the tree the ref names, and red against
  an older tag on a checkout that has moved on, which is the honest answer about a pair. A ref this
  repository does not have is refused before anything is read, because a ref that does not resolve
  reads as a tree with no files in it and a page rendered from that record announces a package with
  no API. Reading a ref needed the rows to be read from one place, so `apiRefRecord()` and
  `refInventoryRecords()` are the record this renders from rather than a second reader beside them.

### Fixed

- **`docs/prose-numbers.md`'s example quotes are held to the records they came from, and two of them
  were stale.** The bound table's second column is titled "The sentence, as the record writes it",
  but only the number inside a quote was ever checked: the guard compares the count, so a sentence
  the record had since reworded — or one whose count had moved while the example kept the old
  wording — stayed in the table as evidence of a sentence nobody could find. Two were in that state.
  `pool-exclusions.md` had capitalised its sentence and bolded it whole ("**The reason vocabulary is
  closed at three.**"), and `docs/prose-numbers.md` still quoted the lower-case fragment, so a reader
  searching for it found nothing. `db-doctor-json.md` had gone from "three rows can offer one" to
  **four** when the `pgcat files` row gained a repair, and the example beside it still said three —
  the row set the test asserts has been those four since that commit. Both are corrected, and so is the docblock in
  `DbDoctorTest` that quoted the old "three rows" reading. The new
  `ProseNumbersTest::test_every_example_the_record_quotes_is_a_sentence_of_the_record_it_names` reads
  both tables as data and takes each example to the record its claim names — the first table's
  record from the claim's own `record`, the second's from the path written beside the pattern —
  asserting the record still writes the sentence, with the fragments around an ellipsis in order, so
  a quote assembled from two sentences fails too. `**` and line breaks are normalised on both sides,
  because a record bolds some numbers and not others and the record bolds the number it counts; the
  guard compares words rather than markup. Adding it changed no reading, count or exit code, and
  `docs/prose-numbers.md` now states the claim and the test that reads it.

- **`PgcatConfigFlipper`'s comment about the paths `writtenPaths()` judges now counts what the
  method returns.** The block above `fileProblems()`' second loop, the docblock on the method and the
  one on `writable()` all said this package judges *three* paths that no step of a flip names — the
  boot stamp, the audit record and the record's `.lock` — but the record file was deliberately taken
  off that list: `db:doctor`'s `store probe` and `BootAudit`'s own finding already judge it, and a
  second sentence would report one fault twice. What the method returns is the boot stamp, and the
  lock only while the record it serialises is itself writable, so at most two. The comment named a
  third row an operator could go looking for and not find, in the one place that explains why the
  `pgcat files` row asks about files a flip never touches; `fileSettingPaths()`' docblock carried
  the same stale count ("the other three") beside its two entries and is corrected with it. No
  reading, sentence or exit code changes — `writtenPaths()` is untouched, and so is every test that
  exercises it.

- **The tag rows of `HANDOFF.md`'s section 3 are moved by the release that falsifies them.** The
  note's section 3 names the tags on the branch, the latest of them and the suffixless version that is
  *not* there — three claims about *now*, and the tag a release is about to cut falsifies all three in
  one act. The push that publishes the tag comes after the commit the release made, so the note's own
  guard failed on CI rather than on the machine that cut the release: every release this package has
  tagged landed red on the same row, on a version somebody had already fetched, and the branch was
  repaired by re-taking the note afterwards. `bin/release.php` now moves those three rows into the
  release commit, beside the CHANGELOG, the record and the page: the list of tags gains the tag being
  cut, the row naming the latest of them becomes it, and the absent version becomes the smallest
  version with no suffix that is newer than it — the release a prerelease announces, or the next patch
  above a release, which is the row the note already carried at a register whose newest tag is a
  prerelease (`v0.2.0` beside `v0.2.0-alpha3`). Only the version in a row moves; the sentence around it
  is the note's, and nothing else in the file is touched, because the rest of it is a snapshot of a
  working tree taken by hand that no script can re-take. A package that keeps no note has nothing moved
  and nothing written; a note whose row cannot be found by the label the guard reads it by, a row with
  no version in it, or two rows sharing one label stops the release before anything is written, since
  the alternative is the same red commit one release later. `HandoffNoteTest` drives all of it through
  the real script against a copy of this note, including that exactly those three lines differ and that
  the three are the lines `Handoff::tagRowLines()` reads.

- **A fresh clone has a machine record to copy from, and two readings of a path written in prose are
  pinned.** `.agents/machine.local.json` is gitignored — it holds this machine's own facts — so a
  checkout had nothing to copy from while `AGENTS.md` named a file that was not there. The same
  record in token form is committed as `.agents/sample.machine.local.json`, `.gitignore` carries the
  exception, and `MachinePathsTest` reads the sample back: it is in the listing the machine-path
  guard scans, the guard finds nothing in it, and every fact that differs between two machines holds
  a `__TOKEN__` rather than a value. The two path readings are the application's own failure asked
  of this package's reader of prose: a `+++` header path there was keyed with the tab git terminates
  it with, so an allowlist could never excuse a file whose name contained a space. Here the
  terminator is not part of what is read, and a spaced directory is read whole where the path
  continues past the space — so `Program Files` is still tolerated inside a header, and a space in
  the last segment shortens the path rather than hiding it. Both cases are asserted, so neither is a
  reading that agrees with nothing.

- **The published config's `swrr.pgcat.schedule.enabled` really does follow `swrr.pgcat.enabled`,
  which is what every surface already promised.** The file shipped `env('SWRR_PGCAT_SCHEDULE_ENABLED',
  false)` — a *value*, present on every installation — while its own comment, the class, `README.md`'s
  setting table and `db:doctor`'s row all described the key as following `pgcat.enabled` when it is
  left unset. The inheritance was therefore dead in every published copy, in the one direction that
  matters: an installation that armed `pgcat.enabled` and said nothing about the schedule got the
  entry *off*, with pgcat's file left where the last write put it and nothing in the day to move it.
  The default is now deliberately absent (`env('SWRR_PGCAT_SCHEDULE_ENABLED')`, no second argument),
  so absent means *follow*, and a value written there means what it says — `false` is the switch off
  on purpose, and is also how a copy says "the reader-window tasks own this". A copy that had been
  written around the old default keeps behaving as it was configured to; the file's comment now states
  which of the two shapes to write and why.

- **An `interval_minutes` the entry cannot read resolves to the class's own default, and the comment
  says which number that is.** The cadence setting falls back to
  `FlipSchedule::DEFAULT_INTERVAL_MINUTES` for every value the cron expression cannot state — `0`, a
  negative, a non-number, anything past 59, where a minute step wraps past the top of the hour — and
  the rule is that every way this setting can be wrong runs *more* often than was asked for rather
  than less, because an unreadable interval must not be the thing that leaves a pooler un-repaired. A
  copy that declares a wider cadence than the package's own default therefore gets the default for an
  unreadable value, not the value it wrote, and the comment beside it now says so as the rule rather
  than as the arithmetic.

- **The guarded commit path is a file of this repository, and the skill that names it points
  there.** The rule that a commit carries only the paths it names lived in the LPR application's
  `git/commit_paths.py`, reached across a sibling checkout — a fact this package's own rules
  forbid it to read. The tool is copied in beside the package it commits, under `git/`, with the
  application's own references removed: the header no longer points at a `site/AGENTS.md` or a
  `.agents/stale_index.py` that are not here, and the history it narrates about an earlier commit
  is stated as the rule it exists to hold. `pkg-commit` now points at that local path, and
  `HandoffEnvironmentTest` names the one `.py` file a commit carries and exempts it, so the
  hand-maintained rule the note states is that no *other* Python is carried rather than that none
  is. `.gitattributes` holds the tool out of a consumer's tarball beside `AGENTS.md`, so
  installation reads nothing new.

- **The handoff note's records reading holds the commands its transcript quotes rather than a fixed
  exit code, so a note can be taken at the commit a release leaves.** The guard required
  `php bin/inventory.php --check --at=<register>` to exit 1 — the three `.tsv` records out of step with
  the commit the note names — and that is a state a note at the end of a release cycle is not in: the
  release writes the records, so at the commit it leaves they are current, their header naming the tag
  just cut, and the check exits 0. The reading therefore refused exactly the note a release needs most
  taken, and a fixed code could only ever catch the direction it was written for. What is read now is
  what the note *quotes*: `Handoff::records()` takes the commands out of section 1's transcript and the
  test runs each one, holding the exit code written beside it to the one it returned — so a note that
  writes `exit 1` beside a command that exits 0 fails, and so does a command that failed under a
  summary saying it did not. The block that holds the transcript is found by the command it quotes
  (`Handoff::RECORDS`, the one command under `bin/` this guard runs) and the program and its path are
  written in the reader rather than read off the line, so a line cannot rename the tool into something
  nobody runs. Reading it needed the fence reader to stop at the first block of output no longer: the
  section holds both the commits its batches became and the transcript, so `fences()` reads them all
  and `fenced()` is its first, while a fence carrying a language — the `powershell` block a reader runs
  — is stepped over rather than mistaken for a block of output. Measured in both directions, and the
  note's section 5 records it.

## 0.2.0-alpha3 - 2026-10-11

### Added

- **A flip that applies a mode now reads the pooler back, instead of reporting the transition and
  assuming the pooler followed it.** The answerability read used to happen only where the mode was
  *already* applied, so the state a flip could not see was its own: the file was the variant the new
  mode asks for, the mode was recorded, and pgcat was still serving the pools it held a moment ago —
  every read to a pool the new variant adds failing while `pgcat mode` compared two files and found
  them in step. The run that swaps the file now asks the pooler about each pool the variant it
  applied declares, with the same bounded attempts and delay a repair's starts use (`start_attempts`,
  `start_retry_delay_ms`), because both ways of applying a config return before pgcat has re-read
  one: a reload is a signal and a restart returns inside supervisor's `startsecs` window. A refusal
  that survives those attempts is a signal that was not acted on, so the repair escalates to
  `restart_command` — judged with the same `SupervisorStep::inspect()` a flip judges its own command
  with — and the pools are read back once more. The run reports `repaired`, not `flipped`, with the
  transition in its sentence (`… was applied (writer → readers) and the running pooler did not answer
  for pool "reader_db_2" …`), and exits `0` for the reason a repair does: the file and the mode are
  the flip's job and both are in place, while the standing fault is `/health/db`'s to report. An
  installation with `use_reload = false` says so rather than restarting the pooler twice, a pooler
  that answered nothing is never repaired — the line that keeps a probe on the wrong address from
  restarting a healthy pooler — and `db:pgcat-flip --dry-run` reports the read as a step it cannot
  perform, because the pools it would ask about are the ones nothing has renamed in yet. No setting
  is added: the probe's own switch and the repair's own bounds are the whole of it, and an
  installation that has not armed `swrr.pgcat.pool_probe.enabled` still swaps a file and reports the
  transition exactly as before.

- **Every pool repair is now counted, and `db:pgcat-flip --status` reports how many there have been
  and when the last one happened.** The repair was visible only as a run's kind, and a kind cannot
  tell a one-off from a pooler that keeps needing repair: two repairs a minute apart and two a week
  apart both leave it reading `repaired`. The state file gains `pool_repairs` and
  `last_pool_repair_at`, written wherever a repair is recorded — both the run that found the mode
  already applied, and the run that applied a mode and read the pooler back — so a counter only one
  path moved would read a pooler repaired by the other as never repaired. `--status` prints them as
  a `pool_repairs` row beside `flip_runs` (`2 recorded (last 2026-10-10T12:00:00+00:00)`), and
  `--status --json` carries the same two facts under `status.window`. Nothing else changes: the same
  state file and the same window block, with a container that has never repaired anything reading
  `0 recorded (last never)` rather than a row that is absent.

- **`/health/db` and `db:doctor` now say whether the running pgcat is answerable for its pools, not
  only whether the flip repaired one.** The pool-answerability probe was a *repair* and had no
  reader: it runs inside a flip, and only inside the container's boot window, so the state it exists
  for — a container past that window whose pgcat is RUNNING and holds no pool for a database the
  applied variant declares — was invisible to every surface. `PgcatConfigFlipper::poolProbeVerdict()`
  publishes that reading, and two surfaces ask for it by name, so they cannot disagree: `/health/db`
  gains a `pool_probe` block (`enabled`, `applicable`, `answered`, `failed`, `warned`, `pools` — one
  entry per declared pool with the pooler's own `answered`/`missing`/`silent` and its sentence — and
  `reason`), and `db:doctor` gains a fourteenth row, `pool probe`, printing the same verdict and the
  same sentence. A pool the pooler refuses makes the payload `degraded` and the row `FAIL`, with the
  log line beside them; a pooler that answered nothing at all is `warned` and never failed, so a
  probe pointed at the wrong address cannot take a healthy installation down or restart it; and
  neither is judged while the boot window is still open, where the per-minute flip re-applies the
  configuration. Off by default: an installation that has not armed `swrr.pgcat.pool_probe.enabled`
  opens no socket, and both surfaces report that the pooler is taken at supervisord's word. The
  reading is deliberately not part of `healthSummary()`, so `db:replica-status` and
  `db:pgcat-flip --status` stay offline.

- **A running pgcat that cannot answer for a pool is now a fault the flip repairs, not one
  supervisord can hide.** The flipper's self-heal used to cover only the state supervisord can see
  — a program that is not RUNNING — and a pooler can be RUNNING and hold a configuration with no
  pool for a database the application is connected to, so every read to that pool fails while
  `supervisorctl status` calls pgcat healthy. A run that finds the mode already applied now asks
  the pooler about each pool the applied variant declares, over a PostgreSQL v3 startup packet and
  the first message back — a pooler that resolves a pool before it authenticates refuses one it
  holds no pool for, so the probe needs no credentials, no `psql` and no connection through the
  pool it is measuring — and re-applies the config when a pool is missing. A pooler that
  authenticates *first* answers every name, which is the limitation `PoolProbe` writes down beside
  its measurement, because a probe that cannot tell those apart must not read as a proof that every
  pool is there. `swrr.pgcat.pool_probe.enabled` arms it (off by
  default; `host`, `port` — pgcat's own convention rather than PostgreSQL's, whose server would
  answer every name in its own words — `user` and `timeout_ms` are its address and its bound), the answer is
  the pooler's own admission rather than this package's guess, and a pooler that answered nothing
  is reported rather than repaired, so a probe pointed at the wrong address cannot restart a
  healthy pooler. The run reports the new `repaired` kind, which exits `0` like a flip.

- **`db:doctor` and `db:pgcat-flip --status` now say which way round a stale pooler is, and the
outside-window case is called out by name.** The verdict behind `/health/db`'s `reader_window`
block — pgcat's own file against the mode the reader windows ask for — carries a `direction`
(`inside_window` or `outside_window`, null whenever there is nothing to be out of step with), and
the two surfaces that report it lead with it. `db:pgcat-flip --status` prints `reader_window`,
`pooler_config` and a `reader_window_state` row, whose two failures read `STALE (inside the window)`
and `STALE (outside the window)`, over the same words the flipper already uses; `db:doctor` gains a
thirteenth row, `pgcat mode`, in the `pgcat …` family, printing the flipper's own sentence under
that distinction — the sentence `/health/db` reports and logs, so the endpoint, the log line and the
preflight cannot drift apart. Both directions are one fault seen from opposite ends of the last
window, and which end it is decides what the operator looks at next: a writer-only file inside an
open window is the boundary flip at the window's *start* that did not land, while the readers config
outside every window is the one at its *end* that never moved it back. An armed flipper whose file
cannot be read is reported as *nothing compared* rather than as a pass — judging the file is
`pgcat files`' row — and an inert one has no file to disagree about. No key moves and no suggestion
line is added; a doctor run on an installation where `db` resolves has one more check in `counts`.

- **Nothing read the half of the scope rule that is about *place on a machine* — a file that names the
drive, the account or the share it was written on — and now a guard does.**
`tests/Unit/Support/RepoEscapesTest.php` walks every path the repository would carry and refuses the
ones that climb above the root; that is a different question from this one, and the two fail
differently — a climb stops resolving when the checkout moves, an absolute path resolves for ever,
for one person — which is why neither the climb reading nor its record ever described this one.
Neither shows up in a diff either, because both read as paths. `tests/Support/MachinePaths.php`
reads the files a commit would carry through the listing `RepoEscapes` already produces, over exactly
the patterns that make a path a *machine* path, while `tests/Unit/Support/MachinePathsTest.php` holds
it to the shapes it must leave alone: the Windows directory, a program's install root, a CI runner's
home, a tilde, the IDE's marker, and an ellipsis standing for the rest of a path. Every path the
reading reports in this tree named this machine, so each was rewritten to name nobody — in
`HANDOFF.md`, `PUSHING.md`, `.agents/README.md`, the three `pkg-*` skills under `.agents/skills/`,
`bin/composer-link.cmd`, `docs/pgcat-supervisor-preflight.md`, and the samples in
`src/Pgcat/SupervisorStep.php` and `tests/Unit/Pgcat/SupervisorStepTest.php`. `AGENTS.md` section 0
now names the reading beside the climb one, and `HANDOFF.md`'s environment table resolves the
interpreter through the machine file rather than spelling its path out.

### Changed

- **The behavioral guidelines a session writes by are a file of this repository, and the load order
  sends a reader to it before `README.md`.** `AGENTS.md` — the canonical rule set — gains the row in
  its authority table and the step in its numbered order that name `BEHAVE.mdc`: state the
  assumptions before implementing, prefer the simplest thing that solves the problem, touch only
  what the request needs, and define the check that says the change worked. The guidelines a change
  is written against therefore live in the tree rather than in a prompt, and `.gitattributes` holds
  the file out of the tarball beside `AGENTS.md`, the other rule file a consumer has no use for. No
  file under `src/` or `config/` moves, so nothing an installation reads changes.

- **Every file this package derives from its own record now names the commands that write it.**
  `bin/surface.php`'s one list (`derivedFiles()`) gains a `written_by` row per artefact, with
  `derivedWriters()` as the key-to-command vocabulary and `derivedBy()` as the other direction — so
  "what is derived?" and "with which command do I refresh it?" are answered by the same table rather
  than by reading three programs. `bin/release.php` writes every row that names it,
  `bin/inventory.php` the record and the page rendered from it, `bin/api.php` the page alone and it
  refuses a page whose row does not name it: the page's path is now something the page's own row
  says rather than one the renderer happens to know. A command missing from a row stops writing that
  file instead of half-writing the set, and `tests/Unit/Release/DerivedWritersTest.php` holds the
  declaration to the commands by measurement — a forged row in each of the three records, a marker in
  the page, and each command asked whether it was the one that repaired the file. No row the record
  or the page carries moves, which the same two `--check` runs report.

- **`db:pgcat-flip --dry-run`'s no-write promise is now read off the installation rather than off
  its own report.** The rehearsal is the one route in this package that writes while promising it
  changes nothing — the swap's temp file is created in the target's own directory and removed again,
  and the lock is taken and released — and the suite read that promise only the way the report makes
  it: the steps and their outcomes, and the flipper's own tests asserting each step's side of the
  line from a fresh fixture. Neither looked at the installation on both sides of one run, so neither
  could fail when a rehearsal rewrote the target, recorded the mode it did not apply, or left its
  probe behind and reported `remove temp done` anyway. `tests/Unit/Console/DbFlipPgcatDryRunTest.php`
  snapshots every file under the installation by the hash of its bytes — the two variants, the
  target, the state file, the stand-in supervisor — and the lock beside them, which is the one trace
  this design leaves on purpose: unchanged where the installation already had one, created empty
  where it did not. Its control is a real flip in a fixture built the same way, whose snapshot has to
  move, so a listing that read nothing cannot pass — and the rehearsal mutated to rename its probe
  over the target instead of removing it fails both promise tests with the target's hash in the diff,
  which is the report saying *nothing was renamed* while the file says otherwise.

- **Every exit `bin/release.php` can take without applying is now held to writing nothing, on the
  whole repository rather than on three of its files.** `RailRefusalsTest` drives the rails the rest
  of the suite was silent about and asserts part of the cost of a refusal — no tag, no promoted
  notes, no inventory — which is a reading taken from the outside: a refusal that rewrote
  `composer.json`, staged something, moved a ref or left a file behind would pass every one of them.
  The script is a precondition machine, so *a refusal costs nothing* is a claim about the whole
  repository, and that reading is now `tests/Support/RepositorySnapshot.php`: every file under the
  fixture's root except `.git`, by the hash of its bytes and by size and mtime — a directory walk, so
  a file a run leaves behind is in the reading by construction — and, where there is a `.git`, HEAD,
  the branch under it, every ref, the index, the porcelain and the reflog. It moved out of
  `tests/Unit/Release/DryRunTest.php`, where it was first taken, because two guards holding one
  promise have to take one reading: two readings would be two promises. `tests/Unit/Release/ExitPathsTest.php`
  applies it to the twenty-seven exits that are not the apply — the refusals, the three usage errors,
  the argument checks, the question a non-interactive shell cannot answer, and the seven dry runs,
  `--skip-ci` and declared versions among them — and each row asserts the exit code and a sentence the
  path is known by, so a mistyped fixture cannot keep the tree still for the wrong reason. The exits
  are read out of the script by the shape of the call and compared with the table, because the way a
  table like this rots is an exit nothing drives rather than a row that stops matching.

- **The handoff note's pending set, its unpushed count and its commit listing are rendered from git
  rather than written by hand.** `HANDOFF.md` is the one record here that describes a moment, and
  three parts of it change whenever the tree does: the files this checkout has that the register does
  not carry, how many commits no remote has and how they break down, and the most recent of those
  commits by id and subject. Every one of them is a question git answers, and each is now an output —
  `bin/handoff.php` writes the three blocks between `<!-- handoff:NAME -->` markers through the same
  readings the note's own guard takes in `tests/Support/Handoff.php`, so a re-take cannot state a set
  of files, a count or a listing the tree does not have. `php bin/handoff.php --check` compares the
  file with its derivation at the register the note *names* rather than at `HEAD` — a note describes a
  commit older than the one that lands it — and `--register=<commit>` re-takes at another; exit codes
  are 0 in step, 1 stale or unrenderable, 2 usage. The one input git cannot answer is the prose column
  of section 1's table, which is declared beside the renderer: a pending file with no line there is
  refused by name rather than rendered blank. Section 1 also stops stating the size of the pending set
  — a count of uncommitted paths is a fact about one working tree, so the sentence names the
  derivation (`git status --short`) and the guard holds it to that, failing a count written back in.
  Beside it, every count the guard reads is a declared pair of forms (`entry`, `path`, `file`), so a
  pattern is built from the declaration and reads the singular and the plural it has to accept, and
  the guard's own source is scanned for a form — or a pair — spelled out by hand instead. The
  renderer is `export-ignore`d, so no consumer tarball carries it.

- **The handoff note may now say that nothing is pending, which is what a re-take after the last
  batch says.** `Handoff::pending()` refused a section 1 whose table had no rows — *the half of the
  note that says what has not been committed yet has nothing in it* — which made the note un-takeable
  in the state a cycle ends in: every batch committed, nothing untracked, and a section whose honest
  answer is that there is nothing to name. The complaint was already made in the other direction, by
  the test that compares `uncarried()` — every path this checkout has that no commit carries — with
  the paths the section names: a file nobody names fails it whether the table has rows or not, so an
  empty table cannot hide one, and the refusal protected nothing while forbidding a state the note has
  to be able to describe. The prose column for the six files the batches had been carrying
  (`PENDING_DESCRIPTIONS`) went with them — a declaration is words about a *pending* file, and prose
  kept beside a table that cannot hold those rows is the stale copy the mechanism exists to refuse —
  and a path the register does not carry with no line for it is still refused by name.

### Fixed

- **The audit record is handed back writable, so a second account can write it — on any host.** The
  record is shared between boots that are routinely not the same account: the web worker that boots
  the application and the scheduled command that writes the same file are the ordinary pair. The swap
  that writes it lands it with the mode the temp file was created under — 0644 under the usual umask
  — so the second of them could not open it for writing at all, every boot after the first then
  refused the whole read-merge-write, and the probe the record is the throttle for was reported as
  one that cannot be remembered, on every boot, from the boot that found the file. The release now
  clears that: `BootAudit::makeRecordWritable()` runs as the lock goes back, and it is one call on
  both hosts rather than a branch — on Windows `chmod()` is the read-only attribute and all that host
  has of a mode, and a record left read-only is exactly what the acquirer's `fopen(…, 'c')` refuses.
  A filesystem that refuses the mode cannot turn a record that *was* written into a boot that failed,
  and a path the write removed — `write()` removes the record when the merged one has nothing left to
  remember — is left alone rather than created by a repair it did not ask for. Held by
  `BootAuditTest::test_the_release_hands_the_record_back_writable_to_the_next_boot`, which takes the
  shipped closure off a plain instance the way the concurrency child does, makes the record
  read-only, and holds the hand-back to that: the mode on a host where a mode is a permission, and
  the attribute on the host where it is not.

- **The handoff note's section 2 is rendered from the two commits its own table names rather than
  from the live `origin/dev`, so a push can no longer make a committed note stale.** The renderer
  read the remote tip off the ref, and a push moves that ref: the register's commits are then
  behind `origin/dev` instead of ahead of it, the count, the breakdown and the listing all render
  against a range that is empty, and `php bin/handoff.php --check` and the guard beside it fail on a
  tree where nothing about the note has changed. It was green on the machine that wrote the note and
  red on CI's runner, which is where it was found: the run for the push that carried the note, with
  `The register is not ahead of \`origin/dev\`, so there is no commit listing to render and the
  section would be written empty.` The register, the tip and the branch are now the snapshot the
  note *records* — the same pair the three comparisons beside it already read (`Handoff::basis()`) —
  so the derived rows are answers about the commit the note is describing rather than about whatever
  the ref has become, and a take reads the three facts off the checkout (`Handoff::take()`). The
  note's own `What the test above cannot ask` says what that costs, and said it before the reading
  matched it: the guard holds the commit the remote named and the commits between it and the basis,
  not the ref it sits on — a fetch that moved `origin/dev` does not fail it.

- **A branch with nothing unpushed is a state the handoff note is written in rather than one its
  renderer refuses.** `Handoff::unpushedCommits()` threw when the range was empty, so the note could
  not be re-taken on the tree a push leaves — `HEAD` and `origin/dev` the same commit — which is the
  state every take now starts in and the one the release that publishes the tag ends in. The section
  answers it instead: the `Unpushed` row keeps its number (`0 commits — \`git log --oneline
  origin/dev..HEAD\` prints nothing`), the breakdown row is `none` and is read back as an empty tally
  (`mix()`), and the listing is the sentence that says there is no listing to print rather than an
  empty fence (`listing()` reads it as a claim of nothing, and still refuses a section that says it
  lists none and lists one). The branch row is recorded with the rest of the snapshot, so a re-take
  from a detached checkout writes which state it was taken in instead of failing.

- **The pool probe's five tests bind the store they judge, so a machine running Redis is no longer
  what makes them pass.** The four of them that read the run's exit code never asserted on the
  `store reachability` row, and never bound a store: that row PINGs it, so on a workstation with a
  Redis listening on 127.0.0.1:6379 the row passed by accident and the tests were green, while on
  CI's runner — where nothing answers — the row failed and all four reported exit 1 with every row
  they do assert on reading correctly. `armPgcatWithPoolProbe()` now binds the store reachable, which
  is what its own promise that every other row judges a working installation costs, and the unarmed
  probe's test gets the same binding. Measured both ways: with the two bindings flipped to an
  unreachable store the four fail exactly as the runner reported them — the same test names, the
  same `Failed asserting that 1 is identical to 0` — and with them in place the five pass.

## 0.2.0-alpha2 - 2026-10-06

BREAKING: parts of this release change what an installation reads at run time rather than only how
the package is built, and each is named below.

`/health/db` answers `degraded`, HTTP 503, in a state it used to answer `ok` to — a pooler left on
the writer-only config inside an open reader window — so a monitor, a load-balancer health check or a
deploy gate pointed at that endpoint changes its answer for the same installation. The boot audit's
record line is triaged by `kept` and `keys_this_boot_read` rather than by the `discarded` and
`keys_on_disk` an alert rule written against the old line selects on.

Neither is a renamed class, a renamed config key or a removed method — `--weigh`'s public-API and
config signals are a minor — and the rest of this section is either additive or tooling this package
does not ship: `bin/`, `tests/`, `RELEASING.md`, `CHANGELOG.md` and the `*.tsv` records are all
`export-ignore`d, so a change there cannot reach an installation.

### Added

- **Nothing reported whether anything would move the pooler at a reader-window boundary, and now a
  preflight does.** The per-minute entry bounds *converging at boot* — on purpose, so a container
  that could not come up looks broken rather than busy — and it is a no-op for the rest of its life.
  A window opens at 10:00, the resolver moves to `readers`, nothing moves pgcat with it, and every
  surface reads well: `switch values` covers pgcat's three switches, `reader windows` judges the
  setting the resolver reads, and `pgcat files` and `pgcat supervisor` judge a flip that runs. None
  of them was asked whether one is *scheduled*, and the two settings that decide it —
  `swrr.pgcat.schedule.enabled` and `swrr.pgcat.schedule.windows.enabled` — were the pair nothing
  reported at all: a value that is neither on nor off was logged once at registration and read by
  no preflight.

  `db:doctor`'s `flip schedule` row is that reading, and both halves come from the class that owns
  them (`FlipSchedule::options()`, `WindowFlipSchedule::settings()`), so the row cannot disagree
  with the entry that runs and the tasks that register. It **fails** on either switch written as
  something that is not on or off, naming the value, the accepted spellings and the value the
  setting falls back to. It *warns* when the reader-window tasks are on and no boundary is scheduled
  — every window unusable, or shorter than the lead and the grace — and when the flipper is armed
  with neither mechanism on, which leaves pgcat's file where the last write put it while the
  resolver's mode moves on. On a run that reads cleanly it names which mechanism answers a boundary,
  and both on is reported as what it is rather than as a fault. `DbDoctorTest` pins the states and
  both refusals.

- **The tools this package installs are written down once, and three readers are held to the one
  copy.** The vendor paths existed twice and nothing compared them: `composer.json`'s scripts named
  the tools bare, for Composer to resolve against `vendor/bin`, and `bin/checks.php` held the same
  four as `$root . '/vendor/…'` strings. A package that moved its binary, or a tool that started
  arriving from another package, would then leave `composer lint` and `composer checks` running two
  builds of one tool — findable only by reading both files and noticing.

  `bin/tools.php` is the manifest: the composer package each tool ships in, the constraint
  `composer.json`'s `require-dev` asks that package to be at, the entry file PHP runs, and one
  sentence on what the tool is for. `bin/tool.php` runs the composer scripts from it, so `composer
  lint`, `composer test:lint`, `composer test:types` and `composer test:unit` name a tool rather
  than a path. `bin/checks.php` reads the same records, both for the paths its own commands run and
  for a new `tools` check that reports each installation against the pin its manifest states — the
  half a test cannot see, because what is installed is the machine's rather than the tree's.
  `ToolTableTest` holds the manifest to the README's tools table and to `require-dev`, cell by cell
  and in both directions for the pins, so a constraint raised in one file and left alone in the
  other stops the suite instead of quietly running the older tool.

- **A pooler out of step with the reader windows is now a failure, not a silent one — inside a
  window and outside one.** The boot window answers whether a container came up; it cannot answer whether the
  pooler is still tracking the day. A container booted outside a reader window converges to
  writer-only and looks perfect — one run, `converged: true`, `window.failed: false` — and then
  10:00 arrives, the windows move the resolver to `readers`, and nothing moves pgcat with it. Every
  read reaches the writer while `/health/db` answers `ok`, because a pooler that answers is not a
  pooler that is right. The mirror image is the same failure: with the readers config left in the
  file outside a window, every read through the pooler reaches a replica during the hours the
  fallback exists to keep them on the writer — and it is the same evidence, that a boundary flip
  stopped landing.

  **What this asks of an installation, and why this release says it is breaking:** a monitor, a
  load-balancer health check or a deploy gate pointed at `/health/db` now fails where it used to
  pass, for the disagreements below. Whether that endpoint should gate a deployment is a decision to
  make rather than one the package can make for you.

  `PgcatConfigFlipper::readerWindowVerdict()` is the second answer, carried in the flipper's own
  snapshot as `reader_window` and reported by `/health/db` as a top-level block that also feeds the
  status: `expected` is what the resolver says now, `applied` is what pgcat's file actually holds,
  and `failed` marks either disagreement — the file holding the variant the windows are not
  asking for, whichever way round it is. `applied` is read off pgcat's file (`appliedMode()`) rather than off
  the state file's `last_mode`, deliberately: a flip that aborted before it recorded anything still
  left a file behind, and `last_mode` is exactly the field that is empty in that case — the shape
  the 2026-10-02 container arrived in, where a per-minute `schedule:run` was aborting on a missing
  cache lock before it ever reached the flipper, and a `last_mode`-based check would have answered
  "unknown" while the pooler was provably writer-only.  The verdict is symmetric on purpose. The file and the windows are two statements about one
  routing decision, and a stale one is stale whichever way round the disagreement is: readers left
  in the file *outside* a window mean the mode the windows asked for at the end of the last window
  is the one that never arrived, and the file is the evidence of that. A file matching neither
  variant (an operator's edit, a half-written copy) is `in_step: null` rather than an invented
  verdict, and the check is not judged at all on an inert flipper or a permissive resolver, where
  `applicable` says so.

- **The reader windows are scheduled tasks, and a mode change waits until the thing it points at
  answers.** The per-minute flip converges the pool at container start and then stops at the end of
  the boot window, deliberately — that is what makes a container that could not come up look broken
  rather than busy. It is also why nothing moves the pool at 10:00: a container booted at 03:00 has
  converged to writer-only and, by the time the first window opens, is no longer allowed to change
  its mind.

  `Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule` is the other half. The provider registers two
  tasks per window in `swrr.reader_windows` — `activate_readers:{start}` and
  `deactivate_readers:{end}` — the first time the container's `Schedule` is resolved, each firing
  once a minute from `lead_seconds` before its boundary to `grace_seconds` after it. Every firing
  runs the new `db:pgcat-window-flip`, which resolves the boundary nearest the run, asks the mode's
  target with one `SELECT 1` on a throwaway connection
  (`Uak35\WeightedDbManager\Database\Weighted\ReadinessProbe`, built on the `SingleHostProbe` that
  `db:probe-replicas` now shares rather than keeping a copy of), and applies the mode through the
  flipper's forced path — no boot window, and no recorded run, so a mode applied at 10:00 cannot
  move the convergence verdict `/health/db` reports. A target that is not answering yet is asked
  again on the following minutes rather than given up for the day, and one that never answers
  leaves the pool where it was and exits `1`, which is the one outcome a scheduler has to see.

  `db-manager.swrr.pgcat.schedule.windows` is off by default, and an installation that turns it on
  should turn `db-manager.swrr.pgcat.schedule.enabled` off in the same change: two mechanisms
  deciding one mode can disagree. The README documents the settings, the exit code of every route
  and the kind table, which `DbWindowFlipCommandTest` reads back — and it is the matrix that
  earned the command its row in `docs/documented-exit-codes.md`, because a task that runs eight
  times per boundary must not put eight error lines in a log for doing its job.

  Each event also carries a `when()` filter built from its own window, evaluated by `schedule:run`
  before the command is spawned. A cron minute field cannot hold "52 to 8 of the next hour", so a
  range that crosses one is written as the union of the minutes on both sides and its expression
  matches twenty-five firings a day where the boundary is for seventeen — minute 52 of hour 9 is
  also matched by minute 0 of hour 9, because cron multiplies the two fields. The filter is what
  narrows it back: a surplus minute costs a closure call and nothing else — no process, no probe,
  no log line — where before it was a whole process whose only job was to report itself early or
  expired. `WindowFlipScheduleTest` pins the filter's arithmetic (including a range that wraps past
  midnight) and its per-event wiring, and the expression's own wider reach is still pinned beside
  it.

- **The flip schedules itself, with a cadence the installation chooses.** `db:pgcat-flip` was the
  package's command and the application's entry to write: the provider now registers it, the first
  time the container's `Schedule` is resolved, so there is no entry to write into
  `routes/console.php` and none to forget to write. `Uak35\WeightedDbManager\Pgcat\FlipSchedule`
  holds the entry and `swrr.pgcat.schedule` holds the settings (`enabled`, `interval_minutes`,
  `name`, `log`), and two Laravel conveniences are deliberately absent because the flipper is
  container-local: `onOneServer()` would let one container flip per minute and skip every other,
  and the mutex `withoutOverlapping()` names by itself is `sha1(expression + command)` — the same
  name in every container when the cache store is shared. The mutex is scoped to the container
  instead, and the authoritative serialisation stays the flipper's own `flock`.

  `interval_minutes` is a trade rather than a saving, and the record says so: what bounds the
  attempts is `flip_window_seconds`, not the cadence — eight minutes holds eight runs at one
  minute and two at five. A value the minute field cannot hold (zero, a negative, a non-number, or
  more than `59`, past which a step *wraps* rather than meaning what it says) resolves to the
  documented default rather than being written into an expression that means something else.
  `FlipScheduleTest` pins the cadence, the switch, the refusal, the mutex name and the duplicate
  guard, and the wrap itself is asserted through the cron library's own `nextRunDate()` rather than
  taken on trust.

- **The inventory publishes a public API page, so the rows a release weighs are also the page a
  consumer reads.** `API.md` is rendered from `files.tsv`, `methods.tsv` and `surface.tsv` by
  `php bin/api.php`: every class with its public methods and their argument shapes, the config keys
  and environment variables the package reads, and the constants and properties a host can name —
  grouped by namespace, and by the file that declares it for the configuration. Nothing asks the
  tree a second time, so the page cannot describe a different package from the one the release
  weighed; a run refuses when the three files do not describe one tree, and when there is no record
  to render rather than publishing an empty API. `--check` is the read-only half and the command a
  pipeline can call.

  `PublicApiReportTest` runs the generator backwards: the bytes on disk are compared with the bytes
  the record produces, every class and every method row has to have reached the page, and the counts
  the page prints have to be the record's own — so a row that stopped being rendered fails a test
  rather than living on a page until somebody notices. The README's layout lists the page and the
  command, and `docs/inventory-surface-rows.md` records the decision, which is the candidate that
  record had been holding open.

- **The audit's live half has alert rules, and both halves are run rather than described.**
  `audit.severity` is the record — what the installation's boots reported — and `audit.current` is the
  same settings read in the process answering the request, which the payload has carried since the two
  readings were published. What was missing was the rule an operator writes against the second one:
  `severity == "error"` pages an instance for an entry another boot wrote, and the two cases it has to
  tell apart — a finding this process re-derived, and one recorded in another scope — were only
  visible to somebody who walked `findings[].current` themselves.

  The README's cookbook now has the live half as its own table and two paste-able rules: the `jq`
  gate that pages only on `audit.current.severity`, so a record-only entry stays a ticket, and a
  `jq -r` triage line that prints the scope each non-standing finding was written in, which is what an
  operator needs before anything is done about it. `docs/boot-audit-surfaces.md` records why the
  record's `severity` and the live half's are two fields rather than one.

  Both rules are run rather than read. `ReadmeAlertingTest` builds two real payloads — one whose
  record holds a foreign-scope reader-window refusal while the live half is clean, one whose record
  holds a fallback refusal this process re-derives — and asserts the gate stays quiet on the first and
  pages on the second, so a rule that regressed to the record alone fails here instead of paging the
  wrong host. The new case needs the audit singleton rebuilt per payload, which is why the fixture now
  forgets it.

- **The gate reads the records as prose, and fails when a sentence boundary has lost its space.**
  `…written by the release.Diffing it is…` is a word the language does not have, and it is what an
  edit that eats a space leaves at the one place a reader cannot recover it from: nothing else in
  the  gate has an opinion about a paragraph — `php -l` reads code, the suite asserts behaviour, and the
  records' whole prose is one copy-paste away from the shape at all times. The new `sentences`
  check in `bin/checks.php` reads `README.md`, `RELEASING.md` and every
  `docs/*.md` — the same set the citations guard reads — and reports a terminator touching the
  capitalised word that begins the next sentence, with the file, the line and the join, so the
  repair is in the report rather than in a hunt.

  The width of the rule is a measurement rather than a preference. "A capital after a dot" reads
  `production.ERROR` twice in these records and `/var/run/postgresql/.s.PGSQL.5432` in the
  changelog — names, not sentences — so the capital has to be followed by a lowercase letter, and
  that is the price: a fusion whose next word is all capitals (`release.PHP`) is not reported. What
  is read is prose, so fenced blocks are dropped first — a fence holds `$_.Subject` and
  `'. '.ClassName`, which a sentence rule reports — and the lines are blanked rather than removed,
  so a finding's line number is the file's own. The CHANGELOG is not read at all: everything below
  its `## Unreleased` heading is a published record and RELEASING.md leaves every released section
  byte for byte alone, so a finding there would have no repair that is not an edit to a release.

  Twenty fixtures run before the records are, because a detector that has stopped matching reports
  an empty result — the rule the write-back register already follows: nine fusions (`.Diffing`,
  `?The`, `!Writing`, a closing bracket, a quotation, an inline code span, emphasis, a version's
  last digit, and prose after a fence) and eleven shapes it has to leave alone, the dotted config
  key, the socket path, a filename, a version, a URL and a wrapped sentence among them, plus the
  all-capitals fusion it cannot tell from a name. Four mutations are measured against it: a fused
  sentence injected into `README.md` fails the check naming the line, and un-skipping the fences,
  widening the rule to any capital, or dropping `?` and `!` each fails its own fixture by name. The
  tree is byte-identical afterwards.

- **The inventory generator can describe any git ref, so a tag with no written record can be
  backfilled.** `files.tsv`, `methods.tsv` and `surface.tsv` are the one artefact a release
  writes and the next release reads, and until now the only way to write them was from the
  working tree: the rows came off the disk and the stamp was the latest tag, a pair that is
  right only while the checkout *is* the tagged tree. That is the state a release commit is in,
  and not the state a tag whose record was never written is in — it is behind the checkout by
  however many unreleased commits, so a hand run describes the tree in front of it and names
  the tag, and so describes neither. `php bin/inventory.php --at=REF` reads its rows out of git
  — `ls-tree` and a `show` per file, the two calls the surface's tag diff makes — and stamps
  what it writes with the ref's own tag, with the release tag on the commit it names, or with
  `(no tag)` when no release names it. The rows and the stamp then come from one tree, which is
  what makes them evidence rather than a claim. A ref is any ref — a tag, a branch, a commit,
  `HEAD~3` — an uncommitted edit in the checkout is invisible to it, and `--check --at=REF` is
  the read-only half: one record compared with the tree a past tag holds, which is how a set of
  tags that predate the file is audited in a loop.

  Reading a ref is also the one case where the record already on disk can belong to a different
  release, and one slot cannot hold two, so a run is refused when a stamp on disk is not the
  release it describes — the refusal names the stamp it read and the one it would write, and
  the files carrying it. The harm there is quiet rather than loud: a substituted record is not
  an error anybody sees, it is a second opinion that stops being weighed, which is why the
  escape (`--force`) has to be asked for. A file that is not there carries no stamp to disagree
  with, so a half-written set is completed rather than refused — an interrupted release's two
  files, and the two-file inventory every tree written before `surface.tsv` existed, which is
  the state this package's own repository is in. The flags that could not change anything are
  usage errors rather than silent no-ops: an `--at=` naming no ref, and a `--force` on a run
  that writes nothing, which is what a `--check` is and what a plain run without it already
  does.

  Measured against eight mutations: the ref reader reading the checkout, the stamp coming from
  the latest tag instead of the ref, the refusal disabled, a missing file counted as a differing
  stamp, `--at` parsed and then ignored for the rows, the drift header no longer naming the tree
  the ref holds, the empty `--at=` guard removed, and the `--force` guard removed — each fails its
  own test by name, and the tree is byte-identical afterwards. [RELEASING.md](RELEASING.md#the-inventory) gains the subsection,
  [README.md](README.md) the line among the `bin/` scripts, and
  [docs/inventory-surface-rows.md](docs/inventory-surface-rows.md) the limitation that rebuilding
  a past tag's rows still leaves one record in one slot.

- **The weighing's two quiet halves are pinned by the headings and the keys they weigh, so a
  severity cannot be edited by accident.** `changelogSignal()` maps a `###` heading onto a rung —
  `Added`/`Changed`/`Deprecated` a minor, `Fixed`/`Security` a patch — and three of those six rows
  were exercised by nothing: turning `### Changed` into a patch, `### Deprecated` into a patch or
  `### Security` into a minor left the whole suite green, and the table is the policy's own statement
  of what a heading is worth, so a row nothing reads is a severity that moves by accident. The same
  was true of the two ways a release note can shout louder than its heading — a `### Breaking
  changes` heading, and a `BREAKING` marker in the body, which is the spelling a changelog may use
  in place of one — and of the surface's config half, where a dropped key was pinned at the evidence
  line and not at the severity it produces.

  The four cases drive `bin/release.php` in a real repository rather than a helper: one walks every
  heading the policy names from a `v0.4.0` tag and asserts both the bump line *and* the next version,
  one weighs the two breaking spellings, one writes a heading the policy does not know and asserts
  that it weighs a patch and says so (`the Unreleased section has no \`###\` heading the policy knows`
  beside `not a Keep a Changelog heading, so read as a patch: ### Notes`, which is also the case that
  tells an unknown vocabulary from an empty section), and one drops a config key under a `feat!:`
  commit and asserts the `breaking config 1 change(s)` row *and* the `patch CHANGELOG ### Fixed — 1
  entry` row, so the config half of the surface is pinned at its severity and not only at the
  sentence that names it. Six mutations are measured against them — `### Changed` weighed a patch,
  `### Deprecated` a patch, `### Security` a minor, the `BREAKING` marker read as a heading rather
  than as a claim, an unknown heading read as empty, and the config half's severity read as a patch —
  and each fails its own case.
- **A sentence elsewhere in the README that restates `db:pgcat-flip`'s exit table is now compared
  with the row it paraphrases, so a paraphrase cannot drift from the contract it restates.** The
  table under [Flipping](README.md#flipping-dbpgcat-flip) is the contract and it is bound to the
  matrix — but it is not the only place the README states a code: the rehearsal recipe says a
  `--dry-run` gate exits `0` when a flip would happen and `1` when a step a flip needs did not work,
  the boot-window bullet says a closed window exits `0`, and the pgcat snapshot's row for a disarmed
  flipper says it exits `0` without entering the watch loop. Each is written for the reader who is
  *in* that section — `--dry-run` is a deploy gate, so its reader is writing a pipeline — and each
  is a restatement, which means a number that changed in the table could stay wrong in three places
  nothing was reading. The failure is quiet from both ends: the sentence still reads as an argument
  about what a scheduler should do, and the table it forwards to is still right.

  `DbFlipPgcatCommandTest::paraphrases()` declares each sentence with the row it restates, and
  `test_the_prose_that_restates_the_table_states_the_same_codes` reads the section it lives in,
  matches the sentence with its whitespace collapsed, and compares the code it writes with the code
  the row documents. The number is the only part of a phrase not written literally, so a reworded
  sentence fails here rather than leaving the claim unchecked — the failure mode `ProseNumbersTest`
  exists for one record over — while a reflowed one is a reflow. The comparison is against the table
  rather than the matrix, because the table is what an operator reads and it is already bound to the
  cells: the chain reads sentence → table → matrix, and each link was measured. A wrong code in the
  table fails both guards by name (two failures); a wrong code in a sentence fails this one alone;
  and rewording a sentence fails it with the phrase printed, so a sentence that has moved is a
  change to the test as well as to the README.

- **The surface says when one name is declared by more than one file, which is the one change it
  cannot see.** The surface is keyed by name, so two files declaring one config key is a single entry
  in it, from the first of them read, and the second file's declaration is in neither map — so a change
  to that second declaration moves nothing the public-API signal can observe: the name is held by the
  file that did not change, at both ends of the diff. The plan was silent about that in exactly the way
  it is silent when nothing moved, which is the difference this closes. This package is in that state:
  `config:timezone` comes from both `config/app.php` and `config/db-manager.php`, so dropping it from
  the second file is a change no signal reports. `--weigh` now carries a note naming the names and the
  files behind each one — up to three, then a count — and pointing at the inventory, whose rows carry
  the file they came from: that is what writes both declarations down. Its verdict is a name-keyed one
  too, for the same reason the tag diff's is, and the note says so rather than promising a signal that
  cannot deliver — the file is what keeps the two apart, and finding the change in it is `git diff`
  away.

  The note belongs to the plan; `bin/blame.php`, which prints the weighing's notes verbatim, answers
  the same question for the one name it was asked about instead, in a `Shadowed names` section — one
  fact, said once per reader, in the terms each of them is answering in. The registries are kept per
  side rather than per surface: folding the tree's and the tag's into one would report every symbol in
  the package as declared twice, by the same file both times.

  A second fold of the same shape did show up under the audit: `surfaceRowDiff()` keys the inventory's
  rows by `kind:symbol` without the file, so a key two files return is one name there as well and the
  inventory's *verdict* cannot see one of the declarations going either. That one is kept — a name is
  what a consumer imports, and the file column is what makes a row a row — so it is documented rather
  than changed, and the note is the thing that says it out loud.

  Pinned by three cases in `BumpWeighingTest`
  (`test_a_key_two_files_declare_is_the_change_no_surface_signal_can_see`,
  `test_a_surface_whose_names_all_come_from_one_file_says_nothing`,
  `test_the_note_names_three_names_and_counts_the_rest`) and two in `BlameTest`
  (`test_a_name_two_files_declare_says_which_file_the_surface_holds`,
  `test_a_name_one_file_declares_is_not_shadowed`). Seven mutations are measured against it: an
  unfilled registry fails three tests, one registry for both sides fails three, recording only the last
  file fails three, reporting every symbol fails one, showing two names instead of three fails one,
  never printing the plan's note fails two, blame reporting a name one file declares fails one, and
  dropping the `nothing to say` early return fails one: the quiet case asserts the note's absence by
  the sentence's own opening line rather than by one clause of it, so a note that fired on every
  release fails something.

- **The inventory records what a consumer can name besides the files and the methods — config keys,
  env vars, public constants, enum cases and public properties — in a third file, `surface.tsv`.**
  The inventory is the one versioning signal that does not need a tag: `bin/release.php` diffs the
  public surface of `src/` and `config/` against the last tag, so a tree with no tag at all has
  nothing for that signal to see a removal in, and the weighing says so ("the notes decide"). What
  it could not see even with a tag is the *moment*: a config key removed in an unreleased commit and
  a key removed before the tag are one diff to it. A stored row is its own "before", which is why
  these are written down at all — an installation that sets `swrr.…` learns a key is gone when the
  key stops being read, and there was nothing in the package that could tell it sooner. The rows are
  `kind`, `symbol`, `file`, one per line like the other two files, and the kind is the word the
  report uses, so a removal reads as `removed public constant Fixture\Thing::VERSION`.

  A third file changes what a missing one means, so the inventory is now weighed **as a whole or not
  at all**: a file that is not there cannot say whether the rows it should hold were never written or
  were just removed, and reading it as an empty one would report every config key and constant in the
  tree as added since the last release — a minor nobody asked for. A tree whose inventory predates
  this file is told its inventory is *incomplete* and the notes and the tag diff carry the weighing,
  which is the state this repository is in until the next release writes all three.  `bin/release.php`
  writes and commits them together, stamped with the tag it creates, and `bin/inventory.php --check`
  compares and reports all three.

  Writing the rows exposed a fault in the symbol reader they go through: a declared type stands
  between the visibility and the variable it modifies (`public string $label`), and the reader walked
  back from the variable and stopped at the type name — so every **typed** property was invisible, to
  this file *and* to the public-API signal that shares the reader. Fourteen are visible in this
  package now, and a typed property in a consumer's class is caught as a removal where it used to be
  invisible to both. Pinned by three cases in `InventoryTest`
  (`test_the_surface_rows_record_the_keys_and_members_a_tag_diff_cannot`,
  `test_check_reports_a_constant_the_tree_no_longer_declares`,
  `test_check_reports_a_config_key_the_file_no_longer_returns`) and two in `BumpWeighingTest`
  (`test_a_constant_removed_with_no_tag_at_all_is_witnessed_by_the_inventory_alone`,
  `test_an_inventory_missing_one_of_its_files_is_incomplete_and_never_moves_the_bump`), and each rule
  is measured against its own regression: not reporting a removed row fails one test, reading an
  incomplete inventory as an empty one fails one, and restoring the typed-property behaviour fails
  two.

- **`php bin/blame.php SYMBOL` reports which of the four signals names a class, a method, a constant
  or a config key, and what that signal contributed to the bump.** The plan answers "what version is
  next"; this answers the question a contributor has afterwards — a `feat:` commit with no changelog
  entry, a changelog entry with no surface change, a public method that vanished — and the two cannot
  disagree about the bump, because the command calls the same `weigh()` the plan does, from the same
  tree. Each signal is reported as `caught` or `quiet` with the severity it weighed, and each half of
  the surface is asked twice: once about the lines it changed, and once about whether it holds the
  name at all. That second question is the one that stops a symbol being *unchanged since the last
  tag* from looking like a symbol nothing could find — the two are the same silence otherwise.

  Named and responsible are kept apart, which is the distinction the report is built on: a `docs:`
  commit that mentions the symbol is `caught` at a patch while the `### Added` heading above it
  weighs the minor, so the contribution paragraph says the bump came from elsewhere and lists what did
  carry it. The notes are searched as entries rather than as headings — a heading is what weighs and
  the entry is what names — and a wrapped entry is joined into one before it is matched, so a mention
  on its third line is not reported as a fragment that starts mid-word. The match is a plain
  `contains`, not a pattern: quoting, a leading `\` and a trailing `()` are stripped and nothing else
  is, so `weight` finds `weighed`, `weighting` and `weigh()` at once. There are no preconditions — no
  branch, no dirty-tree rail, no CI, because a question about one symbol is asked mid-edit — and the
  exit code is the scripted form of the answer: `0` something names it, `1` nothing does, `2` a usage
  error.

  What a weighing *is* now lives in a file of its own. `bin/weighing.php` holds `parseVersion()`,
  `bumpFor()`, `weigh()`, the four readers, and the changelog section helpers, moved out of
  `bin/release.php` byte for byte with no function name declared in both. That is what makes the two
  commands one policy rather than two copies of one: a change to how the surface is diffed or the
  notes are weighed cannot reach one and miss the other. `bin/release.php` keeps the command — the
  arguments, the preconditions, the plan and the tag — and its own behaviour is unchanged. Pinned by
  sixteen cases in `BlameTest`, from a symbol two signals name at the top severity to the four ways
  the name can be typed and the three exit codes, and each rule is measured against its own
  regression: reading any naming as responsibility fails one test, searching the notes as headings
  only fails one, deriving the diagnosis from the evidence rather than from the two surface maps
  fails two, dropping the two surfaces from the exit code fails four, taking the name exactly as
  typed fails one, and ignoring an unknown option fails one.

- **A release is refused when the public surface changed and the Unreleased notes do not account
  for it, so a change cannot ship with no release note.** Every other rail is satisfied by a
  section that is merely not empty, and the notes are the one signal a reader ever sees: a new
  public method filed under `### Fixed` weighs a patch, `--weigh` takes the bump from the surface
  signal instead, and the changelog then announces a fix while a consumer gained something to use.
  The comparison is the notes' own severity against the loudest signal that reads the surface, so
  a removal needs a `### Removed` entry and the refusal says which heading to use — the severity
  comparison rather than a search of the entries for symbol names, because a release note that has
  to spell every class it mentions is one authors route around. The refusal names the symbols, so
  the missing note can be written from it, and the surface includes the inventory, which is the
  only witness to a row the tree no longer backs on a release with a tag. `--allow-silent-notes`
  releases anyway and prints what it let through, like every other escape here; `--dry-run` reports
  the state and refuses nothing, like the dirty-tree and CI rails. On this package the rail is
  silent — `### Added` and the surface are both a minor — and it changes no other release's
  outcome. Pinned by nine cases in `SilentNotesTest`.

### Fixed

- **The notes said the per-minute flip would undo a window task's work, and it cannot.**
  `config/db-manager.php` and the README's reader-window section both told a reader that the two
  mechanisms "can disagree" and that the per-minute entry "would undo a window task's work the next
  time it fires". It would not: `applyCurrentState()` checks the boot window before it takes its
  lock, so once a container has converged every later run returns `window_closed` without reading or
  writing a file — and inside the boot window the two read the same resolver, with the flipper's own
  `flock` serialising them. What the per-minute entry has left after the boot window is a second
  process a minute and a second line in the log; what the tasks have is the mode applied through
  `forceMode()`, which records no run. The advice to keep one of them stands — one mechanism owning
  the mode is easier to reason about, and only one of them can describe a mode change as a
  convergence — and the reason it gives is now the true one.

- **A backup file an editor leaves beside a script in `bin/` is ignorable.** An editor that writes
  `checks.php.bak` beside the file it is editing leaves it under `bin/`, where nothing names it and a
  later `git add bin` picks it up with the script. The `.gitignore` now carries `/bin/*.bak` beside
  the rules for the other artifacts this repository produces. Nothing here writes such a file
  itself — the pattern is for the editor — so it changes no run of anything and only removes the
  chance of one arriving by accident.

- **`PUSHING.md` tells a reader to `cd` to where the package is.** The `git push` block named
  `LPR\side-projects\laravel-weighted-dbmanager`, a directory the package left when it moved to
  `LPR\packages\laravel-weighted-dbmanager`. The block is written to be pasted as it stands, so a
  path that is not there is a paste that fails before anything is pushed; it now names the directory
  the package is at, and no other path in the file's commands moves.

- **The package's own app skeleton names the provider that exists.** `config/app.php` replaces
  Laravel's `DatabaseServiceProvider` with this package's, and the class it named was
  `\App\Providers\WeightedDatabaseServiceProvider` — a provider of the application the package was
  built beside, which resolves nowhere here: `composer.json` autoloads one prefix,
  `Uak35\WeightedDbManager\` from `src/`, and nothing maps `App\`. The replacement now names
  `\Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider`. Nothing in this repository
  loads the file — `bin/` and `tests/` run the package through its own autoloader and never boot the
  skeleton — so no gate here reads it, which is the shape a dangling reference takes when it is an
  application config rather than a class the suite exercises.

- **The `BREAKING` marker is read where a changelog writes one, instead of wherever the word
  appears.** `changelogSignal()` asked `str_contains($unreleased, 'BREAKING')`, and the notes in
  this repository are the case that found it: the entry above this one, which documents the marker,
  quotes the word mid-sentence, so the notes that *describe* the marker marked their own Unreleased
  section breaking. On this tree that read as `minor  (weighed: a breaking change, which is a minor
  while the package is pre-1.0)` from a claim nobody had made — and the day a 1.0 line exists, the
  same sentence is the major the weighing is there to refuse, which is what makes a rule of this
  rather than a rewording.

  `breakingClaim()` is that rule. The marker weighs where a note makes a claim of it — at the start
  of a line's prose, after the bullet, the emphasis or the quote it opens with — and not where it is
  being named: a code span and a fenced block are removed with the word they show, so an entry is
  stripped of what it is exhibiting and kept for what it says. The half it cannot do is read the
  sentence, and that half is deliberate: a claim written mid-sentence weighs nothing, and the remedy
  is the one a marker has always had — give it its own line. `RELEASING.md`'s signal table now says
  so beside the heading the marker is an alternative to.

  Measured on this tree: `php bin/release.php --weigh --dry-run --branch=dev` answered
  `minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)` before the
  change, with a `breaking CHANGELOG` row, and `minor  (weighed: a minor change)` after it, with
  that row gone and the `### Added` heading the loudest one. The version is 0.2.0 either
  way, because what moved is the claim the plan makes about the notes. Pinned by two cases in
  `BumpWeighingTest`: `test_a_note_that_only_names_the_marker_weighs_the_heading_it_sits_under`
  drives the three mentions that used to trip it — a quoted word mid-sentence, a bullet naming it,
  and a fenced example — and asserts the patch their headings weigh, and
  `test_the_marker_weighs_breaking_under_the_decoration_a_note_writes_it_with` drives the three
  claim spellings that must still weigh a major, so the rule cannot narrow the marker to a bare line
  nobody writes. Restoring `str_contains()` fails the first case by name.

- **A method's recorded shape counts its arguments, one part each, instead of the whole list as
  one.** `parameterShape()` splits a parameter list on a comma at nesting depth zero, and inside a
  parameter list the depth is one — the opening parenthesis is what set it — so no comma ever split
  anything: every method was recorded as one part, `1/1` for a signature whose every argument was
  required and `0/1` for one with a default anywhere. That number is what `describeChange()` reads
  to tell an added required argument, which breaks every caller, from an added optional one, so the
  sentence it printed — "needs N required argument(s), was M" — was about a value that could not
  move. The fix is the depth the comma is compared against, and the separator the parts are joined
  with so a shape reads the way the declaration was written. Forty-three rows of `methods.tsv`
  changed and neither of the other two files did, which is the shape of the defect: it was the
  method list and nothing else.

  Rendering the new API page is what surfaced it — a page makes a wrong number visible where a
  signal that compares two rows cannot tell a wrong count from a changed method — and the reader is
  now pinned directly by `SurfaceReaderTest`, because a fixture package can only say that a row
  changed, and a wrong count is a plausible string.

- **`db:doctor --config-file` judges a candidate read list, so a weight the next boot would refuse
  cannot pass the vet.** The vet read one `config/db-manager.php` and reported the switches and
  reader windows it would be refused for; the read list whose replicas are weighted lives in
  `config/database.php`, so the file a deploy actually changes was the one the mode could not look
  at. `--config-file` now accepts either shape: a `swrr` block is judged for its switches and reader
  windows, a `connections` block for the read list of the connection named on the command line
  (`pgsql` by default), and a file holding both for both.

  The new row is the installation row's rule rather than a second one. It asks
  `Support\ReplicaMetadata::refusals()` — the pure classifier the boot audit refuses on and the
  `replica metadata` row already reads — about the connection's `read` list, so a weight, core count
  or memory figure the next boot would refuse fails the vet here, with the same sentence and the
  same named replica. A `weight: 0` drain is named on the pass, as it is there, because the package
  means it. What the candidate row does *not* report is the pool's arithmetic — no size, no total
  weight, no exclusion — because a file has no pool until it is booted; the vet's "refusals, not
  warnings" line is drawn at the resolver, and this row is on the refusals side of it. A
  `connections` block that does not define the connection the run named fails the `config file` row,
  naming the connections the file does define rather than passing a read list it never found.

  Pinned by four cases in `DbDoctorTest` — the refusal of a candidate read list, the pass that names
  a drain, the wrong connection, and the JSON envelope for the new shape — and documented in
  `docs/replica-metadata-refusal.md` and the README's vetting section.

- **The weighing refuses a release on a half-written inventory instead of silently weighing three
  signals.** The fourth signal is the only one that carries the *file* a declaration came from, so
  it is the only thing that can witness a removal a second file's declaration hides — and the only
  thing that can witness anything at all on a tree with no tag. An inventory with one of its three
  files missing was reported as incomplete and skipped, which is under-weighing, and that is the
  one direction a version signal must never be wrong in: this repository's own `files.tsv` and
  `methods.tsv` sat stamped `v0.2.0-alpha1` with no `surface.tsv` beside them, so every weighing
  the package's own development did ran with the second opinion silent and said so only in a note.

  The rule is narrow on purpose, and the narrowness is the decision. A *partly present* record is
  the one state that is unambiguously a lost file: at least one file carries a stamp, so a previous
  release demonstrably published the record, and a file missing from it cannot be "never written".
  An inventory that is *entirely* absent is a tree that has never written one — what every checkout
  is before the release that writes them, and what a first release is — so refusing it would make
  the record a precondition of ever creating it. A stale stamp is repaired by the release that
  proceeds, so refusing it would block an ordinary between-releases state. The refusal names the
  missing file and the one command that repairs it — `php bin/inventory.php --at=<the stamp the
  record carries>` — because a plain `bin/inventory.php` writes the working tree's rows and stamps
  them with a tag that never held them, which is the hand-regeneration the stamp rule exists to
  catch. A dry run reports the state instead of refusing, like every other rail, because a plan
  publishes nothing.

  Pinned by four cases in `BumpWeighingTest` — the refusal on a real run, the same state on a
  plan, the entirely absent record that still releases, and the named repair driven end to end
  until the refused release proceeds with the record weighed — and the limit is stated rather
  than hidden: deleting all three files produces the absent state, which is reported and not
  refused, since the files alone cannot tell that from a tree that never adopted the
  inventory.
  `bin/release.php` and `bin/weighing.php` carry the rule,
  [RELEASING.md](RELEASING.md#a-record-that-is-partly-there-stops-the-release) the procedure, and
  [docs/inventory-surface-rows.md](docs/inventory-surface-rows.md) the decision and its limit.

- **The audit block publishes the live reading beside the record, so a finding written by a boot on
  another connection or environment is labelled instead of read as this host's.** `/health/db`
  embedded `BootAudit::reported()` and nothing else, and the record is per installation while a
  boot is per process: a deployment whose entrypoint migrates under another environment before its
  web process starts writes findings that name *its* connection and *its* Redis host. The payload
  published them beside a `pinned.connection` of `pgsql_proxy` with `replicas.store_healthy` true —
  a `swrr.primary_store.unreachable` sentence about SQLite and `127.0.0.1` on an instance that was
  serving reads from PostgreSQL. Every field was individually correct. The block was answering one
  boot's question with another boot's answer, and nothing on the page said so.

  Two things are recorded now. Each finding remembers the scope it was written in — the resolved
  connection, its driver, the rule that named it and `app.env` — and `reported()` carries a *second*
  reading taken while building the response: the same six producers a boot runs, in the same order,
  handed to `BootAudit` by the provider as a closure, so the class that owns the block still knows
  no producer and the provider that owns the settings still knows no rendering. `primaryStoreFindings()`
  is asked with the probe off, because a reachability check is a connect timeout and a health payload
  is not the place to spend one: the store key is therefore absent from the live findings and absent
  from `evaluated`, so `findings[].current.evaluated` is false and `standing` is null — *nothing
  asked it* is not *it reads well*, and the two are separate fields for exactly that reason.
  `scope_matches` compares the recording boot's scope with this one's, and is null when either side
  is unknown: a record written before scopes were kept, or a live reading that could not run.

  The record is untouched by all of it. The finding keeps its sentence, its level, its first sighting
  and its age, and the boot that finds it clean still closes it out; the live half writes nothing,
  logs nothing and probes nothing, and costs a handful of configuration reads and stats per request.
  `db:replica-status` renders both halves too, each finding followed by its `now:` line and the live
  reading printed under the list — including the one thing the record cannot say, a key that reads on
  now and that no boot has written down yet. No `status`, no HTTP code and no exit code moved.

  Pinned by six cases in `BootAuditTest` (the scope a report remembers, a record that carries none,
  the live reading beside the record, a key that was not evaluated, no evaluator at all, and an
  evaluator that throws) and three in the provider suite (a finding from another scope labelled in
  the payload, the store's reachability reported as not evaluated, and the terminal saying the same
  thing about the same record).
  [docs/boot-audit-surfaces.md](docs/boot-audit-surfaces.md) gains the two-readings section, and its
  limitation 5 now says the block labels what it used to hide; the README's audit section documents
  the new fields.

- **The package's own inventory record is whole at `v0.2.0-alpha1`: the third file it was missing
  is written from that tag's own tree, so the fourth signal weighs here instead of being skipped.**
  `files.tsv` and `methods.tsv` at the package root were stamped `v0.2.0-alpha1` and `surface.tsv`
  was never written — the incomplete state the weighing reports and steps around, which cost every
  `composer release --weigh` run in this repository the inventory's second opinion. `php
  bin/inventory.php --at=v0.2.0-alpha1` builds it from the tree that ref holds, so the rows and the
  stamp come from one tree and a row can only describe what that release published; it is the
  backfill case `--at` was added for, and the one run of it that completes a record rather than
  replacing one. The record is now 34 files, 167 public methods and 165 keys and members — 48 config
  keys, 71 constants, 32 env vars and 14 public properties, the typed properties the surface reader
  had been blind to. The generator's header and `docs/inventory-surface-rows.md` no longer say this
  repository carries a two-file set, and `php bin/inventory.php --check --at=v0.2.0-alpha1` reports
  the set current where it reported the missing file.

- **`db:replica-status`'s one non-zero exit was documented and pinned by nothing, and the record
  described it as another command's row.** The command's rule is one input — is the weighted manager
  bound — so `docs/documented-exit-codes.md` listed it as "not a matrix", pointing at
  `db:probe-replicas`'s `unbound` cell for its single `1`. That cell pins the *probe's* guard, not this
  one, and this one returns from two places: `--json` writes an object and its code, the terminal
  writes the sentence and its code, and only the first was ever driven. The run an operator meets —
  `ERROR  WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.` and
  exit `1` — was in the README's table and asserted nowhere.

  The provider is an exit matrix now, six cells derived from the three routes rather than written a
  second time, and every cell runs the real command: the code, the sentence the route carries, and —
  on `--json` — the `exit_code` inside the object. `documentedSituations()` maps each cell to the
  README row it is an instance of, which is the table's other claim: one `exit` column, so both
  channels exit the same code. `DocumentedExitCounts::MATRICES` names the test file, so the record's
  own count of the commands it is about is rendered from it — three became four, and so did the tables
  and the mutation list, in the record and in the counted rows of `docs/prose-numbers.md`. Both places
  an operator reads were saying the code never moves; the class docblock and the README's
  `db:replica-status` section now name the one exception, and the guard's branch carries the reason
  in a comment. The renamed `test_the_matrix_agrees_with_the_readme_exit_table` is the name the
  other three matrices' guards use, and the two documents that named the old test were updated.
  Pinned by `test_the_exit_code_is_a_function_of_the_route_and_the_channel` and the renamed guard —
  13 tests in the file, from 8. Three mutations are measured against it: reverting the README's
  `unbound` row to `0` fails one test naming the case, reverting the provider's `unbound` exit fails
  four (both of that route's cells, the object's own row, and the agreement test), and returning
  `SUCCESS` from the terminal guard fails one — the new cell.

- **`db:doctor --config-file` reported one problem at a time, so a candidate file that was both
  unusable and noisy was reported as unusable and nothing else.** The vet's `config file` row picked a
  single sentence per state: a file that returned a string instead of an array was named for that, and
  the bytes it printed while it was being read — the ones `config:cache` writes into the cached config,
  which is the whole reason the row mentions them — were dropped, because the row returned before it
  reached the branch that names them. An operator fixed the shape and heard about the output on the
  next run of the pipeline, which is the shape the `reader windows`, `pgcat files` and `store probe`
  rows lost when they learned to name every problem they have. This row is assembled the same way now:
  the shape first, then what the file printed, each in its own sentence, with the loudest problem
  setting the verdict. The vet's other two rows already used that assembly, so nothing about their
  sentences or their lack of dates moved.

  Two things fell out of writing the test. A candidate that prints and *then* throws used to lose the
  bytes as well — the buffer this command opens is read now, before the flush that closes it, so the
  throw and the output are named together. And that flush emptied *every* open output buffer rather
  than the ones this read opened, which under a test runner is the runner's own: it stops at the depth
  the read started at, so a candidate file that throws cannot close somebody else's output. Pinned by
  three cases in `DbDoctorTest` — the shape beside the bytes, the bytes printed before a throw, and the
  entry the register declares, which drives the vet's run rather than the installation fixture's because
  the vet's row is the one whose problems are undated by design. Four mutations are measured against
  it: giving `datedRow()` only the first problem fails twenty-four tests (the register's own case
  among them), never reporting the bytes a candidate printed fails four, dropping the buffer this
  command opened fails one, and emptying every buffer rather than the ones this read opened fails one.

  Every other row was audited one at a time for the same shape, since the register's measure can only
  see rows whose problems are dated. None of them drops a problem: the rows with two states are
  mutually exclusive in the state the first one reports (`weighted factory`, `published config`,
  `pgcat gate`, `replica metadata`), progressive readings of one thing (`store reachability`), one
  chain nobody can shorten (`pgcat supervisor`), or a fact another row names in the same run
  (`provider swap`). `replica metadata` names several problems already — one per replica — and stays
  out of the register because the resolver's pool decisions are not findings to date. The table with
  each row and its reason is in [docs/db-doctor-json.md](docs/db-doctor-json.md).

- **A release after a dev tag was weighed from that tag, so promoting the version it announced was
  refused — and `--weigh` answered with a version that skipped it.** A prerelease does not *hold* the
  rung in its name, it announces it: `0.5.0-alpha1` is a prerelease of `0.5.0`, so a round of changes
  cut after it belongs to a 0.5.0 line rather than to a version anyone has released. Both halves of
  the release read the latest tag as the base instead. `--weigh` counted `0.5.0-alpha1 + patch` as
  `0.5.1`; with a `v1.0.0` released and a `2.0.0-alpha1` in flight it counted `2.0.0-alpha1 + major` as
  **3.0.0**, two lines ahead of the change and a version that leaves the 2.0.0 the alpha was cut for
  unreleased. The gate read it the same way: `0.5.0` over `v0.2.0` is the minor the round's notes ask
  for, but against `0.5.0-alpha1` it reads as a patch, so a release the policy is satisfied by was
  refused and `--minor` — 0.6.0, the same skip — printed as the way out. The rung of a declared
  version is now measured from the newest *release* at or below the line being developed
  (`precedingRelease()`), and `--weigh` offers the promotion a dev tag announced, stepping past it
  only when the changes since the tag ask for more than the promotion itself climbs — and then from
  the release, so the fourth row of `RELEASING.md`'s table is answered with 2.0.0 rather than with a
  version above the tag. A lane cut again is measured the same way, so `0.5.0-alpha2` after a round of
  features is no longer refused for being one patch step from `alpha1`, and a first release whose only
  tag is a dev tag is declared rather than refused. The plan names the base it used, since it shows
  the dev tag as `latest tag` beside a rung measured from a version that is not it. Pinned by six
  cases in `PrereleaseTest` — the promotion at a minor and at a major, `--weigh` naming the promotion
  rather than stepping past it, a second dev tag in a lane, the first release behind a dev tag, and
  the promotion that is *still* refused because it is a smaller step than the changes ask for. Each
  rule is measured against its own regression: measuring the rung against the dev tag again fails
  four of them, stepping `--weigh` from the dev tag fails three, and offering the promotion as the
  prerelease itself fails four.

- **`bin/inventory.php`'s rewrite warned about discarding a record it had never read.** The warning
  compares the files on disk against the tree and names the changes a rewrite is about to forget,
  and it did that whenever *either* file had been read — so a pair that is half there reported
  every row of the missing side as `added public method ...`, under a sentence claiming this rewrite
  discarded them. That state is not hypothetical: both files are written in one call and only the
  second can fail, so an interrupted release leaves exactly it. The file that is not there recorded
  nothing, so the guard is now "both, or neither" — a half-written pair is completed without a word
  about a record, and the rows it *did* write are reported as before. Pinned by
  `test_a_pair_that_is_half_there_discards_nothing_it_did_not_read`, which fails on the old
  condition, and by seven more cases for the generator itself: `--root=PATH` writing into the
  package it names and leaving the script's own alone, `--check` following that root rather than the
  script's, both files named when neither is there, a row too long to print cut at 68 characters and
  ended with an ellipsis, `-h` as the same run as `--help`, an unknown option *after* a valid one
  still exiting `2` and writing nothing, and the second file failing to write while the first is
  already on disk. Sixteen tests on the generator are twenty-four, and the shape of the new ones is
  measured rather than assumed: ignoring `--root` fails three of them, removing the row cut one, and
  writing `methods.tsv` before `files.tsv` two.

- **`db:doctor`'s `store probe` row dates each of its two states from its own boot finding, so a
  preflight can say how long the installation has been unable to check its store rather than only
  that it is.** Both faults the row reports are the same kind of thing and it is the kind a row
  cannot express: a probe switched off (`swrr.audit.store_probe_seconds = 0`, the documented way to
  keep the audit to configuration checks) and a record the probe cannot be remembered in. Neither is
  a malformed value — the package reads both settings perfectly and the installation cannot carry one
  of them out — and both *stand*: a row describes this boot, the record describes every boot since,
  and "since when" is the whole question about a state that has been true for weeks. Each is a
  finding of its own now — `swrr.audit.store_probe_seconds.off` and `swrr.audit.file.unwritable` — so
  the row dates each from its own entry, the way every refused reader setting already does, and the
  register of rows that can name several problems covers it: `store probe` was the one case in that
  register that survived the "first problem only" mutation, because the row built its two sentences
  itself instead of asking the shared row builder for them, and once the states are findings it builds
  them the way every other row does and the mutation reaches it. Both are `warning` level: the first
  because switching the probe off is the choice the README names and `--strict` is the surface that
  refuses it, the second because it is the fault the README sets against that choice and nothing about
  it is malformed either.

  The second key is the one finding in this package whose own record can never hold its date: the file
  a date would be written to is the file that cannot be written, so `persist()` skips the write and the
  operator gets the log line. It is keyed anyway — a line with a key is one an alert rule and a grep
  can select on, and the row is where the state is described — and the asymmetry is written down at
  the constant rather than left to be discovered, because the alternative was a standing state
  reported by no key at all in exactly the case where the record is guaranteed to be silent. The
  third state the row names — a probe that is switched on with an in-process `swrr.primary_store` —
  deliberately keeps **no** key: there is nothing it could have been checking, and the reachability
  row already warns about the store that makes it so.

  The sentences are written once, in the class that owns the probe and writes them into the log
  (`BootAudit::probeOffSentence()` and `probeUnwritableSentence()`), so the row cannot describe a state
  differently from the line beside it — the same reason `ReplicaMetadata` was extracted; and
  `datedRow()` now finishes a sentence by trimming whatever full stops it carries and writing one back,
  because the store probe's two end where the row appends its own `--strict` aside or names the file.
  Pinned by three cases in `WeightedDatabaseServiceProviderTest`
  (`test_a_switched_off_store_probe_is_recorded_under_its_own_key`,
  `test_a_record_that_cannot_be_written_is_logged_and_never_remembered`,
  `test_a_probe_with_nothing_to_reach_is_not_a_finding`) and by
  `DbDoctorTest::test_the_store_probe_row_dates_each_state_from_its_own_finding`, and
  mutation-checked: `datedRow()` printing only the first problem now fails 19 tests rather than the
  15 it did before this change, the store probe's own three row tests among them. The suite's default
  boot stopped being an opted-out installation with it — `store_probe_seconds` was `0` in
  `TestCase`, which is a standing finding of its own now, so the fixture's boot is a probe that is on
  and has already run, and the tests that are about the probe set the interval and backdate the stamp
  themselves.

- **`db:doctor`'s `pgcat files` row prints the repair for every problem it reports, rather than
  naming three unusable files in sentences and leaving the `suggestion` column empty.** The row is
  the only one that can carry several problems at once, and it was the one that never printed a
  line: every fault it reported was a path or a permission, which is exactly what the reader-window
  rule excludes — the package would have to guess at a value only the installation knows. That
  stopped being true of every problem at once. A permission is a **mode** on a path this
  installation has already chosen, and an empty `config_path`/`state_file`/`lock_file` is a value
  the published config ships, so each problem is now dated from its own finding and repaired
  individually: `chmod +r` for a source the row cannot read, `chmod +w` for a file it cannot write,
  and `chmod +wx` for a directory, because the atomic swap writes `{target}.tmp.{pid}` into it *and*
  renames the result over the target, so write without traverse still fails. The mode is a symbolic
  *add* on the path the check was run against rather than a whole mode, so nothing the operator set
  is rewritten, and it is deliberately unscoped — the row proves that *this* user can use the file,
  so a line naming a narrower class could print a repair that does not clear the check it was
  printed for. An empty key prints the setting line with the value the published config ships, which
  is the half of candidate C of `docs/pgcat-suggestion-lines.md` that survived review: a value the
  package itself publishes is its own to repair, so `swrr.pgcat.config_path = '/etc/pgcat/pgcat.toml'`
  and the two state paths as the sample's `sys_get_temp_dir()` spells them on this host are printed,
  while `readers_path` and `no_readers_path` — documented as `—` — still get none. A file that is not
  there gets none either, because that file has to be put there by whatever installs pgcat, and
  nothing about which states are *problems* changed: the sentences, their order and the list they
  come from are the same, and the sentence still accompanies the line because the flip's refusal,
  `--dry-run` and `/health/db`'s `warning` read the sentence and have no suggestion column.

  The row and the record read one list. `PgcatConfigFlipper::fileProblems()` is where the
  preconditions are computed — the flipper already owned the keys, the documented commands and the
  paths a flip touches, so it owns these too — and `db:doctor` renders each problem into the row
  while the boot audit records the same problems as findings, one key per setting:
  `swrr.pgcat.config_path.unusable`, `swrr.pgcat.readers_path.unusable`,
  `swrr.pgcat.no_readers_path.unusable`, `swrr.pgcat.state_file.unusable` and
  `swrr.pgcat.lock_file.unusable`. They are `warning` level rather than `error`, because an unusable
  path is a flip that will throw when the window next opens rather than an installation serving
  reads the wrong way; each carries the setting, the path and the state it was found in, and each
  has a resolution sentence for the boot that finds the file usable again and for the boot that
  finds pgcat off, so a record closes out on the repair rather than waiting to be deleted. An
  *empty* key is the one state the record leaves to the row, and deliberately: the provider defaults
  `state_file` and `lock_file`, so emptiness has to be written on purpose, and a boot that logged
  each unset path would write three lines per boot about a state the two `pgcat` rows already report
  and the flip's first use already throws on. The audit reports files, `db:doctor` reports armings.

  Pinned by seven cases in `PgcatConfigFlipperTest` —
  `test_the_documented_paths_are_the_ones_the_published_config_ships`, which reads the values out of
  the published config rather than copying them beside it,
  `test_an_empty_key_carries_the_published_value_only_where_the_config_ships_one`,
  `test_a_directory_that_will_not_take_a_write_carries_the_mode_that_would`,
  `test_a_file_that_is_not_there_gets_no_line`,
  `test_a_file_that_cannot_be_read_carries_the_mode_that_would`,
  `test_each_file_mode_problem_carries_the_bit_that_failed` and
  `test_an_inert_flipper_has_no_file_preconditions_at_all` — by three in `DbDoctorTest`
  (`test_the_files_row_prints_one_repair_line_per_problem`,
  `test_the_files_row_repairs_an_empty_key_only_where_the_published_config_ships_a_value`,
  `test_each_file_problem_is_dated_from_its_own_finding`) and by three in
  `WeightedDatabaseServiceProviderTest`
  (`test_a_file_a_flip_needs_that_cannot_be_used_is_recorded_under_its_settings_key`,
  `test_an_empty_pgcat_path_is_the_rows_problem_and_not_a_file_finding`,
  `test_switching_pgcat_off_closes_a_file_finding_out`), and mutation-checked against its own
  failures: returning no line for an unreadable source fails one test — which of them depends on
  the platform, the filesystem case where mode bits are honoured and the mode table where they are
  not — and that table exists because the first run of this mutation on this laptop measured
  **zero** failures, with `+r` covered only by a case that has to skip there; declaring the state
  path instead of reading it from the sample fails two, the drift guard and the empty-key line;
  recording an empty key as a file finding fails 34; and a row that printed only the first problem's
  repair fails three.

- **`db:doctor`'s `replica metadata` row names a drained replica in every branch now, rather than
  only in the one that passes.** The row reports two kinds of thing and they are not the same kind: a
  *value* the resolver cannot read, which is what fails a deploy, and a *replica* the pool does not
  hold, which is what an operator acts on. The disable — `weight: 0`, the documented way to take a
  replica out — was named only where nothing else was wrong, because the row's other two branches
  return before the pool is described at all: a weight the resolver could not read returned a
  sentence about the value while the replica somebody had switched off went unmentioned until the
  deploy *after* that repair, and a pool in which every replica is disabled printed `every replica on
  [pgsql] resolves to weight 0 — reads cannot be routed` without saying which replicas they were.
  Both branches now carry the same clause, spelled once: `Also drained on purpose: 10.1.0.2:5432 —
  weight is 0, which is how the read list takes a replica out of the pool.` The rule underneath all
  three branches is asserted as a property rather than case by case —
  `DbDoctorTest::test_the_metadata_row_names_every_replica_the_pool_does_not_hold` runs five read
  lists, asks the resolver which replicas its pool does not hold, and requires the row to name every
  one of them whatever it left over and whatever verdict the row reached — and two cases pin the
  wording: `test_the_metadata_row_names_a_drained_replica_beside_the_value_it_cannot_read` and
  `test_the_metadata_row_names_every_replica_when_every_one_is_drained`. Removing the clause from the
  refusal branch fails four tests and from the empty-pool branch two, so neither branch can go back
  to reporting a value while the replica that is not answering reads goes unnamed.

- **The boot audit's record is now safe under genuinely concurrent boots rather than merely loud
  about them.** The record is one file shared by every boot of an installation, and a boot writes it
  from the copy it read near its start — with the store probe in between, which is a network call, so
  the window can be a connect timeout wide. Two boots in flight therefore both wrote from the same
  starting point, and the later one **discarded** whatever the earlier recorded: an entry nothing had
  taken out of the record yet, dropped by a write that never knew it was there. The rename that made
  the write atomic protected the *reader*; the writer was the same hazard one level up, and it was
  reported — a line naming the entries about to go — rather than prevented. It is prevented now, by
  two mechanisms that are both needed. The write re-reads the record as it is about to be replaced
  and **merges**: the keys this boot produced are written over what is there; a key this boot
  evaluated and found clean is cleared only while the entry is still the one this boot read, and one
  another boot substantiated in the meantime is kept and named, because a boot that has just logged a
  setting failing is not contradicted by a copy read before that; every other key is taken as the
  file holds it; and the probe stamp keeps the later of the two, so a merge cannot cost the
  installation a probe it did not need. And the read-merge-write is taken under an exclusive
  `flock(LOCK_EX)` on a **companion** file beside the record — a companion because the write replaces
  the record by renaming a temp over it, so a descriptor opened on the record would be holding a file
  nothing will ever open again, and two boots either side of a rename would each hold "the record"
  while excluding nothing. Which lock it is matters as much as the exclusion: `flock` lives on an open
  descriptor, so the kernel releases it when a process ends however it ends, and a worker killed
  mid-write leaves an empty file beside the record — which is not a lock, says nothing and stops
  nothing — where the sentinel the store-probe decision refused would have waited for somebody to
  find and delete it. A boot does not wait for the lock indefinitely either: eight attempts, 15 ms
  apart, and then the merge goes ahead from the freshest read it can take and says it could not
  serialise, because recording the settings this boot checked matters more than recording them alone.
  Where the record cannot be written at all, none of the write is attempted — no re-read, no merge and
  no eight futile attempts at a lock a read-only directory would refuse, since the merge's result has
  nowhere to go and a write that never happened has not "merged without serialising": the boot pays a
  stat for the check, and the finding it logged is what an operator gets. The change is pinned by
  seven new tests in `BootAuditTest` — the merge, the kept verdict, the lock taken and left free for
  the next boot, a lock file a killed worker left behind, a lock this boot cannot take, the re-read
  taken inside the lock, and the probe stamp — and mutation-checked against its own failures: writing
  this boot's own copy instead of the merged one fails four of them, reading the file before taking
  the lock fails one, taking the stamp from this boot's copy fails one, and giving up on the write
  when the lock cannot be taken fails one.

  The mechanism is measured rather than argued, too. `BootAuditConcurrencyTest` runs four real boots
  of one record — as **processes**, because a lock is only worth measuring between processes: two
  descriptors the same process opened collide whether or not the writers share a path, so a
  same-process test passes against a design that excludes nothing. Every boot is released at the
  same wall-clock instant and times two windows around the shipped lock closures: the read of the
  record to the rename that replaced it, and the part of that the lock is held for. What it asserts
  is the relationship and never a number — the hold stays far below the boot stage it sits between,
  so a lock taken at boot rather than at the write fails the run — in two regimes that fail for
  different reasons: a boot stage between the read and the write is what a stale copy would be
  written back over, which is the merge's half, and with no stage at all every boot is inside its
  critical section at the same instant, so the reads all happen before any of the writes and the run
  is the lock's. Run against that harness, the two mutations that separate the mechanisms each fail
  their half: writing this boot's own copy instead of the merged one loses three of the four keys
  *in silence*, in both regimes, and giving each boot a lock path of its own fails both runs on the
  boot that wrote its key and could not find it.

- **`PUSHING.md`'s credential section is two paragraphs again.** The sentence that closes the
  `glab` half of it — `the token does not.` — had run into the sentence after it, so the advice
  about a personal access token read as its continuation. Nothing was reworded, and the shape is
  worth naming because nothing here reads sentence shape: the guards over these records read
  claims — a cited test name, a count, a fenced block — so a fused pair of sentences is exactly
  what the suite cannot see.

### Changed

- **Two of the boot audit's log lines are about the record's write instead of about a lost entry,
  and an alert rule written against the old one needs updating.** "The audit record changed while
  this boot was running …" no longer says `discarding N entries` — the entry is not discarded any
  more, the write keeps it — and the context keys it is triaged by are `kept` and
  `keys_this_boot_read` rather than `discarded` and `keys_on_disk`. A lock the write could not take
  is the second line, and its context carries `lock`, `attempts` and `waited_ms`. Both are logged at
  `error`, both name their subject in the context, and the README's triage table says which key tells
  which kind of page apart.

  **What this asks of an installation:** an alert rule, a dashboard query or a log grep that selects
  on `discarded` or `keys_on_disk` selects on nothing after the upgrade, because the keys are `kept`
  and `keys_this_boot_read` and the sentence that carried `discarding N entries` is gone with them.

## 0.2.0-alpha1 - 2026-09-28

### Added

- **A write-back scan is now one of the checks, so a keyed structure read from disk and written back
  whole fails the gate instead of a reader noticing.** The shape is the quiet one: a file is read as a
  record or a state file, a key or two are set on the copy in memory, and the whole structure goes
  back — every key the reader did not know about is gone, the diff shows the key that was added, and
  nothing shows the keys that went. Both places in this package where it has mattered were found by
  someone reading the code rather than by a run failing: the flip's state file was once replaced by
  whichever four keys the flip happened to carry, which erased `converged_at` and moved the boot
  window with it, and the boot audit's record is one file shared by every boot of an installation.
  `bin/checks.php` now scans `src/` and `config/` for the shape in the AST rather than as text: a
  write is `file_put_contents()` or the `fwrite()` of a handle opened in the same method, its target
  is what a `rename()` in the same method finishes on rather than the temp it names, the path has to
  be one the class reads, and the payload has to be a whole value — an encoded structure, or a
  variable, element or array holding one — so a log line written into a file that is also read is not
  a finding. One form needs no explanation, and it is the one the flip already uses:
  `file_put_contents($this->stateFile, json_encode([...$this->readState(), 'last_mode' => $mode]))`
  survives a key that arrived between the read and the write, so a merge with a read taken at the
  write site is exempt. Every other write-back is named in the `STRUCTURE_WRITE_BACKS` register at the
  top of the check, with the reason the copy being replaced is still the truth — the flip's config
  rollback, its state file through `writeState()`, and the audit record, which re-reads and *reports*
  a record another boot left rather than merging it. A register entry the code has left behind fails
  the check too, because a declaration that outlives the code it described would excuse the next write
  at that spot. And because a detector that has stopped seeing the shape reports an empty result, it
  is run against fixtures of its own — the two ways this package has lost a key, the merges that are
  the safe form of both, including one whose read is two calls deep, the atomic temp-then-rename
  write, a handle opened and written with, a config file rebuilt around an encoded structure, a copy
  to another file, a scratch file nobody reads, a line of prose, and a helper that writes what its
  caller read — before it is pointed at the tree, so it fails by name rather than passing everything.
  The scan has been mutation-checked against its own failures: a dropped register entry fails naming
  every site it should have covered, an entry the code no longer has fails naming it, a dropped work
  in the detector — the merge exemption, the temp resolution, the handle resolution, the encoder that
  makes a rebuilt config recognisable, reading a path out of a `require` — fails the fixture that pins
  it, and a file with the bug shape dropped into the scanned tree fails by file, line and payload.

- **A deploy gate for `db:doctor --json` now ships in the README, and the guard that keeps it honest is
  a test.** The preflight's object was documented with `jq` one-liners for a person at a prompt, and
  what a pipeline needs is the other thing: one script to paste that names the rows its deploy blocks
  on and refuses to ship when one of those rows is not `PASS` — a `WARN` on a row you named is a row
  you said must pass — or when some row `FAIL`ed, which the run's own `verdict` folds, because the row
  list is not a fixed length and a pipeline cannot name a row it has never seen. It decides on the
  object and not the exit code: the command's status is discarded on purpose, so a preflight that never
  got written is an empty file, and `jq` refuses that as loudly as a failing row does. A gate written
  in `jq` is a promise about field names, and one failure would otherwise never be seen: `select` over
  a row name no row carries matches nothing, so a row renamed in the doctor leaves the gate passing
  every preflight, the broken one included. So `tests/Unit/Docs/DoctorGateTest.php` treats the block as
  code — the fenced block is read out of the README, its `jq` program is extracted and **run** against
  a report this command really produced: one it must ship, one whose named row is `WARN` and one whose
  unnamed row `FAIL`ed, each refusal asserted to name the row it refused on — and every row the gate
  names is asserted to be a row a real report carries. The gate has been mutation-checked against its
  own failures: a row name no run carries fails the row check, a dropped `select` fails the named-row
  refusal, an unread run `verdict` fails the unnamed-row refusal, and a gate that refuses everything
  fails the clean case.

- **The vocabulary every report is written in is now declared in one place, which is the half of the
  report contract this package's own records had been leaving open.** `docs/db-doctor-json.md` and
  `docs/pgcat-flip-json.md` each ended by naming a package-wide envelope as the thing that would
  change their decision, and the doctor's record said what it would want from one: a single place
  that defines the vocabulary. `Console\JsonEnvelope` already held the other two halves — the five
  keys every report leads with, and the rule that the exit code travels inside the object rather
  than in a `$?` beside it — and now holds the third. `JsonEnvelope::REPORTS` names every command in
  the package that writes a report, the key its run verdict is read by, and the README table its
  closed vocabulary is documented in; `JsonEnvelope::SHARED_KINDS` declares the words two of those
  vocabularies may legitimately share, which is how `unbound` stops being a convention and becomes
  the declared case. `db:doctor` is in that register rather than beside it: its object is still
  written by hand and still names the run's verdict `verdict` — candidate F of
  `docs/command-json-envelope.md` is why, and a rename is still the one-line answer for a gate that
  wants one name — but the spelling is now declared in the same place as the other reports', so
  learning what each of them calls its verdict is reading one document rather than three records and
  an exception. The register is read back out of the package rather than restated: the tests parse
  the table each entry names with the column each entry names, and find the commands that write a
  report by reading `src/Console/Commands/` for the two ways one is written — through the envelope,
  or by hand in the single case that is. A further report command therefore cannot be written
  without an entry, an entry cannot name a command that writes no report or a table that is not
  there, and a word two reports happen to share has to be declared as shared — which turns the
  limitation the envelope's own record carried (nothing compared the vocabularies, because no
  artefact held the set of words) into a comparison instead of a note. Nothing about a run changes:
  the keys, the codes, the kinds and their tables are exactly what they were, and the register is a
  declaration of them.

- **`db:probe-replicas` and `db:replica-status` report as data, in the envelope `db:pgcat-flip`
  defined — extracted into one class so the three commands a pipeline branches on are read by one
  rule.** Both earlier JSON records ended by naming this as the thing that would change their
decision: "a package-wide envelope would be the better home for the vocabulary, and it would want one
place that defines it". It has one now. `Console\JsonEnvelope` owns the five keys every report leads
with — `command`, `kind`, `exit_code`, `reason`, `error`, in that order — and each command declares
its own evidence as a constant of `key => the value an absent one takes`: the flip's `mode`,
`previous_mode`, `steps` and `status`; the sweep's `connection`, its `counts` (`probed`, `answered`,
`failed`) and its `replicas`, one entry per attempted replica carrying the reason a failed one
failed — the half of a probe that is otherwise invisible, because the probe's own connection is
purged after every attempt; the distribution's `status` (the manager's own `healthSummary()`),
`pgcat` and `audit`, which are the two blocks `/health/db` embeds under those same two names, so a
job reading a terminal report, a saved one and the endpoint is reading one vocabulary. A default is
the key's *shape* rather than a guess — a list key defaults to `[]`, `counts` to a zeroed map, so
`.counts.failed` is readable on every route of a command that runs on a schedule every thirty
seconds. The envelope also refuses the two ways a command can write its own report wrong, both of
which would otherwise be invisible: a route supplying a key its command did not declare (a mistyped
`replica` publishes less than the route meant to while producing perfectly well-formed JSON), and a
command declaring one of the five core keys as its own evidence. `kind` stays the command's own
closed vocabulary — the sweep's five are the five cases its exit table documents, one to one,
because a case that exits differently has to be a different verdict — and the word `unbound` is
shared deliberately: "the container has no weighted manager" is the same repair whether the missing
dependency is the manager or the flipper. Each command's kind table is now in the README beside its
exit table and bound the same way, by a test that reads it back and asserts the codes. Nothing about
a run changes: every matrix runs twice, once for the rendered report and once for the object, and
asserts the same code, marks, files and records — `--json` is not refused beside anything here,
because neither command has a flag it could contradict, and the detail `-v` prints is the object's
`replicas` rather than a second channel. `db:doctor --json` deliberately keeps its own envelope
(`verdict`, rows) — its record's candidate F is the reason, and the new record answers it rather
than leaving it implied. The decision is recorded in
[docs/command-json-envelope.md](docs/command-json-envelope.md), and the two older records' "a shared
envelope" bullets now point at it.

- **Every count `docs/documented-exit-codes.md` states is now rendered from the provider that owns
  it, including the ones stated a second time — and one of those second copies had been wrong for
  as long as the map existed.** The renderer below covered the counts that were in the record's
  sentences and in the tables; what it did not cover was every *place* a count is written. Three
  were left: the two bold headings that open the probe's and the flip's paragraphs ("**The probe's
  nine cells.**") restated their cell counts without being bound to anything — the sentence right
  under each one was rendered, the heading above it was prose — and the flip's paragraph states the
  probe's arithmetic for its own table, that sixteen cells are eight documented cases, by naming the
  groups: "the three ways a flip can politely do nothing are one case, the three ways a command can
  be refused are another". Those numbers are not a second fact either: they are how many cells
  `DbFlipPgcatCommandTest::documentedSituations()` assigns to one README row, so the phrase each one
  is a shorthand for is declared once, in `DocumentedExitCounts::FLIP_WAYS`, and the count is read
  from the map — a phrase the declaration does not know, or a row no cell is assigned to, fails
  rather than passing quietly. The same two counts are written in a *second* file: the docblock of
  the map itself, which said **two** ways a command can be refused while the map assigned three
  cells to that row — a number that had been wrong for as long as the map existed, in the file a
  reader of the map opens, with the record a reader of the record opens saying three. A claim is one
  file and one pattern, so a count written twice is two claims over one derivation, which is also
  why the renderer writes two files here: `bin/counts.php` now fixes a docblock as well as a record,
  and its first run over the map did exactly that (`two -> three`). Two consequences worth naming:
  the record's refused phrase is anchored on the words that follow the number, because the record
  states the same phrase again a few lines down describing the state *before* the guard — a
  historical "two" the renderer must not touch — and the pattern allows the whitespace where the
  record's own line wrap falls, so a claim is pinned to the record's wording rather than to its
  column width. `docs/prose-numbers.md` gained the four rows, and its boundary lists now say why
  the historical statement is prose, what the first "ways" phrase actually names (the row is wider
  than the phrase sounds, and which row it is, is the declaration), and that a number written in two
  files is rendered wherever it is written rather than only where the record is.

- **The counts `docs/documented-exit-codes.md` states are rendered from the providers that own
  them, and the first render found one of them had been wrong since it was written.** The record
  was the one that named this problem — the flip's matrix sat at "fifteen" from the day the JSON
  work added a sixteenth cell — and the previous entry below answered it by *checking* every count
  against its derivation. Checking still leaves the number written by hand, and one of them was
  wrong in a way the check could not see: the candidate-C row said the probe's table documents `0`
  on three rows and `1` on two, and the README's table has it the other way round. It reads as a
  real argument, because the sentence's point — the sets of codes match while a case is documented
  as the wrong one — holds either way, and nothing had compared the split with the table's own
  `exit` column. So the counts this record states are no longer written at all:
  `tests/Support/DocumentedExitCounts` derives each one — the three matrices from
  `exitCodeProvider()`, the documented-case counts from the maps from each cell to its README row,
  the doctor's tables from the README, and the probe's split by code from the table's `exit` column
  — and `bin/counts.php` renders them into the record. `php bin/counts.php` writes,
  `php bin/counts.php --check` compares and writes nothing, and `bin/checks.php` gained a check that
  runs it, so a matrix that grows a cell fails the gate instead of leaving a sentence behind. The
  renderer is safe because the guard makes it so: `ProseNumbersTest` asserts that *every* place a
  pattern matches states the derived number, so a pattern that also matched an unrelated sentence
  would already be a failing test — and the patterns are anchored on words around the number, never
  on a number another claim owns, so a rendered count cannot unbound a neighbouring claim's
  occurrence. Nothing is written unless every claim resolves: a reworded sentence, an unreadable
  record or two claims on one word is reported and the run stops, because a half-rendered record is
  worse than a stale one. The vocabulary moved to `tests/Support/NumberWords`, in both directions —
  the guard reads a record's word, the renderer writes it — and a rewrite keeps the record's own
  capitalisation, which is how "Three keys, not one `switches.refused`" stays a sentence that opens
  with a number. `ProseNumbersTest` now merges the rendered claims with the checked ones, so the two
  halves read one table rather than two that can disagree, and `docs/prose-numbers.md` records which
  records are rendered, which are checked, and the three numbers in this record that are
  deliberately neither (the failure modes and the two prose behaviours count a list their own
  sentence writes; the historical counts are about a version of the package that no longer exists).

- **Every number the records state about the code is now derived from it or compared with it, so a
  count that is true when it is written cannot quietly stop being true.** A number in a sentence is
  a second copy of a fact the code holds, and nothing in the suite read the sentences: the flip's
  matrix sat at "fifteen" from the day the JSON work added a sixteenth cell, a sentence beside it
  still said *two* ways a command can be refused when there were three, and three records carried
  counts and test names that later work had overtaken — each one consistent with everything the
  suite asserted, which is what made the drift quiet. The repair is not to delete the numbers
  ("the six methods whose lists are spread into one boot's findings" is how a reader learns the
  shape of the surface) but to give each one a reader: `tests/Unit/Docs/ProseNumbersTest.php` pins
  every claim to the sentence it is written in and compares it with the number the code has —
  counted from a constant, a class's own data provider, the source file the thing is implemented
  in, or a record's own candidate headings — and the two claims that need the command to have run
  (the README's check table, and the rows that can carry a repair) are bound the same way in
  `DbDoctorTest`, where the fixtures are. Three properties make it a guard rather than a
  restatement: the pattern must still match, so a reworded sentence fails instead of passing
  vacuously; every statement of a claim is compared, so a record saying "three" in one paragraph
  and "four" in another fails on the second; and no expectation in the guard is a literal. Two
  cross-table claims that records had been carrying as "nothing checks them" are now compared as
  well — the provider's `SWITCHES`, the doctor's `SWITCH_KEYS` and its `SWITCH_CONSEQUENCES` must
  name the same settings, so a switch added to one table cannot become a finding with no row or a
  row whose key nothing writes. The same defect with a name instead of a number is guarded too, and
  it had already bitten: twenty-two of the tests the records cite did not exist under those names,
  so the reader checking a claim was sent to an empty search. `tests/Unit/Docs/DocCitationsTest.php`
  reads the records as data and checks every `test_…` citation against the classes that declare it —
  and, where the record names the class, against *that* class, because a name that resolves to the
  wrong class sends the reader to a real test that pins a different rule. The record of the rule, the claim table, and the numbers that are
  deliberately *not* bound (a design shape no list holds, a partition that is a judgement, a
  historical state, a budget for a sized installation) are in
  [docs/prose-numbers.md](docs/prose-numbers.md); the guard reads that table back, so a claim added
  here without being written down there fails, and a number typed into the record that the code
  disagrees with fails with the sentence named.

- **The resolver's metadata floors are three constants, so the report and the arithmetic read one
  boundary instead of two that happened to match.** `WeightResolver::resolveWeight()` clamped a
  weight, a core count and a memory figure with `max(0, ConfigValue::int(…))`,
  `max(1, ConfigValue::int(…, 1))` and `max(0.0, ConfigValue::float(…))`, and the classifier that
  decides whether a written value is the one routing uses stated the same three boundaries a second
  time — one rule with two readers, agreeing only by inspection. A report that restates the
  arithmetic it is judging is a report that can go on passing after the arithmetic moves, which is
  exactly the hole `docs/replica-metadata-refusal.md` had been carrying as "no test can catch this
  without reading the resolver". The boundaries are now `ReplicaMetadata::WEIGHT_FLOOR`,
  `CORES_FLOOR` and `RAM_FLOOR`, public constants that the resolver's own `max()` reads, so where a
  boundary is has one definition and the row reads the resolver's numbers rather than its own. They
  are declared in the classifier rather than in `WeightResolver` because the boot audit classifies a
  read list without the manager ever being resolved — and because the resolver already reads the
  classifier, for the refusals and for a replica's identity, so the reverse direction would be a
  cycle for a number. The weight floor is also the value that means *disabled*, so `refusals()` and
  `disables()` now read that one boundary from both of its sides and are non-overlapping by
  construction: a weight is refused exactly when the resolver reads it as the floor and it is not
  the disable the read list means, which is the property the resolver reads to say why a replica
  left its pool. Declaring the floors once is not enough on its own — a constant can still be
  ignored — so a new test drives the resolver to each constant and asks both halves about `floor -
  1` and about `floor`, written against the weight the floor *produces* rather than against the
  replica sitting on it, because a clamp moved by one would move both of those together and an
  equality between them would keep passing. The one number in `resolveWeight()` that is deliberately
  *not* a floor — the formula's final `max(1, $weight)`, a replica that declares hardware is never
  weighted nothing — stays a literal, because nothing outside that method has an opinion about it.

- **The resolver reports the replicas it does not use, and why, so an exclusion is read rather than
  inferred from a shorter list.** A pool is a shorter list than the read config that produced it,
  and three different things take a replica out of it: `weight: 0`, which the read list means as a
  disable; a weight the package refuses to read, which `ConfigValue` falls back to `0` for and which
  therefore removes the replica as well; and the health filter the caller supplied. `resolve()`
  returned the survivors and nothing else, so `db:doctor`'s `replica metadata` row classified the
  read list a second time to work out which replicas were missing, and `pickReplica()`'s warning on
  an empty pool read `All replicas in cool-down; resetting health circuits` with the connection in
  its context and not one replica named — a log line about a shorter list, written by the code that
  knew every entry in it. `resolveWithExclusions()` now returns the pair: the pool, and every
  replica it did not use with its config, its stable `host:port`, the weight it would have carried,
  one of three reasons and the sentence for that reason. The pair *partitions* the read list — every
  configured replica appears exactly once across the two — which is what turns "the pool is one
  shorter than the config" into a question with an answer instead of an inference. The reasons are a
  closed vocabulary, because a reason a reader has to interpret is a reason nothing can select on:
  `refused` for a weight the package will not read, `disabled` for `weight: 0` written on purpose,
  and `filtered` for the caller's own health filter. Which of the first two a departure was is not a
  second rule: a weight the resolver reads as `0` is either the documented disable or a value it
  refuses, never both and never neither, so the resolver reads the refusals and what falls through
  is the drain — a fact the classifier's tests assert over every spelling of `0` and everything that
  is not a number. The configuration half is cached with the pool and the filter half is computed per
  call, so a filtered exclusion is never remembered as a property of the installation and a
  preflight cannot fail on a replica that is momentarily in cool-down; `resolve()` is unchanged for
  the read path and is now the pair's first half, out of the same cached computation either way.
  `WeightedDatabaseManager::poolExclusions()` exposes the configuration half as the deliberate
  counterpart of `replicaStatus()` — that method is the pool, this is what it does not hold, and the
  two together are the read list — and `db:doctor`'s row now reads both instead of re-deriving
  anything. Reading the consequence rather than predicting it is what removed `ReplicaMetadata`'s
  `leaves` flag (a prediction of the pool's shape, made by the classifier rather than by the thing
  that acts) and `DbDoctor::disabledReplicas()` (a second reader of a decision the resolver had
  already made). Pinned by five resolver tests (each reason, the partition, the ordering, the cache,
  and `resolve()` as the first half), the manager's two halves of a read list, the cool-down boundary
  that keeps a failing replica out of the configuration answer, and the row's existing cases, whose
  clause now states what happened to a refused value. The decision, its candidates and the
  alternatives are in `docs/pool-exclusions.md`.

- **Replica metadata the resolver cannot read is refused at boot, so a pool that quietly lost a
  replica says so.** The resolver reads `weight` through `max(0, ConfigValue::int(…))` and
  `cpu_cores`/`ram_gb` through floors of its own, and `ConfigValue` falls back rather than
  throwing — so `'weight' => 'heavy'` is read as `0`, which is how a replica is *disabled*, and
  `buildPool()` drops it. The read list described a replica the pool no longer held, and nothing
  said which one went: `db:doctor`'s `replica metadata` row failed on it, but only when somebody
  ran a preflight, which is usually long after the reads got thinner. Every boot now reads the
  configured replicas through that same rule and refuses every value the resolver does not read as
  written, at `error` level — input the package will not interpret on the operator's behalf, the
  same claim `swrr.reader_windows` and the three switches make — under one key per setting, because
  the three read differently and cost differently: `database.read.weight.refused` for the setting
  that removes a replica from the pool, and `database.read.cpu_cores.refused` /
  `database.read.ram_gb.refused` for the two that leave it there, sized as something the read list
  does not describe. The keys are the setting's own path
  (`database.connections.*.read.*.weight`) rather than one of the `swrr.*` names, because the read
  list belongs to the connection and that is what an operator greps for; the connection the package
  followed is in the finding's context instead, since a key carrying the name would resolve the
  wrong warning the day the name changed. Each key is checked clean or not on every boot, so a
  resolution closes it out on the boot that reads a readable value — the value fixed, the replica
  dropped from the read list, or the whole list removed — and the sentence is written to be true of
  all three. Nothing throws and nothing is repaired: the value is still read exactly the way the
  resolver reads it, so an installation with a typo boots and serves, which is the boundary that
  keeps this from being the throw candidate `docs/replica-metadata-refusal.md` had already
  rejected, and it is why the refusal reports the shrink rather than preventing it. The reading
  moved into `Support\ReplicaMetadata`, which both halves now call — the row and the boot refusal —
  so a log line and a preflight cannot describe one value two ways, and the sentence is built
  there too. The replica's identity moved with it: `ReplicaMetadata::key()` is the one spelling of
  "which replica", read by the health monitor's failure counts, the resolver's pool keys and a boot
  naming a replica the pool is about to drop, where the previous two copies (`WeightResolver` and
  `WeightedDatabaseManager` each built `host:port` themselves) would have become three. The row's
  own rule is unchanged — `weight: 0` is still a drain rather than a fault, a negative or truncated
  weight is still reported as the value it was read as, and a cores or memory value still says the
  replica stayed — which is what the row's existing tests prove about the class they now go
  through. Pinned by `ReplicaMetadataTest` (the reading, the documented disable, the identity) and
  by boot tests for the key, the level, the sentence, the resolution, and the configuration that
  must *not* be refused.

- **`db:doctor --config-file` vets a candidate `config/db-manager.php` without booting the
  package, so a pipeline can refuse a config before it is deployed.** The installation run
  answers questions about a *running* installation — the provider swap, the weighted factory,
  the published config, the gate, the files, the supervisor step, the store — and every one of
  those would be answered about the installation the command happens to be running in rather
  than the file being considered: a pipeline vetting a branch has the old configuration
  installed, so a report that mixed the two would judge the candidate with the incumbent's
  values and call it a review. `--config-file=path` asks the other question and reads the file
  and nothing else — no container binding, no repository, no boot audit, no database and no
  Redis. It prints three rows. `config file` is the file itself: readable, an array, holding a
  `swrr` block, and printing nothing while it is read; each wrong shape names the mistake — a
  file that throws is a row rather than a stack trace, what a file prints is captured in an
  isolated closure and reported as a `WARN` because `config:cache` would write it into the
  cached file too, and an array carrying `pgcat`/`reader_windows` at the top level is named as
  the `swrr` block passed instead of the file that returns it. `switch values` and
  `reader windows` then report the *refusals* — the values the package will not read — reusing
  the installation rows' own rules rather than copies of them: `Support\SwitchValue` and
  `Support\ReaderWindows`/`ReaderDays` own the reading, the sentences come from the shared
  `switchProblems()`/`readerRefusals()`, and `PgcatConfigFlipper::switchReadingsIn()` classifies
  its two switches from a block rather than from the flipper's own config, so a value this mode
  passes is a value the next boot will not refuse. It reports refusals only, and deliberately
  not the "reads as on but can never act" warnings: those need the resolver built over the
  value — a window whose start is not before its end, a day list with no day in 1…7 — which is
  a fact about a running installation's routing. Nothing is dated either, because a boot record
  holds findings about *this* installation and a candidate has never been booted. The JSON
  envelope is the one installation mode already emits, with the subject key naming what was
  judged — `config_file` here, `connection` and `default_connection` there — and every other
  key in the same order, so one gate can read both. `DbDoctorTest` covers the mode end to end:
  the refusals a candidate holds, that it judges the file rather than the installation, a config
  that reads clean, a deliberate opt-out told apart from a refusal, that it refuses only what
  the file wrote, an unreadable path, a file that is not a config, the `swrr` block passed as
  the file, a file that printed and was still judged, and the JSON envelope.

- **The `pgcat supervisor` row discovers what supervisord actually runs when it does not know the configured program, and names the near miss.** Three of the four faults a flip's last step can have are repaired at the thing the command *is* — install the binary, quote the name, start or unmask supervisord — and the fourth is repaired at a *name*: supervisor does not know `pgcat:*`, and nothing on that host could tell the operator what it should be instead. So that fault, and only that fault, now asks a second read-only question. After `supervisorctl status "pgcat:*"` answers `no such group`, the step derives the bare `supervisorctl status` from the same binary and reads what supervisord is running. The answer is parsed the way supervisor writes it — one line per program, accepted only when its second word is one of supervisor's states, because the same command prints a socket error whose second word is `no` and `supervisord` is a plausible program name — and the running names are ranked against the configured one: the same name, the group that name belongs to (`pgcat` and `pgcat:pgcat_00`), a prefix (`pgcat-1`), a name that holds it (`lpr-pgcat-a`), and last an edit distance within a third of the longer name. A name with nothing in common is not offered, since pointing an operator at a program that was never going to match is a guess dressed as an answer, and the sentence then says so and names the `[program:]` section instead. At most three are named, the group line comes with them when there is one to give (`or the whole group "pgcat_x:*"`), and the wording covers one and several: "the closest is …", "the closest are …". A command that works is unaffected — the second question runs only once the first has already been found missing, so the ordinary flip and the ordinary `db:doctor` still spawn one process. The refusal an operator reads now carries the name to write, which is the half of a `[program:]` repair this host can see; `inspect()`'s verdict gains `running`, `near_misses`, `discovery_command`, `discovery_exit` and `discovery_answer` for a surface that would rather read the list than the sentence. The `suggestion` column stays empty for this fault, and deliberately: the row's sentence offers a ranked near miss to a human, while a suggestion line is a value a gate applies without reading it — the two halves of the fault are documented in `docs/pgcat-suggestion-lines.md`, where the ask was adopted without the line.

- **The README has an alerting cookbook: the rules to paste, and how to tell an installation's fault from the package's once a page has fired.** The package documented *that* something has to alert — the audit's `severity`, the endpoint's `status`, the degradation note — and left the alert itself to the reader, which is the right advice and no help at 03:00. The new section is the whole of it. Three log rules written against the payload rather than the message, because every line the boot audit writes carries `severity` and `finding` in it and how a level is spelled is the handler's business: a refused value standing is a page, a setting that reads as on but cannot act is a ticket, and the resolution line — `warning` with `resolved: true` — is where the page stops. The same rule is given as a CloudWatch metric filter for a JSON channel, as a Loki query, and as the substring match that the default line handler makes of it, which is also the one a reader can test by hand. Then the endpoint: the status code answers one question (did a real query come back on the pinned connection), and six readings it deliberately does not move are spelled out — `pinned.checked` switched off, a worker serving from the in-process store, an unhealthy primary store, the audit's own two conditions, and pgcat armed where it can never act — with the whole lot as one paste-able `curl | jq -e` gate for a cron job, a sidecar or a deploy step. The closing table is the question the cookbook exists for: a refused value and a defect in the package both arrive at `severity: "error"`, and the triage is mechanical — a `finding` key names an owner, and the payload either carries a setting's own evidence or the `levels` / `discarded` keys that mean two branches collided or two boots wrote one record, while a line with no `finding` at all is the store's. The recipes are run rather than reviewed: `ReadmeAlertingTest` extracts the gate from the fenced block and runs it through `jq` against real `/health/db` payloads — healthy, failing, and one per condition the fixture can reach — and resolves every field path the section names against a real payload, so a renamed field is a build failure instead of a rule that silently never fires.

- **The release suite drives the rails it was silent about, and RELEASING.md's table now names the test that proves each row.** Five rails had no test at all — a tree that is not a git repository, a missing `CHANGELOG.md` or `composer.json`, a changelog with no `## Unreleased` heading, a dirty tree, and a shell with no terminal to answer the one question the script asks — and the documented behaviours around two of them had none either: `--dry-run` warning about a dirty tree instead of refusing, and `--allow-dirty` releasing the files a release *writes* without sweeping the uncommitted edit into the tag, which is the rail's own defect one flag away if it ever did. The steps of a release *itself* are covered too, because they fail differently from a rail: a rail refuses before anything is written, while a `CHANGELOG.md`, inventory or `composer.json` that cannot be written, an index git will not take, or a push that cannot run leaves the promoted notes on disk — and the push is the one failure that leaves a version behind, because the commit and the tag happen first. Every one of those is a state of the tree rather than an argument, so each is produced rather than mocked: the fixture gained `drop()` and `makeReadOnly()`, and git is asked to fail the way it fails (a path taken by a directory, a lock file, a remote that is not there). The inventory paths got the case neither half had: a **stale** `files.tsv`/`methods.tsv` on a release that *proceeds*, which is where the file's fate is decided — a release replaces it with the stamp of the tag it just cut, so "stale" is a state the next run recovers from. RELEASING.md's rails table grew a `Proved by` column, and `ReleasingDoc`/`ReleasingDocTest` read the table and the suite back: a row with no test in it, or a test name that no class under `tests/` declares, fails the build — the table is the contract, so a rename is a doc failure rather than a row that quietly stops proving anything.

- **`bin/release.php` refuses to tag a commit CI has not verified.** A tag is a version, and
  publishing one is not undoable, so the commit a release is cut from has to be one the
  workflow has built and passed: HEAD must be the tip of the remote branch, and the run for
  it — a push run on that branch — must have finished green. The rail asks two questions in
  that order because they fail differently: a commit that was never pushed is not one CI can
  have an opinion about, and answering "no run found" for it would send a reader to the
  wrong place. Only runs for a push on the branch being released count, because one commit
  carries several — the `v0.1.0-alpha1` commit has one for `dev` and one for the tag, and
  the tag run reports its head branch as the *tag name* — and a pull request's run does not
  vouch for the branch tip even though it runs the same steps. A run still going is not a
  pass either, and a failure beside a success is not verified, because this rail is only
  allowed to be wrong in one direction. The commit the script then goes on to create is
  bookkeeping (the CHANGELOG, the inventory, the branch alias) and cannot have a run of its
  own yet — it reaches the remote in the push at the end — so the workflow's `v*` tag trigger
  is what records it. A dry run reports the state instead of refusing, because "push this
  first" is the answer the plan exists to give, and the plan's `ci` line says which state it
  found. The question goes to `gh run list --commit <sha>`, which is the same data branch
  protection reads; `RELEASE_CI_COMMAND` overrides the command, for a machine whose `gh`
  lives somewhere else and for a test that has to answer without a network.
- **`--skip-ci` releases without asking.** Nothing verified means no tag, so a machine with
  no `gh`, no token or no network needs an explicit way through rather than a rail that
  quietly reads nothing. It asks nothing at all — it will release on a repository with no
  remote — and the plan prints `not checked (--skip-ci)`, so a release that skipped the
  question says so where the version is being read.

### Changed

- **`db:pgcat-flip`'s `--json` object now writes the envelope's five keys first, with its evidence
  after them.** The keys are the same nine and the values are unchanged; the order is `command`,
  `kind`, `exit_code`, `reason`, `error`, then `mode`, `previous_mode`, `steps`, `status`. The order
  is now a package-wide rule rather than this command's own, because two more commands write the
  same five in the same positions — and it is the reason the envelope can be one class: a reader
  looking at a failing step's output sees the verdict and the code before anything else, whichever
  command produced the object. JSON object order carries no meaning to a consumer, and a consumer
  that did depend on it was depending on something no document promised; the test states the list,
  read from `JsonEnvelope::CORE` rather than restated, and the README's sample and
  [docs/pgcat-flip-json.md](docs/pgcat-flip-json.md) were updated to match.

- **`db:replica-status` prints pgcat's two lines on the route where the connection has no replicas.**
  That route used to print the warning and the audit block and nothing else, which meant the object
  could not carry pgcat's state without the terminal saying something different from the report —
  and pgcat's state is a fact about the installation, not about the replicas being described. Both
  channels now carry the same blocks on every route that read the manager.

- **`--weigh --dry-run` reports the next bump from an empty `## Unreleased`, and says why a
  release of the same tree still refuses.** The empty-notes rail is about what a release
  *publishes* — the notes are the version's record, and a version with none is one nobody can
  read — while the weighing is a question about the *changes*, of which the notes are only one
  of four signals. A plan publishes nothing, so it is now let past the rail to answer the
  second question: it prints the weighed bump whatever the section holds, its `CHANGELOG` plan
  line reads `nothing to promote — ## Unreleased is empty, so a real run refuses here; this plan
  weighs the changes in it instead`, and the weighing table names the empty section instead of
  the generic `no ### heading the policy knows` — an empty section and one written in a
  vocabulary the policy cannot weigh are now distinguishable. Every run that could tag still
  refuses, and the refusal names the flag that reports instead. `--minor --dry-run` is
  unaffected: a declared bump is a check on the weighing rather than a question the changes
  answer, so it is refused like any other.
- **`bin/release.php` ends on one line, and the same line on every run.** The closing note
  used to branch on whether the tree already had a tag, reading that as proof the package had
  been submitted to packagist.org: a first release was told the submission comes first, later
  ones were told Packagist would pick the tag up. Neither is true of every run — a package can
  be tagged repeatedly without ever being submitted, which is a state a tag cannot reveal,
  because a tag on GitHub and a version on Packagist are two different events. The note now
  names the tag being released and both ways the gap closes — submit the package if it never
  was, or trigger a crawl — and points at RELEASING.md's *Publishing* section for which one
  applies. (The 0.1.0-alpha1 entry below describes the branching this replaces.)
- **`GET /health/db` answers `ok` only when one real query has answered.** The endpoint's
  verdict used to be the state store's: a Redis store that replied, together with replica
  lists that were healthy or not declared at all, produced `200 "status":"ok"` — observed
  while application queries were failing with `SQLSTATE[08006]`, because the connection it
  followed declares no `read` list for the replica probe to open. The controller now runs
  one statement — `select 1` — on the connection `ActiveConnection::resolve()` names
  (`swrr.connection`, else `database.default`, so it is the connection the pgcat gate and
  `db:doctor` already judge), through the application's own path so a failure is the failure
  a request would have had, and reports it as a new `pinned` block: connection, driver, the
  config key the name came from, the query, `checked`, the latency and the driver's own
  error. `status` is `ok` only when that query came back; the `replicas` and `audit` blocks
  keep their meaning and no longer decide it. `swrr.health.pinned_query`
  (`SWRR_HEALTH_PINNED_QUERY`, default **on**) turns the query off for the one environment
  where it is not a question worth asking — a fixture or an inspection with no database
  behind the connection — and `pinned.checked` is what distinguishes *no query was asked*
  from *the query passed*. A switch written as neither on nor off is refused, resolves to
  the documented default, and is reported as `pinned.refused`, so a typo cannot be what
  silences the check. Anything that treats this endpoint as a signal — a deploy gate does —
  can now read the status rather than the store, and a caller that needs to know how much was
  asked reads `pinned.checked` beside it.

### Fixed

- **A pgcat that is down is now repaired by the flip instead of refusing it, so the one case where the
  file swap *is* the fix is no longer the one case the flip declines to perform.** `supervisorctl
  status` exits non-zero as soon as a program it was asked about is not RUNNING, so `pgcat:pgcat_00
  FATAL   Exited too quickly` reached the preflight looking exactly like a supervisorctl that could
  not reach supervisord at all: `errored`, `usable: false`, the flip refusing before it touched the
  file. A crash-looping pgcat is crashing on the config that is in place, so the variant the flip
  would write is the repair — and the refusal left a dead pooler holding the config that killed it,
  with nothing on the host willing to replace it. The answer is now its own verdict (`not_running`),
  and it is one the flip proceeds on: it carries the state the answer named (`STARTING`, `BACKOFF`,
  `EXITED`, `FATAL`, with `RUNNING` winning whenever any program of the group is up) and the command
  the repair needs. That command is `start`, not the configured `reload_command`/`restart_command`:
  `signal HUP` and `restart` act on a program that is already up and supervisor answers `ERROR (not
  running)` for one that is not, which is why a flip that found pgcat FATAL would previously have
  replaced a file and changed nothing. `SupervisorStep::startCommand()` derives it from the command
  the flip was already judged on, so the binary, the group name and the quoting cannot drift apart
  from it. The ordering is inverted on purpose for this verdict — file first, then `start`, then the
  state read back up to `swrr.pgcat.start_attempts` times with `swrr.pgcat.start_retry_delay_ms`
  between them, because a program inside supervisor's `startsecs` window answers `STARTING` after a
  start that will succeed and a crash loop answers the same thing after one that will not. If the
  attempts run out the new file stays, since rolling it back would restore exactly the config pgcat
  could not start on, and no mode is recorded — so the next poll writes the same file again and
  tries again, which is the whole self-healing loop. A supervisorctl that cannot reach supervisord
  is still a refusal, and the `errored` branch keeps its own tests: a socket that is not there names
  no program, so there is no state to read and no start that could help.

- **`/health/db` reported on every connection profile in the host application instead of the one
  the package follows, so an unrelated profile could make a healthy installation look degraded.**
  The controller walked `database.connections` and summarised each key, which is the host app's
  inventory rather than the package's subject: the endpoint exists to answer "is the connection I
  route reads and writes through healthy", and the rest of the payload — the replica weights, the
  share %, the store, the formula — was never per-connection data anyway, it is `healthSummary()`
  read once per name. On an installation whose `config/database.php` holds eight profiles that
  produced eight summaries and, worse, eight probes: every connection with a declared `read` list
  was opened with `getPdo()`, so a replica behind a legacy mirror, a per-tenant profile or any
  other connection the package does not follow would fail and turn `status` into `degraded` on a
  machine whose PostgreSQL path was answering, with the failure in `errors` under a connection
  nothing on the page was about. The payload now resolves `ActiveConnection::resolve()` once —
  `db-manager.swrr.connection`, else `database.default`, the same call the pgcat gate, the
  flipper's snapshot, `db:replica-status` and `db:doctor` make — and both halves of the report use
  that one name, so what is summarised and what is queried cannot drift apart. `replicas` keeps
  its shape (a map keyed by connection name) and holds that single entry, so a gate reading
  `.replicas.<name>` is unaffected; with nothing named at all there is no summary to write and the
  map is empty, which is the same fact `pinned` already reports as a failure. Two tests fail
  against the old behaviour — an eight-profile installation whose other seven declare `read` lists
  nothing answers on still reports one connection, `ok`, and an empty `errors`; and the subject is
  `swrr.connection` rather than `database.default` when the two differ — and the two `latency_ms`
  assertions stopped being `assertIsFloat`, which failed whenever a probe rounded to a whole
  millisecond and JSON handed the number back as an int.

- **An unreadable audit record was reported as an installation with nothing standing, because the state the two surfaces had a branch for could not be reached.** `BootAudit::reported()`'s failure branch could only be entered by a `standing()` that threw, and `standing()` could not: every unreadable file — truncated by a `kill` between the write and the rename, hand-edited, or left by a version whose schema differed — was rounded to "nothing recorded", so `/health/db` answered `available: true, count: 0` with `severity: none` and `db:replica-status` printed `nothing standing`, both asserting it about a file the reader had just failed to open. `db:replica-status`'s `Audit: unreadable — <message>` line and the payload's `audit.error` field were therefore defensive code by construction: two surfaces carrying the one branch nothing could drive, while the state it describes is the one an operator most needs named, because a record nobody can read is not a record that says the installation is fine. `standing()` now refuses the rounding — it throws `UnreadableRecord` naming which shape the file is: a path with something other than a file on it, bytes that could not be read, an empty file, text that is not JSON, a JSON list rather than the object a record is, or an object whose `findings` is not the map it claims to hold. The tolerance stays where it belongs: `read()`, the boot's reader, catches it and still reads "nothing recorded", because a record a boot cannot read is one it cannot carry over or close out, the boot is the only writer, and a diagnostic must never stop an application from booting. The split is one parse in one place — `decode()` — with the boot and the surfaces either side of it, and a *missing* file is not one of the six: it is what `persist()` leaves behind when a boot finds nothing left to remember, so it is the one state that is a fact about the installation rather than a failure of the reader, and it still reports `available: true` with an empty list. `reported()` catches the throw into `available: false` with the reason in `error`, so the block finally distinguishes the three states it always had the fields for: nothing registered (`available: false`, `error` null), a record that could not be read (`available: false`, `error` naming it), and a record that was read and holds nothing (`available: true`, `count: 0`). Nothing was added to the surfaces — the terminal line and the payload field were already written and are now reachable — and each is covered by a test that fails if the tolerant reading comes back: six unreadable shapes named in `BootAuditTest`, the boot-writes-while-a-surface-refuses case in one process, the payload's `audit.error` with the check that it leaves `status` alone, and `tests/Unit/Console/DbReplicaStatusTest.php` for the command's four `Audit:` lines.

- **Reads served by the writer outside a reader window kept the address declared in `write[]`.**
  The windowed fallback merged `ConfigValue::assoc($config['write'])` into the connection config,
  and `write` is a *list* of server entries — Laravel's shape for a write connection, and the shape
  a real installation declares — so `assoc()`, which builds a map, returned `['0' => [$entry]]`
  instead of the entry. The address therefore arrived at key `0`, `read` and `write` were dropped by
  the merge, and a connection that declares its address only inside those lists (no top-level
  `host`) reached the connector with no host and no port at all. Laravel took its without-hosts
  path, the connector built `pgsql:dbname=''`, and libpq dialled its default address — a local
  socket, port 5432 — instead of the configured one, so every read outside every reader window
  failed with `SQLSTATE[08006] connection to server on socket "/var/run/postgresql/.s.PGSQL.5432"`
  while writes, which the framework merges itself, kept working: an application that serves
  `/health-check` and answers on the writer, and fails on the first read of an authenticated
  request. Both branches that fall back to the writer now narrow the write side to one entry with
  `pickUnweighted()` — the same pick the read pool already used, and the one the framework's own
  `getReadWriteConfig()` makes with `Arr::random()` — so a list of writers and a single write map
  resolve identically. The branch for a connection with no read list is fixed the same way, and it
  is a no-op without a write config. A merge that still produces neither `host` nor `unix_socket`
  now logs a `[WeightedDB]` warning rather than failing, because omitting both on purpose, to reach
  a local socket, is a legitimate configuration. The suite did not see any of this: both
  writer-fallback fixtures declare `write` as a single map, the one shape `assoc()` wraps
  correctly, and the new test declares the list shape instead.

## 0.1.0-alpha3 (pre-release) - 2026-09-27

### Fixed

- **Reads served by the writer outside a reader window kept the address declared in `write[]`.**
  The windowed fallback merged `ConfigValue::assoc($config['write'])` into the connection config,
  and `write` is a *list* of server entries — Laravel's shape for a write connection, and the shape
  a real installation declares — so `assoc()`, which builds a map, returned `['0' => [$entry]]`
  instead of the entry. The address therefore arrived at key `0`, `read` and `write` were dropped by
  the merge, and a connection that declares its address only inside those lists (no top-level
  `host`) reached the connector with no host and no port at all. Laravel took its without-hosts
  path, the connector built `pgsql:dbname=''`, and libpq dialled its default address — a local
  socket, port 5432 — instead of the configured one, so every read outside every reader window
  failed with `SQLSTATE[08006] connection to server on socket "/var/run/postgresql/.s.PGSQL.5432"`
  while writes, which the framework merges itself, kept working: an application that serves
  `/health-check` and answers on the writer, and fails on the first read of an authenticated
  request. Both branches that fall back to the writer now narrow the write side to one entry with
  `pickUnweighted()` — the same pick the read pool already used, and the one the framework's own
  `getReadWriteConfig()` makes with `Arr::random()` — so a list of writers and a single write map
  resolve identically. The branch for a connection with no read list is fixed the same way, and it
  is a no-op without a write config. A merge that still produces neither `host` nor `unix_socket`
  now logs a `[WeightedDB]` warning rather than failing, because omitting both on purpose, to reach
  a local socket, is a legitimate configuration. The suite did not see any of this: both
  writer-fallback fixtures declare `write` as a single map, the one shape `assoc()` wraps
  correctly, and the new test declares the list shape instead.

## 0.1.0-alpha2 - 2026-09-27
Published without changelog - a mistake - will fix

## 0.1.0-alpha1 - 2026-09-27

### Added

- **`swrr.connection` names the connection the pgcat surfaces follow, for an installation
  whose PostgreSQL path is not `database.default`.** The pgcat gate, `PgcatConfigFlipper`,
  `/health/db`, `db:replica-status` and `db:doctor` all read `database.default` and treated
  it as the connection queries run on. That is wrong for an application that reaches
  PostgreSQL through a *non-default* connection — one named by its own config (say
  `app.default_api_connection`, env `API_DB_CONNECTION`) while `database.default` stays on a
  peripheral database — so the gate warned `pgcat will never act: connection "sqlite" uses
  driver "sqlite"` on a boot that was correctly configured, every boot. `swrr.connection`
  (env `SWRR_CONNECTION`) names the connection instead; left unset the package follows
  `database.default` exactly as before, so existing installations and their tests are
  unaffected. The resolution lives in one place, `Support\ActiveConnection::resolve()`, so
  the gate, the flipper's health snapshot and the doctor cannot disagree about which
  connection is in play.

### Changed

- **The pgcat mismatch sentence names the setting the connection came from.** It always
  said `… Set swrr.pgcat.enabled = false, or point database.default at the pgcat-fronted
  connection`, which sends an operator to the wrong key when the connection was chosen by
  `swrr.connection`. It now names whichever key decided the connection, and `db:doctor`'s
  banner reads `followed connection is …` (the JSON key stays `default_connection`, so a
  gate that already reads it keeps working).

### Fixed

- **The release script closed with a promise that does not hold on a package's first
  release — `Packagist picks the tag up from there`, printed before there is anything for
  Packagist to pick up.** A pushed tag publishes nothing until the package has been
  submitted at packagist.org, so the first release now says the submission comes first,
  and that every tag is picked up from there afterwards. Later releases keep the plain
  line, so the caveat appears on the run where it is news rather than on every one.
- **The closing note promised that Packagist would pick the tag up, and said nothing about
  what to do when it does not — which is the case a release can actually end in.** A tag on
  GitHub and a version on Packagist are two different events: a push that succeeded says
  nothing about whether a version was published, and the usual causes are a hook that did
  not fire or a package that was never submitted. Both exits now name the way to close the
  gap — trigger a crawl by hand — where before only the exit that left the push to the
  reader mentioned Packagist at all, and the one that had just pushed said nothing.

## 0.0.1-alpha1 - 2026-09-26

First working release. The package was ported out of the originating application
verbatim; the following defects came with it and are now fixed — along with one
found in the release tooling written alongside it. Each one is covered by tests.

### Fixed

- **An on/off setting written as something the package could not read as on or off was cast
  into a decision nobody heard about — and on `swrr.pgcat.enabled` that decision armed a file
  swap the operator had asked for the opposite of.** `swrr.allow_local_fallback`,
  `swrr.pgcat.enabled` and `swrr.pgcat.use_reload` were read with `ConfigValue::bool()`,
  whose docblock says what PHP's own rule does: `(bool) 'false'` is `true`. So `'false'`,
  `'off'` and `'no'` — the three ways an operator actually writes *off* — were every one of
  them read as **on**, and `'maybe'`, `''` and `2`, which are not on or off in any reading,
  were decided silently as well. On `swrr.pgcat.enabled` that is not a cosmetic slip: the
  switch arms a runtime file swap, so a typo in it made the flipper read `pgcat.toml`, write
  a variant over it, and signal `supervisorctl` — a change to a running database proxy, for
  the opposite of what was written. Each switch is now read from a closed list of spellings
  (`true`/`false`, `1`/`0`, `'on'`/`'off'`, `'yes'`/`'no'`, case- and space-insensitive), and
  a value that is none of them is **refused** rather than cast: it resolves to the value its
  own documentation prints, and every boot that sees it logs it at `error` level under a
  finding key of its own — `swrr.pgcat.enabled.refused`, `swrr.pgcat.use_reload.refused`,
  `swrr.allow_local_fallback.refused`. The rule, its candidates and the alternatives are in
  [docs/switch-values.md](docs/switch-values.md).
- **A finding could be replaced by another before it was ever logged.** `BootAudit::report()`
  folded its findings into a map — the record is keyed by finding key, and that key is what
  makes "still standing" and "resolved" comparable across boots — and only then logged the
  entries that survived, so when two findings arrived under one key the first was gone
  before anyone read it: a problem the installation has, reported by nobody, while the
  payload still looked complete, and with the key's recorded age quoted beside a sentence
  that had not stood that long. Neither of those can happen now. The fold happens *while*
  logging (`fold()`), so every finding a boot produced is logged in the order it produced
  them, and a shared key logs an extra `error` line naming the key, the two levels, how many
  findings the boot produced, and which sentence the record keeps: the louder of the two
  whichever order they arrive in — a monitor pages on that level, so it cannot be handed the
  quieter half — and the first when they are equally loud. The collision is logged and not
  remembered, because the record's entries are states an operator repairs and a defect in the
  package has no resolution sentence anybody could trigger; nothing about it stops a boot.
  The provider's own assembly, which is where a shared key would come from, is pinned in its
  richest misconfiguration: five findings, five keys, and no collision line among them. The
  reasoning, and the alternatives that were rejected, are in
  [docs/boot-audit-finding-keys.md](docs/boot-audit-finding-keys.md).
- **A boot's recorded findings could be replaced by another boot's before anything read
  them.** `report()` replaces the whole record, and that file is shared by every boot of an
  installation — which is what makes the probe cheap and this write contended — while the
  atomic rename it writes through only protects a concurrent *reader*. Two boots in flight
  together each write from the copy they read, so the later one discarded whatever the
  earlier had recorded: an entry nothing had taken out of the file, gone because the write
  never knew it was there. The record is now read once more as it is about to be replaced,
  and the entries another boot added — the ones this write does not carry — are named in an
  `error` line rather than dropped in silence. Reported, not refused, and not locked:
  refusing would leave the settings that boot checked unrecorded over a race, and a lock left
  behind by a killed worker would stop the record being written at all — the mechanism the
  store-probe decision already rejected for this file. The sibling hazard, two findings
  *within* one boot claiming one key, is `fold()`; this is the same loss across boots.
- **Weighted routing never ran.** Laravel chooses the read replica inside
  `Illuminate\Database\Connectors\ConnectionFactory::getReadConfig()`, using
  `Arr::random()` on the `read` list. `DatabaseManager` — the class the manager
  extends — has no read-selection hook at all, so the ported `getReadConfig()`
  override was unreachable and its `mergeReadWriteConfig()` call had no parent
  method to resolve to. The provider now binds `db.factory` to
  `WeightedConnectionFactory`, which delegates every read-PDO decision to
  `WeightedDatabaseManager::readConfigFor()`.

- **The formula config key was unreadable.** The provider read
  `swrr.default_$defaultWeightFormula` and cast it with `(float)` before passing
  it to `setDefaultWeightFormula(string)`, so `DB_WEIGHT_FORMULA` never took
  effect and every installation ran `linear`. The key is now
  `swrr.default_weight_formula` and the value stays a string.

- **Disagreeing factor defaults.** The provider fell back to a RAM factor of
  `0.5` and the manager to `0.8`, while the shipped config and the README said
  `3.375`. All three now say  `3.375` (`3.0` for CPU), and the provider docblock
  matches.

- **A scalar `swrr.reader_days` sent every read to the writer.** `.env` values
  arrive as strings, and the provider passed them through `(array)`, so `"3"`
  became `['3']` — which never matched `TimeWindowResolver`'s strict
  `in_array($dow, $readerDays, true)`. A bare scalar now means that single day.
  Windows and days are also normalised before they reach the resolver, so a
  malformed `reader_windows` entry can no longer disable reader windows — it is
  refused and reported instead, below.


- **`db:probe-replicas` could not run.** It called
  `WeightedDatabaseManager::getConfiguration()`, which does not exist, and
  `DB::connectUsing($config, Closure)` against the real signature
  `connectUsing(string $name, array $config, bool $force = false)`. The manager
  now exposes `connectionConfig()`, and each probe is a throwaway single-host
  connection that is purged afterwards — the pooled `read`/`write` lists are
  dropped so a probe cannot be routed back through the pool it is measuring.

- **`LocalStateStore` ignored the configured key prefix.** `poolKey()` hardcoded
  `swrr`, so local keys diverged from `RedisAtomicStateStore` whenever
  `SWRR_KEY_PREFIX` was customised. The prefix is now a constructor argument
  wired from `swrr.key_prefix`.

- **Silent degradation.** Falling back to the in-process store happened with no
  trace: no log, and `healthSummary()` still reported the primary store as if it
  were serving. Degrading now logs an `error` once per process (and a `warning`
  when the primary recovers), the fallback is a real injected store instead of a
  process-global `static`, `healthSummary()` reports `degraded` / `primary_store`
  / the store actually serving, and `LocalStateStore::name()` says
  `local(in-process, uncoordinated)` so `db:replica-status` and `/health/db`
  show it.

- **The store resolved the `Redis` facade before the application could bind it,
  and poisoned it for the whole process.** A connection is built while providers
  register (any application touching the schema in `register()` does that), which
  asks the store for a replica before `Illuminate\Redis\RedisServiceProvider` has
  bound `redis`. Resolving that unbound key does not fail — the phpredis extension
  ships a class called `Redis` and PHP class names are case-insensitive, so the
  container builds the extension class — and the facade caches whatever it is
  handed as its root. Every later `Redis::connection()` in that worker then threw
  `Call to undefined method Redis::connection()`, including from Horizon, queues,
  the cache and session drivers, and the boot audit could only report the store as
  unreachable with that artefact as its reason. The store now reaches Redis through
  the container (`Support\RedisAccess`), which checks `bound('redis')` before
  resolving, rejects a binding that cannot open connections by shape, and reports
  what it actually found.

- **A pick made before Redis existed counted as a store failure.** Three of them
  marked the store unhealthy and that worker served from the in-process store for
  the rest of its life, even once `redis` was bound. A store that cannot be
  resolved at all now throws `Exceptions\RedisUnavailable` instead: the caller
  still falls back for that call, the consecutive-failure counter is untouched, and
  one `warning` records it per process (under PHP-FPM every request would otherwise
  log the same line).

- **`db:doctor`'s store row was reporting a facade artefact as a bad server.** It
  probes through the same accessor now, so `the container has no "redis" binding
  yet` and `Connection refused [tcp://…]` are distinguished from each other — they
  are repaired in different places.

- **A flat string in `swrr.reader_windows` silently inverted the fallback.** The way a
  window is written by hand — `'10:00-14:20'` — is not the shape the resolver reads
  (`['start' => '10:00:00', 'end' => '14:20:00']`), and every entry that was not an array
  used to be dropped without a word. Dropping all of them left the resolver reading an
  empty list, which is its documented *permissive* mode: reads used the replica pool at
  every hour while the setting said the writer covered the margins, and nothing anywhere
  said otherwise. Those entries are now refused rather than interpreted or quietly
  dropped — `Support\ReaderWindows` names the entry and the shape it should have had
  (`reader_windows[0] is "10:00-14:20"`), and the whole value being a single string is
  reported the same way one level up. The boot audit logs the refusal at `error` level
  under the finding key `swrr.reader_windows.refused` — the first reader finding that is
  not a `warning`, so `BootAuditFinding` gained a `level` and `BootAudit::report()`
  honours it — and `db:doctor` gained a `reader windows` row that `FAIL`s on it, which
  makes `--strict` fail a deploy. Well-formed entries beside a refused one still apply,
  and the message says how many, so a typo costs the entry rather than the fallback.
  Nothing throws: an installation with a typo boots and serves, it just stops doing so
  silently. The decision and the rejected alternatives are recorded in
  `docs/reader-windows-refusal.md`.

- **A comma-separated `swrr.reader_days` silently inverted the fallback the same way.**
  `'1,2,3'` is how a list is written in `.env` and not the shape this setting reads — a
  day number, or an array of day numbers — and every entry that was neither an int nor a
  numeric string used to be dropped without a word. Dropping all of them left no day at
  all, and no day leaves the resolver in its documented *permissive* mode: reads used the
  replica pool all week while the setting said they stopped outside it, which is the
  defect above one key over. It is now refused the same way a flat `reader_windows`
  string is: `Support\ReaderDays` gained the matching `split()` classifier, which names
  the entry and its shape (`reader_days[1] is "1,2,3"`) and reports a whole value written
  as one string one level up, the boot audit logs it at `error` level under the finding
  key `swrr.reader_days.refused`, and `db:doctor`'s `reader windows` row `FAIL`s on it,
  so `--strict` rejects the deploy. A bare scalar stays accepted — `'3'` is one day,
  which can be read exactly one way — and a list whose other entries are days keeps them
  and says how many. What the resolver does with an unreadable day list is unchanged, and
  it is the permissive side: the refusal is a report, not a routing decision.

- **The reader audit reports every finding it can, not the first one it finds.**
  `readerFallbackFindings()` returned the first branch that applied, so a boot that saw two
  problems named one of them: a flat `reader_windows` string beside a comma-separated
  `reader_days` was one log line, and the second mistake waited for the next boot — or for
  whoever went looking through the log. It now collects them all, refusals first, under
  their own keys, and each is closed out by whichever boot sees its own fix. Two things are
  deliberately not two findings. A finding *key* is one finding — the record keeps one entry
  per key, and `BootAudit` now logs a collision instead of letting a second finding replace
  the first unread, below — and the two causes that meet at `always_writer` (no day
  in 1…7, no window that can ever be entered) are therefore one finding naming both. And a
  finding that does not apply is not reported: permissive mode decides the mode before a
  window is read, so when no day can be matched there is nothing about the windows to say.
  The day half of a double refusal also stopped claiming "no reader_windows are
  configured" when they were configured and refused — it now reports what the pair leaves
  behind.
- **`db:doctor`'s `replica metadata` row reported a pool that had quietly got smaller as the
  installation.** The row's question is "is the pool the read list describes", but it answered it
  from `replicaStatus()` — the pool the resolver *kept* — and the resolver reads a weight through
  `max(0, ConfigValue::int(…))` and a core count through `max(1, …)`. So `'weight' => 'heavy'` (a
  typo, a YAML slip, an unquoted `.env` value) was read as `0`, `0` is how a replica is disabled,
  and the replica left the pool: the row then printed `2 replicas on [pgsql], total weight 80` on
  an installation with three, with nothing to say which replica went or why — the exact failure
  the row exists to catch, reported as health. It now reads the *configured* replicas and fails,
  naming each replica (`[host:port]`, through the manager's own key) and quoting the value:
  `weight is "heavy", which the resolver reads as 0`. A cores or memory value is the same failure
  with the difference stated — the replica stays in the pool, weighted as something other than
  what the read list says — because the resolver substitutes for those rather than dropping the
  replica. The rule is the resolver's own arithmetic rather than a second opinion about it: a
  value that is not a number, or a number under the floor that key is read at (`weight` 0,
  `cpu_cores` 1, `ram_gb` 0). That makes three near-misses reportable for the first time: a
  negative weight, which `max(0, …)` clamps to the disable value its author never wrote; a
  `weight: 0.5` that `ConfigValue::int()` truncates to 0; and a `weight: null`, a key present with
  nothing in it, which no longer reads as "no metadata" — the absent case is still the row's
  warning. `weight: 0` itself is the one difference the package means, so that replica does not
  fail the row; it is **named**, because "1 replica, total weight 10" on an installation with two
  is the same quiet shrink through the front door. The row also stops blaming the symptom when
  every replica's weight is unreadable: `every replica resolves to weight 0` was true and useless
  there, since "every replica is drained" and "every replica's weight is a typo" are one picture
  without the values. Eight tests, one of which pins exactly that — the unreadable value is named
  and the symptom sentence is not.
- **`bin/inventory.php --check` described a file that was already right, and named the wrong
  cause when the rows were right but the bytes were not.** Two faults in the one report that
  exists to catch drift, both found by writing down what it is supposed to say. The verdict is
  about the *pair* of files — `syncInventory()` fails the pair when either differs — but the loop
  that explained it read both files out again and described each one, with no test of whether
  that file had actually differed: a tree with one stale row printed `files.tsv: differs only in
  line endings (rewrite it to pin LF)` beside the real finding, sending a reader to open a file
  with nothing wrong with it. And a file whose rows were all correct was reported as a line-ending
  difference, which is impossible by the time that message is reachable: `--check` normalises CRLF
  before comparing (so a CRLF checkout is *current*, as it should be), which leaves only blank
  lines and trailing space to explain a bytes-only difference. The report now skips the files
  that match the document it is comparing against — compared exactly the way the verdict compares
  them, so the two cannot disagree — and the one message that is left says what it actually is:
  `the rows are right but the bytes are not: a blank line or a trailing space somewhere`. Sixteen
  tests cover the generator for the first time: drift caught and named per file, a missing file
  reported as missing rather than described, a stamp naming another tag, a CRLF checkout accepted,
  the discarded-change warning with its three-bullet cap, and the three exit codes.

### Changed

- **`db:replica-status` says when the boot audit is not registered, instead of printing
  nothing.** The health payload has always answered `available: false` — with `error` null —
  for an installation that has no boot audit, while the command, the other surface that
  publishes the record, printed no `Audit:` line at all. The two could therefore disagree
  about an installation, and the terminal could not tell *no audit registered* from *a record
  with nothing on it*: silence was the answer for both. One accessor now settles it,
  `BootAudit::reported()` — the block the payload embeds, unchanged, and the single place
  either surface decides whether there is a record to read — and the command renders its four
  states from it: findings standing, nothing standing, `not registered — no record is kept,
  so no finding is reported here`, and `unreadable`. The block used to be assembled in the
  controller while the command asked the container for itself, which is exactly how one state
  can arrive on one surface and not the other; the field meanings, including the two ways
  `available` is false and why `error` is present either way, are now documented on the
  accessor rather than twice. Nothing else moved: the payload is byte-identical, no exit code
  or HTTP status changed, and the line names a state rather than a fault — an installation
  that registered the manager without the audit is not broken, it is simply not being
  watched. Two tests pin it, the same state through both surfaces and an empty record told
  apart from no record. The reversal it records — this was a documented limitation, with
  silence as the accepted cost — is in
  [docs/boot-audit-surfaces.md](docs/boot-audit-surfaces.md).
- **The pgcat rows print the config line to paste, where the fault reduces to one.** `pgcat gate`
  and `pgcat supervisor` already named their repairs — the mismatch sentence says `Set
  swrr.pgcat.enabled = false, or point database.default at the pgcat-fronted connection`, and the
  unquoted-program sentence says `Write supervisorctl signal HUP "pgcat:*"` — but a sentence is
  the only place a report could put one, and a `--json` gate cannot select a clause out of
  `detail`. Both rows now carry a `suggestion` line in the shape the config file uses:
  `swrr.pgcat.enabled = false` for the switch armed where pgcat cannot act, and
  `swrr.pgcat.<restart|reload>_command = '<command>'` for the two supervisor faults that reduce to
  one — an unquoted program name (where the value is the command `SupervisorStep` itself built,
  with the name quoted) and a command left empty (where it is the command that setting documents).
  `pgcat files` prints none, and is the only row in the command that never does: every problem it
  reports is a path or a permission, the right value is whatever this installation's pgcat and
  supervisor actually use, and a plausible-looking path pasted into configuration would replace
  the operator's intent with this tool's guess. The sentences are unchanged — the flip's refusal,
  `--dry-run` and `/health/db` read them and have no suggestion column — so the row prints the
  fault for reading and the line for acting, and the two agree. The decision, and the candidates
  including naming a path default and asking supervisor for the program it does know, are in
  [docs/pgcat-suggestion-lines.md](docs/pgcat-suggestion-lines.md).
- **The sample config no longer casts the two `.env`-driven switches.**
  `(bool) env('SWRR_PGCAT_ENABLED', false)` became `env('SWRR_PGCAT_ENABLED', false)`, and
  `allow_local_fallback` likewise. This is what makes the refusal above reachable at all: the
  cast sat upstream of the package, so an installation that set `SWRR_PGCAT_ENABLED=off` had
  already been turned into `true` before any reader could see it — and no downstream decision
  could tell the typo from a deliberate on. `env()` already answers a real bool for
  `'true'`/`'false'` and passes everything else through as written, which is precisely what
  the switch reader wants, so nothing about the documented defaults moved.
- **`PgcatConfigFlipper`'s absent-key fallbacks now match the values `config/db-manager.php`
  ships.** The flipper fell back to `enabled => true` and `use_reload => false` when those
  keys were absent, while the published config prints `false` and `true` for them. Writing the
  refusal's sentence exposed the disagreement: it has to say *which* value the switch now
  holds, and "the value this setting documents" would have been false against the file the
  operator is reading. Both now fall back to the shipped value, which is only reachable for a
  flipper built without those keys — an installation whose published config predates the
  `pgcat` block, or a hand-built one — and in both directions it is the safer and the gentler
  choice: an installation with no pgcat configuration block is now inert rather than armed
  against paths it does not have, and a flip reloads rather than restarting when `use_reload`
  is absent.
- **`db:doctor` has an eleventh row, `switch values`.** The preflight half of the refusal
  above: it fails when any of the three switches is written as something that is not on or
  off, names every one it refuses rather than the first, dates each from *its own* finding
  key, and prints no `suggestion` line — re-spelling `'flase'` would be guessing at what was
  meant, which is the mistake the refusal exists to prevent. On a pass it still says something,
  naming the value of each switch, because "no refusals" is worth reading. The `reader windows`
  row and this one now share the method that builds them (`datedRow()`), so the two cannot
  make their claims about verdicts, suggestions and dates in two different ways.
- **`WeightedDatabaseManager::replicaKey()` is public.** It was already *the* spelling of "which
  replica" — the health monitor keys failures by it and `replicaStatus()` prints its two halves —
  and the preflight needs it in the one case the status table cannot answer: a replica the pool has
  dropped, which is precisely the replica the `replica metadata` row now names. A second spelling
  of a replica's identity in the doctor would have been a second thing to keep in step with the
  health record.
- **`db:doctor`'s `reader windows` row names every reader problem it finds, and dates each
  from its own finding.** The row reported the problem its code reached first and stopped,
  so an installation that had written both `reader_windows` as a flat string and
  `reader_days` as `'1,2,3'` heard about the days only on the *next* preflight — a second
  deploy for a sentence that fitted on the first row, and a report that disagreed with the
  boot audit, which has always been logging both. The row now names every problem it finds,
  in the audit's order, with the verdict being the loudest problem in the list (a refusal
  beside a warning is still a `FAIL`) and one `suggestion` line per repair, since two
  refused settings have two replacements to paste. Each problem is also dated from *its own*
  finding: the record holds one entry per finding key, so `recorded unresolved since …` is
  looked up for the problem being reported rather than for the first entry on file — which
  would have answered "since when" with another problem's date, a claim about when the
  installation started being wrong made about the wrong thing. `recordedFinding()` still
  reads one key for the rows that have one; the reader row reads the whole record
  (`recordedFindings()`) and looks each problem up by the key it came from.
- **`db:probe-replicas` now says in its exit code whether the sweep did its job.** The
  command marked what it found and then exited `0` whatever happened, so a scheduled run
  whose every probe failed — the case the command exists for — reported success to the
  scheduler. It now exits `1` when the sweep reached no replica at all, and `0` when it
  reached one, when the connection has no `read` list (nothing was asked of it), and on a
  *partial* failure, because marking a replica that is down is what the health monitor is
  for and the exit code of a scheduled job has to mean "the job ran". The two ways a sweep
  can come back empty are named separately in the run's closing line — `Nothing could be
  probed: the read list on [x] holds no replica array …` versus `No replica answered: 2
  probed, all failed — nothing was marked healthy.` — because they are repaired
  differently: one is `read` written in a shape that holds no replica, the other is
  replicas that cannot be reached. A `read` that is a single config map rather than a list
  of them is read as one replica, which is what routing already does with it: counted as no
  replica at all, such an installation — whose single replica serves every read — would have
  exited `1` on every scheduled run, and an exit code that is always non-zero is an exit code
  nobody reads.
- **`db:doctor --strict` now says when the flag is what failed the run.** A warned run
  under `--strict` exits `1` while every row printed before it is `PASS` or `WARN`, and the
  closing line used to read `No failures — review the warnings above.` — true of the rows,
  and useless next to a non-zero exit code an operator is about to act on. That line now
  names the cause it can see from the counts: `No failures — but --strict counts the 1
  warning above as failures: this run exits 1 as a release gate, not because the
  installation is broken.` The exit rule itself moved into one method, `gateFailed()`, which
  both the exit code and the summary read, so the sentence an operator gets beside the code
  cannot describe a different rule from the one that produced it. A run *without* the flag
  keeps the old sentence and asserts that it does not blame it.
- **A pgcat flip judges its supervisor command before it replaces the file, and puts the
  previous file back if the command fails anyway.** The flip used to end in two steps whose
  order created a state nobody can see: the variant was renamed over `pgcat.toml`, and only
  then did the flip find out whether the command that makes pgcat pick it up worked. When it
  did not, the new file stayed — disk on the new mode, the running pgcat processes on the
  old one — until the next unrelated pgcat restart, which came up in a mode no window asked
  for. `Pgcat\SupervisorStep::inspect()` is now one verdict for all of it: the flip refuses
  the swap on it (nothing copied, nothing run, no mode recorded, so the next poll retries and
  the flip goes through by itself once the command is fixed), `db:doctor`'s `pgcat
  supervisor` row reports the same sentence, and `--dry-run` prints it as a step. The known
  ways a command can be unusable are all caught before the file moves — an empty command, an
  executable that does not resolve, an unquoted program name the shell may rewrite, a program
  supervisord does not know, and a supervisorctl that never answers. A command that passes
  the check and fails anyway (a race, or a pgcat that will not come back up) is answered by
  the rollback: the previous variant goes back, so what is on disk is what pgcat is running,
  and the error says which of the three outcomes happened — put back, nothing to put back, or
  **not** put back, which is the one that needs a human. The check costs one read-only
  process per flip attempt, and only when a flip is about to happen. Both the ordering and
  the rollback are mutation-tested (moving the check after the copy fails five tests;
  dropping the rollback fails two).
- `db:replica-status` and `db:probe-replicas` default to the `pgsql` connection,
  matching `WeightedDatabaseManager::replicaStatus()`.
- `db:replica-status` prints the store that is actually serving, and warns when
  the process has degraded.
- `WeightedDatabaseServiceProvider::boot()` now calls `parent::boot()`, which is
  what points Eloquent at the manager (`Model::setConnectionResolver`) and hands
  models the event dispatcher.
- pgcat flipping is now PostgreSQL-only. The provider hands `PgcatConfigFlipper`
  the current connection (`database.default`) and its driver, and the flipper
  disables itself for any other driver: `status()['enabled']` is `false` while
  `configured_enabled` still shows what `swrr.pgcat.enabled` says, and both
  `applyCurrentState()` and `forceMode()` return a no-change result that names
  the offending driver — before the lock, the file swap and supervisorctl. A
  MySQL, MariaDB, SQLite or SQL Server installation therefore needs no pgcat
  wiring at all. `status()` also gained `connection`, `driver` and
  `driver_supported`, and `db:pgcat-flip --status` prints them.
- pgcat applicability is now reported on every health surface from one place.
  `PgcatConfigFlipper::healthSummary()` is that place — `status()` spreads it, so
  the two cannot drift — and `/health/db` embeds it as a `pgcat` block while
  `db:replica-status` prints it as a `Pgcat:` line. `DatabaseHealthController`
  takes the flipper as an optional constructor argument, so it still resolves (with
  a `null` block) when the provider is absent. A flipper that cannot act does not
  make `/health/db` unhealthy: on a non-PostgreSQL connection there is nothing to
  flip.

- Static analysis tightened from PHPStan level 6 to **level `max`** over `src`,
  with no baseline file and no ignore entries — all 124 level-10 findings are
  fixed rather than silenced. `STATIC-ANALYSIS.md` records the staged burn-down,
  the measured per-level numbers and the behaviour changes that came with it.
- Config reads are narrowed instead of cast. `Support\ConfigValue`
  (`string`/`int`/`float`/`bool`/`assoc`/`assocList`) keeps PHP's cast semantics
  for scalars but returns the caller's default for anything else, so an array in
  a config file no longer becomes the literal `"Array"`. The manager collapsed
  nine repeated casts into `weightTunables()`, the provider's container closures
  are typed (`function (Application $app)`) and resolve the config repository
  through `$app->make(Repository::class)`, and Laravel's own untyped seams
  (`DatabaseManager::configuration()`, `ConnectionFactory::getReadConfig()`) are
  wrapped where the package promises `array<string, mixed>`.
- A `db.factory` binding that is not a `WeightedConnectionFactory` now raises a
  `RuntimeException` naming the binding when `db` is resolved, instead of a type
  error from inside the constructor. Weighted routing only happens inside the
  factory, so a host app that replaces the binding has to be told.
- `WeightedDatabaseManager::healthSummary()`'s docblock now declares the real
  `replicas` array shape — it said `list<array{...}>`, which left every field
  `mixed` for static analysis and for anything reading the summary.
- The supported PHP version is now one number in every place that states it.
  `composer.json` keeps `"php": "^8.4"` as the floor, the README requirement
  reads 8.4+ instead of 8.2+, the CI matrix runs 8.4 only (its 8.2 and 8.3 legs
  could never have got past `composer install`), and `phpstan.neon.dist` pins
  `phpVersion: 80400` so analysis cannot start depending on APIs newer than the
  floor.
- Releases are tag-driven. `composer release` (`bin/release.php`) derives the
  next version from the latest git tag — `0.0.0` when there are none, so the
  first release is `0.0.1` — promotes the CHANGELOG's `## Unreleased` heading to
  `## X.Y.Z - date` with a compare link, updates the branch alias when a release
  opens a new line, then commits and tags. `--dry-run` prints the whole plan and
  writes nothing. It refuses to run on a dirty tree, off the release branch, for
  an existing tag, for a version that is not newer, or with nothing to promote.
  There is still no version in `composer.json` and no version file: the tag is
  the version. `RELEASING.md` documents the policy and the safety rails.
- `extra.branch-alias` maps both dev lanes to the line being developed: `dev-main`,
  which holds releases, and `dev-dev`, which holds the prereleases cut ahead of them.
  They are two installable names for one line, so a consumer requiring the branch
  gets a version constraint that resolves either way, and the release script keeps
  both of them on that line when a release opens a new one.
- CI pins the root package version. `actions/checkout` now uses
  `fetch-depth: 0` and the workflow exports `COMPOSER_ROOT_VERSION` for the
  branch under test, so Composer never falls back to its `1.0.0` guess and the
  "could not detect the root package version" warning is gone. The dependency
  cache key hashes `composer.json` instead of a `composer.lock` this library
  does not commit, which was freezing the cache forever.
- CI runs for both lanes and for `v*` tags, and runs the whole gate. Both lanes are
  released from — a version with no suffix from `main`, a prerelease from `dev` — and
  a `branches` filter on its own excludes tag refs, which left every published
  version with no CI record of its own. It runs `composer checks`, the same eight
  checks a release is cut behind locally, rather than the three `composer test`
  covers. On a tag build the version pin stands aside: there the ref name is the tag,
  so it would export `dev-v0.0.1-alpha1` — a string Composer accepts and which means
  nothing — and override the version the build is actually about.
- Local checks run through one script: `composer checks` (`bin/checks.php`) runs
  `php -l` over every file, an independent AST parse of the same files,
  `composer validate --strict`, `check-platform-reqs`, the workflow YAML,
  PHPStan, Pint and PHPUnit, prints a single pass/fail summary and exits
  non-zero if anything failed. `composer test` still runs exactly the three
  gates CI enforces. The script invokes each tool as
  `PHP_BINARY <vendor entry file>` rather than through the `vendor/bin` shims,
  so the same command works on Windows and Unix, and reports an uninstalled
  tool as a skip instead of failing the run.

- **A release is weighed, not declared.** `composer release` computes the bump from the
  changes instead of taking `--patch`, which is gone: passing it now exits `2` with a
  pointer to `--weigh`, the flag that reads the Unreleased notes, the commits since the
  last tag, the public surface of `src/` and `config/`, and the inventory the last release
  wrote, and takes the loudest bump they call for. `--minor`, `--major` and
  `--version=X.Y.Z` still declare the bump, but are now refused when they undersell what
  the changes ask for — shipping a breaking change as a patch is the accident this policy
  exists to prevent — while overshooting is allowed with a note, and `--ignore-policy`
  stays the loud way past it. Every signal can only raise the bump and never lower it, so
  one that goes quiet costs a second opinion and nothing else. The plan prints the
  weighing, so the reason a number was chosen is read rather than guessed; the policy
  itself, the four signals and the inventory's stale-is-skipped rule are written out in
  [RELEASING.md](RELEASING.md). The weighing and the gate are pinned against a real
  repository with real tags: each signal that can raise a bump, the 0.x softening, the
  stale-inventory skip and every way a declared bump can undersell the changes are cut as
  releases in a temporary package and read back out of the plan.

### Added

- **The release script can cut prereleases, and refuses the spellings Composer cannot read.**
  A version may now carry a suffix — `-alphaN`, `-betaN`, `-rcN`, or a bare `-dev` — and
  those are exactly the forms Composer's own parser accepts. `0.0.1-dev.1` is *not* one of
  them: SemVer would take it, Composer rejects it, and a tag Composer cannot parse fails
  **quietly** rather than loudly — it never becomes a version anybody can install. So it is
  refused where it is typed, with exit code 2 rather than 1, instead of being written and
  discovered later. The dot is refused for the same reason: Composer reads `-alpha.1` and
  `-alpha1` as one version, so two tags carrying the two spellings would be one version
  published twice. A numbered dev lane is therefore spelled `-alphaN`, because `dev` takes
  no number at all.
  The suffix also decides the branch, in both directions: a prerelease precedes the release
  it is named after, so it is cut from `dev`, and a version with no suffix is cut from
  `main`, with `--branch` overriding either. Promotion is unchanged — the notes move, a fresh
  `## Unreleased` is left above them, and the inventory is stamped with the prerelease tag —
  because a prerelease is a version like any other. A refusal on a tag that already exists
  now also names the next free number in that lane, counted from the tags rather than from
  the number that was typed, so a refused dev tag does not become a guessing game. Pinned by
  `tests/Unit/Release/PrereleaseTest.php`, whose last test asks Composer's own parser whether
  every form this script writes is one it accepts.
- **The boot log names the severity of what it reports, so a log-based monitor can page on a
  refused value without polling `/health/db`.** The audit's whole reason for having two levels
  is that they are two claims — a refused value is input the installation is running without,
  worth waking somebody for, while a setting that reads as on and cannot act is a feature
  quietly not applying — and the endpoint publishes that choice as one field, `severity`. The
  log did not: the level was only in the channel's own record, where how it is spelled belongs
  to whatever handler is configured (`production.ERROR` in the default line log, `level_name`
  under a JSON formatter, a priority number in syslog), and where `ERROR` is every error the
  application ever logs. Every line `BootAudit` writes now carries `severity` in its context,
  beside the `finding` key, holding the level it was written at — so a refused value is
  selected with `severity == "error"`, the same comparison the endpoint answers, and the
  finding is logged on every boot while it stands, so no state beyond the last line per key is
  needed. The line that closes a finding is written at `warning` whatever the finding stood
  at, with `resolved: true`, so a pager stops on the very line that repairs it instead of
  being handed one more `error`; the resolution keeps being a transition, and the level it
  cleared at stays in the record and in the lines it wrote while it stood. The line's own
  level wins the key, so a finding cannot make its line disagree with the loudness it was
  logged at. The rule, its four lines and the seven alternatives — including naming the key
  `level`, carrying the closed finding's level on its resolution line, and a dedicated channel
  — are in [docs/boot-audit-log-severity.md](docs/boot-audit-log-severity.md).
- **`Support\SwitchValue` — an on/off setting read as the value it was written as.** The
  classifier behind the fix above: `read()` returns the switch and the value that could not be
  read, `resolve()` answers the same question without a default (for a report that only wants
  to know whether a value is readable), `ACCEPTED` is the shape every refusal quotes, and
  `describeRefused()` turns settings into the one clause a log line and a table cell both
  print. It knows nothing about which setting it is describing — a default is a fact about a
  setting, not about switches — so each caller passes the value its own documentation prints.
  Its contract is pinned in `tests/Unit/Support/SwitchValueTest.php`.
- **`db:pgcat-flip --json` — one object on stdout, for a pipeline to assert on instead of
  parsing the rendered report.** A CI job that runs a rehearsal had to match `would flip` out of
  a sentence that carries colour tags and wraps at the terminal width, and read the code from a
  second place; it now gets both in one object, with each route of the command reporting rather
  than only the rehearsal. The keys are fixed and always present — `null`, or an empty list,
  where a route has nothing for one — so a rule never has to guard for a missing field, and
  `exit_code` is inside the object because a job that saves the report should not have to
  reconcile it with a number from somewhere else. `kind` is the verdict, from one vocabulary:
  a flip's (`flipped`, `no_change`, `skipped`, `failed`),  a rehearsal's (`would_flip`,
  `would_not_flip`), or one of the four the command answers without asking the flipper
  (`status`, `disabled`, `refused`, `unbound`, drawn from this command's own `KIND_*`
  constants). `steps` is a rehearsal's pipeline with each step's outcome, which is the evidence
  the verdict rests on; a `status` report carries the flipper's own array rather than the
  table's `(not set)` substitutions, because a machine has to be able to test a path for being
  unset. The flag moves no exit code and touches no file: the whole exit matrix runs twice,
  once rendered and once as an object, and asserts the code, the target's bytes and the state
  record for every cell. `--json --watch` is refused the way `--dry-run --watch` is — a report a
  machine reads is one object, and a daemon would print one every interval without ever
  reporting a verdict for the run. The kinds and their codes are documented in the README and
  read back out of it by
  `DbFlipPgcatCommandTest::test_every_json_kind_is_documented_with_its_exit_code`, the same
  guard the exit tables have. The decision, and the eight candidates including NDJSON per pass
  and a `--format` flag, are in
  [docs/pgcat-flip-json.md](docs/pgcat-flip-json.md).
- **`db:doctor --json` — the preflight as one object, so a deploy gate asserts on rows,
  verdicts and suggestions instead of parsing the table.** The doctor is the command a release
  pipeline is most likely to run, and it was the one that could only be read by a person: ten
  rows of prose with a colour-coded verdict in the left column, a `suggestion` line under the
  rows that carry one, and a closing sentence explaining the code. `--json` is the same flag,
  envelope and rule `db:pgcat-flip --json` keeps — one object on stdout and nothing else, a
  fixed key set, the code inside it — so the package has one machine-readable contract rather
  than one per command. Every row is a `checks` entry with its `name` (the row's identity, and
  the same string the table prints), its `verdict` (`PASS`, `WARN` or `FAIL` — the table's own
  three strings rather than a second vocabulary for the same fact), its `detail`, and its
  `suggestions`: the repairs a row offers, which until now existed only as a line standing in
  the renderer's name column. `counts` carries the tally the summary line prints, and the run's
  `verdict` is the loudest row rather than a second opinion about the run — deliberately not
  read back out of `exit_code`, because under `--strict` a warning exits `1` while every row is
  `PASS` or `WARN`. That is the one case where the two disagree, and the fields that join them
  travel beside both: `strict` and `counts`. The flag moves no exit code and runs no different
  checks: the whole exit matrix is asserted twice, once per report, with the object's counts,
  code and row list read against the numbers the rendered matrix asserts for its rows — and a
  test reports a single installation both ways and compares the two row lists to each other,
  which is the claim the matrix cannot make by comparing both against one provider. The verdicts
  are documented in the README and read back out of it by
  `DbDoctorTest::test_every_json_verdict_is_documented`, the same guard the flip's kinds table
  has. The decision is in [docs/db-doctor-json.md](docs/db-doctor-json.md).
- **The flip's exit code is pinned the way the other two commands' are, and documented as a
  table.** `db:pgcat-flip` is the third command whose exit code is computed from more than one
  condition — which branch a run takes is decided by the guard, a refused flag combination, the
  flipper's kind and whether it is armed at all — and its matrix stopped short of two states that
  now have rows: the container answering the flipper's name with something that is not a flipper
  (`1`), and a rehearsal of a forced flip, which is the flip `--force-mode` would have applied
  (`0`). Its mapping was prose in the README; it is now the same table shape as
  `db:probe-replicas` and `db:doctor`, read back out of the README by
  `DbFlipPgcatCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`, with
  `DbFlipPgcatCommandTest::documentedSituations()` as the link between the sixteen cells and the
  eight documented cases. `--status` winning over a rehearsal asked for in the same run gets a
  test of its own, because its only evidence is the line the other branch did not print.
  `docs/documented-exit-codes.md` now also records which of the package's four commands has a
  multi-condition exit and what pins each: `db:replica-status` has one condition, and no matrix.

- **The exit-code matrices now assert themselves against the README's own tables.** Each
  matrix pins what its command does, and the table an operator schedules against was
  maintained by hand beside it — so the enforced rule and the documented rule could disagree
  with nothing to notice. `tests/Support/Readme.php` reads a table out of README.md as data
  (heading, then the first table under it with the column a caller names), and each matrix
  gains a test that asserts every cell's code against the number the table names for the case
  that cell is an instance of, in both directions: a documented case with no cell behind it
  fails, and a cell that does not name the documented case it belongs to fails. The probe
  matrix declares the correspondence as a map (nine cells, five documented cases), the
  doctor's derives it from the counts it already asserts — did anything fail, did anything
  warn — against the table's three rows. Reverting a table cell, adding a documented case,
  rewording one, or dropping one cell's assignment is a failing test, not a silent drift.

- **`db:doctor` prints the repair for a refused `reader_windows` / `reader_days`, not only
  the shape it should have had.** The row named the mistake and described the accepted
  shape, which left the operator to translate one into the other — and for `reader_days` the
  accepted shape is an array that a sentence can only describe. The row now prints the exact
  setting line to paste, under the row and never counted as a check:
  `suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]`.
  `ReaderWindows::suggestion()` and `ReaderDays::suggestion()` re-spell only a value that
  names its own replacement — the flat range, the comma-written day list, a window written
  without its outer list, and well-formed entries repeated exactly as written — and answer
  `null` for everything else, so no plausible-looking repair is printed for an overnight
  range (`'22:00-06:00'`, which this resolver cannot express), an unparseable bound
  (`'9:00-14:20'`, which normalises to midnight) or a name (`'mon'`). The verdict, the counts
  and the exit code are unchanged: the suggestion is the value re-spelled, not a fourth
  verdict. Which values get one is pinned by data providers in `ReaderWindowsTest` and
  `ReaderDaysTest`, and the printed line by `DbDoctorTest`.

- **The health payload's audit block is now alertable without reading it.** A finding's
  `level` is only usable by a consumer that walks the list and compares strings, and the
  distinction an alert actually needs is the one the levels already carry: a value the
  package *refused* is malformed input the installation is running without, while a setting
  that merely reads as on but cannot act is a feature quietly not applying. The block now
  carries `severity` — the loudest level standing, one of `error`, `warning` or `none`
  (`BootAudit::SEVERITY_*`) — and `counts`, the number of findings at each level with both
  keys always present, so a monitor can page on `severity == "error"` and open a ticket on
  `severity == "warning"` in one comparison each. A level outside those two — a hand-edited
  record can hold anything — is counted as the quieter one, the same rule that keeps an
  entry written before levels were kept from reading as an error it might not have been.
  `error` is now always present as well, `null` whenever a record was read, which is what
  tells the two reasons for `available: false` apart: a provider that is not registered
  (`null`) from a record that could not be read (the message).

- **Both schedulable commands now have their exit code pinned as a matrix.**
  `db:pgcat-flip` and `db:probe-replicas` are run by a scheduler, so their exit codes are
  contracts with whatever runs them — and what is true of a code is only what somebody
  wrote down. Each gets a data-provider test that builds every state from a real fixture
  (a pgcat installation in a temp directory for the flip, a connection that really answers
  a probe for the sweep) and asserts, per row, the code, the line an operator reads beside
  it, the sentence the run must *not* claim, and what the row left behind: the target
  file's bytes and the mode record for a flip, the `host:port` results that reached the
  health monitor and the throwaway connection's purge for a probe. `tests/Support/PgcatInstallation.php`
  builds the first fixture, `tests/Support/FakeWeightedManager.php` the second — the real
  manager with the calls a probe reports through recorded, so the probe still really
  connects and really runs its `SELECT 1`. The flags that decide a run without performing
  it (`--dry-run`, `--force-mode`, `--status`, `-v`) are rows too, as are the runs that never
  reach the work at all, which are asserted on what the work was asked for.

- **A `store probe` row in `db:doctor`, for the check that can never run.** The store
  reachability finding is produced by a boot-time `PING` that is throttled by the audit
  record, and two states skip it in silence: `swrr.audit.store_probe_seconds = 0`, and
  an audit record that cannot be written (the record *is* the throttle, so without it
  the audit skips the probe rather than issue one per request). In both, no store
  finding is produced or resolved, and the reachability row's `PASS` means "this boot
  did not check" rather than "the store answered". The new row reports the interval,
  the record path and its writability, and where that leaves things: `PASS` when a
  probe can run, `WARN` when probing is switched off or the primary store is in-process
  (moot, not missing), `FAIL` when the record cannot be written. A row that can be about
  more than one of those names all of them rather than the first it reaches — switched
  off *and* unable to write the record is one deploy's worth of reading, not two — and
  takes the loudest as its verdict, so a choice that has been made unwritable reads as
  the failure it is. The interval and the
  writability come from `BootAudit::storeProbeStatus()`, the same source
  `storeProbeDue()` reads, so the row cannot disagree with the runtime about whether a
  probe happens; `WeightedDatabaseServiceProvider::storeSelection()` is public for the
  same reason.

- **`db:doctor`'s `pgcat gate` row reads the boot record, not only its own verdict.**
  The row judged the configuration in front of it, so a preflight could call an
  installation clean while the record standing on disk said a mismatch had been seen
  and never closed out — and when the gate was checked with `pgsql` while
  `database.default` was mismatched, it reported a state the installation was not in.
  It now reports both halves: its own verdict, and `recorded unresolved since …` for a
  mismatch an earlier boot logged, first-seen timestamp included, which is what
  distinguishes this deploy's own change from one it inherited. A record naming a
  connection the run does not inspect warns rather than passing silently, and
  `--strict` fails it. The finding key is public on the provider so the doctor reads
  the same record by the same name.

- **The boot-time pgcat warning now has a resolution half.** It logged the
  mismatch — `swrr.pgcat.enabled` on for a connection pgcat cannot front — and
  then nothing, so a log that reported the problem never said it was over.
  Correcting it means editing configuration, which is read once per process, so
  the boot that sees the fix is a different process: no static guard can tell it
  there is anything to resolve. The unresolved mismatch is now written beside the
  flipper's state file (`pgcat-flip-state.json` → `pgcat-flip-state-gate.json`, so
  it inherits that path's persistence), and the boot that finds the gate open while
  that record stands logs `Pgcat flipping is active, so the earlier mismatch no
  longer applies` and deletes it. A resolution is a transition: it is logged once,
  an installation that was never mismatched never writes the file and never claims
  a correction, and both writes are silenced and never fatal — `db:doctor`'s
  `pgcat files` row reports an unwritable directory where someone can act on it.
- **`db:doctor` can now prove the flip's last step works, without taking it.** The swap
  is two halves — the file and the command — and only the file was checked. The new
  `pgcat supervisor` row judges the command the flip will actually run
  (`PgcatConfigFlipper::supervisorCommand()`, the same method `swap()` uses): it resolves
  the executable the way a shell would (an absolute path, or a name searched on `PATH`
  with PATHEXT extensions on Windows), fails a pattern character in the program name
  that the shell may rewrite, and then asks supervisor — read-only — whether that
  program is one it knows, reporting its state when it is. The inspection runs the
  flip's own command with `status` in the verb position (`supervisorctl status
  "pgcat:*"`), so nothing is restarted, signalled or stopped; the tests pin that by
  asserting on the commands that reached the runner. All four ways the step fails —
  unresolvable binary, rewritten program name, supervisorctl that cannot answer,
  supervisor that does not know the program — are `FAIL`s, because each of them leaves
  the new file already in place. The row is moot while the flipper is not armed, exactly
  like `pgcat files`.
- **The shipped supervisor commands now name pgcat the way supervisorctl needs to hear
  it.** `supervisorctl restart pgcat` became `supervisorctl restart "pgcat:*"` — and the
  same for the reload — in the sample configuration and in the flipper's own fallback
  defaults. The name has to match the supervisor group or program the operator runs,
  and the quotes are what stop the shell from expanding the wildcard before supervisorctl
  receives it.
- **`db:pgcat-flip --dry-run` rehearses a flip instead of taking it.** The flip ends in two
  irreversible steps — the rename over `pgcat.toml` and the supervisor command — and the
  only way to know whether a window boundary would work was to let it arrive. The new flag
  performs every step that leaves nothing behind and reports the rest, so the answer comes
  before the window: the source config is read for real, the temp file the swap writes is
  written for real in the target's own directory (proving the one thing `db:doctor` judges
  from metadata and never proves) and removed again, the lock is taken and released, and the
  rename, the supervisor command and the state write are printed as what a flip *would* do.
  The state file is not written — recording a mode that was never applied would make the
  next real flip believe it had happened — but its writability is reported from metadata,
  because a flip's write to it is silenced and an unwritable one means a flip repeats on
  every poll. `--dry-run` exits `0` when a flip would happen and when there is nothing to do,
  and `1` only when a step a flip needs did not work, so a deploy can use it as a preflight;
  `--force-mode` combines with it, `--status` wins when both are passed, and `--watch` is
  refused because a rehearsal loop looks exactly like a flipping one.
- **The boot audit's standing findings are readable away from the log.** The record kept
  a finding's key and context, while the sentence explaining it existed only in the line
  each boot wrote — so an installation could carry a setting that reads as on but cannot
  act for weeks, with nothing but a log to say which one or why. The record now keeps
  that sentence and the level it was logged at, and `BootAudit::standing()` presents the
  findings oldest first, each with an age in words. `GET /health/db` carries them in an
  `audit` block (availability, count, oldest timestamp, findings) and `db:replica-status`
  prints the same list — including on its no-replicas path, because a finding is about
  the installation rather than one connection. Neither changes its own contract: the
  endpoint's `status` still means live reachability, since a configuration that cannot
  act is not a database that cannot answer, and the command still exits `0` because
  `db:doctor --strict` is the release gate. Records written before the level was kept
  still read, as warnings with no sentence. The surfaces that were rejected — a command of
  its own, a second endpoint, and a status code that would evict an instance over
  configuration — are recorded in `docs/boot-audit-surfaces.md`, next to the store-probe
  timing and the reader-windows refusal.

- **The release remembers what it shipped.** The tree at the last tag is written down as
  two TSV files at the package root — `files.tsv`, one row per file under `src/` or
  `config/`, and `methods.tsv`, one row per public method with its required and total
  argument counts and the shape of each parameter — and the next release reads them as a
  fourth versioning signal beside the notes, the commits and the tag diff. The release
  commit rewrites both, stamped with the tag it is creating, so the record names where it
  was taken; a file stamped with anything else is reported as **stale** and skipped rather
  than trusted, because believing a lazily refreshed inventory is exactly how a breaking
  change ships as a patch. What this covers is the case the tag diff cannot: a history
  with no useful tag to compare against still has a written list of the files and methods
  it had, so a rename or a signature that changed shape is seen rather than remembered.
  `php bin/inventory.php` regenerates both by hand — that is for looking, since the
  release script is the intended writer — and its `--check` compares without writing and
  is deliberately not a CI gate: an inventory kept in step with every commit can never
  witness a change, and witnessing one is the only thing it is for. The format is TSV so
  a row is one `explode("\t")` with no quoting rule to get wrong, and one symbol per line
  so a rename reads as two lines of `git diff`.

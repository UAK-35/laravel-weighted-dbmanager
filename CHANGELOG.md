# Release Notes

## Unreleased

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

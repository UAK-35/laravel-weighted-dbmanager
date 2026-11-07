# Plan - `db:doctor` follows the package's connection when the argument is absent

Created: 2026-11-06. Status: implemented on 2026-11-06; unreleased.

## Context

`db:doctor`'s positional argument is declared as `{connection=pgsql}`, so a bare
`php artisan db:doctor` inspects the literal connection name `pgsql`. Every other surface in the
package resolves the connection through `src/Support/ActiveConnection.php`:
`db-manager.swrr.connection` when the installation sets it, `database.default` otherwise. That is
the connection the gate, the flipper, `/health/db`, `db:replica-status` and the doctor's own
`connector` row follow.

The live deployment is the case that exposed the difference. The task
`5c608bc4e70c4f43a3b8bbc5099ede5b` (cluster `lpr-backend-cluster-01`, service
`lpr-backend-sites-svc-02`, container `lpr-backend-cntnr-01`, region `us-east-1`) has:

- `.env.prod`: `DB_CONNECTION=sqlite` and `API_DB_CONNECTION=pgsql_proxy`;
- `config/db-manager.php`: `'connection' => env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'pgsql'))`,
  so `db-manager.swrr.connection` is `pgsql_proxy`;
- `config/database.php`: the `pgsql` block is **commented out**, and `pgsql_proxy` is the active
  connection with a `write` entry at `127.0.0.1:5433` (`writer_db_1`) and a `read` list of two
  entries (`writer_db_1`, weight 145, and `reader_db_1`, weight 500, both at `127.0.0.1:5433`).

A bare `php artisan db:doctor --connection-only --env=prod --json` on that task reports
`connection: "pgsql"`, `swrr_connection: "pgsql_proxy"`, `default_connection: "pgsql_proxy"`, a
`connector` row for `pgsql_proxy`, and a `replica metadata` warning for `[pgsql]` saying there are
no read replicas. Passing the followed connection explicitly
(`php artisan db:doctor --connection-only --env=prod pgsql_proxy`) makes `replica metadata` pass
with `2 replicas on [pgsql_proxy], total weight 645`. The warning in the bare run is therefore a
wrong-subject reading, not an installation without replicas. Report
`docs/reports/0003-2026-11-06_rpt-db-doctor-warnings-encoding.md` records the live evidence.

## What a bare `db:doctor` did before this change

1. **The argument is filled by the signature.** `db:doctor` is declared as
   `{connection=pgsql : Connection whose weighted replicas are inspected}`. Symfony Console returns
   the literal `pgsql` from `$this->argument('connection')` when the caller names no connection, and
   nothing in the command distinguishes "the caller passed `pgsql`" from "the signature supplied
   it".
2. **`handle()` normalises that value.** It reads
   `$argument = $this->argument('connection')` and falls back to `'pgsql'` for a non-string, then
   holds the result in `$connection`.
3. **Three row groups take `$connection`:**
   - `replicaMetadata($manager, $connection)` - the `replica metadata` row, including the
     `no read replicas on [...]` warning;
   - `readPathRows($manager, $connection)` - the `read/write pair` row and, when
     `swrr.read_path.enabled` is on, `read caps` and `standby feedback`;
   - `storeReachability($manager, $connection)` - the store reachability row in a full run.
4. **The subject and the other connection-aware rows do not take it.** `followedConnection()` and
   `configuredConnection()` resolve `ActiveConnection` for the subject's `default_connection` and
   `swrr_connection`; `connector()` uses `ConnectorInUse::resolve()` (the same active connection);
   `realQuery()` calls `followedConnection()`; `pgcatGate()`, `variantShape()` and the flipper rows
   read the flipper's own configuration. The `connection` key in the JSON is the argument.
5. **The two sources can therefore disagree in one report.** On the live task `pgsql` is not even a
   defined connection (its block is commented out), so the rows in step 3 inspect an undefined
   connection and report its absence, while the subject, `connector` and `real query` rows name
   `pgsql_proxy`. The `read/write pair` row describes a driver-default `127.0.0.1:5432` host the
   application never uses.
6. **`--config-file` uses the same argument differently.** That mode reads a config file rather than
   the running installation and passes `$connection` to `vetConfigFile()` to name the connection
   whose read list is judged in the file. There is no `ActiveConnection` for a file that is not the
   installation, so its default has to remain a name the mode owns.
7. **`--contract` inherits the installation defect.** It builds the same installation rows and
   maps them to requirements; the `replica metadata` reading is the one from step 3.

The inconsistency is therefore narrow: the argument is the only connection source for three row
groups, and its literal default is the only place the package does not use `ActiveConnection`.

## Goal

A bare `db:doctor` inspects the connection the package follows, so its rows agree with the gate,
the flipper, `/health/db`, `db:replica-status` and its own `connector` row. Naming a connection
explicitly still inspects that connection, exactly as today. `--config-file` keeps its own
file-vetting semantics.

## Steps

### 1. Make the argument optional and resolve it through `ActiveConnection`

Change the signature from `{connection=pgsql : ...}` to an optional argument with no literal
default:

```
{connection? : Connection whose weighted replicas are inspected; when omitted, the connection the package follows (db-manager.swrr.connection, else database.default)}
```

In `handle()`, read the argument once and resolve the absent case:

```php
$argument = $this->argument('connection');
$provided = is_string($argument) ? trim($argument) : '';
$connection = $provided !== '' ? $provided : $this->followedConnection();
```

`$this->followedConnection()` already returns `ActiveConnection::resolve(...)['connection']`, which
is the one rule the rest of the package uses. An empty or whitespace-only argument is treated as
absent, matching `ActiveConnection`'s own `!== ''` test. Do not add a second resolution path or a
config switch.

**Verification:** a bare run of a fixture whose `database.default`/`swrr.connection` is not `pgsql`
reports that name in `connection` and in the rows in step 3 of "What a bare `db:doctor` does
today"; the explicit-argument run still reports the name it was given.

### 2. Keep `--config-file` on its own default

The file-vetting mode is not the running installation, so do not resolve it through
`ActiveConnection`. Resolve the installation `$connection` as in step 1, and pass the file mode its
own fallback:

```php
if (is_string($candidate) && $candidate !== '') {
    ...
    return $this->vetConfigFile($candidate, $provided !== '' ? $provided : 'pgsql');
}
```

The literal belongs to the file mode alone. If a later change wants the file's own
`database.default` as its default, that is a separate decision with its own tests; it is not part
of this fix.

**Verification:** `db:doctor --config-file=path` with no connection still judges `pgsql` in the
vetted file, and with `connection` names the named connection, exactly as today.

### 3. Make the subject and banner state the resolution

`$connection` now equals `$this->followedConnection()` on a bare run, so the JSON subject's
`connection` and `default_connection` agree. The banner already prints both; add a clause when no
connection was named, so an operator reading `Connection inspected: pgsql_proxy, followed connection
is pgsql_proxy` knows the command chose the followed connection rather than being told to inspect
it:

```
 - no connection was named, so the package's followed connection was inspected
```

The `swrr_connection`/`default_connection` pair keeps its current meaning: the value the setting was
written with and the connection the package follows (the key stays `default_connection` for gates
already reading it).

**Verification:** the JSON subject and the banner agree with `followedConnection()` on a bare run,
and an explicit argument still shows the inspected name beside the followed one.

### 4. Tests

In `tests/Unit/Console/DbDoctorTest.php`:

- **The live shape.** A fixture whose `database.default` and `swrr.connection` are a non-`pgsql`
  connection (`pgsql_proxy`-like, with a `read` list) and whose `pgsql` is absent; a bare
  `Artisan::call('db:doctor', ['--json' => true])` reports `connection` = the followed name,
  `swrr_connection` = the configured name, and `replica metadata` passes with the replica count.
  This is the regression the live task found.
- **The explicit argument wins.** `db:doctor ... pgsql` still inspects `pgsql` and produces the
  same rows it does today, including the no-read-replicas warning when `pgsql` has no read list.
- **The config-file mode is unchanged.** `--config-file` with no connection still judges `pgsql`;
  with a connection it judges that one.
- **The contract mode follows the installation connection.** A bare `--contract` run's
  `replica metadata` reading is the followed connection's.
- **The argument is optional.** The command definition's `connection` argument is not required, or
  a bare `Artisan::call('db:doctor', ['--json' => true])` reaches the row list rather than failing
  the required-argument check.

The existing tests pass `'connection' => self::CONNECTION` (`weighted` in the test app) explicitly,
so they keep their meaning; the new tests cover the absent-argument branch. Audit any test that
calls `db:doctor` without the argument and expects `pgsql` rows.

**Verification:** the focused `DbDoctorTest` passes, including the new live-shape and explicit-
argument cases.

### 5. Documentation and notes

- `README.md`: the command table/usage changes `{connection=pgsql}` to an optional connection and
  says a bare run follows `ActiveConnection`; the bounded-preflight and doctor sections say the
  same. The existing example that prints `[pgsql]` is either made an explicit-argument example or
  updated to the followed connection.
- `docs/db-doctor-json.md`: the `connection` key is the resolved name when the argument is absent;
  `swrr_connection` and `default_connection` keep their meaning. Add the bare-run behaviour to the
  subject section.
- `src/Console/Commands/DbDoctor.php`: the class docblock, the `subjectLine()` docblock and the
  argument help say which connection a bare run inspects.
- `CHANGELOG.md`: a `### Changed` entry - a bare `db:doctor` now follows the package's connection;
  a caller that wants the literal `pgsql` must pass `pgsql`. Run `php bin/counts.php` and
  `php bin/counts.php --check` if a record's stated value changes.

**Verification:** the docs guards and the fast gate pass.

### 6. Validate, then verify on the live task after release

Run the focused tests, then `php bin/checks.php` on a stable tree. After the package release is
installed in the application (a consumer-side `composer.json`/lock update under that repository's
rules, not this package's), run on the task:

```
php artisan db:doctor --connection-only --env=prod --json
```

It must report `connection: "pgsql_proxy"`, `swrr_connection: "pgsql_proxy"`,
`default_connection: "pgsql_proxy"`, and `replica metadata` PASS with the two replicas (total
weight 645); the only remaining warning should be `published config` (the separate generation
warning). The explicit escape hatch must still work:

```
php artisan db:doctor --connection-only --env=prod --json pgsql
```

That run must still report `connection: "pgsql"` and the no-read-replicas warning, because the
argument was named.

## Risks and dependencies

- **A CLI contract change.** Changing the argument from a required-with-default to optional means a
  bare run that previously inspected `pgsql` now inspects the package's connection. That is the
  intent, but a script that relied on the old literal and runs against a non-`pgsql` installation
  must add `pgsql` explicitly. The `### Changed` note is the record of it.
- **`database.default` can be a non-PostgreSQL connection.** In the live app it is `sqlite`; the
  package follows `swrr.connection` (`pgsql_proxy`) when set. If an installation sets neither and
  its default is `sqlite`, a bare run follows `sqlite` and the `replica metadata` row warns about
  it - which is consistent with every other package surface, and an operator can name the weighted
  connection explicitly.
- **The config-file mode must not lose its default.** Resolving `ActiveConnection` before the
  config-file branch would change what a file vet judges; step 2 keeps the literal for that mode.
- **The live verification depends on the deployment.** The package change can be released and
  tested here; the task's `vendor/` pin is the application repository's change, and the live check
  happens after that update.

## Deliberately left out

- No change to `src/Support/ActiveConnection.php`: it is the one rule the fix uses.
- No change to the doctor's verdicts, its row vocabulary, `gateFailed()`, `--connection-only`,
  `--no-runtime-paths`, `--strict`, `--json` or `--contract` beyond the connection resolution.
- No change to `--config-file`'s connection semantics; the option to resolve its default from the
  vetted file's `database.default` is named as a separate decision.
- No change to the published-config generation warning or to the encoding of user-facing strings;
  those are the subjects of report `0003` and are not this defect.
- No application-side pin, release or deployment from this package; the consumer update is a
  separate repository's change.

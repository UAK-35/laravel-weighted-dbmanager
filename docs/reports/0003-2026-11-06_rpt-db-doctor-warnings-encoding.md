# Two `db:doctor --connection-only` warnings, and the mojibake in their output

Written: 2026-11-06. Subject: a live server's
`php artisan db:doctor --connection-only` report, the two warnings it prints, and the
`ΓÇö` sequence in their sentences.

## The command and its mode

`db:doctor --connection-only` bounds the run to what a build container can answer. It leaves out
six rows whose subject is outside the process - `pgcat supervisor`, `pool probe answer`,
`read caps`, `standby feedback`, `store probe`, `store reachability` - and asks everything that is
about this boot, this configuration and these files, including `replica metadata` and
`published config`. `src/Console/Commands/DbDoctor.php` owns both rows and the mode; the mode's
left-out set is `REACHES_OUT`.

Both warnings are `WARN`, not `FAIL`. The exit rule is `gateFailed($failed, $warned)`:
`$failed > 0`, or `$warned > 0` *and* `--strict`. A plain `--connection-only` run exits `0` with
these two warnings; `--connection-only --strict` exits `1` because strict turns warnings into a
gate failure. Neither warning is caused by the bounded mode - a full run asks the same two rows and
prints the same verdicts.

## Warning 1 - `replica metadata`

```
WARN  replica metadata    no read replicas on [pgsql] - every read uses the writer
```

### What the row reads

`DbDoctor::replicaMetadata()` reads the *followed* connection's configuration:

- `$manager->connectionConfig($connection)` returns the connection's config block;
- `ConfigValue::assocList($config['read'] ?? [])` is the `read` list;
- when that list is empty, the row returns `WARN` with the sentence above.

The connection is the one `Support\ActiveConnection` resolves for the whole run:
`db-manager.swrr.connection` when it is set, otherwise `database.default`. The live report says
`[pgsql]`, so that is the followed connection, and its `read` array is empty.

### Why it fires

An empty `read` list means this Laravel connection has no weighted replicas. Every read on it
reaches the writer, and the package's Smooth Weighted Round-Robin read routing has nothing to
distribute. That is a fact about the connection, not a malformed value; the row reports it as a
warning because a single-writer installation is legitimate.

Two topologies both produce it, and they mean different things:

1. **A genuine writer-only installation.** There are no replicas, or the application deliberately
   sends reads to the writer. The warning is accurate and can be accepted.
2. **A pgcat-fronted installation.** The application connects to the pgcat pooler (for example a
   `pgsql` connection whose host and port are the pooler), and pgcat owns the replica routing
   through its `[pools.*.shards.*]` servers. The Laravel connection's `read` list is empty by
   design. This row cannot see pgcat's pool topology - it reads only
   `database.connections.<name>.read` - so it reports the Laravel side, which is writer-only, as
   the warning above. It is not evidence that the pooler has no replicas.

The package's weighted read routing and pgcat's pooler routing are two layers. This row judges the
first; `pool probe answer` (left out by `--connection-only`) is the row that asks the second.

### What to do

- If this application is meant to use the package's weighted reads, add the replica entries to the
  followed connection's `read` array - host, port, database, username, password, and the
  `weight`/`cpu_cores`/`ram_gb` metadata - or point `db-manager.swrr.connection` at the connection
  that already carries that list.
- If pgcat is the read router, the warning is expected. There is no single-row suppression; the
  bounded mode does not change it, and `--no-runtime-paths` (the entrypoint-path exception added in
  `v0.2.0-alpha19`) does not touch this row. A gate that treats every warning as a failure should
  either accept this one explicitly or configure the read list the package can read.

## The live task: why it warns about `[pgsql]`, not `[pgsql_proxy]`

The live run is not a case of "no replicas". It is the command inspecting the **wrong
connection**. This section is the live evidence for that, taken from the running task on
2026-11-06.

### The task and the command

| | |
|---|---|
| Region / profile | `us-east-1` / `lpr` |
| Cluster / service / container | `lpr-backend-cluster-01` / `lpr-backend-sites-svc-02` / `lpr-backend-cntnr-01` |
| Task | `5c608bc4e70c4f43a3b8bbc5099ede5b` |
| Image | `863518412634.dkr.ecr.us-east-1.amazonaws.com/lpr/backend-image:7c10ea9f4baf9de3bde05d458575259cda7b287d` |
| Command | `php artisan db:doctor --connection-only --env=prod --json`, from `/app/site0/html` |

The JSON object is the evidence. It carries both names at once:

```json
{
    "connection": "pgsql",
    "swrr_connection": "pgsql_proxy",
    "default_connection": "pgsql_proxy",
    "checks": [
        {"name": "connector", "verdict": "PASS",
         "detail": "connection [pgsql_proxy] (driver pgsql) is built by ..."},
        {"name": "replica metadata", "verdict": "WARN",
         "detail": "no read replicas on [pgsql] — every read uses the writer"},
        {"name": "read/write pair", "verdict": "PASS",
         "detail": "connection [pgsql] declares neither half of the pair: one host (127.0.0.1:5432) ..."}
    ]
}
```

`connection` is the command's positional argument. `swrr_connection` and `default_connection` are
the package's resolved setting (`ActiveConnection`: `db-manager.swrr.connection`, else
`database.default`). They disagree, and that disagreement is the finding.

### What the task's configuration actually says

`/app/site0/html/.env.prod` (only the connection keys, no secrets):

```
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/database.sqlite
API_DB_CONNECTION=pgsql_proxy
```

`/app/site0/html/config/db-manager.php`:

```php
'connection' => env('SWRR_CONNECTION', env('API_DB_CONNECTION', 'pgsql')),
```

so `db-manager.swrr.connection` resolves to `pgsql_proxy` (from `API_DB_CONNECTION`).

`/app/site0/html/config/database.php`:

- the `pgsql` connection block is **commented out** - there is no `pgsql` connection in this
  application;
- `pgsql_proxy` is active: a `write` entry at `127.0.0.1:5433` (`writer_db_1`), and a `read` list
  with two entries - `writer_db_1` at `127.0.0.1:5433` with `weight` 145, and `reader_db_1` at
  `127.0.0.1:5433` with `weight` 500.

### The mechanism

`DbDoctor::handle()` takes the positional `{connection=pgsql}` argument and passes it to every row
that takes a connection name:

```php
$rows[] = $this->replicaMetadata($manager, $connection);
$rows = array_merge($rows, $this->readPathRows($manager, $connection));
...
$rows[] = $this->storeReachability($manager, $connection);
```

The signature's default is the literal `pgsql`, so a bare `db:doctor` inspects `pgsql`. In this
application `pgsql` is not defined at all (its block is commented out), so it has no `read` list and
the row prints "no read replicas". The same run's `connector` row and the subject fields use
`Support\ActiveConnection`, which reads `swrr.connection` (`pgsql_proxy`), so the report can carry
`connection [pgsql_proxy]` in one row and `no read replicas on [pgsql]` in another.

### The proof

Passing the connection the package actually follows makes the row pass:

```
php artisan db:doctor --connection-only --env=prod --json pgsql_proxy
```

That run reports `connection: "pgsql_proxy"` and:

```
replica metadata   PASS   2 replicas on [pgsql_proxy], total weight 645
read/write pair    PASS   connection [pgsql_proxy] declares both halves of the pair: reads reach
                          2 read endpoint(s) (127.0.0.1:5433, 127.0.0.1:5433) and writes reach
                          127.0.0.1:5433 from its `write` block ...
```

The only warning left is `published config`. The application's replica configuration is therefore
correct; the bare command was asking about a connection the application does not define.

### What this means and what to change

- For this task, run the doctor with the followed connection when the read routing is what you
  want judged:
  `php artisan db:doctor --connection-only --env=prod pgsql_proxy`.
- The package has a UX/consistency defect: `db:doctor`'s `connection` argument defaults to a
  literal `pgsql`, while the gate, the flipper, `/health/db`, `db:replica-status` and the doctor's
  own `connector` row all follow `ActiveConnection`. A bare `db:doctor` therefore judges a
  connection the package does not follow whenever `database.default` (or `swrr.connection`) is not
  `pgsql`. The fix belongs in the package: resolve the argument through `ActiveConnection` when it
  is absent, or drop the literal default and make the argument optional. This report does not make
  that change; it records the defect and the evidence.
- The `published config` warning in the same run is real and independent of the connection: the
  published file has no `generation` key (a grep of `/app/site0/html/config/db-manager.php` for
  `generation` returned no line, and the run's `published config` row warned). See the next section.

## Warning 2 - `published config`

```
WARN  published config    config/db-manager.php is published and differs from the sample, but the
file declares no generation: it was published before the key existed (v0.2.0), so every key added
to the sample since is read from its default - republish with --force and review the new values
```

### What the row reads

`DbDoctor::publishedConfig()` compares the application's published `config/db-manager.php` with the
package's sample. A missing copy, or a byte-identical copy, is its own warning. When the copy exists
and differs, the row reads the key set generation out of *the file itself*:
`DbDoctor::publishedGeneration()` requires the file and passes the returned array to
`Support\ConfigSchema::of()`.

`ConfigSchema` owns the contract:

- `ConfigSchema::KEY` is `generation`, a top-level whole number in the sample;
- `ConfigSchema::GENERATION` is `4`, the current key set;
- `ConfigSchema::of()` answers `present`, `declared` and `raw`; a file with no such key reads
  `present: false`;
- `ConfigSchema::sentence()` turns that into the warning above.

The package sample, `config/db-manager.php`, declares `'generation' => 4`.

### Why it fires

The published file has no `generation` key. That is the shape of every copy published before the
key existed - generation 1 was introduced by `v0.2.0-alpha5`, and generations 2, 3 and 4 by
`v0.2.0` - so this copy predates that key set. The package's config repository is a merge of the
sample and the published copy, with the published copy winning key by key; a key the copy does not
have falls back to the *sample's* value. The installation therefore keeps working, but any setting
added to the sample since the copy was published is read from its default rather than from a value
the installer reviewed. That silence is what this warning exists to break.

The parenthetical `(v0.2.0)` is `ConfigSchema::release(ConfigSchema::GENERATION)`, i.e. the release
of the *current* generation. The generation key itself first shipped in `v0.2.0-alpha5`, so the
sentence names the current rung rather than the missing key's first release. The actionable half -
"republish with `--force` and review the new values" - is correct either way.

### What to do

1. Back up and diff the published file against the sample (or against the package release you are
   upgrading from) so the application's edited values are not lost.
2. Republish:
   `php artisan vendor:publish --tag=db-manager-config --force`.
3. Review the new values the sample adds. The generation history in `ConfigSchema::RELEASED_IN`
   names what arrived after generation 1: the repair-rate settings (generation 2), the health
   endpoint's `host_interface` (generation 3) and the pooler's `refresh_command` (generation 4).
   Re-apply the application's own values where the diff shows them.
4. Confirm the row passes: the published copy should declare `'generation' => 4` and differ from
   the sample only where the installer edited it.

Adding `'generation' => 4` to the old file by hand silences the row, but it does not add the keys
the sample gained since; only the republish-and-review step does.

## The `ΓÇö` in both sentences

`ΓÇö` is not a package encoding fault and not corrupted data. It is the UTF-8 bytes for an em dash
(`—`, U+2014, `E2 80 94`) decoded by a console that is using the OEM/CP437 code page:

```
UTF-8 bytes  E2 80 94  ->  CP437 glyphs  Γ Ç ö  ->  ΓÇö
```

The source and the output are valid UTF-8. The package does not transcode them; the terminal (or a
tool between the process and the reader) is decoding the bytes as CP437.

The same console will misrender every non-ASCII mark in the doctor's output. The package's runtime
strings use:

| mark | code point | UTF-8 | shown on CP437 |
|---|---|---|---|
| em dash `—` | U+2014 | `E2 80 94` | `ΓÇö` |
| en dash `–` | U+2013 | `E2 80 93` | `ΓÇô` |
| ellipsis `…` | U+2026 | `E2 80 A6` | `ΓÇª` |
| right arrow `→` | U+2192 | `E2 86 92` | `ΓåÆ` |
| bullet `•` | U+2022 | `E2 80 A2` | `ΓÇó` |
| check `✓` | U+2713 | `E2 9C 93` | `Γ£ô` |
| cross `✗` | U+2717 | `E2 9C 97` | `Γ£ù` |
| multiplication `×` | U+00D7 | `C3 97` | `├ù` |

There is no emoji in the package's runtime output; the `🤖` in this repository appears only in
generated commit-message trailers, not in `db:doctor`'s rows. A scan of `src/` finds the marks above
and no characters outside the Basic Multilingual Plane.

### What to do

- Use a UTF-8 console. On Windows: `chcp 65001`, and in PowerShell
  `[Console]::OutputEncoding = [System.Text.Encoding]::UTF8`. On Linux/macOS, run under a UTF-8
  locale (`LANG=C.UTF-8`, `LC_ALL=C.UTF-8`) and a UTF-8 terminal, or capture the output to a file
  and open it as UTF-8.
- `--json` output is valid UTF-8 too: the doctor writes it with `JSON_UNESCAPED_UNICODE`, so a
  consumer must decode it as UTF-8. A JSON parser does that by default.
- If ASCII-only output is a hard requirement for a log pipeline, the package would have to replace
  those marks in user-facing strings with ASCII. That is a package change, not a configuration
  change, and it is not made here; the live server's version (`v0.2.0-alpha18`) and the current
  `v0.2.0-alpha19` both use the em dash in these two sentences.

## What was and was not established

Established from the package source and the live task:

- both warnings come from `db:doctor`'s own rows and both are `WARN`, not `FAIL`;
- on the live task, `replica metadata` fires because the **command argument** is `pgsql`, not
  because the application has no replicas: `pgsql` is commented out in `config/database.php`, while
  `pgsql_proxy` - the connection `db-manager.swrr.connection` resolves to - has two read entries
  (weights 145 and 500);
- running `db:doctor --connection-only --env=prod pgsql_proxy` makes `replica metadata` PASS with
  `2 replicas on [pgsql_proxy], total weight 645`, leaving only `published config` warning;
- `published config` fires because the application's published `config/db-manager.php` has no
  `generation` key;
- the `ΓÇö` is CP437 rendering of a UTF-8 em dash, not a package write.

Not established:

- whether the defaults the old published config inherits for newer keys are acceptable for this
  deployment - that is the review step, not a reading;
- whether the package will change the `connection` argument's default; the current live image
  (`7c10ea9f`, package `v0.2.0-alpha18`) and `v0.2.0-alpha19` both default it to `pgsql`;
- a live run of the `published config` repair was not attempted - republishing would overwrite the
  application's file and is the owner's call.

## Files and commands read

Package source:

- `src/Console/Commands/DbDoctor.php` - `handle()`'s `$connection` argument, `replicaMetadata()`,
  `readPathRows()`, `storeReachability()`, `connector()`, `publishedConfig()`,
  `publishedGeneration()`, `asks()`, `gateFailed()` and the `REACHES_OUT` table.
- `src/Support/ActiveConnection.php` - the `db-manager.swrr.connection` / `database.default` rule.
- `src/Support/ConfigSchema.php` - `KEY`, `GENERATION`, `RELEASED_IN`, `of()`, `sentence()`.
- `config/db-manager.php` - the sample's `'generation' => 4` and its comment.
- `README.md` - the `replica metadata` row, the bounded-preflight section and the `published
  config` example.

Live task (`aws ecs execute-command`, cluster `lpr-backend-cluster-01`, task
`5c608bc4e70c4f43a3b8bbc5099ede5b`, container `lpr-backend-cntnr-01`, region `us-east-1`):

- `php artisan db:doctor --connection-only --env=prod --json` from `/app/site0/html`;
- `php artisan db:doctor --connection-only --env=prod --json pgsql_proxy`;
- `.env.prod` connection keys (`DB_CONNECTION`, `DB_DATABASE`, `API_DB_CONNECTION`);
- `config/db-manager.php` `connection` and `generation` lines;
- `config/database.php` connection blocks (`pgsql` commented out, `pgsql_proxy` active).

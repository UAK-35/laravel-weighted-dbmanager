# Investigation — a bare `select 1` through pgcat on 5433

The report was that `select 1` hangs through the pooler while `select 1 as ok` returns. It does
not reproduce on this deployment, with any client protocol available in the container — and the
alias cannot be the reason. What does fail, and fails for **both** queries, is the named
prepared-statement path that Laravel and PDO use by default.

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task read | `144683894d6d4086ba901faf67e81e96` (and `f7bd22f8ef1a49f4bc7b8de5ff0a381e`) |
| Region / profile | `us-east-1` / `lpr` |
| Pooler | `/usr/local/bin/pgcat /etc/pgcat/pgcat.toml`, **pgcat 1.2.0**, listening `0.0.0.0:5433` |
| Pools | `writer_db_1` (RDS primary), `reader_db_1` (second RDS instance), `pool_mode = transaction` per user |
| Config read | `/etc/pgcat/pgcat.toml` — passwords not read out; the probes below take them from the file in-process |

## Short answer

1. **The alias does not change pgcat's behaviour.** `?column?` is PostgreSQL's own name for an
   unaliased expression; pgcat forwards the rows and the row description without reading either.
   Nothing in the pooler branches on a result column name.
2. **It did not reproduce.** `select 1` and `select 1 as ok` both return, over the simple query
   protocol and over the unnamed extended protocol, in single statements, in batches and inside an
   explicit transaction — 40 fresh connections each, no hang and no error.
3. **The one protocol that fails, fails identically for both.** A *named* prepared statement —
   what `PDO::prepare()` with `ATTR_EMULATE_PREPARES => false` (the default every Laravel
   connector sets in `Connector::getOptions()`) and
   `pg_prepare()` send — answers
   `ERROR: prepared statement "…" does not exist` on `execute`. That is pgcat's transaction-mode
   limitation with `prepared_statements` unset, and it is a real defect of this deployment; it is
   just not alias-sensitive and not a hang.
4. **Where a query can genuinely wait** is the pool checkout: `max_wait_time = 600000` in
   `[general]`, so a client that cannot get a server waits up to **ten minutes** before pgcat
   answers `could not get connection from the pool`. That path is triggered by pool pressure, not
   by the text of the statement.

So: not a pgcat version quirk for `select 1` against `select 1 as ok`; a version-and-config quirk
in the neighbouring area (prepared statements in transaction pooling), which is worth fixing on its
own evidence.

## Evidence

### The matrix

Every row was run inside the task, against `127.0.0.1:5433`, as the published pool user, with an
8-second `timeout` so a wait shows as `exit=124` rather than blocking the session.

| Client path | `select 1` | `select 1 as ok` |
|---|---|---|
| `psql -c` (simple query) | returns `1` in `?column?`, exit 0 | returns `1` in `ok`, exit 0 |
| `begin; select 1; commit;` | `BEGIN / 1 / COMMIT`, exit 0 | — |
| `select 1; select 1 as ok;` in one batch | both rows, exit 0 | both rows, exit 0 |
| `pg_query_params()` — unnamed extended (`Parse ""`/`Bind`/`Execute`/`Sync`) | returns `["?column?" => "1"]`, exit 0 | returns `["ok" => "1"]`, exit 0 |
| PDO, `ATTR_EMULATE_PREPARES => true` (client-side interpolation) | returns, exit 0 | returns, exit 0 |
| PDO, `ATTR_EMULATE_PREPARES => false` + `prepare()` | `SQLSTATE[26000] … prepared statement "pdo_stmt_00000001" does not exist` | **the same error** |
| `pg_prepare()` + `pg_execute()` | `ERROR: prepared statement "s9" does not exist` | **the same error** |

Forty fresh connections per query over the simple protocol: `bare: hangs=0 errors=0 of 40`,
`aliased: hangs=0 errors=0 of 40`. Both pools answer the same way; `reader_db_1` was checked
separately and returns `select 1` as well.

### The configuration has no prepared-statement support switched on

`/etc/pgcat/pgcat.toml` carries `max_wait_time`, `connect_timeout`, `healthcheck_timeout` and
`healthcheck_delay`, and nothing else that governs query handling: no `prepared_statements`, no
`prepared_statements_cache_size`, no `query_parser_enabled`, no `default_role`. In pgcat's   documentation the cache size defaults to zero, which is "disabled", and the documented failure mode
   for a client that prepares anyway is exactly the error above —
   [pgcat issue #734](https://github.com/postgresml/pgcat/issues/734) is the same error from another
   installation in transaction mode.

### The pooler's own binary does not know `select 1`

`strings /usr/local/bin/pgcat` contains the prepared-statement vocabulary (`Checking for prepared
statement`, `Got bind for unknown prepared statement`, `Evicted prepared statement`) and **no**
`SELECT 1` / `select 1` literal. So the pooler is not running `select 1` as a health check that a
client's identical statement could collide with, which was the one mechanism that would have made
the reported pair differ.

## Why the alias cannot be the difference

`select 1 as ok` and `select 1` are the same statement to a proxy: one target list entry with a
constant, no parameters, one result column. The only difference is the byte string of the column
name the origin sends back (`ok` versus `?column?`), and pgcat neither parses nor rewrites a
`RowDescription` — it relays the origin's frames to the client, which is why both queries show the
same timing and the same exit codes above.

A difference that appears only when the column is named therefore lives in the **client**: a driver
or ORM that special-cases `?column?` (some ORMs key result mapping on column names), a wrapper that
retries when it cannot map a row, or a UI that hides unnamed result sets. Those are client bugs and
should be proven with the client's own wire traffic, not by changing the SQL.

## Where a real wait can come from

Three mechanisms in this stack can make a query appear to hang, and none of them looks at the SQL
text:

1. **Pool checkout.** `max_wait_time = 600000` — ten minutes before
   `FATAL: could not get connection from the pool`. If the pool is exhausted by a slow statement or
   a long transaction, the *next* query waits, and which query that is depends on arrival order, not
   on its shape.
2. **pgcat 1.2.0's unnamed-statement handling.**
   [Issue #875](https://github.com/postgresml/pgcat/issues/875) reports a client that sends several
   unnamed `Parse` messages before one `Sync` (JDBC and Liquibase do this) losing a mapping in
   1.2.0. That produces a failure in the same family as the matrix's last two rows, for clients that
   batch parses — again independent of an alias.
3. **Cursors across a transaction pool.** A `DECLARE … CURSOR` and the `FETCH` after it can land on
   different servers in `pool_mode = transaction`; the client then waits for a cursor the second
   server does not have.

## Fix, in the order worth trying

1. **Enable prepared statements where Laravel needs them**, at pool level:

   ```toml
   [pools.writer_db_1]
   prepared_statements = true
   prepared_statements_cache_size = 50000
   ```

   and the same under `reader_db_1`, then reload the pooler. Re-run the PDO row of the matrix: it
   should return `1` instead of `SQLSTATE[26000]`.
2. **Or turn emulation on for this connection** (`PDO::ATTR_EMULATE_PREPARES => true`, or Laravel's
   `options` array for the connection). That sends the simple protocol, which the matrix shows is
   healthy today, and costs a client-side interpolation rather than a round trip.
3. **If a client still hangs, capture the wire instead of guessing.** On the task:

   ```sh
   timeout 20 tcpdump -i any -A -s0 'tcp port 5433' -w /tmp/pgcat-5433.pcap
   ```

   provoked by the single statement that hangs. A server that sent `RowDescription`,
   `DataRow`, `CommandComplete` and `ReadyForQuery` while the client waited is a client-side defect;
   a connection that sent `Parse` and never received `ParseComplete` is a pooler-side one, and the
   statement text belongs in that report.
4. **Leave `select 1 as ok` out of the fix.** It is not a workaround for anything in the pooler, and
   adopting it as one would hide the client defect a capture would name.

## What was not established

* No client that actually hangs was available here, so the report's shape — a hang, as opposed to
  the error the matrix produces — is unconfirmed. Everything above says what *would* have to be
  true for the pair to differ, and how to capture it.
* pgcat 1.2.0 is the newest release this deployment has access to, so "fixed in a later version"
  cannot be asserted. The public release list shows 1.2.0 as the latest tag in the material read,
  which is a limit of that material rather than proof that no later fix exists.
* The application's own connection is configured for `127.0.0.1:5432`, where nothing was listening
  in the tasks read on 2026-10-20, while the pooler listens on `5433`. That is a separate defect
  with its own record, and it is why `db:doctor`'s `connector` row cannot build the connection; it
  is not the cause of the alias question, since no client reaches the pooler through that config.

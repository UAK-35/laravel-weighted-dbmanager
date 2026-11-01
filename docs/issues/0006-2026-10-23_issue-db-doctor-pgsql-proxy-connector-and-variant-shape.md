# Issue — `db:doctor` on `pgsql_proxy`: two failures, and a warning that is already fixed

| | |
|---|---|
| Where | the LPR application task, `/app/site0/html` — the run below is `2026-10-22 21:21:36`, `prod.INFO`, `db:doctor` with no arguments |
| Followed connection | `pgsql_proxy`, named by `db-manager.swrr.connection` |
| Config read | `/app/site0/html/config/db-manager.php`, `/app/site0/html/config/database.php`, `/etc/pgcat/*.toml`, `/var/swrr/` |
| Verdict printed | 24 checks: 19 passed, 3 warnings, 2 failed — *this installation will not behave as configured* |

## The two failures, and what each one is

### 1. `connector` — the connection is never built at all

```
FAIL  connector  connection [pgsql_proxy] (driver pgsql) is built by
      Illuminate\Database\Connectors\PostgresConnector, but the connection itself
      could not be built: Undefined array key "database"
```

The class is the framework's own, so the sentence reads as a fault in the connection's
configuration. It is not. `pgsql_proxy` is written as a **read/write pair**: `read` and
`write` each hold a list of one configuration, and the settings — `database`, `host`,
`username`, `password` — live *inside* those halves, because that is where Laravel's
`ConnectionFactory` merges them from. The base block holds nothing but `driver`.

The package's `ConnectorInUse::connection()` did this before building:

```php
unset($declared['read'], $declared['write']);
$built = $factory->make($declared, $name);
```

Dropping the two lists is deliberate — it keeps `make()` off the read picker, so a
preflight that answers a question about a *class* does not choose a replica and open a
socket. But the lists are also where the settings are, so what reached the factory held
`driver` alone, the framework's `createSingleConnection()` read `$config['database']`,
and the warning became an exception the reading reported as `connection_error`.

**So the connection is buildable and the installation is configured correctly.** The
defect is in the reading, and the fix is in this package: keep the halves out of the
config `make()` is given, but take the settings out of one first — the **write** half,
because it is the connection rather than a replica pick, with the read half used only
when there is no write half at all. Both surfaces read through the same call, so the fix
clears the row *and* the boot finding, which is the line that was appearing on every boot:

```
prod.WARNING: [WeightedDB] The connection this package follows [pgsql_proxy]
(db-manager.swrr.connection) is built by Illuminate\Database\Connectors\PostgresConnector,
but the connection itself cannot be built, so every query on it fails: Undefined array
key "database"
```

That warning is `swrr.connector.unusable` with `build_failed` in its context, and it is
wrong for the same reason. `ConnectorInUseTest` now holds a connection whose settings live
entirely in its halves — the shape this installation has — so the reading cannot drop them
again.

Nothing is to be changed in `config/database.php` for this row. Adding a `database` key to
the base block would silence the warning and leave the read/write pair describing two
different databases, which is a worse state than the one being reported.

### 2. `variant shape` — the writer-only variant puts the reads back

```
FAIL  variant shape  swrr.pgcat.no_readers_path [/etc/pgcat/pgcat-no-readers.toml]
      declares pool "writer_db_1", which connection "pgsql_proxy" weights in its
      read list — the writer-only variant exists to leave the readers out, so a flip
      to it would put them back.
```

This is the same fault the `db:pgcat-flip --force-mode=readers` record names, and it is
still here: the *target* the flip swaps in and the *weights* the application resolves
disagree about which pool reads should reach. `pgcat-no-readers.toml` declares
`writer_db_1` (correctly — it is the writer-only config), but `pgsql_proxy`'s read list
in `config/db-manager.php` also names `writer_db_1`, so after a deactivation the
application's reads still resolve to it. A flip then changes the pooler and not the
routing, which is the state that looks healthy and reads from the primary.

Fix, in the application's `config/db-manager.php`: `pgsql_proxy`'s read list names
**`reader_db_1` only** (and, if the installation wants the writer as a fallback, that is
`swrr.allow_local_fallback`, which is the switch that says so). Then
`db:doctor`'s `variant shape` row passes, and a flip to the writer-only variant actually
moves reads.

## The three warnings

- **`published config` — no generation key.** `config/db-manager.php` was published
  before the key existed (v0.2.0), so every key added to the sample since is read from
  its default. Republish with `php artisan vendor:publish --tag=db-manager-config --force`
  and review the new values. This is also why the next item exists.
- **`replica metadata` — no read replicas.** On this installation there are none: one
  host, one writer. Nothing to repair; it is the fact that makes `reader_db_1` in
  `pgcat.toml` the only read path, and it is why the `variant shape` failure matters more
  than it reads — the reads it puts back are on the primary.
- **`flip schedule` — two mechanisms own the mode.** On the version this ran, the row
  still prints the retired sentence. **This one is already fixed in the package** (the
  mode record now carries `flipped_via`, both mechanisms write it, and the pair is a
  pass — see the changelog's `### Changed` and `### Removed` for the batch that retires
  `swrr.pgcat.schedule.both_armed`). It disappears with the next deployment, and with it
  `recorded unresolved since 2026-10-22T17:03:25+00:00`.

## The finding buried in a PASS

```
PASS  schedule beat  this container recorded no boot time (swrr.pgcat.boot_file is not
      there), so the boot window is not judged and neither is the beat
```

That row is a PASS by *absence*, and the absence has a consequence the sentence does not
draw: with no boot stamp, `FlipWindow::closed()` is false for the container's whole life,
so the per-minute entry never stands down — it keeps taking its lock and recording a run
every minute the container lives. On an installation where the reader-window tasks are
also armed, the pair of mechanisms is therefore a genuine pair of owners all day, not the
`480s` overlap the warning describes.

The row above it prints the path (`boot stamp [/var/swrr/container-booted-at]`, mode
0664, owned by `www-data`), so the *file* is provisioned and writable — what is missing is
the setting that points at it. Setting `SWRR_PGCAT_BOOT_FILE=/var/swrr/container-booted-at`
in the environment (and republishing the config, which is the warning above) makes the
window judged, the beat judged, and the entry stand down as designed.

## What this output does *not* show

- Nothing was connected to: `real query` says *not asked*, so no row here is a claim
  about the database itself. `--real-test` is what makes one.
- The `pool probe` PASS is the pooler's own console: the pools the configuration
  *declares* (`writer_db_1`) are the pools it holds. It is not a statement that the
  application reaches them — the `connector` failure is why.
- `pgcat supervisor`, `pgcat files`, `write paths` and `pgcat mode` are all green: the
  flip machinery itself is healthy. Every failure on this page is configuration, and each
  is repairable without touching the pooler.

## Order to fix

1. **Deploy the package fix** — the reading that drops the read/write halves. It clears the
   `connector` row and the `swrr.connector.unusable` warning together, and it is the only
   item on this page that is the package's rather than the installation's.
2. `pgsql_proxy`'s read list — so a flip moves reads rather than rewriting a file.
3. Republish the config with `--force`, and set `SWRR_PGCAT_BOOT_FILE` — which clears the
   published-config warning and makes the boot window judgeable.
4. Deploy the package version that retires the both-armed finding (already in the branch —
   the same release can carry both fixes).

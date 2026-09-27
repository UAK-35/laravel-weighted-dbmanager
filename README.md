# uak35/laravel-weighted-dbmanager

Smooth Weighted Round-Robin (SWRR) read-replica routing for Laravel 12.

Laravel picks a read replica with `Arr::random()`, which ignores how much
hardware each replica actually has. This package swaps that pick for a
`WeightedDatabaseManager` that:

- resolves an integer weight per replica from `cpu_cores` / `ram_gb` (linear or
  diminishing formula), or from an explicit `weight` key;
- runs one SWRR step per read connection — the same algorithm nginx uses for
  upstreams, so distribution is exact over Σweights draws and never clumps;
- keeps the SWRR state in Redis behind a Lua `EVAL`, so N PHP-FPM workers share
  one rotation instead of drifting apart;
- degrades to an in-process store when Redis is unavailable, and says so;
- tracks replica health with exponential cool-down (30 s → 30 min) and filters
  failing replicas out of the pool;
- can hand reads to the writer outside configurable `reader_windows`, and keep
  `pgcat.toml` in lock-step with that decision.

## How the wiring works

Laravel chooses the read host in
`Illuminate\Database\Connectors\ConnectionFactory::getReadConfig()` — **not** in
`DatabaseManager`. So the provider does two things:

1. binds `db.factory` to `WeightedConnectionFactory`, and
2. installs a resolver on it that calls
   `WeightedDatabaseManager::readConfigFor()` for every read PDO.

`readConfigFor()` then decides, in order: the writer (when a `TimeWindowResolver`
says we are outside every reader window) → Laravel's own random pick (when the
replicas carry no `cpu_cores` / `ram_gb` / `weight` metadata, so upgrading is a
no-op) → SWRR over the weighted, health-filtered pool.

Binding the factory is what makes any of this run; the manager alone cannot
intercept the choice.

## Requirements

- PHP 8.4+ — the floor `composer.json` enforces with `"php": "^8.4"`
- Laravel 12 (`illuminate/console`, `illuminate/database`, `illuminate/http`,
  `illuminate/redis`, `illuminate/routing`, `illuminate/support`)
- Redis (`ext-redis` or `predis/predis`) if `swrr.primary_store` is `redis` — the
  default. `local` works without Redis but only coordinates within a single
  process.
- A host app whose replica entries carry `cpu_cores`, `ram_gb` or `weight`.
- pgcat — optional, and PostgreSQL-only: the flipper never acts on a MySQL,
  MariaDB, SQLite or SQL Server connection (see [Pgcat](#pgcat)).

## Installation

```bash
composer require uak35/laravel-weighted-dbmanager
```

Two steps follow, and neither is optional:

1. **Publish the config, then edit it.** The application owns this file:

   ```bash
   php artisan vendor:publish --tag=db-manager-config
   ```

   See [The application owns its config](#the-application-owns-its-config) for what
   that means in practice.
2. **Register the provider.** It replaces the framework's `DatabaseServiceProvider`
   and is not auto-discovered — see
   [Register the provider](#register-the-provider-explicit--it-is-not-auto-discovered).

### The application owns its config

Every install keeps its own `config/db-manager.php`, and that copy is the
configuration the application runs on. The file shipped inside the package is the **sample** it is published from.

The sample holds sample values — values that illustrate the shape of the config,
not values chosen for your installation. Publishing it is therefore the start of the
config step rather than the end of it: the installer is expected to open the
published copy and change it. That is the intended workflow, not a workaround.

If the published copy is missing the package still merges its sample as the base,
so nothing breaks — but the app is then running on sample values, which is an
unfinished installation rather than a configured one.

### Publishing on Composer's install and update events

Publishing is a Composer step, but Composer will not take it on the package's
behalf: **only the root package's scripts run**, so the scripts declared in this
package fire when this checkout is the root and never when an application requires
it as a dependency. The application declares the call itself, beside its other
publish scripts:

```json
"post-install-cmd": [
    "@php artisan vendor:publish --tag=db-manager-config --ansi"
],
"post-update-cmd": [
    "@php artisan vendor:publish --tag=db-manager-config --ansi"
]
```

Note the missing `--force`: `vendor:publish` copies a file only when the
destination does not exist and reports `SKIPPED` when it does. That is precisely
the behaviour this setup wants — every install ends with a config the application
owns, and no install ever writes over the copy the installer has edited.

An unknown tag is not an error either: the framework prints
`No publishable resources for tag [...]` and exits `0`, so the line is harmless in
an application that has the package installed but not yet wired up. Deploys that
run `composer install --no-scripts` skip it; run the artisan command from the
release step instead.

The package ships the same call as a script of its own — the one its
`post-install-cmd` and `post-update-cmd` events run — and you can point it at an
application by hand:

```bash
composer publish-config                   # from a checkout of this package
php bin/publish-config.php path/to/app     # or at an application, from anywhere
```

Given a directory that is not an application, it says so and exits `0`, so a stray
call is never an error. Given an application it runs `vendor:publish` for the tag,
and fails with a hint when the tag does not resolve — which is what happens while
the provider is still unregistered, since the tag comes from the provider's
`boot()`.

### Linking into a local application (read the package from disk)

To point an application at a working copy of this package instead of a tagged
release, register the checkout as a Composer path repository and require it at
its dev version. `bin\composer-link.cmd` does both, from the application root:

```bat
rem run this from the root of the Laravel application, not from the package
bin\composer-link.cmd ..\path\to\laravel-weighted-dbmanager
```

It reads `name` out of the package's `composer.json`, writes a
`repositories.local` path entry into the application's `composer.json`, and
requires `<name>:@dev`. Composer then **junctions**
`vendor\uak35\laravel-weighted-dbmanager` onto the checkout, so an edit under
`src/` is live in the application with no commit and no `composer update`.

Anything after the directory is forwarded to `composer require`, so flags pass
straight through:

```bat
bin\composer-link.cmd ..\path\to\laravel-weighted-dbmanager --dry-run
```

`--help` prints the full description. `COMPOSER_LINK_COMPOSER`,
`COMPOSER_LINK_PHP` and `COMPOSER_LINK_JQ` override the programs it detects.
PHP reads the JSON, so unlike the bash original this needs no `jq`.

Undo with `composer remove uak35/laravel-weighted-dbmanager` and
`composer config --unlink repositories.local`.

### Register the provider (explicit — it is not auto-discovered)

The package provider extends `Illuminate\Database\DatabaseServiceProvider` and
must **replace** it: two providers binding `db` means whichever registers last
wins. That is why `extra.laravel.providers` is intentionally empty —
auto-discovery can only *add* the provider, never remove the framework's.

`Illuminate\Foundation\Bootstrap\RegisterProviders` builds the provider list
like this:

```php
config('app.providers') ?? ServiceProvider::defaultProviders()->toArray()
// … and only then appends whatever bootstrap/providers.php returns
```

So `bootstrap/providers.php` can only append. The framework's
`DatabaseServiceProvider` is one of the 23 entries in `defaultProviders()` and
cannot be dropped from that file. Declare the swap in `config/app.php` instead,
built from the defaults so no other framework provider is lost:

```php
'providers' => \Illuminate\Support\ServiceProvider::defaultProviders()->replace([
    \Illuminate\Database\DatabaseServiceProvider::class
        => \Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider::class,
])->toArray(),
```

`replace()` swaps the class in place and leaves the other 22 defaults alone. It
is keyed by class name, so the key is simply ignored if the framework ever stops
listing `DatabaseServiceProvider` by default.

The package ships that file as a starting point: `config/app.php` is the Laravel
12 skeleton with exactly this block appended. It points the map at
`App\Providers\WeightedDatabaseServiceProvider` — an app-local provider that
extends the package one — so point the map at
`Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider` itself if you
have no app-local subclass.

Two things worth knowing:

- Registering the package provider in `bootstrap/providers.php` "works" only by
  accident of ordering: the framework's provider registers first, so the package
  provider overwrites the `db` binding — leaving two providers claiming `db`. The
  `providers` key removes the ambiguity instead of relying on it.
- With a cached config (`php artisan config:cache`) `RegisterProviders` skips the
  merge step entirely and reads `app.providers` from
  `bootstrap/cache/config.php`. The swap still applies; re-run `config:cache`
  after editing the key.

## Configuration

The application owns `config/db-manager.php`. Every install keeps its own copy and
edits that; the package's copy is the sample it is published from.

| File                                                            | Role                                                                                |
|-----------------------------------------------------------------|-------------------------------------------------------------------------------------|
| `<app>/config/db-manager.php`                                   | the configuration — installed once, edited per app                                  |
| `vendor/uak35/laravel-weighted-dbmanager/config/db-manager.php` | the sample `vendor:publish` copies, and the fallback when the app's copy is missing |

The two are combined by Laravel's `mergeConfigFrom()`, which merges shallowly with
the app's copy winning. Because the whole config is a single nested `swrr` block,
an app copy **shadows** the sample completely rather than key by key: what your
`config/db-manager.php` contains is what the app runs on.

The `swrr` block is global; per-connection tunables stay on the connection itself,
next to the `read` list being weighted.

| Key                              | Env                            | Sample value                 | Meaning                                                                               |
|----------------------------------|--------------------------------|------------------------------|---------------------------------------------------------------------------------------|
| `swrr.connection`                | `SWRR_CONNECTION`              | *(unset)*                    | The connection the pgcat gate, the flipper and `db:doctor` follow; unset ⇒ `database.default`. Set it when the app's real PostgreSQL path is a **non-default** connection — e.g. the one `app.default_api_connection` (`API_DB_CONNECTION`) names while `database.default` stays on SQLite |
| `swrr.primary_store`             | `DB_STORE_PRIMARY`             | `redis`                      | `redis` or `local`                                                                    |
| `swrr.redis_connection`          | `SWRR_REDIS_CONNECTION`        | `default`                    | Connection name used by `RedisAtomicStateStore`                                       |
| `swrr.state_ttl`                 | `SWRR_STATE_TTL`               | `86400`                      | TTL (s) for the SWRR state key                                                        |
| `swrr.key_prefix`                | `SWRR_KEY_PREFIX`              | `swrr`                       | Key prefix, shared by both stores                                                     |
| `swrr.allow_local_fallback`      | `SWRR_ALLOW_LOCAL_FALLBACK`    | `true`                       | Fall back to the in-process store instead of throwing. Read as a **switch** — on/off, `1`/`0`, `'on'`/`'off'`, `'yes'`/`'no'` — and a value that is none of those is refused rather than cast |
| `swrr.default_weight_cpu_factor` | `DB_DEFAULT_WEIGHT_CPU_FACTOR` | `3.0`                        | CPU multiplier when a connection sets none                                            |
| `swrr.default_weight_ram_factor` | `DB_DEFAULT_WEIGHT_RAM_FACTOR` | `3.375`                      | RAM multiplier when a connection sets none                                            |
| `swrr.default_weight_formula`    | `DB_WEIGHT_FORMULA`            | `linear`                     | `linear` or `diminishing`                                                             |
| `swrr.reader_windows`            | —                              | `10:00–14:20`, `17:00–20:30` | List of `['start' => 'HH:MM[:SS]', 'end' => 'HH:MM[:SS]']` where readers **are** used — an entry that is not that shape is refused, never read as a window |
| `swrr.reader_days`               | —                              | `[1,2,3,4,5]`                | ISO-8601 days readers are allowed on; one day number, or an array of them (`'1,2,3'` as a single string is refused) |
| `swrr.timezone`                  | —                              | `UTC`                        | Timezone for window evaluation                                                        |
| `swrr.pgcat`                     | `SWRR_PGCAT_*`                 | *(see [Pgcat](#pgcat))*      | Pgcat flipper settings — **PostgreSQL connections only**                              |

The sample sets two weekday windows — `10:00–14:20` and `17:00–20:30`, read
in `swrr.timezone` (UTC) — so reads are served by the weighted replica pool
inside them and by the writer for the rest of the day. Leave `reader_windows`
empty or unset and `TimeWindowResolver` is permissive instead: reads always go to
the replica pool. Either way reads are served by the writer on non-reader days
and outside every window (start inclusive, end exclusive), and a window like
`['start' => '00:00:00', 'end' => '00:00:00']` can never match, which is a
convenient way to force writer-only mode.

A window written any other way is **refused**, not repaired. An entry that is not an
array — the flat `'10:00-14:20'` is the first one everybody writes — is left out of the
windows the resolver is built with, logged at `error` level by every boot that sees it,
and failed by `db:doctor`'s `reader windows` row, so `--strict` rejects it in a
pipeline. Reading it silently as *no window at all* is what used to happen, and it is
the worst of the options: it inverts the setting, so reads use the replica pool at every
hour while the config says the writer covers the margins. Well-formed entries beside a
refused one still apply, and the message says how many, so a typo costs the entry rather
than the fallback. Nothing throws, so an installation with a typo boots and serves — it
just stops doing so quietly. The decision and the alternatives are recorded in
[docs/reader-windows-refusal.md](docs/reader-windows-refusal.md).

`swrr.reader_days` is refused the same way, for the same reason. A day list is a list of
day numbers — `[1, 2, 3, 4, 5]`, or the single `'3'` a scalar `.env` value produces — and
`'1,2,3'`, which is how a list is written in `.env`, is not one. It used to be dropped
entry by entry, leaving no day at all, and no day leaves the resolver permissive: a day
list meant to restrict reads runs as its opposite, all week. It is logged at `error`
level by every boot that sees it under `swrr.reader_days.refused`, and `db:doctor`'s
`reader windows` row `FAIL`s on it, naming the value and quoting the accepted shape. A
list with one bad entry keeps the entries that are days, and says how many.

A boot reports **every** reader finding it can, not the first one it finds: a flat window
string beside a comma-separated day list is two log lines and two records, each closed out
by the boot that sees its own fix. One key is one finding, though — the record keeps one
entry per key — so the two ways the pool ends up unused (no day in 1…7, no window that can
ever be entered) are one sentence when both hold. That rule is enforced rather than assumed:
`BootAudit` logs *every* finding a boot produced before it remembers any of them, and two
findings claiming one key are logged for what they are — a defect in the package — with the
louder of the two kept for the record, so a branch that reuses a key cannot cost another
finding its log line. Nothing is claimed about windows when the day list leaves the resolver
permissive: mode is decided before a window is read, so those reads cannot be wrong about
one. The rule and the alternatives are in
[docs/boot-audit-finding-keys.md](docs/boot-audit-finding-keys.md).

Switches are read, not cast. `swrr.allow_local_fallback`, `swrr.pgcat.enabled` and
`swrr.pgcat.use_reload` are on/off settings, and PHP's own rule for turning a value into
a boolean makes `'false'`, `'off'` and `'no'` — the three ways everyone writes *off* —
into `true`. On `swrr.pgcat.enabled` that is not a cosmetic slip: it arms a file swap the
operator asked for the opposite of. So each is read from a closed list of spellings —
`true`/`false`, `1`/`0`, `'on'`/`'off'`, `'yes'`/`'no'`, case and surrounding space ignored
— and anything else is **refused**: the setting resolves to the value its own documentation
prints (the flipper stays off, a flip reloads, local fallback stays on), the boot logs it at
`error` level under its own key (`swrr.pgcat.enabled.refused`, `swrr.pgcat.use_reload.refused`,
`swrr.allow_local_fallback.refused`), and `db:doctor`'s `switch values` row fails on it.
That is also why the sample config does **not** wrap those values in `(bool) env(...)`: the
cast upstream would swallow the typo before the package could refuse it. The decision and the
alternatives are recorded in [docs/switch-values.md](docs/switch-values.md).

Each key can also come from `.env`: the sample resolves them through `env()`, and a
published copy carries those calls across, so `.env` stays the place for
per-environment values while the file stays the place for everything else.

### Upgrading

Shallow merging cuts both ways. A key added to the sample by a newer version does
not reach an app whose copy predates it, because that copy's `swrr` block replaces
the sample's wholesale. After a `composer update`, compare the two and carry any new
keys across:

```bash
# Unix
diff -u config/db-manager.php vendor/uak35/laravel-weighted-dbmanager/config/db-manager.php

# Windows
fc config\db-manager.php vendor\uak35\laravel-weighted-dbmanager\config\db-manager.php
```

Never reach for `vendor:publish --force` on a config you have edited: it overwrites
your copy with the sample.

### Weighting a connection

```php
'pgsql' => [
    'driver' => 'pgsql',
    'write' => [[ 'host' => '127.0.0.1', 'port' => 5433, /* … */ ]],
    'read' => [
        ['host' => '10.0.1.11', 'port' => 5432, 'cpu_cores' => 16, 'ram_gb' => 64],
        ['host' => '10.0.1.12', 'port' => 5432, 'cpu_cores' => 8,  'ram_gb' => 32],
        ['host' => '10.0.1.13', 'port' => 5432, 'weight' => 0],   // explicitly disabled
    ],
    // optional per-connection overrides
    'weight_formula' => 'diminishing',
    'weight_cpu_factor' => 3.0,
    'weight_ram_factor' => 3.375,
],
```

`linear` → `round(cores × cpu_factor + ram_gb × ram_factor)`.
`diminishing` → `round(cores^0.7 × cpu_factor + √ram_gb × ram_factor)`, for clusters
spanning very different hardware tiers.

## Pgcat

[pgcat](https://github.com/postgresml/pgcat) is a PostgreSQL connection pooler. It
sits between the app and the database and decides which server a query reaches, so
when `reader_windows` moves reads between the replica pool and the writer, pgcat has
to be told as well. `PgcatConfigFlipper` does that — it copies the variant of
`pgcat.toml` matching the current mode into place, signals pgcat, and records the
mode it applied. `db:pgcat-flip` drives it.

### It only ever acts on a PostgreSQL connection

pgcat proxies PostgreSQL and nothing else. The provider therefore tells the flipper
which connection is current (`database.default`) and which driver it uses, and the
flipper refuses to act on a driver it cannot front — MySQL, MariaDB, SQLite, SQL
Server. There is no pool to swap and no `pgcat.toml` to keep in step, so on those
connections:

| Surface                                                        | Behaviour                                                                                                                                                                                                                                                                              |
|----------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `status()['enabled']`                                          | `false`                                                                                                                                                                                                                                                                                |
| `status()['configured_enabled']`                               | what `swrr.pgcat.enabled` alone says, so an explicit setting is not hidden                                                                                                                                                                                                             |
| `status()['connection']`, `['driver']`, `['driver_supported']` | name the connection and why it is off                                                                                                                                                                                                                                                  |
| `status()`                                                     | the full snapshot: everything below plus the file paths and commands                                                                                                                                                                                                                   |
| `disabledReason()`                                             | the sentence explaining it — `null` whenever pgcat does apply                                                                                                                                                                                                                          |
| `armedReason()`                                                | the opposite sentence — names both conditions that passed, the switch and the driver; `null` whenever pgcat does *not* apply                                                                                                                                                           |
| `isMismatched()` / `armingWarning()`                           | the states that read as armed but cannot act: `swrr.pgcat.enabled = true` on a connection pgcat cannot front (`mismatch` is `true`), or an armed flipper missing one of its three paths (a flip would throw). The flipper stays inert either way, and the warning names what to change |
| `db:pgcat-flip`, `--watch`, `--force-mode`                     | the command prints that reason and exits `0` at once; it never enters the watch loop, so a scheduled or supervised run costs nothing                                                                                                                                                   |
| `applyCurrentState()` / `forceMode()`                          | a *no change* result carrying the same reason, returned before the lock, the file copy or supervisorctl                                                                                                                                                                                |
| `db:pgcat-flip --status`                                       | prints all of it, including the inactive reason, the armed reason and any warning                                                                                                                                                                                                      |
| `db:replica-status`                                            | the same snapshot as a `Pgcat:` line — active with driver, connection and mode, or inactive and why — plus a `Warning:` line when it is expected to act but cannot                                                                                                                     |
| `GET /health/db`                                               | the same snapshot in a `pgcat` block (see [Health endpoint](#health-endpoint))                                                                                                                                                                                                         |
| the application log                                            | two `[WeightedDB]` lines, at boot: a warning per process while the setting and the driver disagree, and a warning on the boot that sees it corrected — the first state would otherwise never be mentioned, the second would never be closed out                                                                |

PostgreSQL is recognised as `pgsql`, `postgres` or `postgresql`, matched
case-insensitively. A flipper constructed without a driver — which is what the unit
tests do — keeps its unrestricted behaviour.

Being inert is not the same as being wired correctly, so the two are reported
separately: `reason` says why it will not act, `armed_reason` says why it will, and
exactly one of them is `null`. When the setting and the driver disagree —
`swrr.pgcat.enabled = true` on a connection pgcat cannot front — `mismatch` is
`true` and `armingWarning()` carries the sentence, because the flipper will never
flip however long it runs.

That case is also **logged**, once per process at boot, so it is visible without
anyone reading `/health/db`:

```
[WeightedDB] swrr.pgcat.enabled is true but pgcat will never act: connection "sqlite"
uses driver "sqlite", and pgcat only fronts PostgreSQL. Set swrr.pgcat.enabled = false,
or point database.default at the pgcat-fronted connection. {"connection":"sqlite","driver":"sqlite"}
```

"Once per process" is the point: Octane reuses a worker, so it is one line per
worker rather than one per request or per flip. The guard is static, so a process
that boots several applications — the test suite, `config:cache` — still logs it
once. An armed flipper whose paths are not mounted yet is transient and is not
logged; a flip reports that as a failure on its own.

**The other half of the story.** Correcting the mismatch means editing
configuration, and configuration is read once per process — so the boot that sees
the fix is a different process from the one that logged the warning, and a static
guard cannot tell it that there is anything to resolve. The unresolved mismatch is
therefore written down, beside the flipper's own state file:

```
$TMPDIR/pgcat-flip-state.json        the flipper's last applied mode
$TMPDIR/pgcat-flip-state-gate.json   the mismatch that has not been resolved yet
```

```json
{
    "gate_state": "mismatch",
    "connection": "sqlite",
    "driver": "sqlite",
    "reported_at": "2026-09-25T12:25:15+00:00"
}
```

The record lives in `dirname(swrr.pgcat.state_file)`, so it follows whatever
persistence that path was given — a `/var/lib/…` state file survives a reboot, the
`sys_get_temp_dir()` default does not. It exists only while a mismatch is
unresolved: the boot that finds the gate open while it stands logs the resolution
and deletes it, and an installation that was never mismatched never creates it. The
two lines then sit next to each other in the log:

```
[WeightedDB] swrr.pgcat.enabled is true but pgcat will never act: connection "sqlite" uses driver "sqlite", and pgcat only fronts PostgreSQL. … {"severity":"warning","connection":"sqlite","driver":"sqlite"}
[WeightedDB] Pgcat flipping is active, so the earlier mismatch no longer applies. {"severity":"warning","resolved":true,"connection":"pgsql","driver":"pgsql","mismatch_reported_at":"2026-09-25T12:25:15+00:00"}
```

The resolution is a `warning` for the same reason the store's recovery is: window
flips start happening from that boot on, which is worth noticing. It is logged once
per transition rather than once per boot, the record is cleared so that a second
mismatch is reported as a new problem instead of being resolved by the boot after
it, and switching pgcat off on purpose resolves nothing — the record stays, so a
later, correct arming still closes the warning out. Both writes are silenced and
never fatal: a read-only deploy loses the pair of lines, not the application, and
`db:doctor`'s `pgcat files` row reports that directory as unwritable so the loss is
visible where someone can act on it.

### Flipper settings

The `pgcat` block lives inside `swrr`, and every key is optional. The sample ships
it switched **off** — flipping is a deliberate step, so turn it on in your own copy
once the paths below point at your pgcat install:

```php
'pgcat' => [
    // No (bool) here on purpose: a cast reads 'off' as on, so `enabled` and
    // `allow_local_fallback` hand over the value as written and let the package
    // read it — see "Switches are read, not cast" above.
    'enabled'          => env('SWRR_PGCAT_ENABLED', false),
    'config_path'      => env('SWRR_PGCAT_CONFIG', '/etc/pgcat/pgcat.toml'),
    'readers_path'     => env('SWRR_PGCAT_READERS', '/etc/pgcat/pgcat-readers.toml'),
    'no_readers_path'  => env('SWRR_PGCAT_NO_READERS', '/etc/pgcat/pgcat-no-readers.toml'),
    'restart_command'  => 'supervisorctl restart "pgcat:*"',
    'reload_command'   => 'supervisorctl signal HUP "pgcat:*"',
    'use_reload'       => true,
    'state_file'       => sys_get_temp_dir().'/pgcat-flip-state.json',
    'lock_file'        => sys_get_temp_dir().'/pgcat-flip.lock',
],
```

| Key                     | Env                     | Sample value                     | Meaning                                                                                  |
|-------------------------|-------------------------|----------------------------------|------------------------------------------------------------------------------------------|
| `pgcat.enabled`         | `SWRR_PGCAT_ENABLED`    | `false`                          | Master switch, still subject to the PostgreSQL-only rule above. The sample leaves it off |
| `pgcat.config_path`     | `SWRR_PGCAT_CONFIG`     | `/etc/pgcat/pgcat.toml`          | The file pgcat reads                                                                     |
| `pgcat.readers_path`    | `SWRR_PGCAT_READERS`    | —                                | Variant applied while readers are in use                                                 |
| `pgcat.no_readers_path` | `SWRR_PGCAT_NO_READERS` | —                                | Variant applied in writer-only mode                                                      |
| `pgcat.restart_command` | —                       | `supervisorctl restart "pgcat:*"`    | Run after the swap — the program name has to match what supervisord runs              |
| `pgcat.reload_command`  | —                       | `supervisorctl signal HUP "pgcat:*"` | Used instead when `use_reload` is true                                                |
| `pgcat.use_reload`      | —                       | `true`                           | Prefer a HUP over a full restart. Read as a **switch** like `allow_local_fallback`, so `'no'` is off and a value that is neither is refused |
| `pgcat.state_file`      | —                       | `sys_get_temp_dir()`             | Last applied mode, so a poll that changes nothing copies nothing — the boot check keeps its unresolved-mismatch record beside it |
| `pgcat.lock_file`       | —                       | `sys_get_temp_dir()`             | Mutex — a concurrent flipper returns *skipped*                                           |

The swap is atomic: the variant is written to `{config_path}.tmp.{pid}` and then
`rename()`d, so pgcat never reads a half-written file; only then is the restart or
reload signal sent. Either transition is one copy plus one signal.

A flip also **judges its supervisor command before it replaces anything**, and **puts the
previous file back if the command fails anyway**. Both exist for the same state, which is
the one an operator cannot see: a new `pgcat.toml` on disk while the running pgcat
processes still hold the old one. Nothing is wrong *yet* — and then pgcat restarts for some
unrelated reason, and comes up in a mode no window asked for.

| When the command cannot work | What a flip does                                                                                          |
|------------------------------|-----------------------------------------------------------------------------------------------------------|
| before the swap              | refuses: nothing is copied, nothing is run, no mode is recorded — so the next poll retries and the flip goes through by itself once the command is fixed |
| after the swap (it passed the check and failed anyway) | rolls the previous variant back, so disk and running processes agree again        |

The check is the same read-only question `db:doctor` asks — `supervisorctl status
"pgcat:*"`, never a restart or a signal — so the row and the flip cannot disagree about
whether a command works. It costs one process per flip attempt, and only when a flip is
actually about to happen. A rollback that itself fails says so, loudly, in the flip's
error: that is the one outcome that needs a human.

That last step is a *shell* command line, so two things about the name in it matter.
It has to match what supervisord runs — `"pgcat:*"` addresses every program in a group
called `pgcat` (which is what a `[program:pgcat]` section becomes), and a single
program can be named on its own — and it has to be quoted, because an unquoted
`pgcat:*` is a glob: the shell may rewrite it before supervisorctl ever sees it, and
what arrives is then decided by the working directory at the moment of the flip.
`php artisan db:doctor`'s `pgcat supervisor` row judges both of those, plus whether
supervisorctl resolves for the user the flipper runs as and whether supervisord knows
that name — see [Preflight](#preflight-dbdoctor).

### Rehearsing a flip: `--dry-run`

A window flip is a file replaced and a process restarted, and the person who wants to
know whether that will work is rarely the person who is allowed to do it. `--dry-run`
performs every step of a flip that leaves nothing behind and reports the two that do:

```
$ php artisan db:pgcat-flip --dry-run
[dry run] would flip: never → readers (nothing was renamed, and pgcat was neither signalled nor restarted)
  lock             done     taken and released: /var/lib/lpr/pgcat-flip.lock
  read source      done     /etc/pgcat/pgcat-readers.toml (1892 bytes)
  supervisor check done     supervisorctl resolves to /usr/bin/supervisorctl and knows "pgcat:*": pgcat:pgcat_00 RUNNING pid 4242, uptime 0:12:34
  write temp       done     /etc/pgcat/pgcat.toml.tmp.4711 (1892 bytes)
  remove temp      done     the rehearsal leaves nothing behind
  rename           would    /etc/pgcat/pgcat.toml.tmp.4711 → /etc/pgcat/pgcat.toml (the content would change)
  supervisor       would    supervisorctl signal HUP "pgcat:*"
  state file       would    /var/lib/lpr/pgcat-flip-state.json (writable, left as it is)
```

The source is read for real, the flip's own supervisor check is run for real (it is
read-only — the same `supervisorctl status "pgcat:*"` the flip makes before it replaces
anything), and the temp file the swap writes is written for real into the target's own
directory — then removed again. That write is the one thing a metadata check cannot do:
`db:doctor`'s `pgcat files` row judges the directory with `is_writable` and deliberately
leaves no trace, and a directory can pass that and still refuse the write. Proving the
write also proves the rename's precondition, since the two are on the same filesystem;
the rename itself is reportable and cannot be rehearsed without doing it.

Three things it does not do, and says so:

| Not taken                       | Why                                                                                                                   |
|---------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| the rename                      | the point of a rehearsal                                                                                              |
| the supervisor command          | nothing is restarted, signalled or stopped — the command a flip would run is printed instead                          |
| the state write                 | recording a mode that was never applied would make the next real flip believe it had already happened                |

The state file's *writability* is still answered, from metadata, because a flip's write to
it is silenced: an unwritable one produces a flip that reports success and then repeats
on every poll, restarting pgcat each time. The rehearsal calls that a **failure** even
though the flip would technically succeed — that is the outcome worth catching before the
window.

Exit codes are the question, not the outcome: `0` when a flip would happen *or* when
there is nothing to do (mode unchanged, flipper not armed, lock held — a flip would skip
those too), and `1` only when a step a flip needs did not work. That makes it usable as a
preflight on a deploy that is not allowed to flip anything yet:

```bash
php artisan db:pgcat-flip --dry-run || exit 1   # would the next flip work?
```

`--dry-run --force-mode=writer` rehearses the forced flip exactly as `--force-mode`
applies it, `--status` wins when both are passed (a state report is what was asked for),
and `--dry-run --watch` is refused rather than half-honoured: a watch daemon whose every
pass is a rehearsal looks exactly like one that is flipping.

`--json` reports any of it as one object instead of the rendered report — the verdict, the
exit code and the evidence, for a pipeline to assert on rather than to grep: see
[the JSON report](#the-json-report-one-object-for-a-pipeline).

The flip itself follows the same mapping, which is written out as a table of its own
under [Flipping](#flipping-dbpgcat-flip) below: applied, nothing to do and skipped all
exit `0`, and only a step that did not work — or a source config that is not there — exits
`1`. A refused flag combination exits `1` without reaching the flipper at all.

One trace is left on purpose: the lock file, because a flip takes that lock too and
cannot take it if the directory will not accept it. It is the same file every flip
leaves behind.

Which steps can be rehearsed and which cannot, what each failure means and why a rehearsal
is a command rather than a `db:doctor` row is written up in
[docs/pgcat-flip-dry-run.md](docs/pgcat-flip-dry-run.md).

## Degradation, and why it is not silent

When the primary store is unhealthy, the manager serves from an in-process
`LocalStateStore` and logs an `error` **once** per process:

```
[WeightedDB] Degraded to the in-process SWRR store. Read rotation is no longer
shared across PHP workers until the primary store recovers.
```

When the primary store answers again it logs a `warning` and releases the
fallback. `healthSummary()` — and therefore `/health/db` and
`db:replica-status` — reports which store is actually serving:

| field           | meaning                                         |
|-----------------|-------------------------------------------------|
| `store`         | the store serving requests right now            |
| `store_healthy` | health of the **primary** store                 |
| `primary_store` | the configured primary store (`redis(default)`) |
| `degraded`      | `true` once this process has fallen back        |

A worker serving from the in-process store is *not* sharing a rotation with its
siblings, so it is worth alerting on. Set
`swrr.allow_local_fallback = false` to hard-fail instead of degrading:

```php
$manager->setAllowLocalFallback(false);
```

### A store that is not there yet is not a failing store

Connection configs are built while providers register — any application that touches
the schema in `register()` does exactly that — so the first replica choice of a
process can happen before the application has bound `redis`. That pick falls back
like any other failure, but it is deliberately not counted as one: three early picks
would otherwise mark the store unhealthy, and that worker would stop using Redis for
the rest of its life. One `warning` records it per process:

```
[WeightedDB] This pick fell back to the in-process store.
{"store":"redis(default)","connection":"pgsql","reason":"The redis(default) store
 cannot be used: the container has no \"redis\" binding yet."}
```

The timing of that probe — how often an installation may PING its store, and why a
`static` guard cannot bound it — is recorded in
[docs/boot-audit-store-probe.md](docs/boot-audit-store-probe.md), together with the
mechanisms that were rejected and the measurements behind the choice.

The store reaches Redis through the container rather than the `Redis` facade, and
that is not a style preference. Resolving an unbound `redis` does not fail: the
phpredis extension ships a class called `Redis` and PHP class names are
case-insensitive, so the container builds the extension class — and the `Redis`
facade caches whatever it is handed as its root for the rest of the process. A
correct installation then throws

```
Error: Call to undefined method Redis::connection()
```

from *any* code in that worker, including Horizon, queues and the cache. If you see
that message, something in the application — not this package — used `Redis::` before
the Redis provider registered.

## Health endpoint

The package ships `DatabaseHealthController`, which reports per-connection replica
weights, share %, health, the active formula, store state and whether pgcat
flipping is active. Route it yourself and protect it:

```php
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;

Route::get('/health/db', [DatabaseHealthController::class, 'index'])
    ->middleware('auth.basic');
```

Alongside the per-connection `replicas` map it carries one `pgcat` block — the same
snapshot `db:replica-status` prints and `db:pgcat-flip --status` shows, so the three
cannot disagree about whether flipping is active:

```json
{
    "status": "ok",
    "replicas": {
        "pgsql": {
            ".": "."
        }
    },
    "pgcat": {
        "enabled": true,
        "configured_enabled": true,
        "connection": "pgsql",
        "driver": "pgsql",
        "driver_supported": true,
        "resolver_mode": "readers",
        "last_mode": "readers",
        "reason": null,
        "armed_reason": "pgcat flipping armed: swrr.pgcat.enabled = true and connection \"pgsql\" uses driver \"pgsql\"",
        "mismatch": false,
        "warning": null
    },
    "audit": {
        "available": true,
        "count": 1,
        "severity": "error",
        "counts": {"error": 1, "warning": 0},
        "oldest": "2026-09-21T08:15:00+00:00",
        "findings": [
            {
                "key": "swrr.reader_windows.refused",
                "level": "error",
                "warning": "reader_windows[0] is \"10:00-14:20\". Refused: each window must be an array …",
                "resolution": "swrr.reader_windows is readable again: every entry is a window …",
                "first_reported_at": "2026-09-21T08:15:00+00:00",
                "age_seconds": 345600,
                "age": "4 days",
                "context": {"rejected_windows": [{"at": "[0]", "entry": "\"10:00-14:20\""}]}
            }
        ],
        "error": null
    },
    "errors": {}
}
```

The `audit` block is the boot audit's standing findings: the settings that read as on
but cannot act, each with the sentence the boot log carried for it, the level it was
logged at, and how long it has stood — `age` in words, with `age_seconds` for a monitor
that would rather do the arithmetic. `count` and `oldest` save a dashboard from walking
the list.

`severity` and `counts` are the machine-readable half, and the reason the block is
alertable at all: a level per finding can only be used by a reader that walks the list and
compares strings. `severity` is the loudest level standing — `error` when at least one
finding is a value the package **refused** (malformed input it will not guess at, which is
what the installation is running without), `warning` when the loudest is a setting that
reads as on but cannot act, and `none` when nothing stands — so the distinction between the
two kinds of finding is one field:

| alert | rule |
|---|---|
| page — a refused value is standing | `severity == "error"` (or `counts.error > 0`) |
| ticket — a setting reads as on but cannot act | `severity == "warning"` |
| the audit stopped reporting | `available == false` |

`counts` carries the same distinction per level, with both keys always present so a rule
can be written as `counts.error > 0` rather than as a lookup that might be missing. Levels
are compared exactly: the audit logs at two of them, and anything else in a hand-edited
record reads as the quieter one rather than being upgraded into a claim the record cannot
support.

The same rule is in the boot log, so a monitor that would rather read lines than poll every
host does not have to poll this route at all: every line the boot audit writes carries the
level it was written at as `severity`, in the payload beside the `finding` key — a refused
value still standing is `severity: "error"`, the same comparison this route's `severity`
answers, and the finding is logged on every boot while it stands. It is in the payload rather
than left to the channel's own level because how a level is spelled belongs to whatever
handler is configured (`production.ERROR` in the default line log, `level_name` under a JSON
formatter, a priority number in syslog), and because `ERROR` there is every error the
application ever logs. The line that *closes* a finding is written at `warning` whatever the
finding stood at, and carries `resolved: true` — arriving at a working configuration is good
news either way — so a pager written per finding key stops on the very line that repairs it.
The decision, and the seven shapes it was weighed against, are in
[docs/boot-audit-log-severity.md](docs/boot-audit-log-severity.md).

`available` is `false` when no record could be read, and `error` — present either way,
`null` whenever the record was read — says which of the two that was: `null` with
`available` `false` means the package's provider is not registered, and a set `error` means
the file could not be read. Neither is a claim about the installation, so the summary stays
`severity: none`.

The same list is printed by `db:replica-status`, so a misconfiguration is visible from
a terminal as well as from a dashboard — and both surfaces render one accessor,
`BootAudit::reported()`, so they cannot disagree about the record either, including when
there is none: the command prints `Audit: not registered` where the payload answers
`available: false` with `error: null`, instead of the silence that used to leave "no audit
installed" and "an audit with nothing to say" indistinguishable in a terminal. It
deliberately does not move this endpoint's
`status`: a configuration that cannot act is not the same claim as a database that
cannot answer, and whatever polls this route should keep believing the second one.

Where a standing finding is published, which surfaces were rejected (a command of its
own, a second endpoint, an eviction-worthy status code) and why is recorded in
[docs/boot-audit-surfaces.md](docs/boot-audit-surfaces.md).

`reason` is the sentence to alert on when `enabled` is `false`, and `null` whenever
flipping is active. `armed_reason` is its mirror image — the proof of *why* it is
active, naming the switch and the driver that both passed. Exactly one of the two is
`null`.

The one combination that deserves attention of its own is a switch that is on for a
connection pgcat cannot front. The flipper is correctly inert — there is no pool to
swap — but nothing will ever flip, so `mismatch` is `true` and `warning` carries the
sentence:

```json
{
    "status": "ok",
    "pgcat": {
        "enabled": false,
        "configured_enabled": true,
        "connection": "sqlite",
        "driver": "sqlite",
        "driver_supported": false,
        "reason": "pgcat is PostgreSQL-only; connection \"sqlite\" uses driver \"sqlite\", so pgcat flipping is disabled",
        "armed_reason": null,
        "mismatch": true,
        "warning": "swrr.pgcat.enabled is true but pgcat will never act: connection \"sqlite\" uses driver \"sqlite\", and pgcat only fronts PostgreSQL. Set swrr.pgcat.enabled = false, or point database.default at the pgcat-fronted connection."
    }
}
```

A flipper that cannot act does **not** make the endpoint unhealthy — on MySQL there
is nothing to flip, so `status` stays `ok` — and when the provider is not registered
the block is `null`.

## Artisan commands

All four are registered by the provider.

| Command                                                                      | Purpose                                                                                                       |
|------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| `php artisan db:doctor {connection=pgsql} [--strict] [--json]`               | Preflight: check an installation end to end before traffic arrives                                            |
| `php artisan db:replica-status {connection=pgsql}`                           | Table of host, weight, share %, health, plus formula, store backend, pgcat state and the boot audit's standing findings |
| `php artisan db:probe-replicas {connection=pgsql}`                           | `SELECT 1` against every replica and feed results to `HealthMonitor` (schedule every 30 s)                    |
| `php artisan db:pgcat-flip [--status\|--watch\|--dry-run\|--force-mode=] [--interval=] [--json]` | Keep `pgcat.toml` in step with the reader/writer window — a no-op unless the current connection is PostgreSQL |

```php
// routes/console.php
Schedule::command('db:probe-replicas')->everyThirtySeconds()->withoutOverlapping()->runInBackground();
Schedule::command('db:pgcat-flip')->everyMinute()->withoutOverlapping(60)->runInBackground();
```

Each probe opens a throwaway single-host connection (the pooled `read`/`write`
lists are stripped) and purges it afterwards, so probing never feeds the weighted
pool it is measuring. Diagnostics go to the replica's own `host:port` key, the
same key `WeightResolver` uses.

### Probing: `db:probe-replicas`

The exit code is what a scheduler reports, so it says whether the sweep did its
job — not whether every replica is well:

| what the sweep found                     | exit                             |
|------------------------------------------|----------------------------------|
| at least one replica answered            | `0`                              |
| the connection has no `read` list at all | `0` — nothing was asked of it    |
| no entry in `read` is a replica map      | `1` — nothing could be probed    |
| every replica failed                     | `1` — nothing was marked healthy |
| the container has no weighted manager    | `1` — there was no sweep         |

A *partial* failure stays `0`: marking a replica that is down is what the command
is for, and the health monitor — not the exit code — is that record. A sweep that
reached nothing is the different case, and the two ways that can happen are named
separately in the run's closing line, because they are repaired differently:
`Nothing could be probed: the read list on [x] holds no replica array …` is a
configuration shape (`read` must be a list of maps, or one map), while `No replica
answered: 2 probed, all failed …` is reachability.

`read` written as a single config map rather than a list of them is one replica,
which is what routing does with it. Reading it as nothing would make the command
exit `1` every thirty seconds on an installation whose single replica is serving
fine, and an exit code that is always non-zero is an exit code nobody reads.

Every row is pinned by
`DbProbeReplicasCommandTest::test_the_exit_code_is_a_function_of_what_the_sweep_reached`,
which probes a real connection per row and asserts the code, the sentence beside
it, and the `host:port` results that had to reach the health monitor — the half of
the command that is otherwise invisible, because the probe's own connection is
purged after every attempt. That matrix is not left to be kept in step with the
table above by hand: `DbProbeReplicasCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`
reads this table out of the README and asserts each row's number against the cell the
matrix enforces, and asserts that the two name the same cases — so a documented case
cannot lose its last cell, and a cell cannot be added without documenting it.

The flipper only ever acts on a PostgreSQL connection — [Pgcat](#pgcat) has the
details.

### Preflight: `db:doctor`

`php artisan db:doctor` answers the question a deploy actually cares about — *will
this installation do what it is configured to do?* Eleven things can be wrong while
the application still boots and answers requests, and each one is a row:

| Row                  | Fails when                                                                                                                                         |
|----------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| `provider swap`      | the framework's `DatabaseServiceProvider` is still in charge, so `db` is not the weighted manager and reads are unweighted                         |
| `weighted factory`   | `db.factory` is not `WeightedConnectionFactory` — the replica is chosen there, so weighting is not running either                                  |
| `published config`   | *warns* when `config/db-manager.php` is missing (the package sample is in use) or still byte-identical to the sample (nobody has reviewed a value) |
| `pgcat gate`         | `swrr.pgcat.enabled` is on where pgcat cannot act, or armed while a path a flip needs is unset. Reads the boot record as well as this boot's verdict, so it also fails with the age of a mismatch an earlier boot recorded, and warns when one is on record for a connection this run does not inspect. Prints `swrr.pgcat.enabled = false` as a `suggestion` for the switch armed where pgcat cannot act, and nothing for the other two failures: the paths it names are this installation's to choose |
| `pgcat files`        | the flipper is armed and a file a flip needs is missing, unreadable or unwritable — including the target's directory, where the atomic swap writes `{target}.tmp.{pid}`, and the state/lock directory. The only row that never prints a `suggestion`: every problem here is a path or a permission, and the right value is whatever this installation's pgcat and supervisor actually use |
| `pgcat supervisor`   | the flipper is armed and the command it runs *after* the swap cannot work: its executable does not resolve for this user (not an absolute path, not on `PATH`), the program name is an unquoted glob the shell may rewrite, supervisorctl cannot answer, or supervisor does not know the program. Read-only: the only command it runs is the flip's own with `status` in the verb position. Prints the setting line to paste as a `suggestion` for the two faults that reduce to a command — an unquoted program name, and a command left empty — and nothing for the rest, which are repaired in a `PATH`, a running supervisord or a `[program:]` section |
| `replica metadata`   | *fails* when a replica's `weight`/`cpu_cores`/`ram_gb` is a value the resolver does not read as written — not a number, or a number under the floor that key is read at — naming the replica and what the resolver reads instead. A weight it cannot read is read as `0`, and `0` is how a replica is disabled, so that replica leaves the pool: without this the row reports the smaller pool as the installation. Also names a replica disabled with `weight: 0`, which is a choice and does not fail. *Warns* when the replicas carry no metadata at all, so the resolver picks at random while the table still looks weighted |
| `switch values`      | **Fails** when `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` or `swrr.allow_local_fallback` is written as something that is not on or off — `'false'`, `'off'` and `'no'` are how off is written, and a cast reads every one of them as *on*, which on the pgcat switch arms the file swap. The row quotes the value, quotes the accepted spellings, and names the value the setting falls back to. Names **every** switch it refuses rather than the first, and dates each from its own finding in the boot record. Nothing is suggested: re-spelling `'flase'` would be guessing at what was meant |
| `reader windows`     | `swrr.reader_windows` holds an entry that is not a window — the flat `'10:00-14:20'` is the one everyone writes first — or is not a list of windows at all, or `swrr.reader_days` is not a list of day numbers — `'1,2,3'` written as one string is the same mistake one key over. **Fails** on both, naming the entry and quoting the shape the setting reads: reading a typo as "nothing" is what inverts the setting, so the package refuses it instead. *Warns* on the three ways a well-formed setting still cannot act — an empty day list (permissive), days outside 1…7 (the pool is never used), and windows whose start is not before their end (never entered). When the refused value names its own replacement — the flat string, `'1,2,3'` — the row also prints the setting line to paste as a `suggestion` under it. Names **every** problem it finds rather than the first, and dates each from its own finding in the boot record |
| `store probe`        | *warns* when the store check can never run: probing switched off (`swrr.audit.store_probe_seconds = 0`), or an in-process primary store with nothing to reach. **Fails** when the audit record cannot be written — the record is what throttles the probe, so without it the `PING` is skipped on every boot and an unreachable store goes unreported. Names **every** state it finds rather than the first: an installation that is both switched off and unable to write the record is told both, and the row's verdict is the loudest of them |
| `store reachability` | the primary store cannot serve a read: no usable `redis` binding, or a connection that refuses — workers then fall back to an in-process rotation that is not shared between them |

Read-only, so it is safe in a deploy pipeline: nothing is written, no replica is
queried and no flip is attempted. Two checks reach outside the process: the store
check, which is a real `PING` on `swrr.redis_connection` rather than a look at the
store's own health
flag — that flag is only lowered *after* an operation has already failed, so on a
fresh process it says "nothing has gone wrong yet", which is not "the store is
reachable". An unreachable store therefore costs one connect timeout.

The pgcat file check is the other one that has to look past the configuration: the
gate only asks whether the three paths are *set*, so an installation can be armed,
gated correctly and still be unable to flip. The check reads the paths off the
flipper's own status, so it judges the files a flip really touches — the reader
source and writer source must be readable (swap() picks one by mode), the target
must exist and be writable, and the target's directory must accept the temp file the
atomic swap writes. The `state_file` and `lock_file` directories are included because
both writes are silenced with `@`: if they fail, a flip still reports success and
then restarts pgcat again on the next poll, forever. Every unusable file is named in
the row. Readability and writability come from `is_readable`/`is_writable`, so no
probe file is ever created.

Exit `0` when nothing failed, `1` when a check failed — or, with `--strict`, when any
check warned:

| the rows | `--strict` off | `--strict` on |
|---|---|---|
| no failures and no warnings | `0` | `0` |
| warnings, no failures | `0` | `1` |
| any failure | `1` | `1` |

`--strict` moves the exit code and the closing line, and nothing else — the rows are the
same either way — so `php artisan db:doctor --strict` is a release gate. That line is what
an operator reads *beside* the code, so a warning the flag turned into a failure says so,
and names the flag that did it: exit `1` with nothing failed is otherwise undiagnosable
from rows that are all `PASS` and `WARN`. A pipeline reads the same three facts as fields —
`exit_code`, `verdict` and `strict` — from `--json`, a few paragraphs below.

```
$ php artisan db:doctor --strict
…
11 checks: 10 passed, 1 warning, 0 failed
No failures — but --strict counts the 1 warning above as failures: this run exits 1 as a release gate, not because the installation is broken.
```

The table above is read back out of the README by
`DbDoctorTest::test_the_matrix_agrees_with_the_readme_exit_table`, which asserts every cell
of the matrix against the number the table names for its case — the documented rule and the
enforced rule cannot drift apart in either direction. What the cells *do* is pinned by
`DbDoctorTest::test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag`, which
builds each row shape from real misconfiguration and asserts the counts it printed, the
code it exited with, and the sentence it closed on — including that a run *without* the
flag does not blame it. Both come from one rule, `gateFailed()`, so the sentence cannot
describe a different rule from the one that produced the code:

```
$ php artisan db:doctor
Connection inspected: pgsql, followed connection is pgsql

PASS  provider swap       WeightedDatabaseServiceProvider is in charge, weighted manager bound
PASS  weighted factory    db.factory is Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory
PASS  published config    config/db-manager.php is published and differs from the sample
FAIL  pgcat gate          swrr.pgcat.enabled is true but pgcat will never act: connection "sqlite"
                          uses driver "sqlite", and pgcat only fronts PostgreSQL. ... — recorded
                          unresolved since 2026-09-20T08:15:00+00:00
      suggestion          swrr.pgcat.enabled = false
PASS  pgcat files         flipper is not armed — no pgcat file is read or written
PASS  pgcat supervisor    flipper is not armed — no supervisor command is ever run
WARN  replica metadata    no read replicas on [pgsql] — every read uses the writer
PASS  switch values       swrr.pgcat.enabled on, swrr.pgcat.use_reload on,
                          swrr.allow_local_fallback on — each reads as on or off, so nothing
                          is refused
FAIL  reader windows      reader_windows[0] is "10:00-14:20" — each window must be an
                          array like ['start' => '10:00:00', 'end' => '14:20:00'] — a string
                          such as '10:00-14:20' is not a window. Refused: nothing is left
                          to apply, so reads use the replica pool at every hour
      suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]
PASS  store probe         at most one PING every 60 seconds, throttled by the record at
                          /tmp/swrr-audit.json, which is writable
FAIL  store reachability  [redis(default)] could not serve a read: A connection attempt failed ...
                          [tcp://10.0.0.7:6379] — reads fall back to an in-process rotation that is
                          not shared between workers

11 checks: 7 passed, 1 warning, 3 failed
This installation will not behave as configured.
```

A `FAIL` on `store reachability` is the one worth reading twice: the application
still serves reads (they fall back), so nothing else looks wrong — but replica
choice stops being shared between workers, and the `WARN` form means this process
already fell back, which may predate the deploy. The row names which of the two
ways the store is unusable, because they are repaired differently: a connection
that refuses is Redis' problem, while `the container has no "redis" binding yet`
means the application never registered a Redis provider.

`store probe` exists because the reachability check can be silently unable to run,
and a skipped check looks exactly like a passing one. The probe is throttled by the
audit record, so an installation that cannot write that record — or that switched
probing off with `store_probe_seconds = 0` — never issues a `PING` at all: no store
finding is produced, none is resolved, and the boot audit has nothing to say. The row
reports the interval, the record, and whether the record is writable, so an
installation that can never check its store is visible rather than merely quiet; the
reachability row's `PASS` in that state means "this boot did not check", which is why
this one sits directly above it. Disabling the probe is a choice, so that is a
warning; an unwritable record is not, because nothing then performs the check.

`pgcat gate` is the row that is not only this process's opinion. The boot audit
records a mismatch while it stands and clears it on the boot that finds the gate
open, so the row reports both halves: the verdict it computes now, and
`recorded unresolved since …` — the first sighting, preserved across boots — which
is what separates "this deploy is configured wrong" from "production has been in
this state since 2026-09-20". When the record names a connection this run does not
inspect, the row warns instead of passing silently, and `--strict` fails it.

`replica metadata` is the row whose question is easiest to get wrong. It is not "does
this installation look weighted" — a replica with `'weight' => 'heavy'` looks weighted
and is not — but "is the pool the read list describes". The pool is what the resolver
*kept*, and the resolver reads a weight through `max(0, ConfigValue::int(…))` and a core
count through `max(1, …)`, so a value that is not a number becomes 0 — which is exactly
how a replica is switched off. An installation whose weight was a typo therefore lost
that replica from the pool without a word, and the row used to report the pool that was
left: a count short by one, with nothing to say which replica went or why. It now reads
the *configured* replicas and fails, naming each one and the value:

```
FAIL  replica metadata    replica metadata the resolver cannot read on [pgsql]:
                          [10.1.0.2:5432] weight is "heavy", which the resolver reads as 0
                          — a replica weighted 0 leaves the pool, so reads are routed over a
                          smaller pool than the read list describes and nothing else reports
                          that it happened
```

A cores or memory value is the same failure one key over, with the difference stated: the
replica **stays** in the pool, weighted as something other than the read list says,
because the resolver substitutes rather than drops it (`cpu_cores` becomes one core,
`ram_gb` none). The nearest case the rule has to get exactly right is `weight: 0` itself,
which *is* the documented disable: that replica is meant to be absent, so the row passes —
and names it, because a pool reported as "1 replica, total weight 10" on an installation
with two is the same quiet shrink through the front door. The rule is the resolver's own
arithmetic rather than a restatement of it, which is what makes a negative weight, a
`weight: 0.5` that truncates to 0, and a `weight: null` all reportable;
`docs/replica-metadata-refusal.md` records which values are refused, which are named, and
why `weight: true` is left alone.

`reader windows` answers a configuration typo rather than a configuration gap. An entry
that is not an array — the flat `'10:00-14:20'` — used to be dropped in silence, which
reads as *no window at all*: the resolver is then permissive, so every read uses the
replica pool, the opposite of the fallback the setting describes, and nothing said so.
The row fails on it, names the entry (`reader_windows[0] is "10:00-14:20"`) and quotes
the shape a window has, then says what the resolver does next. Well-formed entries beside
a refused one still apply, so a typo costs the entry rather than the whole fallback. The
three remaining ways the setting can fail to act stay warnings, matching the boot audit,
because those are understood values that can do nothing — `--strict` fails them all the
same.

The same row answers the days setting, because it is the same mistake one key over:
`swrr.reader_days = '1,2,3'` is a list written as one string, and it used to be dropped
entry by entry until no day was left — which makes the resolver permissive, so a day list
meant to restrict reads runs all week as its opposite. The row fails on it and names the
value (`swrr.reader_days is "1,2,3", not a list of days`), quotes the shape the setting
reads, and says what is left: a list whose remaining entries are days keeps them, and the
row says how many.

One row can be about more than one of these at once, so it names **every** problem it finds
and not the first. The two settings are refused independently, and an operator who fixes the
windows and redeploys should not hear about the days on the next preflight — a second deploy
for a sentence that would have fitted on this row — while the boot audit had been reporting
both from the beginning. The verdict is the loudest problem in the list, so a refusal beside
a warning is still a `FAIL`, and each problem is dated from *its own* finding: the record
holds one entry per finding key, and quoting the first key on file would put a date on a
problem the row is not reporting. Two refused settings therefore print two `suggestion`
lines, one repair each.

Both failures end with a line that is not a check. A refused value that names its own
replacement is printed in the shape the setting reads, so the repair is a paste rather
than a translation — `swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]`
for the flat string, `swrr.reader_days = [1, 2, 3]` for `'1,2,3'` — and the row's `FAIL`,
the counts and the exit code are the same whether it is printed or not. A well-formed entry
beside the refused one is repeated exactly as it was written, extra keys and all, since that
is the value the setting already accepts. Where the refused value does not reduce to one
replacement there is no line at all: an overnight range (`'22:00-06:00'`) has no equivalent
this resolver could enter, `'9:00-14:20'` has a bound the resolver silently reads as
midnight, `'10:00 to 14:20'` is not a range, and `'mon'` is not a day. The package explains
the shape and stops, because a guess printed as a fix is worse than the sentence it
replaces. `ReaderWindows::suggestion()` and `ReaderDays::suggestion()` hold that rule;
`docs/reader-windows-refusal.md` lists which values get a line and why the rest do not.

The pgcat rows follow the same rule, and they are where it is easiest to see why it is a rule
rather than a nicety. `pgcat gate` prints `swrr.pgcat.enabled = false` when the switch is armed
where pgcat cannot act: the repair its own sentence already names, in the shape a report can be
acted on without being read as English — and, in `--json`, a string a gate can select on rather
than a clause buried in a `detail`. `pgcat supervisor` prints the setting line for the two faults
that reduce to a command, an unquoted program name and a command left empty. `pgcat files` prints
no line at all: every problem it reports is a path or a permission, the right value is whatever
this installation's pgcat and supervisor actually use, and a plausible-looking path pasted into
configuration replaces the operator's intent with this tool's. `PgcatConfigFlipper::suggestionForGate()`
and `suggestionForSupervisor()` hold that half of the rule, so the line comes from the class that
knows which setting the flip reads — and a `FAIL` stays a `FAIL` whether a line is printed under
it or not.

A `FAIL` on `pgcat files` is the one that would otherwise wait for the window to
open: the application boots, `pgcat gate` passes, and the flip throws at the moment
traffic was supposed to switch. Seeing it here means the flip could be repaired
before that moment rather than during it.

`pgcat supervisor` is the same failure one step later. `pgcat files` proves the file a
swap writes is reachable; the swap also runs a shell command, and everything about
that command is invisible to a file check — whether the executable resolves for the
user the flipper runs as, whether the program name reaches supervisorctl unchanged,
and whether supervisord knows that name. All four ways it can fail leave the file
already replaced:

```
FAIL  pgcat supervisor    a flip would run supervisorctl signal HUP pgcat:*, but pgcat:*
                          is unquoted: the shell may expand it before supervisorctl sees
                          it. Write supervisorctl signal HUP "pgcat:*" — the quoted name
                          is the one supervisorctl receives unchanged
      suggestion          swrr.pgcat.reload_command = 'supervisorctl signal HUP "pgcat:*"'
FAIL  pgcat supervisor    supervisor does not know "pgcat:*": pgcat:*: ERROR (no such
                          group). The name has to match what supervisord runs: a group
                          called pgcat is addressed as "pgcat:*", a single program by
                          its own name — a flip refuses before it swaps the file, so
                          nothing is replaced and no mode is recorded
```

The first of those two carries a `suggestion` and the second does not, which is the whole rule
in one screen: the unquoted name reduces to a command the package can write down exactly (and
names the key a flip reads — `reload_command` here, because `use_reload` is on), while "the
program supervisor does not know" is repaired in supervisord's own `[program:]` sections. The
row states the fault either way, and the file is not swapped either way.

The row asks supervisor rather than guessing, and asks it read-only: the command it runs
is the flip's own with `status` in the verb position, `supervisorctl status "pgcat:*"`,
so nothing is restarted or signalled. The command itself comes from
`PgcatConfigFlipper::supervisorCommand()`, the method `swap()` uses, so the row cannot
describe a different command than the flip performs — including which of the two it
picks: the reload command when `use_reload` is true, the restart command otherwise.
A command that does not invoke supervisorctl (systemd, a wrapper script) is checked for
resolution only, and the row says so rather than implying it asked supervisor about
something. Like `pgcat files`, it is moot while the flipper is not armed.

The verdict behind all of this is one object — `Pgcat\SupervisorStep::inspect()` — which
the row, the flip's preflight and `--dry-run` all read, so a row that passes while a flip
refuses is impossible, and the sentence an operator reads is the same one in all three
places. The decision behind the read-only derivation, the quoting rule, the four ways the
step can fail, and what a flip now does about them is written up in
[docs/pgcat-supervisor-preflight.md](docs/pgcat-supervisor-preflight.md).

### The preflight as data: db:doctor --json

A deploy gate should not parse the table either. `--json` writes one object and nothing
else — every row as `checks`, each with the verdict it reached and the repairs it carries,
the run's `counts`, the `verdict` for the run, and the `exit_code` the process exits with —
so the whole of stdout is the report. It is the same flag, envelope and rule as
`db:pgcat-flip --json`, so the package has one machine-readable contract rather than one per
command:

```
$ php artisan db:doctor --strict --json
{
    "command": "db:doctor",
    "connection": "pgsql",
    "default_connection": "pgsql",
    "strict": true,
    "verdict": "FAIL",
    "exit_code": 1,
    "counts": {
        "checks": 11,
        "passed": 7,
        "warnings": 1,
        "failed": 3
    },
    "checks": [
        {
            "name": "provider swap",
            "verdict": "PASS",
            "detail": "WeightedDatabaseServiceProvider is in charge, weighted manager bound",
            "suggestions": []
        },
        {
            "name": "weighted factory",
            "verdict": "PASS",
            "detail": "db.factory is Uak35\\WeightedDbManager\\Database\\Weighted\\WeightedConnectionFactory",
            "suggestions": []
        },
        {
            "name": "published config",
            "verdict": "PASS",
            "detail": "config/db-manager.php is published and differs from the sample",
            "suggestions": []
        },
        {
            "name": "pgcat gate",
            "verdict": "FAIL",
            "detail": "swrr.pgcat.enabled is true but pgcat will never act: connection \"sqlite\" uses driver \"sqlite\", and pgcat only fronts PostgreSQL. ... — recorded unresolved since 2026-09-20T08:15:00+00:00",
            "suggestions": ["swrr.pgcat.enabled = false"]
        },
        {
            "name": "pgcat files",
            "verdict": "PASS",
            "detail": "swrr.pgcat.config_path is readable; swrr.pgcat.readers_path is readable; swrr.pgcat.no_readers_path is readable",
            "suggestions": []
        },
        {
            "name": "pgcat supervisor",
            "verdict": "PASS",
            "detail": "supervisorctl resolves to /usr/bin/supervisorctl and knows \"pgcat:*\"",
            "suggestions": []
        },
        {
            "name": "replica metadata",
            "verdict": "WARN",
            "detail": "no read replicas on [pgsql] — every read uses the writer",
            "suggestions": []
        },
        {
            "name": "switch values",
            "verdict": "PASS",
            "detail": "swrr.pgcat.enabled on, swrr.pgcat.use_reload on, swrr.allow_local_fallback on — each reads as on or off, so nothing is refused",
            "suggestions": []
        },
        {
            "name": "reader windows",
            "verdict": "FAIL",
            "detail": "reader_windows[0] is \"10:00-14:20\" — each window must be an array like ['start' => '10:00:00', 'end' => '14:20:00'] — a string such as '10:00-14:20' is not a window. Refused: nothing is left to apply, so reads use the replica pool at every hour",
            "suggestions": [
                "swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]"
            ]
        },
        {
            "name": "store probe",
            "verdict": "PASS",
            "detail": "at most one PING every 60 seconds, throttled by the record at /tmp/swrr-audit.json, which is writable",
            "suggestions": []
        },
        {
            "name": "store reachability",
            "verdict": "FAIL",
            "detail": "[redis(default)] could not serve a read: Connection refused [tcp://10.0.0.7:6379] — reads fall back to an in-process rotation that is not shared between workers",
            "suggestions": []
        }
    ]
}
```

*The report carries the rows in the order the table prints them, and `detail` is the row's own
detail — the same string, unwrapped rather than folded into the terminal's width. The `pgcat gate`
detail is abbreviated above with a trailing `...`; a real one is several sentences long.* The key set
is fixed and always present — an empty list where a run has nothing for a key — so a rule never has to
check whether a field exists:

```bash
php artisan db:doctor --strict --json > preflight.json
jq -e '.exit_code == 0' preflight.json
jq -e '.counts.failed == 0' preflight.json
jq -e '.checks[] | select(.name == "store probe") | .verdict == "PASS"' preflight.json
jq -e '.checks[] | select(.name == "reader windows") | .suggestions[]' preflight.json
```

`verdict` is one of three strings, at both scopes — the same three the table prints in its left
column, rather than a second vocabulary for the same fact:

| verdict | what it means                                                                                                        |
|---------|----------------------------------------------------------------------------------------------------------------------|
| `PASS`  | the check passed — and, for the run's own verdict, so did every row                                                  |
| `WARN`  | the check found a setting that cannot act but is not refused — and, for the run, nothing failed and something warned |
| `FAIL`  | the check refused a value — and, for the run, a row failed                                                           |

The run's `verdict` is the **loudest row** rather than a second opinion about the run, and it is
deliberately not read back out of `exit_code`: under `--strict` a warning exits `1` while every row is
`PASS` or `WARN`. So a gate that wants to know whether the *installation* is broken asserts on
`verdict`, one that wants the *gate's* decision asserts on `exit_code`, and the two fields that join
them are `strict` and `counts`:

```bash
jq -e '.verdict != "FAIL"' preflight.json                                   # is anything actually broken?
jq -e '.exit_code == 1 and .verdict == "WARN" and .strict' preflight.json    # ...or is this just --strict?
```

Two things to know about the row list. It is not a fixed length: a `db` that does not resolve to the
weighted manager leaves the six configuration rows and no replica rows at all, so select a row by
`name` — the row's identity, and the same string the table prints — rather than by position. And
`detail` is prose written for a reader, naming paths and commands and supervisor's own output on the
platform that ran it; assert on `name`, `verdict` and `exit_code`, which are the contract, and treat
`suggestions` as the repairs a row offers rather than as a substitute for reading the row.

`--json` changes the report and nothing else: the same checks run, the code is the one `gateFailed()`
computed, and nothing is written either way. It composes with `--strict`, which is how a pipeline will
pass it, and there is no combination to refuse — a doctor is not a daemon, so a report cannot be
untrue of the run that produced it. The verdicts above are read back out of this table by
`DbDoctorTest::test_every_json_verdict_is_documented`, the same guard the flip's kind table has, so a
verdict cannot be introduced without documenting it or documented without something producing it. The
decision — including the three candidates it shares with the flip's record, and the two that are the
doctor's own, a rows-free envelope and a per-row exit code — is in
[docs/db-doctor-json.md](docs/db-doctor-json.md).

### Flipping: `db:pgcat-flip`

The flip's exit code is the same kind of contract as the sweep's and the preflight's — it is
what a scheduler, or a `--dry-run` gate on a deploy, branches on — so it is written the same
way:

| what the run found                                            | exit |
|---------------------------------------------------------------|------|
| a flip applied, nothing to do, or skipped by another instance | `0` |
| a flip that was forced                                        | `0` |
| a rehearsal, whether it would flip, would not, or was forced  | `0` |
| the flipper is not armed for this connection                  | `0` |
| `--status`: a state report was asked for                      | `0` |
| a step a flip needs did not work, or a rehearsal of one       | `1` |
| a flag combination that is refused                            | `1` |
| the container has no pgcat flipper                            | `1` |

The rule behind the table is one sentence: non-zero exactly when the run could not do what it
was asked. Applying a flip, having nothing to apply, and being skipped by another instance are
all the run doing its job, so a scheduler never has to read the output to tell them apart —
whereas a step that did not work, or a request that was refused before it started, is a run that
did not happen. The two refusals are `--force-mode=sideways`, which is not a mode, and
`--dry-run --watch`, which would be a daemon whose every pass is a rehearsal.

Every row is pinned by
`DbFlipPgcatCommandTest::test_the_exit_code_is_a_function_of_what_the_flip_would_do`, which
builds each state as a real installation in a temp directory and asserts the code, the line
beside it, the file it did or did not touch, and whether the run recorded a mode. The table is
then read back out of the README by
`DbFlipPgcatCommandTest::test_the_matrix_agrees_with_the_readme_exit_table`, the same guard the
sweep and the preflight have — so a case cannot be documented without a cell behind it, and a
cell cannot be added without documenting it.

One state the table names is not a row of that matrix and has a test of its own: `--status`
wins when a rehearsal is asked for in the same run, because its only evidence is the line the
*other* branch did not print. What each flag does, and which half of a flip a rehearsal can
prove, is under [Pgcat](#pgcat).

### The JSON report: one object for a pipeline

A pipeline should not parse a rendered report. `--json` writes one object and nothing else — the
verdict, the code the process exits with, and the evidence of whichever route produced it — so
the whole of stdout is the report:

```
$ php artisan db:pgcat-flip --dry-run --json
{
    "command": "db:pgcat-flip",
    "kind": "would_flip",
    "exit_code": 0,
    "mode": "readers",
    "previous_mode": null,
    "reason": "the mode has never been applied, so a first flip would run",
    "error": null,
    "steps": [
        {"step": "lock", "outcome": "done", "detail": "taken and released: /…/pgcat-flip.lock"},
        {"step": "read source", "outcome": "done", "detail": "/…/pgcat-readers.toml (35 bytes)"},
        {"step": "supervisor check", "outcome": "done", "detail": "supervisorctl resolves to /…/supervisorctl and knows \"pgcat:*\": pgcat:pgcat_00 RUNNING pid 4242, uptime 0:12:34"},
        {"step": "write temp", "outcome": "done", "detail": "/…/pgcat.toml.tmp.47388 (35 bytes)"},
        {"step": "remove temp", "outcome": "done", "detail": "the rehearsal leaves nothing behind"},
        {"step": "rename", "outcome": "would", "detail": "/…/pgcat.toml.tmp.47388 → /…/pgcat.toml (the content would change)"},
        {"step": "supervisor", "outcome": "would", "detail": "/…/supervisorctl restart \"pgcat:*\""},
        {"step": "state file", "outcome": "would", "detail": "/…/pgcat-flip-state.json (writable, left as it is)"}
    ],
    "status": null
}
```

*The report pretty-prints each step over several lines and names real paths; they are shown one
per line here.* The key set is fixed and always present — `null`, or an empty list, where a route
has nothing for a key — so a rule never has to check whether a field exists:

```bash
php artisan db:pgcat-flip --dry-run --json > rehearsal.json
jq -e '.exit_code == 0 and .kind == "would_flip"' rehearsal.json
jq -e '[.steps[].outcome] | all(. == "done" or . == "would")' rehearsal.json
```

`kind` is the verdict, from one vocabulary:

| `kind`                                                                                                                    | what it means                                                                | exit |
|---------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|------|
| `flipped`                                                                                                                 | a flip applied                                                               | `0`  |
| `no_change`                                                                                                               | the mode on record already matched, so nothing was done                      | `0`  |
| `skipped`                                                                                                                 | another flipper instance held the lock                                       | `0`  |
| `disabled`                                                                                                                | flipping is off, or pgcat cannot front this driver                           | `0`  |
| `status`                                                                                                                  | a state report was asked for (`--status`)                                    | `0`  |
| `would_flip`                                                                                                              | a rehearsal proved a flip would apply                                        | `0`  |
| `would_not_flip`                                                                                                          | a rehearsal found nothing to do                                              | `0`  |
| `failed`                                                                                                                  | a step a flip needs did not work — a flip, or a rehearsal of one             | `1`  |
| `refused`                                                                                                                 | a flag combination was refused before anything was read                      | `1`  |
| `unbound`                                                                                                                 | the container has no pgcat flipper                                           | `1`  |

`mode` and `previous_mode` are the modes the run is about. A `status` report carries its two
inside `status`, which is the flipper's own `status()` array — with `null` for an unset path
rather than the `(not set)` the table substitutes, because a job has to be able to test a path
for being unset. `steps` is a rehearsal's pipeline and empty for every other route; `reason` and
`error` are the sentence a refusal or a failure would otherwise have printed down a human
channel.

Two things `--json` does not do. It does not move a single exit code —
`DbFlipPgcatCommandTest::test_the_json_report_is_the_same_run_as_one_object` runs this whole exit
matrix twice, once for the rendered report and once for the object, and asserts the code, the
file and the record are the same either way. And it cannot be combined with `--watch`: a report a
machine reads is one object, and a daemon would print one every interval without ever reporting a
verdict for the run. The kinds above are read back out of this table by
`DbFlipPgcatCommandTest::test_every_json_kind_is_documented_with_its_exit_code` — the same guard
the exit tables have, so a kind cannot be introduced without documenting it or documented without
something producing it.

## Reporting failures from your own code

```php
try {
    DB::select('SELECT …');
} catch (\Illuminate\Database\QueryException $e) {
    app('db')->markReplicaFailed('10.0.1.11', 5432, $e->getMessage());
}
```

The replica is excluded from routing for an exponentially growing cool-down (30 s, 60 s, 120 s … capped at 30 min) and
retried afterwards.

## Layout

```
src/Database/Weighted/   SWRR algorithm, weight resolver, health monitor, state stores,
                         connection factory, window resolver, manager
src/Pgcat/               pgcat.toml flipper, the supervisor step it runs afterwards,
                         and the two result value objects (a flip, and a rehearsal)
src/Providers/           WeightedDatabaseServiceProvider (replaces the framework's)
src/Console/Commands/    db:doctor, db:replica-status, db:probe-replicas, db:pgcat-flip
src/Http/Controllers/    DatabaseHealthController
src/Support/             ConfigValue (typed config reads), ReaderWindows/ReaderDays (what the
                         reader fallback may contain), BootAudit — findings and their record
config/db-manager.php    the sample config — `vendor:publish` copies it into the app,
                         which then owns and edits its own copy
config/app.php           Laravel 12 skeleton + the provider-swap block above
tests/                   unit tests plus a Testbench test that boots the provider
tests/Support/           fixtures the command tests build their rows from — a pgcat
                         installation in a temp directory, a stand-in supervisor, the
                         real manager with a probe's reporting calls recorded, and the
                         README read as data, so a documented exit code is asserted
                         rather than merely written down
docs/                    design records for decisions that are not obvious from the
                         code — the boot audit's store-probe timing, where its findings
                         are published, and what it does when two of them claim one key,
                         what the package does with a reader window that is not a
                         window, how the flip's supervisor step is checked without
                         taking it, which half of a flip can be rehearsed and how a
                         pipeline reads its verdict, what a deploy gate reads out of the
                         preflight, what the preflight does with replica metadata it
                         cannot read, and how the documented exit codes are kept in step
                         with the code
bin/checks.php           every local check, summed up in one summary
bin/release.php          tag-driven releases (see RELEASING.md)
bin/surface.php          the symbol reader and differ both release commands share
bin/inventory.php        writes files.tsv and methods.tsv from the working tree
bin/publish-config.php   publishes the sample config into an application
files.tsv, methods.tsv   what the last release shipped — its files and their public
                         methods, stamped with the tag they describe, refreshed by
                         bin/release.php and read back by the next release
```

## Testing

```bash
composer install
composer test                     # the three gates CI runs: pint --test, phpstan, phpunit
composer checks                   # everything that can be checked, in one summary
vendor/bin/phpunit --testdox
```

`composer checks` (`bin/checks.php`) runs every applicable check and prints one
summary with one exit code: `php -l` over every file (including `bin/`), an
independent AST parse of the same files, `composer validate --strict`,
`check-platform-reqs`, the workflow YAML, PHPStan, Pint and PHPUnit. It prints only failures by default (`--verbose` streams everything), takes
`--only=syntax,tests` to narrow, reports
a missing tool as a skip rather than crashing on it (`--require-all` turns that
into a failure), and can add `composer audit` with `--audit`.

`phpstan.neon.dist` runs at **level `max`** over `src` with **no ignore entries
and no baseline file** — every finding is fixed, not silenced.
[STATIC-ANALYSIS.md](STATIC-ANALYSIS.md) records the staged burn-down from
level 6, the per-level numbers and the behaviour changes that came with it.

## Releasing

Releases are cut from git tags — there is no `version` field in `composer.json`
and no version file, so a tag is the only version that can be out of date:

```bash
composer release -- --weigh --dry-run    # print the plan, change nothing
composer release -- --weigh              # promote the CHANGELOG, commit, tag
composer release -- --weigh --push       # ...and push branch + tag (or push yourself)
```

`bin/release.php` works the next version out from the latest tag (the base is
`0.0.0` when there are none) and the bump out of the changes themselves:
`--weigh` reads four signals — the Unreleased notes, the commits since the last
tag, the public surface of `src/` and `config/`, and the inventory (`files.tsv`,
`methods.tsv`) the previous release wrote — and applies the policy in
[RELEASING.md](RELEASING.md) — a fix is a patch, a new command or config key is a
minor, a removed or renamed public symbol is a minor while 0.x and a major after
1.0.0. Declaring a smaller bump instead (`--minor`, `--major`,
`--version=X.Y.Z`) stops the release with exit code 1 and names the change that
forbids it, so a breaking change cannot be shipped as a patch. It then promotes
`## Unreleased` to the released heading with a compare link, refreshes both
inventory files in the same commit, keeps both dev-lane branch aliases
(`dev-main`, `dev-dev`) on the line being developed, and refuses to continue on a
dirty tree, the wrong branch, an existing tag or an empty release section. A
version may also be a prerelease — `--version=0.0.1-alpha1`, cut from `dev` — which
promotes the notes and stamps the inventory exactly as a release does; a consumer
opts in with `minimum-stability: alpha` or a constraint like `"^0.0.1@alpha"`. The
suffixes it writes are the ones Composer's own parser reads, and the spellings it
cannot read (`0.0.1-dev.1`, `0.0.1-alpha.1`) are refused rather than written.
[RELEASING.md](RELEASING.md) has the policy, the safety rails and the first-release
walkthrough.

## Changelog

The five defects this package was ported with — unrunnable routing, an unreadable
formula key, disagreeing factor defaults, a broken `db:probe-replicas`, a
hardcoded local-store prefix and silent degradation — are documented with their
fixes in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).

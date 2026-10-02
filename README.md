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
| `swrr.health.pinned_query`       | `SWRR_HEALTH_PINNED_QUERY`     | `true`                       | Whether `GET /health/db` runs one real query — `select 1` — on the connection the package follows before it is allowed to answer `ok` (see [Health endpoint](#health-endpoint)). Read as a **switch**; a value that is not one resolves to the documented default — on — and is reported as `pinned.refused`. Turn it off only where no database exists behind that connection at all — a test fixture, a configuration inspection — because with it off the endpoint's status is the state store's again, and `pinned.checked` is `false` |

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

The package ships `DatabaseHealthController`, which runs one real query on the
connection it follows and reports that connection's replica weights, share %, health,
the active formula, store state and whether pgcat flipping is active. Route it
yourself and protect it:

```php
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;

Route::get('/health/db', [DatabaseHealthController::class, 'index'])
    ->middleware('auth.basic');
```

### It is about one connection: the one the package follows

The subject is `db-manager.swrr.connection` — else `database.default` — resolved once for the whole
payload, so the connection summarised and the connection queried are the same name, and it is the
same name the pgcat gate, the flipper, `db:replica-status` and `db:doctor` read. It is deliberately
*not* every key of `database.connections`: a host application with eight profiles does not have
eight weighted connections, and seven of them are not this package's to report on. Walking that
list also made the endpoint's own status depend on them — each connection with a declared `read`
list was probed with `getPdo()`, so a replica behind any *other* profile that did not answer turned
a healthy followed connection into a `degraded` payload, and a monitoring rule on `status` paged for
a database the package has nothing to do with.

Two fields answer "which connection is this about": `pinned.connection`, and `pinned.source` — the
config key the name came from, so an operator can tell an installation that named one from the
package falling back. `replicas` keeps its shape (a map keyed by connection name) and now holds
that one entry, so a gate reading `.replicas.<name>` is unaffected. To ask about a connection the
package does not follow, ask the command that takes one: `php artisan db:replica-status
{connection=…}`.

### The status is a query, not a store

Every other field of this payload is a reading — of configuration, of the weighted
resolver's bookkeeping, of the state store — and all of them can be well while the
database is not. The endpoint was observed answering `200 "status":"ok"` with every
`replicas` array empty, because the connection it follows declares no `read` list for
the replica probe to open, while application queries were failing with
`SQLSTATE[08006]`. A Redis store that answers is not a database that answers, and a
deploy gate promoting on it is promoting on the wrong fact.

So the controller runs one statement — `select 1` — on the connection
`ActiveConnection::resolve()` names (`db-manager.swrr.connection`, else
`database.default`, the same call the pgcat gate and `db:doctor` make), through the
application's own path, so a failure is the failure a request would have had. The
answer is the `pinned` block, `status` is `ok` only when it came back, and the block
carries the connection, its driver, the config key the name came from, the query, the
latency, and the driver's own error when there is one:

```json
{
    "status": "degraded",
    "pinned": {
        "connection": "pgsql_proxy",
        "driver": "pgsql",
        "source": "db-manager.swrr.connection",
        "query": "select 1",
        "checked": true,
        "refused": null,
        "ok": false,
        "latency_ms": 48.41,
        "error": "SQLSTATE[08006] [7] connection to server at \"localhost\" (::1), port 5432 failed: fe_sendauth: no password supplied (Connection: pgsql_proxy, SQL: select 1)"
    }
}
```

`swrr.health.pinned_query` turns the query off — its default is on, and `checked` is
what says whether it ran: `false` with `ok: null` means *no query was asked*, which is
a different statement from *the query passed*, and anything reading this endpoint as a
signal should read that flag rather than the status. A switch written as something that
is neither on nor off resolves to the default and is reported as `refused`, so a typo
cannot be what silences the check. On a large installation the query is one statement on
one connection per poll, which is the cost this endpoint is worth: the alternative is a
load balancer promoting a revision that cannot reach its database.

Alongside it the payload carries one `pgcat` block — the same
snapshot `db:replica-status` prints and `db:pgcat-flip --status` shows, so the three
cannot disagree about whether flipping is active:

```json
{
    "status": "ok",
    "pinned": {
        "connection": "pgsql",
        "driver": "pgsql",
        "source": "database.default",
        "query": "select 1",
        "checked": true,
        "refused": null,
        "ok": true,
        "latency_ms": 1.21,
        "error": null
    },
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
                "context": {"rejected_windows": [{"at": "[0]", "entry": "\"10:00-14:20\""}]},
                "scope": {
                    "connection": "sqlite",
                    "driver": "sqlite",
                    "source": "db-manager.swrr.connection",
                    "app_env": "sqlite-live"
                },
                "current": {"evaluated": true, "standing": false, "scope_matches": false}
            }
        ],
        "error": null,
        "checked_at": "2026-09-30T09:41:02+00:00",
        "scope": {
            "connection": "pgsql_proxy",
            "driver": "pgsql",
            "source": "db-manager.swrr.connection",
            "app_env": "production"
        },
        "current": {
            "available": true,
            "count": 0,
            "severity": "none",
            "counts": {"error": 0, "warning": 0},
            "findings": [],
            "error": null
        }
    },
    "errors": {}
}
```

The `audit` block is the boot audit's standing findings: the settings that read as on
but cannot act, each with the sentence the boot log carried for it, the level it was
logged at, and how long it has stood — `age` in words, with `age_seconds` for a monitor
that would rather do the arithmetic. `count` and `oldest` save a dashboard from walking
the list.

### What the installation recorded, and what this process sees

The block is two readings of the same settings, and both are there on purpose. `findings` is the
record — what this installation has been claiming, dated from the first boot that wrote it down —
and `current` is a second reading taken while building *this* response, with every recorded
finding annotated under its own `current`.

| field | meaning |
|---|---|
| `scope` | the connection, its driver, the rule that named it and `app.env` that this process resolved while answering — read through the same `ActiveConnection::resolve()` the pgcat gate and the flipper make, so a scope cannot name a connection the package is not following |
| `checked_at` | when the live reading was taken |
| `current` | the audited settings as they read *now*, in the record's own vocabulary — `count`, `severity`, `counts` and the sentences — with `available: false` when there is no live reading to make at all |
| `findings[].scope` | the scope the finding was **recorded** in |
| `findings[].current.evaluated` | whether this process looked at that key. `false` for the one key it cannot answer without paying for a probe (`swrr.primary_store.unreachable`) |
| `findings[].current.standing` | whether the key reads on here — `null` when `evaluated` is `false`, because *nothing asked it* is not the same claim as *it reads well* |
| `findings[].current.scope_matches` | whether the boot that wrote the finding and this process resolved the same scope. `null` when either side is unknown |

The pair exists because a record is per installation while a boot is per process. One installation
has many boots — a migration container, a queue worker, the web process — and they do not
necessarily resolve the same connection or the same environment. A deployment that migrates under
another environment before its web process starts is the concrete case: its findings name a
connection and a Redis host that are not the deployment's, and publishing that sentence beside a
`pgsql_proxy` payload made it read as a problem on the instance answering the request. It now reads
as exactly what it is — `scope_matches: false`, `standing: false`, and the scope it *was* written
in — without the record itself changing: the finding is still dated, still carries its sentence, and
is still closed out by the boot that finds it gone. The live reading costs a handful of
configuration reads and stats and never opens a socket; the one check that needs the network is the
one it deliberately does not make.

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

The rest of it — the same three rules as paste-able patterns, what a load balancer's status
code does and does not cover, and how to tell an installation's fault from the package's once
a page has fired — is in [Alerting: a cookbook](#alerting-a-cookbook).

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
the file is there and is not a record. The message names the file and what is wrong with it
(`…/audit.json is not JSON (Syntax error)`, `…/audit.json is empty`, `…/audit.json does not
hold a map of findings`), because an operator who is told only "unreadable" has been sent to
a file to guess. Neither is a claim about the installation, so the summary stays
`severity: none` — nothing is *known* to stand rather than nothing being wrong.

The same list is printed by `db:replica-status`, so a misconfiguration is visible from
a terminal as well as from a dashboard — and both surfaces render one accessor,
`BootAudit::reported()`, so they cannot disagree about the record either, including when
there is none: the command prints `Audit: not registered` where the payload answers
`available: false` with `error: null`, and `Audit: unreadable — <file> is not JSON …` where
the payload answers with a set `error`, instead of the silence that used to leave "no audit
installed" and "an audit with nothing to say" indistinguishable in a terminal. A record that
cannot be read is not rounded into "nothing standing" either, which would have been the one
answer the file contradicts. It
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
the block is `null`. Neither does the boot audit: a configuration that cannot act is not
a database that cannot answer. The query above is the only field that decides the status,
which is why the endpoint is safe to point a deploy gate at.

## Alerting: a cookbook

Nothing here pages anybody by itself: `severity`, `status` and the blocks around them are
readings, and the alert is the application's to write, because only the application knows who
should be woken. This is the whole of it — the comparisons to paste, and the one question a
page has to answer before anything is done about it.

### The log: three rules, written against the payload

Every line the boot audit writes carries `severity` — the level it was written at — and
`finding`, the setting it is about, in the payload beside the message. So a rule selects on
the payload rather than on the message, and rather than on whatever the channel calls its
level. Under a JSON handler the line is:

```json
{"message":"[WeightedDB] reader_windows[0] is \"10:00-14:20\". Refused: …","context":{"severity":"error","finding":"swrr.reader_windows.refused"}}
```

| what to do | the condition | what it means |
|---|---|---|
| page | `severity == "error"` | a value the package refused to interpret is standing: the installation is running without it |
| ticket | `severity == "warning"`, no `resolved` | a setting that reads as on but cannot act |
| stop the page | `severity == "warning"`, `resolved == true` | the finding cleared — the line the pager stops on. It is logged on every boot while it stands, so the last line per `finding` is the state, and no other state is needed |

The two patterns below are the same rule, and which one applies is the handler's shape: a
JSON channel makes the payload the line, while the default line handler appends it, so there
the rule is a substring match. The substring is also the one you can test by hand:

```
{ $.context.severity = "error" }                  # CloudWatch metric filter, JSON channel
{app="api"} | json | context_severity="error"     # Loki — the JSON parser flattens context
```

```sh
grep -c '"severity":"error"' storage/logs/laravel.log    # the page, default line handler
grep -c '"resolved":true' storage/logs/laravel.log       # and the clears
```

### The endpoint: the status code, then the six things it does not cover

Point a load balancer, an ECS health check or a deploy gate at the route and read the status
code: `200` means the one real query on the pinned connection answered, `503` means it did
not. Nothing else in the payload moves it, deliberately — which is what makes the route safe
to gate on, and also why everything else has to be its own rule. The first row below is the
status code's own fact, spelled out again because a rule that reads it can say *why*:

| the rule | what it means | what to do |
|---|---|---|
| `pinned.checked == false` | the query is switched off (`swrr.health.pinned_query`), so the status code is answering a question nobody asked | ticket: a monitor believes this route is protected when it is not |
| `pinned.ok == false` | the connection cannot answer — the status code says this too, but a rule that reads it can name the driver's own `error` | page |
| `replicas.*.degraded == true` | this worker is serving reads from the in-process store: its rotation is no longer shared with its siblings | page |
| `replicas.*.store_healthy == false` | the primary store is unhealthy — reads are falling back, one pick at a time | page |
| `audit.severity == "error"` | a refused value is standing: the same comparison as the log rule, polled instead of streamed | page |
| `audit.available == false` | nothing could be read at all — `error` null is a provider that is not registered, a set `error` is a record that is not a record | ticket / page, and the next table says which |
| `pgcat.mismatch == true` | flipping is armed where it can never act: there is no pool to swap | ticket |

The whole of it as one gate, for a cron job, a sidecar or a deploy step. `curl` without
`--fail`, because the body is what the rules read — and the audit is left out on purpose rather
than by omission, which is the one commented line:

```sh
curl -sS "$HEALTH_URL" | jq -e '
  .pinned.checked and .pinned.ok
  and ([.replicas[].degraded] | any | not)
  and ([.replicas[].store_healthy] | all)
  and (.pgcat.mismatch != true)
  # and (.audit.severity != "error")   # a page, not a reason to hold a deploy
'
```

With no monitor at all, the same facts are on the terminal: `php artisan db:replica-status`
prints the store state and the audit list, and `php artisan db:doctor --strict` is a release
gate that exits non-zero on any row that warns or fails.

### The audit's live half: does it still stand here, or only in the record?

`audit.severity` is the *record*: what this installation's boots have reported and no boot has
closed out yet. It is per installation, and it says nothing about which process is reading it — a
migration container, a queue worker and the web process share one record and do not necessarily
resolve the same connection or the same environment, so an entry can be true of the boot that wrote
it and false of the host printing it. `audit.current` is the other half: the same audited settings,
read again **in the process answering the request**, with the one check that needs a socket left out.
Both are published because they answer different questions, and a rule that reads only the record can
page an instance for a finding that was never about it.

The per-finding `current` object is where the two are compared. `evaluated` is `false` for a key this
process deliberately did not look at — the store probe, because a failed PING is a connect timeout —
and only then is `standing` `null`; `standing` is whether this process re-derived that finding now;
and `scope_matches` compares the scope the entry was written in with the scope reading it.

| what to do | the condition | what it means |
|---|---|---|
| page | `audit.current.severity == "error"` | a refused value reads as refused **here**, re-derived by this process rather than only remembered |
| page, named | `audit.findings[].current.standing == true` | the key the record holds, re-reported here, so the entry and this process agree |
| ticket the scope's owner, do not page here | `audit.findings[].current.scope_matches == false` | the entry was written by a boot on another connection or environment: it is true about *that* scope, and this process is not it |
| re-check, do not read it as cleared | `audit.findings[].current.evaluated == false` | nothing here asked this setting, so its silence is not evidence of repair |
| ticket | `audit.current.available == false` | the live half did not run here at all, so there is no "here" to compare with |

The rule that pages on this process rather than on the record, for the same cron job or sidecar as the
gate above:

```sh
curl -sS "$HEALTH_URL" | jq -e '
  # the live half of the audit: a finding written in another scope must not page this instance
  .audit.current.severity != "error"
'
```

And the line that hands the other case to whoever owns it — the scope the entry was written in, which
is what an operator needs before anything is done about it:

```sh
curl -sS "$HEALTH_URL" | jq -r '
  .audit.findings[] | select(.current.standing != true)
  | "\(.key) (\(.level)) recorded in \(.scope.connection)/\(.scope.app_env) — here: evaluated=\(.current.evaluated) same_scope=\(.current.scope_matches)"
'
```

`audit.scope` is the scope reading this payload — `connection`, `driver`, the rule that named it, and
`app_env` — and `audit.checked_at` is when the live half was taken, so a rule can say how stale its
"here" is. Neither moves `status`: the live half is a reading, and `status` stays the pinned query's.

### The page: the installation's fault or the package's?

A refused value and a defect in the package both arrive at `severity: "error"` — deliberately,
because a defect nobody reports is worse than a page somebody triages. Triage is mechanical,
and it is three fields: a `finding` key names an owner, and the payload either carries a
setting's own evidence or the keys that mean something else happened.

| what arrives | how you know | whose it is |
|---|---|---|
| a `swrr.*` setting was refused | the message quotes the value, and the context carries none of `levels`, `kept` or `lock` | the installation's: the fix is `config/db-manager.php` |
| "Two findings this boot share the key …" | the context carries `levels` — two branches of the package produced one key | the package's: no configuration can repair it, and the record keeps the louder of the two |
| "The audit record changed while this boot was running …" | the context carries `kept` and `keys_this_boot_read` | the installation's: two boots in flight were running different configurations, so the entry is kept rather than wrong — it stands until a boot evaluates the key cleanly with nothing re-reporting it |
| "The audit record lock could not be taken …" | the context carries `lock` and `attempts` | the host's: the record's directory would not accept the lock file, or another boot held the lock longer than this one waits — the merge ran unserialised, which is the one write left that can lose an entry |
| `audit.available: false` with `error` set | the message names the file and what is wrong with it (`… is not JSON (Syntax error)`, `… is empty`, `… does not hold a map of findings`) | the installation's: a path, a permission, or half a write |
| `audit.available: false` with `error: null` | there is no record and no reason — nothing was read because nothing is registered | the installation's: the package's provider is not registered |
| a line with **no** `finding` key | `[WeightedDB] Degraded to the in-process SWRR store.` and its siblings, which carry `connection`, `store` and a `reason`/`error` instead | the store's: no boot audit is involved, and this is the degradation described above |

A refused value is the only row whose repair is a configuration change, and the repair is
reported rather than inferred: the record keeps a finding until a boot stops reporting the key,
and that boot logs the resolution at `warning` with `resolved: true` — so the page on a setting
closes itself, and a page that never closes is a defect or a file.

## Artisan commands

All four are registered by the provider.

| Command                                                                      | Purpose                                                                                                       |
|------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| `php artisan db:doctor {connection=pgsql} [--strict] [--json] [--config-file=path]` | Preflight: check an installation end to end before traffic arrives — or vet one config file before it is installed |
| `php artisan db:replica-status {connection=pgsql} [--json]`                  | Table of host, weight, share %, health, plus formula, store backend, pgcat state and the boot audit's standing findings |
| `php artisan db:probe-replicas {connection=pgsql} [--json]`                  | `SELECT 1` against every replica and feed results to `HealthMonitor` (schedule every 30 s)                    |
| `php artisan db:pgcat-flip [--status\|--watch\|--dry-run\|--force-mode=] [--interval=] [--json]` | Keep `pgcat.toml` in step with the reader/writer window — a no-op unless the current connection is PostgreSQL |

```php
// routes/console.php
Schedule::command('db:probe-replicas')->everyThirtySeconds()->withoutOverlapping()->runInBackground();
```

Each probe opens a throwaway single-host connection (the pooled `read`/`write`
lists are stripped) and purges it afterwards, so probing never feeds the weighted
pool it is measuring. Diagnostics go to the replica's own `host:port` key, the
same key `WeightResolver` uses.

### The flipper schedules itself

`db:pgcat-flip` is not yours to schedule. The provider registers it on the container's
schedule the first time that schedule is resolved — which is what `schedule:run` and
`schedule:list` do — so there is no entry to write into `routes/console.php`, and none to
forget to write. The entry is one event, on the cadence the settings below ask for (a minute by
default):

```php
$schedule->command('db:pgcat-flip')
    ->cron('* * * * *')            // interval_minutes: 1 — `*/5 * * * *` for five
    ->withoutOverlapping(2)
    ->createMutexNameUsing(fn (): string => 'framework/schedule-pgcat-flip-'.gethostname())
    ->name($name)
    ->appendOutputTo($log);
```

| Setting | Default | What it decides |
|---|---|---|
| `swrr.pgcat.schedule.enabled` | follows `swrr.pgcat.enabled` | Whether the entry is registered. A switch, read as one (`yes`/`no`, `on`/`off`, `1`/`0`), and a value that is neither resolves to `swrr.pgcat.enabled` and is reported at `warning` rather than obeyed — a typo must not be what stops the repair. |
| `swrr.pgcat.schedule.interval_minutes` | `1` | How often the flip runs, in whole minutes: `5` is a step of five in the minute field, so the entry runs at :00, :05, :10 … Raising it is a trade rather than a saving, because the attempts are bounded by the boot window and not by the cadence — see below. A value the minute field cannot hold — `0`, a negative, a non-number, or more than `59`, past which a step *wraps* rather than meaning what it says — resolves to `1` rather than being written into an expression that means something else. An installation that needs a cadence wider than an hour registers its own `db:pgcat-flip` entry, which the guard below leaves alone. |
| `swrr.pgcat.schedule.name` | `pgcat_flip` | What `schedule:list` shows the entry as, and what the duplicate guard matches against. |
| `swrr.pgcat.schedule.log` | `storage_path('logs/scheduled_tasks/pgcat_flip.log')` | Where each run's output is appended. The directory is created if it is missing: a command event runs through a shell redirect, and a log that cannot be opened is a command that never runs. |

Two Laravel conveniences are deliberately absent, because the flipper is container-local — it
replaces `/etc/pgcat/pgcat.toml` and talks to the supervisord in its own container:

- **`onOneServer()`** would let exactly one container flip per scheduled run and skip every other,
  leaving the rest running a configuration nobody maintains;
- **the mutex name `withoutOverlapping()` derives on its own** is `sha1(expression + command)`,
  which is the *same name in every container* when the cache store is shared — so one slow
  container would hold the lock for all of them.

`createMutexNameUsing()` scopes the name to the container, and the hostname being unreadable
falls back to a random suffix rather than to the empty string, which would put every container
back on one shared name. The authoritative serialisation is still the flipper's own `flock` on
`swrr.pgcat.lock_file` — container-local, needs no shared cache, and already what makes a
concurrent run return `skipped` rather than queueing. Not `runInBackground()` for the same
reason it is not for a doctor run: a failure belongs in the schedule's own log, not in a
detached process nobody reads.

An entry you wrote yourself is left alone rather than joined by a second one — the guard reads
the name *and* the command — so an installation upgrading from a version that asked you to paste
the entry keeps working, and should delete its own copy to get the package's name and log
handling. Two entries would be two `schedule:run` passes a minute, and the overlap protection of
one cannot serialise the other.

### How long the flip keeps trying: the boot window

The flip is scheduled — every minute by default — and stops at the end of a window measured from
the container's own boot — `swrr.pgcat.flip_window_seconds`, 480 (eight minutes) by default.
Without a bound, a pooler that will never come up is retried for as long as the container lives,
and a container nobody replaced looks busy instead of broken.

Eight is the number the window is chosen in: ECS reports a healthy container within six or seven
minutes of task start, so the window must not close before that, and eight attempts at the shipped
minute is far more than the first or second one a working flip needs. By the end of it the container
has either flipped to the writer-only configuration — the database that is always up — or the pooler
cannot be made to serve at all, and one more attempt cannot tell those apart.

**The window, and not the cadence, is what bounds the attempts.** An eight-minute window holds eight
runs at `interval_minutes: 1` and two at `5`; an interval at or past the window's own length is *one*
attempt. That is the trade the setting makes, and the reason to raise `flip_window_seconds` with it
rather than on its own: an installation that slows the cadence without widening the window has given
the pooler less time to be repaired, not less work to do.

- **Where the clock starts.** A file `entrypoint.sh` writes before the pooler is started, holding
  `date +%s` (`swrr.pgcat.boot_file`). Deliberately not the PHP process start, which is a different
  moment for every Octane and queue worker, and not "when the first flip ran", which cannot happen
  at all if the schedule is the thing that is broken.
- **What stops.** At the deadline `db:pgcat-flip` returns `window_closed` and exits `0` without
  reading, writing, running or recording anything — the run did what it should have, and a
  scheduler must not see an error line every minute forever.
- **What fails.** `/health/db` reports `flip.failed: true` (HTTP 503) when the window closed and no
  run inside it reached a usable mode, and it logs the failure with the window beside it. The
  `reason` names which of the three ways it failed, because the repairs differ: the flip never ran
  (`db:pgcat-flip` is not scheduled, or cannot reach the flipper), every run inside the window
  failed (the pooler is the problem), or the flip only converged after the window had closed (the
  container was too slow).
- **What does not fail.** A window that is still open, a container with no boot stamp at all
  (`window.source: no_stamp` — a local run, or an image whose entrypoint predates the stamp), and
  an installation where pgcat does not apply. `db:pgcat-flip --status` prints the whole window as
  `flip_window` / `flip_window_state` / `flip_converged` / `flip_runs` rows, and `--status --json`
  carries it under `status.window`, so the same four facts are readable without an HTTP endpoint.

The window says nothing about `--dry-run`: a rehearsal answers what a flip *would* do, and a
container that has stopped flipping is exactly the container a deploy wants to rehearse against.

### Reading the distribution: `db:replica-status`

Prints each weighted replica's host, port, CPU cores, RAM, resolved weight, share of reads, health
and failure count — then the formula in force, the pool cache size, the store backend and whether
it is healthy, pgcat's state, and the boot audit's standing findings. Nothing it *finds* moves an
exit code: it reports, and `db:doctor --strict` is the gate. The one code that is not `0` is the
guard *before* the report — a container with no weighted manager, where there is no distribution to
print — and it is `1` on both channels, so a pipeline that runs this command sees a failure exactly
when the deployment never registered the manager.

`--json` writes the same run as one object, in the envelope `db:pgcat-flip` and
`db:probe-replicas` write: the command, the verdict, the exit code, and the evidence after them.
The evidence is the manager's own `healthSummary()` under `status`, the flipper's under `pgcat`,
and the audit's block under `audit` — the last two are the same arrays `/health/db` embeds under
the same two names, so a job reading a terminal report, a saved one and the endpoint is reading
one vocabulary:

```bash
php artisan db:replica-status weighted --json > distribution.json
jq -e '.kind == "distribution" and .exit_code == 0' distribution.json
jq -e '.status.degraded == false and .status.store_healthy' distribution.json
jq -e '[.status.replicas[] | select(.healthy == false)] | length == 0' distribution.json
jq -e '.audit.available and .audit.count == 0' distribution.json
```

| `kind`         | what it means                                              | exit |
|----------------|------------------------------------------------------------|------|
| `distribution` | the weighted replicas were read                            | `0`  |
| `no_replicas`  | the connection has no weighted read replica to describe    | `0`  |
| `unbound`      | the container has no weighted manager, so nothing was read | `1`  |

Degradation is a field rather than a verdict: `status.degraded` says the primary store is down and
reads are being served from this process's own store, and the command still exits `0` — the number
is not what a deploy should branch on, the field is.

Every route is reported, including the two that found nothing to describe, and this table is bound
the same way the other commands' tables are: `DbReplicaStatusTest::test_the_matrix_agrees_with_the_readme_exit_table`
reads it and asserts that the routes a run can reach are exactly the rows written here, that every
cell of the exit matrix is an instance of one of them — each route exits the same code whether the
run renders a table or writes an object, which is why the table has one `exit` column — and the code
each one exits. The one row that exits `1` is the guard, and it is driven down both channels:
`DbReplicaStatusTest::test_the_exit_code_is_a_function_of_the_route_and_the_channel` runs every
route on the terminal and again as an object.

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

`--json` writes the same sweep as one object, in the envelope `db:pgcat-flip` and
`db:replica-status` write — and the per-replica rows `-v` prints are its `replicas`, so `--json -v`
is one object rather than two channels. The counts are of the replicas the sweep attempted, and a
*partial* sweep is still `answered`, which is the asymmetry the exit table documents: the object
says which replica failed and why, and the code says whether the run did its job.

```bash
php artisan db:probe-replicas weighted --json > sweep.json
jq -e '.kind == "answered" and .exit_code == 0' sweep.json
jq -e '[.replicas[] | select(.healthy == false)] | length == 0' sweep.json
jq -e '.counts.probed == (.counts.answered + .counts.failed)' sweep.json
```

| `kind`            | what it means                                                    | exit |
|-------------------|------------------------------------------------------------------|------|
| `answered`        | at least one replica answered — a partial sweep is still this     | `0`  |
| `no_read_list`    | the connection has no `read` list at all                          | `0`  |
| `no_replica_maps` | no entry in `read` is a replica map, so nothing could be probed   | `1`  |
| `none_answered`   | every replica failed                                              | `1`  |
| `unbound`         | the container has no weighted manager, so there was no sweep      | `1`  |

The kinds are the cases the exit table above documents, one to one — a case that exits differently
has to be a different verdict, or `kind` would be a word a scheduler cannot branch on — and
`DbProbeReplicasCommandTest::test_every_json_kind_is_documented_with_its_exit_code` reads this
table back to assert exactly that.

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
| `pgcat files`        | the flipper is armed and a file a flip needs is missing, unreadable or unwritable — including the target's directory, where the atomic swap writes `{target}.tmp.{pid}`, and the state/lock directory. Names **every** problem it finds, one sentence each and each dated from its own finding, and prints a `suggestion` per problem where the package can state the repair exactly: `chmod +r` for a source it cannot read, `chmod +w` for a file it cannot write and `chmod +wx` for a directory (a rename has to traverse it), and — for an *empty* `config_path`/`state_file`/`lock_file` — the setting line with the value the published config ships. A path that is not there, and an empty `readers_path`/`no_readers_path`, get no line: those values are this installation's to choose, and a plausible-looking path pasted into configuration replaces the operator's intent with this tool's |
| `pgcat supervisor`   | the flipper is armed and the command it runs *after* the swap cannot work: its executable does not resolve for this user (not an absolute path, not on `PATH`), the program name is an unquoted glob the shell may rewrite, supervisorctl cannot answer, or supervisor does not know the program. Read-only: the flip's own command with `status` in the verb position, plus — only when supervisor does not know the program, the one fault whose repair is a name — the bare `supervisorctl status` that lists what supervisord is running, whose nearest names the row then reports. Prints the setting line to paste as a `suggestion` for the two faults that reduce to a command — an unquoted program name, and a command left empty — and nothing for the rest, which are repaired in a `PATH`, a running supervisord or a `[program:]` section; an unknown program is named in the row's sentence rather than printed as a line, because a near miss is a ranked answer and a `suggestion` is a value a gate may apply without reading it |
| `replica metadata`   | *fails* when a replica's `weight`/`cpu_cores`/`ram_gb` is a value the resolver does not read as written — not a number, or a number under the floor that key is read at — naming the replica and what the resolver reads instead. A weight it cannot read is read as `0`, and `0` is how a replica is disabled, so that replica leaves the pool: without this the row reports the smaller pool as the installation. Also names a replica disabled with `weight: 0`, which is a choice and does not fail. The pool's own arithmetic comes from the resolver rather than from this row: `replicaStatus()` is the pool, `poolExclusions()` is what it does not hold and why, and the two partition the read list. *Warns* when the replicas carry no metadata at all, so the resolver picks at random while the table still looks weighted |
| `switch values`      | **Fails** when `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` or `swrr.allow_local_fallback` is written as something that is not on or off — `'false'`, `'off'` and `'no'` are how off is written, and a cast reads every one of them as *on*, which on the pgcat switch arms the file swap. The row quotes the value, quotes the accepted spellings, and names the value the setting falls back to. Names **every** switch it refuses rather than the first, and dates each from its own finding in the boot record. Nothing is suggested: re-spelling `'flase'` would be guessing at what was meant |
| `reader windows`     | `swrr.reader_windows` holds an entry that is not a window — the flat `'10:00-14:20'` is the one everyone writes first — or is not a list of windows at all, or `swrr.reader_days` is not a list of day numbers — `'1,2,3'` written as one string is the same mistake one key over. **Fails** on both, naming the entry and quoting the shape the setting reads: reading a typo as "nothing" is what inverts the setting, so the package refuses it instead. *Warns* on the three ways a well-formed setting still cannot act — an empty day list (permissive), days outside 1…7 (the pool is never used), and windows whose start is not before their end (never entered). When the refused value names its own replacement — the flat string, `'1,2,3'` — the row also prints the setting line to paste as a `suggestion` under it. Names **every** problem it finds rather than the first, and dates each from its own finding in the boot record |
| `store probe`        | *warns* when the store check can never run: probing switched off (`swrr.audit.store_probe_seconds = 0`), or an in-process primary store with nothing to reach. **Fails** when the audit record cannot be written — the record is what throttles the probe, so without it the `PING` is skipped on every boot and an unreachable store goes unreported. Names **every** state it finds rather than the first: an installation that is both switched off and unable to write the record is told both, and the row's verdict is the loudest of them. The two states are boot findings of their own (`swrr.audit.store_probe_seconds.off` and `swrr.audit.file.unwritable`), so each is dated from its own record entry — except the unwritable record, which is the one state whose own record cannot hold the date it would print |
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

Both states are boot findings as well, under a key each —
`swrr.audit.store_probe_seconds.off` and `swrr.audit.file.unwritable` — so the row dates each
from its own and a preflight can say how long the installation has been unable to check its
store rather than only that it is. The second is the one finding whose own record can never hold
its date: the file a date would be written to is the file that cannot be written, so an operator
gets the log line, and the row is the surface that remembers nothing about it. A probe that is
switched on with an in-process store is named by the row and is not a finding, because there is
nothing it could have been checking.

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
*kept*, and the resolver reads each setting at a floor declared once —
`ReplicaMetadata::WEIGHT_FLOOR`, `CORES_FLOOR` and `RAM_FLOOR`, the arguments its own
`max()` reads — so a value that is not a number is read as the floor for its key, and for
a weight that floor is 0, which is exactly how a replica is switched off. An installation
whose weight was a typo therefore lost that replica from the pool without a word, and the
row used to report the pool that was left: a count short by one, with nothing to say which
replica went or why. It now reads the *configured* replicas and fails, naming each one and
the value:

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
with two is the same quiet shrink through the front door. That name is in every branch and
not only the passing one, because the other two are about *values*: a refusal returns
before the pool is described at all, so a `weight: 0` beside one used to be a replica
nothing said was gone until the deploy after the repair, and a pool in which every replica
is disabled is reported as the replicas somebody switched off rather than as a fact about
the pool. The rule is the resolver's own
arithmetic rather than a restatement of it — its floors included, since the row reads them
from the same three constants the resolver clamps at, so a boundary that moved fails a test
rather than a routing decision — which is what makes a negative weight, a `weight: 0.5`
that truncates to 0, and a `weight: null` all reportable;
`docs/replica-metadata-refusal.md` records which values are refused, which are named, and
why `weight: true` is left alone.

The same value is refused **at boot**, which is the half that was missing: the row answers
when somebody runs a preflight, while the replicas are read when the process reads its
configuration, usually long before anyone looks. Every boot reads the configured metadata
through the same rule the row does — one class, so a log line and a preflight cannot describe
the same value two ways — and a value the resolver does not read as written is logged at
`error` level, remembered, and reported with the other findings under one key per setting:
`database.read.weight.refused`, `database.read.cpu_cores.refused` and
`database.read.ram_gb.refused`. One key each, because the three read differently and cost
differently — the weight is the one that removes a replica from the pool, and its sentence
says so, while a core count and a memory figure leave the replica there, sized as something
the read list does not describe. The keys are the setting's own path
(`database.connections.*.read.*.weight`) rather than one of the `swrr.*` names, because the
read list belongs to the connection and that is what an operator greps for; which connection
the package followed is in the finding's context. Nothing is repaired and nothing throws —
the value is still read exactly the way the resolver reads it — so an installation with a
typo boots and serves, and what has changed is that the shrink is no longer silent:
`/health/db` reports `severity: "error"` for it, and the finding closes out on the boot that
reads a readable value, whether it was fixed, dropped from the read list, or removed with the
whole list.

What the refusal *costs* the pool is no longer predicted by anything. The resolver is the thing
that builds it, so it reports the replicas it did not use and the reason for each —
`resolveWithExclusions()`, whose `pool` and `excluded` partition the read list — and
`poolExclusions()` is the manager's read of it: the other half of `replicaStatus()`, deliberately
without a health filter, because which replicas are out of rotation *right now* is the health
monitor's to report per replica. So the row can say which replica left and why instead of
comparing lengths, and the read path's own warning on an empty pool names the replicas it was
about rather than saying "all replicas" into a log with no other evidence in it. The three
reasons are `refused` (a weight the package will not read), `disabled` (`weight: 0`, which the
read list means) and `filtered` (the caller's health filter, for that call only). That decision
is in [docs/pool-exclusions.md](docs/pool-exclusions.md).

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
lines, one repair each — and the same shape is what `pgcat files` prints its file problems
in, one line per problem where the package can name the repair.

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
one per problem it can repair, and the two kinds are the two the package knows rather than guesses:
a **mode** for a path this installation has chosen and cannot use, and a **setting line** for an
empty key whose value the published config ships. A line's shape says where it goes — `chmod …` is
run, `swrr.pgcat.… = …` is pasted — which is what a gate binding to `suggestions` needs to know. A
path that is not there, and an empty `readers_path`/`no_readers_path`, still get none: the file has
to be put there by whatever installs pgcat, and the package will not name a value it would have to
guess at. `PgcatConfigFlipper` holds all of it — `suggestionForGate()`, `suggestionForSupervisor()`
and `fileProblems()` — so every line comes from the class that knows which setting a flip reads and
which file it touches — and a `FAIL` stays a `FAIL` whether a line is printed under it or not.

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
                          its own name. supervisorctl status says supervisord runs 2
                          program(s): pgcat_x:pgcat_00, pgcat_x:pgcat_01 — the closest
                          are "pgcat_x:pgcat_00", "pgcat_x:pgcat_01", which are the
                          names to write, or the whole group "pgcat_x:*" — a flip
                          refuses before it swaps the file, so nothing is replaced and
                          no mode is recorded
```

The first of those two carries a `suggestion` and the second does not, which is the whole rule
in one screen: the unquoted name reduces to a command the package can write down exactly (and
names the key a flip reads — `reload_command` here, because `use_reload` is on), while "the
program supervisor does not know" is repaired somewhere the package cannot write down. It
can name that repair's *candidate*, though: a name is what this fault costs, so the row asks
supervisor the one question that answers it — what it is running at all — and reports the
programs nearest the configured name, nearest first. The line stays absent because a close
name is a ranked answer for a reader, and a `suggestion` is a value a gate may apply without
reading it. The row states the fault either way, and the file is not swapped either way.

The row asks supervisor rather than guessing, and asks it read-only: the command it runs
is the flip's own with `status` in the verb position, `supervisorctl status "pgcat:*"`,
so nothing is restarted or signalled. When that answer is `no such group`/`no such
process` — the fault whose repair is a name — it asks once more, `supervisorctl status`
with no program, which lists every program supervisord is running; nothing else ever runs
that second command, so a command that works costs one process as before. The command itself comes from
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

The one-liners above are what a person types at a prompt. What a deploy pastes is the gate below:
**the rows this pipeline treats as blocking are named in it**, editing that list is the whole of
configuring it, and it refuses the deploy — printing the rows — when one of those rows is not `PASS`
(a `WARN` on a row you named is a row you said must pass) or when some row `FAIL`ed, which the run's
own `verdict` folds. The object decides and not the exit code: the command's status is discarded on
purpose, so a preflight that never got written is an empty file, and `jq` refuses that as loudly as a
failing row does.

```bash
# The deploy gate: name the rows your pipeline treats as blocking, and this refuses the deploy —
# printing them — when one is not PASS, or when any row failed.
php artisan db:doctor --strict --json > preflight.json || true

jq -e '
  ["provider swap", "weighted factory", "store reachability"] as $blocking
  | . as $preflight
  | [$preflight.checks[]
     | select(.name as $row | $blocking | index($row))
     | select(.verdict != "PASS")]
  | if length > 0
    then error("refusing to deploy: " + (map("\(.verdict)  \(.name)") | join("; ")))
    elif $preflight.verdict == "FAIL"
    then error("refusing to deploy: the preflight failed — "
               + ([$preflight.checks[] | select(.verdict == "FAIL") | .name] | join(", ")))
    else true
    end
' preflight.json
```

A gate is a promise about field names, and field names are what a later commit changes. So this block
is read back by `tests/Unit/Docs/DoctorGateTest.php`, which runs *this* program through `jq` against a
report a real `db:doctor --json` run produced: a clean preflight it must ship, and two it must refuse
— a named row that warned, and a run whose unnamed row failed — each while naming the row it refused
on. Every row the gate names must also be a row a run really produces, because a renamed row is the
one failure the gate cannot report: it would simply match nothing, and pass.

`--json` changes the report and nothing else: the same checks run, the code is the one `gateFailed()`
computed, and nothing is written either way. It composes with `--strict`, which is how a pipeline will
pass it, and there is no combination to refuse — a doctor is not a daemon, so a report cannot be
untrue of the run that produced it. Every key a gate selects on is the same in both modes; the only
difference is the pair that names the *subject* — `connection` and `default_connection` for a run of an
installation, `config_file` for a run of a file (see below). The verdicts above are read back out of this table by
`DbDoctorTest::test_every_json_verdict_is_documented`, the same guard the flip's kind table has, so a
verdict cannot be introduced without documenting it or documented without something producing it. The
decision — including the three candidates it shares with the flip's record, and the two that are the
doctor's own, a rows-free envelope and a per-row exit code — is in
[docs/db-doctor-json.md](docs/db-doctor-json.md).

### Vetting a config before it is deployed: db:doctor --config-file

A deploy that changes `config/db-manager.php` has one moment the preflight above cannot serve: the
branch's config is a candidate, and the configuration installed on the host is the *old* one. Every
row of the preflight is a question about a running installation — which provider binds `db`, whether
the gate can act, whether the files exist, what the replicas weigh, whether the store answers — and one
run against a candidate on a host running the incumbent would answer all of them about the incumbent
and print the candidate's name above them.

`--config-file=path` asks the other question. It reads one config file and reports the values the
package would *refuse* in it, exiting `1` if there are any, so a pipeline can fail the build before
anything is deployed with that file. The two files a deployment can change are read for what each
holds: a `config/db-manager.php` for its switches and reader windows (as below), and a
`config/database.php` for the replica metadata in the read list of the connection named on the
command line — the `connection` argument, `pgsql` by default. A file holding both blocks is judged
for both.

```bash
php artisan db:doctor --config-file=config/db-manager.php
php artisan db:doctor --config-file=config/database.php
php artisan db:doctor --config-file=config/db-manager.php --json | jq -e '.exit_code == 0'
```

```
Config file vetted: config/db-manager.php — the values it would be refused for, read without the installation

PASS  config file         config/db-manager.php read as an array holding a "swrr" block — its switches and reader settings are judged below
FAIL  switch values       swrr.pgcat.enabled is "flase" — a switch is on or off — true/false, 1/0, or the strings '1'/'0', 'true'/'false', 'on'/'off', 'yes'/'no'. Refused: the switch falls back to off — the value this setting documents — so the flipper is inert and no pgcat file is swapped.; swrr.pgcat.use_reload is "maybe" — …; swrr.allow_local_fallback is "nope" — …
FAIL  reader windows      swrr.reader_windows is "10:00-14:20", not a list of windows — each window must be an array like ['start' => '10:00:00', 'end' => '14:20:00'] — a string such as '10:00-14:20' is not a window. Refused: nothing is left to apply, so reads use the replica pool at every hour.; swrr.reader_days is "1,2,3", not a list of days — …
      suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]
      suggestion          swrr.reader_days = [1, 2, 3]

3 checks: 1 passed, 0 warnings, 2 failed
A value in this file would be refused: a boot of it runs on the defaults, and logs the refusals above.
```

It is not a quieter doctor, it is a different subject: read the file and nothing else. No container
binding, no `db-manager` repository, no boot audit, no database and no Redis are touched — the store is
never probed, there is no provider row to pass, and the mode answers even when the package cannot boot
at all. The rows it does print are the same rule the installation rows apply, from the same classes
(`Support\SwitchValue`, `Support\ReaderWindows`/`ReaderDays`, and the flipper's own reader for its two
switches), so a value this mode passes is a value the next boot will not refuse. Nothing is dated: a
boot record holds findings about the installation, and the candidate has never been booted.

What it reports is the **refusals** — the values the package will not read. The rest of what `reader
windows` says on a running installation, "this reads as a window but can never be entered", needs the
resolver built over the value, and the switch warnings need the boot: `db:doctor --strict` on a real
deployment is where those fail a pipeline. `replica metadata` is no exception to that line, and it is
the reason the line is drawn at the resolver rather than at "anything a file can be asked":
`Support\ReplicaMetadata` classifies one replica array on its own — it is the classifier the boot
audit refuses on — so a candidate read list can be refused here without a resolver. A weight the next
boot would refuse therefore cannot pass this vet.

The rows are `config file` (readable, an array, a `swrr` and/or a `connections` block, and nothing
printed while it was read — a config file that echoes is a warning, because `config:cache` would bake
those bytes into the cached file) and, for each block the file holds: `replica metadata` for the named
connection's read list, `switch values` and `reader windows`. `--strict` and `--json` work as they do
everywhere, and `config_file` replaces the connection pair in the object. A file that is not there, is
not an array, holds the `swrr` block itself instead of returning it, or holds a `connections` block
without the connection the run named, fails with the mistake named — a vet that reported no refusal
for a file it never read would be worse than no vet at all.

### Flipping: `db:pgcat-flip`

The flip's exit code is the same kind of contract as the sweep's and the preflight's — it is
what a scheduler, or a `--dry-run` gate on a deploy, branches on — so it is written the same
way:

| what the run found                                            | exit |
|---------------------------------------------------------------|------|
| a flip applied, nothing to do, or skipped by another instance | `0` |
| a flip that was forced                                        | `0` |
| the container's boot window had closed                        | `0` |
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
cell cannot be added without documenting it. The three other places this README says what the
flip exits — the rehearsal recipe, the boot-window bullet, and the disarmed-flipper row under
[Pgcat](#pgcat) — are compared with the row each one restates by
`DbFlipPgcatCommandTest::test_the_prose_that_restates_the_table_states_the_same_codes`, because a
second statement of a code is a second thing to keep in step.

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
    "reason": "the mode has never been applied, so a first flip would run",
    "error": null,
    "mode": "readers",
    "previous_mode": null,
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
per line here.* `db:pgcat-flip`, `db:probe-replicas` and `db:replica-status` all write this
envelope: the same five keys in the same positions — `command`, `kind`, `exit_code`, `reason`,
`error` — and then the evidence of whichever command and route produced the object. It is defined
once, in `Console\JsonEnvelope`, so there is one place that says what a report leads with;
`db:doctor --json` is the exception, and says so in [its own
record](docs/db-doctor-json.md) — what a gate asserts of a preflight is *rows*, and a run of a
flip or a sweep is one verdict.

The key set is fixed and always present — `null`, or an empty list, where a route
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
| `window_closed`                                                                                                           | the boot window had closed before the flip converged                         | `0`  |
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
src/Database/Weighted/   SWRR algorithm, weight resolver (and the exclusions it reports),
                         health monitor, state stores, connection factory, window resolver,
                         manager
src/Pgcat/               pgcat.toml flipper, the supervisor step it runs afterwards,
                         and the two result value objects (a flip, and a rehearsal)
src/Providers/           WeightedDatabaseServiceProvider (replaces the framework's)
src/Console/Commands/    db:doctor, db:replica-status, db:probe-replicas, db:pgcat-flip
src/Http/Controllers/    DatabaseHealthController
src/Support/             ConfigValue (typed config reads), ReaderWindows/ReaderDays (what the
                         reader fallback may contain), ReplicaMetadata (what a replica's
                         weight/cpu_cores/ram_gb may be, the floors the resolver clamps at,
                         and which replica a report is naming),
                         BootAudit — findings and their record
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
                         preflight, what the package does with replica metadata it cannot
                         read — at boot and in the preflight — what a resolver reports about
                         the replicas it did not use, how the documented exit codes are
                         kept in step with the code, and how a number a record states is
                         kept equal to the number the code has — rendered by a command
                         where that is possible, checked where it is not
bin/tools.php            where each installed tool's entry file is written down, once
bin/tool.php             runs one of them by name: what every composer script goes through
bin/checks.php           every local check, summed up in one summary
bin/counts.php           writes the counts a record states from the code that owns them
bin/release.php          tag-driven releases (see RELEASING.md)
bin/surface.php          the symbol reader, the surface differ and the inventory format
                         the release commands share
bin/weighing.php         the four signals and the weighing release.php and blame.php share
bin/inventory.php        writes files.tsv, methods.tsv and surface.tsv from the working tree,
                         or from any ref with --at= (see RELEASING.md)
bin/api.php              renders API.md — the public API page — from that record, so the
                         rows a release weighs are also the page a consumer reads
bin/blame.php            which signal names one symbol, and what it contributed to the bump
bin/publish-config.php   publishes the sample config into an application
files.tsv, methods.tsv,  what the last release shipped — its files, their public methods,
surface.tsv              and the config keys, env vars, constants and properties a
                         consumer can name, stamped with the tag they describe,
                         refreshed by bin/release.php, backfilled for a past tag from
                         that tag's own tree with bin/inventory.php --at=REF, and read
                         back by the next release
API.md                   the same rows as a page a person reads — every class with its
                         public methods, their argument shapes, and the config the
                         package reads — rendered from the record by bin/api.php and
                         guarded by PublicApiReportTest, so the page cannot describe a
                         different tree from the one the release weighed
```

## Testing

```bash
composer install
composer test                     # the three gates CI runs: pint --test, phpstan, phpunit
composer checks                   # everything that can be checked, in one summary
php bin/tool.php phpunit --testdox   # any installed tool by name (see bin/tools.php)
```

The records under `docs/` are read by the suite as well as by people: the exit tables are compared
cell by cell with the matrices the commands are written as, the test names they cite are checked
against the classes that declare them, and every count they state about this package's code is
compared with the number the code has — see [docs/prose-numbers.md](docs/prose-numbers.md).
[API.md](API.md) is checked the same way, against the inventory it is rendered from: the page has
to be the bytes the record produces, or `PublicApiReportTest` fails and names
the command that writes it.

The counts in `docs/documented-exit-codes.md` go one step further and are not written by hand at
all: `php bin/counts.php` renders them from the providers, `php bin/counts.php --check` reports
drift without writing, and `composer checks` runs that check — so after adding a matrix cell, run
the renderer rather than editing the sentence.

### The tools this package installs

Every tool a script here runs is one record of `bin/tools.php` and one row here, and the two are
compared cell by cell by `ToolTableTest` rather than kept in step by hand. **Pin** is the constraint
`composer.json`'s `require-dev` asks that package to be at; `none` is a tool that arrives with
something else instead of being required here, which is why the manifest writes its pin as `null`.
What a *machine* has installed is a different fact, and it is read where the machine is: `composer
checks` reports every installed version against its pin, so a constraint raised without a `composer
update` stops the gate instead of quietly running the older tool.

| Tool | Package | Pin | What it is for |
| --- | --- | --- | --- |
| `pint` | `laravel/pint` | `^1.25` | the formatter behind `composer lint`, and the style gate `composer test:lint` runs |
| `phpstan` | `phpstan/phpstan` | `^2.0` | static analysis of src/ at the level phpstan.neon.dist asks for |
| `phpunit` | `phpunit/phpunit` | `^11.5` | the test runner the suite is written for |
| `yaml-lint` | `symfony/yaml` | `none` | the linter the gate runs over the workflow YAML |

`php bin/tool.php --list` prints the same records with their entry files, and
`php bin/tool.php <tool> [arguments …]` runs one by name — which is what every composer script does,
so a script and a check cannot end up running two builds of one tool.

### Composer on Windows behind a TLS scanner

If `composer install` fails with `curl error 60 ... SSL certificate problem: unable to
get local issuer certificate` while `git` and `curl` keep working, a local antivirus or
proxy is scanning TLS and PHP does not trust its root. Run Composer through
`bin\composer-ca.ps1` instead of a bare `composer`:

```powershell
pwsh -File .\bin\composer-ca.ps1 install
pwsh -File .\bin\composer-ca.ps1 test
```

It rebuilds a CA bundle from Git's `ca-bundle.crt` plus every intercepting root in the
Windows trust store, points Composer at it with `COMPOSER_CAFILE`, and passes the
Windows-only `ext-*` platform requirements to `install` / `update` / `require` /
`remove`. The diagnosis, the manual recipe, and why the two TLS stacks disagree are in
[docs/composer-tls-windows.md](docs/composer-tls-windows.md).

`composer checks` (`bin/checks.php`) runs every applicable check and prints one
summary with one exit code: `php -l` over every file (including `bin/`), an
independent AST parse of the same files, a write-back scan of `src/` and `config/`,
`composer validate --strict`, `check-platform-reqs`, the workflow YAML, a scan of
the records for a sentence boundary that lost its space, PHPStan, Pint and PHPUnit. The schema
check asks about `composer.lock` only when the repository contains one — it is
gitignored here, so a lock a local `composer.json` edit has made stale cannot turn
the gate red over a file no clone has, which is the same question CI answers after
writing its own. It prints only failures by default (`--verbose` streams everything), takes
`--only=syntax,tests` to narrow, reports
a missing tool as a skip rather than crashing on it (`--require-all` turns that
into a failure), and can add `composer audit` with `--audit`.

The write-back scan is the one check whose subject is the shape of the code rather
than the code itself: a class that reads a file and writes that same file back is a
read-modify-write of it, which is how a key nobody knew about disappears without a
diff saying so — a write of a temp file that a `rename()` completes is followed to
the path it really replaces, and a write that merges a read taken at the write site
is the one form that needs no explanation. Every other one is named in the
`STRUCTURE_WRITE_BACKS` register at the top of `bin/checks.php`, with the reason the
copy being replaced is still the truth; a write-back that is not in the register
fails the check, and a register entry the code has left behind fails it too. The
detector is run against fixtures of its own first, so a shape it has stopped
recognising fails the check rather than reporting an empty result — the limitation
of a static scan is written up beside the register.

The records are read as prose as well. A sentence that ends and the next one that
begins with nothing between them is a word the language does not have, and it is what
an edit that eats a space leaves behind:

```
…written by the release.Diffing it is the next sentence…
```

The check reads `README.md`, `RELEASING.md` and every `docs/*.md`, and leaves fenced
blocks alone, because the code these records quote — a PowerShell member, a PHP string
concatenation, a version — is what a sentence rule reports. It needs a capital with a
lowercase letter after it as well, which is why the dotted `production.ERROR` these
records mention is not one of its findings. A finding prints the file, the line and
the join, and the detector runs against fixtures of its own first — the same rule the
write-back scan follows — so a pattern that has stopped matching fails the gate
instead of reporting no fused sentence.

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
`methods.tsv`, `surface.tsv`) the previous release wrote — and applies the policy in
[RELEASING.md](RELEASING.md) — a fix is a patch, a new command or config key is a
minor, a removed or renamed public symbol is a minor while 0.x and a major after
1.0.0. Declaring a smaller bump instead (`--minor`, `--major`,
`--version=X.Y.Z`) stops the release with exit code 1 and names the change that
forbids it, so a breaking change cannot be shipped as a patch. It then promotes
`## Unreleased` to the released heading with a compare link, refreshes the three
inventory files in the same commit, keeps both dev-lane branch aliases
(`dev-main`, `dev-dev`) on the line being developed, and refuses to continue on a
dirty tree, the wrong branch, an existing tag, an empty release section, or a
commit CI has not verified — the tip of the branch has to be on the remote with a
finished, passing workflow run for it, since the release commit itself cannot have
one yet; `--skip-ci` tags anyway and says so in the plan. An empty `## Unreleased`
is the one rail `--weigh --dry-run` is let past: the notes are what a release
publishes, and a plan publishes nothing, so it reports the bump the other three
signals weigh and says in the plan that a real run refuses there. A surface change
has to be accounted for by the notes as well: the notes are the one signal a reader
ever sees, so a new public method filed under `### Fixed` is refused and pointed at
the heading that covers it — the version moves on the surface either way, and the
changelog would otherwise announce a fix — with `--allow-silent-notes` as the escape,
printed in the plan like every other one. A
version may also be a prerelease — `--version=0.0.1-alpha1`, cut from `dev` — which
promotes the notes and stamps the inventory exactly as a release does; a consumer
opts in with `minimum-stability: alpha` or a constraint like `"^0.0.1@alpha"`. The
suffixes it writes are the ones Composer's own parser reads, and the spellings it
cannot read (`0.0.1-dev.1`, `0.0.1-alpha.1`) are refused rather than written.

A dev tag is weighed as the version it *announced* rather than the one it holds:
`0.5.0-alpha1` is a prerelease of `0.5.0`, cut from the branch developing it, so the
rung is measured from the newest release at or below that line — `v0.2.0` — and
`--weigh` answers `0.5.0` rather than stepping past it to `0.5.1`. A promotion that
does not clear what the changes since the tag ask for is still refused, and the step
then comes from that same release. The measurements and the cases are in
[docs/prerelease-promotion.md](docs/prerelease-promotion.md).

[RELEASING.md](RELEASING.md) has the policy, the safety rails and the first-release
walkthrough.

## Changelog

The five defects this package was ported with — unrunnable routing, an unreadable
formula key, disagreeing factor defaults, a broken `db:probe-replicas`, a
hardcoded local-store prefix and silent degradation — are documented with their
fixes in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).

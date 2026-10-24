# A Linux PHP, fetched on Windows and run from WSL

`scripts/windows/wsl-linux-php.ps1` builds a Linux PHP test runtime without installing anything
into the WSL distribution: the download happens on the Windows side, into the machine's temp
directory, and the run happens through the `/mnt/<drive>/…` mount of that same directory.

**Measured on 2026-10-24:** `tests/Unit` runs under it — `64 passed (295 assertions)`, exit 0,
twice (60.65 s and 75.08 s) — and so does `php artisan --version` (`Laravel Framework 12.42.0`). The two
`ConcurrencyHelpers` probes recorded in
[0007](investigations/0007-which-octane-concurrently-calls-exist-and-what-runs-without.md) are
the reason it exists.

```powershell
# create it (idempotent - reuses what is already there)
pwsh -NoProfile -File .\scripts\windows\wsl-linux-php.ps1

# judge it, downloading nothing; exit 1 when it cannot run what this repository needs
pwsh -NoProfile -File .\scripts\windows\wsl-linux-php.ps1 -Check

# use it: the line runs inside WSL, in the application root, with the Linux PHP first on PATH
pwsh -NoProfile -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php vendor/bin/pest tests/Unit'
```

## Why the download happens on the Windows side

This was measured, not chosen:

| Question | Answer on this machine |
|---|---|
| Is there a distro? | yes — Debian GNU/Linux 13 (trixie), the WSL default, kernel `6.18.54.1-microsoft-standard-WSL2` |
| Does it have PHP? | no — no `php` on PATH, and no package could be installed anyway |
| Does it have the network? | no — `curl` to `github.com` and to `deb.debian.org` both time out |
| Does Windows have the network? | yes |
| Can the distro read the Windows temp directory? | yes, at its own mount path, which `wslpath` answers |

So the file is fetched where the network is and read where the interpreter is. Nothing is
installed into the distribution, no package manager runs, no root is needed, and deleting the
temp directory undoes the whole thing.

## What it puts where

| Path | What it is |
|---|---|
| `%TEMP%\lpr\linux-php\php` | the interpreter, about 12 MB, executable through the mount |
| `%TEMP%\lpr\linux-php\php-8.4.23-cli-linux-x86_64.tar.gz` | the download it was extracted from, kept so a re-run needs no network |
| `%TEMP%\lpr\linux-php\SOURCE.txt` | url, sha256, byte count, preset, version, arch and the moment it was fetched |

The build is static-php-cli's prebuilt CLI — a single self-contained file, so there is no
extension directory to mount, no `php.ini` to point anywhere, and no library version to match to
the distribution's `glibc`. `php -v` reports `PHP 8.4.23 (cli) … Built by static-php-cli 2.8.6`,
64-bit, 46 extensions.

**The two presets differ, and the difference is printed rather than remembered.** `common`
(12 375 301 bytes, the default) carries the extensions the code paths need; `bulk`
(31 024 351 bytes, `-Preset bulk`) adds five that `common` omits — `brotli`, `lz4`, `memcached`,
`protobuf`, `zstd`. None of the five is declared in the manifest's `require` today: its one `ext-*`
row, `ext-redis`, sits under `suggest`, because the store that needs it is one an installation opts
into. So the `manifest ext` line below reports on an empty set and says `(0)` — the five are carried
for the day a code path reaches for one, not because a run is failing without them. `common` is the
default because it is what the probes were measured on and what a suite actually needs.

**No checksum is published beside these builds**, so the script records the sha256 of what it
downloaded in `SOURCE.txt` and nothing more. That is enough to tell a same-version rebuild from
the artifact this machine measured — both downloads on 2026-10-24 hashed to
`1aeed5bc7967977ca5b1da7163acd91bf9ba3ac56037045d4e91ee2ff2712bb7` — and it is not a
signature, which is a claim the source does not make.

## What `-Check` judges

| Line | What it decides |
|---|---|
| `distro` | which distribution answered, and its kernel |
| `mount` | the Windows directory and the path WSL resolves it to, asked of `wslpath` rather than assembled |
| `php` | the version banner, the extension count, and 64-bit — the one platform fact `vendor/composer/platform_check.php` enforces |
| `app root` | that the mounted application root resolves, so a run cannot be aimed at the wrong tree |
| `runtime ext` | the 16 the code paths need: `pdo_sqlite`/`sqlite3` and `pdo_pgsql`/`pgsql` (the connections `phpunit.xml` can select), `pcntl`/`posix`/`sockets` (`spatie/fork`, Octane's `ServerProcessInspector`), and `openssl`, `fileinfo`, `ctype`, `session`, `phar`, `filter`, `iconv`, `dom`, `simplexml` for Laravel and Composer's own bootstrap |
| `manifest ext` | the `ext-*` entries `composer.json` **requires**, read from the manifest rather than listed here — absent ones are a **warning**, not a failure, because `phpunit.xml` overrides the cache and session drivers. It reads `require` and not `suggest`, so it is empty on a manifest whose only `ext-*` row is optional, and it prints `(0)` rather than pretending to have judged something |
| `version` | the running version against `composer.json`'s `require.php` constraint, not against the patch build the script pins |

Exit `0` is usable, `1` is `-Check` with a finding, `2` is an environment that cannot answer at
all (no `wsl.exe`, no such distribution, no network on the Windows side).

## Using it

`-Run` exports the mount ahead of `PATH` and `cd`s to the application root, so the ordinary
commands work unchanged:

```powershell
pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php -v'
pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php vendor/bin/pest tests/Unit'
pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php vendor/bin/pint app/Helpers/ConcurrencyHelpers.php --test'
```

Exit status is the command's own, so a failing suite fails the wrapper. Output is streamed, not
captured — a suite that writes to `stderr` still reaches the terminal.

Other switches: `-Distro` for a distribution other than the default, `-InstallDir` for a
directory other than `%TEMP%\lpr\linux-php`, `-Force` to download again, `-Arch aarch64` on an
ARM host, and `-PhpVersion` to move the pin.

An ad-hoc boot that touches the cache needs the drivers `phpunit.xml` normally overrides,
because `.env` selects memcached and a database session store that this build does not carry:

```powershell
pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan tinker --execute="echo 1;"'
```

## Running the provided app, not this package

`external/laravel-site` is the site's source carried into this repository by
`scripts/windows/build-external-laravel-site.ps1`, and it is the app the package is exercised with.
The wrapper runs it unchanged — the same mount, the same Linux PHP — because both roots it derives
are the repository's, and the app is one `cd` below them:

```powershell
pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'cd external/laravel-site && php artisan --version'
```

Three things are true of that app and not of this package:

| | |
|---|---|
| it has no dependency manifest | the copy carries eleven names, and `composer.json` and `composer.lock` are not among them. Both are copied from the site checkout `scripts/windows/site-path.local.cmd` names, this package is added as a `path` repository so the app runs the working tree, and `composer install` runs **on the Windows side** — the distribution has no egress, and the packages are PHP source read through the mount |
| it has no environment file | `.env` is created beside it from the site's `.env.prod` — a plaintext secret, so it is named and not read — with the rows for a local run overriding it: the pooler on 5433, the pool's own database name rather than a shard's, `CACHE_STORE` and `SESSION_DRIVER` of `array`, and the `SWRR_*` rows a flip needs |
| its scheduled work is a cron's | the distribution has no crontab, so the schedule is run with `php artisan schedule:work`: the entries fire every minute for as long as it is up, which is what a scheduled `db:pgcat-flip` needs |

A local flip test leans on a `reader_windows` entry a few minutes out, and three rows decide whether
it ever applies. `timezone` is the zone the times are read in. `reader_days` is ISO 1–7, and a window
on a day that list omits is never applied — the shipped list is Monday to Friday, so a Saturday test
has to widen it. `flip_window_seconds` bounds the per-minute entry, so a boundary that is to be acted
on at boot has to fall inside it, within eight minutes of the boot stamp.

## What it does not give you

- **No Swoole.** `octaneServerIsRunning()` therefore answers `false` here for the same reason it
  does on Windows: the predicate needs the extension before it can ask about a server. The
  `true` branch stays unmeasured.
- **No `composer install` inside the distribution.** The distro has no network, and the
  `vendor/` it reads is the Windows checkout's, mounted. That is fine — the installed packages
  are PHP source — but it does mean a dependency change is made on the Windows side, with the
  Composer wrapper, and only then run here. The app in `external/laravel-site` is the clearest case:
  it ships without a manifest at all, so its `composer install` is a step of its own before any
  `artisan` line is worth running.
- **No `ext-memcached`, `ext-brotli`, `ext-lz4`, `ext-protobuf`, `ext-zstd`** in the default
  preset, as above.
- **Nothing persists in the distribution.** The whole environment is the temp directory; `wsl.exe` itself is the only thing this depends on, and the check says so when it is missing.

## Reproducing it by hand

The script is a convenience, not the definition — these are the steps it takes, in the order it
takes them:

```powershell
# 1. download on the Windows side: this is the half the distribution cannot do
$url = 'https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz'
$tar = Join-Path $env:TEMP 'php-8.4.23-cli-linux-x86_64.tar.gz'
Invoke-WebRequest -Uri $url -OutFile $tar

# 2. let the distribution say where that file is - do not assemble the mount path by hand
$linuxTar = (wsl.exe -e bash -lc "wslpath -a '$tar'").Trim()

# 3. extract and prove it: the archive holds one file, `php`
wsl.exe -e bash -lc "mkdir -p /tmp/linux-php && tar -xzf '$linuxTar' -C /tmp/linux-php php && chmod +x /tmp/linux-php/php && /tmp/linux-php/php -v && /tmp/linux-php/php -m"

# 4. run the application through it
$linuxApp = (wsl.exe -e bash -lc "wslpath -a '$(Resolve-Path .).Path'").Trim()
wsl.exe -e bash -lc "cd '$linuxApp' && /tmp/linux-php/php vendor/bin/pest tests/Unit"
```

Two details in there are load-bearing. The mount prefix comes from `wslpath`, because the same
directory sits under a different single-letter mount on the next machine, and a spelled-out
prefix is the one thing that would silently point at nothing. And the extraction is done **by the
distribution's tar**, not by Windows' `tar.exe`: on a shell that puts Git's `usr/bin` first,
`tar.exe` is GNU tar, which reads a drive-lettered path as a remote host and fails with
`Cannot connect to C:`.

## What was measured with it

| Run | Result |
|---|---|
| `php -v` through the wrapper | `PHP 8.4.23 (cli)`, `static-php-cli 2.8.6`, 46 extensions, 64-bit |
| `php artisan --version` | `Laravel Framework 12.42.0` — the application boots under Linux |
| `php vendor/bin/pest tests/Unit` | `64 passed (295 assertions)`, exit 0, twice: 60.65 s and 75.08 s |
| fork transport probe (0007) | 20 checks, 3 runs: 3 × 500 ms tasks in 0.502 s, distinct child pids, `TaskException` parity with Octane |
| route-payload probe (0007) | 31 checks, 3 runs: a 100-route chunk of 375 792 bytes crossed in 0.241 s, digest-equal |
| `-Check` on an empty `-InstallDir` | exit 1, `nothing usable … run this script with -Setup` |
| `-Setup` on an empty `-InstallDir` | 11.8 MB in 44.5 s, extracted, verified, `SOURCE.txt` written |
| `-Setup` a second time | `reusing …`, no download |

The probes themselves live outside the repository, in `%TEMP%\lpr\linux\`, and 0007 names them
there; each takes the application root as an optional first argument and defaults to the directory
it was started in, so the wrapper's `cd` to the application root is all one needs — and the wrapper
exists so that a later session does not have to rediscover how to run them.

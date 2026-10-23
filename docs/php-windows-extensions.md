# The Windows extension builds these scripts fetch

Two scripts, one per PHP line, both beside this record in `scripts/`:

| script | line | archives go to | loadables go to |
|---|---|---|---|
| `scripts/download-php85-extensions.ps1` | PHP 8.5 | `ext/85/downloads/` | `ext/85/` |
| `scripts/download-php84-extensions.ps1` | PHP 8.4 | `ext/84/downloads/` | `ext/84/` |

Each fetches its line's pinned archives and unpacks the parts PHP loads. They are independent —
neither script reads or writes the other's folders — so the order they run in does not matter, and
running one twice never touches the other.

## The pin is a variable block, not eight URLs

The four parts every archive's name carries are declared once at the top of each script:

| variable | value | what it decides |
|---|---|---|
| `$phpVersion` | `8.5` or `8.4` | the PHP line the build is for — the one part the two scripts differ in |
| `$threadness` | `ts` | thread-safe, for the thread-safe `php.exe` — `nts` is the other build |
| `$buildEnv` | `vs17` | the compiler the build was made with |
| `$arch` | `x64` | the architecture |

and one version per library is declared below them. Every URL is assembled from those by
substitution, so an archive cannot drift from the version it is pinned to: raising a line is one
edit, not eight, and a row still naming the old value is impossible.

The template for PECL's Windows builds is

```
https://downloads.php.net/~windows/pecl/releases/{library}/{version}/php_{library}-{version}-{php}-{ts}-{vs}-{arch}.zip
```

Two things about the names are the builds' rather than ours, and each is commented where it sits:

- **`lz4` is not a PECL build.** It comes from the `php-ext-lz4` project's own GitHub release —
  a tagged release rather than a foldered one — and that project spells the architecture `x86_64`,
  not `x64`. Its row carries a template of its own, with the shared parts substituted as everywhere
  else.
- **The file names carry the whole pin**, which is what lets both lines' archives sit in one
  `downloads` folder without colliding.

## The libraries

| library | PHP 8.5 | PHP 8.4 | published by |
|---|---|---|---|
| `brotli` | `0.21.0` | `0.21.0` | PECL `windows` |
| `igbinary` | `3.2.16` | `3.2.16` | PECL `windows` |
| `lz4` | `0.8.0` | `0.8.0` | `kjdev/php-ext-lz4`, GitHub |
| `memcached` | `3.4.0` | `3.4.0` | PECL `windows` |
| `pcov` | `1.0.12` | `1.0.12` | PECL `windows` |
| `protobuf` | `5.36.2` | `5.36.2` | PECL `windows` |
| `redis` | `6.3.0` | `6.2.0` | PECL `windows` |
| `zstd` | `0.18.0` | `0.18.0` | PECL `windows` |

`redis` is the only library whose two lines are pinned apart, and it is worth knowing why: the 8.4
and 8.5 archives hold **different binaries under the same name** (`php_redis.dll`), so the two lines
must never share one loadables folder. That is what the per-line folders are for, and it is the
reason the defaults are not one `ext/`.

## Running them

```powershell
pwsh -NoProfile -File scripts/download-php85-extensions.ps1
pwsh -NoProfile -File scripts/download-php84-extensions.ps1
```

| flag | for |
|---|---|
| `-Force` | download the archives again even where they are already there |
| `-Into` | where the archives go — defaults to the line's `downloads` folder |
| `-ExtractInto` | where the `.dll` and `.ini` members are unpacked — defaults to the line's folder |

A value given on the command line is taken from the package root when it is relative, so
`-ExtractInto ext/85` and the full path mean one folder. Both destinations are resolved from the
script's own file — `$PSScriptRoot`, with the invocation's own command path for a dot-sourced run — so
they are the same two folders however the script was started and wherever the terminal is standing.
The archives are kept: an archive already there is left alone unless `-Force` is given, so a re-run
downloads nothing, while the extraction runs every time — a `.dll` deleted by hand is put back by the
next run.

## What is unpacked, and the two rules about names

Only the members PHP loads, taken by member rather than by path, so an archive's own directory layout
is discarded rather than reproduced:

- **the extension's own library** — the member whose name begins `php_` — is written as
  `php_<library>.dll`, whatever the archive called it. A build is free to name the copy inside its
  archive after the archive: `php-ext-lz4` ships `php_lz4-0.8.0-8.5-ts-vs17-x86_64.dll`, which no
  `extension=lz4` line can find, and the rename is what makes it findable. The run says so when it
  happens.
- **any other `.dll`** is a library the extension links against — `memcached`'s `libmemcached.dll`,
  `brotli`'s `brotlienc.dll` — and it keeps the name the build gave it. Renaming those would be the
  damaging mistake: several would land on one name, and the extension loads them *by* theirs.
- **every `.ini`** is kept as the build named it. They are templates to copy settings from, not files
  PHP reads where they sit.

Everything else an archive carries — the `.pdb` symbols, licenses, READMEs — is left in the archive.
It belongs to the build rather than to an installation.

## Loading them

**`php.ini` has to point at the line's folder.** Each line's `extension_dir` is its own:

```ini
; the 8.5 build
extension_dir = "…/ext/85"

; the 8.4 build
extension_dir = "…/ext/84"
```

Until that is set PHP looks in `ext/`, which is empty by design, and every `extension=` line fails at
startup — the DLL is present and unloadable, which reads like a missing file.

Two further facts belong to the application rather than to these scripts, and both are the sort of
thing that fails silently if they are wrong:

- **`redis` and `memcached` are two clients for the same store.** Only one belongs in `php.ini`.
- **`lz4`, `zstd` and `protobuf` are usually dependencies of something else** rather than extensions
  enabled on their own, and a DLL that links against a runtime which is not there loads as a failure
  at startup. The `lz4` archive in particular carries no `liblz4` runtime.

## What these scripts deliberately do not do

- **They do not enable anything.** Unpacking a DLL and loading it are two acts, and the second is the
  installation's — an `extension=` line, and an `extension_dir` that points at the folder.
- **They do not extract whole archives.** `Expand-Archive` cannot filter, so the `.pdb` files and the
  licenses would land beside the extensions.
- **They do not name a disk, an account or a share.** Both destinations come from the script's own
  file, which is what `tests/Unit/Support/MachinePathsTest.php` holds every committed file to, and
  the folders themselves are gitignored with `ext/` — these are artefacts fetched from somewhere
  else, not content this repository ships.
- **They do not push or pop a directory for their own sake.** The scripts do `Push-Location` to their
  own folder so that a path added to them later resolves from there, and `Pop-Location` at the end;
  neither has any effect on where the archives or the loadables go, because both destinations are
  already absolute.

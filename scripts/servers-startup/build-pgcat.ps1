#requires -Version 5.1
<#
    Build the local pgcat pooler on Windows, with the workarounds this platform
    needs and nothing else.

    A plain `cargo build` does not finish here, and the obstacles are not pgcat's
    own logic — docs/local-postgres-and-pgcat.md, section 2.5.1, is the record:

      1. ring 0.16.20 and rustls-webpki 0.100 and 0.101 embed `.der` files with
         include_bytes!, and this machine refuses to read that extension whatever
         the file holds (Access is denied, os error 5). Each crate is extracted
         from its `.crate` archive into a patch directory outside the registry,
         its `.der` files are renamed `.bin` with every include path followed,
         and one crate-level allow is added so a newer rustc's lints are not
         errors. Cargo is then pointed at those copies with `--config patch…`.
      2. pgcat's own source carries two `#[cfg(unix)]` guards for the same
         reason; they are in the checkout, not here.

    The patch directories are reused between runs: delete PGCAT_CRATES (default
    %LOCALAPPDATA%\pgcat-patched-crates) to have them made again.

    The build rewrites the checkout's Cargo.lock, because the three crates now
    resolve to paths rather than to registry entries. That is expected; the file
    is restored by `git checkout -- Cargo.lock` when the patch flags stop being
    used.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$machineFile = Join-Path $here 'servers.local.cmd'

function Exit-With([int] $code, [string] $message) {
    [Console]::Error.WriteLine($message)
    exit $code
}

if (-not (Test-Path -LiteralPath $machineFile)) {
    Exit-With 2 "servers.local.cmd is missing from `"$here`". Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT."
}

# The machine file is a cmd script, so it is read as the `set "NAME=VALUE"` lines
# it is made of rather than executed: this script must not start or stop anything.
$settings = @{}

foreach ($line in Get-Content -LiteralPath $machineFile) {
    if ($line -match '^\s*set\s+"([A-Za-z_][A-Za-z0-9_]*)=(.*)"\s*$') {
        $settings[$Matches[1]] = $Matches[2]
    }
}

$pgcatRoot = $settings['PGCAT_ROOT']

if (-not $pgcatRoot -or -not (Test-Path -LiteralPath (Join-Path $pgcatRoot 'Cargo.toml'))) {
    Exit-With 2 "PGCAT_ROOT is not set to a pgcat checkout (a directory holding Cargo.toml): `"$pgcatRoot`"."
}

$cratesDir = if ($settings['PGCAT_CRATES']) { $settings['PGCAT_CRATES'] } else { Join-Path $env:LOCALAPPDATA 'pgcat-patched-crates' }

if (-not (Get-Command cargo -ErrorAction SilentlyContinue)) {
    Exit-With 2 'cargo is not on this shell''s PATH; install Rust, or add its bin directory to PATH.'
}

Write-Host "Pooler checkout: $pgcatRoot"
Write-Host "Patched crates: $cratesDir"

# One entry per crate that reads a `.der` file: the archive name, the directory it
# is unpacked into, and the two patches each copy needs.
$crates = @(
    @{ File = 'ring-0.16.20.crate';           Dir = 'ring-0.16.20' },
    @{ File = 'rustls-webpki-0.100.2.crate';  Dir = 'rustls-webpki-0.100.2' },
    @{ File = 'rustls-webpki-0.101.1.crate';  Dir = 'rustls-webpki-0.101.1' }
)

function Find-CrateArchive([string] $file) {
    $cache = Join-Path $env:USERPROFILE '.cargo\registry\cache'

    if (-not (Test-Path -LiteralPath $cache)) {
        return $null
    }

    $found = Get-ChildItem -LiteralPath $cache -Directory |
        ForEach-Object { Join-Path $_.FullName $file } |
        Where-Object { Test-Path -LiteralPath $_ } |
        Select-Object -First 1

    return $found
}

function Repair-Crate([string] $dir) {
    # The extension is the problem, not the contents, so each file is renamed and
    # every include path that names it is followed.
    #
    # A name is left alone when a file of its new name is already there: ring's
    # tests ship both ed25519_test_public_key.der and ed25519_test_public_key.bin,
    # two different keys, and renaming one over the other would silently change what
    # a test reads. Only the renamed ones have their include rewritten.
    $renamed = @()

    Get-ChildItem -LiteralPath $dir -Recurse -File -Filter '*.der' | ForEach-Object {
        $bin = [System.IO.Path]::ChangeExtension($_.FullName, '.bin')

        if (Test-Path -LiteralPath $bin) {
            Write-Host ("  kept {0}: a .bin of that name is already there" -f $_.Name)
            return
        }

        Rename-Item -LiteralPath $_.FullName -NewName ([System.IO.Path]::ChangeExtension($_.Name, '.bin'))
        $renamed += [System.IO.Path]::GetFileNameWithoutExtension($_.Name)
    }

    Get-ChildItem -LiteralPath $dir -Recurse -File -Filter '*.rs' | ForEach-Object {
        $text = Get-Content -LiteralPath $_.FullName -Raw
        $edited = $text

        foreach ($stem in $renamed) {
            $edited = $edited.Replace($stem + '.der"', $stem + '.bin"')
        }

        if ($edited -ne $text) {
            Set-Content -LiteralPath $_.FullName -Value $edited -NoNewline
        }
    }

    # A newer rustc turns lints these crates deny into errors, and a deny written
    # after an allow wins — so the deny itself is what is relaxed, in place, rather
    # than an allow inserted beside it.
    $lib = Join-Path $dir 'src\lib.rs'

    if (Test-Path -LiteralPath $lib) {
        $text = Get-Content -LiteralPath $lib -Raw
        $relaxed = $text.Replace('#![deny(warnings', '#![allow(warnings').Replace('unused_qualifications, ', '')

        if ($relaxed -ne $text) {
            Set-Content -LiteralPath $lib -Value $relaxed -NoNewline
        }
    }

    Write-Host ("  {0}: {1} `.der` file(s) renamed" -f (Split-Path -Leaf $dir), $renamed.Count)
}

New-Item -ItemType Directory -Path $cratesDir -Force | Out-Null

$patches = New-Object System.Collections.Generic.List[string]

foreach ($crate in $crates) {
    $target = Join-Path $cratesDir $crate.Dir
    $done = Join-Path $target '.patched'

    if (Test-Path -LiteralPath $done) {
        Write-Host "  $($crate.Dir): patched already"
        continue
    }

    # An unpacked copy with no marker is half-done — an interrupted run — so it is
    # unpacked again rather than repaired on top of its own edits.
    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Recurse -Force
    }

    if (-not (Test-Path -LiteralPath (Join-Path $target 'Cargo.toml'))) {
        $archive = Find-CrateArchive $crate.File

        if (-not $archive) {
            Exit-With 1 "No $($crate.File) in the cargo cache. Run `"cargo fetch`" once in the checkout, then run this again."
        }

        Write-Host "Unpacking $($crate.File)..."

        # Windows' own tar, named in full: a shell that has a GNU tar earlier on PATH
        # reads `C:` as a remote host and unpacks nothing.
        $tar = Join-Path $env:SystemRoot 'System32\tar.exe'

        if (-not (Test-Path -LiteralPath $tar)) {
            $tar = 'tar'
        }

        & $tar -xzf $archive -C $cratesDir

        if ($LASTEXITCODE -ne 0) {
            Exit-With 1 "tar could not unpack $archive."
        }
    }

    Repair-Crate $target
    New-Item -ItemType File -Path $done -Force | Out-Null
}

# A forward-slash path: backslashes would be escape sequences inside a TOML string.
function As-TomlPath([string] $path) {
    return ($path -replace '\\', '/')
}

$patches.Add('patch.crates-io.ring.path="{0}"' -f (As-TomlPath (Join-Path $cratesDir 'ring-0.16.20')))
$patches.Add('patch.crates-io.webpki100.path="{0}"' -f (As-TomlPath (Join-Path $cratesDir 'rustls-webpki-0.100.2')))
$patches.Add('patch.crates-io.webpki100.package="rustls-webpki"')
$patches.Add('patch.crates-io.webpki101.path="{0}"' -f (As-TomlPath (Join-Path $cratesDir 'rustls-webpki-0.101.1')))
$patches.Add('patch.crates-io.webpki101.package="rustls-webpki"')

$arguments = @('build')

foreach ($patch in $patches) {
    $arguments += @('--config', $patch)
}

# cargo's last step replaces the binary, and Windows will not let it while a pooler
# started from that file is running. Failing here names the reason; failing inside
# cargo reads as `Access is denied` with no clue in it.
$binary = Join-Path $pgcatRoot 'target\debug\pgcat.exe'

if (Test-Path -LiteralPath $binary) {
    try {
        $handle = [System.IO.File]::Open($binary, 'Open', 'Write', 'None')
        $handle.Close()
    } catch {
        Exit-With 1 "The pooler is running from `"$binary`" and Windows will not replace a file in use. Close its window, then run this again."
    }
}

Write-Host "cargo $($arguments -join ' ')"

Push-Location $pgcatRoot

try {
    & cargo @arguments
    $status = $LASTEXITCODE
} finally {
    Pop-Location
}

if ($status -ne 0) {
    Exit-With 1 "cargo build exited $status."
}

$binary = Join-Path $pgcatRoot 'target\debug\pgcat.exe'

if (-not (Test-Path -LiteralPath $binary)) {
    Exit-With 1 "cargo succeeded but there is no binary at $binary."
}

Write-Host "Built $binary"
exit 0

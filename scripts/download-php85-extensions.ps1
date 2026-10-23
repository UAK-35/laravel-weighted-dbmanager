#Requires -Version 7
<#
.SYNOPSIS
    Download the pinned PHP extension builds for Windows, and unpack what PHP actually loads.

.DESCRIPTION
    Eight extension builds for Windows: seven from PECL's `windows` builds and one from the
    `php-ext-lz4` project's own GitHub release.

    The pin is the variables at the top of this file, and it is one declaration each rather than a URL
    copied eight times: the PHP line, the thread safety, the build environment and the architecture
    once, and a version per library below them. Every URL is assembled from those, so an archive
    cannot drift from the version it is pinned to — raising the PHP build from `8.5` to `8.6` is one
    edit, not eight, and a row that still says `8.5` is impossible.

    The file's own name carries the line it is pinned to — `download-php85-extensions` — so a build
    for another line is a rename as well as a variable: a script still called `php85` while
    `$phpVersion` says `8.6` is the drift this arrangement exists to make impossible.

    The archives go to `ext/85/downloads/` and are kept there, so a re-run is a re-download of nothing;
    from each one only the members PHP loads are unpacked into `ext/85/` — the extension's own library,
    written as `php_<name>.dll` whatever the archive called it, and any `.ini` beside it. Only the
    extension's library is renamed: a `.dll` the build names some other way is a library the extension
    links against, and it is loaded by the name it has.

    Both directories are gitignored with `ext/`: these are artefacts fetched from somewhere else, not
    content this repository ships. An archive already downloaded is left alone unless `-Force` is
    given, while the extraction runs every time — so a `.dll` deleted by hand, or one left from an
    older pin, is put back or replaced by the run that follows.

.PARAMETER Into
    Where the archives are downloaded. Defaults to `ext/85/downloads/` beside the script's parent
    directory.

.PARAMETER ExtractInto
    Where the `.dll` and `.ini` members are unpacked. Defaults to `ext/85/` beside the script's parent
    directory — the folder PHP's `extension_dir` points at.

.PARAMETER Force
    Download again even where the archive is already there.

.EXAMPLE
    pwsh scripts/download-php85-extensions.ps1
    pwsh scripts/download-php85-extensions.ps1 -Force
#>
[CmdletBinding()]
param(
    [string] $Into,
    [string] $ExtractInto,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

# ── The pin ──────────────────────────────────────────────────────────────────────────────────────
#
# The four parts every archive's name carries. A build of another PHP line, without thread safety
# (`nts`), from another toolchain (`vs16`, `vs18`) or for another architecture is this block.
$phpVersion = '8.5'
$threadness = 'ts'
$buildEnv = 'vs17'
$arch = 'x64'

# ...and the fifth part, which belongs to one library each. A library that is not pinned here is not
# downloaded, and one that is pinned here and nowhere else is a row below.
$brotliVersion = '0.21.0'
$igbinaryVersion = '3.2.16'
$lz4Version = '0.8.0'
$memcachedVersion = '3.4.0'
$pcovVersion = '1.0.12'
$protobufVersion = '5.36.2'
$redisVersion = '6.3.0'
$zstdVersion = '0.18.0'

# PECL's Windows builds: one directory per library, one archive per pin, and the archive's name is the
# library's name followed by all five parts.
$peclWindows = 'https://downloads.php.net/~windows/pecl/releases/{0}/{1}/php_{0}-{1}-{2}-{3}-{4}-{5}.zip'

$extensions = @(
    [pscustomobject] @{ Name = 'brotli'; Version = $brotliVersion; Url = $peclWindows -f 'brotli', $brotliVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'igbinary'; Version = $igbinaryVersion; Url = $peclWindows -f 'igbinary', $igbinaryVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'memcached'; Version = $memcachedVersion; Url = $peclWindows -f 'memcached', $memcachedVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'pcov'; Version = $pcovVersion; Url = $peclWindows -f 'pcov', $pcovVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'protobuf'; Version = $protobufVersion; Url = $peclWindows -f 'protobuf', $protobufVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'redis'; Version = $redisVersion; Url = $peclWindows -f 'redis', $redisVersion, $phpVersion, $threadness, $buildEnv, $arch },
    [pscustomobject] @{ Name = 'zstd'; Version = $zstdVersion; Url = $peclWindows -f 'zstd', $zstdVersion, $phpVersion, $threadness, $buildEnv, $arch },

    # The one row that is not a PECL build, and it differs in three ways that are the build's rather
    # than this script's: it is published by the project from GitHub, the release is tagged rather
    # than foldered, and the architecture is spelled `x86_64` there. So it carries a template of its
    # own — the shared parts substituted as everywhere else.
    [pscustomobject] @{ Name = 'lz4'; Version = $lz4Version; Url = 'https://github.com/kjdev/php-ext-lz4/releases/download/{0}/php_lz4-{0}-{1}-{2}-{3}-x86_64.zip' -f $lz4Version, $phpVersion, $threadness, $buildEnv }
)

# Where this script's own file is, and the package root above it — the anchor for both destinations,
# so they are the same two folders however the script was started and wherever the terminal is
# standing. `$PSScriptRoot` is set when a script runs from a file — by `-File`, or invoked by path in
# a session — and `$MyInvocation.MyCommand.Path` still answers when it is dot-sourced. With neither,
# there is no file to take the folders from, and a destination built from the current directory would
# be right only by accident.
$scriptDirectory = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }

if (-not $scriptDirectory) {
    throw 'Nothing names this script''s own file, so its ext folders cannot be located. Run it as a file — pwsh -File scripts/download-php85-extensions.ps1 — rather than as a piped or pasted command.'
}

$package = Split-Path -Parent (Resolve-Path -LiteralPath $scriptDirectory).Path

# Every destination is absolute: a value given on the command line is taken from the package root when
# it is relative, and normalised when it is not — so `-ExtractInto ext/85` and a full path mean the
# same folder, and neither depends on where the terminal is standing.
function Get-Destination([string] $Value, [string] $Base, [string] $Fallback) {
    if (-not $Value) {
        return $Fallback
    }

    return [System.IO.Path]::GetFullPath($(if ([System.IO.Path]::IsPathRooted($Value)) { $Value } else { Join-Path $Base $Value }))
}

$archiveDir = Get-Destination $Into $package (Join-Path (Join-Path (Join-Path $package 'ext') '85') 'downloads')
$loadDir = Get-Destination $ExtractInto $package (Join-Path (Join-Path $package 'ext') '85')

# Work from the script's own directory for the rest of the run. Every destination above is absolute
# already, so this changes nothing about where the archives and the loadables go; what it does is make
# any *other* relative path — a command an editor adds later, a tool that writes beside its caller —
# resolve from this file's folder rather than from wherever the terminal was standing when it started.
Push-Location -Path $scriptDirectory

foreach ($directory in @($archiveDir, $loadDir)) {
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
}

$downloaded = 0
$kept = 0

foreach ($extension in $extensions) {
    $file = Join-Path $archiveDir (Split-Path -Leaf $extension.Url)

    if ((Test-Path -LiteralPath $file) -and -not $Force) {
        Write-Host ('kept      {0}' -f (Split-Path -Leaf $file))
        $kept++

        continue
    }

    Write-Host ('download  {0}  {1} {2}' -f (Split-Path -Leaf $file), $extension.Name, $extension.Version)

    try {
        Invoke-WebRequest -Uri $extension.Url -OutFile $file -UseBasicParsing
        $downloaded++
    } catch {
        Write-Error ('{0} could not be fetched from {1}: {2}' -f (Split-Path -Leaf $file), $extension.Url, $_.Exception.Message)
    }
}

# Only the members PHP loads, taken by name rather than by path: an archive's own directory layout is
# the build's business, and what an installation needs is the file, not the tree around it.
#
# The extension's own library is written under the name PHP looks for — `php_<name>.dll` — because a
# build is free to name the copy *inside its archive* after the archive: `php-ext-lz4` ships
# `php_lz4-0.8.0-8.5-ts-vs17-x86_64.dll`, which no `extension=lz4` line can find. Any other `.dll` is
# a library the extension links against (`memcached`'s `libmemcached.dll`, `brotli`'s
# `brotlienc.dll`), and it keeps the name the build gave it — the extension loads it *by* that name.
$unpacked = 0

foreach ($extension in $extensions) {
    $archive = Join-Path $archiveDir (Split-Path -Leaf $extension.Url)

    if (-not (Test-Path -LiteralPath $archive)) {
        Write-Warning ('{0} is not in {1}, so nothing was unpacked from it' -f (Split-Path -Leaf $archive), $archiveDir)

        continue
    }

    $zip = [System.IO.Compression.ZipFile]::OpenRead($archive)

    try {
        foreach ($entry in $zip.Entries) {
            if ($entry.Name -match '^php_.*\.dll$') {
                $name = 'php_{0}.dll' -f $extension.Name
            } elseif ($entry.Name -match '\.(dll|ini)$') {
                $name = $entry.Name
            } else {
                continue
            }

            $target = Join-Path $loadDir $name
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $true)

            $note = if ($name -eq $entry.Name) { '' } else { '  as ' + $entry.Name }

            Write-Host ('unpack    {0}{1}  from {2}' -f $name, $note, (Split-Path -Leaf $archive))
            $unpacked++
        }
    } finally {
        $zip.Dispose()
    }
}

$present = Get-ChildItem -Path $loadDir -File | Where-Object { $_.Extension -in '.dll', '.ini' } | Sort-Object Name

Write-Host ''
Write-Host ('{0} downloaded, {1} kept in {2}' -f $downloaded, $kept, $archiveDir)
Write-Host ('{0} member(s) unpacked — {1} file(s) for PHP to load in {2}' -f $unpacked, $present.Count, $loadDir)
$present | ForEach-Object { Write-Host ('  {0,-42} {1,10:N0} bytes' -f $_.Name, $_.Length) }

Pop-Location

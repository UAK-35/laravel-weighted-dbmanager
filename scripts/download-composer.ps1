#Requires -Version 7
<#
.SYNOPSIS
    Download the latest composer.phar, and verify it against the checksum Composer publishes with it.

.DESCRIPTION
    Two live reads and no constant. The release is resolved from `https://getcomposer.org/versions`
    — the catalogue Composer publishes — and then both the phar and its checksum are taken from that
    release's own directory, so the hash is the one that belongs to the bytes being fetched rather
    than a value written here that can go stale or be edited.

        https://getcomposer.org/download/<version>/composer.phar
        https://getcomposer.org/download/<version>/composer.phar.sha256sum

    That is why this is not the installer route `scripts/download-composer.bat` takes. The installer
    is a small file whose own sha384 has to be written into the script — a constant that is correct
    on the day it is pasted and wrong the moment Composer republishes — while the phar route reads
    its checksum from the release every time.

    The bytes are verified *before* the file is installed: the download lands beside the target and
    is moved over it only once its sha256 matches the published one, so a truncated or tampered
    fetch never replaces a working `composer.phar`. A mismatch removes the download and fails.

    A copy already in place whose hash matches is left alone, which is what makes a re-run a check
    rather than a download. The phar is gitignored in this repository, so what this fetches is a
    tool for the machine rather than content a commit carries.

.PARAMETER Into
    The folder to install into. Defaults to `external/` beside the package root — a gitignored folder
    of its own, so a downloaded tool does not sit among the extension builds.

.PARAMETER Version
    Fetch exactly this release instead of resolving the latest stable one. Useful for pinning a
    build, and for asking for a version whose checksum the catalogue no longer lists.

.PARAMETER Force
    Download even where the copy in place already matches.

.EXAMPLE
    pwsh scripts/download-composer.ps1
    pwsh scripts/download-composer.ps1 -Version 2.10.3
#>
[CmdletBinding()]
param(
    [string] $Into,
    [string] $Version,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$catalogue = 'https://getcomposer.org/versions'

# Where this script's own file is, and the package root above it: the default destination is the
# repository's own root, and it is the same folder however the script was started and wherever the
# terminal is standing. `$PSScriptRoot` is set when a script runs from a file; the invocation's own
# command path still answers when it is dot-sourced, and with neither there is no folder to take.
$scriptDirectory = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }

if (-not $scriptDirectory) {
    throw 'Nothing names this script''s own file, so the package root cannot be located. Run it as a file — pwsh -File scripts/download-composer.ps1 — rather than as a piped or pasted command.'
}

$package = Split-Path -Parent (Resolve-Path -LiteralPath $scriptDirectory).Path

# An absolute destination, always: a relative value is taken from the package root.
$folder = if ($Into) {
    [System.IO.Path]::GetFullPath($(if ([System.IO.Path]::IsPathRooted($Into)) { $Into } else { Join-Path $package $Into }))
} else {
    Join-Path $package 'external'
}

$target = Join-Path $folder 'composer.phar'

# ── The release, and the checksum it publishes ───────────────────────────────────────────────────
$composerVersion = if ($Version) {
    $Version
} else {
    $known = Invoke-RestMethod -Uri $catalogue
    $stable = $known.stable | Select-Object -First 1

    if (-not $stable) {
        throw "The catalogue at {$catalogue} lists no stable release, so there is no version to fetch."
    }

    $stable.version
}

$release = 'https://getcomposer.org/download/' + $composerVersion
$pharUrl = $release + '/composer.phar'
$checksumUrl = $release + '/composer.phar.sha256sum'

# The body comes back as bytes when the response carries no text content type, and as a string when it
# does: `Content` is one or the other depending on how getcomposer.org answers, so it is decoded here
# rather than assumed. Reading a checksum as bytes fails on the first thing done to it.
$answer = Invoke-WebRequest -Uri $checksumUrl -UseBasicParsing
$body = if ($answer.Content -is [byte[]]) { [System.Text.Encoding]::UTF8.GetString($answer.Content) } else { [string] $answer.Content }
$published = $body.Trim()

# The file Composer publishes is `<64 hex>  composer.phar` — the same shape `sha256sum` writes. Only
# the hash is read from it, and the name beside it is deliberately not trusted for anything: what is
# compared is the hash of the bytes actually fetched.
if ($published -notmatch '(?m)^([0-9a-fA-F]{64})\s') {
    throw "The checksum at {$checksumUrl} is not a sha256sum line, so there is nothing to verify against."
}

$expected = $Matches[1].ToLowerInvariant()

Write-Host ('release   composer.phar {0}' -f $composerVersion)
Write-Host ('checksum  {0}' -f $expected)
Write-Host ('published {0}' -f $checksumUrl)

# ── The copy in place, if it is a good one ───────────────────────────────────────────────────────
if ((Test-Path -LiteralPath $target) -and -not $Force) {
    $inPlace = (Get-FileHash -Algorithm SHA256 -LiteralPath $target).Hash.ToLowerInvariant()

    if ($inPlace -eq $expected) {
        Write-Host ('')
        Write-Host ('kept      {0} — the copy in place is {1} and matches the published checksum' -f $target, $composerVersion)

        exit 0
    }

    Write-Host ('')
    Write-Host ('replace   {0} — the copy in place hashes {1}' -f $target, $inPlace)
}

# ── Fetch, verify, and only then install ─────────────────────────────────────────────────────────
if (-not (Test-Path -LiteralPath $folder)) {
    New-Item -ItemType Directory -Path $folder -Force | Out-Null
}

$download = $target + '.download'

Invoke-WebRequest -Uri $pharUrl -OutFile $download -UseBasicParsing

$actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $download).Hash.ToLowerInvariant()

if ($actual -ne $expected) {
    Remove-Item -LiteralPath $download -Force

    throw ('composer.phar {0} does not match the checksum Composer publishes for it: expected {1}, got {2}. The download was removed rather than installed, and any composer.phar already there is untouched.' -f $composerVersion, $expected, $actual)
}

Move-Item -LiteralPath $download -Destination $target -Force

Write-Host ('')
Write-Host ('verified  {0} — {1} bytes, sha256 {2}' -f $target, (Get-Item -LiteralPath $target).Length, $actual)
Write-Host ('          the hash was read from {0}, not written into this script' -f $checksumUrl)

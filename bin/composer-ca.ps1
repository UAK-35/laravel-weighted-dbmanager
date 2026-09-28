#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Run Composer for this package on Windows when a local TLS scanner is
    intercepting HTTPS.

.DESCRIPTION
    A local antivirus or proxy that scans TLS -- Avast Web Shield is the one this
    was found with -- can make every `composer install` / `composer update` fail
    with:

        curl error 60 ... SSL certificate problem: unable to get local issuer
        certificate

    Git, Git Bash's curl and the Windows certificate store keep working, because
    they use Schannel; PHP's curl uses OpenSSL with curl.cainfo / openssl.cafile,
    which does not carry the scanner's root. The two stacks disagreeing about the
    same URL is the fingerprint -- see docs/composer-tls-windows.md.

    This script rebuilds a CA bundle from Git's own ca-bundle.crt plus every
    intercepting root it finds in the Windows trust store, points Composer at it
    with COMPOSER_CAFILE (which Composer reads itself, so no PHP ini override is
    needed for a composer.bat shim), and then runs Composer. For
    install/update/require/remove it also ignores the Linux-only platform
    extensions a locked dependency may still require -- the second, unrelated
    Windows wrinkle docs/composer-tls-windows.md describes.

    It is a Windows convenience, not part of the package's runtime: nothing under
    src/ ever calls it.

.PARAMETER ComposerArgs
    Everything after the script name is forwarded to Composer verbatim, e.g.
    `install`, `update`, `test`, `release -- --weigh`.

.EXAMPLE
    pwsh -File .\bin\composer-ca.ps1 install
    pwsh -File .\bin\composer-ca.ps1 update
    pwsh -File .\bin\composer-ca.ps1 -- test
    pwsh -File .\bin\composer-ca.ps1 validate --no-check-publish

.NOTES
    The bundle is written to %TEMP% and is machine-local: never commit it, and
    never hard-code its path in composer.json. COMPOSER_CA_COMPOSER overrides the
    Composer program (a phar, a shim, or an absolute path).
#>
[CmdletBinding()]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $ComposerArgs
)

$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if (-not (Test-Path (Join-Path $repoRoot 'composer.json'))) {
    throw "No composer.json at $repoRoot -- run this from the package checkout."
}

# ── 1. The public roots, from Git for Windows ────────────────────────────────
$gitBundle = @(
    'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
    'C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt',
    'C:\Program Files\Git\mingw64\ssl\certs\ca-bundle.crt'
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $gitBundle) {
    # Derive it from wherever git.exe actually is, for a non-default install.
    $git = Get-Command git -ErrorAction SilentlyContinue
    if ($git) {
        $gitRoot = Split-Path (Split-Path $git.Source -Parent) -Parent
        foreach ($candidate in @(
            (Join-Path $gitRoot 'mingw64\etc\ssl\certs\ca-bundle.crt'),
            (Join-Path $gitRoot 'usr\ssl\certs\ca-bundle.crt')
        )) {
            if (Test-Path $candidate) { $gitBundle = $candidate; break }
        }
    }
}
if (-not $gitBundle) {
    throw 'Git CA bundle not found -- is Git for Windows installed? See docs/composer-tls-windows.md.'
}

# ── 2. Every intercepting root the scanner installed ────────────────────────
$bundle = Join-Path $env:TEMP 'composer-ca.pem'
Copy-Item $gitBundle $bundle -Force

$interceptors = 'Web/Mail Shield|Kaspersky|ESET|Bitdefender|Sophos|Zscaler|Netskope|Avast'
$roots = @()
foreach ($store in @('Cert:\LocalMachine\Root', 'Cert:\CurrentUser\Root')) {
    $roots += Get-ChildItem $store -ErrorAction SilentlyContinue |
        Where-Object { $_.Subject -match $interceptors }
}
foreach ($root in $roots) {
    $b64 = [Convert]::ToBase64String($root.RawData, 'InsertLineBreaks')
    Add-Content -Path $bundle -Value "`n-----BEGIN CERTIFICATE-----`n$b64`n-----END CERTIFICATE-----"
}

if ($roots.Count -eq 0) {
    Write-Warning 'No intercepting root found in the Windows trust store; using Git''s bundle as-is.'
}

$env:COMPOSER_CAFILE = $bundle

# ── 3. The Composer to run ──────────────────────────────────────────────────
$composerCmd = $env:COMPOSER_CA_COMPOSER
if (-not $composerCmd) {
    $found = Get-Command composer -ErrorAction SilentlyContinue
    if ($found) { $composerCmd = $found.Source }
}
if (-not $composerCmd) {
    foreach ($candidate in @(
        (Join-Path $env:USERPROFILE '.config\herd\bin\composer.bat'),
        (Join-Path $repoRoot 'composer.phar')
    )) {
        if (Test-Path $candidate) { $composerCmd = $candidate; break }
    }
}
if (-not $composerCmd) {
    throw 'Composer not found on PATH. Set COMPOSER_CA_COMPOSER to composer.bat or composer.phar.'
}

# ── 4. The Windows-only platform requirements ───────────────────────────────
# Only the subcommands where Composer resolves/verifies requirements need them;
# they are no-ops where nothing requires the extensions.
$windowsOnlyExts = @('ext-grpc', 'ext-pcntl', 'ext-posix', 'ext-swoole', 'ext-uv')
$subcommand = if ($ComposerArgs.Count -gt 0) { $ComposerArgs[0].ToLowerInvariant() } else { '' }
$platformArgs = @()
if ($subcommand -in @('install', 'update', 'require', 'remove')) {
    $platformArgs = $windowsOnlyExts | ForEach-Object { "--ignore-platform-req=$_" }
}

Write-Host "CA bundle: $bundle (+$($roots.Count) intercepting root(s))" -ForegroundColor DarkGray

& $composerCmd @ComposerArgs @platformArgs
exit $LASTEXITCODE

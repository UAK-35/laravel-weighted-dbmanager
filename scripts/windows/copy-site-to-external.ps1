#requires -Version 5.1
<#
    Copies this machine's Laravel site checkout into external/laravel-site, carrying
    the parts of it this package works against and nothing else.

    Where the site is, is a machine's fact rather than a rule's, so it is not written
    into this file: it is read from site-path.local.cmd beside this script — the same
    token-and-sample arrangement as scripts/servers-startup/servers.local.cmd — or
    passed as the first argument. The destination defaults to external/laravel-site in
    this repository.

    Two filters, both by name, both applied to what is inside the included folders:

      1. only these thirteen top-level names are visited — app, artisan, bootstrap,
         composer.json, composer.lock, config, database, public, resources, routes,
         storage, tests, vendorOverrides. `artisan`, `composer.json` and
         `composer.lock` are files rather than folders, and are copied as ones; the
         rest are folders and are walked recursively. Everything else at the top of
         the site — vendor, node_modules, .git, .env* — is left where it is.

      2. files whose names end in .txt, .text, .todo, .7z, .zip, .ignore or
         .gitignore are not copied, at any depth. Folder names are never filtered:
         the folder structure is carried whole, empty folders included.

    The copy is additive: a file that is already at its destination is overwritten, and
    a file at the destination that the site does not have is left alone. Start from an
    empty external/laravel-site if you want the two to match exactly.

    -WhatIf reports each included name and touches nothing.

    Exit codes: 0 everything included was copied, 1 a copy or a folder creation failed,
    2 the site path is unknown or missing, or the destination is unusable.
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Position = 0)]
    [string] $Source,

    [Parameter(Position = 1)]
    [string] $Destination
)

$ErrorActionPreference = 'Stop'

# scripts/windows -> scripts -> the repository root.
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent (Split-Path -Parent $here)
$sitePathFile = Join-Path $here 'site-path.local.cmd'

# The top-level names that are carried, and no others.
$included = @(
    'app',
    'artisan',
    'bootstrap',
    'composer.json',
    'composer.lock',
    'config',
    'database',
    'public',
    'resources',
    'routes',
    'storage',
    'tests',
    'vendorOverrides'
)

# Files that are not carried, at any depth.
$excluded = @('*.txt', '*.text', '*.todo', '*.7z', '*.zip', '*.ignore', '*.gitignore')

if (-not $Destination) { $Destination = Join-Path $root 'external\laravel-site' }
elseif (-not [System.IO.Path]::IsPathRooted($Destination)) { $Destination = Join-Path $root $Destination }

function Exit-With([int] $code, [string] $message) {
    [Console]::Error.WriteLine($message)
    exit $code
}

function Test-Excluded([string] $name) {
    foreach ($pattern in $excluded) {
        if ($name -like $pattern) { return $true }
    }

    return $false
}

#
# 1. Where the site is, and where it goes.
#

if (-not $Source) {
    if (-not (Test-Path -LiteralPath $sitePathFile -PathType Leaf)) {
        Exit-With 2 @(
            "The site path is unknown: `"$sitePathFile`" is missing and no source was passed."
            "Copy site-path.sample.cmd to that name and replace __SITE_SOURCE__ with this machine's path,"
            "or pass the path as the first argument."
        ) -join "`n"
    }

    foreach ($line in Get-Content -LiteralPath $sitePathFile) {
        if ($line -match '^\s*set\s+"SITE_SOURCE=(.*)"\s*$') { $Source = $Matches[1] }
    }

    if (-not $Source -or $Source -eq '__SITE_SOURCE__') {
        Exit-With 2 "`"$sitePathFile`" does not set SITE_SOURCE to a path; it still holds the sample's token."
    }
} elseif (-not [System.IO.Path]::IsPathRooted($Source)) {
    $Source = Join-Path $root $Source
}

if (-not (Test-Path -LiteralPath $Source -PathType Container)) {
    Exit-With 2 "The site path does not name a folder: `"$Source`"."
}

$sourceRoot = (Resolve-Path -LiteralPath $Source).ProviderPath.TrimEnd('\', '/')

Write-Host "From: $sourceRoot"
Write-Host "To:   $Destination"
Write-Host ''

#
# 2. The eleven names, each walked once.
#

$filesCopied = 0
$filesExcluded = 0
$foldersCreated = 0
$notThere = @()
$failed = @()

foreach ($name in $included) {
    $from = Join-Path $sourceRoot $name
    $to = Join-Path $Destination $name

    if (-not (Test-Path -LiteralPath $from)) {
        $notThere += $name
        continue
    }

    if (-not $PSCmdlet.ShouldProcess($to, "Copy $name")) { continue }

    try {
        # A top-level file — artisan — is copied as itself, filter included.
        if (Test-Path -LiteralPath $from -PathType Leaf) {
            if (Test-Excluded $name) {
                $filesExcluded++
                continue
            }

            if (-not (Test-Path -LiteralPath $Destination)) {
                New-Item -ItemType Directory -Path $Destination -Force | Out-Null
                $foldersCreated++
            }

            Copy-Item -LiteralPath $from -Destination $to -Force
            $filesCopied++
            continue
        }

        # A folder: its structure whole, then the files the filter keeps.
        $dirs = @(Get-Item -LiteralPath $from) + @(Get-ChildItem -LiteralPath $from -Recurse -Directory -Force)

        foreach ($dir in $dirs) {
            $relativeDir = $dir.FullName.Substring($sourceRoot.Length + 1)
            $targetDir = Join-Path $Destination $relativeDir

            if (-not (Test-Path -LiteralPath $targetDir)) {
                New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
                $foldersCreated++
            }
        }

        foreach ($file in Get-ChildItem -LiteralPath $from -Recurse -File -Force) {
            if (Test-Excluded $file.Name) {
                $filesExcluded++
                continue
            }

            $relativeFile = $file.FullName.Substring($sourceRoot.Length + 1)
            Copy-Item -LiteralPath $file.FullName -Destination (Join-Path $Destination $relativeFile) -Force
            $filesCopied++
        }
    } catch {
        $failed += "$name  ($($_.Exception.Message))"
    }
}

#
# 3. What happened.
#

Write-Host ''
Write-Host ("Files copied:     {0}" -f $filesCopied)
Write-Host ("Files excluded:   {0}" -f $filesExcluded)
Write-Host ("Folders created:  {0}" -f $foldersCreated)
Write-Host ("Not in the site:  {0}" -f $notThere.Count)

foreach ($item in $notThere) { Write-Host "  absent  $item" }
foreach ($item in $failed) { Write-Host "  FAILED  $item" }

if ($failed.Count -gt 0) {
    Exit-With 1 "At least one name could not be copied; the lines above name them."
}

if ($notThere.Count -gt 0) {
    Exit-With 1 "At least one included name is not in the site; the lines above name it."
}

exit 0

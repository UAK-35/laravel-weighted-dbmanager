#requires -Version 5.1
<#
    Deletes the files named, one per line, in ext/app-files-to-delete.txt.

    Each line is a path relative to external/app — `app/Common/TypedCollection.php.txt`
    means `external/app/app/Common/TypedCollection.php.txt` — because that is the folder
    the names were taken from when the list was written. Forward slashes are accepted,
    blank lines and lines starting with `#` are ignored, and a name listed twice is only
    attempted once.

    A name that is not there is reported as already gone rather than as a failure, so the
    script is safe to run twice. A name that resolves outside external/app, or that names
    a directory rather than a file, is skipped and reported; nothing is ever deleted
    recursively.

    The folder the lines are relative to is external/app unless another is named as the
    first argument — `remove-external-app-files.ps1 external/laravel-site` — which is how
    the copy made by copy-site-to-external.ps1 is cleaned the same way.

    -WhatIf prints what would be deleted and touches nothing.

    Exit codes: 0 nothing listed remains, 1 a listed name was not deleted (the delete
    failed, or the name was skipped), 2 the list file or external/app is missing.
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # The folder the list's paths are relative to, and the only folder this may
    # delete from — external/app unless another checkout is named, as in
    # `remove-external-app-files.ps1 external/laravel-site`.
    [Parameter(Position = 0)]
    [Alias('Path', 'Root')]
    [string] $Target,

    [Parameter(Position = 1)]
    [string] $ListFile
)

$ErrorActionPreference = 'Stop'

# scripts/windows -> scripts -> the repository root.
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent (Split-Path -Parent $here)

if (-not $ListFile) { $ListFile = Join-Path $root 'ext\app-files-to-delete.txt' }
if (-not $Target) { $Target = Join-Path $root 'external\app' }
elseif (-not [System.IO.Path]::IsPathRooted($Target)) { $Target = Join-Path $root $Target }

function Exit-With([int] $code, [string] $message) {
    [Console]::Error.WriteLine($message)
    exit $code
}

if (-not (Test-Path -LiteralPath $ListFile -PathType Leaf)) {
    Exit-With 2 "The list file is missing: `"$ListFile`"."
}

if (-not (Test-Path -LiteralPath $Target -PathType Container)) {
    Exit-With 2 "The folder the list is relative to is missing: `"$Target`"."
}

$targetRoot = (Resolve-Path -LiteralPath $Target).ProviderPath.TrimEnd('\', '/')
$separator = [System.IO.Path]::DirectorySeparatorChar
$prefix = $targetRoot + $separator

$seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
$deleted = 0
$alreadyGone = @()
$skipped = @()
$failed = @()

Write-Host "List:   $ListFile"
Write-Host "Target: $targetRoot"
Write-Host ''

foreach ($line in Get-Content -LiteralPath $ListFile) {
    $relative = $line.Trim()

    if ($relative -eq '' -or $relative.StartsWith('#')) { continue }
    if (-not $seen.Add($relative)) { continue }

    $full = [System.IO.Path]::GetFullPath((Join-Path $targetRoot ($relative -replace '/', '\')))

    # A path that climbs out of external/app is not a name this list may delete.
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        $skipped += "$relative  (outside $targetRoot)"
        continue
    }

    if (-not (Test-Path -LiteralPath $full)) {
        $alreadyGone += $relative
        continue
    }

    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) {
        $skipped += "$relative  (not a file)"
        continue
    }

    if ($PSCmdlet.ShouldProcess($full, 'Delete file')) {
        try {
            Remove-Item -LiteralPath $full -Force
            $deleted++
        } catch {
            $failed += "$relative  ($($_.Exception.Message))"
        }
    }
}

Write-Host ''
Write-Host ("Deleted:       {0}" -f $deleted)
Write-Host ("Already gone:  {0}" -f $alreadyGone.Count)
Write-Host ("Skipped:       {0}" -f $skipped.Count)
Write-Host ("Failed:        {0}" -f $failed.Count)

foreach ($item in $alreadyGone) { Write-Host "  gone    $item" }
foreach ($item in $skipped) { Write-Host "  skipped $item" }
foreach ($item in $failed) { Write-Host "  FAILED  $item" }

if ($failed.Count -gt 0) {
    Exit-With 1 "At least one file could not be deleted; the lines above name them."
}

if ($skipped.Count -gt 0) {
    Exit-With 1 "At least one listed name was not deleted; the lines above name it."
}

exit 0

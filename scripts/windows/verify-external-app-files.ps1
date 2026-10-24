#requires -Version 5.1
<#
    Verifies that the files named, one per line, in ext/app-files-list.txt still exist.

    Each line is a path relative to external/app — `app/Casts/EfficientUuid.php` means
    `external/app/app/Casts/EfficientUuid.php`. Forward slashes are accepted, blank lines
    and lines starting with `#` are ignored, and a name listed twice is only checked once.

    Output is the finding, not the progress: a name that is there prints nothing, and a
    name that is not is printed on its own line, so the output can be piped straight into
    another command. A line that resolves outside external/app is printed with a note
    instead of being checked, because a list that climbs out of the folder is a list to
    fix rather than a folder to walk.

    A name that exists but is a folder counts as missing: the list is a list of files.

    The folder the lines are relative to is external/app unless another is named as the
    first argument — `verify-external-app-files.ps1 external/laravel-site` — which is how
    the copy made by copy-site-to-external.ps1 is checked.

    Exit codes: 0 every name exists, 1 at least one is missing, 2 the list file or
    external/app is missing.
#>

[CmdletBinding()]
param(
    # The folder the list's paths are relative to — external/app unless another
    # checkout is named, as in `verify-external-app-files.ps1 external/laravel-site`.
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

if (-not $ListFile) { $ListFile = Join-Path $root 'ext\app-files-list.txt' }
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
$checked = 0
$missing = 0

foreach ($line in Get-Content -LiteralPath $ListFile) {
    $relative = $line.Trim()

    if ($relative -eq '' -or $relative.StartsWith('#')) { continue }
    if (-not $seen.Add($relative)) { continue }

    $checked++

    $full = [System.IO.Path]::GetFullPath((Join-Path $targetRoot ($relative -replace '/', '\')))

    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        $missing++
        Write-Output "$relative  (outside $targetRoot)"
        continue
    }

    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) {
        $missing++
        Write-Output $relative
    }
}

if ($checked -eq 0) {
    Exit-With 2 "The list file names nothing: `"$ListFile`"."
}

if ($missing -gt 0) {
    exit 1
}

exit 0

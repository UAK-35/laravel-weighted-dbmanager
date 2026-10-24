#requires -Version 5.1
<#
    Builds external/laravel-site from the site checkout, then runs the three scripts in
    scripts/windows against it, in the order they are meant to be run:

      1. copy-site-to-external.ps1        — the thirteen included names, filtered by extension
      2. remove-external-app-files.ps1    — the files named in ext/app-files-to-delete.txt
      3. remove-external-app-folders.ps1  — the folders named in ext/app-folders-to-delete.txt
      4. verify-external-app-files.ps1    — that the files named in ext/app-files-list.txt exist

    Each of the three takes the destination as its first argument, which is the point of
    this script: the lists are relative to a checkout root, and that root here is the
    copy rather than external/app.

    Steps do not stop the run — the two removals are idempotent and the verdict at the
    end is the fourth step's — but a copy that failed ends it, because there is nothing
    worth checking out when the checkout was not made.

    Step 4 checks a different record from the two steps before it, and the folder list
    wins where they overlap: a name the folder list deletes is reported as missing by
    the file list unless it has also been taken out of ext/app-files-list.txt. That is
    what a red fourth step usually means, and pruning the file list is what makes this
    script exit 0.

    Exit codes: 0 all four steps succeeded, 1 a step reported failure (the summary says
    which), 2 the copy could not be made.
#>

[CmdletBinding()]
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

if (-not $Destination) { $Destination = Join-Path $root 'external\laravel-site' }
elseif (-not [System.IO.Path]::IsPathRooted($Destination)) { $Destination = Join-Path $root $Destination }

function Write-Step([string] $text) {
    Write-Host ''
    Write-Host "== $text"
}

$steps = @()

#
# 1. The copy.
#

Write-Step 'Copying the site'

$copyArgs = @{ Destination = $Destination }
if ($Source) { $copyArgs['Source'] = $Source }

& (Join-Path $here 'copy-site-to-external.ps1') @copyArgs
$steps += @{ Name = 'copy-site-to-external.ps1'; Code = $LASTEXITCODE }

if ($LASTEXITCODE -ne 0) {
    Write-Step 'Stopped'
    Write-Host 'The copy did not finish, so nothing was cleaned and nothing was verified.'
    Write-Host 'The copy script named what went wrong above.'
    exit 2
}

#
# 2 and 3. The two removals, each against the copy.
#

Write-Step 'Removing the listed files'

& (Join-Path $here 'remove-external-app-files.ps1') -Target $Destination
$steps += @{ Name = 'remove-external-app-files.ps1'; Code = $LASTEXITCODE }

Write-Step 'Removing the listed folders'

& (Join-Path $here 'remove-external-app-folders.ps1') -Target $Destination
$steps += @{ Name = 'remove-external-app-folders.ps1'; Code = $LASTEXITCODE }

#
# 4. The verdict.
#

Write-Step 'Verifying the listed files'

& (Join-Path $here 'verify-external-app-files.ps1') -Target $Destination
$steps += @{ Name = 'verify-external-app-files.ps1'; Code = $LASTEXITCODE }

#
# The summary: one line per step, and the run's own code.
#

Write-Step 'Summary'

foreach ($step in $steps) {
    Write-Host ("{0,-34} {1}" -f $step.Name, $(if ($step.Code -eq 0) { 'ok' } else { "exit $($step.Code)" }))
}

$failed = @($steps | Where-Object { $_.Code -ne 0 })

if ($failed.Count -gt 0) {
    Write-Host ''
    Write-Host ("{0} of {1} steps reported a failure; the output above each is what each one found." -f $failed.Count, $steps.Count)
    exit 1
}

Write-Host ''
Write-Host "external/laravel-site is built and every listed file is there: $Destination"
exit 0

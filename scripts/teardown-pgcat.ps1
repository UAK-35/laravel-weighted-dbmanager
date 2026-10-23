#requires -Version 5.1
<#
    The counterpart of setup-pgcat.ps1: this machine's rig, down, in the reverse
    order.

    The three stops already exist, each beside the servers it talks to in
    scripts/servers-startup/, and each reports the same three things — whether the
    thing was up at all, what the server answered when asked to stop, and the
    readiness line afterwards. What this script adds is the order and one verdict
    at the end:

      1. the pooler, because it holds connections to both servers;
      2. the standby, before the primary it streams from;
      3. the primary.

    Nothing enforces the order — each stop script stops what it names — but a
    standby left streaming from a primary that has gone spends the interval until
    its own shutdown retrying a walreceiver that cannot receive, so the reverse
    order is what keeps the logs quiet.

    "Down" is a port that answers nothing, not an exit code: the last step asks
    all three again with `pg_isready`, and one that still answers fails the run.
    Running it twice is safe — an already-silent port is reported as nothing to
    stop by the script that owns it — and the data directories are untouched, so
    setup-pgcat.ps1 starts the same three again.

    Exit codes: 0 all three are silent, 1 a stop failed, 2 the machine file or the
    PostgreSQL directory is missing.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$servers = Join-Path $here 'servers-startup'
$machineFile = Join-Path $servers 'servers.local.cmd'

# The ports the three processes bind: the standby, the primary, the pooler.
$primaryPort = 5432
$standbyPort = 5431
$poolerPort = 5433

function Exit-With([int] $code, [string] $message) {
    [Console]::Error.WriteLine($message)
    exit $code
}

function Write-Step([string] $text) {
    Write-Host ''
    Write-Host "== $text"
}

# The machine file is a cmd script, so it is read as the `set "NAME=VALUE"` lines
# it is made of rather than executed: nothing in it starts or stops a server.
function Read-MachineFile([string] $path) {
    $settings = @{}

    foreach ($line in Get-Content -LiteralPath $path) {
        if ($line -match '^\s*set\s+"([A-Za-z_][A-Za-z0-9_]*)=(.*)"\s*$') {
            $settings[$Matches[1]] = $Matches[2]
        }
    }

    return $settings
}

# A stop is waited on, because the process it stops is the one that would keep the
# caller attached: unlike a starter, a stop leaves nothing holding a handle. The
# wait is still bounded, and a stop that has not finished after it is a failure
# with its output printed rather than a script that never returns.
function Invoke-StopScript([string] $path, [string] $name) {
    $out = Join-Path $env:TEMP ("pgcat-teardown-$name.out")
    $err = Join-Path $env:TEMP ("pgcat-teardown-$name.err")

    $process = Start-Process -FilePath $env:ComSpec `
        -ArgumentList '/c', ('"' + $path + '"') `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $out -RedirectStandardError $err

    $finished = $process.WaitForExit(60000)

    foreach ($file in @($out, $err)) {
        if (Test-Path -LiteralPath $file) {
            Get-Content -LiteralPath $file | Where-Object { $_ -notmatch '^\s*$' } | ForEach-Object { Write-Host $_ }
            Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue
        }
    }

    if (-not $finished) {
        return 1
    }

    return $process.ExitCode
}

#
# 1. The machine file, and the PostgreSQL directory it names.
#

Write-Step 'The machine file'

if (-not (Test-Path -LiteralPath $machineFile)) {
    Exit-With 2 "servers.local.cmd is missing from `"$servers`". Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT."
}

$settings = Read-MachineFile $machineFile
$pgRoot = $settings['PG_ROOT']
$pgBin = Join-Path $pgRoot 'pgsql179\bin'

if (-not $pgRoot -or -not (Test-Path -LiteralPath (Join-Path $pgBin 'pg_isready.exe'))) {
    Exit-With 2 "PG_ROOT is not set to the PostgreSQL pair's directory (the one holding pgsql179\bin\pg_isready.exe): `"$pgRoot`"."
}

Write-Host "PostgreSQL: $pgRoot"

#
# 2. The three stops, in the reverse of the order the setup uses.
#

$order = @(
    @{ Script = 'stop-pgcat.bat'; Name = 'pooler'; Port = $poolerPort },
    @{ Script = 'stop-replica.bat'; Name = 'standby'; Port = $standbyPort },
    @{ Script = 'stop-primary.bat'; Name = 'primary'; Port = $primaryPort }
)

foreach ($step in $order) {
    Write-Step "Stopping the $($step.Name) on $($step.Port)"

    $status = Invoke-StopScript (Join-Path $servers $step.Script) $step.Name

    if ($status -ne 0) {
        Exit-With 1 "$($step.Script) exited $status, so the $($step.Name) did not stop. Its output above names what still holds port $($step.Port); a second postmaster or a pooler whose window answered nothing is the usual pair."
    }
}

#
# 3. The verdict: three ports that answer nothing.
#

Write-Step 'The three ports'

$stillUp = @()

foreach ($step in @(
    @{ Name = 'primary'; Port = $primaryPort; User = 'postgres' },
    @{ Name = 'standby'; Port = $standbyPort; User = 'postgres' },
    @{ Name = 'pooler'; Port = $poolerPort; User = 'admin_user' }
)) {
    & (Join-Path $pgBin 'pg_isready.exe') -h 127.0.0.1 -p $step.Port -U $step.User *> $null

    if ($LASTEXITCODE -eq 0) {
        $stillUp += ("the {0} still answers on {1}" -f $step.Name, $step.Port)
        Write-Host ("{0}: still answering" -f $step.Port)
    } else {
        Write-Host ("{0}: silent" -f $step.Port)
    }
}

if ($stillUp.Count -gt 0) {
    Exit-With 1 (@(
        'The rig is not down:'
        (($stillUp | ForEach-Object { "  - $_" }) -join "`n")
        ''
        'A stop that reported success while its port still answers is the case the stop scripts exist'
        'to catch; the line above names what to look at, and each script prints the process it found.'
    ) -join "`n")
}

Write-Host ''
Write-Host ("The rig is down: nothing answers on {0}, {1} or {2}." -f $standbyPort, $primaryPort, $poolerPort)
Write-Host 'The data directories are untouched; setup-pgcat.ps1 starts the same three again.'

exit 0

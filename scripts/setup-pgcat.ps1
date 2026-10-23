#requires -Version 5.1
<#
    One command for this machine's pgcat-from-source rig.

    The setup is three pieces, and each already has a script of its own beside the
    servers it talks to, in scripts/servers-startup/:

      1. the pooler's own build: three crates whose `.der` files this platform
         refuses to read, re-extracted from their archives, renamed and patched,
         then `cargo build` with the patched copies passed as `--config` —
         build-pgcat.ps1;
      2. the two `pg_hba.conf` rules that offer scram-sha-256 to the pool user,
         which is the only method pgcat speaks when it is a client —
         apply-hba-scram.ps1;
      3. the rows the pooler runs with: 127.0.0.1:5433, the standby named as the
         replica, and `simple_db` split into reads and writes — pgcat.toml.

    What this script adds is the order and the checks around them. The machine
    file is read once, the ports Windows has reserved are reported before
    anything is started (a postmaster that cannot bind is a mystery otherwise),
    the pool rows are compared with what this rig needs before any of it is
    started, then the two servers come up, the rules and the build run against a
    live pair, and the pooler is started last.

    It finishes by proving the routing rather than trusting the rows it checked:
    a read through the pooler that must be answered by the standby and a write
    that must be answered by the primary, both asked as a client asks, with the
    run failing when either one lands on the wrong server. A pooler that is up
    and sends a read to the primary is the state this step exists to catch, and
    nothing before it would.

    Running it twice is safe. An already-running server is reported as such by
    its own script, the rules are added once, the patched crates are reused, and
    the build is skipped while the pooler holds its own binary open — cargo
    cannot replace a file in use, and saying so is more useful than letting cargo
    report `Access is denied` with no cause in it.

    Nothing is installed and nothing is searched for. PG_ROOT and PGCAT_ROOT are
    this machine's, named in servers.local.cmd; cargo and the crate cache are
    whatever the environment holds. scripts/servers-startup/README.md is the long
    form of every step, and docs/local-postgres-and-pgcat.md section 2 the record
    of why each one exists.

    Exit codes: 0 the rig is up and routes the way it is configured to, 1 a step
    failed or a query went to the wrong server, 2 the machine file or a directory
    it names is missing.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$servers = Join-Path $here 'servers-startup'
$machineFile = Join-Path $servers 'servers.local.cmd'
$configFile = Join-Path $servers 'pgcat.toml'

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

function Test-Listening([int] $port) {
    foreach ($line in netstat -ano) {
        if ($line -match ('^\s*TCP\s+127\.0\.0\.1:' + $port + '\s+\S+\s+LISTENING')) {
            return $true
        }
    }

    return $false
}

# Windows' own reserved ranges, which Hyper-V and WinNAT re-create at boot. A
# server inside one of them fails on `could not bind IPv4 address: Permission
# denied`, which reads like a permissions problem and is not one.
function Get-ReservedPortRanges {
    $ranges = @()

    foreach ($line in netsh interface ipv4 show excludedportrange protocol=tcp) {
        if ($line -match '^\s*(\d+)\s+(\d+)\s*\*?\s*$') {
            $ranges += @{ Start = [int] $Matches[1]; End = [int] $Matches[2] }
        }
    }

    return $ranges
}

# The two bundled scripts are children, not dotsourced: each ends with `exit`,
# which would end this script with it. Their output is piped to the host rather
# than left on this function's pipeline, or every line they print would be
# returned instead of the exit code.
function Invoke-PowerShellScript([string] $path) {
    $hostExe = if ($PSVersionTable.PSEdition -eq 'Core') { Join-Path $PSHOME 'pwsh.exe' } else { Join-Path $PSHOME 'powershell.exe' }

    & $hostExe -NoProfile -ExecutionPolicy Bypass -File $path | Out-Host
    return $LASTEXITCODE
}

# A starter is launched rather than waited on, and its readiness is read from the
# port instead of from an exit code. A postmaster inherits whatever handles its
# starter was given, so anything that waits for the batch file to finish — this
# script's own console, or a caller redirecting this script's output — can end up
# waiting for as long as the server lives. "Started" is therefore a port that
# answers, which is the same verdict the starter itself reports.
function Start-BatchFile([string] $path, [string] $name) {
    $out = Join-Path $env:TEMP ("pgcat-setup-$name.out")
    $err = Join-Path $env:TEMP ("pgcat-setup-$name.err")

    $process = Start-Process -FilePath $env:ComSpec `
        -ArgumentList '/c', ('"' + $path + '"') `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $out -RedirectStandardError $err

    return @{ Process = $process; Out = $out; Err = $err }
}

function Show-BatchOutput([hashtable] $batch) {
    # The port answers before the starter has printed its last line, so the batch
    # is given a moment to finish — bounded, because the postmaster it started is
    # exactly the process it may never be able to wait for.
    $batch.Process.WaitForExit(3000) | Out-Null

    foreach ($file in @($batch.Out, $batch.Err)) {
        if (Test-Path -LiteralPath $file) {
            Get-Content -LiteralPath $file | Where-Object { $_ -notmatch '^\s*$' } | ForEach-Object { Write-Host $_ }
            Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue
        }
    }
}

function Wait-ForPort([int] $port, [string] $user, [int] $seconds) {
    $isReady = Join-Path $pgBin 'pg_isready.exe'
    $deadline = (Get-Date).AddSeconds($seconds)

    while ((Get-Date) -lt $deadline) {
        & $isReady -h 127.0.0.1 -p $port -U $user *> $null

        if ($LASTEXITCODE -eq 0) {
            return $true
        }

        Start-Sleep -Milliseconds 800
    }

    return $false
}

# A TOML file read only as far as the checks below need: one entry per table,
# holding that table's lines. Values are read inside their own table, so a
# `port` in [general] cannot be answered by one in a pool.
# One psql run against one server, its answer and its exit code. The pooler is asked
# as a client reaches it — the pool's own name and user — rather than as the superuser
# the two server checks use, because the password is the first link in the chain.
function Invoke-Psql([int] $port, [string] $database, [string] $user, [string] $sql) {
    $psql = Join-Path $pgBin 'psql.exe'
    $output = & $psql -h 127.0.0.1 -p $port -U $user -d $database -Atc $sql 2>&1

    return @{ Exit = $LASTEXITCODE; Output = (($output | ForEach-Object { "$_" }) -join "`n") }
}

# The row a routing question asks for, out of what psql printed. A `-c` carrying
# several statements prints a command tag for each, so the answer is the one line with
# both fields: the port that served it, and whether that server is a standby.
function Get-RouteAnswer([string] $output) {
    foreach ($line in ($output -split "`n")) {
        if ($line -match '^\s*(\d+)\|(t|f)\s*$') {
            return @{ Port = [int] $Matches[1]; InRecovery = ($Matches[2] -eq 't') }
        }
    }

    return $null
}

function Read-TomlSections([string] $path) {
    $sections = @{}
    $current = ''
    $lines = New-Object System.Collections.Generic.List[string]

    foreach ($line in Get-Content -LiteralPath $path) {
        # A table name is letters, digits, dots and underscores. The looser shape —
        # anything between brackets — would read a shard's `[ "localhost", 5431,
        # "replica" ]` row as the start of a table and cut the shard in two.
        if ($line -match '^\s*\[\s*([A-Za-z0-9_.-]+)\s*\]\s*$') {
            if ($current -ne '') {
                $sections[$current] = ($lines -join "`n")
            }

            $current = $Matches[1].Trim()
            $lines.Clear()
            continue
        }

        $lines.Add($line) | Out-Null
    }

    if ($current -ne '') {
        $sections[$current] = ($lines -join "`n")
    }

    return $sections
}

function Get-TomlValue([hashtable] $sections, [string] $section, [string] $key) {
    if (-not $sections.ContainsKey($section)) {
        return $null
    }

    $match = [regex]::Match($sections[$section], '(?m)^\s*' + [regex]::Escape($key) + '\s*=\s*(.+?)\s*$')

    if (-not $match.Success) {
        return $null
    }

    # An inline comment is not part of the value: `connect_timeout = 5000 # ms`.
    return ($match.Groups[1].Value -replace '\s+#.*$', '').Trim()
}

#
# 1. The machine file, and the two directories it names.
#

Write-Step 'The machine file'

if (-not (Test-Path -LiteralPath $machineFile)) {
    Exit-With 2 "servers.local.cmd is missing from `"$servers`". Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT."
}

$settings = Read-MachineFile $machineFile
$pgRoot = $settings['PG_ROOT']
$pgcatRoot = $settings['PGCAT_ROOT']

$pgBin = Join-Path $pgRoot 'pgsql179\bin'

if (-not $pgRoot -or -not (Test-Path -LiteralPath (Join-Path $pgBin 'pg_ctl.exe'))) {
    Exit-With 2 "PG_ROOT is not set to the PostgreSQL pair's directory (the one holding pgsql179\bin\pg_ctl.exe): `"$pgRoot`"."
}

if (-not $pgcatRoot -or -not (Test-Path -LiteralPath (Join-Path $pgcatRoot 'Cargo.toml'))) {
    Exit-With 2 "PGCAT_ROOT is not set to a pgcat checkout (a directory holding Cargo.toml): `"$pgcatRoot`"."
}

Write-Host "PostgreSQL: $pgRoot"
Write-Host "Pooler:     $pgcatRoot"

#
# 2. The ports Windows may hold before any server can have them.
#

Write-Step 'The ports Windows has reserved'

$reserved = Get-ReservedPortRanges
$blocked = @()

foreach ($port in @($primaryPort, $standbyPort, $poolerPort)) {
    foreach ($range in $reserved) {
        if ($port -ge $range.Start -and $port -le $range.End) {
            $blocked += ("{0} is inside {1}-{2}" -f $port, $range.Start, $range.End)
            break
        }
    }
}

if ($blocked.Count -gt 0) {
    Exit-With 1 (@(
        'Windows has reserved these ports, so no server can bind them:'
        (($blocked | ForEach-Object { "  - $_" }) -join "`n")
        ''
        'Hyper-V and WinNAT hold the ranges and re-create them at boot. In an elevated prompt:'
        '  net stop winnat'
        'then run this script again, and afterwards'
        '  net start winnat'
    ) -join "`n")
}

Write-Host "None of $standbyPort, $primaryPort or $poolerPort is inside a reserved range."

#
# 3. The pool rows, before anything is started: drift is a file edit, and
#    finding it after a build wastes the build.
#

Write-Step "The pool rows in $configFile"

if (-not (Test-Path -LiteralPath $configFile)) {
    Exit-With 1 "No pgcat.toml at `"$configFile`". It is the config the pooler is started with, and it is committed beside these scripts."
}

$sections = Read-TomlSections $configFile
$drift = New-Object System.Collections.Generic.List[string]

# Each row this rig stands on, with what it is for: a value that differs is
# reported as the behaviour it would change rather than as a string mismatch.
$expectations = @(
    @{ Section = 'general'; Key = 'host'; Want = '"127.0.0.1"'; Says = 'the pooler listens on 127.0.0.1' },
    @{ Section = 'general'; Key = 'port'; Want = "$poolerPort"; Says = "the pooler listens on $poolerPort" },
    @{ Section = 'general'; Key = 'admin_username'; Want = '"admin_user"'; Says = 'start-pgcat.bat reads SHOW POOLS as admin_user' },
    @{ Section = 'general'; Key = 'admin_password'; Want = '"admin_pass"'; Says = 'start-pgcat.bat reads SHOW POOLS with that user''s password' },
    @{ Section = 'pools.simple_db'; Key = 'query_parser_read_write_splitting'; Want = 'true'; Says = 'reads are split from writes' },
    @{ Section = 'pools.simple_db'; Key = 'primary_reads_enabled'; Want = 'false'; Says = 'reads are kept off the primary' },
    @{ Section = 'pools.simple_db.users.0'; Key = 'username'; Want = '"simple_user"'; Says = 'the pool reaches the servers as simple_user' },
    @{ Section = 'pools.simple_db.shards.0'; Key = 'database'; Want = '"some_db"'; Says = 'the pool reaches the some_db database' }
)

foreach ($expectation in $expectations) {
    $actual = Get-TomlValue $sections $expectation.Section $expectation.Key

    if ($null -eq $actual) {
        $drift.Add(("[{0}] {1} is not set, and {2}" -f $expectation.Section, $expectation.Key, $expectation.Says))
    } elseif ($actual -ne $expectation.Want) {
        $drift.Add(("[{0}] {1} is {2}, not {3}, and {4}" -f $expectation.Section, $expectation.Key, $actual, $expectation.Want, $expectation.Says))
    }
}

# The shard's server rows are an array rather than a value, so they are read as
# one: the primary on 5432 and this machine's standby on 5431.
$shard = if ($sections.ContainsKey('pools.simple_db.shards.0')) { $sections['pools.simple_db.shards.0'] } else { '' }

if ($shard -notmatch '\[\s*"127\.0\.0\.1"\s*,\s*5432\s*,\s*"primary"\s*\]') {
    $drift.Add('pools.simple_db.shards.0 does not list 127.0.0.1:5432 as the primary')
}

if ($shard -notmatch '\[\s*"localhost"\s*,\s*5431\s*,\s*"replica"\s*\]') {
    $drift.Add('pools.simple_db.shards.0 does not list localhost:5431 as the replica — this machine''s standby')
}

if ($drift.Count -gt 0) {
    Exit-With 1 (@(
        "$configFile does not carry the rows this rig needs:"
        (($drift | ForEach-Object { "  - $_" }) -join "`n")
        ''
        'Edit that file, or restore it from the checkout it was copied out of. The hba rules below'
        'offer scram-sha-256 to simple_user on some_db, which is the pair the pool is checked for.'
    ) -join "`n")
}

Write-Host "The pool rows are the ones this rig needs: $poolerPort, the standby as the replica, simple_db split."

#
# 4. The two servers, in the order the standby depends on.
#

Write-Step "Starting the primary on $primaryPort and the standby on $standbyPort"

foreach ($step in @(
    @{ Script = 'start-primary.bat'; Name = 'primary'; Port = $primaryPort },
    @{ Script = 'start-replica.bat'; Name = 'standby'; Port = $standbyPort }
)) {
    $batch = Start-BatchFile (Join-Path $servers $step.Script) $step.Name
    $ready = Wait-ForPort $step.Port 'postgres' 60

    # The starter's own report — already up, or the role it read back — is printed
    # whichever way readiness went: on failure it is the first thing to read.
    Show-BatchOutput $batch

    if (-not $ready) {
        Exit-With 1 "Nothing answers on $($step.Port) after 60 seconds, so $($step.Script) did not bring the $($step.Name) up. The server's log is beside its data directory, and scripts/servers-startup/README.md section 2 names what a start fails on."
    }
}

#
# 5. The hba rules, against the pair that is now live.
#

Write-Step 'The pg_hba.conf rules pgcat needs'

$status = Invoke-PowerShellScript (Join-Path $servers 'apply-hba-scram.ps1')

if ($status -ne 0) {
    Exit-With 1 "apply-hba-scram.ps1 exited $status; the server connection the pooler needs is not offered."
}

#
# 6. The patched crates and the build.
#

Write-Step 'The pooler binary'

if (Test-Listening $poolerPort) {
    # cargo's last step replaces the binary, and Windows will not let it while a
    # pooler started from that file is running. Skipping is the honest answer;
    # the alternative is a build that fails on a file-lock error nobody can act on.
    Write-Host "The pooler is already listening on $poolerPort, so its binary is in use and cannot be rebuilt."
    Write-Host 'Stop it with stop-pgcat.bat and run this again when a rebuild is what is wanted.'
} else {
    $status = Invoke-PowerShellScript (Join-Path $servers 'build-pgcat.ps1')

    if ($status -ne 0) {
        Exit-With 1 "build-pgcat.ps1 exited $status; there is no binary to start."
    }
}

#
# 7. The pooler, last, and its own report.
#

Write-Step "Starting the pooler on $poolerPort"

$batch = Start-BatchFile (Join-Path $servers 'start-pgcat.bat') 'pgcat'
$ready = Wait-ForPort $poolerPort 'admin_user' 60

Show-BatchOutput $batch

if (-not $ready) {
    Exit-With 1 "Nothing answers on $poolerPort after 60 seconds, so the pooler is not serving. Its window holds the log, and the usual causes are a server that is down or a pg_hba.conf offering only the cleartext password pgcat refuses."
}

#
# 8. The routing, proved through the pooler rather than read off the config: a read
#    that must be answered by the standby and a write that must be answered by the
#    primary, both asked by a client that knows nothing about roles.
#

Write-Step "Proving the routing on $poolerPort"

# The write is one transaction that leaves nothing behind — a temp table and a row in
# it, rolled back. What makes it a write is not the table's lifetime: the pooler's
# parser sends anything that is not a read to the primary, and a standby refuses it as
# read-only. The `returning` names the server that answered, which is the question.
$readSql = 'select inet_server_port(), pg_is_in_recovery()'
$writeSql = 'begin; create temp table setup_routing_probe (note text); ' +
    "insert into setup_routing_probe values ('setup-pgcat') returning inet_server_port(), pg_is_in_recovery(); rollback;"

# The pool's own user and database rather than the superuser and the shard's database:
# the chain being proved is the one a client walks, and its first link is the password
# the pool is configured with. pgpass has no line for it — the pooler is the client
# there, and it reads that password out of pgcat.toml.
$previousPassword = $env:PGPASSWORD

try {
    $env:PGPASSWORD = 'simple_user'

    $read = Invoke-Psql $poolerPort 'simple_db' 'simple_user' $readSql
    $write = Invoke-Psql $poolerPort 'simple_db' 'simple_user' $writeSql
}
finally {
    if ($null -eq $previousPassword) {
        Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    } else {
        $env:PGPASSWORD = $previousPassword
    }
}

$readAnswer = Get-RouteAnswer $read.Output
$writeAnswer = Get-RouteAnswer $write.Output
$readDetail = (($read.Output -split "`n") | Where-Object { $_ -ne '' } | ForEach-Object { "    $_" }) -join "`n"
$writeDetail = (($write.Output -split "`n") | Where-Object { $_ -ne '' } | ForEach-Object { "    $_" }) -join "`n"

if ($read.Exit -ne 0 -or $null -eq $readAnswer) {
    Exit-With 1 (@(
        'The read through the pooler did not answer, so the chain is broken at its first link:'
        ("  $readSql")
        $readDetail
        ''
        'scripts/servers-startup/README.md section 6 has the same query on its own, and stop-pgcat.bat'
        'followed by start-pgcat.bat is how to put the pooler back in a state worth asking.'
    ) -join "`n")
}

if ($readAnswer.Port -ne $standbyPort -or -not $readAnswer.InRecovery) {
    $answeredBy = if ($readAnswer.InRecovery) { "a standby on $($readAnswer.Port)" } else { "a primary on $($readAnswer.Port)" }

    Exit-With 1 (@(
        "A read through the pooler was answered by $answeredBy, not the standby on $standbyPort."
        ''
        'query_parser_read_write_splitting = true and primary_reads_enabled = false under'
        '[pools.simple_db] are what keep a SELECT off the primary, and the shard rows are what'
        'name the standby; a read answered by the primary means one of those rows has moved.'
    ) -join "`n")
}

if ($write.Exit -ne 0 -or $null -eq $writeAnswer) {
    Exit-With 1 (@(
        'The write through the pooler did not answer, so nothing proves it reached the primary:'
        ("  $writeSql")
        $writeDetail
        ''
        'A standby refuses a write as "cannot execute ... in a read-only transaction", which is the'
        'write having reached one; any other error is the shard''s own connection to the primary, whose'
        'two server checks above have already reported on.'
    ) -join "`n")
}

if ($writeAnswer.Port -ne $primaryPort -or $writeAnswer.InRecovery) {
    $answeredBy = if ($writeAnswer.InRecovery) { "a standby on $($writeAnswer.Port)" } else { "a server on $($writeAnswer.Port)" }

    Exit-With 1 (@(
        "A write through the pooler was answered by $answeredBy, not the primary on $primaryPort."
        'A write only ever goes to the primary; a shard row that names another server first is'
        'what this reports.'
    ) -join "`n")
}

Write-Host ("  read     answered on {0}, in recovery - the standby" -f $readAnswer.Port)
Write-Host ("  write    answered on {0}, not in recovery - the primary" -f $writeAnswer.Port)

Write-Host ''
Write-Host 'The rig is up:'
Write-Host ("  primary   127.0.0.1:{0}" -f $primaryPort)
Write-Host ("  standby   127.0.0.1:{0}" -f $standbyPort)
Write-Host ("  pooler    127.0.0.1:{0}" -f $poolerPort)
Write-Host ''
Write-Host 'A read through the pooler answered on the standby and a write answered on the primary,'
Write-Host 'so the pool is routing the way it is configured to; scripts/servers-startup/README.md'
Write-Host 'section 6 has the two queries for a by-hand check.'
Write-Host 'To take it down in the reverse order: stop-pgcat.bat, stop-replica.bat, stop-primary.bat.'

exit 0

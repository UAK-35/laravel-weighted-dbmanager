#requires -Version 5.1
<#
    Give the pooler an authentication method it speaks, on both servers.

    Why this is needed: the cluster's pg_hba.conf asks every local connection for
    a cleartext password, and pgcat — when it is the client — answers only md5,
    SASL and ok. A pool connection therefore dies with

        ERROR pgcat::server: Unsupported authentication mechanism: 3

    and the pooler exits with `Pool error: ServerError`. The repair is two host
    rules, above the `password` ones, that offer scram-sha-256 to the pool user on
    the pool's database. The cluster stores its passwords as scram-sha-256
    (password_encryption is the PostgreSQL default), so nothing about a password
    changes.

    The script is idempotent: a file that already names the user is left alone,
    and the reload is a query rather than a restart. Each file is backed up once,
    beside itself as pg_hba.conf.before-pgcat, before it is written.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$machineFile = Join-Path $here 'servers.local.cmd'

# The pool user and database the rules name. They are the shipped pgcat sample's
# `simple_db` row — the pool this folder's pgcat.toml routes through.
$poolDatabase = 'some_db'
$poolUser = 'simple_user'

function Exit-With([int] $code, [string] $message) {
    [Console]::Error.WriteLine($message)
    exit $code
}

if (-not (Test-Path -LiteralPath $machineFile)) {
    Exit-With 2 "servers.local.cmd is missing from `"$here`". Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT."
}

$settings = @{}

foreach ($line in Get-Content -LiteralPath $machineFile) {
    if ($line -match '^\s*set\s+"([A-Za-z_][A-Za-z0-9_]*)=(.*)"\s*$') {
        $settings[$Matches[1]] = $Matches[2]
    }
}

$pgRoot = $settings['PG_ROOT']

if (-not $pgRoot) {
    Exit-With 2 'PG_ROOT is not set in servers.local.cmd.'
}

$psql = Join-Path $pgRoot 'pgsql179\bin\psql.exe'

if (-not (Test-Path -LiteralPath $psql)) {
    Exit-With 1 "No psql at `"$psql`" — PG_ROOT does not look like the PostgreSQL pair's directory."
}

$files = @(
    (Join-Path $pgRoot 'pgsql179\data\pg_hba.conf'),
    (Join-Path $pgRoot 'standby179\pg_hba.conf')
)

$rules = @(
    "# pgcat authenticates to the servers with SCRAM, so this pool user is offered it here.",
    ("host    {0}   {1}   127.0.0.1/32   scram-sha-256" -f $poolDatabase, $poolUser),
    ("host    {0}   {1}   ::1/128        scram-sha-256" -f $poolDatabase, $poolUser)
)

foreach ($file in $files) {
    if (-not (Test-Path -LiteralPath $file)) {
        Exit-With 1 "No pg_hba.conf at `"$file`" — is the standby's data directory really beside pgsql179?"
    }

    $text = Get-Content -LiteralPath $file -Raw

    # The pool user is the mark: a file that already offers it is already done, and
    # a second copy of the rules would only be read after the first, where it could
    # not change an answer.
    # `(?m)` because the file is one string and the rule is one of its lines; without
    # it `^` only ever means the start of the whole file, and every run would add the
    # rules again.
    if ($text -match ("(?m)^\s*host\s+" + [regex]::Escape($poolDatabase) + "\s+" + [regex]::Escape($poolUser) + "\s")) {
        Write-Host "$file already offers scram-sha-256 to $poolUser on $poolDatabase."
        continue
    }

    $backup = $file + '.before-pgcat'

    if (-not (Test-Path -LiteralPath $backup)) {
        Copy-Item -LiteralPath $file -Destination $backup
        Write-Host "Backed up to $backup"
    }

    # Above the first `host` rule, because pg_hba.conf is read top to bottom and the
    # first match decides: below the `password` rules these lines would never be read.
    $lines = [System.Collections.Generic.List[string]] (Get-Content -LiteralPath $file)
    $at = -1

    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^host\s') { $at = $i; break }
    }

    if ($at -lt 0) {
        $at = $lines.Count
    }

    for ($i = $rules.Count - 1; $i -ge 0; $i--) {
        $lines.Insert($at, $rules[$i])
    }

    Set-Content -LiteralPath $file -Value $lines
    Write-Host "Added the two scram-sha-256 rules to $file."
}

# pg_reload_conf() re-reads the file without a restart. The password comes from
# pgpass, so -w keeps a missing entry an error rather than a prompt.
foreach ($port in @(5432, 5431)) {
    & $psql -w -h localhost -p $port -U postgres -d postgres -At -c 'select pg_reload_conf()'

    if ($LASTEXITCODE -ne 0) {
        Exit-With 1 "pg_reload_conf() failed on port $port; the rules are written but not live there."
    }
}

Write-Host 'Both servers reloaded.'
exit 0

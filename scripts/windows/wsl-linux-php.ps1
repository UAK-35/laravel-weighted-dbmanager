#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Fetch a static Linux PHP CLI on the Windows side and run Laravel through it from inside WSL.

.DESCRIPTION
    This lives at `scripts/windows/`, and `docs/wsl-linux-php.md` is the runbook for it - what
    was measured, the switches, and what it deliberately does not give you. It was first written one
    level deeper, in `external/`, where both roots below resolved a level *above* the repository
    root; one level up from `scripts/windows/` is the root, which is the whole of why it belongs
    here.

    `ConcurrencyHelpers::canFork()` is false on Windows, so the fork branch of
    `runConcurrently()` - the one `spatie/fork` gives a pcntl host - cannot run here at all.
    Proving it, and anything else that needs a POSIX PHP with pcntl, needs a Linux interpreter
    looking at the same tree. This script is how that interpreter is made and used.

    Two facts about this machine shape it, and both were measured rather than assumed:

      * the WSL distribution has no PHP, and no egress either (curl to github.com and to
        deb.debian.org both time out), so nothing can be installed inside it and nothing can be
        downloaded from inside it;
      * Windows has the network, and a WSL distribution can read a Windows directory at
        /mnt/<drive>/... .

    So the download happens here, into %TEMP%\lpr\linux-php, and the run happens through the
    mount. Nothing is installed into the distribution, nothing needs root, and the only thing
    written is that one temporary directory - re-running the script reuses it.

    The build is static-php-cli's prebuilt CLI. `common` (about 12 MB) is the default and is the
    set this repository's probes were measured on; `bulk` (about 31 MB) carries the extensions
    `composer.json` declares but `common` omits - brotli, lz4, memcached, protobuf, zstd. The
    status block says which of the manifest's `ext-*` entries the build actually has, so the
    difference is read rather than remembered.

    What this does NOT give you: PostgreSQL. `phpunit.xml` selects the `pgsql_test` connection,
    so the Feature suite still needs the database `docs/TODO-database-tests.md` describes. What it
    does give you is `tests/Unit`, and every probe that needs pcntl, sockets or posix.

.PARAMETER Setup
    Ensure the build is there and print the status. This is the default action.

.PARAMETER Check
    Print the status and judge it, downloading nothing. Exits 1 when the build is missing or
    cannot run the code paths this repository needs.

.PARAMETER Run
    A bash line to run inside WSL, in the application root, with the Linux PHP first on PATH.
    Because the PATH is exported, `php`, `composer` (through the record) and
    `php vendor/bin/pest` all resolve without naming the binary again. Exit status is the
    command's own.

.PARAMETER Distro
    The WSL distribution to use. Empty means the machine's default distribution, which is the
    one `wsl.exe` runs when no -d is given.

.PARAMETER Preset
    `common` (default) or `bulk`. See the description - the status block names what is missing.

.PARAMETER PhpVersion
    The static-php-cli patch build to fetch. Patch-numbered, because that is how static-php-cli
    publishes; it is pinned here rather than floated so two runs a month apart are the same
    interpreter.

.PARAMETER Arch
    `x86_64` (default) or `aarch64`.

.PARAMETER InstallDir
    Where the binary and its tarball live. Defaults to %TEMP%\lpr\linux-php.

.PARAMETER Force
    Download again even when a usable build is already there.

.EXAMPLE
    pwsh -File .\scripts\windows\wsl-linux-php.ps1

.EXAMPLE
    pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php -v'

.EXAMPLE
    pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php vendor/bin/pest tests/Unit'

.EXAMPLE
    pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Check
#>
[CmdletBinding()]
param(
    [switch] $Setup,
    [switch] $Check,
    [string] $Run,
    [string] $Distro,
    [ValidateSet('common', 'bulk')]
    [string] $Preset = 'common',
    [string] $PhpVersion = '8.4.23',
    [ValidateSet('x86_64', 'aarch64')]
    [string] $Arch = 'x86_64',
    [string] $InstallDir,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# --- where things are --------------------------------------------------------
# Both roots are derived from this file's own location. The directory this script writes to is
# the machine's temp directory, spelled as an environment variable, so no drive letter is ever
# written down here (the `machine-paths` gate refuses one).
$appRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composerJson = Join-Path $appRoot 'composer.json'

if (-not $InstallDir) {
    $InstallDir = Join-Path $env:TEMP 'lpr\linux-php'
}
$InstallDir = [System.IO.Path]::GetFullPath($InstallDir)

$tarball = Join-Path $InstallDir ("php-{0}-cli-linux-{1}.tar.gz" -f $PhpVersion, $Arch)
$phpPath = Join-Path $InstallDir 'php'
$provenance = Join-Path $InstallDir 'SOURCE.txt'

# The extensions this repository's own code paths need beyond what the manifest happens to
# declare, each for a reason that would show up as a failure rather than a slower run:
#   pdo_sqlite / sqlite3     phpunit.xml can be pointed at the sqlite connection
#   pdo_pgsql / pgsql        phpunit.xml's default connection is pgsql_test
#   pcntl / posix / sockets  spatie/fork and Octane's ServerProcessInspector
#   openssl, fileinfo, ctype, session, phar, filter, iconv, dom, simplexml
#                            Laravel boot, Composer's own bootstrap, and the XML the suites parse
$runtimeExtensions = @(
    'pdo_sqlite', 'sqlite3', 'pdo_pgsql', 'pgsql',
    'pcntl', 'posix', 'sockets',
    'openssl', 'fileinfo', 'ctype', 'session', 'phar', 'filter', 'iconv', 'dom', 'simplexml'
)

function Write-Step {
    param([string] $Tag, [string] $Name, [string] $Text)
    Write-Host ('[{0}] {1,-12} {2}' -f $Tag, $Name, $Text)
}

function Stop-WithError {
    param([string] $Name, [string] $Message, [int] $Code = 2)
    Write-Step 'FAIL' $Name $Message
    exit $Code
}

# -Check is the read-only half and -Setup is the writing one; asking for both is a typo rather
# than a request, because the writing half would then decide what the read-only one reports.
if ($Check -and $Setup) {
    Stop-WithError 'args' '-Check judges without downloading and -Setup fetches; pass one of them'
}

# --- the WSL side ------------------------------------------------------------
# One argv prefix, so every call goes to the same distribution: the named one, or the default.
$wslArgs = @()
if ($Distro) {
    $wslArgs = @('-d', $Distro)
}

function Invoke-WslText {
    param([Parameter(Mandatory)][string] $Line)
    $argv = $wslArgs + @('-e', 'bash', '-lc', $Line)
    $output = & wsl.exe @argv 2>&1
    # Anything wsl.exe itself says - "There is no distribution with the supplied name" - arrives
    # as UTF-16, which reads as one NUL between every character once PowerShell has decoded it as
    # UTF-8. The bash output is UTF-8 and carries no NUL, so dropping them fixes the one without
    # touching the other.
    return [pscustomobject]@{
        Output = ((($output | Out-String) -replace "`0", '').TrimEnd())
        Code   = $LASTEXITCODE
    }
}

function ConvertTo-WslPath {
    param([Parameter(Mandatory)][string] $WindowsPath)
    # wslpath is the distribution's own answer to "where is this Windows path", so the mount
    # prefix is never guessed here.
    $quoted = "'" + ($WindowsPath -replace "'", "'\''") + "'"
    $result = Invoke-WslText ("wslpath -a " + $quoted)
    if ($result.Code -ne 0 -or -not $result.Output) {
        Stop-WithError 'mount' ("cannot resolve {0} inside WSL: {1}" -f $WindowsPath, $result.Output)
    }
    return $result.Output.Trim()
}

function Quote-Bash {
    param([string] $Text)
    return "'" + ($Text -replace "'", "'\''") + "'"
}

# --- WSL itself --------------------------------------------------------------
if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    Stop-WithError 'wsl' 'wsl.exe is not on PATH - install WSL2 and a Linux distribution; docs/wsl-linux-php.md says what the run needs.'
}

$release = Invoke-WslText ('echo "$WSL_DISTRO_NAME|$(uname -r)|$(. /etc/os-release; echo $PRETTY_NAME)"')
if ($release.Code -ne 0) {
    $wanted = if ($Distro) { "the '$Distro' distribution" } else { 'the default distribution' }
    Stop-WithError 'wsl' ("cannot start {0}: {1}" -f $wanted, $release.Output)
}
$distroName, $kernel, $prettyName = $release.Output.Split([char] '|', 3)
$linuxInstall = ConvertTo-WslPath $InstallDir

# --- the build ---------------------------------------------------------------
$url = 'https://dl.static-php.dev/static-php-cli/{0}/php-{1}-cli-linux-{2}.tar.gz' -f $Preset, $PhpVersion, $Arch

function Test-InstalledBuild {
    # A cached binary is only reused when it is the version asked for and still runs. Nothing
    # else about it is remembered between runs: `php -v` is the authority.
    if (-not (Test-Path $phpPath)) {
        return $false
    }
    $probe = Invoke-WslText ((ConvertTo-WslPath $phpPath) + ' -v')
    if ($probe.Code -ne 0) {
        return $false
    }
    return ($probe.Output -match ('PHP ' + [regex]::Escape($PhpVersion) + '\b'))
}

function Install-Build {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null

    if ((Test-Path $tarball) -and -not $Force) {
        Write-Step ' .. ' 'download' ("reusing {0}" -f $tarball)
    }
    else {
        Write-Step ' .. ' 'download' $url
        $started = Get-Date
        try {
            Invoke-WebRequest -Uri $url -OutFile $tarball
        }
        catch {
            Stop-WithError 'download' ("{0} - the Windows side needs the network; this is the half WSL cannot do" -f $_.Exception.Message)
        }
        $seconds = [math]::Round(((Get-Date) - $started).TotalSeconds, 1)
        $size = [math]::Round((Get-Item $tarball).Length / 1MB, 1)
        Write-Step ' OK ' 'download' ("{0} MB in {1} s" -f $size, $seconds)
    }

    # Extracted by the distribution's own tar, from the mount. The archive holds one file, `php`,
    # and the mode it carries is the one it gets - which is why the chmod below is a guard rather
    # than a fix. Windows' tar is deliberately not used: `tar.exe` on PATH can be Git's GNU tar,
    # which reads a drive-lettered path as a remote host and fails with "Cannot connect to C".
    $extract = Invoke-WslText ('tar -xzf {0} -C {1} php' -f (Quote-Bash (ConvertTo-WslPath $tarball)), (Quote-Bash $linuxInstall))
    if ($extract.Code -ne 0 -or -not (Test-Path $phpPath)) {
        Stop-WithError 'extract' ('{0} did not yield a php binary: {1}' -f $tarball, $extract.Output)
    }

    $chmod = Invoke-WslText ('chmod +x ' + (Quote-Bash ($linuxInstall + '/php')))
    if ($chmod.Code -ne 0) {
        Stop-WithError 'extract' 'the extracted binary is not executable inside WSL'
    }

    # No checksum is published beside these builds, so the hash of what was downloaded is
    # recorded next to it: a later run can then tell a same-version rebuild from the one this
    # machine measured, which is the only integrity claim the source allows.
    $hash = (Get-FileHash -Algorithm SHA256 -Path $tarball).Hash.ToLower()
    $record = @(
        "url      $url",
        "sha256   $hash",
        "bytes    $((Get-Item $tarball).Length)",
        "preset   $Preset",
        "version  $PhpVersion",
        "arch     $Arch",
        "fetched  $((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))"
    )
    Set-Content -Path $provenance -Value $record -Encoding utf8
    Write-Step ' OK ' 'extract' ("{0} ({1} bytes, sha256 {2}...)" -f $phpPath, (Get-Item $phpPath).Length, $hash.Substring(0, 12))
}

# --- what the manifest asks for ---------------------------------------------
$manifest = Get-Content -Raw $composerJson | ConvertFrom-Json
$declared = @(
    $manifest.require.PSObject.Properties.Name |
        Where-Object { $_ -like 'ext-*' } |
        ForEach-Object { $_.Substring(4).ToLower() }
)
$constraint = [string] $manifest.require.php

# --- run ---------------------------------------------------------------------
$installed = Test-InstalledBuild

if ($Check -and -not $installed) {
    Write-Step 'FAIL' 'binary' ("nothing usable at {0} - run this script with -Setup" -f $InstallDir)
    exit 1
}

if (-not $installed) {
    Install-Build
}
else {
    Write-Step ' OK ' 'binary' ("reusing {0}" -f $phpPath)
}

$linuxPhp = $linuxInstall + '/php'
$linuxApp = ConvertTo-WslPath $appRoot

$version = Invoke-WslText ($linuxPhp + ' -v')
if ($version.Code -ne 0) {
    Stop-WithError 'binary' ("{0} does not run inside WSL: {1}" -f $linuxPhp, $version.Output)
}
$phpVersion = ($version.Output -split "`n")[0].Trim()
$builder = (($version.Output -split "`n") | Where-Object { $_ -match 'static-php-cli' } | Select-Object -First 1)

$modules = Invoke-WslText ($linuxPhp + ' -m')
if ($modules.Code -ne 0) {
    Stop-WithError 'binary' ("{0} -m failed" -f $linuxPhp)
}
$loaded = @(
    $modules.Output -split "`n" |
        ForEach-Object { $_.Trim().ToLower() } |
        Where-Object { $_ -and -not $_.StartsWith('[') }
)

$bits = Invoke-WslText ($linuxPhp + " -r 'echo PHP_INT_SIZE * 8;'")
$missingDeclared = @($declared | Where-Object { $loaded -notcontains $_ })
$missingRuntime = @($runtimeExtensions | Where-Object { $loaded -notcontains $_ })

# The version is judged against the constraint composer.json states, rather than against the
# patch build this script pins: the pin says which download, the constraint says what is legal.
$versionProblem = $null
if ($constraint -match '(\d+)\.(\d+)') {
    $wantMajor = [int] $Matches[1]
    $wantMinor = [int] $Matches[2]
    $haveVersion = ($version.Output -split "`n")[0]
    if ($haveVersion -match 'PHP (\d+)\.(\d+)\.') {
        $haveMajor = [int] $Matches[1]
        $haveMinor = [int] $Matches[2]
        if ($haveMajor -lt $wantMajor -or ($haveMajor -eq $wantMajor -and $haveMinor -lt $wantMinor)) {
            $versionProblem = 'PHP {0}.{1} is below composer.json''s "{2}"' -f $haveMajor, $haveMinor, $constraint
        }
    }
    else {
        $versionProblem = 'cannot read a version out of: ' + $haveVersion
    }
}

# --- the report --------------------------------------------------------------
Write-Step ' OK ' 'distro' ("{0} (WSL name {1}), kernel {2}" -f $prettyName, $distroName, $kernel)
Write-Step ' OK ' 'mount' ("{0} -> {1}" -f $InstallDir, $linuxInstall)
Write-Step ' OK ' 'php' ("{0}; {1} extensions; {2}-bit" -f $phpVersion, $loaded.Count, $bits.Output.Trim())
if ($builder) {
    Write-Step ' .. ' 'build' $builder.Trim()
}
Write-Step ' OK ' 'app root' $linuxApp

$problems = 0

if ($missingRuntime.Count -gt 0) {
    $problems++
    Write-Step 'FAIL' 'runtime ext' ('missing: ' + ($missingRuntime -join ', '))
}
else {
    Write-Step ' OK ' 'runtime ext' ("all {0} the code paths need are loaded" -f $runtimeExtensions.Count)
}

if ($missingDeclared.Count -gt 0) {
    # A warning, not a failure: phpunit.xml overrides the cache and session drivers, so a suite
    # does not reach for memcached, and the response-compression middlewares load brotli/zstd
    # only on the request path. `bulk` is what closes this list.
    Write-Step ' !! ' 'manifest ext' ('absent from the {0} preset: {1} (-Preset bulk carries them)' -f $Preset, ($missingDeclared -join ', '))
}
else {
    Write-Step ' OK ' 'manifest ext' ("every ext-* composer.json declares is loaded ({0})" -f $declared.Count)
}

if ($versionProblem) {
    $problems++
    Write-Step 'FAIL' 'version' $versionProblem
}
else {
    Write-Step ' OK ' 'version' ("satisfies composer.json's {0}" -f $constraint)
}

if (Test-Path $provenance) {
    $source = (Get-Content -Path $provenance | Where-Object { $_ -like 'url*' } | Select-Object -First 1)
    Write-Step ' .. ' 'source' $source
}

if ($Run) {
    $line = 'export PATH={0}:"$PATH"; cd {1} && {2}' -f (Quote-Bash $linuxInstall), (Quote-Bash $linuxApp), $Run
    Write-Host ''
    Write-Step ' .. ' 'run' $Run
    Write-Host ''
    $argv = $wslArgs + @('-e', 'bash', '-lc', $line)
    & wsl.exe @argv
    exit $LASTEXITCODE
}

if ($Check -and $problems -gt 0) {
    exit 1
}

Write-Host ''
Write-Host ("Next:  pwsh -File .\scripts\windows\wsl-linux-php.ps1 -Run 'php vendor/bin/pest tests/Unit'")
exit 0

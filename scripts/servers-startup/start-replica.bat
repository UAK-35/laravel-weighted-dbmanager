@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Start the local standby PostgreSQL server, port 5431.
rem
rem Its data directory is its own copy of the primary's, cloned with
rem pg_basebackup, and its postgresql.conf sets port = 5431, so starting it
rem cannot collide with the primary on 5432. It is not a Windows service:
rem pg_ctl starts it as a detached child, which keeps running after this window
rem closes but does not come back after a reboot.
rem
rem The directories come from servers.local.cmd beside this script (gitignored,
rem copied from servers.sample.cmd): PG_ROOT holds pgsql179\bin and standby179\.
rem
rem pg_ctl's own output goes to a temporary file rather than to this console: the
rem postmaster inherits whatever handles pg_ctl was given, and a console handle
rem that stays open keeps a redirect of this script from ever finishing.
rem ---------------------------------------------------------------------------

if not exist "%~dp0servers.local.cmd" (
    echo servers.local.cmd is missing from "%~dp0".
    echo Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT.
    exit /b 2
)

call "%~dp0servers.local.cmd"

set "PG_BIN=%PG_ROOT%\pgsql179\bin"
set "STANDBY=%PG_ROOT%\standby179"
set "PGLOG=%PG_ROOT%\standby179.log"
set "PGCTL_OUT=%TEMP%\pg_ctl-start-standby.log"

"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5431 -U postgres >nul 2>&1
if not errorlevel 1 goto :running

echo Starting the standby on port 5431 from "%STANDBY%"...
"%PG_BIN%\pg_ctl.exe" -D "%STANDBY%" -l "%PGLOG%" start > "%PGCTL_OUT%" 2>&1 <nul
if errorlevel 1 (
    echo.
    echo pg_ctl could not start the standby. It said:
    type "%PGCTL_OUT%"
    echo.
    echo The server log is "%PGLOG%".
    exit /b 1
)

goto :status

:running
echo The standby is already accepting connections on port 5431.

:status
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5431 -U postgres
if errorlevel 1 (
    echo.
    echo Nothing answers on port 5431. Read the log:
    echo   "%PGLOG%"
    exit /b 1
)

rem pg_isready answers for a server that accepts connections, not for one that is
rem still a standby, so the server itself is asked. pgpass already holds a line
rem for 127.0.0.1:5431, which is what lets psql run without a password prompt.
"%PG_BIN%\psql.exe" -w -h 127.0.0.1 -p 5431 -U postgres -d postgres -At -c "select case when pg_is_in_recovery() then 'in recovery, this is a standby' else 'not in recovery, this is a primary' end" 2>nul
if errorlevel 1 echo Role not read: no pgpass entry for 127.0.0.1:5431, or psql cannot reach the server.

exit /b 0

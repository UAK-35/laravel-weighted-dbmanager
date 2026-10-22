@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Start the local primary PostgreSQL server, port 5432.
rem
rem The directories come from servers.local.cmd beside this script (gitignored,
rem copied from servers.sample.cmd): PG_ROOT holds pgsql179\bin, pgsql179\data
rem and standby179\. The server is that portable build, started with pg_ctl on
rem its own data directory — deliberately not through the Windows service
rem postgresql-x64-17, which is registered against the installer's copy under
rem C:\Program Files\PostgreSQL\17 and would bring up a different cluster on the
rem same port.
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
set "PGDATA=%PG_ROOT%\pgsql179\data"
set "PGLOG=%PG_ROOT%\pgsql179.log"
set "PGCTL_OUT=%TEMP%\pg_ctl-start-primary.log"

"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5432 -U postgres >nul 2>&1
if not errorlevel 1 goto :running

sc query postgresql-x64-17 2>nul | findstr /c:"RUNNING" >nul
if not errorlevel 1 (
    echo The Windows service postgresql-x64-17 is running, and it serves the
    echo installer's data directory rather than this one. Stop it, then run this
    echo script again:
    echo   net stop postgresql-x64-17
    exit /b 2
)

echo Starting the primary on port 5432 from "%PGDATA%"...
"%PG_BIN%\pg_ctl.exe" -D "%PGDATA%" -l "%PGLOG%" start > "%PGCTL_OUT%" 2>&1 <nul
if errorlevel 1 (
    echo.
    echo pg_ctl could not start the primary. It said:
    type "%PGCTL_OUT%"
    echo.
    echo The server log is "%PGLOG%".
    exit /b 1
)

goto :status

:running
echo The primary is already accepting connections on port 5432.

:status
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5432 -U postgres
if errorlevel 1 (
    echo.
    echo Nothing answers on port 5432. Read the server log:
    echo   "%PGLOG%"
    exit /b 1
)

rem pgpass holds the entry for localhost:5432, which is what lets psql run
rem without a password prompt; -w makes a missing entry an error rather than a
rem prompt that would wait for input this script never sends.
"%PG_BIN%\psql.exe" -w -h localhost -p 5432 -U postgres -d postgres -At -c "select case when pg_is_in_recovery() then 'in recovery, this is a standby' else 'not in recovery, this is a primary' end" 2>nul
if errorlevel 1 echo Role not read: no pgpass entry for localhost:5432, or psql cannot reach the server.

exit /b 0

@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Stop the local primary PostgreSQL server, port 5432.
rem
rem The counterpart of start-primary.bat, and it reports the same three things
rem that script does: whether the server was up at all, what the server answered
rem when it was asked to stop, and whether the port it listened on is silent
rem afterwards. "Stopped" is a port that answers nothing rather than an exit code
rem — pg_ctl reports success for a postmaster that exited while a second one still
rem holds the port, which is a state this script refuses to call done.
rem
rem -m fast rather than -m immediate: clients are disconnected and a shutdown
rem checkpoint is taken, so the next start reads a data directory that needs no
rem recovery. immediate is for a postmaster that has stopped answering, which is
rem not what this script is asked to repair.
rem
rem The directories come from servers.local.cmd beside this script (gitignored,
rem copied from servers.sample.cmd): PG_ROOT holds pgsql179\.
rem
rem pg_ctl's own output goes to a temporary file rather than to this console, for
rem the reason start-primary.bat gives: the postmaster inherits whatever handles
rem pg_ctl was given, and a console handle that stays open keeps a redirect of
rem this script from ever finishing.
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
set "PGCTL_OUT=%TEMP%\pg_ctl-stop-primary.log"

"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5432 -U postgres >nul 2>&1
if errorlevel 1 (
    echo The primary is not answering on port 5432 before this run, so there is nothing to stop.
    goto :status
)

echo Stopping the primary on port 5432, from "%PGDATA%"...
"%PG_BIN%\pg_ctl.exe" -D "%PGDATA%" -m fast stop > "%PGCTL_OUT%" 2>&1 <nul
if errorlevel 1 (
    echo.
    echo pg_ctl could not stop the primary. It said:
    type "%PGCTL_OUT%"
    echo.
    echo The server log is "%PGLOG%".
    exit /b 1
)

:status
rem The readiness reading is the verdict: pg_isready answers zero for a server
rem that accepts connections and non-zero for one that does not, so the port is
rem asked rather than the exit code above trusted.
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5432 -U postgres
if not errorlevel 1 (
    echo.
    echo Something still answers on port 5432, so the primary did not stop.
    echo A second postmaster on the same port is the usual cause. Read the log:
    echo   "%PGLOG%"
    exit /b 1
)

echo The primary on port 5432 is stopped: nothing answers there.
echo Its log is "%PGLOG%".

exit /b 0

@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Stop the local standby PostgreSQL server, port 5431.
rem
rem The counterpart of start-replica.bat, and it reports the same three things
rem that script does: whether the standby was up at all, what the server answered
rem when it was asked to stop, and whether the port it listened on is silent
rem afterwards. "Stopped" is a port that answers nothing rather than an exit code,
rem which is why the port is asked again after pg_ctl returns.
rem
rem The standby is stopped before the primary — the reverse of the start order.
rem A standby left streaming from a primary that has gone down reports a failed
rem walreceiver on the next start and retries until the primary answers, so the
rem order is a shorter log rather than a correctness rule, and this script does
rem not enforce it: it stops the server it names and nothing else.
rem
rem -m fast rather than -m immediate: the walreceiver is disconnected and the
rem server exits cleanly, which is the shutdown a primary's next start reads
rem without recovery. It is also the point at which the standby's data directory
rem is safe to reuse for another clone.
rem
rem The directories come from servers.local.cmd beside this script (gitignored,
rem copied from servers.sample.cmd): PG_ROOT holds pgsql179\ and standby179\.
rem
rem pg_ctl's own output goes to a temporary file rather than to this console, for
rem the reason start-replica.bat gives: the postmaster inherits whatever handles
rem pg_ctl was given.
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
set "PGCTL_OUT=%TEMP%\pg_ctl-stop-standby.log"

"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5431 -U postgres >nul 2>&1
if errorlevel 1 (
    echo The standby is not answering on port 5431 before this run, so there is nothing to stop.
    goto :status
)

echo Stopping the standby on port 5431, from "%STANDBY%"...
"%PG_BIN%\pg_ctl.exe" -D "%STANDBY%" -m fast stop > "%PGCTL_OUT%" 2>&1 <nul
if errorlevel 1 (
    echo.
    echo pg_ctl could not stop the standby. It said:
    type "%PGCTL_OUT%"
    echo.
    echo The server log is "%PGLOG%".
    exit /b 1
)

:status
rem The readiness reading is the verdict: pg_isready answers zero for a server
rem that accepts connections and non-zero for one that does not, so the port is
rem asked rather than the exit code above trusted.
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5431 -U postgres
if not errorlevel 1 (
    echo.
    echo Something still answers on port 5431, so the standby did not stop.
    echo Read the log:
    echo   "%PGLOG%"
    exit /b 1
)

echo The standby on port 5431 is stopped: nothing answers there.
echo Its log is "%PGLOG%".

exit /b 0

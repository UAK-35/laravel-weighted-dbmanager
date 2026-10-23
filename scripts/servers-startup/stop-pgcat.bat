@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Stop the local pgcat pooler on 127.0.0.1:5433.
rem
rem WHY THIS IS NOT AN ADMIN COMMAND
rem   The pooler has a SHUTDOWN command on its admin database and this platform
rem   cannot use it: it sends SIGINT, Windows has no SIGINT to send, and the
rem   pooler answers `f` while it keeps running. start-pgcat.bat records the same
rem   fact from the other side. What is stopped here is therefore the process that
rem   owns the listening socket.
rem
rem WHY THE PORT NAMES THE PROCESS, AND WHAT IS CHECKED BEFORE IT IS CLOSED
rem   The PID comes out of netstat's line for 127.0.0.1:5433 — the socket this
rem   script is asked about — rather than from the image name: `taskkill /IM
rem   pgcat.exe` would take every pooler on the machine, including one started by
rem   hand against another config and reported by nothing here. The PID is then
rem   checked against tasklist, so a port held by some other program is refused
rem   with the program named instead of killed.
rem
rem WHAT "STOPPED" MEANS
rem   The listener, not the exit code. taskkill without /F asks the window to
rem   close, which is the same thing closing the pooler's minimised window does;
rem   /F is the fallback for a window that answers nothing, and the script says
rem   which one was needed. "Stopped" is netstat no longer listing the port and
rem   pg_isready no longer answering on it — a process that exited while a child
rem   kept the socket is the state this script refuses to call done.
rem
rem The servers are left running. Stopping the pooler does not stop the primary
rem or the standby, and the script stops nothing it was not asked about.
rem ---------------------------------------------------------------------------

if not exist "%~dp0servers.local.cmd" (
    echo servers.local.cmd is missing from "%~dp0".
    echo Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT.
    exit /b 2
)

call "%~dp0servers.local.cmd"

set "PG_BIN=%PG_ROOT%\pgsql179\bin"
set "LISTENER=%TEMP%\pgcat-listener.txt"
set "OWNER=%TEMP%\pgcat-owner.txt"

netstat -ano | findstr /r /c:"127.0.0.1:5433 .*LISTENING" > "%LISTENER%" 2>nul
if errorlevel 1 goto :not_running
for %%A in ("%LISTENER%") do if %%~zA==0 goto :not_running

set "PGCAT_PID="
for /f "tokens=5" %%P in ('type "%LISTENER%"') do set "PGCAT_PID=%%P"

if not defined PGCAT_PID goto :not_running

tasklist /FI "PID eq %PGCAT_PID%" /FO CSV /NH > "%OWNER%" 2>nul
findstr /i /c:"pgcat" "%OWNER%" >nul 2>&1
if errorlevel 1 (
    echo Port 5433 is held by a process that is not pgcat, so this script leaves it alone:
    type "%OWNER%"
    echo.
    echo If that is the pooler under another image name, stop it by hand:
    echo   taskkill /PID %PGCAT_PID%
    exit /b 1
)

echo The pooler is listening on 127.0.0.1:5433 as PID %PGCAT_PID%.
echo Asking PID %PGCAT_PID% to close...
taskkill /PID %PGCAT_PID% >nul 2>&1
call :wait 10
if not errorlevel 1 goto :stopped

echo.
echo The pooler did not close when it was asked, so PID %PGCAT_PID% is forced to exit...
taskkill /PID %PGCAT_PID% /F >nul 2>&1
call :wait 10
if not errorlevel 1 goto :stopped

echo.
echo Port 5433 is still listening after PID %PGCAT_PID% was killed, so the pooler did
echo not stop. The process holding the port is the one this line names:
netstat -ano | findstr /r /c:"127.0.0.1:5433 .*LISTENING"
exit /b 1

:stopped
echo.
echo The pooler is stopped: nothing listens on 127.0.0.1:5433.
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5433 -U admin_user
exit /b 0

:not_running
echo The pooler is not listening on 127.0.0.1:5433, so there is nothing to stop.
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5433 -U admin_user
exit /b 0

:wait
rem Polls the listener up to %1 times, 400 ms apart. errorlevel 0 means the port
rem went silent and the process is gone; 1 means it is still there.
set /a WAITED=0
:wait_loop
set /a WAITED+=1
powershell -NoProfile -Command "Start-Sleep -Milliseconds 400" >nul 2>&1
netstat -ano | findstr /r /c:"127.0.0.1:5433 .*LISTENING" >nul 2>&1
if errorlevel 1 exit /b 0
if %WAITED% LSS %1 goto :wait_loop
exit /b 1

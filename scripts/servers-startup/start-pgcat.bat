@echo off
setlocal EnableExtensions

rem ---------------------------------------------------------------------------
rem Start the local pgcat pooler on 127.0.0.1:5433, with the config beside this
rem script (pgcat.toml) rather than the checkout's.
rem
rem The pooler runs in its own minimised window, and that window is its log:
rem closing it stops the pooler. It is started through Start-Process without any
rem redirection on purpose — a child that inherits this console's handles keeps the
rem shell that ran this script waiting until the pooler exits, and Start-Process
rem with -RedirectStandardOutput waits for the process it started. To stop it
rem without the window, take the PID from the 5433 line of
rem `netstat -ano ^| findstr 5433` and run `taskkill /PID <pid> /F`. Its admin
rem SHUTDOWN command is not a way to stop it on Windows — that command sends SIGINT,
rem which this platform does not have, so it answers `f` instead.
rem
rem The servers must already be up (start-primary.bat, start-replica.bat): a pool
rem whose servers do not answer is reported and the pooler exits.
rem ---------------------------------------------------------------------------

if not exist "%~dp0servers.local.cmd" (
    echo servers.local.cmd is missing from "%~dp0".
    echo Copy servers.sample.cmd to that name and fill in PG_ROOT and PGCAT_ROOT.
    exit /b 2
)

call "%~dp0servers.local.cmd"

set "PG_BIN=%PG_ROOT%\pgsql179\bin"
set "PGCAT_EXE=%PGCAT_ROOT%\target\debug\pgcat.exe"
set "PGCAT_CFG=%~dp0pgcat.toml"

if not exist "%PGCAT_EXE%" (
    echo No pooler at "%PGCAT_EXE%".
    echo Build it first, from this folder:
    echo   pwsh -File "%~dp0build-pgcat.ps1"
    exit /b 1
)

if not exist "%PGCAT_CFG%" (
    echo No config at "%PGCAT_CFG%".
    exit /b 1
)

rem The listener is what says whether the pooler is up: a pgcat that lost its
rem servers exits on its own, so a process that exists is not a pooler that works.
"%PG_BIN%\pg_isready.exe" -h 127.0.0.1 -p 5433 -U admin_user >nul 2>&1
netstat -ano | findstr /r /c:"127.0.0.1:5433 .*LISTENING" >nul 2>&1
if not errorlevel 1 goto :running

echo Starting the pooler on 127.0.0.1:5433 with "%PGCAT_CFG%"...
powershell -NoProfile -Command "Start-Process -FilePath '%PGCAT_EXE%' -ArgumentList '%PGCAT_CFG%' -WorkingDirectory '%PGCAT_ROOT%' -WindowStyle Minimized"

rem The pooler validates one connection per server before it serves clients, so a
rem few seconds pass between the window opening and the port answering.
set /a TRIES=0

:wait
set /a TRIES+=1
powershell -NoProfile -Command "Start-Sleep -Milliseconds 800"
netstat -ano | findstr /r /c:"127.0.0.1:5433 .*LISTENING" >nul 2>&1
if not errorlevel 1 goto :running
if %TRIES% LSS 15 goto :wait

echo Nothing listens on 5433 after %TRIES% seconds. The pooler's window is
echo minimised in the taskbar and holds its log; the usual causes are a server that
echo is down, or a pg_hba.conf
echo that offers only the cleartext password pgcat refuses (apply-hba-scram.ps1).
exit /b 1

:running
powershell -NoProfile -Command "Start-Sleep -Milliseconds 800"
echo The pooler is listening on 127.0.0.1:5433.

rem The admin database is the pooler's own answer, so it is asked rather than
rem inferred from the port: the credentials are the config's sample admin user.
set "PGPASSWORD=admin_pass"
"%PG_BIN%\psql.exe" -w -h 127.0.0.1 -p 5433 -U admin_user -d pgcat -c "SHOW POOLS" 2>&1
set "PGPASSWORD="

exit /b 0

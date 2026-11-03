@echo off
setlocal EnableExtensions

rem Stops the Puppeteer server that start_puppeteer.bat started, using the PID in data\puppeteer.pid.
rem Before stopping, it checks that the PID is still a node process running puppeteer_server.js.
rem If that check fails, nothing is stopped. No process is ever found by port or by name.
rem Set WCRAWL_NOPAUSE=1 to skip the final "Press any key" prompt (used for automated checks).

set "ROOT=%~dp0"
set "PID_FILE=%ROOT%data\puppeteer.pid"

echo ========================================
echo  Puppeteer Server Stopper
echo ========================================
echo.

if not exist "%PID_FILE%" (
    echo No PID file at %PID_FILE%. Nothing to stop.
    goto :done_ok
)

set "PID="
set /p PID=<"%PID_FILE%"
echo %PID%| findstr /R "^[0-9][0-9]*$" >nul 2>&1
if errorlevel 1 (
    echo [REFUSED] The PID file does not contain a process id: %PID_FILE%
    goto :done_fail
)

rem Verify ownership: the process must still exist and be running puppeteer_server.js.
rem Exit codes: 0 = verified, 2 = different process, 3 = no such process.
powershell -NoProfile -Command "$p = Get-CimInstance Win32_Process -Filter 'ProcessId=%PID%'; if (-not $p) { exit 3 }; if ($p.CommandLine -notlike '*puppeteer_server.js*') { exit 2 }; exit 0" >nul 2>&1
set "CHECK=%ERRORLEVEL%"

if "%CHECK%"=="3" (
    echo Process %PID% is no longer running. Removing the stale PID file.
    del "%PID_FILE%" >nul 2>&1
    goto :done_ok
)
if "%CHECK%"=="2" (
    echo [REFUSED] Process %PID% is not running puppeteer_server.js. It was NOT stopped.
    echo           If %PID_FILE% is stale, delete it by hand after checking.
    goto :done_fail
)
if not "%CHECK%"=="0" (
    echo [ERROR] Could not inspect process %PID%. Nothing was stopped.
    goto :done_fail
)

rem Verified. Stop only this process and its children (for example the browser it launched).
taskkill /F /T /PID %PID% >nul 2>&1
if errorlevel 1 (
    echo [ERROR] taskkill could not stop process %PID%.
    goto :done_fail
)
del "%PID_FILE%" >nul 2>&1
echo Stopped the Puppeteer server process tree (PID %PID%).
goto :done_ok

:done_ok
if not defined WCRAWL_NOPAUSE pause
exit /b 0

:done_fail
if not defined WCRAWL_NOPAUSE pause
exit /b 1

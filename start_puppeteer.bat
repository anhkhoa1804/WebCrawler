@echo off
setlocal EnableExtensions

rem Starts the optional Puppeteer bridge server (includes\puppeteer_server.js) on 127.0.0.1:9222.
rem It never stops a process it did not start. A port held by something that is not a healthy
rem Puppeteer server is reported as a conflict and left alone.
rem Set WCRAWL_NOPAUSE=1 to skip the final "Press any key" prompt (used for automated checks).

set "ROOT=%~dp0"
set "PID_FILE=%ROOT%data\puppeteer.pid"
set "LOG_DIR=%ROOT%data\logs"
set "HEALTH_URL=http://127.0.0.1:9222/health"

echo ========================================
echo  Puppeteer Server Starter
echo ========================================
echo.

rem 1) A healthy server is already answering: nothing to do.
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -UseBasicParsing -Uri '%HEALTH_URL%' -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch { }; exit 1" >nul 2>&1
if not errorlevel 1 (
    echo Puppeteer server is already running at %HEALTH_URL%
    echo Use stop_puppeteer.bat to stop it.
    goto :done_ok
)

rem 2) Something holds port 9222 but does not answer /health: report it and do not touch it.
netstat -ano -p TCP | findstr /R /C:":9222 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [CONFLICT] Port 9222 is in use by a process that is not a healthy Puppeteer server.
    echo            This script will not stop it. To see the owner, run: netstat -ano ^| findstr :9222
    goto :done_fail
)

rem 3) Preconditions.
where node >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Node.js was not found on PATH. Install Node.js LTS and run this script again.
    goto :done_fail
)
if not exist "%ROOT%node_modules\puppeteer\package.json" (
    echo [ERROR] Puppeteer is not installed. Run this once in %ROOT%:  npm install
    goto :done_fail
)

rem 4) Start the server hidden, recording its PID so stop_puppeteer.bat can find exactly this process.
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
echo Starting Puppeteer server in the background...
powershell -NoProfile -Command "$p = Start-Process -FilePath 'node' -ArgumentList 'puppeteer_server.js' -WorkingDirectory '%ROOT%includes' -WindowStyle Hidden -PassThru -RedirectStandardOutput '%LOG_DIR%\puppeteer_server.out.log' -RedirectStandardError '%LOG_DIR%\puppeteer_server.err.log'; Set-Content -Path '%PID_FILE%' -Value $p.Id -Encoding ascii"
if errorlevel 1 (
    echo [ERROR] The server could not be started.
    goto :done_fail
)

rem 5) Wait up to about 30 seconds for /health to answer.
echo Waiting for the server to become ready...
powershell -NoProfile -Command "$ok = $false; for ($i = 0; $i -lt 60; $i++) { try { $r = Invoke-WebRequest -UseBasicParsing -Uri '%HEALTH_URL%' -TimeoutSec 1; if ($r.StatusCode -eq 200) { $ok = $true; break } } catch { }; Start-Sleep -Milliseconds 500 }; if ($ok) { exit 0 } else { exit 1 }" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] The server did not become healthy. See %LOG_DIR%\puppeteer_server.err.log
    goto :done_fail
)

echo.
echo Puppeteer server is running at %HEALTH_URL%
echo PID is recorded in %PID_FILE%. Stop it with stop_puppeteer.bat.
goto :done_ok

:done_ok
if not defined WCRAWL_NOPAUSE pause
exit /b 0

:done_fail
if not defined WCRAWL_NOPAUSE pause
exit /b 1

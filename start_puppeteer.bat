@echo off
echo ========================================
echo  Puppeteer Server Starter
echo ========================================
echo.

REM Kill existing Node.js processes on port 9222
echo Checking for existing server...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :9222') do (
    if not "%%a"=="0" (
        echo Killing process %%a...
        taskkill /F /T /PID %%a >nul 2>&1
    )
)

echo.
echo Starting Puppeteer Server...
echo Server will run on: http://localhost:9222
echo Press Ctrl+C to stop
echo.
echo ========================================
echo.

cd /d "%~dp0includes"
node puppeteer_server.js

pause

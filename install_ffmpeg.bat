@echo off
REM FFmpeg Auto-Installer for Windows
REM Downloads FFmpeg and adds to PATH

echo ============================================
echo    FFmpeg Installer for WebCrawler
echo ============================================
echo.

REM Check if FFmpeg already installed
where ffmpeg >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [OK] FFmpeg is already installed!
    ffmpeg -version | findstr "ffmpeg version"
    echo.
    echo Press any key to exit...
    pause >nul
    exit /b 0
)

echo [INFO] FFmpeg not found. Installing...
echo.

REM Set download URL (latest stable from gyan.dev)
set FFMPEG_URL=https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip
set INSTALL_DIR=C:\ffmpeg
set TEMP_ZIP=%TEMP%\ffmpeg.zip

echo [1/4] Downloading FFmpeg...
echo From: %FFMPEG_URL%
echo To: %TEMP_ZIP%
echo.

REM Download using PowerShell
powershell -Command "& {[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%FFMPEG_URL%' -OutFile '%TEMP_ZIP%'}"

if not exist "%TEMP_ZIP%" (
    echo [ERROR] Download failed!
    echo Please download manually from: https://www.gyan.dev/ffmpeg/builds/
    pause
    exit /b 1
)

echo [OK] Downloaded successfully!
echo.

echo [2/4] Extracting to %INSTALL_DIR%...
if exist "%INSTALL_DIR%" (
    echo [INFO] Removing old installation...
    rmdir /s /q "%INSTALL_DIR%"
)

REM Extract using PowerShell
powershell -Command "& {Expand-Archive -Path '%TEMP_ZIP%' -DestinationPath '%INSTALL_DIR%' -Force}"

REM Find ffmpeg.exe and move to root
for /d %%i in ("%INSTALL_DIR%\ffmpeg-*") do (
    xcopy "%%i\bin\*" "%INSTALL_DIR%\bin\" /E /I /Y
    rmdir /s /q "%%i"
)

if not exist "%INSTALL_DIR%\bin\ffmpeg.exe" (
    echo [ERROR] Extraction failed!
    pause
    exit /b 1
)

echo [OK] Extracted successfully!
echo.

echo [3/4] Adding to PATH...
REM Add to USER PATH (not SYSTEM PATH, no admin required)
for /f "skip=2 tokens=3*" %%a in ('reg query HKCU\Environment /v PATH 2^>nul') do set "CURRENT_PATH=%%a %%b"

REM Check if already in PATH
echo %CURRENT_PATH% | find /i "%INSTALL_DIR%\bin" >nul
if %ERRORLEVEL% EQU 0 (
    echo [INFO] Already in PATH
) else if "%CURRENT_PATH:~900,1%"=="" (
    setx PATH "%CURRENT_PATH%;%INSTALL_DIR%\bin"
    echo [OK] Added to PATH
) else (
    echo [WARN] Your user PATH is too long for setx, which would truncate it.
    echo        Add %INSTALL_DIR%\bin to your PATH manually.
)

echo.

echo [4/4] Cleaning up...
del /q "%TEMP_ZIP%"
echo [OK] Temporary files removed
echo.

echo ============================================
echo    Installation Complete!
echo ============================================
echo.
echo FFmpeg installed to: %INSTALL_DIR%\bin
echo.
echo IMPORTANT: Please RESTART this terminal/cmd window
echo for PATH changes to take effect!
echo.
echo Verifying the installed binaries by full path (does not depend on PATH):
"%INSTALL_DIR%\bin\ffmpeg.exe" -version >nul 2>&1 && (echo   [OK] ffmpeg.exe runs) || (echo   [FAIL] ffmpeg.exe did not run from %INSTALL_DIR%\bin)
"%INSTALL_DIR%\bin\ffprobe.exe" -version >nul 2>&1 && (echo   [OK] ffprobe.exe runs) || (echo   [FAIL] ffprobe.exe did not run from %INSTALL_DIR%\bin)
echo.
echo To check from a NEW terminal after the PATH change, run:
echo   ffmpeg -version
echo   ffprobe -version
echo.
pause

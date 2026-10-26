@echo off
REM ===========================================================================
REM  PaCELab Equip - set up this copy of the application.
REM
REM  1. Creates config.local.json from config.template.json when it is missing.
REM  2. When the drive data folder ..\..\data\pacelab-equip\data exists, points
REM     dataFolder at it and backupFolder at ..\..\data\pacelab-equip\backups,
REM     through tools\config.php (the loader's own save). A value already set
REM     is left alone.
REM  3. Runs "SETUP ON THIS PC.bat": the logon and Desktop shortcuts for this
REM     folder, and the server start.
REM
REM  SETUP.bat /config-only does steps 1 and 2 and stops.
REM  Every path is relative to this file, so the drive letter does not matter.
REM ===========================================================================

setlocal
cd /d "%~dp0"

set "PHPDIR=%~dp0tools\php\"
if not exist "%PHPDIR%php.exe" set "PHPDIR=%LOCALAPPDATA%\php83\"
if not exist "%PHPDIR%php.exe" (
  echo.
  echo   PHP was not found in tools\php or %%LOCALAPPDATA%%\php83.
  echo   Copy the bundled PHP into tools\php and run this again.
  echo.
  pause
  exit /b 1
)

"%PHPDIR%php.exe" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" "tools\config.php" init
if errorlevel 1 goto failed

if exist "%~dp0..\..\data\pacelab-equip\data\" (
  "%PHPDIR%php.exe" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" "tools\config.php" set-if-empty dataFolder "..\..\data\pacelab-equip\data"
  if errorlevel 1 goto failed
  "%PHPDIR%php.exe" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" "tools\config.php" set-if-empty backupFolder "..\..\data\pacelab-equip\backups"
  if errorlevel 1 goto failed
) else (
  echo   No drive data folder beside this copy; the data stays in data\ here.
)

echo.
"%PHPDIR%php.exe" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" "tools\config.php" show
echo.

if /i "%~1"=="/config-only" exit /b 0

call "%~dp0SETUP ON THIS PC.bat"
exit /b %errorlevel%

:failed
echo.
echo   Setup stopped: config.local.json could not be written. See the message above.
echo.
pause
exit /b 1

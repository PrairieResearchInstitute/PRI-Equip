@echo off
REM ===========================================================================
REM  Take a backup of the database now, and prove it can be restored.
REM
REM  A backup is taken automatically once a day while the server is running, so
REM  you should not normally need this. Use it before anything risky.
REM
REM  Backups go to backupFolder in config.local.json (on the drive, the backups
REM  folder beside the data), otherwise to the "PaCELab Equip Backups" folder
REM  beside this one. To send them somewhere else - Taiga, once this is on the
REM  server - put that path on a single line in backup-path.txt in the data
REM  folder.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\backup.ps1"
echo.
echo   Now proving the newest backup can be restored...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\backup.ps1" -Restore
echo.
pause

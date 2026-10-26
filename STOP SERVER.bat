@echo off
REM ===========================================================================
REM  Stop the Shared Laboratory Equipment server.
REM
REM  Only this application's server is stopped. Other PHP servers on this
REM  computer - PRI Facilities Request, for one - are left alone.
REM
REM  On PostgreSQL it then stops the estate's PostgreSQL on this drive with a
REM  fast, clean shutdown, unless another application is still connected
REM  (tools\estate-pg.ps1). Run it before unplugging the drive.
REM
REM  It starts again the next time you log on. To stop that too, delete
REM  "Shared Lab Equipment.lnk" from your Startup folder.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\stop.ps1"
pause

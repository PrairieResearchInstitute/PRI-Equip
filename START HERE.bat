@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment System - open it.
REM
REM  You should rarely need this. A shortcut in your Startup folder already
REM  starts the server when you log on, and a supervisor restarts it if it ever
REM  stops, so http://localhost:8147/ is normally just there.
REM
REM  Double-click this if the browser ever says the site cannot be reached. It
REM  makes sure the server is up and then opens it. Running it twice is safe.
REM
REM  On PostgreSQL it first starts the estate's PostgreSQL on this drive
REM  (port 5433) unless it already runs (tools\estate-pg.ps1).
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\ensure-running.ps1" -Open

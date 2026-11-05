@echo off
rem Source: drez-containerize skill, templates\launcher\build.bat.
rem Compiles Launcher.cs with launcher.ini embedded into the .exe named by the
rem ini's exe= line, beside this launcher folder, with the icon named by icon=.
rem Uses the C# compiler that ships with Windows (.NET Framework 4); nothing to install.
setlocal EnableDelayedExpansion
set "HERE=%~dp0"
set "ROOT=%HERE%.."
set "EXE="
set "ICON="
for /f "usebackq tokens=1,* delims==" %%a in ("%HERE%launcher.ini") do (
  if /i "%%a"=="exe" set "EXE=%%b"
  if /i "%%a"=="icon" set "ICON=%%b"
)
if "%EXE%"=="" (echo launcher.ini has no exe= line.& exit /b 2)
set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (echo The .NET Framework 4 C# compiler is missing: %CSC%& exit /b 2)
set "ICONARG="
if not "%ICON%"=="" (
  if not exist "%ROOT%\%ICON%" (echo Icon not found: %ROOT%\%ICON%& exit /b 2)
  set "ICONARG=/win32icon:"%ROOT%\%ICON%""
)
"%CSC%" /nologo /target:winexe /optimize+ !ICONARG! /r:System.Windows.Forms.dll /resource:"%HERE%launcher.ini",launcher.ini /out:"%ROOT%\%EXE%" "%HERE%Launcher.cs"
if errorlevel 1 (echo Build failed.& exit /b 1)
echo Built %EXE%
exit /b 0

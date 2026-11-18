@echo off
REM AmetecsGlobal - ONE CLICK SYNC. Keeps this folder and github.com/ametecsindia/ametecsglobal in step: saves local changes, brings in
REM GitHub changes, pushes. Writes only inside this folder and that repository. For automatic syncing use AUTO-SYNC.bat.
REM Plugins are edited inside AmetecsGlobal\plugins; the original app folders are never read.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync.ps1" -Only "%~1"
echo.
pause

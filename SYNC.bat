@echo off
REM AmetecsGlobal - ONE CLICK SYNC. Copies in the latest of every app in sync-apps.json (those folders are only read),
REM then keeps this folder and github.com/ametecsindia/ametecsglobal in step: saves local changes, brings in
REM GitHub changes, pushes. Writes only inside this folder and that repository. For automatic syncing use AUTO-SYNC.bat.
REM Optional: SYNC.bat SmartPRS3   (sync just one app)
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync.ps1" -Only "%~1"
echo.
pause

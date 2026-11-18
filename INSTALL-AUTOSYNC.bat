@echo off
REM AmetecsGlobal - make AUTO-SYNC truly automatic on this PC (run ONCE per PC, double-click).
REM It registers a Windows scheduled task "AmetecsGlobal AUTO-SYNC" that starts autosync.ps1 hidden every time you
REM sign in to Windows, and starts it now. No window to keep open. Progress: sync-log.txt in this folder.
REM To stop it for good:  schtasks /Delete /TN "AmetecsGlobal AUTO-SYNC" /F
setlocal
set "AG=%~dp0"
schtasks /Create /TN "AmetecsGlobal AUTO-SYNC" /SC ONLOGON /RL LIMITED /F /TR "powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File \"%AG%autosync.ps1\"" >nul
if errorlevel 1 (
  echo Could not register the automatic sync. Right-click this file and choose "Run as administrator", then try again.
  pause
  exit /b 1
)
schtasks /Run /TN "AmetecsGlobal AUTO-SYNC" >nul
echo.
echo  AUTO-SYNC is now automatic on this PC.
echo  - It runs hidden in the background and starts by itself every time you sign in to Windows.
echo  - Changes reach GitHub about a minute after you stop saving; others' changes arrive within 5 minutes.
echo  - See what it did in sync-log.txt (this folder).
echo  You no longer need to keep AUTO-SYNC.bat open.
echo.
pause

@echo off
setlocal
rem SmartPRS3 - on-premises installer (Windows). Double-click it: it asks for Administrator rights itself.
rem What the engineer types: the MySQL root password, and (optionally) a database name and a port. Nothing else.
net session >nul 2>&1
if errorlevel 1 (
  echo Asking Windows for Administrator rights ...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
cd /d "%~dp0"
set "APPDIR=%~dp0"
title SmartPRS3 - on-premises installer (Windows)
echo ==================================================
echo   SmartPRS3 - on-premises installer (Windows)
echo   see VERSION.txt for the version
echo ==================================================

set "PHPEXE="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHPEXE set "PHPEXE=%%P"
if not defined PHPEXE for /d %%D in ("C:\php*" "C:\laragon\bin\php\php-*") do if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
if not defined PHPEXE ( echo [X] PHP not found. Install PHP 8.3 NTS x64 ^(guide, step 3^) and run this again. & pause & exit /b 1 )
echo   PHP: %PHPEXE%

echo.
echo STEP 1: checking the server and the extracted files ...
"%PHPEXE%" _install.php verify
if errorlevel 1 goto :failed

set "FRESH="
if not exist ".env" ( copy ".env.example" ".env" >nul & set "FRESH=fresh" )
if not exist "storage\updates" mkdir "storage\updates"
if not exist "storage\keys" mkdir "storage\keys"
if not exist "public\uploads" mkdir "public\uploads"

echo.
echo STEP 2: database and its accounts ...
"%PHPEXE%" _install.php db %FRESH%
if errorlevel 1 goto :failed

echo.
echo STEP 3: application key ...
"%PHPEXE%" _install.php key
if errorlevel 1 goto :failed

echo.
echo STEP 4: database tables ...
"%PHPEXE%" tools\db\migrate.php --no-seed
if errorlevel 1 goto :failed

echo.
echo STEP 5: database rights for the application account ...
"%PHPEXE%" tools\db\grant-application-account.php --apply
if errorlevel 1 goto :failed

echo.
echo STEP 6: can the application connect? ...
"%PHPEXE%" tools\db\verify-connection.php
if errorlevel 1 goto :failed

echo.
echo STEP 7: background jobs ^(biometric sync, licence check, absence alerts^) - Windows Task Scheduler ...
set "TASKFAIL=0"
call :task "cron"                 "/SC MINUTE /MO 5"    "cron.php"
call :task "timers"               "/SC MINUTE /MO 5"    "db\schedule.php" timers
call :task "absence"              "/SC DAILY /ST 06:15" "db\schedule.php" absence
call :task "unauthorized-absence" "/SC DAILY /ST 06:20" "db\schedule.php" unauthorized-absence
call :task "archive"              "/SC DAILY /ST 01:30" "db\schedule.php" archive
call :task "anchor"               "/SC DAILY /ST 23:45" "db\schedule.php" anchor
if "%TASKFAIL%"=="0" ( echo   [ok] registered under Task Scheduler ^> SmartPRS3 ) else ( echo   [!] Windows refused %TASKFAIL% scheduled task^(s^). )

echo.
echo STEP 8: IIS web site ...
set "PORT=8085"
set /p "PORT=  Port for SmartPRS3 [8085]: "
"%PHPEXE%" _install.php iis %PORT%
if errorlevel 1 goto :failed

set "URL="
if exist "storage\install-url.txt" set /p URL=<"storage\install-url.txt"
echo.
echo ==================================================
echo   Done.
if defined URL echo   Open  %URL%/activate  and enter the License Code from Ametecs.
if not defined URL echo   Point your web server at the  public  folder, then open /activate.
echo   After activation the sign-in page asks you to create the administrator.
echo   Back up the .env file now - it holds this server's database passwords.
echo ==================================================
pause
exit /b 0

:failed
echo.
echo ==================================================
echo   [X] The step above FAILED - read the message above it.
echo   Fix it and run this installer again: it is safe to
echo   repeat and keeps everything already done.
echo ==================================================
pause
exit /b 1

:task
schtasks /Create /F /RU SYSTEM %~2 /TN "SmartPRS3\%~1" /TR "\"%PHPEXE%\" \"%APPDIR%tools\%~3\" %4" >nul 2>nul || set /a TASKFAIL+=1
exit /b 0

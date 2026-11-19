@echo off
setlocal
title SmartEPT - Test
cd /d "%~dp0"
set "PHP="
REM 11-Nov-2026: use the PHP the Laragon website runs (etc\apache2\mod_php.conf); the last php-* folder by name was php-8.5.9, not the website's 8.3.30
for /f tokens^=2^ delims^=^" %%d in ('findstr /b /c:"PHPIniDir" "C:\laragon\etc\apache2\mod_php.conf" 2^>nul') do if exist "%%~d\php.exe" set "PHP=%%~d\php.exe"
if defined PHP set "PHP=%PHP:/=\%"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found under C:\laragon\bin\php & pause & exit /b 1 )
echo App:  %~dp0
echo PHP:  %PHP%
echo.

REM 27-Aug-2026: `artisan test` comes from nunomaduro/collision and the runner from
REM phpunit/phpunit, both require-dev. BUILD-CLIENT-PACKAGE.bat runs `composer install --no-dev`,
REM which deletes them — so the first test run after a package build failed with
REM `Command "test" is not defined`, which points at artisan and not at the real cause.
if not exist "%~dp0vendor\phpunit\phpunit\phpunit" (
  echo  [!] Dev dependencies are missing - a package build ran `composer install --no-dev`.
  echo      Restoring them ...
  echo.
  call composer install
  if errorlevel 1 (
    echo  [X] composer install failed. The test suite needs phpunit and collision.
    pause & exit /b 1
  )
  echo.
)

"%PHP%" artisan test %*
echo.
echo ===== Done. =====
pause

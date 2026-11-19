@echo off
setlocal enabledelayedexpansion
title SmartEPT - RELEASE

REM ===========================================================================
REM  SmartEPT - ONE command. Dependencies, database, tests, client package.
REM
REM      RELEASE.bat            everything: deps -> migrate -> tests -> package
REM      RELEASE.bat -test      tests only, no package
REM      RELEASE.bat -build     package only, skip the tests (say why to yourself)
REM      RELEASE.bat -force     build even though the tests failed
REM      RELEASE.bat -package   only the NEW-CLIENT package, from the server setup already
REM                             built (no tests, no new version) - after an agent rebuild
REM
REM  EVERY output lands in ONE place (27-Oct-2026):
REM      C:\SmartEPT-Releases\1-Update-for-Central   upload this in Central - Upload Update
REM                           2-Server-Setup         fresh server installs
REM                           3-Client-Package       the box for a new client, per version
REM                           4-Agent                signed agent installers
REM                           5-Agent-Test           unsigned test agents - never for clients
REM
REM  WHY THIS EXISTS
REM  ---------------
REM  The steps have to run in an order that is not obvious, and getting it wrong
REM  fails in a way that points at the wrong thing:
REM
REM    * `php artisan test` comes from nunomaduro/collision and the runner from
REM      phpunit/phpunit. Both are require-dev. The package build runs
REM      `composer install --no-dev`, which DELETES them - so the first test run
REM      after any build died with `Command "test" is not defined`, which reads
REM      like a broken artisan and is actually a missing dev tree.
REM
REM    * rebuild-server-zip.bat copies vendor\ into the client ZIP as it finds
REM      it. Build with the dev tree present and every client ships phpunit,
REM      faker, pint and sail. tests\ and phpunit.xml are excluded; vendor\phpunit
REM      is not.
REM
REM  So: dev tree ON to test, dev tree OFF to package, dev tree back ON at the
REM  end so tomorrow's first command is not a puzzle. That is the whole script.
REM ===========================================================================

cd /d "%~dp0"
set "SRC=%cd%"

if not exist "%SRC%\artisan" (
  echo  [X] %SRC% is not the SmartEPT server ^(no artisan^).
  pause & exit /b 1
)

REM ---- arguments -----------------------------------------------------------
set "DO_TESTS=1"
set "DO_BUILD=1"
set "FORCE=0"
set "USAGE=0"
set "PACKAGE_ONLY="
REM A flag, not a `goto` out of the loop. cmd's handling of a label jump from
REM inside a parenthesised for-block is the trap that silently broke the manifest
REM step of BUILD-CLIENT-PACKAGE.bat once already; it is not worth re-testing here.
for %%A in (%*) do (
  if /i "%%~A"=="-test"  ( set "DO_BUILD=0" )
  if /i "%%~A"=="-build" ( set "DO_TESTS=0" )
  if /i "%%~A"=="-force" ( set "FORCE=1" )
  if /i "%%~A"=="-package" ( set "PACKAGE_ONLY=1" & set "DO_TESTS=0" )
  if /i "%%~A"=="-h"     ( set "USAGE=1" )
  if /i "%%~A"=="--help" ( set "USAGE=1" )
  if /i "%%~A"=="/?"     ( set "USAGE=1" )
)
if "%USAGE%"=="1" goto :USAGE

REM ---- locate PHP, the same way every other script here does ----------------
set "PHP="
REM 11-Nov-2026: use the PHP the Laragon website runs (etc\apache2\mod_php.conf); the last php-* folder by name was php-8.5.9, not the website's 8.3.30
for /f tokens^=2^ delims^=^" %%d in ('findstr /b /c:"PHPIniDir" "C:\laragon\etc\apache2\mod_php.conf" 2^>nul') do if exist "%%~d\php.exe" set "PHP=%%~d\php.exe"
if defined PHP set "PHP=%PHP:/=\%"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP (
  echo  [X] PHP not found under C:\laragon\bin\php and not on PATH.
  pause & exit /b 1
)

REM ---- locate Composer (27-Oct-2026: a window without Composer on PATH failed
REM      the package step with "'composer' is not recognized") -------------------
set "COMPOSER_CMD="
for /f "delims=" %%w in ('where composer 2^>nul') do if not defined COMPOSER_CMD set "COMPOSER_CMD=composer"
if not defined COMPOSER_CMD if exist "C:\laragon\bin\composer\composer.phar" set "COMPOSER_CMD="%PHP%" "C:\laragon\bin\composer\composer.phar""
if not defined COMPOSER_CMD if exist "%SRC%\composer.phar" set "COMPOSER_CMD="%PHP%" "%SRC%\composer.phar""

echo ===============================================================
echo   SmartEPT - RELEASE
echo ===============================================================
echo   App   : %SRC%
echo   PHP   : %PHP%
if "%DO_TESTS%"=="1" (echo   Tests : yes) else (echo   Tests : SKIPPED ^(-build^))
if "%DO_BUILD%"=="1" (echo   Build : yes) else (echo   Build : skipped ^(-test^))
echo.

REM ===========================================================================
REM  1. TESTS  - needs the dev tree
REM ===========================================================================
if "%DO_TESTS%"=="0" goto :SKIP_TESTS

echo [1/4] Restoring dev dependencies ...
REM Checked by file, not by asking composer: `composer install` on an already
REM complete tree still takes the better part of a minute, and this script is
REM meant to be run several times an afternoon.
if exist "%SRC%\vendor\phpunit\phpunit\phpunit" (
  echo       Already present.
  REM 07-Nov-2026: refresh the class map anyway, takes seconds - a new code folder such as
  REM packages\qronex-module is not found until it is, and migrate would fail on it.
  if defined COMPOSER_CMD call %COMPOSER_CMD% dump-autoload
) else (
  echo       The dev tree is missing ^(a previous package build stripped it^).
  if not defined COMPOSER_CMD (
    echo  [X] Composer not found - not on PATH and not at C:\laragon\bin\composer\composer.phar.
    pause & exit /b 1
  )
  call %COMPOSER_CMD% install
  if errorlevel 1 (
    echo.
    echo  [X] composer install failed. Without phpunit there is nothing to run.
    pause & exit /b 1
  )
)

echo.
echo [2/4] Database and caches ...
REM optimize:clear FIRST. A cached config holds the old SMARTEPT_* values, and a
REM test run against a stale config passes or fails for reasons that have nothing
REM to do with the code in front of you.
"%PHP%" artisan optimize:clear
"%PHP%" artisan migrate --force
if errorlevel 1 (
  echo.
  echo  [X] migrate failed. Fix that before anything else - every later step
  echo      assumes the schema is current.
  pause & exit /b 1
)

echo.
echo [3/4] Running the test suite ...
echo.
"%PHP%" artisan test
set "TESTS=!errorlevel!"
echo.

if not "!TESTS!"=="0" (
  if "%FORCE%"=="1" (
    echo  [!] Tests FAILED and -force was given. Building anyway.
    echo      Whatever is red above ships in this package.
    echo.
  ) else (
    echo ===============================================================
    echo   [X] TESTS FAILED - nothing was packaged.
    echo.
    echo   Read the red lines above; they name the file and the case.
    echo.
    echo   To build regardless ^(you have decided the failures are known
    echo   and unrelated^):
    echo.
    echo       RELEASE.bat -force
    echo ===============================================================
    pause & exit /b 1
  )
)

:SKIP_TESTS
if "%DO_BUILD%"=="0" (
  echo.
  echo ===============================================================
  echo   Done. Nothing was packaged ^(-test^).
  echo ===============================================================
  pause & exit /b 0
)

REM ===========================================================================
REM  2. PACKAGE  - must NOT have the dev tree
REM ===========================================================================
if not defined PACKAGE_ONLY goto :FULL_BUILD
echo.
echo [4/4] Making the new-client package from the server setup already built ...
call "%SRC%\deployment\BUILD-CLIENT-PACKAGE.bat" -reuse < nul
set "BUILT=!errorlevel!"
if "!BUILT!"=="0" goto :DONE_OK
echo.
echo   [X] The client package was not made - the reason is above.
pause & exit /b 1

:FULL_BUILD
echo.
echo [4/4] Building the client package ...
echo       Stripping dev dependencies first - rebuild-server-zip.bat copies
echo       vendor\ as it finds it, so the dev tree would ship to every client.
echo.
if not defined COMPOSER_CMD (
  echo  [X] Composer not found - not on PATH and not at C:\laragon\bin\composer\composer.phar.
  echo      Nothing was changed. Run this from the Laragon Terminal, or install Composer.
  pause & exit /b 1
)
call %COMPOSER_CMD% install --no-dev --optimize-autoloader
if errorlevel 1 (
  echo.
  echo  [X] composer install --no-dev failed. Not packaging a vendor tree
  echo      nobody can vouch for.
  call :RESTORE_DEV
  pause & exit /b 1
)

REM stdin redirected from nul so the `pause` at the end of every nested script
REM returns immediately. Errors still print; this script checks errorlevel.
call "%SRC%\deployment\BUILD-CLIENT-PACKAGE.bat" < nul
set "BUILT=!errorlevel!"

call :RESTORE_DEV

REM 2 = the server release (update + setup) is ready; only the new-client package was skipped,
REM and BUILD-CLIENT-PACKAGE.bat has just said why and where the files are.
if "!BUILT!"=="2" (
  echo.
  echo   The update for Central is ready in C:\SmartEPT-Releases\1-Update-for-Central.
  echo   Only the new-client package was skipped - see the reason above.
  pause & exit /b 0
)
if not "!BUILT!"=="0" (
  echo.
  echo ===============================================================
  echo   [X] The package build failed - see the messages above.
  echo   Dev dependencies have been restored, so `RELEASE.bat -test`
  echo   works straight away.
  echo ===============================================================
  pause & exit /b 1
)

:DONE_OK
echo.
echo ===============================================================
echo   RELEASE COMPLETE  -  everything is in  C:\SmartEPT-Releases
echo.
echo     1-Update-for-Central   upload this in Central - Upload Update
echo     2-Server-Setup         fresh server installs
echo     3-Client-Package       the box for a new client
echo     4-Agent                the signed agent
echo.
echo   ON THE CLIENT SERVER, after installing:
echo       php artisan migrate
echo       php artisan optimize:clear
echo       php artisan smartept:enforcement on
echo.
echo   ON EACH CLIENT PC:
echo       2-AGENT     run the one setup - Next, Finish. It contains the
echo                   service that blocks and enrols itself at sign-in.
echo.
echo   BEFORE WIDE DISTRIBUTION: SourceGuardian-encode
echo   app\Services\LicenseFile.php and
echo   app\Http\Middleware\EnsureLicensed.php.
echo ===============================================================
pause
exit /b 0

REM ---------------------------------------------------------------------------
REM  Always put the dev tree back. Leaving it stripped is what made
REM  `php artisan test` fail with a message about artisan rather than composer.
REM ---------------------------------------------------------------------------
:RESTORE_DEV
echo.
echo       Restoring dev dependencies so the next test run just works ...
call %COMPOSER_CMD% install >nul 2>nul
if errorlevel 1 (
  echo  [!] Could not restore the dev dependencies automatically.
  echo      Run this before your next test run:  composer install
)
exit /b 0

:USAGE
echo.
echo   RELEASE.bat            deps, migrate, tests, then the client package
echo   RELEASE.bat -test      tests only
echo   RELEASE.bat -build     package only, no tests
echo   RELEASE.bat -force     package even though the tests failed
echo   RELEASE.bat -package   only the new-client package, from the last server build
echo.
pause
exit /b 0

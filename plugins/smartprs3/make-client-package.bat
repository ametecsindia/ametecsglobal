@echo off
rem SmartPRS3 - build the ON-PREMISES CLIENT PACKAGE (full install for a new client server) into
rem C:\AmetecsGlobal-Releases\Client-Packages. Double-click, or run from the Laragon terminal.
rem Online updates for existing clients are still made with make-release.bat.
cd /d "%~dp0"
set PHPEXE=
for /d %%D in (C:\laragon\bin\php\*) do if exist "%%D\php.exe" set PHPEXE=%%D\php.exe
if "%PHPEXE%"=="" set PHPEXE=php
"%PHPEXE%" tools\release\build-client-package.php %*
echo.
pause

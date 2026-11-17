@echo off
cd /d "%~dp0"
title SmartPRS3 - is this install complete?
echo Checking the server and every file of the package. Reads only - changes nothing.
echo.
php _install.php verify
echo.
pause

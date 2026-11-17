@echo off
REM AmetecsGlobal - SET UP ON A NEW PC (run once per PC).
REM Run it from the Laragon Terminal (so php, composer and mysql are found), with Laragon started:
REM     cd C:\laragon\www
REM     SETUP-NEW-PC.bat
REM It creates C:\laragon\www\AmetecsGlobal from GitHub and touches nothing else on this PC.
setlocal
cd /d C:\laragon\www
if exist "AmetecsGlobal\.git" ( echo AmetecsGlobal is already set up on this PC. Start AmetecsGlobal\AUTO-SYNC.bat instead. & pause & exit /b 0 )
if exist "AmetecsGlobal\*" ( echo C:\laragon\www\AmetecsGlobal already exists but is not linked to GitHub. Move it away first, then run this again. & pause & exit /b 1 )
where git >/dev/null 2>/dev/null || ( echo Git is not installed. Install Git for Windows from https://git-scm.com, then run this again. & pause & exit /b 1 )
echo == 1/4  downloading AmetecsGlobal from GitHub ==
git clone https://github.com/ametecsindia/ametecsglobal.git AmetecsGlobal || ( echo Download failed. Check internet and that your GitHub account can open the repository. & pause & exit /b 1 )
cd AmetecsGlobal\plugins\smartprs3
echo == 2/4  SmartPRS3 plugin: PHP libraries (vendor) ==
call composer install --no-interaction || ( echo composer install failed - send Claude a screenshot. & pause & exit /b 1 )
echo == 3/4  this PC's own database ==
if not exist .env copy .env.example .env >nul
call laragon-setup.bat --with-demo || ( echo Database setup failed - send Claude a screenshot. & pause & exit /b 1 )
cd ..\..
echo == 4/4  done ==
echo.
echo  Next:
echo   1. Create your Super Admin:  php plugins\smartprs3\tools\make-super-admin.php you@ametecsindia.com "Your Name"   (from C:\laragon\www\AmetecsGlobal)
echo   2. Double-click AUTO-SYNC.bat in C:\laragon\www\AmetecsGlobal and leave it running.
echo   3. Open http://localhost/AmetecsGlobal/
pause

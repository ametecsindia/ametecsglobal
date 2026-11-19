@echo off
setlocal enabledelayedexpansion
title SmartEPT - Build the complete client package

REM ===========================================================================
REM  SmartEPT - one command, one complete client package.
REM
REM      latest source  ->  build  ->  server + agent  ->  package
REM
REM  TWO things a client installs: the server, once; the agent, on each PC. The agent
REM  setup already contains the service that does the blocking - there is no third thing.
REM
REM  Produces C:\SmartEPT-Releases\3-Client-Package\v<version> containing
REM  everything an installer person needs and nothing they do not.
REM
REM  DESIGN NOTE, and the reason this script is longer than it looks like it
REM  should be: it REFUSES to package a binary that is older than its source.
REM  A stale service inside the agent installer is invisible - the client installs
REM  it, it runs, it reports healthy, and it enforces last week's rules. That
REM  is the same class of failure as a policy that says "applied" and blocks
REM  nothing, and it is the one this whole product exists to stop shipping.
REM ===========================================================================

cd /d "%~dp0.."
set "SRC=%cd%"

set "SRC_ENF=C:\laragon\www\smartept-enforcer"
set "SRC_AGENT=C:\Users\MPS\Documents\Claude\Projects\SmartEPT\agent"
REM 27-Oct-2026 (Ejaz): ONE place for every build output.
REM   C:\SmartEPT-Releases\1-Update-for-Central   the ZIP to upload in Central - Upload Update
REM                        2-Server-Setup         fresh server installs
REM                        3-Client-Package       server + signed agent + docs, per version
REM                        4-Agent                signed agent installers (build-agent.bat)
REM                        5-Agent-Test           unsigned test agents - never for clients
set "REL=C:\SmartEPT-Releases"
set "OUTROOT=%REL%\3-Client-Package"

REM  BUILD-CLIENT-PACKAGE.bat -reuse  (RELEASE.bat -package) makes the client package from the
REM  server setup already in 2-Server-Setup: no server rebuild, no new version number. For when
REM  the agent was out of date at release time and has been rebuilt since.
set "REUSE="
if /i "%~1"=="-reuse" set "REUSE=1"

REM Date stamp as YYYY-MM-DD, locale-independent (the "date" command's format
REM changes with Windows region settings and has produced folders called
REM 22-08-2026 and 08/22/2026 on the same estate).
for /f "usebackq tokens=*" %%D in (`powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"`) do set "STAMP=%%D"
if not defined STAMP set "STAMP=undated"

echo ===============================================================
echo   SmartEPT - BUILD CLIENT PACKAGE
echo ===============================================================
echo   Server  : %SRC%
echo   Docs    : %SRC_ENF%\docs
echo   Agent   : %SRC_AGENT%
echo.

REM ---------------------------------------------------------------
REM  0. Locate PHP the same way INSTALL.bat does.
REM ---------------------------------------------------------------
set "PHP="
REM 11-Nov-2026: use the PHP the Laragon website runs (etc\apache2\mod_php.conf); the last php-* folder by name was php-8.5.9, not the website's 8.3.30
for /f tokens^=2^ delims^=^" %%d in ('findstr /b /c:"PHPIniDir" "C:\laragon\etc\apache2\mod_php.conf" 2^>nul') do if exist "%%~d\php.exe" set "PHP=%%~d\php.exe"
if defined PHP set "PHP=%PHP:/=\%"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP (
  echo  [X] PHP not found. This script needs it to build and verify the server ZIP.
  pause & exit /b 1
)

if not exist "%SRC%\artisan" ( echo  [X] %SRC% is not the SmartEPT server. & pause & exit /b 1 )
if not exist "%SRC%\vendor\autoload.php" ( echo  [X] vendor is missing. Run: composer install --no-dev & pause & exit /b 1 )

REM ---------------------------------------------------------------
REM  0b. VERSION - ONE number for the whole product (Ejaz, 1-Sep-2026).
REM
REM  version.json is the same file the installed server reports to SmartEPT
REM  Central when a client presses "Check for Update". Naming the package from
REM  anything else guarantees the two disagree: a package called 1.2 whose
REM  version.json still says 1.1 is never offered as an update and never
REM  reports that it installed. So the package name comes FROM that file, and
REM  the file is bumped at the END - only after a build that actually worked.
REM ---------------------------------------------------------------
REM  Read via a temp file, NOT `for /f`: a backtick block runs cmd /c on the
REM  string, and a quoted exe followed by a quoted argument is what produced
REM  "The filename, directory name, or volume label syntax is incorrect."
REM  The STAMP loop above survives only because it calls powershell unquoted.
set "VERFILE=%TEMP%\smartept-version.txt"
set "VER="
if defined REUSE goto :REUSE_VER
"%PHP%" "%SRC%\deployment\version.php" read > "%VERFILE%"
if exist "%VERFILE%" set /p VER=<"%VERFILE%"
del "%VERFILE%" 2>nul
goto :VER_READ
:REUSE_VER
if exist "%REL%\2-Server-Setup\LATEST.txt" set /p VER=<"%REL%\2-Server-Setup\LATEST.txt"
if not defined VER (
  echo  [X] Nothing to reuse in %REL%\2-Server-Setup - run RELEASE.bat -build first.
  pause & exit /b 1
)
:VER_READ
if not defined VER (
  echo  [X] version.json is missing or has no "version" - that file IS the product's
  echo      version number. Fix %SRC%\version.json and run this again.
  pause & exit /b 1
)
set "PKG=%OUTROOT%\v%VER%"
echo   Version : %VER%
echo   Output  : %PKG%
echo.

REM ===========================================================================
REM  1. SERVER
REM ===========================================================================
if defined REUSE goto :REUSE_SERVER
echo [1/7] Building the server package ...
echo.
call "%SRC%\deployment\rebuild-server-zip.bat" %VER%
if errorlevel 1 (
  echo.
  echo  [X] The server package failed to build. Nothing else was produced.
  pause & exit /b 1
)

set "SERVER_ZIP=%REL%\2-Server-Setup\SmartEPT-Admin-Server-Setup-%VER%.zip"
if not exist "%SERVER_ZIP%" (
  echo  [X] Expected %SERVER_ZIP% and it is not there.
  pause & exit /b 1
)

REM 27-Oct-2026: the number is spent the moment the update ZIP exists - it can be uploaded to
REM Central straight away, even if the client package below cannot be made yet. (It used to be
REM bumped only at the very end, so an out-of-date agent left a published number un-bumped.)
set "NEXTVER="
"%PHP%" "%SRC%\deployment\version.php" bump > "%VERFILE%"
if exist "%VERFILE%" set /p NEXTVER=<"%VERFILE%"
del "%VERFILE%" 2>nul
if not defined NEXTVER (
  echo.
  echo  [!] version.json could not be bumped. Edit it by hand before the next build,
  echo      or you will rebuild %VER% over itself.
)
goto :SERVER_READY

:REUSE_SERVER
set "SERVER_ZIP=%REL%\2-Server-Setup\SmartEPT-Admin-Server-Setup-%VER%.zip"
echo [1/7] Reusing the server setup already built for %VER%
if not exist "%SERVER_ZIP%" (
  echo  [X] %SERVER_ZIP% is missing - run RELEASE.bat -build.
  goto :SKIP_PKG
)
:SERVER_READY

REM ===========================================================================
REM  2. THE POLICY SERVICE  -  inside the agent installer, not beside it
REM ===========================================================================
REM
REM  27-Aug-2026 (Ejaz): "I want the similar package for client - not with a separate
REM  enforcer. It should be Only 1 Agent setup file (all inclusive)."
REM
REM  It already is, and has been since 26-Aug. `agent\build\installer.nsh` ships the service
REM  inside the agent as `resources\service\SmartEPTAgentService.exe`, registers it, starts it,
REM  turns on AppIDSvc, adds the Start Menu escape hatch, and REMOVES the old standalone
REM  `SmartEPTEnforcer` product if it finds one. It enrols itself from a handoff token the
REM  first time an employee signs in, so there is no address and no token in the build and one
REM  file works at every client.
REM
REM  This script was still shipping the retired standalone installer in a 2-ENFORCER folder.
REM  That is worse than redundant: installing it puts back the very service the agent installer
REM  deletes, so whichever ran last won and the PC's state depended on install order. Both the
REM  folder and the INSTALL-ON-THIS-PC.bat wrapper are gone.
REM
REM  What is verified here instead: that the agent installer we are about to ship actually
REM  CONTAINS the service. An agent built from a tree with an empty build\service\ installs
REM  cleanly, reports activity, and blocks nothing - and its own .nsh says so in a message box
REM  nobody reads. Better to fail here.
echo.
echo [2/7] Checking the policy service inside the agent ...

set "SVC_EXE=%SRC_AGENT%\build\service\SmartEPTAgentService.exe"
if not exist "%SVC_EXE%" (
  echo.
  echo  [X] STOP. The policy service binary is missing from the agent tree:
  echo      %SVC_EXE%
  echo.
  echo      An agent built without it installs fine, reports activity, and blocks
  echo      NOTHING. That is the exact failure this product exists to stop shipping.
  goto :SKIP_PKG
)
for %%A in ("%SVC_EXE%") do echo       Service binary: %%~zA bytes, %%~tA

REM ===========================================================================
REM  3. AGENT  -  the one file that goes on an employee PC
REM ===========================================================================
echo.
echo [3/7] Checking the employee agent ...

REM 27-Oct-2026: ONLY the SIGNED installer of the agent version in package.json. It used to take
REM whatever "SmartEPT Agent Setup *.exe" was loose in dist\ - and shipped an August 0.15.0 that way.
REM Looked for in 4-Agent first, then in the signed build's own dist\v<version> (never -test).
set "AVER="
for /f "tokens=2 delims=:, " %%v in ('findstr /c:"\"version\":" "%SRC_AGENT%\package.json"') do if not defined AVER set "AVER=%%~v"
if not defined AVER (
  set "SKIP_WHY=the agent version could not be read from %SRC_AGENT%\package.json"
  goto :SKIP_PKG
)
set "AGENT_SETUP=%REL%\4-Agent\v%AVER%\SmartEPT Agent Setup %AVER%.exe"
if not exist "!AGENT_SETUP!" if exist "%SRC_AGENT%\dist\v%AVER%\SmartEPT Agent Setup %AVER%.exe" (
  if not exist "%REL%\4-Agent\v%AVER%" mkdir "%REL%\4-Agent\v%AVER%"
  copy /y "%SRC_AGENT%\dist\v%AVER%\SmartEPT Agent Setup %AVER%.exe" "%REL%\4-Agent\v%AVER%\" >nul
)
if not exist "!AGENT_SETUP!" (
  set "SKIP_WHY=there is no signed agent %AVER% installer - build it with build-agent.bat"
  goto :SKIP_PKG
)
powershell -NoProfile -Command "if ((Get-AuthenticodeSignature -LiteralPath '!AGENT_SETUP!').Status -ne 'Valid') { exit 1 }"
if errorlevel 1 (
  set "SKIP_WHY=the agent installer !AGENT_SETUP! is not validly signed"
  goto :SKIP_PKG
)
echo       Using: !AGENT_SETUP!  - signed

call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%\src" "*.js" STALE_AGENT
if "!STALE_AGENT!"=="1" (
  set "SKIP_WHY=the agent code in src is NEWER than signed agent %AVER% - rebuild it with build-agent.bat"
  goto :SKIP_PKG
)
call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%" "main.js" STALE_AGENT2
if "!STALE_AGENT2!"=="1" (
  set "SKIP_WHY=the agent's main.js is NEWER than signed agent %AVER% - rebuild it with build-agent.bat"
  goto :SKIP_PKG
)

REM The one that actually caught something. An agent installer older than the service binary
REM or older than installer.nsh is an installer that does not contain the current blocking
REM half - and it looks completely healthy on the PC afterwards.
call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%\build" "*.*" STALE_AGENT3
if "!STALE_AGENT3!"=="1" (
  echo.
  echo  [X] STOP. The agent installer is OLDER than the policy service or installer.nsh.
  echo      It would ship without the current blocking half, install cleanly, and
  echo      block nothing.
  echo.
  echo      Rebuild with:  cd /d %SRC_AGENT%  ^&^&  build-agent.bat
  set "SKIP_WHY=the signed agent is older than the policy service or installer.nsh - rebuild it with build-agent.bat"
  goto :SKIP_PKG
)
echo       OK - installer is current and contains the service.

REM ===========================================================================
REM  4. LIVEVIEW RELAY  -  compiled binary, same staleness risk as the agent above
REM ===========================================================================
REM 18-Sep-2026: relay\server.js changed (native TLS/wss:// support) and nothing
REM here ever checked whether relay\dist\smartept-relay-win.exe was rebuilt to
REM match it - unlike the agent above, which has had this guard from the start.
REM Same failure class as the agent comment at the top of this file: a stale
REM relay installs fine, starts fine, and simply never upgrades to wss:// (or
REM whatever the next relay fix is), which looks like "it's just not working"
REM in the field with nothing in this build log to explain why.
echo.
echo [4/7] Checking the LiveView relay ...
if not exist "%SRC%\relay\dist\smartept-relay-win.exe" (
  echo  [X] No relay\dist\smartept-relay-win.exe - LiveView will not be available to any client.
  echo      Build it with:  cd /d %SRC%\relay  ^&^&  npm install  ^&^&  npm run build
  goto :SKIP_PKG
)
call :NEWER_THAN "%SRC%\relay\dist\smartept-relay-win.exe" "%SRC%\relay" "*.js" STALE_RELAY
if "!STALE_RELAY!"=="1" (
  echo.
  echo  [X] STOP. relay source is NEWER than the compiled relay binary.
  echo      Rebuild with:  cd /d %SRC%\relay  ^&^&  npm run build
  goto :SKIP_PKG
)
echo       OK - relay binary is current.

REM ===========================================================================
REM  5. ASSEMBLE
REM ===========================================================================
echo.
echo [5/7] Assembling %PKG% ...

REM Two folders, not three. There is no separate enforcer to install any more - the agent
REM setup IS the whole employee-PC install.
if exist "%PKG%" rmdir /s /q "%PKG%"
mkdir "%PKG%\1-SERVER" 2>nul
mkdir "%PKG%\2-AGENT"  2>nul
mkdir "%PKG%\DOCS"     2>nul

copy /y "%SERVER_ZIP%"  "%PKG%\1-SERVER\" >nul      || goto :COPYFAIL
copy /y "!AGENT_SETUP!" "%PKG%\2-AGENT\"  >nul      || goto :COPYFAIL

REM --- DOCS: an ALLOW-list, for the same reason deployment\ is one ------------
REM
REM 27-Aug-2026 (Ejaz): "many other files here are old ones". He was right, and the cause was
REM the wildcard this replaces: `SmartEPT-*.md` shipped EVERYTHING in the enforcer's docs
REM folder. The 27-Aug package went out with our internal build report, session report,
REM handover note, test plan, "Monday plan", status note and the build runbook that explains
REM how we assemble the product. Eight documents no client should ever receive, and the list
REM grows by itself every time somebody writes a note in that folder.
REM
REM This is the same mistake the deployment\ loop below already learned: a deny-list means
REM anything NEW ships by default. Named files only. A document that belongs in the box gets
REM added here deliberately, by someone who decided a client should read it.
set "CLIENT_DOCS=SmartEPT-Client-Installation SmartEPT-Agent-Deployment"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Client-Guide-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Deployment-Guide-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Operations"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Business-Case-Client-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Control-Summary-Auditor-2026-08-21"

set "DOCS_MISSING="
for %%D in (%CLIENT_DOCS%) do (
  set "GOT="
  if exist "%SRC_ENF%\docs\%%D.md"  ( copy /y "%SRC_ENF%\docs\%%D.md"  "%PKG%\DOCS\" >nul & set "GOT=1" )
  if exist "%SRC_ENF%\docs\%%D.pdf" ( copy /y "%SRC_ENF%\docs\%%D.pdf" "%PKG%\DOCS\" >nul & set "GOT=1" )
  if not defined GOT set "DOCS_MISSING=!DOCS_MISSING! %%D"
)

REM --- the customer's own manual set -----------------------------------------
REM
REM 27-Aug-2026 (Ejaz). These are the documents an actual END USER reads — the 288-page
REM Client User Manual, the quick start, the installation guide for their IT person, and the
REM printable first-day checklist. They were being written and never shipped, so every client
REM received the deployment notes and none of the manual.
REM
REM PDFs only, deliberately: the .md sources are ours and are where the next edit happens.
REM A client with both eventually reads whichever one is stale.
set "SRC_MANUAL=C:\Users\MPS\Documents\Claude\Projects\SmartEPT\docs\SmartEPT-Client-Manual-2026-08-24"
set "MANUAL_DOCS=SmartEPT-Client-User-Manual-2026-08-24 SmartEPT-Quick-Start-Guide-2026-08-24"
set "MANUAL_DOCS=%MANUAL_DOCS% SmartEPT-Agent-Installation-Guide-2026-08-24"
set "MANUAL_DOCS=%MANUAL_DOCS% SmartEPT-Admin-First-Day-Checklist-2026-08-24"

for %%D in (%MANUAL_DOCS%) do (
  if exist "%SRC_MANUAL%\%%D.pdf" (
    copy /y "%SRC_MANUAL%\%%D.pdf" "%PKG%\DOCS\" >nul
  ) else (
    set "DOCS_MISSING=!DOCS_MISSING! %%D.pdf"
  )
)

REM The INTERNAL-* files in that folder are never copied. They list 162 product defects and a
REM discovery audit; a wildcard here would put them in a client's hands, which is exactly the
REM accident the DOCS allow-list above exists to prevent.

if defined DOCS_MISSING (
  echo.
  echo  [X] These client documents are missing:
  echo     !DOCS_MISSING!
  echo.
  echo      Looked in:
  echo        %SRC_ENF%\docs          ^(the deployment documents^)
  echo        %SRC_MANUAL%   ^(the customer manual set^)
  echo.
  echo      Either write them, or take them off CLIENT_DOCS / MANUAL_DOCS in this
  echo      script. A name on those lists is a promise the client finds the file in the box.
  rmdir /s /q "%PKG%"
  goto :SKIP_PKG
)

REM README-FIRST goes at the ROOT, not in DOCS. Whoever opens this folder at a
REM client site reads exactly one file, and it has to be the one that says the
REM enforcer is not optional.
if exist "%SRC_ENF%\docs\README-FIRST.txt" copy /y "%SRC_ENF%\docs\README-FIRST.txt" "%PKG%\" >nul

REM Nothing else goes in the package root. There WAS an INSTALL-ON-THIS-PC.bat here that ran
REM two installers in sequence; it was removed on 27-Aug-2026 because the second of them was
REM the retired standalone enforcer, which the agent installer deletes. One file, one step.

if not exist "%PKG%\DOCS\SmartEPT-Client-Installation.md" (
  echo  [X] The installation guide is missing from %SRC_ENF%\docs.
  echo      A package without its instructions is not a deliverable.
  rmdir /s /q "%PKG%"
  REM was `pause ^& exit /b 1` - cmd un-escapes that to a literal & handed to
  REM pause, so the exit never ran and the build carried on past a fatal error.
  goto :SKIP_PKG
)

REM --- documents that describe a feature this build no longer has -------------
REM
REM This script already refuses to ship a binary older than its source, on the grounds that a
REM stale enforcer.exe reports healthy and enforces last week's rules. A document has the same
REM failure mode and is read by more people: "click Start learning, wait three to five days"
REM sends the client hunting for a button that was removed on 27-Aug-2026, and they conclude
REM the software is broken rather than the manual.
REM
REM Deliberately a hard stop, like the staleness gates. A doc that contradicts the product is
REM a support call with our name on it.
set "STALE_DOCS="
for %%D in (%CLIENT_DOCS%) do (
  if exist "%PKG%\DOCS\%%D.md" (
    findstr /i /c:"Start learning" "%PKG%\DOCS\%%D.md" >nul && set "STALE_DOCS=!STALE_DOCS! %%D"
  )
)
if exist "%PKG%\README-FIRST.txt" (
  findstr /i /c:"learning period" "%PKG%\README-FIRST.txt" >nul && set "STALE_DOCS=!STALE_DOCS! README-FIRST"
)

if defined STALE_DOCS (
  echo.
  echo  [X] STOP. These documents still describe the LEARNING period, which this
  echo      build does not have ^(SMARTEPT_ENFORCEMENT_LEARNING=false^):
  echo     !STALE_DOCS!
  echo.
  echo      They tell the client to press "Start learning" and wait days. There is no
  echo      such button. Enforcement is on or off.
  echo.
  echo      Update them in %SRC_ENF%\docs ^(the .md AND the .pdf^), then build again.
  echo      Nothing was written to %OUTROOT%.
  rmdir /s /q "%PKG%"
  goto :SKIP_PKG
)

REM ===========================================================================
REM  6. MANIFEST  - what is in the box, and what it is made of
REM ===========================================================================
echo.
echo [6/7] Writing the manifest ...

REM Written by PowerShell, not a cmd loop. A `goto` label inside a
REM parenthesised for-block does not work in cmd - the first version of this
REM silently produced a manifest with one entry, which is worse than none.
REM
REM 27-Aug-2026: it lived here as an inline -Command string and broke
REM ("Missing expression after ','" / "'Every' is not recognized"). A multi-line
REM PowerShell literal inside a .bat needs a trailing ^ on EVERY line, and `^|`
REM inside double quotes is passed through to PowerShell verbatim rather than
REM escaped. Both faults are cmd quoting, not logic, so the script moved to its
REM own file where cmd has nothing to escape.
powershell -NoProfile -ExecutionPolicy Bypass -File "%SRC%\deployment\write-manifest.ps1" -Package "%PKG%" -Stamp "%STAMP%"

if not exist "%PKG%\MANIFEST.txt" (
  echo  [X] The manifest was not written. A package nobody can verify is not shippable.
  goto :SKIP_PKG
)

REM ===========================================================================
REM  7. DONE
REM ===========================================================================
echo.
echo [7/7] Done.

REM The version bump now happens right after the server ZIPs (step 1) - see there.
echo.
echo ===============================================================
echo   RELEASE %VER% READY  -  everything is in  %REL%
echo.
echo     1-Update-for-Central\SmartEPT-Update-%VER%.zip
echo         Central - Upload Update - choose this file - version %VER% - Publish.
echo     2-Server-Setup\SmartEPT-Admin-Server-Setup-%VER%.zip
echo     3-Client-Package\v%VER%       the box for a NEW client
echo     4-Agent\v%AVER%               the signed agent inside it
if defined NEXTVER echo.
if defined NEXTVER echo   The next build will be version %NEXTVER%.
echo.
echo   Inside 3-Client-Package\v%VER%:
echo   1-SERVER    the Laravel app + vendor + INSTALL.bat
echo   2-AGENT     ONE file. The employee agent AND the service that blocks,
echo               in a single setup. It enrols itself at first sign-in.
echo   DOCS        the customer manual set + the deployment guides
echo   MANIFEST    every file with its checksum
echo.
echo   Two steps at a client site:
echo     1. On the server: unzip 1-SERVER and run INSTALL.bat
echo     2. On each PC:    run the setup in 2-AGENT. Next, Finish. Nothing else.
echo.
echo   Check the FIRST PC before rolling out:
echo     "C:\Program Files\SmartEPT Agent\resources\service\SmartEPTAgentService.exe" -status
echo ===============================================================
start "" "%REL%"
pause
exit /b 0

:COPYFAIL
echo  [X] A component could not be copied. The package is incomplete and has been removed.
if exist "%PKG%" rmdir /s /q "%PKG%"
set "SKIP_WHY=a file could not be copied into the package - see above"
goto :SKIP_PKG

REM ---------------------------------------------------------------------------
REM  :SKIP_PKG  - the client package could not be made. The server release above
REM  (update + setup) is complete and usable on its own, so it is reported as ready.
REM  Exit code 2 = "server release ready, client package skipped".
REM ---------------------------------------------------------------------------
:SKIP_PKG
if defined PKG if exist "%PKG%" rmdir /s /q "%PKG%"
if not defined SKIP_WHY set "SKIP_WHY=see the message above"
echo.
echo ===============================================================
if defined REUSE goto :SKIP_REUSE
echo   SERVER RELEASE %VER% IS READY  -  in  %REL%
echo.
echo     1-Update-for-Central\SmartEPT-Update-%VER%.zip
echo         Central - Upload Update - choose this file - version %VER% - Publish.
echo     2-Server-Setup\SmartEPT-Admin-Server-Setup-%VER%.zip
if defined NEXTVER echo.
if defined NEXTVER echo   The next build will be version %NEXTVER%.
echo.
echo   Only the NEW-CLIENT package was not made, because
:SKIP_REUSE
if defined REUSE echo   [X] The client package was NOT made, because
echo     !SKIP_WHY!
echo.
echo   Fix that, then run  RELEASE.bat -package  to make 3-Client-Package\v%VER%.
echo   It reuses the server setup above - no new version number.
echo ===============================================================
pause
exit /b 2

REM ---------------------------------------------------------------------------
REM  :NEWER_THAN <builtFile> <sourceDir> <pattern> <resultVar>
REM
REM  Sets <resultVar> to 1 when any file matching <pattern> under <sourceDir>
REM  is newer than <builtFile>.
REM
REM  Uses PowerShell rather than comparing "date" strings: cmd has no date
REM  arithmetic, and every hand-rolled attempt at it in this codebase has
REM  eventually got a month boundary wrong. One line of PowerShell is boring
REM  and correct.
REM ---------------------------------------------------------------------------
:NEWER_THAN
setlocal
set "BUILT=%~1"
set "DIR=%~2"
set "PAT=%~3"
set "RES=0"
REM 27-Oct-2026: this used to be a multi-line PowerShell inside a for /f backtick block. cmd could
REM not run it, the loop body never executed, and RES stayed 0 - so EVERY staleness check here
REM passed, whatever the dates (an August 0.15.0 agent went into the 27-Oct package). One line,
REM and the answer comes back as the exit code.
if not exist "%DIR%" goto :NEWER_DONE
powershell -NoProfile -Command "$b=(Get-Item -LiteralPath '%BUILT%').LastWriteTimeUtc; $n=Get-ChildItem -LiteralPath '%DIR%' -Filter '%PAT%' -Recurse -File -ErrorAction SilentlyContinue | Where-Object { $_.FullName -notmatch '\\(node_modules|dist|vendor|\.git|_to_delete)\\' -and $_.LastWriteTimeUtc -gt $b } | Select-Object -First 1; if ($n) { exit 1 }; exit 0"
if errorlevel 1 set "RES=1"
:NEWER_DONE
endlocal & set "%~4=%RES%"
exit /b 0

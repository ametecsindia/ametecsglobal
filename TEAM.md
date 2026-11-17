# AmetecsGlobal - how the project is laid out, and working from more than one PC

## Folders
```
AmetecsGlobal\
  index.php            front door -> core/
  core\                AmetecsGlobal itself: client sign-in + app tiles (index.php), Super Admin console (super.php),
                       licences & email (access.php), the gate every app passes through (gate.php)
  core\data\           this server's clients, licences, activity log, app list (never on GitHub)
  plugins\smartprs3\   everything SmartPRS3: its code, vendor, .env, storage, tools, mobile app
  plugins\<app>\       one folder per app as each joins (SmartEPT next)
  SYNC.bat / AUTO-SYNC.bat, sync.ps1, autosync.ps1, sync-apps.json   keeping it all up to date
```
Clients and their logins live in the database set in `plugins\smartprs3\.env` (SmartPRS3 is the base plugin).

## New PC (once)
1. Install Laragon and Git for Windows. Ask Ejaz to add your GitHub account to the repository (Write access).
2. Download `SETUP-NEW-PC.bat` from the repository into `C:\laragon\www`.
3. Start Laragon, open its Terminal, run:
   ```
   cd C:\laragon\www
   SETUP-NEW-PC.bat
   ```
4. Create your Super Admin (in `C:\laragon\www\AmetecsGlobal`): `php plugins\smartprs3\tools\make-super-admin.php you@ametecsindia.com "Your Name"`

## Every day
- Double-click `AUTO-SYNC.bat` and leave it running (minimised). About a minute after you stop saving, your changes go to GitHub; others' changes arrive within 5 minutes.
- `SYNC.bat` does the same once, on click.
- If the window says **"the same file was changed here and on GitHub"**: you and someone else edited the same lines. Nothing was overwritten. Tell Claude which file; it gets merged by hand.

## Rules
- **Each app is brought in on one PC** (`onlyOn` in `sync-apps.json`): SmartPRS3 and SmartEPT on Ejaz's office PC, the other apps on the second PC. Every other PC receives them through GitHub.
- **Code is shared; data is not.** Each PC has its own database and `core\data`. Shared client data belongs on one shared server.
- **The repository is public.** `.env`, `vendor`, `storage`, uploads, keys and `core\data` are never uploaded; the sync stops if one is about to be, and never pushes after a failed step.
- Two people editing the same lines is the one thing the sync cannot settle on its own - agree who works on which screen.

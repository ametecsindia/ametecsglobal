# AmetecsGlobal - how the project is laid out, and working from more than one PC

## Folders
```
AmetecsGlobal\
  index.php            front door -> core/
  core\                AmetecsGlobal itself: client sign-in + app tiles (index.php), Super Admin console (super.php),
                       app access & email (access.php), licensing for every app (licensing.php), the on-premise
                       licence server (licence-api.php), the gate every app passes through (gate.php)
  core\apps.json       the app list (shared through GitHub)
  core\data\           this PC's client contacts, activity log (never on GitHub)
  plugins\smartprs3\   everything SmartPRS3: its code, vendor, .env, storage, tools, mobile app
  plugins\<app>\       one folder per app as each joins (SmartEPT next)
  SYNC.bat / AUTO-SYNC.bat, sync.ps1, autosync.ps1, sync-apps.json   keeping it all up to date
```
Clients and their logins live in the database set in `plugins\smartprs3\.env` (SmartPRS3 is the base plugin).

## Organization Setup (central master)
- core/org.php is the one place for companies, branches, departments, designations, teams, shifts, employees
  (add / import / edit / delete / fields), logins, passwords and roles, and biometric / QR attendance devices.
  The same menu for every client; the list lives in core/setup.php.
- The central database is the source of employee data. Apps keep their own screens and settings as they are
  (salary structure, payslip and ID card formats, leave rules etc. stay in the app) and use the central employee data for their actions and reports.
- Inside an app those central screens are hidden; opening one by address goes to Organization Setup, and saving
  employee changes from inside an app is refused. Every app page has "<- All apps".
- App access (Organization Setup -> Apps): switch each app on/off per company, branch, department, team or employee
  (most specific wins; nothing set = on). Off = hidden in that app and no seat. Rules in core/data/app-access.json;
  SmartPRS3 applies them through a SYNC fix in surface/SqlStore.php.
- Fully wired: every module reads and writes the central tables (employees, companies, branches, departments,
  designations, teams, users). SmartPRS3 self-onboarding approval writes the new joiner into the central employees
  table; a login is created for every employee with an email the moment they are saved (User Management; default password optional).
- QR attendance, mobile device registration and biometric are core modules (Organization Setup only). If an app has
  its own version of these, hide it in that app (as done for SmartPRS3) and map the app to the core's records.
- New app: use these central tables for employees and organisation; never its own employee table or employee screens.

## Licences (Phase 1, 18 Nov 2026)
- One licence per client per app, for every app, in the database (`ag_licences`, history in `ag_licence_events`).
  Super Admin -> Licences: cloud or on-premise, plan (trial / monthly / yearly / perpetual), seats, validity, price;
  Activate / Suspend / Cancel / Renew; nothing is ever deleted. Only an Active, in-date licence opens the app.
- On-premise: issue a licence key (`AG-XXXX-XXXX-XXXX-XXXX`, shown once). The installed app calls
  `core/licence-api.php` (action activate / check); the first server is bound, others are refused until
  "Release from server". Offline servers get a signed .lic file (needs the signing key, which lives only on the
  live licence server). The old `core\data\access.json` is imported once and renamed `access.json.imported`.
## Payments & invoices (Phase 3, 18 Nov 2026)
- Super Admin -> Payment settings: Razorpay Key ID / Secret (stored encrypted, in the payment_gateways table SmartPRS3's
  console also uses), test or live mode, on/off; GST %, seller name, GSTIN, address and billing email for every invoice.
- Invoices (ag_invoices, numbered AG-<year>-00001) for paid upgrades, licence renewals ("Raise renewal invoice" on a
  licence with a price) and anything else (New invoice). Clients pay from Payments & invoices in their panel (Razorpay:
  cards, UPI, net banking), or with "Pay & upgrade" on Updates / "Renew online" on their licences.
- A payment counts only after the server checks it: Razorpay's signature, or "Check with Razorpay" on the invoice (for a
  client who paid and closed the page). Paying unlocks the upgrade / extends the licence; a refund (Razorpay or offline)
  takes it back. Offline payments: "Mark paid" with the UTR / cheque reference. Every invoice prints as a PDF.

## Updates & releases (Phase 2, 18 Nov 2026)
- Super Admin -> Updates & releases is the one place for every app's full releases, patches and plugins/modules.
  Upload a .zip with release.json {product, version, notes} at its top (bigger than the upload limit? copy it into
  core\data\releases\incoming and use "Add from the incoming folder"). Packages are checked (paths, product, version),
  stored in core\data\releases (never on GitHub) with their SHA-256, and signed when the server holds the licence key.
- Each release: notes, "requires version", minimum PHP, mandatory, download allowed, price, every client or selected
  clients (with a free grant per client), publish now / schedule / disable / withdraw. Every download, install,
  failure and rollback is logged per release.
- Cloud: "Install on cloud server" uses SmartPRS3's installer (verify, back up every replaced file, write, restore
  on failure, migrate); "Roll back last install" puts the backup back. The sync then shares the new code with every PC.
  Unsigned packages install only on non-production PCs.
- On-premise: the installed app asks core/licence-api.php (actions updates / download / report, with its key from its
  bound server). Client administrators see their own apps under Updates (and a banner on Home); paid upgrades wait
  for payment (Phase 3, Razorpay) unless Super Admin grants them free.

## New PC (once)
1. Install Laragon and Git for Windows. Ask Ejaz to add your GitHub account to the repository (Write access).
2. Download `SETUP-NEW-PC.bat` from the repository into `C:\laragon\www`.
3. Start Laragon, open its Terminal, run:
   ```
   cd C:\laragon\www
   SETUP-NEW-PC.bat
   ```
4. Create your Super Admin (in `C:\laragon\www\AmetecsGlobal`): `php plugins\smartprs3\tools\make-super-admin.php you@ametecsindia.com "Your Name"`

## Client panel & Super Admin look (Phase 4, 18 Nov 2026)
- One sidebar for the client panel and Organization Setup (`core/shell.php`, `core/assets/shell.css`, `core/assets/ui.js`):
  Dashboard, My applications, Modules & plugins, Available updates, Upgrade history, Installations, Licences,
  Subscription & plans, Payments & invoices, Company profile, Users & permissions, Organization setup, App access,
  Notifications, Activity history, Support & help, Account settings. Employees see only Dashboard, Notifications, Support, Account.
- Bell + Notifications: updates, overdue invoices, expiring/suspended licences, silent on-premise servers, failed installs.
- Super Admin -> System & health: database, PHP, disk, signing key, Razorpay, email, security warnings, sync log.
- Works on phones (menu button), long tables page by 25, buttons can't be pressed twice.

## Connecting an on-premise install to the hub (Phase 5, 18 Nov 2026)
- An on-premise SmartPRS3 (or any app using the SmartPRS licence client) needs one line in its `.env`:
  `SMARTPRS_LICENCE_SERVER=https://<the AmetecsGlobal server>/core/update.php`
  then the client activates with the AG-XXXX-XXXX-XXXX-XXXX key from Super Admin -> Licences -> the licence -> Issue licence key.
- From then on everything comes from AmetecsGlobal: activation binds the key to that server, heartbeats carry renewals,
  seats and suspensions, Administration -> Updates & Licence shows releases published in Updates & releases, a paid
  upgrade is shown as "pay in your AmetecsGlobal panel" until paid or granted free, and each install is reported back
  (Upgrade history / Installations). A copied install on another machine is refused and stops.
- Apps written fresh can use the JSON door instead: `core/licence-api.php` (actions activate, check, updates, download, report).

## Dashboard, My applications, Modules & plugins (Phase 6, 18 Nov 2026)
- Client menu is shorter: Dashboard, My applications, Modules & plugins, Licences & plans, Payments & invoices, the
  Organization pages, Activity history, Support. Updates open from an app's card; notifications are the bell; account
  settings are under the user menu. Old links (?v=plans, installs, upgrades) land on the page that now holds them.
- Dashboard: one block per application the company uses, with that app's own figures (SmartPRS3: employees, present,
  absent, on leave, late, early logouts, leave requests, payroll - from the same muster SmartPRS3 itself shows).
- Any app can supply figures: put `plugins/<app>/ag-stats.php` returning `function (PDO $pdo, string $tenantId): array`
  with `['tiles' => [['label', 'value', 'sub', 'tone' => green|amber|red|blue]], 'list' => [...]]`. On-premise installs
  send the same array (SmartPRS3 does it with its hourly check-in; others: licence-api action "stats").
- My applications: only what the company holds - version, cloud/on-premise, licence, seats, modules, update status.
- Plugins: upload with Type "Plugin / module" and a Plugin code (same code for every version, e.g. gst-returns). No price
  = free for every client holding the app. Clients Buy (Razorpay), then switch it on/off and, on-premise, Download /
  Update. A purchase covers later versions. Super Admin -> Licences -> the client's licence -> Plugins for this client:
  Give free, Take back free, Block, Stop/Allow downloads. Apps ask `AmetecsPlugins::on($tenant, $app, $code)`.

## Every day
- **Once per PC:** double-click `INSTALL-AUTOSYNC.bat`. AUTO-SYNC then runs hidden and starts by itself at every Windows sign-in (no window to keep open). Without it, `AUTO-SYNC.bat` must be left running (minimised). About a minute after you stop saving, your changes go to GitHub; others' changes arrive within 5 minutes. Only the AmetecsGlobal folder is watched.
- `SYNC.bat` does the same once, on click.
- If the window says **"the same file was changed here and on GitHub"**: you and someone else edited the same lines. Nothing was overwritten. Tell Claude which file; it gets merged by hand.

## Rules
- **Plugins are edited inside AmetecsGlobal** (`plugins\<app>` is the master copy). The original app folders (e.g. `C:\laragon\www\SmartPRS3`) are never read or touched by the sync. A plugin built or changed on one PC reaches the other through GitHub, and the sync sets it up there by itself (.env, PHP libraries, database). Releases and patches for every plugin will come from AmetecsGlobal Super Admin (the update hub, decided 18 Nov 2026).
- **Shared through GitHub (same on every PC):** all code, screens and logic - client view, Super Admin console, every app in `plugins\`, and the app list (`core\apps.json`).
- **Kept on each PC:** its own database (clients, licences, logins, Super Admins) and `core\data` (contacts, activity log). Each person creates their own Super Admin on their own PC.
- **Database changes travel automatically:** when an app's code arrives with new database tables, the sync updates that PC's database by itself.
- **The repository is public.** `.env`, `vendor`, `storage`, uploads, keys and `core\data` are never uploaded; the sync stops if one is about to be, and never pushes after a failed step.
- Two people editing the same lines is the one thing the sync cannot settle on its own - agree who works on which screen.

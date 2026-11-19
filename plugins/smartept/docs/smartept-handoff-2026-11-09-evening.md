# SmartEPT handoff — 09 Nov 2026 (evening)

Supersedes the 09-Nov morning handoff (`claude/smartept-handoff-2026-11-09.md`). Covers 10:45 – 15:20 IST, 09-Nov.

## 1. State at 15:20 IST

| Area | Where it stands |
|---|---|
| Server code | All changes below are on Ejaz's PC (`C:\laragon\www\smartept`), byte-checked |
| Server tests | **536 / 536 pass** (1 skipped) — Claude's workspace, PHP 8.3, on update 4.1 + every change below |
| Live server | Update **4.2** applied. LiveView relay **now running** (`/relay/` answers the relay's own 404, not nginx 502) |
| Not yet live | QR scanner clock 12-hour + QR module Organisation time zone (written after 4.2) |
| Agent | Scanner auto-close / punch-OUT sign-out **written, not built**. Version stays 0.40.0 |
| Admin app | Built 14:18 as 1.1.0 (has all features incl. dark icon). `build.gradle` now really 1.1.1 / code 3 |
| QR app | 1.3.6 unchanged this session |

## 2. Ejaz's next steps (in order)

1. **Server release** (QR clock + ledger times): push → `RELEASE.bat` → Central upload → Check for Update.
2. **Agent:** `build-agent.bat` (0.40.0, no bump) → reinstall on PCs. Needed for the scanner fix.
3. **Admin app:** Android Studio build → `C:\SmartEPT-Releases\6-Mobile-Apps\SmartEPT-Admin\v1.1.1`. Install over 1.1.0.
4. **LiveView check:** close old tiles (✕), reload Live View, Start. Report any tile still "Reconnecting…".

## 3. Problems fixed today (root cause → fix)

| # | Symptom | Root cause (confirmed) | Fix |
|---|---|---|---|
| 1 | LiveView "Relay connection closed" every few days | **(a)** `relay/dist/smartept-relay-linux` shipped in every update was built on Windows → crashes on Linux at start (`… or --sea to avoid bytecode`). 19-Sep server-built copy worked until an update replaced it; failure showed at the next relay restart. **(b)** every update also carried the build PC's `relay-secret.txt` over live's key. | New Linux relay **built on Linux** (`--no-bytecode`), tested end to end (frame passes, forged token refused), sha256 `e67f1371…46d5`, in `relay\dist` and in update 4.2. `relay\package.json` build script now `--no-bytecode --public-packages "*" --public`. `rebuild-server-zip.bat` strips relay secret/status/TLS/log files from packages. `install-linux.sh` also writes `relay/dist/relay-secret.txt`. |
| 2 | Relay never came back after a stop | No self-start | New `smartept:relay-ensure` (scheduler, every minute): key file = signing key; relay started with `RELAY_SECRET` in its environment (works even if the file is unwritable); stale-key relay restarted; launched via `proc_open`/`popen` (live blocks `exec`), detached (`setsid`, inherited handles closed); logs reason to laravel.log (max 1 line / 10 min) incl. relay's own output from `storage/logs/liveview-relay.log`. |
| 3 | Tiles stuck on the error | No retry | LiveView tile reconnects on a fresh session every 5 s ("Reconnecting…") instead of stopping. |
| 4 | Late minutes 0 for everyone on 09-Nov | **09-Nov-2026 was in the holiday list** ("Diwali", from starter data `DatabaseSeeder.php` line 80). Late is never calculated on a holiday. | Ejaz deleted the holiday and toggled Late from → recalculated. **Open:** holiday add/delete should recalculate by itself; seeder date should be 08-Nov — awaiting Ejaz's yes. |
| 5 | QR "Activate app" details at the bottom of the screen | Global toast + page reload at the top | Page returns scrolled to that employee (`#emp-{id}`); details in a card under the row with Copy and ×. Test updated. |
| 6 | Agent stays on the scanner after punch; no sign-out on punch OUT | Scanner closed itself only from the gate wall | While the scanner is open the agent polls gate-status every 3 s; when `last_punch_at` changes → IN: back to main window; OUT: same as Sign out. (`main.js`, `preload.js`, `renderer.js`) |
| 7 | Scanner clock 24-hour; QR module fixed to India time | Hard-coded `hour12:false` / `Asia/Kolkata` (10 places) | New `Qronex::tz()` = Organisation time zone; clock "2:23:17 PM"; ledger tables 12-hour; exports, filters and defaults in Org zone. |

## 4. Admin mobile app changes

- **QR Attendance** menu cards (8): Dashboard, Ledger, Approvals, Phones, Remote, Employees, Offices, Policy — open the QR module in-app, signed in (`QrActivity`, module side menu hidden). Shown when plan has `qr_attendance`, attendance source = QR, role in Super/Company/HR Admin, Auditor, Compliance Officer.
- Bug fixed: `base_role` is JSON null for built-in roles; Android read it as "null" → cards hidden. Now falls back to role (as the web does).
- Organisation › Attendance source: QR option added. Biometric menu in QR mode = "Gate-to-PC" with only that card.
- **Attendance** = one line per employee (Employee · In · Out · Late · Status; Date column when From ≠ To); tap → details + Edit. Generic `"columns"` support in `ScreenActivity`.
- **Icon:** dark `#101820` background, stacked SmartEPT logo (white "Smart", teal "EPT"); name "Admin Console"; sign-in shows "SmartEPT  Admin Console".
- Open project in Android Studio: `C:\Users\MPS\Documents\Claude\Projects\SmartEPT\admin-app\settings.gradle`.

## 5. Files changed this session

**Server** (`C:\laragon\www\smartept`): `app/Console/Commands/EnsureLiveViewRelay.php` (new), `routes/console.php`, `resources/views/admin.blade.php` (LiveView tile only), `deployment/rebuild-server-zip.bat`, `install-linux.sh`, `relay/package.json`, `relay/dist/smartept-relay-linux`, `packages/qronex-module/src/Qronex.php`, `…/src/Http/Controllers/DisplayController.php`, `…/src/Http/Controllers/Admin/AttendanceController.php`, `…/src/Http/Controllers/Admin/EmployeeController.php`, `…/resources/views/display.blade.php`, `…/resources/views/attendance.blade.php`, `…/resources/views/employees/index.blade.php`, tests `EnsureLiveViewRelayTest.php` (new), `QronexModuleTest.php`.

**Agent** (`…\SmartEPT\agent`): `main.js`, `preload.js`, `src/renderer/renderer.js`.

**Admin app** (`…\SmartEPT\admin-app`): `Spec.java`, `MenuActivity.java`, `ScreenActivity.java`, `LoginActivity.java`, `QrActivity.java` (new), `AndroidManifest.xml`, `app/build.gradle` (1.1.1/3), `assets/menus.json`, `specs/qr.json` (new), `specs/setup.json`, `specs/people.json`, launcher icons (`mipmap-*`, `values/ic_launcher_colors.xml`).

## 6. Open items

- Holiday add/delete → auto-recalculate that day; seeder Diwali 09-Nov → 08-Nov. **Awaiting Ejaz's yes.**
- Confirm LiveView pictures arrive after reload (relay is up; viewing not yet seen by Claude).
- Agent scanner change and Admin app 1.1.1 not yet built or tried on a real PC/phone.
- Unused: `admin-app/res/drawable/ic_launcher.xml` (old icon) left in place.
- `Projects\SmartEPT\relay` is an old proof-of-concept copy — the real relay is `C:\laragon\www\smartept\relay`.

## 7. Rules confirmed this session

- Do not assume; prove the root cause with data (repro on the code or live data) before naming it.
- Fix in code, not by hand on the server; Ejaz does not want server changes for product issues.
- Byte-check **every** committed file (a commit silently wrote stale `build.gradle` once).
- Admin app stays dark, icon included.
- Times everywhere: 12-hour AM/PM, Organisation time zone.
- After a QR punch the agent returns to its main window; punch OUT signs the agent out.

## 8. Useful facts

- Live relay check: `https://admin.smartept.com/relay/view` → empty **404** = relay up; **502** = relay down.
- Live log: console → System → Help & Troubleshooting → Application log → Copy for developer. Look for `LiveView relay:` lines.
- Live is Ubuntu (nginx 1.18), PHP-FPM blocks `exec`; scheduler runs inline in web requests (`SchedulerKeepAlive`).
- Relay build on Linux needs `~/.pkg-cache/v3.6/fetched-v22.23.2-linux-x64` (sha256 `6e3f32d0…3886`, from GitHub yao-pkg/pkg-fetch v3.6).

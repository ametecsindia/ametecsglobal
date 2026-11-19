# SmartEPT handoff and report — 09 & 10 Nov 2026

This replaces every earlier handoff, including `smartept-handoff-2026-11-09-evening.md`. It covers every session on 09 and 10 Nov 2026, up to 21:40 IST on 10-Nov.

> **Change freeze (Ejaz, 10-Nov 21:39):** no further change to any module (server, Central, QR module, agent, mobile apps, website) unless Ejaz first gives permission for that specific change.

---

## 1. State at 21:40 IST, 10-Nov

| Area | Where it stands |
|---|---|
| Server code (`C:\laragon\www\smartept`) | All 09/10-Nov changes are on Ejaz's PC, and Ejaz has pushed them to git (21:23). |
| Server version | `version.json` was reset to **4.5**, so the next `RELEASE.bat` builds 4.5 again. 4.5 has not been published. After the build the file shows 4.6, which is the *next* number, not a release. |
| Live (`admin.smartept.com`) | **On an older release.** The QR-only gate, the auto sign-out fixes, the QR = sign-in/sign-out rules and the label are **not live** yet. |
| Central (`C:\laragon\www\smartept-central`) | Landing page v9 (QR Attendance plus the Admin mobile app) is **live on smartept.com**. The "Sync from live page" button is deployed. The SmartEPT 3.0 pricing code is on the PC; its migration has **not run** on local or production. |
| Agent (`…\SmartEPT\agent`, its own git repo) | Changes are on the PC and **not built**. Version stays **0.40.0**. |
| EPT QR Attendance app | 1.3.7, targeting Android 16 (Play prep). Built in Android Studio by Ejaz. |
| Admin Console app | 1.1.2 (code 4), targeting Android 16, with Organisation time zone and Live View notice. Built in Android Studio by Ejaz. |
| Server tests | The 10-Nov changes have **not** been run through the full suite in Claude's workspace (PHP libraries can't be downloaded there). **RELEASE.bat is the first full run.** |

---

## 2. Ejaz's next steps (in order)

1. **Agent repo:** commit and push in `C:\Users\MPS\Documents\Claude\Projects\SmartEPT\agent` (`git add main.js src/renderer/renderer.js`).
2. **`RELEASE.bat`** in `C:\laragon\www\smartept`. It runs the migrations (incl. `skip_geofence`) and the tests, and builds **4.5**. Send the output if any test fails.
3. **Central → Upload Update** (version 4.5) → tick Ametecs → Publish. Then **live → Check for Update**.
4. **Central pricing:** run `php artisan migrate` in `smartept-central` (local first, then production) so the new prices are written. Then deploy Central.
5. **Agent:** `build-agent.bat` (stays 0.40.0) → reinstall on the PCs.
6. **Test on one PC with Attendance source = QR:**
   - Sign in: the agent shows "Scan the QR" and opens only after a QR IN.
   - QR OUT (scanner or phone): the agent signs out within 30 s.
   - Agent Sign out: the QR ledger shows an OUT labelled **"Auto Punch Out due to Agent Sign out"**.
   - Sign in and don't scan: signed out after 10 minutes.
   - Lock the PC after shift end + N: signed out.

---

## 3. Work done — 09 Nov

### 3.1 Morning (agent, server and QR app bugs from the 08-Nov rollout)

| # | Symptom | Root cause (confirmed) | Fix |
|---|---|---|---|
| 1 | Attendance scanner "Too Many Attempts" | QR-scanner throttle shared the agent group's counter | Own throttle per caller (`ThrottlePerCaller` middleware) |
| 2 | First sign-in bounced to login | Stale "Still working?" idle cache; late 401 from the old token | Cache cleared on register-device; agent ignores a 401 for an old token |
| 3 | Away 19h 30m | Last night's session activity opened the new day | `DayTimeline::spill()` |
| 4 | Agent opened without QR scan | Altaf had an employee-level Gate exclusion | Ejaz removed the setting |
| 5–6 | Signed out right after the QR scan | No heartbeats while on the wall; heartbeat landed before the LOGIN record | Grace for new sessions; orphan-sweep exemptions (**the 12-hour exemption was removed on 10-Nov, see 4.3**) |
| 7 | After-hours staff saw the wall, then were kicked | No refusal at sign-in | register-device returns 403 `OUTSIDE_SHIFT` |
| 9 | Sign-in "HTTP 429" | All unauthenticated throttles keyed by IP | Per path and per caller |
| 10 | QR app Dashboard stuck on "Opening" | No deadline or retry | Step deadlines and "Try again" |
| 11 | Times not in the Organisation time zone | Device zone used | Server sends `timezone`; agent and app use it |
| 13 | Productivity over several dates had no Date column | — | Date column added |
| 14 | Check-in shown as 2:31 AM | Agent stamped in UTC before the QR IN | Sign-in stamped before that day's first gate IN is ignored |

### 3.2 Midday (LiveView and others) — update 4.2

- **LiveView "Relay connection closed":** the Linux relay in every update had been built on Windows, and every update also overwrote live's relay key. A Linux-built relay now ships, update packages no longer carry the secret, and `smartept:relay-ensure` restarts the relay every minute. Tiles reconnect every 5 s.
- **Late minutes 0 on 09-Nov:** 09-Nov was in the holiday list ("Diwali" from the starter data). Ejaz deleted it.
- **QR "Activate app"** details now open under the employee's row.
- **Agent scanner:** after a punch the agent returns to its main window.
- **QR module:** 12-hour clock in the Organisation time zone.
- **Admin app 1.1.1:** QR Attendance cards, one-line Attendance table, dark icon.

---

## 4. Work done — 10 Nov

### 4.1 Website — smartept.com (live)

- **QR Attendance brief** (18 points). Backup: `public/landing.html.bak-20261110-pre-qr`.
  - Hero: **Built-in QR Attendance** in bold.
  - Admin Console app line in the hero.
  - Small punch-in GIF (`/img/smartept-qr-punch-in.gif`) at the left edge of the dashboard image.
  - New sections: `#qr` "Attendance Without Another Device." and `#mobile` "Your whole team. Live. In your pocket."
  - **All biometric-support claims removed.** QR Attendance is listed in every plan from Standard.
- **Central Landing editor:** new **"Sync from live page"** button (`landing/sync-from-live`), which imports `public/landing.html` into the editor. Pushed and deployed.

### 4.2 QR Attendance = gate (server)

1. **Attendance source = QR → only QR punches count** (gate, attendance, sign-in). Reader and biometric punches are ignored: `BiometricLog::qrOnly()/onlyCounted()`, `BiometricCloudSync`, `GateService::stateFor`. Commit `0390c35` (release 4.4).
2. **QR punch OUT = agent sign-out.** Any QR OUT (phone, remote, scanner) ends the agent session; SmartDCM follows within 30 s (`EptHost::recordPunch` → `endSession`).
3. **Agent sign-out = QR punch OUT.** Every sign-out path (button, force logout, auto sign-out, 10-min wall, SmartDCM logout) writes a QR OUT with mode `AGENT_SIGNOUT`, but only if the employee is still IN, so there is never a duplicate. Implemented by `EmployeeDevice` updated hook → `GateService::punchOutOnSignOut`.
4. **Label:** an `AGENT_SIGNOUT` punch shows **"Auto Punch Out due to Agent Sign out"** in place of the ref (QRX-…). It appears in the QR ledger (Office · gate and Ref columns), in the Excel/CSV/PDF export (Mode and Ref) and in the mobile app history (office field). See `AttendanceEvent::AGENT_SIGNOUT_LABEL` and `shownRef()`.
5. **Demo office "Skip location check"** tick box on the office form, for the Google Play reviewer. Migration `2026_11_10_000001_add_skip_geofence_to_qx_offices`. The QR, phone, fake-location and duplicate checks still apply.
6. **QR ledger layout:** one-row toolbar, filter card, **Company column**, no text wrapping.
7. **Organisation time zone and 12-hour times** across the whole QR module (dashboard, remote, approvals, phones, IN/OUT decision, remote permission "today"). Sign-in reply now includes `timezone` for the Admin app.
8. **Test fixes for RELEASE.bat:** `QrToken::make/age` now honour time-travel (`now()->getTimestamp()`); the portal-session test was updated accordingly.

### 4.3 Post-shift auto sign-out — root cause and fix

**Case:** Altaf, laptop "Laptop-Altaf". Lid closed at 18:04, still signed in and reporting ONLINE at 20:52.

**Data (production):**
- Sessions: the last one ran 16:10:08 → 18:04:36 and ended on LOCK.
- Last punch: biometric OUT at 18:06.
- Device: ACTIVE / ONLINE, heartbeating.
- Shift: General, 09:00–18:00, auto sign-out +15 min.

**Root causes (both verified in code):**
1. **Server:** once a lock has closed the session, the orphan-device sweep was skipped by the 08-Nov "still on the QR wall" exemption (gate state ≠ IN within 12 h of sign-in). Altaf's OUT at 18:06 matched it, so the PC would have stayed signed in until about 04:10.
2. **Agent:** in Presence-only mode no idle monitor runs, so every heartbeat said ONLINE, even while locked. The server's "Still working?" check therefore never started.

**Fixes (`AutoLogoutPostShift.php`, `AgentStatusController.php`, agent `main.js`):**
- After shift end + N, the PC is signed out **whatever the punches say**. The 08-Nov exemption has been deleted.
- An **Idle or locked** PC past the cutoff is signed out at once, with no question.
- **Punch-in screen: 10 minutes maximum** (`WALL_MINUTES = 10`) after agent sign-in, at any hour, then force sign-out. This is checked on every gate poll too, so it doesn't depend on the scheduler.
- **Agent:**
  - a locked PC reports IDLE;
  - Presence-only uses Windows idle time;
  - a 401 on the punch-in screen returns to sign-in;
  - **any QR or door punch OUT signs the agent out**, checked on every heartbeat, every unlock and after a scan.
- **Tests:** `AutoSignOutAfterShiftTest` (new: Altaf's case, and idle past cutoff); `QrWallAfterHoursTest` updated to the 10-minute rule.

### 4.4 Mobile apps and Play Store (other session)

- Play Store submission guide, as a Claude Doc. Demo company steps for the reviewer.
- QR app 1.3.7 and Admin app 1.1.2 target Android 16, with edge-to-edge screens and Play graphics.
- Admin app: every time shown in the Organisation time zone (`Api.applyTimeZone()`), plus the Live View responsible-use line.
- Privacy policy sections 7 and 8 (both apps) on smartept.com/privacy, in Central.

### 4.5 SmartEPT 3.0 pricing (Central, on PC, not deployed)

| Plan | On-Premises (one-time, per user) | Cloud (per user per month: 10–24 / 25–49 / 50 users) |
|---|---|---|
| Standard | ₹1,500 | ₹150 / ₹125 / ₹100 |
| Enforcer | ₹2,500 | ₹250 / ₹225 / ₹200 |
| Commander | ₹3,000 | **Not offered on Cloud** |

- **On-Premises:** minimum 15 users, installation ₹5,000, AMC 20% from year 2.
- **Cloud:** quarterly billing only, minimum 10 users, 51 or more users goes to Contact Sales.
- **Both:** Setup & Onboarding ₹2,500 + ₹100 per user.
- Applied by a migration (old bands saved first). **Ejaz must run `php artisan migrate` in Central.**

### 4.6 Design only (no code)

- Architecture for storing screenshots in Google Drive (one-page image). Nothing was built.

---

## 5. Files changed on 10-Nov (this session)

**Server (`C:\laragon\www\smartept`):**
- `app/Models/BiometricLog.php`
- `app/Http/Controllers/Api/BiometricController.php`
- `PublicApiController.php`
- `app/Services/BiometricCloudSync.php`
- `app/Services/GateService.php`
- `app/Console/Commands/AutoLogoutPostShift.php`
- `app/Http/Controllers/Api/AgentStatusController.php`
- `packages/qronex-module/src/Services/QrToken.php`
- `packages/qronex-module/src/Models/AttendanceEvent.php`
- `packages/qronex-module/src/Http/Controllers/Admin/AttendanceController.php`
- `packages/qronex-module/src/Http/Controllers/Api/MobileController.php`
- `packages/qronex-module/resources/views/attendance.blade.php`
- `tests/Feature/QronexModuleTest.php`
- `tests/Feature/QrWallAfterHoursTest.php`
- `tests/Feature/AutoSignOutAfterShiftTest.php` (new)
- `version.json` (4.5)

**Other session, same day:** `EmployeeDevice.php`, `EptHost.php`, `AuthController.php`, the QR module offices/remote/dashboard/approvals/devices views, `RemotePermission.php`, `AttendanceVerifier.php`, the `skip_geofence` migration.

**Agent:** `main.js`, `src/renderer/renderer.js`.

**Central:**
- `public/landing.html` and `public/img/smartept-qr-punch-in.gif`
- `routes/web.php`, `LandingAdminController.php`, `admin/landing.blade.php`, `LandingSync.php`
- Pricing files (10) and the privacy page

**Backups:**
- `C:\laragon\www\smartept\Claude outputs\backup-2026-11-10-qr-gate`
- `C:\laragon\www\smartept\Claude outputs\backup-2026-11-10-autosignout`

---

## 6. Open items (waiting for Ejaz)

- Today's 8:37 AM reader punch is still in Altaf's attendance. Correct it in the console if wanted; Claude doesn't delete real data.
- Holiday add/delete should recalculate that day automatically, and the starter-data Diwali date should be 08-Nov. **Waiting for Ejaz's yes.**
- The cause of Altaf's three "USER" sign-outs at 09:13, 15:29 and 16:09 (each re-signed in within a minute) is unverified. The agent log on that PC would show it.
- Limit: a QR IN cannot sign the agent in from the sign-in screen; the password is still needed once.
- Door (biometric) punches reach SmartEPT only after the provider's delay (eTimeOffice about 20–27 min).
- The pricing slab jump, and the decisions listed in the pricing session (10,000-user cap, AMC from year 2, the 10% ecosystem tick box removed), still need Ejaz's review.

---

## 7. Standing rules (confirmed again 09/10-Nov)

- **No change to any module without Ejaz's permission. Never touch working modules.**
- Don't assume. Prove the root cause with live data and code before stating it.
- Fix in code, never by hand on the live server. Ask before running anything on servers or operating the PC.
- Ejaz pushes to git himself; Claude never pushes.
- Agent version stays 0.40.0. Server release stays 4.5 until Ejaz says otherwise.
- Times: 12-hour AM/PM, in the Organisation time zone, everywhere.
- QR Attendance replaces biometric. Website copy says built-in QR Attendance only.
- QR = gate every time:
  - QR IN is required before every agent sign-in;
  - QR OUT = agent sign-out;
  - agent sign-out = QR OUT, labelled "Auto Punch Out due to Agent Sign out".
- After shift end + auto sign-out minutes, sign out whatever the punches or status. The walk to the scanner gets at most 10 minutes.

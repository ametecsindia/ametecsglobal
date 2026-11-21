# AmetecsGlobal mobile app (Android)

One app for every AmetecsGlobal employee. Copied from the ROVEXY Android app (20 Nov 2026); the ROVEXY app itself is unchanged.

- Sign in: AmetecsGlobal Employee ID (or email) and password. A new phone waits for approval (ROVEXY console → Devices).
- Home: tiles for the apps this person may use (licences, App access, role). ROVEXY work is native; Payslips, Leave,
  Attendance, QRONEX and SmartEPT open as AmetecsGlobal pages already signed in.
- Server address on the phone: the ROVEXY address, e.g. `http://<PC-IP>/AmetecsGlobal/plugins/rovexy/public`.

Build (Android Studio, or `gradlew assembleDirectDebug`): APK in `app/build/outputs/apk/direct/debug/`.
Play bundle: `build-play-release.bat` (upload key from `%USERPROFILE%\.gradle\gradle.properties`, never in this folder).

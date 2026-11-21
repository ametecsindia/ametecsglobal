<?php
/**
 * SCAN QR ATTENDANCE - QRONEX INSIDE AMETECS GLOBAL (owner, 20 Nov 2026: "employees should be able to use the QRONEX
 * attendance scanner functionality directly from the unified application"). Works in the Ametecs Global phone app
 * (its web view) and in any browser with a camera. The camera reads the gate screen's rotating QRONEX code, the phone's
 * location goes with it, and QRONEX itself decides (plugins/qronex/.../AgScanController -> AttendanceVerifier): gate
 * signature and time window, office/gate assignment, geofence, GPS accuracy, replay. Each scan carries a fresh one-time
 * ticket from here (?ticket=1: kind "scan", this person only, 60 s, single use), signed like the sign-in hand-over.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

header('Cache-Control: no-store');
session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$session = $pdo === null ? null : new Session(new SqlStore($pdo), PasswordHasher::platformDefault());
$session?->start();
$user = $session?->user();
if ($user === null) {
    isset($_GET['ticket']) ? http_response_code(401) : header('Location: ./');
    exit;
}
$tid = (string) $user['tenant_id'];
$emp = (int) ($user['employee_id'] ?? 0);
$ok = $emp > 0 && in_array('qronex', array_column(AmetecsRoles::apps($pdo, $user + ['tenant_id' => $tid]), 'app'), true);
// apps() lists QRONEX only for those who may open its console; scanning is every employee's, so check the licence + App access
if ($emp > 0 && !$ok) {
    $ok = AmetecsAccess::state($tid, 'qronex') === 'on' && AmetecsLicences::installed(AmetecsLicences::row($tid, 'qronex'))
        && !in_array($emp, array_map('intval', AmetecsAppAccess::hidden($pdo, $tid, 'qronex')), true);
}
if (isset($_GET['ticket'])) {
    header('Content-Type: application/json');
    if (!$ok) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'QR attendance is not on for you.']);
        exit;
    }
    $keyFile = __DIR__ . '/data/ag-sso.key';
    if (!is_file($keyFile)) {
        @mkdir(dirname($keyFile), 0700, true);
        file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX);
    }
    $body = rtrim(strtr(base64_encode((string) json_encode(['kind' => 'scan', 'tid' => $tid, 'emp' => $emp,
        'exp' => time() + 60, 'jti' => bin2hex(random_bytes(12))])), '+/', '-_'), '=');
    echo json_encode(['ok' => true, 't' => $body . '.' . hash_hmac('sha256', $body, trim((string) file_get_contents($keyFile)))]);
    exit;
}
$root = AmetecsMenu::root();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="assets/brand/favicon.svg" type="image/svg+xml"><title>Scan QR attendance · Ametecs Global</title>
<link rel="stylesheet" href="assets/fa/css/all.min.css">
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,"Segoe UI",system-ui,sans-serif;background:#0b0906;color:#f3e9d2;min-height:100vh;display:flex;flex-direction:column}
header{display:flex;align-items:center;gap:10px;padding:14px 16px}header a{color:#e8c977;text-decoration:none;font-weight:600}header b{color:#e8c977}
main{flex:1;display:flex;flex-direction:column;align-items:center;gap:14px;padding:6px 16px 24px;max-width:520px;width:100%;margin:0 auto}
h1{margin:4px 0 0;font-size:20px;color:#fff;text-align:center}p{margin:0;color:#cdbb92;font-size:13.5px;text-align:center;line-height:1.5}
.cam{width:100%;aspect-ratio:1;border-radius:20px;overflow:hidden;background:#000;position:relative;border:2px solid rgba(201,162,76,.5)}
.cam video{width:100%;height:100%;object-fit:cover}.cam .fr{position:absolute;inset:18%;border:3px solid #e8c977;border-radius:18px;box-shadow:0 0 0 999px rgba(0,0,0,.35)}
.go{width:100%;border:0;border-radius:14px;padding:15px;font-size:16px;font-weight:700;background:#c9a24c;color:#14100a;cursor:pointer}
.go.alt{background:transparent;border:1px solid rgba(201,162,76,.5);color:#e8c977}
.msg{width:100%;border-radius:14px;padding:14px;font-size:14px;text-align:center}.msg.ok{background:#0f3b23;color:#b6f0c8}.msg.bad{background:#3b1010;color:#f6b4b4}.msg.info{background:#211a0e;color:#e8d7ae}
.gps{font-size:12px;color:#a8977a}
</style></head><body>
<header><a href="./"><i class="fas fa-arrow-left"></i></a><span>Ametecs <b>Global</b></span></header>
<main>
  <h1><i class="fas fa-qrcode" style="color:#e8c977"></i> Scan QR attendance</h1>
  <?php if ($emp === 0): ?><div class="msg bad">This sign-in is not linked to an employee. Ask your administrator.</div>
  <?php elseif (!$ok): ?><div class="msg bad">QR attendance is not on for you. Ask your administrator.</div>
  <?php else: ?>
  <p>Point the camera at the QR code on your office gate screen. Your location is sent with it.</p>
  <div class="cam" id="cam" hidden><video id="v" playsinline muted></video><span class="fr"></span></div>
  <div class="msg info" id="msg">Tap Start to open the camera.</div>
  <button class="go" id="start"><i class="fas fa-camera"></i> Start scanning</button>
  <label class="go alt" id="photo" style="text-align:center;display:none"><i class="fas fa-image"></i> Take a photo of the QR instead<input type="file" accept="image/*" capture="environment" hidden></label>
  <div class="gps" id="gps">Waiting for location…</div>
  <?php endif; ?>
</main>
<?php if ($ok): ?>
<script src="<?= htmlspecialchars($root, ENT_QUOTES) ?>plugins/smartprs3/public/js/jsQR.js"></script>
<script>
(function () {
  var api = <?= json_encode($root . 'plugins/qronex/public/api/ag/scan') ?>;
  var v = document.getElementById('v'), msg = document.getElementById('msg'), gps = document.getElementById('gps'), cam = document.getElementById('cam');
  var start = document.getElementById('start'), photo = document.getElementById('photo');
  var cv = document.createElement('canvas'), cx = cv.getContext('2d', {willReadFrequently: true}), stream = null, busy = false, pos = null;
  function say(t, k) { msg.className = 'msg ' + (k || 'info'); msg.textContent = t; }
  // Inside the Ametecs Global phone app the page may be on plain http (an office server), where browsers give no live
  // camera or location to a web page: the app gives its own location (window.AGApp) and the photo button opens the
  // phone's camera. On https and in a normal browser the live camera below works as usual.
  var app = window.AGApp && typeof window.AGApp.location === 'function';
  if (app) {
    var poll = function () { try { var l = JSON.parse(window.AGApp.location() || 'null'); if (l && l.lat) { pos = {latitude: l.lat, longitude: l.lng, accuracy: l.acc}; gps.textContent = 'Location ready (±' + Math.round(l.acc) + ' m)'; } else gps.textContent = 'Getting location… make sure location is on.'; } catch (e) {} };
    poll(); setInterval(poll, 2000);
  }
  var live = window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
  if (!live) { photo.style.display = 'block'; photo.className = 'go'; start.style.display = 'none'; say('Tap the button below to take a photo of the QR on the gate screen.'); }
  if (!app && navigator.geolocation) navigator.geolocation.watchPosition(function (p) { pos = p.coords; gps.textContent = 'Location ready (±' + Math.round(p.coords.accuracy) + ' m)'; },
    function () { gps.textContent = 'Location is off - allow location, or the punch is refused.'; }, {enableHighAccuracy: true, maximumAge: 5000});
  function read(src, w, h) { cv.width = w; cv.height = h; cx.drawImage(src, 0, 0, w, h); var c = jsQR(cx.getImageData(0, 0, w, h).data, w, h, {inversionAttempts: 'dontInvert'}); return c && /^QRX1\./.test(c.data) ? c.data : null; }
  function stop() { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; cam.hidden = true; }
  function send(code) {
    if (busy) return; busy = true; stop();
    if (!pos) { say('Location is needed for QR attendance. Allow location and scan again.', 'bad'); busy = false; return; }
    say('QR read - checking with QR attendance…');
    fetch('scan.php?ticket=1', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (t) {
      if (!t.ok) throw new Error(t.message || 'Not allowed');
      return fetch(api, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({t: t.t, qr: code, lat: pos.latitude, lng: pos.longitude, accuracy: Math.round(pos.accuracy || 9999), agent: navigator.userAgent.slice(0, 120)})});
    }).then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); }).then(function (x) {
      var j = x[1];
      if (x[0] && j.ok) say('Punched ' + j.type + ' at ' + j.at + (j.office ? ' · ' + j.office : '') + (j.gate ? ' · ' + j.gate : ''), 'ok');
      else say(j.message || j.reason || 'The punch was refused.', 'bad');
      start.innerHTML = '<i class="fas fa-camera"></i> Scan again'; busy = false;
    }).catch(function (e) { say(e.message || 'Could not reach the server.', 'bad'); busy = false; });
  }
  photo.querySelector('input').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return; var img = new Image();
    img.onload = function () { var k = Math.min(1, 1280 / Math.max(img.width, img.height)), c = read(img, Math.round(img.width * k), Math.round(img.height * k)); c ? send(c) : say('No QR attendance code found in the photo. Take it again, closer and straight on.', 'bad'); };
    img.src = URL.createObjectURL(f);
  });
  start.addEventListener('click', function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { photo.style.display = 'block'; say('This browser cannot open the camera here - take a photo of the QR instead.'); return; }
    navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}, audio: false}).then(function (s) {
      stream = s; v.srcObject = s; cam.hidden = false; v.play(); say('Point the camera at the QR on the gate screen.');
      (function tick() { if (!stream) return; if (v.readyState >= 2) { var c = read(v, 480, Math.round(480 * v.videoHeight / (v.videoWidth || 480))); if (c) { send(c); return; } } requestAnimationFrame(tick); })();
    }).catch(function () { photo.style.display = 'block'; say('The camera could not open - allow camera access, or take a photo of the QR instead.', 'bad'); });
  });
  if (/[?&]go=1/.test(location.search)) start.click();
})();
</script>
<?php endif; ?>
</body></html>

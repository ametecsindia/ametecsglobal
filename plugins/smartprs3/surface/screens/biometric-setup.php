<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Biometric Device Setup</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
/*
 * BIOMETRIC DEVICE SETUP — SmartPRS 2's screen (AppController biometricSetupScreen +
 * BiometricConfigController), owner 16 Nov 2026: "remove the current content and implement
 * the exact coding and logic as available in SmartPRS2". Device cards → Add / Edit form with
 * provider-specific fields (Push / eTimeTrackLite / eTimeOffice / Custom API + presets),
 * Test connection, Sync now, and the Employee Mapping card. Writes go to actions/biometric.php;
 * pulling is BiometricSync, ingest is PunchIngest (unchanged).
 */
$bsEsc = static fn (mixed $v): string => e((string) ($v ?? ''));
$bsDevices = liveRows($store, 'biometric_configs');
usort($bsDevices, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
$bsBranchRows = liveRows($store, 'branches');
$bsBranches = orgDistinctLabels($store, 'branches', array_column(array_map(static fn (array $b): array => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? '')], $bsBranchRows), 'name', 'id'));
asort($bsBranches);
$bsResult = $_SESSION['bio_result'] ?? null;
unset($_SESSION['bio_result']);
$bsConfirm = $_SESSION['bio_map_confirm'] ?? null;
unset($_SESSION['bio_map_confirm']);
$bsBulk = $_SESSION['bio_bulk'] ?? null;
unset($_SESSION['bio_bulk']);
$bsKept = $_SESSION['bio_form'] ?? null; // typed values kept across Test / Sync now (SmartPRS 2 kept the form open)
unset($_SESSION['bio_form']);
$bsCsrf = '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="biometric"><input type="hidden" name="back" value="biometric-setup">';

// The result line SmartPRS 2 printed in #bio-result.
$bsResultHtml = '';
if (is_array($bsResult)) {
    if (!(bool) $bsResult['ok']) {
        $bsResultHtml = '<span style="color:var(--red,#dc2626)">' . e((string) ($bsResult['message'] ?? ('Connection failed: ' . (string) ($bsResult['error'] ?? 'unknown')))) . '</span>';
    } elseif (($bsResult['op'] ?? '') === 'test') {
        $bsResultHtml = '<div style="color:var(--green,#16a34a);margin-bottom:6px">Connected. Parsed ' . (int) $bsResult['fetched'] . ' punch(es).</div>'
            . '<pre style="white-space:pre-wrap;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px;max-height:240px;overflow:auto;font-size:12px">' . e((string) ($bsResult['sample'] ?? '')) . '</pre>';
    } else {
        $bsResultHtml = '<span style="color:var(--green,#16a34a)">Imported ' . (int) $bsResult['accepted'] . ' punch(es)' . ((int) ($bsResult['duplicates'] ?? 0) > 0 ? ' (' . (int) $bsResult['duplicates'] . ' already in)' : '') . '.'
            . ((int) ($bsResult['pending'] ?? 0) > 0 ? ' ' . (int) $bsResult['pending'] . ' punch(es) had no matching employee — held until the ID is mapped below.' : '') . '</span>';
    }
}

// ------------------------------------------------------------------ the form
$bsFormId = (string) ($_GET['edit'] ?? '');
$bsForm = null;
if (is_array($bsKept)) {
    $bsForm = $bsKept;
} elseif (isset($_GET['add'])) {
    $bsForm = ['_new' => true];
} elseif ($bsFormId !== '') {
    foreach ($bsDevices as $d) {
        (string) $d['id'] === $bsFormId && $bsForm = $d + ['hasPassword' => trim((string) ($d['password'] ?? '')) !== ''];
    }
}

if (is_array($bsForm)):
    $c = $bsForm;
    $isNew = !empty($c['_new']) || (string) ($c['id'] ?? '') === '';
    $prov = strtolower(trim((string) ($c['provider'] ?? ''))) ?: 'etimeoffice';
    $dm = strtolower(trim((string) ($c['device_mode'] ?? '')));
    $dmode = $dm === 'single' ? 'single' : ($dm === 'dual' ? 'dual' : ($isNew ? 'single' : 'dual'));
    $pwPh = !empty($c['hasPassword']) ? 'Saved — leave blank to keep current password' : 'Device API password';
    $ac = json_decode((string) ($c['api_config'] ?? ''), true);
    $ac = is_array($ac) ? $ac : [];
    $acm = (array) ($ac['map'] ?? []);
    $lb = 'display:block;font-size:12px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px';
    $in = 'width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px';
    $help = static fn (string $h): string => $h === '' ? '' : '<div style="font-size:12px;color:var(--text3);margin-top:4px">' . e($h) . '</div>';
    $fld = static function (string $label, string $name, mixed $val, string $type = 'text', string $ph = '', string $h = '', string $id = '', bool $req = false) use ($lb, $in, $help): string {
        return '<div style="margin-bottom:14px"><label style="' . $lb . '">' . e($label) . '</label>'
            . '<input' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ($name !== '' ? ' name="' . e($name) . '"' : '') . ' type="' . e($type) . '" value="' . e((string) ($val ?? '')) . '" placeholder="' . e($ph) . '" autocomplete="off"' . ($req ? ' required' : '') . ' style="' . $in . '">' . $help($h) . '</div>';
    };
    $sel = static function (string $label, string $name, array $opts, mixed $val, string $h = '', string $id = '', string $onch = '') use ($lb, $in, $help): string {
        $o = '';
        foreach ($opts as [$k, $t]) {
            $o .= '<option value="' . e((string) $k) . '"' . ((string) $k === (string) ($val ?? '') ? ' selected' : '') . '>' . e((string) $t) . '</option>';
        }
        return '<div style="margin-bottom:14px"><label style="' . $lb . '">' . e($label) . '</label><select' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ($name !== '' ? ' name="' . e($name) . '"' : '') . ($onch !== '' ? ' onchange="' . e($onch) . '"' : '') . ' style="' . $in . '">' . $o . '</select>' . $help($h) . '</div>';
    };
    $ta = static fn (string $label, string $name, mixed $val, string $ph, string $h, int $rows, string $id): string => '<div style="margin-bottom:14px"><label style="' . $lb . '">' . e($label) . '</label><textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '" placeholder="' . e($ph) . '" style="' . $in . ';font-family:monospace;font-size:12px">' . e((string) ($val ?? '')) . '</textarea>' . $help($h) . '</div>';
    $grid = static fn (string $html): string => '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">' . $html . '</div>';
    $sub = static fn (string $t): string => '<div style="border-top:1px dashed var(--border);margin:2px 0 12px;padding-top:10px;font-size:12px;font-weight:700;color:var(--text3)">' . $t . '</div>';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'THIS-SERVER');
    $port = (string) ($_SERVER['SERVER_PORT'] ?? '80');
    $ivVal = (string) ($c['sync_interval_min'] ?? '') !== '' ? (string) $c['sync_interval_min'] : ($prov === 'push' ? '0' : '60');
?>
<div class="page-header"><div><h2>Biometric Device Setup</h2><p><?= $isNew ? 'Add a new device' : 'Edit device' ?> — map it to a branch and give it a label.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=biometric-setup"><i class="fas fa-arrow-left"></i> Back to devices</a></div></div>
<form method="post" class="card" style="padding:22px 24px;max-width:600px" id="bio-form">
  <?= $bsCsrf ?><input type="hidden" name="id" value="<?= $bsEsc($c['id'] ?? '') ?>">
  <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:16px"><input name="enabled" value="1" type="checkbox" style="width:auto"<?= !empty($c['enabled']) ? ' checked' : '' ?>> Enable this device (auto-sync / accept its pushes)</label>
  <?= $fld('Device label', 'label', $c['label'] ?? '', 'text', 'e.g. Main Gate, 3rd Floor', 'A friendly name for this specific device / location.', '', true) ?>
  <div style="margin-bottom:14px"><label style="<?= $lb ?>">Branch / Location</label>
    <select name="branch_id" style="<?= $in ?>"><option value="">— Select branch —</option>
<?php foreach ($bsBranches as $bid => $bname): ?>
      <option value="<?= e((string) $bid) ?>"<?= (string) $bid === (string) ($c['branch_id'] ?? '') ? ' selected' : '' ?>><?= e($bname) ?></option>
<?php endforeach; ?>
    </select><?= $help('Which branch this device belongs to (from your Branches master).') ?></div>
  <div style="margin-bottom:14px"><label style="<?= $lb ?>">Provider / Device type</label>
    <select name="provider" id="bio-provider" onchange="bioSetProvider(this.value)" style="<?= $in ?>">
      <option value="push"<?= $prov === 'push' ? ' selected' : '' ?>>Push / ADMS — device pushes to SmartPRS (best for many devices)</option>
      <option value="etimetracklite"<?= $prov === 'etimetracklite' ? ' selected' : '' ?>>eSSL eTimeTrackLite — Local WebAPI (SOAP, port 81)</option>
      <option value="etimeoffice"<?= $prov === 'etimeoffice' ? ' selected' : '' ?>>eTimeOffice — Cloud API (etimeoffice.com)</option>
      <option value="generic"<?= $prov === 'generic' ? ' selected' : '' ?>>Custom / Generic API — configure any HTTP API</option>
    </select>
    <div style="font-size:12px;color:var(--text3);margin-top:4px"><b>Push</b>: point the device at SmartPRS (recommended). <b>eTimeTrackLite</b>: on-prem eSSL SOAP. <b>eTimeOffice</b>: eSSL cloud. <b>Custom</b>: any other API.</div></div>

  <div data-prov="push">
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:13px;line-height:1.6">
      <div style="font-weight:700;margin-bottom:6px"><i class="fas fa-satellite-dish" style="color:#f97316"></i> Point the device here</div>
      On the device: <b>Menu → Comm → Cloud Server / ADMS</b>. Set <b>Server Address</b> = this server’s IP and <b>Server Port</b> = <b><?= e($port) ?></b>. The device then pushes punches automatically — no URL or password needed here. It auto-registers by its serial number and appears in this list.
      <div style="margin-top:8px;color:var(--text3)">This server: <b><?= e($host) ?></b> · path <code>/iclock/</code> (fixed in the device). If the devices are on your office LAN, use this machine’s <b>LAN IP</b>, not a public domain.</div></div>
    <?= $fld('Device Serial Number (optional)', 'serial_number', $c['serial_number'] ?? '', 'text', 'e.g. CUB7244600978', 'Pre-register a serial, or leave blank — the device auto-registers itself when it first pushes.') ?>
  </div>
  <div data-prov="etimetracklite">
    <?= $fld('WebAPI URL', 'base_url', $c['base_url'] ?? '', 'text', 'http://192.168.1.100:81/iclock/WebAPIservice.asmx', 'eTimeTrackLite Web API endpoint. Host:port is fine too — /iclock/WebAPIservice.asmx is appended automatically.') ?>
    <?= $fld('Device Serial Number', 'serial_number', $c['serial_number'] ?? '', 'text', 'e.g. CUB7244600978', 'Shown in eTimeTrackLite / printed on the device. Used by GetTransactionsLog.') ?>
    <?= $fld('WebAPI Username', 'username', $c['username'] ?? '', 'text', 'Test', 'A user created in eTimeTrackLite WITH API permission granted — not the desktop-app login.') ?>
    <?= $fld('WebAPI Password', 'password', '', 'password', $pwPh) ?>
  </div>
  <div data-prov="etimeoffice">
    <?= $fld('API Base URL', 'base_url', $c['base_url'] ?? '', 'text', 'https://api.etimeoffice.com/api') ?>
    <?= $fld('Endpoint', 'endpoint', $c['endpoint'] ?? '', 'text', 'DownloadPunchDataMCID') ?>
    <?= $fld('Corporate ID', 'corp_id', $c['corp_id'] ?? '', 'text', 'Your eTimeOffice corporate ID') ?>
    <?= $fld('Username', 'username', $c['username'] ?? '', 'text', 'API username') ?>
    <?= $fld('Password', 'password', '', 'password', $pwPh) ?>
    <?= $fld('Employee code filter', 'empcode', $c['empcode'] ?? '', 'text', 'ALL', 'Usually ALL.') ?>
  </div>
  <div data-prov="generic">
    <?= $sel('Start from a preset', '', [['blank', 'Custom (blank) — fill manually'], ['json_query', 'Generic JSON — GET, credentials in the URL'], ['soap_xml', 'Generic SOAP / XML — POST'], ['zkteco_biotime', 'ZKTeco BioTime 8 — token login (set HOST)'], ['matrix_cosec', 'Matrix COSEC — REST (template, verify endpoints)']], '', 'Fills the fields below with a starting template — then adjust and Test.', 'gen-preset', 'bioPreset(this.value)') ?>
    <?= $sel('HTTP Method', 'gen_method', [['GET', 'GET'], ['POST', 'POST']], $ac['method'] ?? 'GET', '', 'gen-method') ?>
    <?= $fld('API URL', 'gen_url', $ac['url'] ?? '', 'text', 'http://HOST/api?from={from}&to={to}', 'Placeholders: {from} {to} {from_date} {to_date} {serial} {user} {pass} {key} {token} {empcode}', 'gen-url') ?>
    <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:14px"><input id="gen-basic" name="gen_basic" value="1" type="checkbox" style="width:auto"<?= !empty($ac['basic_auth']) ? ' checked' : '' ?>> Send username/password as HTTP Basic auth</label>
    <?= $fld('Username', 'username', $c['username'] ?? '', 'text', 'API username (also {user})') ?>
    <?= $fld('Password', 'password', '', 'password', $pwPh, 'Also available as {pass}.') ?>
    <?= $fld('API Key / Token value', 'gen_key', $ac['api_key'] ?? '', 'text', '', 'Optional. Available as {key} in URL / headers / body.', 'gen-key') ?>
    <?= $fld('Device Serial (optional)', 'serial_number', $c['serial_number'] ?? '', 'text', '', 'Available as {serial}.') ?>
    <?= $ta('Extra Headers', 'gen_headers', $ac['headers'] ?? '', "X-API-Key: {key}\nSOAPAction: ...", 'One "Header: value" per line. Values accept placeholders.', 3, 'gen-headers') ?>
    <?= $sel('Request Body Type (POST)', 'gen_bodytype', [['none', 'None (GET / no body)'], ['form', 'Form-encoded'], ['json', 'JSON'], ['xml', 'XML / SOAP']], $ac['body_type'] ?? 'none', '', 'gen-bodytype') ?>
    <?= $ta('Request Body Template', 'gen_body', $ac['body'] ?? '', 'key={user}&from={from}   OR   {"user":"{user}"}   OR   <xml>...', 'For POST. Placeholders substituted.', 4, 'gen-body') ?>
    <?= $fld('Request date format', 'gen_datefmt', $ac['date_format_req'] ?? '', 'text', 'Y-m-d H:i:s', 'PHP date format for {from}/{to}. Blank = Y-m-d H:i:s.', 'gen-datefmt') ?>
    <?= $sub('OPTIONAL TOKEN LOGIN (called first — exposes {token})') ?>
    <?= $fld('Token login URL', 'gen_tokenurl', $ac['token_url'] ?? '', 'text', 'http://HOST/api-token-auth/', 'Leave blank if not used.', 'gen-tokenurl') ?>
    <?= $fld('Token login body (JSON)', 'gen_tokenbody', $ac['token_body'] ?? '', 'text', '{"username":"{user}","password":"{pass}"}', '', 'gen-tokenbody') ?>
    <?= $fld('Token JSON path', 'gen_tokenpath', $ac['token_path'] ?? '', 'text', 'token', 'Where the token sits in the login response, e.g. token or data.token.', 'gen-tokenpath') ?>
    <?= $sub('RESPONSE MAPPING') ?>
    <?= $sel('Response format', 'gen_restype', [['json', 'JSON'], ['xml', 'XML'], ['delimited', 'Delimited text']], $ac['response_type'] ?? 'json', '', 'gen-restype') ?>
    <?= $fld('Records location', 'gen_recpath', $ac['record_path'] ?? '', 'text', 'data (JSON dot-path)  /  Row (XML tag)', 'JSON: dot-path to the array (blank = root). XML: the repeating tag. Delimited: leave blank.', 'gen-recpath') ?>
    <?= $grid($fld('Record separator', 'gen_recdelim', $ac['record_delim'] ?? '', 'text', '\n', 'Delimited only. Type \n for newline.', 'gen-recdelim') . $fld('Field separator', 'gen_fielddelim', $ac['field_delim'] ?? '', 'text', '\t', 'Delimited only. Type \t for tab.', 'gen-fielddelim')) ?>
    <?= $grid($fld('Map: Employee code', 'gen_map_emp', $acm['emp'] ?? '', 'text', 'empcode / 0', 'Field key, dot-path, or column index.', 'gen-map-emp')
        . $fld('Map: Date+Time', 'gen_map_dt', $acm['datetime'] ?? '', 'text', 'punchtime / 1', '', 'gen-map-dt')
        . $fld('Map: Date (if split)', 'gen_map_date', $acm['date'] ?? '', 'text', '', '', 'gen-map-date')
        . $fld('Map: Time (if split)', 'gen_map_time', $acm['time'] ?? '', 'text', '', '', 'gen-map-time')
        . $fld('Map: Direction', 'gen_map_dir', $acm['direction'] ?? '', 'text', 'inout / status', '', 'gen-map-dir')
        . $fld('Map: Machine (optional)', 'gen_map_mc', $acm['machine'] ?? '', 'text', '', '', 'gen-map-mc')) ?>
    <?= $fld('Punch date format', 'gen_punchfmt', $ac['punch_date_format'] ?? '', 'text', 'Y-m-d H:i:s', 'PHP format to read the punch time. Blank = auto-detect.', 'gen-punchfmt') ?>
    <?= $grid($fld('Direction: IN values', 'gen_dirin', $ac['dir_in'] ?? '', 'text', 'in,0,I', '', 'gen-dirin') . $fld('Direction: OUT values', 'gen_dirout', $ac['dir_out'] ?? '', 'text', 'out,1,O', '', 'gen-dirout')) ?>
  </div>

  <div style="margin-bottom:14px"><label style="<?= $lb ?>">Sync frequency</label>
    <select name="sync_interval_min" id="bio-interval" data-val="<?= e($ivVal) ?>" style="<?= $in ?>"></select>
    <div id="bio-interval-help" style="font-size:12px;color:var(--text3);margin-top:4px"></div></div>
  <?= $fld('Employee ID prefix', 'emp_prefix', $c['emp_prefix'] ?? '', 'text', 'e.g. A', 'If the device returns 12345 and your employees are A12345, enter A.') ?>
  <?= $sel('Device setup', 'device_mode', [['single', '1 Device - one reader handles IN and OUT'], ['dual', '2 Devices (IN + OUT) - separate entry and exit readers']], $dmode, '', 'bio-devmode', 'bioSetDeviceMode(this.value)') ?>
  <div data-mode="single">
    <div style="font-size:12px;color:var(--text3);margin:-10px 0 12px">One reader. SmartPRS alternates automatically: 1st punch of the day IN, 2nd OUT, 3rd IN, and so on, counted per employee per attendance day.</div>
    <?= $fld('Machine ID', 'in_machine_id', $c['in_machine_id'] ?? '', 'text', 'e.g. 101', 'Device number / serial of this reader. Optional - leave blank if this device setup has only one reader anyway.') ?>
  </div>
  <div data-mode="dual">
    <div style="font-size:12px;color:var(--text3);margin:-10px 0 12px">Two readers. Every punch from the IN Machine ID is an IN, every punch from the OUT Machine ID is an OUT. Leave both blank to keep using the IN/OUT flag the device sends.</div>
    <?= $grid($fld('IN Machine ID', 'in_machine_id', $c['in_machine_id'] ?? '', 'text', 'e.g. 101', 'Entry device number/serial - its punches are IN.') . $fld('OUT Machine ID', 'out_machine_id', $c['out_machine_id'] ?? '', 'text', 'e.g. 102', 'Exit device number/serial - its punches are OUT.')) ?>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
    <button class="btn btn-primary" type="submit" name="op" value="save"><i class="fas fa-floppy-disk"></i> Save device</button>
    <button class="btn btn-outline" type="submit" name="op" value="form-test" formnovalidate data-pull><i class="fas fa-plug"></i> Test connection</button>
    <button class="btn btn-outline" type="submit" name="op" value="form-sync" formnovalidate data-pull><i class="fas fa-rotate"></i> Sync now</button>
  </div>
  <div id="bio-result" style="margin-top:14px;font-size:13px"><?= $bsResultHtml ?></div>
</form>
<script>
(function () {
  // SmartPRS 2 re-rendered the form on a provider / device-setup change, keeping what was typed.
  // Here every provider's block is on the page; the hidden ones are disabled (never posted) and
  // a value typed under one name follows to the same name in the block that is shown.
  var form = document.getElementById('bio-form');
  function carry(attr, v) {
    var typed = {};
    form.querySelectorAll('[data-' + attr + '] input:not(:disabled), [data-' + attr + '] select:not(:disabled), [data-' + attr + '] textarea:not(:disabled)').forEach(function (el) { if (el.name && el.type !== 'checkbox' && el.value !== '') { typed[el.name] = el.value; } });
    form.querySelectorAll('[data-' + attr + ']').forEach(function (box) {
      var on = box.getAttribute('data-' + attr) === v;
      box.style.display = on ? '' : 'none';
      box.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = !on;
        if (on && el.name && el.value === '' && typed[el.name] !== undefined && el.type !== 'password') { el.value = typed[el.name]; }
      });
    });
  }
  var IV = { push: [['0', 'Real-time (each punch)'], ['5', 'Every 5 minutes'], ['10', 'Every 10 minutes'], ['15', 'Every 15 minutes'], ['30', 'Every 30 minutes'], ['60', 'Hourly']],
             pull: [['5', 'Every 5 minutes'], ['10', 'Every 10 minutes'], ['15', 'Every 15 minutes'], ['30', 'Every 30 minutes'], ['60', 'Hourly']] };
  window.bioSetProvider = function (v) {
    carry('prov', v);
    var iv = document.getElementById('bio-interval');
    var cur = iv.value || iv.getAttribute('data-val');
    var opts = v === 'push' ? IV.push : IV.pull;
    if (!opts.some(function (o) { return o[0] === cur; })) { cur = v === 'push' ? '0' : '60'; }
    iv.innerHTML = opts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
    document.getElementById('bio-interval-help').textContent = v === 'push' ? 'How often the device pushes (sent to the device on connect). Real-time is best.' : 'How often SmartPRS pulls this device.';
    form.querySelectorAll('[data-pull]').forEach(function (b) { b.style.display = v === 'push' ? 'none' : ''; });
  };
  window.bioSetDeviceMode = function (v) { carry('mode', v === 'single' ? 'single' : 'dual'); };
  // Starting templates for the Custom/Generic API provider (SmartPRS 2's BIO_PRESETS, as is).
  var P = {
    blank: {},
    json_query: { method: 'GET', url: 'http://HOST/api/attendance?username={user}&password={pass}&from={from}&to={to}', response_type: 'json', record_path: 'data', date_format_req: 'Y-m-d H:i:s', map: { emp: 'empcode', datetime: 'punchtime', direction: 'inout' }, dir_in: 'in,0', dir_out: 'out,1' },
    soap_xml: { method: 'POST', url: 'http://HOST/service.asmx', headers: 'Content-Type: text/xml; charset=utf-8\nSOAPAction: http://tempuri.org/GetLogs', body_type: 'xml',
      body: '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><GetLogs xmlns="http://tempuri.org/"><From>{from}</From><To>{to}</To><User>{user}</User><Pass>{pass}</Pass></GetLogs></soap:Body></soap:Envelope>',
      response_type: 'xml', record_path: 'Row', map: { emp: 'EmpCode', datetime: 'LogDateTime', direction: 'InOut' }, dir_in: 'in', dir_out: 'out' },
    zkteco_biotime: { token_url: 'http://HOST/api-token-auth/', token_body: '{"username":"{user}","password":"{pass}"}', token_path: 'token', method: 'GET', url: 'http://HOST/iclock/api/transactions/?start_time={from}&end_time={to}&page_size=1000', headers: 'Authorization: Token {token}', response_type: 'json', record_path: 'data', date_format_req: 'Y-m-d H:i:s', map: { emp: 'emp_code', datetime: 'punch_time', direction: 'punch_state' }, dir_in: '0,255', dir_out: '1' },
    matrix_cosec: { method: 'GET', url: 'http://HOST/cosec/api/attendance?fromdate={from_date}&todate={to_date}', basic_auth: 1, response_type: 'json', record_path: '', date_format_req: 'Y-m-d', map: { emp: 'UserID', datetime: 'EventDateTime', direction: 'InOut' }, dir_in: 'in', dir_out: 'out' }
  };
  window.bioPreset = function (name) {
    var p = P[name] || {}, m = p.map || {};
    var sv = function (id, v) { var el = document.getElementById(id); if (el) { el.value = (v == null ? '' : v); } };
    sv('gen-method', p.method || 'GET'); sv('gen-url', p.url); document.getElementById('gen-basic').checked = !!p.basic_auth;
    sv('gen-key', p.api_key); sv('gen-headers', p.headers); sv('gen-bodytype', p.body_type || 'none'); sv('gen-body', p.body);
    sv('gen-datefmt', p.date_format_req); sv('gen-tokenurl', p.token_url); sv('gen-tokenbody', p.token_body); sv('gen-tokenpath', p.token_path);
    sv('gen-restype', p.response_type || 'json'); sv('gen-recpath', p.record_path); sv('gen-recdelim', p.record_delim); sv('gen-fielddelim', p.field_delim);
    sv('gen-punchfmt', p.punch_date_format); sv('gen-dirin', p.dir_in); sv('gen-dirout', p.dir_out);
    sv('gen-map-emp', m.emp); sv('gen-map-dt', m.datetime); sv('gen-map-date', m.date); sv('gen-map-time', m.time); sv('gen-map-dir', m.direction); sv('gen-map-mc', m.machine);
  };
  bioSetProvider(document.getElementById('bio-provider').value);
  bioSetDeviceMode(document.getElementById('bio-devmode').value);
})();
</script>
<?php return; endif; ?>
<?php
// ------------------------------------------------------------------ device list + mapping card
$bsEmps = array_values(array_filter($store->all('employees'), static fn (array $em): bool => trim((string) ($em['deleted_at'] ?? '')) === '' && SmartPRS\Surface\Employment::code($em) !== ''));
usort($bsEmps, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
$bsMaps = array_values(array_filter($bsEmps, static fn (array $em): bool => trim((string) ($em['device_user_id'] ?? '')) !== ''));
$bsKnown = [];
foreach ($bsEmps as $em) {
    $bsKnown[strtolower(SmartPRS\Surface\Employment::code($em))] = true;
    trim((string) ($em['device_user_id'] ?? '')) !== '' && $bsKnown[strtolower(trim((string) $em['device_user_id']))] = true;
}
$bsUnmapped = array_values(array_filter($store->all('biometric_unmapped'), static fn (array $u): bool => !isset($bsKnown[strtolower((string) ($u['device_code'] ?? ''))])));
usort($bsUnmapped, static fn (array $a, array $b): int => (int) ($b['punches'] ?? 0) <=> (int) ($a['punches'] ?? 0));
$bsBranchName = static fn (mixed $id): string => (string) ($bsBranches[(string) ($id ?? '')] ?? '');
$bsWhen = static fn (string $at): string => $at === '' ? '' : SmartPRS\Surface\Dates::dmy(substr($at, 0, 10)) . ' ' . substr($at, 11, 5);
$lb = 'display:block;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px';
$in = 'width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px';
?>
<div class="page-header"><div><h2>Biometric Device Setup</h2><p>Connect one or more attendance devices. Each maps to a branch; punches import into Attendance &amp; payroll automatically every hour.</p></div>
  <div class="actions"><a class="btn btn-primary" href="?r=biometric-setup&amp;add=1"><i class="fas fa-plus"></i> Add device</a></div></div>
<?php if ($bsDevices === []): ?>
<div class="card"><div style="padding:36px;text-align:center;color:var(--text3)">No biometric devices configured yet. Click <b>Add device</b> to connect your first one.</div></div>
<?php endif; ?>
<?php foreach ($bsDevices as $v):
    $vProv = strtolower(trim((string) ($v['provider'] ?? ''))) ?: 'etimeoffice';
    $vMode = strtolower(trim((string) ($v['device_mode'] ?? ''))) === 'single' ? 'single' : 'dual';
    $vIn = trim((string) ($v['in_machine_id'] ?? ''));
    $vOut = trim((string) ($v['out_machine_id'] ?? ''));
    $mc = $vMode === 'single' ? '1 device' . ($vIn !== '' ? ' · MC ' . $vIn : '') : ($vIn !== '' || $vOut !== '' ? '2 devices · IN ' . ($vIn ?: '-') . ' / OUT ' . ($vOut ?: '-') : '');
    $vBranch = $bsBranchName($v['branch_id'] ?? '');
    $vLast = trim((string) ($v['last_sync_at'] ?? ''));
?>
<div class="card" style="padding:16px 18px;margin-bottom:12px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div><div style="font-weight:700;font-size:15px"><?= e((string) (($v['label'] ?? '') ?: (($v['corp_id'] ?? '') ?: (($v['provider'] ?? '') ?: 'Device #' . $v['id'])))) ?></div>
      <div style="font-size:13px;color:var(--text2);margin-top:2px"><?= $vBranch !== '' ? e($vBranch) : '<span style="color:var(--text3)">No branch</span>' ?> · <?= e($vProv) ?><?= $mc !== '' ? ' · ' . e($mc) : '' ?></div>
      <div style="font-size:12px;color:var(--text3);margin-top:6px"><?= $vLast !== '' ? '<i class="fas fa-clock"></i> Last sync: ' . e($bsWhen($vLast)) . ' — ' . e((string) ($v['last_status'] ?? '')) : 'Never synced' ?></div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?= !empty($v['enabled']) ? '<span style="background:#dcfce7;color:#166534;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600">Auto-sync ON</span>' : '<span style="background:var(--bg2);color:var(--text3);border-radius:20px;padding:2px 10px;font-size:12px">Auto-sync off</span>' ?>
      <?php if ($vProv !== 'push'): ?><form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= $bsCsrf ?><input type="hidden" name="op" value="sync"><select name="days" title="How many days back" style="width:auto;padding:4px 6px"><option value="1">1 day</option><option value="3">3 days</option><option value="7">7 days</option><option value="31">31 days</option></select><input type="hidden" name="id" value="<?= e((string) $v['id']) ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-rotate"></i> Sync</button></form><?php endif; ?>
      <a class="btn btn-outline btn-sm" href="?r=biometric-setup&amp;edit=<?= e((string) $v['id']) ?>"><i class="fas fa-pen"></i> Edit</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this biometric device? Punches already imported are kept.')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="module-delete"><input type="hidden" name="k" value="biometric-config"><input type="hidden" name="id" value="<?= e((string) $v['id']) ?>"><button class="btn btn-outline btn-sm" type="submit" title="Remove"><i class="fas fa-trash"></i></button></form>
    </div></div></div>
<?php endforeach; ?>
<div id="bio-result" style="margin-top:6px;font-size:13px"><?= $bsResultHtml ?></div>

<div class="card" style="padding:18px 20px;margin-top:14px">
  <div style="font-weight:700;font-size:15px;margin-bottom:2px"><i class="fas fa-fingerprint" style="color:#f97316;margin-right:6px"></i> Employee Mapping (Biometric ID → Employee)</div>
  <div style="font-size:12.5px;color:var(--text3);margin-bottom:10px">A punch feeds nobody until its device ID is linked to a person. IDs that match the Employee ID map automatically; anything else appears below after a sync/upload. The link is saved on the employee (also editable as &quot;Biometric ID&quot; in the Directory profile).</div>
<?php if (is_array($bsConfirm)): ?>
  <form method="post" class="card" style="border-left:4px solid #b45309;padding:12px 14px;margin:0 0 10px"><?= $bsCsrf ?><input type="hidden" name="op" value="setup-map"><input type="hidden" name="force" value="1">
    <input type="hidden" name="device_code" value="<?= e((string) $bsConfirm['code']) ?>"><input type="hidden" name="employee" value="<?= e((string) $bsConfirm['employee']) ?>">
    <div style="font-size:13px;margin-bottom:8px"><?= e((string) $bsConfirm['message']) ?> Move it to this employee?</div>
    <button class="btn btn-primary btn-sm" type="submit">Yes, move it</button> <a class="btn btn-outline btn-sm" href="?r=biometric-setup">Not changed</a></form>
<?php endif; ?>
  <form method="post" onsubmit="var u=this.querySelector('#bio-map-id');if(!u.value.trim()){u.value=this.querySelector('#bio-map-unmapped').value;}">
    <?= $bsCsrf ?><input type="hidden" name="op" value="setup-map">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
      <div><label style="<?= $lb ?>">Unmapped biometric IDs (seen in punches)</label>
        <select id="bio-map-unmapped" onchange="if(this.value){document.getElementById('bio-map-id').value=this.value;}" style="<?= $in ?>"><option value="">— pick an unmapped ID —</option>
<?php foreach ($bsUnmapped as $u): $uc = (string) $u['device_code']; ?>
          <option value="<?= e($uc) ?>"><?= e($uc) ?> · <?= (int) ($u['punches'] ?? 0) ?> punch(es)<?= ($u['last_seen'] ?? '') ? ' · last ' . e($bsWhen((string) $u['last_seen'])) : '' ?></option>
<?php endforeach; ?>
        </select></div>
      <div><label style="<?= $lb ?>">Biometric ID (as on the device)</label><input id="bio-map-id" name="device_code" placeholder="e.g. 1043" style="<?= $in ?>"></div>
      <div><label style="<?= $lb ?>">SmartPRS employee</label><?= employeePicker('bio-map-emp', 'employee', employeeChoices($bsEmps), '', '— choose employee —', true) ?></div>
      <div style="display:flex;align-items:flex-end;padding-bottom:2px"><button class="btn btn-primary" type="submit"><i class="fas fa-link"></i> Map</button></div>
    </div>
  </form>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
    <span style="font-size:12.5px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin-right:4px">Bulk upload</span>
    <form method="post" style="display:inline"><?= $bsCsrf ?><input type="hidden" name="op" value="map-sample"><button class="btn btn-outline" type="submit"><i class="fas fa-download"></i> Sample file</button></form>
    <form method="post" enctype="multipart/form-data" style="display:inline"><?= $bsCsrf ?><input type="hidden" name="op" value="map-bulk">
      <input id="bio-map-file" name="sheet" type="file" accept=".csv,.xlsx,.xls,text/csv" style="display:none" onchange="this.form.submit()">
      <button class="btn btn-primary" type="button" onclick="document.getElementById('bio-map-file').click()"><i class="fas fa-upload"></i> Upload file</button></form>
    <span style="font-size:12px;color:var(--text3)">Two columns: Biometric ID, Employee ID. IDs already mapped are skipped and listed.</span>
  </div>
  <div style="font-weight:700;font-size:13.5px;margin:14px 0 6px">Current mappings (<?= count($bsMaps) ?>)</div>
  <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table style="width:100%;border-collapse:collapse"><thead><tr><th>Biometric ID</th><th>Employee</th><th>Branch</th><th></th></tr></thead><tbody>
<?php if ($bsMaps === []): ?>
    <tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text3);font-size:13px">No mappings yet. Link an unmapped biometric ID to a person above — or set the &quot;Biometric ID&quot; field in the employee's Directory profile.</td></tr>
<?php endif; ?>
<?php foreach ($bsMaps as $r): $br = $bsBranchName($r['branch_id'] ?? ''); ?>
    <tr><td style="font-weight:600"><?= e((string) $r['device_user_id']) ?></td><td><?= e((string) ($r['name'] ?? '')) ?> <span style="color:var(--text3)">(<?= e(SmartPRS\Surface\Employment::code($r)) ?>)</span></td>
      <td><?= $br !== '' ? e($br) : '<span style="color:var(--text3)">—</span>' ?></td>
      <td style="text-align:right"><form method="post" style="display:inline" onsubmit="return confirm('Remove this biometric mapping? Punch history is kept; new punches for that ID stay unmapped until re-linked.')"><?= $bsCsrf ?><input type="hidden" name="op" value="unmap"><input type="hidden" name="employee" value="<?= e((string) $r['id']) ?>"><button type="submit" title="Remove mapping" style="background:transparent;border:0;cursor:pointer;color:var(--red,#dc2626)"><i class="fas fa-link-slash"></i></button></form></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if (is_array($bsBulk)): ?>
<div id="bio-bulk-pop" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="max-width:620px;width:100%;max-height:80vh;display:flex;flex-direction:column;padding:18px 20px">
    <div style="font-weight:700;font-size:15px;margin-bottom:8px"><?= e((string) $bsBulk['title']) ?></div>
    <div style="overflow:auto;font-size:13px;line-height:1.7;flex:1">
<?php foreach ((array) $bsBulk['lines'] as [$k, $t]): ?>
      <div style="color:<?= $k === 'ok' ? 'var(--green,#16a34a)' : ($k === 'dup' ? '#b45309' : 'var(--red,#dc2626)') ?>"><?= e((string) $t) ?></div>
<?php endforeach; ?>
    </div>
    <div style="text-align:right;margin-top:12px"><button class="btn btn-primary" type="button" onclick="document.getElementById('bio-bulk-pop').remove()">OK</button></div></div></div>
<?php endif; ?>

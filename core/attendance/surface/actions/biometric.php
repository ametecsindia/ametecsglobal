<?php
// AMETECSGLOBAL CORE MODULE (owner, 17 Nov 2026): QR attendance, mobile devices and biometric live in the core, not in any app.
// Moved from SmartPRS3 (surface/actions/biometric.php); SmartPRS3 keeps a one-line pointer to this file. Paths that pointed at SmartPRS3's own folder still do.
/**
 * BIOMETRIC SYNC / TEST / MAP (SmartPRS 2 BiometricConfigController sync, test, mapEmployee).
 *
 *   sync  pull the last N days from an eTimeOffice / eTimeTrackLite / Generic device and ingest
 *   test  pull the last day WITHOUT writing — shows what the server answers
 *   map   device id → employee: sets the employee's device user id (cleared from anyone else
 *         holding it) and moves every held punch for it into attendance
 */
declare(strict_types=1);

$bioBack = '?r=' . (post('back') === 'qr-attendance&qp=unmatched' ? 'qr-attendance&qp=unmatched' : (post('back') === 'biometric-setup' ? 'biometric-setup' : 'biometric-setup')); // QR Attendance → Unmatched punches (12 Nov 2026) matches here too; Biometric Device Setup (16 Nov 2026)

if (!$may('tenant.settings.update') || $tenantId === '' || $pdo === null) {
    $_SESSION['flash_problem'] = 'Denied, or no database: biometric sync needs a company workspace on MySQL.';
    header('Location: ' . $bioBack);
    exit;
}

$bioOp = post('op');

// ---------------------------------------------------------------------------
// BIOMETRIC DEVICE SETUP (owner, 16 Nov 2026: SmartPRS 2's screen and logic —
// BiometricConfigController save / effectiveConfig / test / sync / mapEmployee /
// unmapEmployee and the bulk mapping file). Screen: surface/screens/biometric-setup.php.
// ---------------------------------------------------------------------------
if (in_array($bioOp, ['save', 'form-test', 'form-sync'], true)) {
    $bsSaved = post('id') !== '' ? $store->find('biometric_configs', post('id')) : null;
    $bsProv = in_array(post('provider'), ['push', 'etimetracklite', 'etimeoffice', 'generic'], true) ? post('provider') : 'etimeoffice';
    $bsMode = post('device_mode') === 'single' ? 'single' : 'dual';
    $bsBase = trim(post('base_url'));
    $bsBase === '' && $bsProv !== 'etimetracklite' && $bsBase = 'https://api.etimeoffice.com/api';
    $bsApi = null;

    if ($bsProv === 'generic') {
        $bsMap = array_filter(['emp' => trim(post('gen_map_emp')), 'datetime' => trim(post('gen_map_dt')), 'date' => trim(post('gen_map_date')),
            'time' => trim(post('gen_map_time')), 'direction' => trim(post('gen_map_dir')), 'machine' => trim(post('gen_map_mc'))], static fn (string $v): bool => $v !== '');
        $bsApi = json_encode(['method' => post('gen_method') ?: 'GET', 'url' => trim(post('gen_url')), 'basic_auth' => post('gen_basic') === '1' ? 1 : 0,
            'api_key' => post('gen_key'), 'headers' => post('gen_headers'), 'body_type' => post('gen_bodytype') ?: 'none', 'body' => post('gen_body'),
            'date_format_req' => post('gen_datefmt'), 'token_url' => post('gen_tokenurl'), 'token_body' => post('gen_tokenbody'), 'token_path' => post('gen_tokenpath'),
            'response_type' => post('gen_restype') ?: 'json', 'record_path' => post('gen_recpath'), 'record_delim' => post('gen_recdelim'), 'field_delim' => post('gen_fielddelim'),
            'punch_date_format' => post('gen_punchfmt'), 'dir_in' => post('gen_dirin'), 'dir_out' => post('gen_dirout'), 'map' => (object) $bsMap], JSON_UNESCAPED_SLASHES);
    }

    $bsIv = trim(post('sync_interval_min'));
    $bsRow = [
        'label' => trim(post('label')) ?: null,
        'branch_id' => post('branch_id') ?: null,
        'provider' => $bsProv,
        'enabled' => post('enabled') === '1' ? 1 : 0,
        'base_url' => $bsBase ?: null,
        'endpoint' => trim(post('endpoint')) ?: 'DownloadPunchDataMCID',
        'corp_id' => trim(post('corp_id')) ?: null,
        'serial_number' => trim(post('serial_number')) ?: null,
        'api_config' => $bsApi,
        'sync_interval_min' => $bsIv !== '' ? max(0, (int) $bsIv) : null,
        'username' => trim(post('username')) ?: null,
        'empcode' => trim(post('empcode')) ?: 'ALL',
        'emp_prefix' => trim(post('emp_prefix')) ?: null,
        'device_mode' => $bsMode,
        'in_machine_id' => trim(post('in_machine_id')) ?: null,
        'out_machine_id' => $bsMode === 'single' ? null : (trim(post('out_machine_id')) ?: null), // one reader has no OUT machine
    ];
    $bsPw = (string) ($_POST['password'] ?? '');

    if ($bioOp === 'save') {
        if ($bsRow['label'] === null) {
            $_SESSION['flash_problem'] = 'Device label is required.';
            $_SESSION['bio_form'] = $bsRow + ['id' => post('id'), 'hasPassword' => trim((string) ($bsSaved['password'] ?? '')) !== ''];
        } else {
            $bsPw !== '' && $bsRow['password'] = SmartPRS\Surface\Secret::encrypt($bsPw, dirname((dirname(__DIR__, 4) . '/plugins/smartprs3/surface/actions'), 2) . '/.env');
            $bsCols = $store->columnsOf('biometric_configs');
            $bsPut = array_intersect_key($bsRow, array_flip($bsCols)) + ($bsSaved !== null ? ['id' => (string) $bsSaved['id']] : []);
            $bsNew = $store->put('biometric_configs', $bsPut);
            unset($bsPut['password']);
            $audit($bsSaved === null ? 'biometric.device.added' : 'biometric.device.saved', 'biometric_configs', (string) ($bsNew['id'] ?? ''), null, $bsPut, 'Biometric Device Setup');
            $_SESSION['flash_notice'] = 'Device saved.';
        }
    } else {
        // effectiveConfig: what is on the form, the saved password when none was typed.
        $bsCfg = $bsRow + ['password' => $bsPw !== '' ? $bsPw : (string) ($bsSaved['password'] ?? '')] + ($bsSaved !== null ? ['id' => (string) $bsSaved['id']] : []);
        $bsAc = json_decode((string) $bsApi, true) ?: [];
        $bsNeed = match ($bsProv) {
            'etimetracklite' => (string) $bsCfg['base_url'] === '' || (string) $bsCfg['serial_number'] === '' || (string) $bsCfg['username'] === '' || $bsCfg['password'] === ''
                ? ($bioOp === 'form-test' ? 'Enter' : 'Save') . ' the WebAPI URL, Serial Number, Username and Password first.' : null,
            'generic' => trim((string) ($bsAc['url'] ?? '')) === '' ? ($bioOp === 'form-test' ? 'Set at least the API URL and Response format first.' : 'Set the API URL and Response format first.') : null,
            'etimeoffice' => (string) $bsCfg['corp_id'] === '' || (string) $bsCfg['username'] === '' || $bsCfg['password'] === ''
                ? ($bioOp === 'form-test' ? 'Enter Corporate ID, Username and Password first.' : 'Save the connection details first.') : null,
            default => 'Push devices send their punches themselves — nothing to test or pull.',
        };

        if ($bsNeed !== null) {
            $_SESSION['bio_result'] = ['ok' => false, 'op' => $bioOp === 'form-test' ? 'test' : 'sync', 'message' => $bsNeed];
        } else {
            @set_time_limit(180);
            $r = (new SmartPRS\Surface\BiometricSync($store, $pdo, $tenantId, dirname((dirname(__DIR__, 4) . '/plugins/smartprs3/surface/actions'), 2)))->run($bsCfg, 1, $bioOp === 'form-test');
            $_SESSION['bio_result'] = $r + ['op' => $bioOp === 'form-test' ? 'test' : 'sync'];
            $audit('biometric.' . ($bioOp === 'form-test' ? 'test' : 'sync'), 'biometric_configs', (string) ($bsCfg['id'] ?? ''), null, ['fetched' => $r['fetched'], 'accepted' => $r['accepted'], 'error' => $r['error']], 'manual');
        }

        $_SESSION['bio_form'] = $bsRow + ['id' => post('id'), 'hasPassword' => trim((string) ($bsSaved['password'] ?? '')) !== ''];
    }

    header('Location: ' . $bioBack);
    exit;
}

if ($bioOp === 'unmap') {
    $bsEmp = $store->find('employees', post('employee'));

    if ($bsEmp === null) {
        $_SESSION['flash_problem'] = 'Employee not found.';
    } else {
        $store->put('employees', ['id' => $bsEmp['id'], 'device_user_id' => null]);
        $audit('biometric.unmapped', 'employees', (string) $bsEmp['id'], ['device_user_id' => $bsEmp['device_user_id'] ?? null], ['device_user_id' => null], 'Biometric Device Setup');
        $_SESSION['flash_notice'] = 'Mapping removed. Punch history is kept; new punches for that ID stay unmapped until re-linked.';
    }

    header('Location: ' . $bioBack);
    exit;
}

if ($bioOp === 'map-sample') {
    // Two columns, the unmapped IDs pre-filled (SmartPRS 2's bioMapSample). The CSV is turned
    // into a formatted .xlsx by the output filter like every download.
    $bsKnown = [];
    foreach ($store->all('employees') as $bsE) {
        $bsKnown[strtolower(SmartPRS\Surface\Employment::code($bsE))] = true;
        trim((string) ($bsE['device_user_id'] ?? '')) !== '' && $bsKnown[strtolower(trim((string) $bsE['device_user_id']))] = true;
    }
    $bsLines = ['Biometric ID,Employee ID'];
    foreach ($store->all('biometric_unmapped') as $bsU) {
        isset($bsKnown[strtolower((string) ($bsU['device_code'] ?? ''))]) || $bsLines[] = '"' . str_replace('"', '""', (string) $bsU['device_code']) . '",';
    }
    count($bsLines) === 1 && $bsLines[] = '1043,AO0001';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="biometric-mapping-sample.csv"');
    echo implode("\n", $bsLines) . "\n";
    exit;
}

if ($bioOp === 'map-bulk') {
    // SmartPRS 2's bioMapBulk: an ID already mapped, an unknown Employee ID, an employee already
    // mapped, or a repeat in the file is NOT changed — each is listed in the popup.
    $bsFile = (string) ($_FILES['sheet']['tmp_name'] ?? '');
    $bsOut = [];
    $bsDone = 0;
    $bsRows = [];

    if (is_uploaded_file($bsFile)) {
        try {
            $bsCsv = SmartPRS\Surface\Sheet::toCsv((string) file_get_contents($bsFile), (string) ($_FILES['sheet']['name'] ?? 'upload.csv'));
            foreach (preg_split('/\r\n|\n|\r/', ltrim($bsCsv, "\xEF\xBB\xBF")) ?: [] as $bsLn) {
                $bsC = array_map(static fn ($v): string => trim((string) $v), str_getcsv($bsLn));
                (($bsC[0] ?? '') !== '' || ($bsC[1] ?? '') !== '') && $bsRows[] = [$bsC[0] ?? '', $bsC[1] ?? ''];
            }
        } catch (\Throwable $e) {
            $bsOut[] = ['err', 'The file could not be read: ' . $e->getMessage()];
        }
    }

    $bsRows !== [] && preg_match('/biometric/i', $bsRows[0][0]) === 1 && array_shift($bsRows);

    if ($bsRows === [] && $bsOut === []) {
        $bsOut[] = ['err', 'No rows found. Use the sample file: column A Biometric ID, column B Employee ID.'];
    }

    $bsEmps = [];
    $bsByDev = [];
    $bsByEmp = [];
    foreach ($store->all('employees') as $bsE) {
        if (trim((string) ($bsE['deleted_at'] ?? '')) !== '' || ($bsCode = SmartPRS\Surface\Employment::code($bsE)) === '') {
            continue;
        }
        $bsEmps[strtolower($bsCode)] = $bsE;
        $bsDev = trim((string) ($bsE['device_user_id'] ?? ''));
        if ($bsDev !== '') {
            $bsByDev[strtolower($bsDev)] = $bsE;
            $bsByEmp[strtolower($bsCode)] = $bsDev;
        }
    }

    $bsSeen = [];
    $bsIngest = new SmartPRS\Surface\PunchIngest($store, $pdo, $tenantId);
    foreach ($bsRows as $i => [$bsDevId, $bsEmpCode]) {
        $n = 'Row ' . ($i + 2) . ': ';
        $dk = strtolower($bsDevId);
        $ek = strtolower($bsEmpCode);

        if ($bsDevId === '' || $bsEmpCode === '') {
            $bsOut[] = ['err', $n . 'Biometric ID and Employee ID are both required.'];
        } elseif (isset($bsByDev[$dk])) {
            $bsOut[] = ['dup', $n . 'Biometric ID ' . $bsDevId . ' is already mapped with ' . SmartPRS\Surface\Employment::label($bsByDev[$dk]) . '.'];
        } elseif (!isset($bsEmps[$ek])) {
            $bsOut[] = ['err', $n . 'Employee ID ' . $bsEmpCode . ' does not exist in SmartPRS. Column B must be the Employee ID shown in People → Directory, not the device ID.'];
        } elseif (isset($bsByEmp[$ek])) {
            $bsOut[] = ['dup', $n . 'Employee ID ' . SmartPRS\Surface\Employment::code($bsEmps[$ek]) . ' is already mapped with Biometric ID ' . $bsByEmp[$ek] . '.'];
        } elseif (isset($bsSeen[$dk])) {
            $bsOut[] = ['dup', $n . 'Biometric ID ' . $bsDevId . ' repeats in the file (already used for ' . $bsSeen[$dk] . ').'];
        } elseif (isset($bsSeen['e:' . $ek])) {
            $bsOut[] = ['dup', $n . 'Employee ID ' . $bsEmpCode . ' repeats in the file.'];
        } else {
            $bsE = $bsEmps[$ek];
            $bsCode = SmartPRS\Surface\Employment::code($bsE);
            $bsSeen[$dk] = $bsCode;
            $bsSeen['e:' . $ek] = 1;
            $store->put('employees', ['id' => $bsE['id'], 'device_user_id' => $bsDevId]);
            $bsMoved = $bsIngest->promote($bsDevId, $bsCode);
            $audit('biometric.mapped', 'employees', (string) $bsE['id'], ['device_user_id' => $bsE['device_user_id'] ?? null], ['device_user_id' => $bsDevId], 'Bulk mapping; ' . $bsMoved . ' held punch(es) moved into attendance');
            $bsOut[] = ['ok', $n . 'Mapped ' . $bsDevId . ' to ' . $bsCode . '.' . ($bsMoved > 0 ? ' ' . $bsMoved . ' held punch(es) released.' : '')];
            $bsDone++;
        }
    }

    $_SESSION['bio_bulk'] = ['title' => 'Bulk mapping: ' . $bsDone . ' mapped, ' . (count($bsRows) - $bsDone) . ' not changed', 'lines' => $bsOut];
    header('Location: ' . $bioBack);
    exit;
}

if ($bioOp === 'setup-map') {
    // One Biometric ID belongs to one employee: if somebody else holds it, ask first (SmartPRS 2's
    // needForce), then the ordinary map below moves it.
    $bsEmp = $store->find('employees', post('employee'));
    $bsCode = trim(post('device_code'));
    $bsHolder = null;
    foreach ($store->all('employees') as $bsO) {
        if ($bsEmp !== null && (string) $bsO['id'] !== (string) $bsEmp['id'] && $bsCode !== '' && trim((string) ($bsO['deleted_at'] ?? '')) === ''
            && strcasecmp(trim((string) ($bsO['device_user_id'] ?? '')), $bsCode) === 0) {
            $bsHolder = $bsO;
        }
    }

    if ($bsEmp === null || $bsCode === '') {
        $_SESSION['flash_problem'] = 'Pick (or type) a biometric ID and choose the employee.';
        header('Location: ' . $bioBack);
        exit;
    }

    if ($bsHolder !== null && post('force') !== '1') {
        $_SESSION['bio_map_confirm'] = ['code' => $bsCode, 'employee' => (string) $bsEmp['id'],
            'message' => 'Biometric ID "' . $bsCode . '" is already mapped to ' . SmartPRS\Surface\Employment::label($bsHolder) . '.'];
        header('Location: ' . $bioBack);
        exit;
    }

    $bioOp = 'map';
}

if ($bioOp === 'sync' || $bioOp === 'test') {
    $cfg = $store->find('biometric_configs', post('id'));

    if ($cfg === null) {
        $_SESSION['flash_problem'] = 'No such device.';
    } else {
        @set_time_limit(180);
        $r = (new SmartPRS\Surface\BiometricSync($store, $pdo, $tenantId, dirname((dirname(__DIR__, 4) . '/plugins/smartprs3/surface/actions'), 2)))->run($cfg, $bioOp === 'test' ? 1 : max(1, (int) post('days')), $bioOp === 'test');
        $_SESSION['bio_result'] = $r + ['op' => $bioOp, 'title' => ($bioOp === 'test' ? 'Test of ' : 'Sync of ') . (string) ($cfg['label'] ?? 'device') . ($bioOp === 'test' ? ' (nothing written)' : '')];
        $audit('biometric.' . $bioOp, 'biometric_configs', (string) $cfg['id'], null, ['fetched' => $r['fetched'], 'accepted' => $r['accepted'], 'error' => $r['error']], 'manual');
    }
} elseif ($bioOp === 'map') {
    $emp = $store->find('employees', post('employee'));
    $code = trim(post('device_code'));

    // ONE ATTENDANCE SOURCE PER COMPANY (owner, 12 Nov 2026): SmartEPT punches never land on a QR Attendance company's employee.
    $bioFromEpt = false;
    foreach ($store->all('biometric_unmapped') as $u) {
        strcasecmp((string) ($u['device_code'] ?? ''), $code) === 0 && (string) ($u['source'] ?? '') === 'smartept' && $bioFromEpt = true;
    }
    $bioQrCo = $emp !== null && $bioFromEpt && SmartPRS\Surface\QrAttendance::load($customisation, dirname((dirname(__DIR__, 4) . '/plugins/smartprs3/surface/actions'), 2))->takesSmartEpt((string) ($emp['employer_company_id'] ?? '')) === false;

    if ($emp === null || $code === '') {
        $_SESSION['flash_problem'] = 'Choose an employee to map this device id to.';
    } elseif ($bioQrCo) {
        $_SESSION['flash_problem'] = SmartPRS\Surface\Employment::label($emp) . '\'s company records attendance by QR Attendance or the biometric device, so SmartEPT punches are not taken for them (one source per company — QR Attendance → Module & policy).';
    } else {
        foreach ($store->all('employees') as $other) {
            if ((string) $other['id'] !== (string) $emp['id'] && strcasecmp(trim((string) ($other['device_user_id'] ?? '')), $code) === 0) {
                $store->put('employees', ['id' => $other['id'], 'device_user_id' => null]);
            }
        }

        $store->put('employees', ['id' => $emp['id'], 'device_user_id' => $code]);
        $moved = (new SmartPRS\Surface\PunchIngest($store, $pdo, $tenantId))->promote($code, SmartPRS\Surface\Employment::code($emp));
        $audit('biometric.mapped', 'employees', (string) $emp['id'], ['device_user_id' => $emp['device_user_id'] ?? null], ['device_user_id' => $code], $moved . ' held punch(es) moved into attendance');
        $_SESSION['flash_notice'] = 'Device id ' . $code . ' is now ' . SmartPRS\Surface\Employment::label($emp) . ' — ' . $moved . ' held punch(es) moved into attendance.';
    }
}

header('Location: ' . $bioBack);
exit;

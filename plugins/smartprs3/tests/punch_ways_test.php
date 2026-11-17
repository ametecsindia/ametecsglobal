<?php

declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

/**
 * Owner, 15 Nov 2026: three tick boxes per employee — Biometric, QR, Punch (geo-fence). Whatever is ticked is allowed;
 * the rest is not. Nobody ticked yet keeps what the company's attendance source gives them.
 */
group('punch ways per employee');

$cfg = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/qr-attendance.json'), true);
$gates = [['code' => 'G1', 'branch_id' => 'B1', 'active' => true]];
$emp = ['id' => 'E1', 'employer_company_id' => 'C1', 'branch_id' => 'B1'];

$qa = new QrAttendance($cfg, ['sources' => ['C1' => 'qr_biometric'], 'gates' => $gates]);
same(null, $qa->waysSet('E1'), 'nothing ticked yet: no explicit setting');
same(['biometric' => true, 'qr' => true, 'punch' => false], $qa->ways($emp, false, '2026-11-15'), 'untouched: the company source decides (QR + Biometric)');

$qa = new QrAttendance($cfg, ['sources' => ['C1' => 'qr_biometric'], 'gates' => $gates,
    'punch_ways' => ['E1' => ['biometric' => true, 'qr' => true, 'punch' => false]]]);
same(['biometric' => true, 'qr' => true, 'punch' => false], $qa->waysSet('E1'), 'Biometric + QR ticked, read back');
ok($qa->ways($emp, true, '2026-11-15')['punch'] === false, 'Punch not ticked: no normal punch even with a geo-fence rule');
ok($qa->deviceAllowed('E1'), 'Biometric ticked: device punches are taken');

$qa = new QrAttendance($cfg, ['punch_ways' => ['E1' => ['biometric' => false, 'qr' => false, 'punch' => true]]]);
same(['biometric' => false, 'qr' => false, 'punch' => true], $qa->ways($emp, false, '2026-11-15'), 'only Punch ticked');
ok($qa->deviceAllowed('E1') === false, 'Biometric not ticked: device punches are refused');
ok($qa->deviceAllowed('E2'), 'someone never ticked: device punches are taken as before');

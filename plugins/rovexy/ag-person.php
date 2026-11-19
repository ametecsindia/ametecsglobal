<?php
/** ONE EMPLOYEE IN ROVEXY, FOR THE AMETECSGLOBAL EMPLOYEE 360 PAGE (19 Nov 2026). Same contract as plugins/smartept/ag-person.php; login code "ag-<id>". */
declare(strict_types=1);

return static function (string $tenant, int $id, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $q = $pdo->prepare("SELECT u.id, u.status FROM users u JOIN companies c ON c.id = u.company_id WHERE JSON_UNQUOTE(JSON_EXTRACT(c.settings, '$.ag_tenant')) = ? AND u.employee_code = ?");
    $q->execute([$tenant, 'ag-' . $id]);
    $u = $q->fetch();
    if ($u === false) {
        return ['tiles' => [], 'rows' => [], 'note' => 'Not in ROVEXY yet - copied the next time an administrator opens ROVEXY.'];
    }
    $s = $pdo->prepare('SELECT COUNT(*) n, COUNT(answered_at) c, COALESCE(SUM(talk_seconds), 0) t FROM calls WHERE user_id = ? AND is_test = 0 AND initiated_at >= ? AND initiated_at < ? + INTERVAL 1 DAY');
    $s->execute([$u['id'], $from, $to]);
    $c = $s->fetch();
    $f = $pdo->prepare("SELECT COUNT(*) FROM follow_ups WHERE user_id = ? AND status = 'open'");
    $f->execute([$u['id']]);
    $l = $pdo->prepare('SELECT initiated_at, destination, answered_at, talk_seconds, disposition_status FROM calls WHERE user_id = ? AND is_test = 0 AND initiated_at >= ? AND initiated_at < ? + INTERVAL 1 DAY ORDER BY initiated_at DESC LIMIT 12');
    $l->execute([$u['id'], $from, $to]);
    $rows = array_map(static fn ($x) => [substr((string) $x['initiated_at'], 0, 16), 'Call ' . substr((string) $x['destination'], 0, 4) . '••••' . substr((string) $x['destination'], -2), ($x['answered_at'] ? 'connected · ' . (int) $x['talk_seconds'] . 's' : 'not connected') . ($x['disposition_status'] ? ' · ' . $x['disposition_status'] : '')], $l->fetchAll());
    return ['tiles' => [
        ['Calls', (string) $c['n'], $c['c'] . ' connected'],
        ['Talk time', sprintf('%dh %02dm', intdiv((int) $c['t'], 3600), intdiv((int) $c['t'] % 3600, 60)), 'connected calls'],
        ['Follow-ups open', (string) $f->fetchColumn(), 'all dates'],
        ['Login', ucfirst((string) $u['status']), 'ROVEXY app'],
    ], 'rows' => $rows];
};

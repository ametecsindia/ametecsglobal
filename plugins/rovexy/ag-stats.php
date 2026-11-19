<?php
/**
 * ROVEXY'S FIGURES FOR THE AMETECSGLOBAL DASHBOARD (19 Nov 2026). Contract: plugins/smartprs3/ag-stats.php.
 * Reads ROVEXY's own database (plugins/rovexy/.env); the client's workspace is the one linked to it (companies.settings.ag_tenant).
 * Employee filters map through the synced login codes "ag-<employee id>".
 */
declare(strict_types=1);

return static function (\PDO $agPdo, string $tenant, array $f = []): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    if (($e['DB_DATABASE'] ?? '') === '') {
        return ['tiles' => []];
    }
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . $e['DB_DATABASE'] . ';charset=utf8mb4',
        (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare("SELECT id FROM companies WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.ag_tenant')) = ?");
    $q->execute([$tenant]);
    $cid = $q->fetchColumn();
    if ($cid === false) {
        return ['tiles' => [], 'list' => ['title' => 'ROVEXY', 'rows' => [['text' => 'Not opened yet', 'sub' => 'Open ROVEXY once from My applications to set it up.']]]];
    }
    $today = date('Y-m-d');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) === 1 ? min((string) $f['to'], $today) : $today;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) === 1 ? min((string) $f['from'], $to) : $to;
    $who = '';
    $args = [$cid];
    if (is_array($f['ids'] ?? null)) {   // the dashboard's employee filters
        $codes = array_map(static fn ($id): string => 'ag-' . (int) $id, $f['ids']) ?: ['-'];
        $who = ' AND user_id IN (SELECT id FROM users WHERE company_id = ? AND employee_code IN (' . implode(',', array_fill(0, count($codes), '?')) . '))';
        $args = [$cid, $cid, ...$codes];
    }
    $one = static function (string $sql, array $more = []) use ($pdo, $args): array {
        $s = $pdo->prepare($sql);
        $s->execute([...$args, ...$more]);
        return $s->fetch(\PDO::FETCH_NUM) ?: [0, 0, 0];
    };
    [$calls, $connected, $talk] = $one("SELECT COUNT(*), COUNT(answered_at), COALESCE(SUM(talk_seconds), 0) FROM calls WHERE company_id = ?{$who} AND is_test = 0 AND initiated_at >= ? AND initiated_at < ? + INTERVAL 1 DAY", [$from, $to]);
    [$due] = $one("SELECT COUNT(*) FROM follow_ups WHERE company_id = ?{$who} AND status = 'open' AND due_at < ? + INTERVAL 1 DAY", [$to]);
    [$onDuty] = $one("SELECT COUNT(DISTINCT user_id) FROM work_sessions WHERE company_id = ?{$who} AND ended_at IS NULL AND lease_expires_at > NOW()");
    [$users] = $one("SELECT COUNT(*) FROM users WHERE company_id = ? AND status = 'active'" . str_replace('user_id', 'id', $who));
    $rate = $calls > 0 ? round($connected * 100 / $calls) : 0;
    return ['tiles' => [
        ['key' => 'rx-users', 'label' => 'ROVEXY users', 'value' => (string) $users, 'sub' => 'active logins', 'tone' => 'blue'],
        ['key' => 'rx-duty', 'label' => 'On duty now', 'value' => (string) $onDuty, 'sub' => 'signed in on the phone', 'tone' => $onDuty > 0 ? 'green' : ''],
        ['key' => 'rx-calls', 'label' => 'Calls', 'value' => (string) $calls, 'sub' => $connected . ' connected (' . $rate . '%)', 'tone' => 'blue'],
        ['key' => 'rx-talk', 'label' => 'Talk time', 'value' => sprintf('%dh %02dm', intdiv((int) $talk, 3600), intdiv((int) $talk % 3600, 60)), 'sub' => 'connected calls', 'tone' => ''],
        ['key' => 'rx-due', 'label' => 'Follow-ups due', 'value' => (string) $due, 'sub' => 'open, due by ' . date('j M', strtotime($to)), 'tone' => $due > 0 ? 'amber' : 'green'],
    ]];
};

<?php
/**
 * AMETECSGLOBAL LICENSING (owner, 18 Nov 2026 - Phase 1): one licence per client per app, for every app,
 * on-premise or cloud, kept in the central database (ag_licences + ag_licence_events, migration 2026_11_18_000100).
 *
 *   status      active | suspended | cancelled            (set by Super Admin; nothing is ever deleted)
 *   state()     on | suspended | expired | off            (what the client actually gets today)
 *   deployment  cloud  -> monthly / yearly plans, renew / cancel, runs on Ametecs' servers
 *               onprem -> a licence key, bound to ONE server (fingerprint) at first activation; a signed .lic file
 *                         for offline installs (same RSA key and token shape as SmartPRS3's, product = the app code)
 *
 * Until the migration has run on a PC, everything falls back to the old core/data/access.json, so nothing breaks
 * in between. The first time the table exists, access.json is imported once and renamed *.imported.
 */
declare(strict_types=1);

final class AmetecsLicences
{
    public const DEPLOYMENTS = ['cloud' => 'Cloud', 'onprem' => 'On-premise'];
    public const PLANS = ['trial' => 'Trial', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'perpetual' => 'Perpetual'];
    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    public const STATES = ['on' => ['Active', 'green'], 'suspended' => ['Suspended', 'amber'], 'expired' => ['Expired', 'red'], 'off' => ['Not licensed', 'grey']];

    /** Who is making changes (Super Admin email, or "licence server" for activations). */
    public static string $actor = 'AmetecsGlobal';

    private static ?\PDO $pdo = null;
    private static ?bool $ready = null;
    /** @var array<string, array<string, array<string, mixed>>>|null [tenant][app] => row */
    private static ?array $cache = null;

    public static function pdo(): ?\PDO
    {
        return self::$pdo ??= \SmartPRS\Surface\Database::connect(AG_BASE . '/.env');
    }

    /** True once the ag_licences table exists on this PC (and the old JSON has been imported). */
    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        $pdo = self::pdo();
        try {
            self::$ready = $pdo !== null && $pdo->query("SHOW TABLES LIKE 'ag_licences'")->fetchColumn() !== false;
        } catch (\Throwable) {
            self::$ready = false;
        }
        self::$ready && self::importJson();
        return self::$ready;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    }

    /** All licences: [tenant id][app code] => row. */
    public static function rows(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        if (self::ready()) {
            foreach (self::pdo()->query('SELECT * FROM ag_licences') as $r) {
                self::$cache[(string) $r['tenant_id']][(string) $r['app']] = $r;
            }
        }
        return self::$cache;
    }

    public static function row(string $tenant, string $app): ?array
    {
        return self::rows()[$tenant][$app] ?? null;
    }

    public static function byId(string $id): ?array
    {
        if (!self::ready() || !ctype_digit($id)) {
            return null;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_licences WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** on | suspended | expired | off - what the client gets today. */
    public static function stateOf(?array $r): string
    {
        if ($r === null || (string) $r['status'] === 'cancelled') {
            return 'off';
        }
        if ((string) $r['status'] === 'suspended') {
            return 'suspended';
        }
        $until = (string) ($r['valid_until'] ?? '');
        return $until !== '' && $until < AmetecsAccess::today() ? 'expired' : 'on';
    }

    /** The old access.json shape every existing caller understands: [tenant][app] => [on, seats, until, ...row]. */
    public static function asAccess(): array
    {
        $out = [];
        foreach (self::rows() as $tid => $apps) {
            foreach ($apps as $app => $r) {
                $out[$tid][$app] = ['on' => (string) $r['status'] !== 'cancelled', 'seats' => (int) $r['seats'], 'until' => (string) ($r['valid_until'] ?? ''),
                    'state' => self::stateOf($r)] + $r;
            }
        }
        return $out;
    }

    /**
     * Create or change one licence. $set holds only the fields to change. Returns the saved row.
     * A brand-new licence defaults to active, cloud, yearly, starting today.
     */
    public static function put(string $tenant, string $app, array $set, string $why = 'Changed'): array
    {
        if (!self::ready()) {
            throw new \RuntimeException('The licence tables are not on this PC yet (the next sync adds them).');
        }
        $allowed = ['deployment', 'plan', 'status', 'seats', 'starts_on', 'valid_until', 'price', 'currency', 'auto_renew', 'key_hash', 'key_enc',
            'fingerprint', 'server_name', 'server_os', 'app_version', 'activated_at', 'last_seen_at', 'notes'];
        $set = array_intersect_key($set, array_flip($allowed));
        $old = self::row($tenant, $app);
        $pdo = self::pdo();
        $now = self::now();
        if ($old === null) {
            $set += ['deployment' => 'cloud', 'plan' => 'yearly', 'status' => 'active', 'starts_on' => AmetecsAccess::today()];
            $cols = array_merge(['tenant_id', 'app', 'created_at', 'updated_at', 'updated_by'], array_keys($set));
            $pdo->prepare('INSERT INTO ag_licences (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_merge([$tenant, $app, $now, $now, self::$actor], array_values($set)));
            $id = (string) $pdo->lastInsertId();
        } else {
            $id = (string) $old['id'];
            $changed = array_filter($set, static fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                return $old;
            }
            $set = $changed;
            $pdo->prepare('UPDATE ag_licences SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($set))) . ', updated_at = ?, updated_by = ? WHERE id = ?')
                ->execute(array_merge(array_values($set), [$now, self::$actor, $id]));
        }
        self::$cache = null;
        $new = (array) self::byId($id);
        $shown = array_diff_key($set, ['key_hash' => 1, 'key_enc' => 1, 'last_seen_at' => 1]);
        self::event($new, $old === null ? 'Created' : $why, $shown === [] ? '' : implode(', ', array_map(
            static fn ($k, $v) => $k . ' ' . ($old === null || !array_key_exists($k, $old) ? '' : ((string) ($old[$k] ?? '') === '' ? '(empty)' : $old[$k]) . ' -> ') . ((string) ($v ?? '') === '' ? '(empty)' : $v),
            array_keys($shown), $shown)));
        return $new;
    }

    public static function event(array $licence, string $action, string $detail = ''): void
    {
        if (!self::ready() || empty($licence['id'])) {
            return;
        }
        self::pdo()->prepare('INSERT INTO ag_licence_events (licence_id, tenant_id, app, action, detail, by_who, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$licence['id'], $licence['tenant_id'], $licence['app'], $action, $detail, self::$actor, self::now()]);
    }

    /** Newest first; one licence, one client, or everything. */
    public static function events(?string $licenceId = null, ?string $tenant = null, int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }
        $w = $licenceId !== null ? 'licence_id = ?' : ($tenant !== null ? 'tenant_id = ?' : '1 = 1');
        $q = self::pdo()->prepare('SELECT * FROM ag_licence_events WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . max(1, $limit));
        $q->execute($licenceId !== null ? [$licenceId] : ($tenant !== null ? [$tenant] : []));
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** The end date after one more period of the plan, counted from the later of today and the current end. */
    public static function nextUntil(array $r): ?string
    {
        $from = max(AmetecsAccess::today(), (string) ($r['valid_until'] ?? '') ?: AmetecsAccess::today());
        $step = ['monthly' => '+1 month', 'yearly' => '+1 year', 'trial' => '+14 days'][(string) $r['plan']] ?? null;
        return $step === null ? null : (new DateTimeImmutable($from))->modify($step)->format('Y-m-d');
    }

    // ------------------------------------------------------------------ on-premise keys and .lic files

    public static function newKey(): string
    {
        $cs = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $b = static function () use ($cs): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $cs[random_int(0, strlen($cs) - 1)];
            }
            return $s;
        };
        return 'AG-' . $b() . '-' . $b() . '-' . $b() . '-' . $b();
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $key)));
    }

    /** Issue (or replace) the licence key. Returns the plain key - shown once, stored only hashed + encrypted. */
    public static function issueKey(string $tenant, string $app): string
    {
        $key = self::newKey();
        self::put($tenant, $app, ['key_hash' => self::hashKey($key), 'key_enc' => \SmartPRS\Surface\Secret::encrypt($key, AG_BASE . '/.env')], 'Issued a new licence key');
        return $key;
    }

    public static function keyOf(array $r): string
    {
        return empty($r['key_enc']) ? '' : (string) \SmartPRS\Surface\Secret::decrypt((string) $r['key_enc'], AG_BASE . '/.env');
    }

    public static function privateKeyPath(): string
    {
        return AG_BASE . '/storage/keys/license_private.pem';
    }

    public static function canSign(): bool
    {
        return is_readable(self::privateKeyPath());
    }

    /** A signed, server-locked licence code (.lic) for this licence. Same token shape SmartPRS3 verifies. */
    public static function licFile(array $r, string $fingerprint): string
    {
        $priv = openssl_pkey_get_private((string) @file_get_contents(self::privateKeyPath()));
        if ($priv === false) {
            throw new \RuntimeException('The licence signing key is not on this server (plugins/smartprs3/storage/keys/license_private.pem). It lives only on the live licence server.');
        }
        $key = self::keyOf($r);
        if ($key === '') {
            throw new \RuntimeException('Issue a licence key first.');
        }
        $t = AmetecsAccess::tenantName((string) $r['tenant_id']);
        $payload = ['v' => 1, 'product' => (string) $r['app'], 'key' => $key, 'company' => $t, 'tenant' => (int) $r['tenant_id'],
            'device_limit' => (int) $r['seats'] ?: null, 'seats' => (int) $r['seats'], 'kind' => (string) $r['plan'], 'deployment' => 'onprem',
            'expires_at' => ($r['valid_until'] ?? '') ?: null, 'grace_days' => 7, 'features' => [], 'fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            'issued_at' => date('c')];
        $b64 = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);
        self::event($r, 'Issued a .lic file', 'locked to ' . ($fingerprint !== '' ? substr($fingerprint, 0, 16) . '...' : 'no server'));
        return $b64 . '.' . rtrim(strtr(base64_encode((string) $sig), '+/', '-_'), '=');
    }

    /**
     * An on-premise install activating or checking in (core/licence-api.php). Binds the server on first activation;
     * refuses another server. @return array{ok: bool, status: string, message: string, licence?: array}
     */
    public static function serverCall(string $action, string $app, string $key, string $fingerprint, array $info): array
    {
        $neutral = ['ok' => false, 'status' => 'unknown', 'message' => 'Licence not recognised. Check the key or contact Ametecs India.'];
        if (!self::ready() || trim($key) === '' || $fingerprint === '') {
            return $neutral;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_licences WHERE key_hash = ? LIMIT 1');
        $q->execute([self::hashKey($key)]);
        $r = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($r === null || (string) $r['app'] !== $app || (string) $r['deployment'] !== 'onprem') {
            return $neutral;
        }
        $was = self::$actor;
        self::$actor = 'licence server';
        try {
            $bound = trim((string) ($r['fingerprint'] ?? ''));
            if ($bound !== '' && !hash_equals($bound, $fingerprint)) {
                self::event($r, 'Refused another server', ($info['server_name'] ?? '') . ' ' . substr($fingerprint, 0, 16) . '...');
                return ['ok' => false, 'status' => 'wrong_server', 'message' => 'This licence is already activated on another server. Contact Ametecs India to move it.'];
            }
            $st = self::stateOf($r);
            $set = ['last_seen_at' => self::now(), 'server_name' => mb_substr((string) ($info['server_name'] ?? ''), 0, 190) ?: $r['server_name'],
                'server_os' => mb_substr((string) ($info['server_os'] ?? ''), 0, 99) ?: $r['server_os'], 'app_version' => mb_substr((string) ($info['version'] ?? ''), 0, 39) ?: $r['app_version']];
            if ($bound === '' && $action === 'activate' && $st === 'on') {
                $set += ['fingerprint' => $fingerprint, 'activated_at' => self::now()];
            }
            $r = self::put((string) $r['tenant_id'], $app, $set, $action === 'activate' ? 'Activated on a server' : 'Checked in');
            $ok = $st === 'on' && ($bound !== '' || $action === 'activate');
            return ['ok' => $ok, 'status' => $st, 'message' => ['on' => $ok ? 'Licence active.' : 'Activate this licence first.', 'suspended' => 'This licence is suspended. Contact Ametecs India.',
                'expired' => 'This licence has expired. Renew it to continue.', 'off' => 'This licence has been cancelled.'][$st],
                'licence' => ['app' => $app, 'seats' => (int) $r['seats'], 'plan' => (string) $r['plan'], 'valid_until' => ($r['valid_until'] ?? '') ?: null]];
        } finally {
            self::$actor = $was;
        }
    }

    public static function byKey(string $key): ?array
    {
        if (!self::ready() || trim($key) === '') {
            return null;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_licences WHERE key_hash = ? LIMIT 1');
        $q->execute([self::hashKey($key)]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ------------------------------------------------------------------ the one-time move from access.json

    private static function importJson(): void
    {
        $f = AmetecsAccess::FILE;
        if (!is_file($f)) {
            return;
        }
        $all = json_decode((string) file_get_contents($f), true);
        $pdo = self::pdo();
        $was = self::$actor;
        self::$actor = 'import from access.json';
        try {
            foreach (is_array($all) ? $all : [] as $tid => $apps) {
                foreach ((array) $apps as $app => $a) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM ag_licences WHERE tenant_id = ? AND app = ?');
                    $exists->execute([$tid, $app]);
                    if ((int) $exists->fetchColumn() > 0 || !ctype_digit((string) $tid)) {
                        continue;
                    }
                    self::$cache = [];   // the row lookup in put() must not read the table again mid-import
                    self::put((string) $tid, (string) $app, ['status' => !empty($a['on']) ? 'active' : 'cancelled', 'seats' => max(0, (int) ($a['seats'] ?? 0)),
                        'valid_until' => ((string) ($a['until'] ?? '')) ?: null, 'notes' => isset($a['changed']) ? 'Last change before the move: ' . $a['changed'] : null]);
                }
            }
            @rename($f, $f . '.imported');
        } finally {
            self::$actor = $was;
            self::$cache = null;
        }
    }
}

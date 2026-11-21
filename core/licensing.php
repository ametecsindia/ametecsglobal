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
    public static string $actor = 'Ametecs Global';

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
        self::$ready && self::backfillInstalled();
        return self::$ready;
    }

    /**
     * DOWNLOAD FIRST (owner, 19 Nov 2026): a cloud app Super Admin enables (or the client buys) shows "Download" in My
     * applications; Download installs it into the client's account (activated_at = when). On-premise licences are installed
     * on the client's own server (their activation is the licence-server check-in), so they count as installed here.
     */
    public static function installed(?array $r): bool
    {
        return $r !== null && ($r['deployment'] === 'onprem' || !empty($r['activated_at']));
    }


    /**
     * THE INSTALL, STEP BY STEP (owner, 19 Nov 2026: "first complete download from super admin then install - maintenance
     * mode"). 1 download: the newest published package of the app from Super Admin -> Updates & releases (checked against
     * its SHA-256), else the build already on this server. 2 install: the app is in MAINTENANCE MODE (it does not open)
     * while its set-up hook runs. 3 activate: the licence is activated and the app opens. Progress per client and app in
     * core/data/installs/ so a closed browser can carry on where it stopped.
     */
    private static function stateFile(string $tenant, string $app): string
    {
        return __DIR__ . '/data/installs/' . preg_replace('/[^0-9]/', '', $tenant) . '-' . preg_replace('/[^a-z0-9]/', '', $app) . '.json';
    }

    public static function installState(string $tenant, string $app): ?array
    {
        $f = self::stateFile($tenant, $app);
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j) ? $j : null;
    }

    /** True while an install is under way: the app shows "maintenance mode" and does not open. */
    public static function maintenance(string $tenant, string $app): bool
    {
        return self::installState($tenant, $app) !== null;
    }

    /** @return array{ok: bool, phase?: string, message: string, version?: string, pct?: int} */
    public static function installStep(string $tenant, string $app, string $step): array
    {
        $r = self::row($tenant, $app);
        if ($r === null || self::stateOf($r) !== 'on') {
            return ['ok' => false, 'message' => 'That application is not enabled for your company.'];
        }
        if (self::installed($r) && $step !== 'activate') {
            return ['ok' => true, 'phase' => 'done', 'message' => 'Already installed.', 'pct' => 100];
        }
        $f = self::stateFile($tenant, $app);
        @mkdir(dirname($f), 0775, true);
        $st = self::installState($tenant, $app) ?? [];
        $save = static function (array $s) use ($f): void { file_put_contents($f, json_encode($s), LOCK_EX); };
        if ($step === 'download') {
            $rel = null;
            foreach (AmetecsReleases::offerFor($tenant, $app, '')['updates'] as $u) {
                if ($u['kind'] === 'full' && AmetecsReleases::stateOf($u) === 'published') { $rel = $u; break; }
            }
            if ($rel !== null) {
                $file = AmetecsReleases::path($rel);
                if (!is_file($file) || !hash_equals((string) $rel['sha256'], (string) hash_file('sha256', $file))) {
                    return ['ok' => false, 'message' => 'The package for version ' . $rel['version'] . ' is missing or damaged on the Ametecs server. Contact Ametecs.'];
                }
                AmetecsReleases::event($rel, $app, $tenant, 'downloaded', '', (string) $rel['version'], 'cloud install from My applications', (string) $r['id']);
            }
            $root = AmetecsReleases::pluginRoot($app);
            $ver = $rel !== null ? (string) $rel['version'] : (AmetecsReleases::platformVersion($app)
                ?: (string) (json_decode((string) @file_get_contents((string) $root . '/version.json'), true)['version'] ?? ''));
            $save(['phase' => 'downloaded', 'version' => $ver, 'release' => $rel['id'] ?? null, 'at' => self::now()]);
            return ['ok' => true, 'phase' => 'downloaded', 'version' => $ver, 'pct' => 35,
                'message' => 'Downloaded ' . ($ver !== '' ? 'version ' . $ver . ' ' : '') . ($rel !== null ? 'from Ametecs (package checked)' : 'from Ametecs') . '.'];
        }
        if ($step === 'install') {
            if (!in_array($st['phase'] ?? '', ['downloaded', 'installing'], true)) {
                return ['ok' => false, 'message' => 'Download it first.'];
            }
            $save(['phase' => 'installing'] + $st);   // maintenance mode on
            $hook = __DIR__ . '/../plugins/' . preg_replace('/[^a-z0-9]/', '', $app) . '/ag-install-tenant.php';
            is_file($hook) && (require $hook)(self::pdo(), $tenant);
            $save(['phase' => 'installed'] + $st);
            return ['ok' => true, 'phase' => 'installed', 'pct' => 75, 'message' => 'Installed and set up for your company.'];
        }
        if ($step === 'activate') {
            if (!self::installed($r)) {
                if (($st['phase'] ?? '') !== 'installed') {
                    return ['ok' => false, 'message' => 'Install it first.'];
                }
                self::put($tenant, $app, ['activated_at' => self::now()] + (($st['version'] ?? '') !== '' ? ['app_version' => $st['version']] : []), 'Downloaded and installed by the client');
            }
            @unlink($f);   // maintenance mode off
            return ['ok' => true, 'phase' => 'done', 'pct' => 100, 'message' => 'Licence activated. Ready to open.'];
        }
        return ['ok' => false, 'message' => 'Unknown step.'];
    }

    /**
     * SELF UPDATE (owner, 19 Nov 2026: "i want to self update"): the client's administrator updates a cloud app from My
     * applications - 1 download: the newest published release Super Admin lets clients download (SHA-256 checked);
     * 2 install: MAINTENANCE MODE for the app while Ametecs' cloud copy is updated (backup + roll-back on failure, database
     * changes run). The cloud copy is shared, so every client of this server moves to the new version.
     * @return array{ok: bool, phase?: string, message: string, version?: string, pct?: int}
     */
    public static function updateStep(string $tenant, string $app, string $step): array
    {
        $r = self::row($tenant, $app);
        if ($r === null || self::stateOf($r) !== 'on' || $r['deployment'] !== 'cloud' || !self::installed($r)) {
            return ['ok' => false, 'message' => 'Only an installed cloud application with an active licence updates here.'];
        }
        $cur = AmetecsReleases::platformVersion($app);
        $rel = AmetecsReleases::offerFor($tenant, $app, $cur)['updates'][0] ?? null;
        $f = self::stateFile($tenant, $app);
        if ($rel === null) {
            @unlink($f);
            return ['ok' => true, 'phase' => 'done', 'pct' => 100, 'version' => $cur, 'message' => 'Already on the newest version (' . $cur . ').'];
        }
        if (empty($rel['downloadable'])) {
            return ['ok' => false, 'message' => 'Ametecs installs this update for you. Contact Ametecs to have it installed now.'];
        }
        if (AmetecsReleases::payment($rel, $tenant) === 'required') {
            return ['ok' => false, 'message' => 'This upgrade is paid. Pay it under Payments & invoices first.'];
        }
        @mkdir(dirname($f), 0775, true);
        if ($step === 'download') {
            $file = AmetecsReleases::path($rel);
            if (!is_file($file) || !hash_equals((string) $rel['sha256'], (string) hash_file('sha256', $file))) {
                return ['ok' => false, 'message' => 'The ' . $rel['version'] . ' package is missing or damaged on the Ametecs server. Contact Ametecs.'];
            }
            AmetecsReleases::event($rel, $app, $tenant, 'downloaded', $cur, (string) $rel['version'], 'self update from My applications', (string) $r['id']);
            file_put_contents($f, json_encode(['phase' => 'update-downloaded', 'version' => $rel['version'], 'at' => self::now()]), LOCK_EX);
            return ['ok' => true, 'phase' => 'downloaded', 'pct' => 40, 'version' => (string) $rel['version'], 'message' => 'Downloaded ' . $rel['version'] . ' from Ametecs (package checked).'];
        }
        if ($step === 'install') {
            file_put_contents($f, json_encode(['phase' => 'installing', 'version' => $rel['version'], 'at' => self::now()]), LOCK_EX);   // maintenance mode on
            try {
                $res = AmetecsReleases::installOnPlatform($rel);
            } finally {
                @unlink($f);   // maintenance mode off, installed or put back
            }
            if (!$res['ok']) {
                return ['ok' => false, 'message' => 'Not updated: ' . $res['message'] . ' Your ' . $cur . ' keeps running.'];
            }
            self::put($tenant, $app, ['app_version' => (string) $rel['version']], 'Updated by the client to ' . $rel['version']);
            return ['ok' => true, 'phase' => 'done', 'pct' => 100, 'version' => (string) $rel['version'], 'message' => $res['message']];
        }
        return ['ok' => false, 'message' => 'Unknown step.'];
    }

    /** Apps already in use when "Download first" arrived (19 Nov 2026) stay installed: once per PC. */
    private static function backfillInstalled(): void
    {
        $mark = __DIR__ . '/data/installed-backfill.done';
        if (is_file($mark)) {
            return;
        }
        self::pdo()->exec("UPDATE ag_licences SET activated_at = COALESCE(updated_at, created_at, NOW()) WHERE deployment = 'cloud' AND activated_at IS NULL AND status <> 'cancelled'");
        @file_put_contents($mark, date('c'));
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
        in_array($set['status'] ?? '', ['suspended', 'cancelled'], true) && self::revokeCodes($tenant, $app);   // a deactivation also withdraws codes not used yet
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

    /** Check a .lic file a client holds against this licence (signature, product, client, key, server, end date). @return array{0: bool, 1: string} */
    public static function verifyLic(array $r, string $tok): array
    {
        $p = explode('.', trim($tok));
        $d = static fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $pub = count($p) === 2 ? openssl_pkey_get_public(\SmartPRS\Surface\Licence::PUBLIC_KEY) : false;
        if ($pub === false || openssl_verify($p[0], (string) $d($p[1]), $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return [false, 'Not a genuine Ametecs licence file: the signature does not match (wrong file, or it was edited).'];
        }
        $j = json_decode((string) $d($p[0]), true) ?: [];
        $bound = trim((string) ($r['fingerprint'] ?? ''));
        $why = match (true) {
            ($j['product'] ?? '') !== $r['app'] => 'it is for another application (' . ($j['product'] ?? '?') . ')',
            (int) ($j['tenant'] ?? 0) !== (int) $r['tenant_id'] => 'it was issued to another company',
            !hash_equals(self::keyOf($r), (string) ($j['key'] ?? '')) => 'it carries an old licence key - ask Ametecs for a new file',
            $bound !== '' && !hash_equals($bound, (string) ($j['fingerprint'] ?? '')) => 'it is locked to a different server than the one bound to this licence',
            !empty($j['expires_at']) && (string) $j['expires_at'] < date('Y-m-d') => 'it ended on ' . $j['expires_at'],
            default => '',
        };
        return $why !== '' ? [false, 'Genuine Ametecs file, but ' . $why . '.']
            : [true, 'Genuine Ametecs licence: ' . (int) ($j['seats'] ?? 0) . ' seats, ' . (empty($j['expires_at']) ? 'no end date' : 'valid till ' . $j['expires_at']) . ', locked to server ' . substr((string) ($j['fingerprint'] ?? '-'), 0, 12) . '..., issued ' . substr((string) ($j['issued_at'] ?? ''), 0, 10) . '.'];
    }

    // ------------------------------------------------------------------ licence codes (LC) and .lic activation by the client
    // (owner, 19 Nov 2026: "enter an approved LC, upload a valid .lic, or pay" - Super Admin creates and approves, the client only applies.)

    private static function codeFile(): string
    {
        return __DIR__ . '/data/licence-codes.json';
    }

    private static function codes(): array
    {
        return json_decode((string) @file_get_contents(self::codeFile()), true) ?: [];
    }

    private static function saveCodes(array $all): void
    {
        @mkdir(dirname(self::codeFile()), 0775, true);
        file_put_contents(self::codeFile(), json_encode(array_values($all), JSON_PRETTY_PRINT), LOCK_EX);   // ponytail: one JSON file; a table when codes run into thousands
    }

    /** Super Admin: a one-time code carrying the approved terms. Shown once; stored hashed. */
    public static function issueCode(array $r, array $terms, int $validDays = 30): string
    {
        $code = 'LC' . substr(self::newKey(), 2);
        $all = self::codes();
        $all[] = ['hash' => self::hashKey($code), 'tail' => substr($code, -4), 'tenant' => (string) $r['tenant_id'], 'app' => (string) $r['app'], 'licence' => (string) $r['id'],
            'terms' => array_intersect_key($terms, array_flip(['plan', 'seats', 'valid_until', 'starts_on'])), 'reactivate' => in_array($r['status'], ['suspended', 'cancelled'], true),
            'issued_at' => self::now(), 'issued_by' => self::$actor, 'use_by' => date('Y-m-d', strtotime('+' . max(1, $validDays) . ' days')), 'used_at' => null, 'used_by' => null, 'revoked_at' => null];
        self::saveCodes($all);
        self::event($r, 'Issued a licence code', '…' . substr($code, -4) . ': ' . ($terms['seats'] ?? '') . ' seats till ' . ($terms['valid_until'] ?? '') . ', use by ' . date('d M Y', strtotime('+' . $validDays . ' days')));
        return $code;
    }

    /** Codes of one licence, newest first, with a state: waiting | used | revoked | lapsed. */
    public static function codesOf(string $tenant, string $app): array
    {
        $out = [];
        foreach (self::codes() as $c) {
            if ($c['tenant'] === $tenant && $c['app'] === $app) {
                $c['state'] = $c['used_at'] ? 'used' : ($c['revoked_at'] ? 'revoked' : ($c['use_by'] < date('Y-m-d') ? 'lapsed' : 'waiting'));
                $out[] = $c;
            }
        }
        return array_reverse($out);
    }

    public static function revokeCodes(string $tenant, string $app, ?string $tail = null): int
    {
        $n = 0;
        $all = self::codes();
        foreach ($all as &$c) {
            if ($c['tenant'] === $tenant && $c['app'] === $app && !$c['used_at'] && !$c['revoked_at'] && ($tail === null || $c['tail'] === $tail)) {
                $c['revoked_at'] = self::now();
                $n++;
            }
        }
        unset($c);
        $n > 0 && self::saveCodes($all);
        return $n;
    }

    /** Client: apply a licence code. The code says which app; it must be this company's, unused, not revoked and not lapsed. @return array{0: bool, 1: string, 2?: string} */
    public static function redeemCode(string $tenant, string $code, string $by): array
    {
        $h = self::hashKey($code);
        $all = self::codes();
        foreach ($all as $i => $c) {
            if (!hash_equals($c['hash'], $h)) {
                continue;
            }
            $r = self::row($tenant, (string) $c['app']);
            $why = match (true) {
                $c['tenant'] !== $tenant || $r === null => 'This licence code is not for your company.',
                (bool) $c['used_at'] => 'This licence code was already used on ' . date('d M Y', (int) strtotime((string) $c['used_at'])) . '.',
                (bool) $c['revoked_at'] => 'This licence code was withdrawn by Ametecs.',
                $c['use_by'] < date('Y-m-d') => 'This licence code lapsed on ' . date('d M Y', (int) strtotime($c['use_by'])) . '. Ask Ametecs for a new one.',
                in_array($r['status'], ['suspended', 'cancelled'], true) && !$c['reactivate'] => 'This licence was deactivated by Ametecs. Contact Ametecs.',
                default => '',
            };
            if ($why !== '') {
                return [false, $why];
            }
            $actor = self::$actor;
            self::$actor = $by;
            self::put($tenant, (string) $c['app'], $c['terms'] + ['status' => 'active'], 'Activated with licence code …' . $c['tail']);
            self::$actor = $actor;
            $all[$i]['used_at'] = self::now();
            $all[$i]['used_by'] = $by;
            self::saveCodes($all);
            return [true, 'Licence activated: ' . ($c['terms']['seats'] ?? $r['seats']) . ' seats, valid till ' . date('d M Y', (int) strtotime((string) ($c['terms']['valid_until'] ?? $r['valid_until']))) . '.', (string) $c['app']];
        }
        return [false, 'That licence code is not valid. Check it, or ask Ametecs.'];
    }

    /** Client: upload a .lic and apply it. Never reduces what the licence already has; never lifts a Super Admin deactivation. */
    public static function activateLic(array $r, string $tok, string $by): array
    {
        if (in_array($r['status'], ['suspended', 'cancelled'], true)) {
            return [false, 'This licence was deactivated by Ametecs. A licence file cannot reopen it - contact Ametecs.'];
        }
        if (self::keyOf($r) === '') {
            return [false, 'There is no licence key on this licence, so no .lic file can match it. Use a licence code or pay online.'];
        }
        [$ok, $msg] = self::verifyLic($r, $tok);
        if (!$ok) {
            return [false, $msg];
        }
        $j = json_decode((string) base64_decode(strtr(explode('.', trim($tok))[0], '-_', '+/')), true) ?: [];
        // ponytail: an older genuine file can't shorten anything (max of dates); replay of a file issued before a Super Admin
        // shortening would re-extend - add an issued_at check against Super Admin's last date change if that ever matters
        $until = max((string) ($r['valid_until'] ?? ''), (string) ($j['expires_at'] ?? ''));
        $set = ['valid_until' => $until !== '' ? $until : null, 'seats' => max((int) $r['seats'], (int) ($j['seats'] ?? 0)), 'status' => 'active']
            + (isset(self::PLANS[$j['kind'] ?? '']) ? ['plan' => (string) $j['kind']] : []);
        $actor = self::$actor;
        self::$actor = $by;
        self::put((string) $r['tenant_id'], (string) $r['app'], $set, 'Activated with a .lic file issued ' . substr((string) ($j['issued_at'] ?? ''), 0, 10));
        self::$actor = $actor;
        return [true, $msg . ' Applied.'];
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

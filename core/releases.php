<?php
/**
 * AMETECSGLOBAL UPDATE HUB (owner, 18 Nov 2026 - Phase 2): releases, patches and plugins for every app, published
 * from Super Admin and taken by clients. Tables: ag_releases, ag_release_access, ag_release_events
 * (migration 2026_11_18_000200).
 *
 *   kind        full (whole app) | patch (only the changed files) | plugin (an add-on module for the app)
 *   status      draft -> published (now, or at publish_at) -> disabled (paused) | withdrawn (pulled; never offered again)
 *   audience    all clients holding the app | selected clients (ag_release_access; per-client "free" grant)
 *   price       empty/0 = free. Paid releases need payment (Phase 3, Razorpay) or a free grant from Super Admin.
 *
 * Packages: the zip made by each app's release builder - files at the root plus release.json {product, version, notes}.
 * Stored in core/data/releases/<app>/ (never on GitHub), SHA-256 recorded, and signed with the licence private key
 * when this server holds it (same signature SmartPRS3's installer checks). Installing on Ametecs' cloud server uses
 * SmartPRS3's installer (surface/Updater.php): verify, back up every replaced file, write, restore on failure, migrate.
 */
declare(strict_types=1);

final class AmetecsReleases
{
    public const KINDS = ['full' => 'Full release', 'patch' => 'Patch', 'plugin' => 'Plugin / module'];
    public const STATUSES = ['draft' => ['Draft', 'grey'], 'published' => ['Published', 'green'], 'scheduled' => ['Scheduled', 'amber'],
        'disabled' => ['Disabled', 'grey'], 'withdrawn' => ['Withdrawn', 'red']];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $pdo = AmetecsLicences::pdo();
            self::$ready = $pdo !== null && $pdo->query("SHOW TABLES LIKE 'ag_release_events'")->fetchColumn() !== false;
        } catch (\Throwable) {
            self::$ready = false;
        }
        return self::$ready;
    }

    private static function pdo(): \PDO
    {
        return AmetecsLicences::pdo();
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    }

    public static function dir(string $app = ''): string
    {
        $d = __DIR__ . '/data/releases' . ($app !== '' ? '/' . preg_replace('/[^a-z0-9]/', '', $app) : '');
        is_dir($d) || @mkdir($d, 0775, true);
        return $d;
    }

    /** Zips dropped into core/data/releases/incoming/ (for packages bigger than PHP's upload limit). */
    public static function incoming(): array
    {
        return array_map('basename', glob(self::dir('incoming') . '/*.zip') ?: []);
    }

    public static function path(array $r): string
    {
        return self::dir((string) $r['app']) . '/' . basename((string) $r['file_name']);
    }

    // ------------------------------------------------------------------ reading

    public static function all(string $app = ''): array
    {
        if (!self::ready()) {
            return [];
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_releases' . ($app !== '' ? ' WHERE app = ?' : '') . ' ORDER BY id DESC');
        $q->execute($app !== '' ? [$app] : []);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function byId(string $id): ?array
    {
        if (!self::ready() || !ctype_digit($id)) {
            return null;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_releases WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** published | scheduled (published, time not reached) | draft | disabled | withdrawn */
    public static function stateOf(array $r): string
    {
        if ((string) $r['status'] !== 'published') {
            return (string) $r['status'];
        }
        return ($r['publish_at'] ?? '') !== '' && (string) $r['publish_at'] > self::now() ? 'scheduled' : 'published';
    }

    /** [tenant id => free?] for a release. */
    public static function access(string $releaseId): array
    {
        $q = self::pdo()->prepare('SELECT tenant_id, free FROM ag_release_access WHERE release_id = ?');
        $q->execute([$releaseId]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $out[(string) $a['tenant_id']] = (bool) $a['free'];
        }
        return $out;
    }

    /** Can this client see the release at all? (live, and for everyone or for them) */
    public static function visibleTo(array $r, string $tenant): bool
    {
        if (self::stateOf($r) !== 'published' || $tenant === '') {
            return false;
        }
        return (string) $r['audience'] === 'all' || array_key_exists($tenant, self::access((string) $r['id']));
    }

    /** free | granted | paid | required */
    public static function payment(array $r, string $tenant): string
    {
        if ($r['kind'] === 'plugin' && AmetecsPlugins::ready()) {   // a plugin bought once covers its later versions
            return ['free' => 'free', 'granted' => 'granted', 'purchased' => 'paid', 'locked' => 'required', 'blocked' => 'required'][AmetecsPlugins::state($tenant, $r)];
        }
        if ((float) ($r['price'] ?? 0) <= 0) {
            return 'free';
        }
        if (!empty(self::access((string) $r['id'])[$tenant])) {
            return 'granted';
        }
        // Paid = more "paid" than "refunded" events (Phase 3 billing writes both).
        $q = self::pdo()->prepare("SELECT SUM(action = 'paid') - SUM(action = 'refunded') FROM ag_release_events WHERE release_id = ? AND tenant_id = ? AND action IN ('paid', 'refunded')");
        $q->execute([$r['id'], $tenant]);
        return (int) $q->fetchColumn() > 0 ? 'paid' : 'required';
    }

    /**
     * What a client running $current of $app is offered: every visible full release / patch newer than $current whose
     * "requires" is met, newest first; plugins listed separately. A withdrawn release the client is running offers the
     * newest older stable release as a rollback.
     *
     * @return array{updates: list<array>, plugins: list<array>, mandatory: bool, rollback: ?array}
     */
    public static function offerFor(string $tenant, string $app, string $current): array
    {
        $updates = $plugins = [];
        $rollback = null;
        $runningWithdrawn = false;
        foreach (self::all($app) as $r) {
            if ($current !== '' && (string) $r['version'] === $current && (string) $r['status'] === 'withdrawn' && $r['kind'] !== 'plugin') {
                $runningWithdrawn = true;
            }
            if (!self::visibleTo($r, $tenant)) {
                continue;
            }
            if ($r['kind'] === 'plugin') {
                $plugins[] = $r;
                continue;
            }
            $newer = $current === '' || version_compare((string) $r['version'], $current, '>');
            $met = trim((string) ($r['requires_version'] ?? '')) === '' || ($current !== '' && version_compare($current, (string) $r['requires_version'], '>='));
            $newer && $met && $updates[] = $r;
            if (!$newer && $r['kind'] === 'full' && ($rollback === null || version_compare((string) $r['version'], (string) $rollback['version'], '>'))) {
                $rollback = $r;
            }
        }
        usort($updates, static fn ($a, $b) => version_compare((string) $b['version'], (string) $a['version']));
        return ['updates' => $updates, 'plugins' => $plugins, 'mandatory' => in_array(true, array_map(static fn ($r) => (bool) $r['mandatory'], $updates), true),
            'rollback' => $runningWithdrawn ? $rollback : null];
    }

    public static function events(array $where = [], int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }
        $w = [];
        $args = [];
        foreach ($where as $k => $v) {
            $w[] = $k . ' = ?';
            $args[] = $v;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_release_events' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit));
        $q->execute($args);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function countEvents(string $action, int $days = 30, ?string $releaseId = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $q = self::pdo()->prepare('SELECT COUNT(*) FROM ag_release_events WHERE action = ? AND created_at >= ?' . ($releaseId !== null ? ' AND release_id = ?' : ''));
        $q->execute(array_merge([$action, (new DateTimeImmutable(self::now()))->modify('-' . $days . ' days')->format('Y-m-d H:i:s')], $releaseId !== null ? [$releaseId] : []));
        return (int) $q->fetchColumn();
    }

    public static function event(?array $r, string $app, string $tenant, string $action, string $from = '', string $to = '', string $detail = '', ?string $licenceId = null): void
    {
        if (!self::ready()) {
            return;
        }
        self::pdo()->prepare('INSERT INTO ag_release_events (release_id, app, tenant_id, licence_id, action, from_version, to_version, detail, by_who, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['id'] ?? null, $app, $tenant === '' ? 0 : $tenant, $licenceId, mb_substr($action, 0, 30), $from ?: null, $to ?: ($r['version'] ?? null), mb_substr($detail, 0, 4000), AmetecsLicences::$actor, self::now()]);
    }

    // ------------------------------------------------------------------ the cloud server's own copy of each app

    public static function pluginRoot(string $app): ?string
    {
        $d = realpath(__DIR__ . '/../plugins/' . preg_replace('/[^a-z0-9]/', '', $app));
        return $d !== false && is_dir($d) ? $d : null;
    }

    /** The version this server runs: the app's config/version.json, else the last install recorded here. */
    public static function platformVersion(string $app): string
    {
        $root = self::pluginRoot($app);
        $v = $root === null ? null : json_decode((string) (@file_get_contents($root . '/config/version.json') ?: @file_get_contents($root . '/version.json')), true);   // ROVEXY keeps it at its root
        if (is_array($v) && trim((string) ($v['version'] ?? '')) !== '') {
            return trim((string) $v['version']);
        }
        foreach (self::events(['app' => $app, 'tenant_id' => 0, 'action' => 'installed'], 1) as $e) {
            return (string) $e['to_version'];
        }
        return '';
    }

    // ------------------------------------------------------------------ uploading

    /**
     * Check and store a package. $file = the uploaded temp file or a zip in incoming/. Returns the new draft or an error.
     *
     * @return array{ok: bool, why: string, release?: array}
     */
    public static function upload(string $app, string $kind, string $file, array $meta): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'why' => 'The update tables are not on this PC yet (the next sync adds them).'];
        }
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'why' => 'Choose full release, patch or plugin.'];
        }
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'why' => 'PHP zip extension is not enabled on this server.'];
        }
        $z = new \ZipArchive();
        if (!is_file($file) || $z->open($file) !== true) {
            return ['ok' => false, 'why' => 'That is not a readable zip file.'];
        }
        $m = json_decode((string) $z->getFromName('release.json'), true);
        $bad = null;
        for ($i = 0; $i < $z->numFiles && $bad === null; $i++) {
            $n = str_replace('\\', '/', (string) $z->getNameIndex($i));
            if (str_contains($n, '..') || str_starts_with($n, '/') || preg_match('/^[A-Za-z]:/', $n) === 1) {
                $bad = $n;
            }
        }
        $files = $z->numFiles;
        $z->close();
        $version = trim((string) ($meta['version'] ?? '')) ?: trim((string) ($m['version'] ?? ''));
        $product = strtolower(trim((string) ($m['product'] ?? '')));
        $err = match (true) {
            $bad !== null => 'The package holds an unsafe path (' . $bad . '). Refused.',
            !is_array($m) => 'release.json is missing at the top of the zip. Every package needs it: {"product": "' . $app . '", "version": "1.2.3", "notes": "..."}.',
            $version === '' || preg_match('/^\d+(\.\d+){1,3}([.-][A-Za-z0-9]+)?$/', $version) !== 1 => 'The version must look like 1.2.3.',
            trim((string) ($m['version'] ?? '')) !== '' && trim((string) $m['version']) !== $version => 'The package says version ' . $m['version'] . ' but ' . $version . ' was typed. They must match.',
            $product !== '' && !in_array($product, [$app, $app === 'smartprs3' ? 'smartprs' : $app], true) => 'This package is for "' . $m['product'] . '", not ' . $app . '.',
            $files < 2 => 'The package is empty.',
            default => null,
        };
        if ($err !== null) {
            return ['ok' => false, 'why' => $err];
        }
        // A plugin's code: typed, else release.json "plugin", else made from its title (Phase 6).
        $plugin = $kind !== 'plugin' ? '' : AmetecsPlugins::slug(trim((string) ($meta['plugin'] ?? '')) ?: (trim((string) ($m['plugin'] ?? '')) ?: (trim((string) ($meta['title'] ?? '')) ?: trim((string) ($m['title'] ?? 'module')))));
        $dup = self::pdo()->prepare('SELECT COUNT(*) FROM ag_releases WHERE app = ? AND version = ? AND kind = ?' . ($kind === 'plugin' ? ' AND plugin = ?' : ''));
        $dup->execute(array_merge([$app, $version, $kind], $kind === 'plugin' ? [$plugin] : []));
        if ((int) $dup->fetchColumn() > 0) {
            return ['ok' => false, 'why' => $app . ' ' . ($plugin !== '' ? $plugin . ' ' : '') . $version . ' (' . strtolower(self::KINDS[$kind]) . ') is already uploaded. Use a new version number.'];
        }
        $sha = (string) hash_file('sha256', $file);
        $name = $app . ($plugin !== '' ? '-' . $plugin : '') . '-' . $version . ($kind === 'full' ? '' : '-' . $kind) . '.zip';
        $dest = self::dir($app) . '/' . $name;
        $moved = is_uploaded_file($file) ? move_uploaded_file($file, $dest) : @copy($file, $dest);
        if (!$moved || hash_file('sha256', $dest) !== $sha) {
            @unlink($dest);
            return ['ok' => false, 'why' => 'The package could not be saved in core/data/releases. Nothing was added.'];
        }
        $sig = null;
        if (AmetecsLicences::canSign() && ($pk = openssl_pkey_get_private((string) file_get_contents(AmetecsLicences::privateKeyPath()))) !== false) {
            openssl_sign(strtolower($sha), $raw, $pk, OPENSSL_ALGO_SHA256);
            $sig = base64_encode((string) $raw);
        }
        $price = trim((string) ($meta['price'] ?? ''));
        $now = self::now();
        self::pdo()->prepare('INSERT INTO ag_releases (app, version, kind, plugin, title, notes, requires_version, min_php, file_name, size, sha256, signature, status, mandatory, downloadable,
            audience, price, currency, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, 1, \'all\', ?, \'INR\', ?, ?, ?)')
            ->execute([$app, $version, $kind, $plugin, mb_substr(trim((string) ($meta['title'] ?? '')), 0, 190) ?: null, trim((string) ($meta['notes'] ?? '')) ?: (trim((string) ($m['notes'] ?? '')) ?: null),
                trim((string) ($meta['requires_version'] ?? '')) ?: null, trim((string) ($meta['min_php'] ?? '')) ?: null, $name, (int) filesize($dest), $sha, $sig,
                !empty($meta['mandatory']) ? 1 : 0, is_numeric($price) && (float) $price > 0 ? number_format((float) $price, 2, '.', '') : null, AmetecsLicences::$actor, $now, $now]);
        $r = (array) self::byId((string) self::pdo()->lastInsertId());
        self::event($r, $app, '', 'uploaded', '', $version, self::KINDS[$kind] . ', ' . ($r['size'] >= 1048576 ? round($r['size'] / 1048576, 1) . ' MB' : max(1, (int) round($r['size'] / 1024)) . ' KB') . ', sha256 ' . substr($sha, 0, 16) . '…' . ($sig === null ? ', NOT signed (no signing key on this server)' : ', signed'));
        return ['ok' => true, 'why' => '', 'release' => $r];
    }

    public static function save(array $r, array $set, string $why): array
    {
        $allowed = ['title', 'notes', 'requires_version', 'min_php', 'status', 'publish_at', 'mandatory', 'downloadable', 'audience', 'price', 'currency'];
        $set = array_intersect_key($set, array_flip($allowed));
        $changed = array_filter($set, static fn ($v, $k) => (string) ($r[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return $r;
        }
        self::pdo()->prepare('UPDATE ag_releases SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($changed))) . ', updated_at = ? WHERE id = ?')
            ->execute(array_merge(array_values($changed), [self::now(), $r['id']]));
        self::event($r, (string) $r['app'], '', $why, '', '', implode(', ', array_map(static fn ($k, $v) => $k . ' ' . ((string) ($r[$k] ?? '') === '' ? '(empty)' : $r[$k]) . ' -> ' . ((string) ($v ?? '') === '' ? '(empty)' : $v),
            array_keys($changed), $changed)));
        return (array) self::byId((string) $r['id']);
    }

    /** Replace the list of clients a release goes to. @param array<string, bool> $tenants [tenant id => free] */
    public static function setAccess(array $r, array $tenants): void
    {
        $pdo = self::pdo();
        $old = self::access((string) $r['id']);
        $pdo->prepare('DELETE FROM ag_release_access WHERE release_id = ?')->execute([$r['id']]);
        $ins = $pdo->prepare('INSERT INTO ag_release_access (release_id, tenant_id, free, created_by, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($tenants as $tid => $free) {
            $ins->execute([$r['id'], $tid, $free ? 1 : 0, AmetecsLicences::$actor, self::now()]);
        }
        $old == $tenants || self::event($r, (string) $r['app'], '', 'audience changed', '', '', count($tenants) . ' client(s), ' . count(array_filter($tenants)) . ' free');
    }

    // ------------------------------------------------------------------ installing on Ametecs' own cloud server

    /** @return array{ok: bool, message: string, log: string} */
    public static function installOnPlatform(array $r): array
    {
        $app = (string) $r['app'];
        $root = self::pluginRoot($app);
        $zip = self::path($r);
        $from = self::platformVersion($app);
        $fail = static function (string $why, string $log = '') use ($r, $app, $from): array {
            self::event($r, $app, '', 'failed', $from, '', $why . ($log !== '' ? "\n" . $log : ''));
            return ['ok' => false, 'message' => $why, 'log' => $log];
        };
        if ($root === null) {
            return $fail($app . ' is not on this server yet (no plugins/' . $app . ' folder).');
        }
        if ((string) $r['kind'] === 'plugin' && !is_dir($root)) {
            return $fail('The app this plugin belongs to is not installed.');
        }
        if (!is_file($zip) || !hash_equals((string) $r['sha256'], (string) hash_file('sha256', $zip))) {
            return $fail('The stored package is missing or changed since upload (checksum mismatch). Nothing was installed.');
        }
        $req = trim((string) ($r['requires_version'] ?? ''));
        if ($req !== '' && $from !== '' && version_compare($from, $req, '<')) {
            return $fail('This needs ' . $app . ' ' . $req . ' or newer; this server runs ' . $from . '. Install that first.');
        }
        if (trim((string) ($r['min_php'] ?? '')) !== '' && version_compare(PHP_VERSION, (string) $r['min_php'], '<')) {
            return $fail('This needs PHP ' . $r['min_php'] . '; this server runs ' . PHP_VERSION . '.');
        }
        $signed = trim((string) ($r['signature'] ?? '')) !== '';
        if ($signed && !\SmartPRS\Surface\Updater::signatureValid((string) $r['sha256'], (string) $r['signature'])) {
            return $fail('The package signature is not valid. Nothing was installed.');
        }
        if (!$signed && self::production()) {
            return $fail('Unsigned packages are never installed on a production server. Upload it on the server that holds the signing key.');
        }
        $res = (new \SmartPRS\Surface\Updater($root))->install($zip, AmetecsLicences::$actor);
        if (!$res['ok']) {
            return $fail($res['message'], (string) $res['log']);
        }
        self::event($r, $app, '', 'installed', $res['from'] ?: $from, (string) $res['version'], trim($res['message'] . ($signed ? '' : ' (unsigned - test server)') . "\n" . $res['log']));
        return ['ok' => true, 'message' => $res['message'], 'log' => (string) $res['log']];
    }

    /** Put back the files the last install on this server replaced (its backup), and the version it replaced. */
    public static function rollbackPlatform(string $app): array
    {
        $root = self::pluginRoot($app);
        $hist = $root === null ? null : json_decode((string) @file_get_contents($root . '/storage/updates/history.json'), true);
        $last = is_array($hist) && $hist !== [] ? end($hist) : null;
        $backup = $last === null ? '' : $root . '/storage/updates/' . basename((string) $last['backup']);
        if ($last === null) {
            return ['ok' => false, 'message' => 'There is no install on this server to roll back.'];
        }
        if (!empty($last['rolled_back'])) {
            return ['ok' => false, 'message' => 'The last install (' . $last['to'] . ') was already rolled back.'];
        }
        // No backup file = that install replaced nothing (it only added files), so there is nothing to put back.
        if (is_file($backup)) {
            $z = new \ZipArchive();
            if ($z->open($backup) !== true || !$z->extractTo($root)) {
                return ['ok' => false, 'message' => 'The backup ' . basename($backup) . ' could not be restored. Nothing changed.'];
            }
            $z->close();
        }
        function_exists('opcache_reset') && @opcache_reset();
        $hist[array_key_last($hist)]['rolled_back'] = date('Y-m-d H:i:s');
        @file_put_contents($root . '/storage/updates/history.json', json_encode($hist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        // ponytail: files the update ADDED stay (harmless: nothing references them once the old files are back). Database changes are
        // not undone - migrations here only ever add, so the older code keeps working on the newer schema.
        $rq = self::pdo()->prepare("SELECT * FROM ag_releases WHERE app = ? AND version = ? AND kind <> 'plugin' LIMIT 1");
        $rq->execute([$app, (string) $last['to']]);
        self::event($rq->fetch(\PDO::FETCH_ASSOC) ?: null, $app, '', 'rolled_back', (string) $last['to'], (string) $last['from'], 'restored ' . basename($backup));
        return ['ok' => true, 'message' => 'Rolled back ' . $app . ' from ' . $last['to'] . ' to ' . ($last['from'] ?: 'the previous files') . '.'];
    }

    public static function production(): bool
    {
        return strtolower((string) (\SmartPRS\Surface\Database::env(AG_BASE . '/.env')['APP_ENV'] ?? 'production')) === 'production';
    }

    // ------------------------------------------------------------------ what an on-premise server asks (licence-api.php)

    /**
     * $licence = the caller's licence (already checked: key, bound server, active). Answers in the field names SmartPRS3's
     * updater reads (update_available, version, notes, size_bytes, package_hash, signature, downloadable).
     */
    public static function apiCheck(array $licence, string $current): array
    {
        $tid = (string) $licence['tenant_id'];
        $o = self::offerFor($tid, (string) $licence['app'], $current);
        $best = $o['updates'][0] ?? null;
        $base = ['ok' => true, 'current_version' => $current, 'mandatory' => $o['mandatory'],
            // Phase 6: one entry per plugin (its newest version), with whether this client holds it and has it switched on.
            'plugins' => array_values(array_map(static function ($p) use ($tid) {
                $v = AmetecsPlugins::view($tid, $p, true);
                return ['plugin' => $p['code'], 'version' => $p['latest']['version'], 'title' => $p['title'], 'notes' => $p['notes'], 'payment' => self::payment($p['latest'], $tid),
                    'state' => $v['state'], 'enabled' => $v['enabled'], 'installed_version' => $v['installed'], 'downloadable' => $v['canDownload'],
                    'package_hash' => $p['latest']['sha256'], 'signature' => $p['latest']['signature']];
            }, AmetecsPlugins::ready() ? AmetecsPlugins::catalogue((string) $licence['app'], $tid) : [])),
            'rollback' => $o['rollback'] === null ? null : ['version' => $o['rollback']['version'], 'package_hash' => $o['rollback']['sha256'], 'signature' => $o['rollback']['signature']]];
        if ($best === null) {
            return $base + ['update_available' => false, 'reason' => 'up_to_date', 'message' => 'You are on the latest version.'];
        }
        $pay = self::payment($best, $tid);
        return $base + ['update_available' => true, 'product' => $licence['app'], 'version' => $best['version'], 'kind' => $best['kind'], 'title' => $best['title'],
            'notes' => (string) ($best['notes'] ?? ''), 'size_bytes' => (int) $best['size'], 'package_hash' => $best['sha256'], 'signature' => $best['signature'],
            'requires_version' => $best['requires_version'], 'payment' => $pay, 'price' => $best['price'], 'currency' => $best['currency'],
            'downloadable' => $pay !== 'required', 'message' => $pay === 'required' ? 'This upgrade is paid. Complete the payment in your Ametecs Global panel first.' : 'Update ' . $best['version'] . ' is available.'];
    }

    /** The zip for $version if this licence may have it, else an error. @return array{ok: bool, why: string, release?: array, path?: string} */
    public static function apiDownload(array $licence, string $version, string $current = '', string $plugin = ''): array
    {
        $tid = (string) $licence['tenant_id'];
        foreach (self::all((string) $licence['app']) as $r) {
            if ((string) $r['version'] !== $version || ($r['kind'] === 'plugin') !== ($plugin !== '') || !self::visibleTo($r, $tid)) {
                continue;
            }
            if ($plugin !== '') {   // a plugin: its own code, held by this client, downloads allowed (Phase 6)
                if (AmetecsPlugins::codeOf($r) !== $plugin) {
                    continue;
                }
                $pv = AmetecsPlugins::view($tid, AmetecsPlugins::catalogue((string) $r['app'], $tid)[$plugin] ?? ['latest' => $r, 'app' => $r['app'], 'code' => $plugin], true);
                if (!AmetecsPlugins::authorised($pv['state'])) {
                    return ['ok' => false, 'why' => $pv['state'] === 'blocked' ? 'This plugin has been disabled for your company by Ametecs.' : 'Buy this plugin in your Ametecs Global panel first.'];
                }
                if (AmetecsPlugins::row($tid, (string) $r['app'], $plugin) !== null && empty(AmetecsPlugins::row($tid, (string) $r['app'], $plugin)['download'])) {
                    return ['ok' => false, 'why' => 'Downloading this plugin is turned off for your company. Contact Ametecs.'];
                }
            }
            if (self::payment($r, $tid) === 'required') {
                return ['ok' => false, 'why' => 'Payment for this upgrade is pending. Pay in your Ametecs Global panel, then try again.'];
            }
            if (!is_file(self::path($r))) {
                return ['ok' => false, 'why' => 'The package is not on the update server. Contact Ametecs.'];
            }
            self::event($r, (string) $r['app'], $tid, 'downloaded', $current, $version, 'by the installed server' . ($plugin !== '' ? ', plugin ' . $plugin : ''), (string) $licence['id']);
            return ['ok' => true, 'why' => '', 'release' => $r, 'path' => self::path($r)];
        }
        return ['ok' => false, 'why' => 'That version is not available to this licence.'];
    }

    public static function apiReport(array $licence, string $version, string $action, string $detail, string $from = '', string $plugin = ''): void
    {
        $act = in_array($action, ['applied', 'installed'], true) ? 'installed' : ($action === 'rolled_back' ? 'rolled_back' : 'failed');
        $rq = self::pdo()->prepare('SELECT * FROM ag_releases WHERE app = ? AND version = ? AND ' . ($plugin !== '' ? "kind = 'plugin' AND plugin = ?" : "kind <> 'plugin'") . ' LIMIT 1');
        $rq->execute(array_merge([$licence['app'], $version], $plugin !== '' ? [$plugin] : []));
        $rel = $rq->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($plugin !== '') {   // a plugin's install moves only that plugin's installed version, never the app's
            $act === 'installed' && $rel !== null && AmetecsPlugins::put((string) $licence['tenant_id'], $rel, ['installed_version' => mb_substr($version, 0, 39)], 'plugin installed');
            $act !== 'installed' && self::event($rel, (string) $licence['app'], (string) $licence['tenant_id'], $act, $from, $version, 'plugin ' . $plugin . ': ' . mb_substr($detail, 0, 2000), (string) $licence['id']);
            return;
        }
        self::event($rel, (string) $licence['app'], (string) $licence['tenant_id'], $act, $from, $version, mb_substr($detail, 0, 2000), (string) $licence['id']);
        $act === 'installed' && AmetecsLicences::put((string) $licence['tenant_id'], (string) $licence['app'], ['app_version' => mb_substr($version, 0, 39)], 'Reported a new version');
    }
}

<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * ON-PREMISE LICENCE, CLIENT SIDE — SmartPRS 2's LicenseFile + ClientUpdateController
 * licence half, rebuilt for SmartPRS 3 with its OWN key pair (owner, 16 Oct 2026:
 * a SmartPRS 2 .lic must not unlock SmartPRS 3).
 *
 * Two ways in, exactly as SmartPRS 2:
 *   1. ONLINE  — a licence key (SPRS-XXXX-XXXX-XXXX-XXXX) is sent with this machine's
 *               fingerprint to the licence server (SMARTPRS_LICENCE_SERVER/update/activate),
 *               which binds the key to this machine and returns a certificate.
 *   2. OFFLINE — a signed .lic token (base64url(payload).base64url(RSA-SHA256 sig)),
 *               node-locked to this machine's fingerprint. Only when SMARTPRS_OFFLINE_LIC=true;
 *               otherwise the key inside the .lic is activated online.
 *
 * Everything is OFF unless SMARTPRS_DEPLOYMENT=onprem and SMARTPRS_LICENCE_ENFORCE is not
 * false — the SaaS (app.smartprs.com) and local development never see a gate.
 *
 * State lives in storage/licence/ (a file, not the settings table: settings rows carry a
 * tenant FK and the licence belongs to the install, not to a tenant).
 */
final class Licence
{
    /**
     * SmartPRS 3 licence PUBLIC key. Its PRIVATE half lives only on the licence server
     * (storage/keys/license_private.pem on app.smartprs.com) and is never shipped.
     * Rotating it invalidates every .lic already issued — see tools/licence-keys.php.
     */
    public const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6oxgydekx6uzec4n5yEi
StMeKUHd4keUjofG6inBBvHCfqCvIqjzTRf/I5o+JgqHN6rn0OWld1L0qWVqzdvs
zYCiztGA53kW+VAjqpFkw9OuY5zBr9M/OlxwuNxtudnlkGdPxbcIplxAK/83XSUg
bPZQlUVZjnTyykh7wWRIeWJXAGbzk+pRjttlCeZ53NaR6ipCVYhXKuosDjqOdiui
lC6iBZxErVN9th2nKSMpDZwfialrxN4aKffCpWSZ73IrrD33McGpwzTcYrK7ZqGe
ln7nFWHI0QOvxFvHfNJH256BB4Gp+91uF/mV0szgNlP/bevC/TrduOT6gmxnfzEM
9QIDAQAB
-----END PUBLIC KEY-----
PEM;

    public const CONTACT = 'Ametecs (ejaz@ametecsindia.com · WhatsApp 9000098877)';

    /** Employee statuses that do not take a seat (SmartPRS 2 SubscriptionService). */
    public const SEAT_FREE = ['exited', 'inactive', 'resigned', 'terminated', 'left', 'absconded', 'old data'];

    private readonly string $root;
    /** @var array<string, string> */
    private readonly array $env;
    /** Why the last call to the licence server failed ('' = it did not). */
    private string $lastError = '';

    /**
     * Windows PHP ships no list of trusted certificates, so every HTTPS call failed with "SSL certificate problem"
     * until someone edited php.ini (16 Nov 2026 install). The install carries Mozilla's list (config/cacert.pem)
     * and uses it whenever php.ini names none.
     *
     * @return array<int, string>
     */
    private function caOption(): array
    {
        $bundle = $this->root . '/config/cacert.pem';

        return ini_get('curl.cainfo') === '' || ini_get('curl.cainfo') === false ? (is_readable($bundle) ? [CURLOPT_CAINFO => $bundle] : []) : [];
    }

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__);
        $this->env = Database::env($this->root . '/.env');
    }

    // ------------------------------------------------------------------ switches

    public function env(string $key, string $default = ''): string
    {
        $v = getenv($key);

        return $v !== false && $v !== '' ? (string) $v : (string) ($this->env[$key] ?? $default);
    }

    public function isOnPrem(): bool
    {
        return strtolower($this->env('SMARTPRS_DEPLOYMENT', 'saas')) === 'onprem';
    }

    public function enforced(): bool
    {
        return $this->isOnPrem()
            && filter_var($this->env('SMARTPRS_LICENCE_ENFORCE', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public function server(): string
    {
        return rtrim($this->env('SMARTPRS_LICENCE_SERVER', 'https://smartprs.com'), '/'); // central moved to smartprs.com (12 Nov 2026)
    }

    private function dir(): string
    {
        $d = $this->root . '/storage/licence';

        if (!is_dir($d)) {
            @mkdir($d, 0770, true);
        }

        return $d;
    }

    // --------------------------------------------------------------- fingerprint

    /** Stable, hard-to-move machine id (SMBIOS / OS UUID, hashed), cached once per install. */
    public function fingerprint(): string
    {
        $cache = $this->dir() . '/.machine_fp';
        $c = is_readable($cache) ? trim((string) file_get_contents($cache)) : '';

        if (strlen($c) === 40 && ctype_xdigit($c)) {
            return $c;
        }

        $fp = substr(hash('sha256', 'SMARTPRS3|' . self::rawMachineId()), 0, 40);
        @file_put_contents($cache, $fp);

        return $fp;
    }

    private static function rawMachineId(): string
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $out = (string) @shell_exec('wmic csproduct get uuid 2>NUL');

            if (preg_match('/[0-9A-F]{8}-?[0-9A-F]{4}-?[0-9A-F]{4}-?[0-9A-F]{4}-?[0-9A-F]{12}/i', $out, $m) === 1) {
                return strtoupper($m[0]);
            }

            $out = trim((string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID" 2>NUL'));

            if ($out !== '') {
                return strtoupper($out);
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
                if (is_readable($f)) {
                    return trim((string) file_get_contents($f));
                }
            }
        }

        // Weak fallback, never empty: keeps the app usable where the OS hides its UUID.
        return php_uname('n') . '|' . php_uname('s') . '|' . php_uname('m');
    }

    // --------------------------------------------------------------------- state

    /** @return array<string, mixed> */
    public function state(): array
    {
        $f = $this->dir() . '/state.json';
        $s = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($s) ? $s : [];
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        file_put_contents($this->dir() . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * none | ok | expired, with expiry, company, mode, seats.
     *
     * @return array{state: string, expires_on: ?string, company: string, mode: string, seats: ?int, source: string, reason: string}
     */
    public function status(): array
    {
        $st = $this->state();
        $cert = (array) ($st['cert'] ?? []);
        $out = ['state' => 'none', 'expires_on' => null, 'company' => (string) ($st['company'] ?? ''),
            'mode' => (($cert['expiry_mode'] ?? 'renew') === 'notify') ? 'notify' : 'renew',
            'seats' => ((int) ($cert['seats'] ?? 0)) > 0 ? (int) $cert['seats'] : null,
            'source' => (string) ($cert['source'] ?? ''), 'reason' => (string) ($st['block_reason'] ?? '')];

        if ($cert === [] || empty($st['activated_at'])) {
            return $out;
        }

        // A .lic is re-verified on every check: deleting or swapping the file, or moving
        // the install to another PC, ends the licence there and then.
        if (($cert['source'] ?? '') === 'file') {
            $v = $this->verifyToken((string) @file_get_contents($this->dir() . '/license.lic'));

            if (!$v['ok']) {
                return ['reason' => (string) $v['reason']] + $out;
            }

            $cert['amc_expires_on'] = $v['payload']['expires_at'] ?? null;
        }

        $exp = isset($cert['amc_expires_on']) && $cert['amc_expires_on'] !== '' ? (string) $cert['amc_expires_on'] : null;
        $out['expires_on'] = $exp;
        $out['state'] = $exp !== null && $exp < date('Y-m-d') ? 'expired' : 'ok';

        // SUSPENDED by Ametecs (owner, 16 Nov 2026): stops the install like an expired licence, but nothing is
        // forgotten — the key and certificate stay, and the next check-in after Resume lets it run again.
        if (($st['block_reason'] ?? '') === 'suspended') {
            $out['state'] = 'suspended';
        }

        return $out;
    }

    /**
     * Is the app allowed to run? Always yes off on-prem. An expired licence stops it in BOTH modes, as SmartPRS 2's
     * LicenseGate does (owner, 13 Nov 2026: "exactly as SmartPRS2") — notify only changes the message shown.
     */
    public function valid(): bool
    {
        if (!$this->enforced()) {
            return true;
        }

        $s = $this->status();

        return $s['state'] === 'ok';
    }

    /** The employee cap carried by the licence; null = unlimited or not on-prem. */
    public function seatLimit(): ?int
    {
        return $this->enforced() ? $this->status()['seats'] : null;
    }

    // ----------------------------------------------------------------- activation

    /** @return array{ok: bool, error: ?string, company?: string} */
    public function activate(string $input, int $activeEmployees = 0): array
    {
        $input = trim($input);

        if (self::looksLikeLicenceFile($input)) {
            if (filter_var($this->env('SMARTPRS_OFFLINE_LIC', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                return $this->activateFile($input, $activeEmployees);
            }

            $input = self::keyInsideToken($input);

            if ($input === '') {
                return ['ok' => false, 'error' => 'This PC must be connected to the Internet to activate. Offline licence files are disabled — connect to the Internet and try again.'];
            }
        }

        return $this->activateKey($input);
    }

    /** @return array{ok: bool, error: ?string, company?: string} */
    public function activateKey(string $key): array
    {
        if (strlen(LicenceAuthority::normalize($key)) < 16) {
            return ['ok' => false, 'error' => 'That does not look like a SmartPRS License Code — please check and try again.'];
        }

        $j = $this->post('/update/activate', ['key' => $key, 'fingerprint' => $this->fingerprint(),
            'server_name' => gethostname() ?: 'unknown'], 20);

        if ($j === null || empty($j['ok'])) {
            return ['ok' => false, 'error' => (string) ($j['error'] ?? ('Could not reach the licence server ' . $this->server() . ($this->lastError !== '' ? ' (' . $this->lastError . ')' : '')
                . ' — check this server\'s internet connection and try again.'))];
        }

        $exp = $j['cert']['amc_expires_on'] ?? null;

        if (is_string($exp) && $exp !== '' && $exp < date('Y-m-d')) {
            return ['ok' => false, 'error' => 'That License Code expired on ' . $exp . '. Please obtain a current code from ' . self::CONTACT . '.'];
        }

        $old = $this->state();
        $this->save(['key_enc' => Secret::encrypt(LicenceAuthority::normalize($key), $this->root . '/.env'),
            'cert' => (array) ($j['cert'] ?? []) + ['source' => 'online'], 'company' => (string) ($j['company'] ?? ''),
            'activated_at' => date('Y-m-d H:i:s'), 'last_ok' => date('Y-m-d H:i:s'),
            'revoked_hashes' => $old['revoked_hashes'] ?? []]);

        return ['ok' => true, 'error' => null, 'company' => (string) ($j['company'] ?? '')];
    }

    /** @return array{ok: bool, error: ?string, company?: string} */
    public function activateFile(string $token, int $activeEmployees = 0): array
    {
        $v = $this->verifyToken($token);

        if (!$v['ok']) {
            $map = [
                'wrong_machine' => 'This licence file is locked to a different computer and cannot be used on this server. Ask ' . self::CONTACT . ' to re-issue it for this machine.',
                'not_locked' => 'This licence file is not tied to a computer, so it cannot be used (one licence works on one machine only). Send this server\'s fingerprint from the Activation screen to ' . self::CONTACT . '.',
                'invalid_signature' => 'This licence file is invalid or has been tampered with. Please use the exact .lic file Ametecs sent you.',
                'bad_public_key' => 'This SmartPRS build cannot read its licence key. Please contact ' . self::CONTACT . '.',
            ];

            return ['ok' => false, 'error' => $map[$v['reason']] ?? 'This does not look like a SmartPRS licence file. Paste the whole code or upload the .lic file Ametecs sent you.'];
        }

        $p = $v['payload'];
        $exp = isset($p['expires_at']) && $p['expires_at'] !== '' ? (string) $p['expires_at'] : null;

        if ($exp !== null && $exp < date('Y-m-d')) {
            return ['ok' => false, 'error' => 'This licence expired on ' . $exp . '. Please obtain a current licence file from ' . self::CONTACT . '.'];
        }

        $old = $this->state();

        if (in_array(LicenceAuthority::hashKey((string) ($p['key'] ?? $token)), (array) ($old['revoked_hashes'] ?? []), true)) {
            return ['ok' => false, 'error' => 'This licence has been revoked. Please contact ' . self::CONTACT . '.'];
        }

        $seats = (int) ($p['device_limit'] ?? 0);

        if ($seats > 0 && $activeEmployees > $seats) {
            return ['ok' => false, 'error' => 'This licence covers ' . $seats . ' employees, but this installation currently has ' . $activeEmployees . ' active. Obtain a licence for your team size, or move surplus employees to Inactive, then activate.'];
        }

        file_put_contents($this->dir() . '/license.lic', trim($token) . "\n", LOCK_EX);
        $this->save(['key_enc' => Secret::encrypt((string) ($p['key'] ?? ''), $this->root . '/.env'),
            'cert' => ['amc_expires_on' => $exp, 'expiry_mode' => 'renew', 'fingerprint' => $this->fingerprint(),
                'seats' => $seats ?: null, 'source' => 'file', 'issued_at' => $p['issued_at'] ?? null],
            'company' => (string) ($p['company'] ?? ''), 'activated_at' => date('Y-m-d H:i:s'),
            'last_ok' => date('Y-m-d H:i:s'), 'revoked_hashes' => $old['revoked_hashes'] ?? []]);

        return ['ok' => true, 'error' => null, 'company' => (string) ($p['company'] ?? '')];
    }

    /**
     * Ask the licence server whether this key was revoked, suspended or moved, and pick up a password reset
     * Ametecs set (SmartPRS 2 revocationSweep). At most once per $every seconds, also when the server cannot be
     * reached — an offline install is never slowed down on every page. Unreachable = offline grace: nothing changes.
     * Hourly from the app, every 5 minutes from tools/cron.php, every minute while signed out or suspended
     * (owner, 16 Nov 2026: Suspend, Resume and a password reset must reach the client quickly).
     */
    public function sweep(bool $force = false, int $every = 3600): void
    {
        if (!$this->isOnPrem()) {
            return;
        }

        $st = $this->state();

        if (empty($st['cert']) || empty($st['key_enc'])) {
            return;
        }

        if (($st['block_reason'] ?? '') === 'suspended') {
            $every = min($every, 60);
        }

        $last = max((int) strtotime((string) ($st['last_revcheck'] ?? '')), (int) ($st['last_try'] ?? 0));

        if (!$force && $last > time() - $every) {
            return;
        }

        $st['last_try'] = time();
        $this->save($st);

        $key = (string) Secret::decrypt((string) $st['key_enc'], $this->root . '/.env');

        if ($key === '') {
            return;
        }

        $j = $this->post('/update/heartbeat', ['key' => $key, 'fingerprint' => $this->fingerprint(),
            'server_name' => gethostname() ?: 'unknown'] + $this->dashboardFigures(), 6);

        if ($j === null) {
            return;
        }

        $st['last_revcheck'] = date('Y-m-d H:i:s');
        $reason = strtolower((string) ($j['reason'] ?? ''));
        $status = !empty($j['ok']) ? 'active' : ($reason === 'server_mismatch' ? 'server_mismatch' : (str_starts_with($reason, 'licence_') ? substr($reason, 8) : ''));

        if ($status === 'suspended') {
            $st['block_reason'] = 'suspended';
        } elseif (in_array($status, ['revoked', 'server_mismatch'], true)) {
            if ($status !== 'server_mismatch') {
                $st['revoked_hashes'] = array_values(array_unique(array_merge((array) ($st['revoked_hashes'] ?? []), [LicenceAuthority::hashKey($key)])));
            }

            $st['cert'] = [];
            $st['block_reason'] = $status;
        } elseif (!empty($j['ok'])) {
            $st['last_ok'] = date('Y-m-d H:i:s');
            unset($st['block_reason']);

            // A PASSWORD RESET set by Ametecs in Super Admin (owner, 16 Nov 2026): only the hash travels; boot-hooks
            // applies it to this install's login on the next page and reports back (resetDone).
            if (is_array($j['password_reset'] ?? null) && ($j['password_reset']['email'] ?? '') !== '' && ($j['password_reset']['hash'] ?? '') !== '') {
                $st['pending_reset'] = ['id' => (string) ($j['password_reset']['id'] ?? ''), 'email' => (string) $j['password_reset']['email'],
                    'hash' => (string) $j['password_reset']['hash']];
            }

            // A renewal on the server reaches an online install here, without re-entering the key.
            if (($st['cert']['source'] ?? '') === 'online' && !empty($j['amc_expires_on'])) {
                $st['cert']['amc_expires_on'] = (string) $j['amc_expires_on'];
                $st['cert']['expiry_mode'] = (string) ($j['expiry_mode'] ?? 'renew');
                $st['cert']['seats'] = isset($j['seats']) ? (int) $j['seats'] : ($st['cert']['seats'] ?? null);
            }
        }

        $this->save($st);
    }

    /**
     * THIS INSTALL'S DASHBOARD FIGURES for the client's AmetecsGlobal panel (owner, 18 Nov 2026): ag-stats.php, run for the
     * install's company and sent with the hourly check-in. Best effort - a failure here never touches the licence.
     *
     * @return array<string, string>
     */
    private function dashboardFigures(): array
    {
        try {
            $pdo = is_file($this->root . '/ag-stats.php') ? Database::connect($this->root . '/.env') : null;
            $tid = $pdo === null ? null : $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
            return $tid ? ['stats' => (string) json_encode((require $this->root . '/ag-stats.php')($pdo, (string) $tid))] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** The password reset Ametecs sent, taken once (boot-hooks applies it). @return array{id: string, email: string, hash: string}|null */
    public function takePendingReset(): ?array
    {
        $st = $this->state();
        $r = $st['pending_reset'] ?? null;

        if (!is_array($r)) {
            return null;
        }

        unset($st['pending_reset']);
        $this->save($st);

        return $r;
    }

    /** Tell the licence server how many logins the reset changed (0 = no login with that email here). */
    public function resetDone(string $id, int $applied): void
    {
        $key = $this->key();
        $key === '' || $this->post('/update/reset-done', ['key' => $key, 'fingerprint' => $this->fingerprint(), 'id' => $id, 'applied' => (string) $applied], 10);
    }

    // ------------------------------------------------------------------ .lic token

    /** @return array{ok: bool, reason: ?string, payload?: array<string, mixed>} */
    public function verifyToken(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['ok' => false, 'reason' => 'no_file'];
        }

        $parts = explode('.', $raw);

        if (count($parts) !== 2) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$b64, $sig64] = $parts;
        $json = self::b64urlDecode($b64);
        $sig = self::b64urlDecode($sig64);

        if ($json === false || $sig === false) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $pub = openssl_pkey_get_public(self::PUBLIC_KEY);

        if ($pub === false) {
            return ['ok' => false, 'reason' => 'bad_public_key'];
        }

        if (openssl_verify($b64, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        $p = json_decode($json, true);

        if (!is_array($p) || empty($p['key'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $fp = (string) ($p['fingerprint'] ?? '');

        if ($fp === '') {
            return ['ok' => false, 'reason' => 'not_locked', 'payload' => $p];
        }

        if (!hash_equals($fp, $this->fingerprint())) {
            return ['ok' => false, 'reason' => 'wrong_machine', 'payload' => $p];
        }

        return ['ok' => true, 'reason' => null, 'payload' => $p];
    }

    public static function looksLikeLicenceFile(string $s): bool
    {
        $t = trim($s);

        if ($t === '' || stripos($t, 'SPRS-') === 0) {
            return false;
        }

        $parts = explode('.', $t);

        return count($parts) === 2 && strlen($parts[0]) >= 40
            && preg_match('/^[A-Za-z0-9_-]+$/', $parts[0]) === 1 && preg_match('/^[A-Za-z0-9_-]+$/', $parts[1]) === 1;
    }

    public static function keyInsideToken(string $token): string
    {
        $json = self::b64urlDecode(explode('.', trim($token))[0] ?? '');
        $p = $json === false ? null : json_decode($json, true);

        return is_array($p) ? trim((string) ($p['key'] ?? '')) : '';
    }

    private static function b64urlDecode(string $s): string|false
    {
        $s = strtr($s, '-_', '+/');

        return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    // ------------------------------------------------------------ updates (12 Nov 2026)

    /** This install's licence key, or '' (offline .lic installs have none to send). */
    private function key(): string
    {
        $st = $this->state();

        return empty($st['key_enc']) ? '' : (string) Secret::decrypt((string) $st['key_enc'], $this->root . '/.env');
    }

    /**
     * Ask central whether a newer release has been sent to this licence.
     *
     * @return array<string, mixed> central's answer, or ['ok' => false, 'message' => …]
     */
    public function checkUpdate(string $currentVersion): array
    {
        $key = $this->key();

        if ($key === '') {
            return ['ok' => false, 'update_available' => false, 'message' => 'This installation has no online licence key, so it cannot check for updates. Activate online first, or ask Ametecs for the package.'];
        }

        return $this->post('/update/check', ['key' => $key, 'current_version' => $currentVersion, 'fingerprint' => $this->fingerprint()], 20)
            ?? ['ok' => false, 'update_available' => false, 'message' => 'The update server (' . $this->server() . ') could not be reached. Check this server\'s internet connection.'];
    }

    /** Download a granted release to $dest. Returns '' on success, else the reason. */
    public function downloadUpdate(string $version, string $dest): string
    {
        $key = $this->key();
        $fh = $key === '' ? false : @fopen($dest, 'wb');

        if ($fh === false) {
            return $key === '' ? 'No online licence key.' : 'Cannot write ' . $dest . '.';
        }

        $ch = curl_init($this->server() . '/update/download');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['key' => $key, 'version' => $version, 'fingerprint' => $this->fingerprint()]),
            CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true] + $this->caOption());
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $code !== 200 || !str_contains($type, 'zip')) {
            $j = json_decode((string) @file_get_contents($dest), true);
            @unlink($dest);

            return is_array($j) && isset($j['error']) ? (string) $j['error'] : 'Download failed (HTTP ' . $code . ').';
        }

        return '';
    }

    /** Tell central what happened (applied / failed). Best effort. */
    public function reportUpdate(string $version, string $action, string $detail): void
    {
        $key = $this->key();
        $key === '' || $this->post('/update/report', ['key' => $key, 'version' => $version, 'action' => $action, 'detail' => $detail, 'fingerprint' => $this->fingerprint()], 10);
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>|null null = the server could not be reached
     */
    private function post(string $path, array $body, int $timeout): ?array
    {
        $ch = curl_init($this->server() . $path);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => ['Accept: application/json']] + $this->caOption());
        $resp = curl_exec($ch);
        $this->lastError = $resp === false ? curl_error($ch) : ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 500 ? 'HTTP ' . (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : '');
        curl_close($ch);
        $j = is_string($resp) ? json_decode($resp, true) : null;

        return is_array($j) ? $j : null;
    }
}

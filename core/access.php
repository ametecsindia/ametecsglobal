<?php
/**
 * AMETECSGLOBAL — WHICH APPS EACH CLIENT MAY USE (set by Super Admin in core/super.php).
 *
 * One file, core/data/access.json:  { "<tenant id>": { "<plugin code>": {"on": true, "seats": 50, "until": "2027-03-31"} } }
 *   on     — Super Admin switched the app on for this client
 *   seats  — how many the client may use (employees for SmartPRS3); 0/empty = no AmetecsGlobal cap
 *   until  — last valid day; after it the app is "expired" and will not open
 * ponytail: a JSON file, one server; move to a table when AmetecsGlobal runs on more than one server.
 * This folder is AmetecsGlobal's own: SYNC.bat never copies over it.
 */
declare(strict_types=1);

/**
 * THE BASE PLUGIN: clients (tenants), their logins and the database live in SmartPRS3's tables, so the core
 * borrows SmartPRS3's classes and its .env. Every plugin has its own folder under plugins/.
 */
const AG_BASE = __DIR__ . '/../plugins/smartprs3';

spl_autoload_register(static function (string $class): void {
    foreach (['SmartPRS\\Platform\\' => '/src/Platform/', 'SmartPRS\\Surface\\' => '/surface/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $f = AG_BASE . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($f)) {
                require_once $f;
            }
            return;
        }
    }
});

final class AmetecsAccess
{
    public const FILE = __DIR__ . '/data/access.json';

    /** The apps AmetecsGlobal starts with. Super Admin → Apps edits these and adds more (core/data/apps.json). */
    public const STARTER_APPS = [
        'smartprs3' => ['name' => 'SmartPRS 3', 'what' => 'HRM · Payroll · Attendance · Compliance', 'unit' => 'employees', 'open' => '../plugins/smartprs3/public/app.php', 'color' => '#F97316'],
        'smartept'  => ['name' => 'SmartEPT', 'what' => 'Employee productivity tracking', 'unit' => 'users', 'open' => null, 'color' => '#2563EB'],
        'smartdcm'  => ['name' => 'SmartDCM', 'what' => 'Debt collection management', 'unit' => 'agents', 'open' => null, 'color' => '#0F766E'],
        'leadger'   => ['name' => 'LeadGER', 'what' => 'Lead management', 'unit' => 'users', 'open' => null, 'color' => '#7C3AED'],
        'rovexy'    => ['name' => 'ROVEXY', 'what' => '', 'unit' => 'users', 'open' => null, 'color' => '#DB2777'],
        'qronex'    => ['name' => 'QRONEX', 'what' => 'QR attendance', 'unit' => 'employees', 'open' => null, 'color' => '#CA8A04'],
    ];

    /**
     * The app catalogue: starter apps, then everything Super Admin added or changed, in the order saved.
     * 'open' = where the app lives (null = not on AmetecsGlobal yet). Retired apps are left out unless asked for:
     * clients stop seeing them, but their licences and data stay.
     */
    public static function plugins(bool $withRetired = false): array
    {
        $f = __DIR__ . '/data/apps.json';
        $saved = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
        $apps = self::STARTER_APPS;
        foreach (is_array($saved) ? $saved : [] as $code => $app) {
            $apps[(string) $code] = (array) $app + ($apps[(string) $code] ?? []) + ['what' => '', 'unit' => 'users', 'open' => null, 'color' => '#64748B'];
        }
        return $withRetired ? $apps : array_filter($apps, static fn (array $a): bool => empty($a['retired']));
    }

    public static function saveApp(string $code, array $app): void
    {
        $f = __DIR__ . '/data/apps.json';
        $saved = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $saved[$code] = $app;
        if (!is_dir(dirname($f))) {
            mkdir(dirname($f), 0775, true);
        }
        file_put_contents($f, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function all(): array
    {
        $j = is_file(self::FILE) ? json_decode((string) file_get_contents(self::FILE), true) : [];
        return is_array($j) ? $j : [];
    }

    public static function save(array $all): void
    {
        if (!is_dir(dirname(self::FILE))) {
            mkdir(dirname(self::FILE), 0775, true);
        }
        if (file_put_contents(self::FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . self::FILE);
        }
    }

    public static function get(string $tenant, string $plugin, ?array $all = null): array
    {
        return (array) (($all ?? self::all())[$tenant][$plugin] ?? []);
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    /** on | off | expired */
    public static function state(string $tenant, string $plugin, ?array $all = null): string
    {
        $a = self::get($tenant, $plugin, $all);
        if ($tenant === '' || empty($a['on'])) {
            return 'off';
        }
        $until = (string) ($a['until'] ?? '');
        return $until !== '' && $until < self::today() ? 'expired' : 'on';
    }

    /** The seat cap for an app that is on, or null when there is none. */
    public static function seats(string $tenant, string $plugin): ?int
    {
        $s = (int) (self::get($tenant, $plugin)['seats'] ?? 0);
        return self::state($tenant, $plugin) === 'on' && $s > 0 ? $s : null;
    }

    /** Seats in use for SmartPRS3: live employees not marked as leavers — SmartPRS3's own rule. */
    public static function employeesUsed(\SmartPRS\Surface\SqlStore $platform, string $tenant): int
    {
        try {
            return count(array_filter($platform->forTenant($tenant)->all('employees'), static fn (array $e): bool
                => !\SmartPRS\Surface\Modules::isRetired($e)
                && !in_array(strtolower(trim((string) ($e['status'] ?? ''))), \SmartPRS\Surface\Licence::SEAT_FREE, true)));
        } catch (\Throwable) {
            return 0;
        }
    }
}

/** AmetecsGlobal's own records: client contact details and the activity log (core/data/). */
final class AmetecsData
{
    public static function clients(): array
    {
        $f = __DIR__ . '/data/clients.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
        return is_array($j) ? $j : [];
    }

    public static function saveClient(string $tid, array $meta): void
    {
        $all = self::clients();
        $all[$tid] = $meta + ($all[$tid] ?? []);
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0775, true);
        }
        file_put_contents(__DIR__ . '/data/clients.json', json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** One line per change: who, when, what, which client. Never edited, only appended. */
    public static function log(string $who, string $what, string $tenant = '', string $detail = ''): void
    {
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0775, true);
        }
        file_put_contents(__DIR__ . '/data/activity.jsonl', json_encode(['at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s'),
            'who' => $who, 'what' => $what, 'tenant' => $tenant, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Newest first. ponytail: reads the whole file; rotate it when it passes a few MB. */
    public static function activity(string $tenant = '', int $limit = 300): array
    {
        $f = __DIR__ . '/data/activity.jsonl';
        $rows = [];
        foreach (is_file($f) ? array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && ($tenant === '' || (string) ($r['tenant'] ?? '') === $tenant)) {
                $rows[] = $r;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return $rows;
    }
}

/**
 * AmetecsGlobal email: the platform mailbox (SMTP) saved under Super Admin -> Email settings, with AmetecsGlobal's
 * own look. The SMTP settings are the same record SmartPRS3's console uses, so there is one mailbox to set up.
 */
final class AmetecsMail
{
    public static function box(\PDO $pdo): \SmartPRS\Surface\PlatformMail
    {
        return new \SmartPRS\Surface\PlatformMail(new \SmartPRS\Surface\PlatformSettings(new \SmartPRS\Surface\SqlStore($pdo)), AG_BASE . '/.env');
    }

    /** @return array{ok: bool, why: string} */
    public static function send(\PDO $pdo, string $to, string $subject, string $text, string $button = '', string $url = ''): array
    {
        if (!self::box($pdo)->ready()) {
            return ['ok' => false, 'why' => 'Email is not set up yet (Super Admin -> Email settings).'];
        }
        $s = array_replace(\SmartPRS\Surface\PlatformMail::DEFAULTS, (new \SmartPRS\Surface\PlatformSettings(new \SmartPRS\Surface\SqlStore($pdo)))->get('smtp'));
        $s['password'] = (string) (\SmartPRS\Surface\Secret::decrypt((string) $s['password'], AG_BASE . '/.env') ?? '');
        $s['encryption'] === 'none' && $s['encryption'] = '';
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'
            . '<div style="background:#1F3A5F;color:#fff;padding:16px 22px;font-size:20px;font-weight:bold">Ametecs<span style="color:#B8893B">Global</span></div>'
            . '<div style="padding:22px;font-size:14px;line-height:1.6;color:#1e293b">' . nl2br($e($text))
            . ($url !== '' ? '<p style="margin:22px 0"><a href="' . $e($url) . '" style="background:#B8893B;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold">' . $e($button ?: 'Open') . '</a></p>'
                . '<p style="font-size:12px;color:#64748b">If the button does not work, copy this link into your browser:<br>' . $e($url) . '</p>' : '')
            . '</div><div style="padding:12px 22px;background:#f8fafc;color:#94a3b8;font-size:12px">AmetecsGlobal by Ametecs India</div></div>';
        try {
            return (new \SmartPRS\Surface\Smtp())->send($s, [$to], [], $subject, $text . ($url !== '' ? "\n\n" . $url : ''), $html);
        } catch (\Throwable $x) {
            return ['ok' => false, 'why' => $x->getMessage()];
        }
    }

    /** This AmetecsGlobal's own address, e.g. http://localhost/AmetecsGlobal/core/ */
    public static function base(): string
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/core/index.php'))), '/') . '/';
    }

    /** Issue a one-time reset link for a client login and email it. @return array{ok: bool, why: string} */
    public static function resetLink(\PDO $pdo, string $tenant, string $userId): array
    {
        $hours = max(1, (int) (json_decode((string) @file_get_contents(AG_BASE . '/config/password-reset.json'), true)['link_hours'] ?? 2));
        $users = new \SmartPRS\Surface\Users((new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant), new \SmartPRS\Surface\Authorisation(), \SmartPRS\Platform\Identity\PasswordHasher::platformDefault());
        if (!self::box($pdo)->ready()) {
            return ['ok' => false, 'why' => 'Email is not set up yet (Super Admin -> Email settings).'];
        }
        $inv = $users->invite($userId, $hours);
        if (!$inv['ok']) {
            return $inv;
        }
        return self::send($pdo, (string) $inv['user']['email'], 'Reset your AmetecsGlobal password',
            "Hello,\n\nUse the button below to set a new password for your AmetecsGlobal login (" . $inv['user']['email'] . ").\nThe link works once, for "
            . $hours . ' hour' . ($hours === 1 ? '' : 's') . ".\n\nIf you did not ask for this, ignore this email; your password stays as it is.",
            'Set a new password', self::base() . '?reset=' . rawurlencode($tenant . '.' . $inv['token']));
    }
}

function ag_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

<?php
/**
 * AMETECSGLOBAL PLUGINS (owner, 18 Nov 2026 - Phase 6): add-on modules for an app, bought or given, switched on and off.
 *
 * A plugin is the series of 'plugin' releases of one app that share a code (ag_releases.plugin), e.g. smartprs3/gst-returns
 * 1.0.0, 1.1.0. Its newest release visible to the client is "the" plugin: its notes, price and package.
 *
 * Per client (ag_plugin_access, created on first change):
 *   free       the newest release has no price - every client holding the app may use it
 *   granted    Super Admin gave it to this client without payment
 *   purchased  paid for: more 'paid' than 'refunded' events over ANY release of the plugin (so updates need no new payment)
 *   locked     none of the above: shown with its price and a Buy button
 *   blocked    Super Admin switched it off for this client (wins over everything)
 * free / granted / purchased = authorised: the client may switch it on or off (on unless switched off) and, on-premise,
 * download it when Super Admin allows downloads. Apps ask AmetecsPlugins::on() whether to show a plugin's features.
 */
declare(strict_types=1);

final class AmetecsPlugins
{
    public const STATES = ['free' => ['Free', 'green'], 'granted' => ['Given by Ametecs', 'green'], 'purchased' => ['Purchased', 'green'],
        'locked' => ['Not purchased', 'grey'], 'blocked' => ['Disabled by Ametecs', 'red']];

    private static ?bool $ready = null;
    private static array $rowCache = [];

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                $pdo = AmetecsLicences::pdo();
                self::$ready = $pdo !== null && AmetecsReleases::ready() && $pdo->query("SHOW TABLES LIKE 'ag_plugin_access'")->fetchColumn() !== false;
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function slug(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'), 0, 60) ?: 'module';
    }

    public static function codeOf(array $r): string
    {
        return (string) ($r['plugin'] ?? '') !== '' ? (string) $r['plugin'] : self::slug((string) ($r['title'] ?? ''));
    }

    /**
     * The plugins of $app: code => [code, title, notes, latest, versions]. With $tenant: only releases that client can
     * see (published, for everyone or for them); without: every release that is not withdrawn (Super Admin).
     */
    public static function catalogue(string $app, string $tenant = ''): array
    {
        $out = [];
        foreach (AmetecsReleases::all($app) as $r) {
            if ($r['kind'] !== 'plugin' || ($tenant !== '' ? !AmetecsReleases::visibleTo($r, $tenant) : $r['status'] === 'withdrawn')) {
                continue;
            }
            $c = self::codeOf($r);
            $out[$c] ??= ['code' => $c, 'app' => $app, 'latest' => $r, 'versions' => []];
            $out[$c]['versions'][] = $r;
            if (version_compare((string) $r['version'], (string) $out[$c]['latest']['version'], '>')) {
                $out[$c]['latest'] = $r;
            }
        }
        foreach ($out as &$p) {
            $p['title'] = (string) ($p['latest']['title'] ?: ucwords(str_replace('-', ' ', $p['code'])));
            $p['notes'] = (string) ($p['latest']['notes'] ?? '');
        }
        uasort($out, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));
        return $out;
    }

    public static function row(string $tenant, string $app, string $code): ?array
    {
        $k = $tenant . '|' . $app . '|' . $code;
        if (!array_key_exists($k, self::$rowCache)) {
            $q = AmetecsLicences::pdo()->prepare('SELECT * FROM ag_plugin_access WHERE tenant_id = ? AND app = ? AND plugin = ?');
            $q->execute([$tenant, $app, $code]);
            self::$rowCache[$k] = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        return self::$rowCache[$k];
    }

    public static function purchased(string $tenant, string $app, string $code): bool
    {
        $q = AmetecsLicences::pdo()->prepare("SELECT SUM(e.action = 'paid') - SUM(e.action = 'refunded') FROM ag_release_events e JOIN ag_releases r ON r.id = e.release_id
            WHERE r.app = ? AND r.kind = 'plugin' AND r.plugin = ? AND e.tenant_id = ? AND e.action IN ('paid', 'refunded')");
        $q->execute([$app, $code, $tenant]);
        return (int) $q->fetchColumn() > 0;
    }

    /** free | granted | purchased | locked | blocked, for the plugin whose newest release is $latest. */
    public static function state(string $tenant, array $latest): string
    {
        $app = (string) $latest['app'];
        $code = self::codeOf($latest);
        $row = self::row($tenant, $app, $code);
        return match (true) {
            !empty($row['blocked']) => 'blocked',
            (float) ($latest['price'] ?? 0) <= 0 => 'free',
            !empty($row['granted']) || !empty(AmetecsReleases::access((string) $latest['id'])[$tenant]) => 'granted',
            self::purchased($tenant, $app, $code) => 'purchased',
            default => 'locked',
        };
    }

    public static function authorised(string $state): bool
    {
        return in_array($state, ['free', 'granted', 'purchased'], true);
    }

    /** For apps: should this client see the plugin's features? (authorised and not switched off) */
    public static function on(string $tenant, string $app, string $code): bool
    {
        if (!self::ready()) {
            return false;
        }
        $p = self::catalogue($app, $tenant)[$code] ?? null;
        return $p !== null && self::authorised(self::state($tenant, $p['latest'])) && (bool) (self::row($tenant, $app, $code)['enabled'] ?? true);
    }

    /**
     * Everything the client panel shows for one plugin.
     * @return array{state: string, enabled: bool, installed: string, downloaded: string, update: bool, canDownload: bool, why: string}
     */
    public static function view(string $tenant, array $p, bool $onprem): array
    {
        $st = self::state($tenant, $p['latest']);
        $row = self::row($tenant, (string) $p['app'], $p['code']);
        $ok = self::authorised($st);
        $installed = (string) ($row['installed_version'] ?? '');
        $have = $installed !== '' ? $installed : (string) ($row['downloaded_version'] ?? '');
        $why = match (true) {
            !$ok => '',
            !$onprem => 'Runs on Ametecs\' cloud: nothing to download.',
            !empty($row) && empty($row['download']) => 'Downloading is turned off for your company. Contact Ametecs.',
            empty($p['latest']['downloadable']) => 'Ametecs has not made this package downloadable yet.',
            !is_file(AmetecsReleases::path($p['latest'])) => 'The package is not on the update server yet.',
            default => '',
        };
        return ['state' => $st, 'enabled' => $ok && (bool) ($row['enabled'] ?? true), 'installed' => $installed, 'downloaded' => (string) ($row['downloaded_version'] ?? ''),
            'update' => $ok && $have !== '' && version_compare((string) $p['latest']['version'], $have, '>'), 'canDownload' => $ok && $onprem && $why === '', 'why' => $why];
    }

    /** Create or change a client's row; every change is written to the release history. */
    public static function put(string $tenant, array $latest, array $set, string $why): void
    {
        $app = (string) $latest['app'];
        $code = self::codeOf($latest);
        $set = array_intersect_key($set, array_flip(['granted', 'enabled', 'blocked', 'download', 'downloaded_version', 'installed_version']));
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $cols = array_keys($set);
        AmetecsLicences::pdo()->prepare('INSERT INTO ag_plugin_access (tenant_id, app, plugin, ' . implode(', ', $cols) . ', updated_by, created_at, updated_at) VALUES (?, ?, ?, '
            . str_repeat('?, ', count($cols)) . '?, ?, ?) ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => $c . ' = VALUES(' . $c . ')', $cols)) . ', updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
            ->execute(array_merge([$tenant, $app, $code], array_values($set), [AmetecsLicences::$actor, $now, $now]));
        self::$rowCache = [];
        AmetecsReleases::event($latest, $app, $tenant, $why, '', (string) ($set['installed_version'] ?? $set['downloaded_version'] ?? ''), 'plugin ' . $code);
    }
}

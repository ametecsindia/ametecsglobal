<?php
/**
 * THE CLIENT SHELL (owner, 18 Nov 2026 - Phase 4): one sidebar and one notification list for every client page
 * (core/index.php and core/org.php), grouped the way the Ametecs Global spec lists the administrator's sections:
 *
 *   Overview      Dashboard · My applications
 *   Software      Modules & plugins · Available updates · Upgrade history · Installations
 *   Licensing     Licences · Subscription & plans · Payments & invoices
 *   Organization  Company profile · Users & permissions · Organization setup (the central master) · App access
 *   Account       Notifications · Activity history · Support & help · Account settings
 *
 * Employees (not administrators) see Dashboard, Notifications, Support & help and Account settings only.
 */
declare(strict_types=1);

final class AmetecsShell
{
    /** @var array<string, array> per tenant */
    private static array $updCache = [];

    /** What each licensed app is offered (cloud = Ametecs' server version; on-premise = what the server reported). */
    public static function updates(string $tenant): array
    {
        if (isset(self::$updCache[$tenant])) {
            return self::$updCache[$tenant];
        }
        $out = [];
        if ($tenant !== '' && AmetecsReleases::ready()) {
            foreach (AmetecsAccess::plugins() as $code => $p) {
                $lr = AmetecsLicences::row($tenant, $code);
                if ($lr === null || AmetecsLicences::stateOf($lr) !== 'on') {
                    continue;
                }
                $cur = $lr['deployment'] === 'onprem' ? (string) ($lr['app_version'] ?? '') : AmetecsReleases::platformVersion($code);
                $out[$code] = ['p' => $p, 'l' => $lr, 'current' => $cur, 'offer' => AmetecsReleases::offerFor($tenant, $code, $cur)];
            }
        }
        return self::$updCache[$tenant] = $out;
    }

    private static array $statsCache = [];

    /** Keep only what the dashboard draws, in sane sizes (the figures come from client servers). */
    public static function cleanStats(array $in): array
    {
        $str = static fn ($v, int $n): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $n);
        $tiles = [];
        foreach (array_slice(array_values(array_filter((array) ($in['tiles'] ?? []), 'is_array')), 0, 12) as $t) {
            $str($t['label'] ?? '', 40) !== '' && $tiles[] = ['key' => preg_replace('/[^a-z_]/', '', strtolower($str($t['key'] ?? '', 30))), 'label' => $str($t['label'], 40), 'value' => $str($t['value'] ?? '', 30), 'sub' => $str($t['sub'] ?? '', 60),
                'tone' => in_array($t['tone'] ?? '', ['green', 'amber', 'red', 'blue'], true) ? $t['tone'] : ''];
        }
        $rows = [];
        foreach (array_slice(array_values(array_filter((array) ($in['list']['rows'] ?? []), 'is_array')), 0, 8) as $r) {
            $rows[] = ['text' => $str($r['text'] ?? '', 120), 'sub' => $str($r['sub'] ?? '', 80)];
        }
        return ['tiles' => $tiles] + ($rows !== [] ? ['list' => ['title' => $str($in['list']['title'] ?? '', 60), 'rows' => $rows]] : []);
    }

    /** An on-premise install's latest figures (heartbeat / licence-api "stats"). Not in the licence history: it changes hourly. */
    public static function saveStats(array $licence, array $stats): void
    {
        try {
            AmetecsLicences::pdo()->prepare('UPDATE ag_licences SET stats_json = ?, stats_at = ? WHERE id = ?')
                ->execute([json_encode(self::cleanStats($stats), JSON_UNESCAPED_UNICODE), AmetecsAccess::today() . ' ' . date('H:i:s'), $licence['id']]);
        } catch (\Throwable) {
            // the column arrives with the Phase 6 migration; until then there is nothing to keep
        }
    }

    /**
     * The figures for one app on the client's dashboard, or null when the app has none (yet).
     * Cloud: the app's own plugins/<app>/ag-stats.php, run now. On-premise: what its server last reported.
     * @return array{tiles: list<array>, list?: array, at?: string, error?: string}|null
     */
    public static function appStats(string $tenant, string $code, array $licence, array $filters = []): ?array
    {
        $k = $tenant . '|' . $code . '|' . md5((string) json_encode($filters));
        if (array_key_exists($k, self::$statsCache)) {
            return self::$statsCache[$k];
        }
        $file = __DIR__ . '/../plugins/' . preg_replace('/[^a-z0-9]/', '', $code) . '/ag-stats.php';
        $out = null;
        if ($licence['deployment'] !== 'onprem' && is_file($file)) {
            // ponytail: 30-second file cache per client + filters, so the dashboard's auto-refresh and several open tabs
            // do not re-read the whole muster every time. A shared cache (Redis) if many clients sit on one server.
            $cf = sys_get_temp_dir() . '/ag-stats-' . md5($k . '|' . filemtime($file)) . '.json';
            $hit = is_file($cf) && filemtime($cf) > time() - 30 ? json_decode((string) file_get_contents($cf), true) : null;
            if (is_array($hit)) {
                return self::$statsCache[$k] = $hit;
            }
            try {
                $raw = (array) (require $file)(AmetecsLicences::pdo(), $tenant, $filters);
                $out = self::cleanStats($raw) + array_filter(['trend' => is_array($raw['trend'] ?? null) ? $raw['trend'] : null,
                    'people' => is_array($raw['people'] ?? null) ? $raw['people'] : null, 'range' => $raw['range'] ?? null]);
                @file_put_contents($cf, json_encode($out), LOCK_EX);
            } catch (\Throwable $x) {
                error_log('Ametecs Global dashboard figures for ' . $code . ': ' . $x->getMessage());
                $out = ['tiles' => [], 'error' => 'The summary could not be read just now.'];
            }
        } elseif (($j = json_decode((string) ($licence['stats_json'] ?? ''), true)) !== null && is_array($j)) {
            $out = self::cleanStats($j) + ['at' => (string) ($licence['stats_at'] ?? ''), 'reported' => true];
        }
        return self::$statsCache[$k] = $out;
    }

    /**
     * Things the client's administrator should know or act on, most urgent first.
     * @return list<array{level: string, icon: string, title: string, detail: string, link: string}>
     */
    public static function notifications(string $tenant, bool $isAdmin): array
    {
        if ($tenant === '' || !$isAdmin) {
            return [];
        }
        $n = [];
        $today = AmetecsAccess::today();
        $soon = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
        $apps = AmetecsAccess::plugins(true);
        foreach (self::updates($tenant) as $code => $u) {
            foreach ($u['offer']['updates'] as $r) {
                $n[] = ['level' => $r['mandatory'] ? 'red' : 'blue', 'icon' => 'fa-cloud-arrow-down', 'title' => $u['p']['name'] . ' ' . $r['version'] . ($r['mandatory'] ? ' - mandatory update' : ' is available'),
                    'detail' => (string) ($r['title'] ?: mb_strimwidth((string) ($r['notes'] ?? ''), 0, 90, '…')), 'link' => '?v=updates'];
            }
            if ($u['offer']['rollback'] !== null) {
                $n[] = ['level' => 'red', 'icon' => 'fa-rotate-left', 'title' => $u['p']['name'] . ' ' . $u['current'] . ' was withdrawn', 'detail' => 'Version ' . $u['offer']['rollback']['version'] . ' is offered to your server instead.', 'link' => '?v=updates'];
            }
        }
        foreach (AmetecsBilling::ready() ? AmetecsBilling::invoices($tenant, 'pending') : [] as $i) {
            $late = (string) $i['due_on'] < $today;
            $n[] = ['level' => $late ? 'red' : 'amber', 'icon' => 'fa-file-invoice', 'title' => 'Invoice ' . $i['number'] . ($late ? ' is overdue' : ' is due ' . date('d M', (int) strtotime((string) $i['due_on']))),
                'detail' => $i['currency'] . ' ' . number_format((float) $i['total'], 2) . ' · ' . $i['description'], 'link' => '?v=billing'];
        }
        foreach (AmetecsLicences::rows()[$tenant] ?? [] as $code => $l) {
            if ($l['status'] === 'cancelled') {
                continue;
            }
            $name = $apps[$code]['name'] ?? $code;
            $st = AmetecsLicences::stateOf($l);
            $until = (string) ($l['valid_until'] ?? '');
            if ($st === 'expired') {
                $n[] = ['level' => 'red', 'icon' => 'fa-hourglass-end', 'title' => $name . ' licence expired', 'detail' => 'Ended ' . date('d M Y', (int) strtotime($until)) . '. Renew to keep using it.', 'link' => '?v=licences'];
            } elseif ($st === 'suspended') {
                $n[] = ['level' => 'red', 'icon' => 'fa-pause', 'title' => $name . ' licence is suspended', 'detail' => 'Contact Ametecs to reactivate it.', 'link' => '?v=support'];
            } elseif ($until !== '' && $until <= $soon) {
                $n[] = ['level' => 'amber', 'icon' => 'fa-calendar-xmark', 'title' => $name . ' renews ' . date('d M Y', (int) strtotime($until)), 'detail' => max(0, (int) ((strtotime($until) - strtotime($today)) / 86400)) . ' days left.', 'link' => '?v=licences'];
            }
            if ($l['deployment'] === 'onprem' && $st === 'on') {
                if (trim((string) ($l['key_hash'] ?? '')) !== '' && trim((string) ($l['fingerprint'] ?? '')) === '') {
                    $n[] = ['level' => 'blue', 'icon' => 'fa-server', 'title' => $name . ' is not activated on your server yet', 'detail' => 'Enter the licence key on the installed app\'s activation screen.', 'link' => '?v=apps'];
                } elseif (($seen = (string) ($l['last_seen_at'] ?? '')) !== '' && $seen < (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d')) {
                    $n[] = ['level' => 'amber', 'icon' => 'fa-plug-circle-xmark', 'title' => $name . ' server has not checked in for a week', 'detail' => 'Last seen ' . date('d M Y', (int) strtotime($seen)) . '. Check the server\'s internet connection.', 'link' => '?v=apps'];
                }
            }
        }
        foreach (AmetecsReleases::ready() ? AmetecsReleases::events(['tenant_id' => $tenant, 'action' => 'failed'], 3) : [] as $e) {
            if ((string) $e['created_at'] >= (new DateTimeImmutable($today))->modify('-14 days')->format('Y-m-d')) {
                $n[] = ['level' => 'red', 'icon' => 'fa-triangle-exclamation', 'title' => ($apps[$e['app']]['name'] ?? $e['app']) . ' ' . $e['to_version'] . ' failed to install', 'detail' => mb_strimwidth((string) $e['detail'], 0, 90, '…'), 'link' => '?v=activity'];
            }
        }
        $rank = ['red' => 0, 'amber' => 1, 'blue' => 2, 'green' => 3];
        usort($n, static fn ($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
        return $n;
    }

    public static function notificationList(array $items, int $limit = 0): string
    {
        $items = $limit > 0 ? array_slice($items, 0, $limit) : $items;
        if ($items === []) {
            return '<div class="empty2"><i class="fas fa-circle-check" style="color:#15803d"></i> All clear: nothing needs your attention.</div>';
        }
        $h = '<ul class="nl">';
        foreach ($items as $i) {
            $h .= '<li><span class="ic ' . ag_h($i['level']) . '"><i class="fas ' . ag_h($i['icon']) . '"></i></span><a href="' . ag_h($i['link']) . '"><b>' . ag_h($i['title']) . '</b><small>' . ag_h($i['detail']) . '</small></a></li>';
        }
        return $h . '</ul>';
    }

    /**
     * The sidebar. $base = how this page reaches core/index.php ('' from index.php itself, './' from org.php).
     * $active = view key (home, apps, updates, ... ) or 'org:<route>' on Organization Setup.
     */
    public static function nav(string $company, bool $isAdmin, string $active, array $counts = [], string $base = '', ?\PDO $pdo = null, ?array $user = null): string
    {
        // MENU, 18 Nov 2026 (owner: "redesign left side menu and toggle option like hide and unhide"): the arrow by the
        // brand folds the menu to an icon rail and back (remembered in this browser, html.sb-mini; ui.js).
        $h = '<aside class="side cside"><div class="brandrow"><a class="brand" href="' . ag_h($base === '' ? './' : $base) . '"><span class="logo-mark">AG</span><span>Ametecs <b>Global</b></span></a>'
            . '<button type="button" class="sbtog" data-sbtog title="Hide menu" aria-label="Hide or show the menu"><i class="fas fa-angles-left"></i></button></div>';
        if ($company !== '') {
            $h .= '<div class="ws"><span class="ws-i">' . ag_h(mb_strtoupper(mb_substr($company, 0, 1))) . '</span><span><b>' . ag_h($company) . '</b><small>Workspace</small></span></div>';
        }
        // ONE MENU (owner, 20 Nov 2026): every task of every licensed app, grouped by work area (core/menu.php) - the same
        // list ag-topbar.js draws inside the apps. A group opens when it holds the page on screen.
        $m = $pdo !== null && $user !== null ? AmetecsMenu::groups($pdo, $user, AmetecsMenu::root()) : ['groups' => [], 'prs_pending' => false];
        $keyOf = static function (string $href): string {
            if (preg_match('/[?&]v=([a-z0-9-]+)/', $href, $x) === 1) { return $x[1]; }
            if (preg_match('/org\.php\?s=([a-z0-9-]+)/', $href, $x) === 1) { return 'org:' . $x[1]; }
            return str_ends_with($href, '/core/') ? 'home' : '';
        };
        $alias = ['updates' => 'apps', 'invoice' => 'billing', 'notifications' => 'home', 'account' => 'home'];
        $want = $alias[$active] ?? $active;
        // FIND A PAGE (redesign, 20 Nov 2026): type to filter every task in every work area - no hunting through groups
        $h .= '<div class="sfind"><i class="fas fa-magnifying-glass"></i><input type="search" placeholder="Find a page…" aria-label="Find a page" data-sfind></div>'
            . '<script>document.addEventListener("input",function(e){if(!e.target.matches("[data-sfind]"))return;var q=e.target.value.trim().toLowerCase(),n=e.target.closest("aside").querySelector(".snav");'
            . 'n.querySelectorAll("details.grp").forEach(function(g){var any=false;g.querySelectorAll("a.sub").forEach(function(a){var m=!q||a.textContent.toLowerCase().indexOf(q)>=0;a.hidden=!m;any=any||m;});g.hidden=!any;if(q)g.open=any;});'
            . 'n.querySelectorAll(":scope > a.nv").forEach(function(a){a.hidden=!!q&&a.textContent.toLowerCase().indexOf(q)<0;});});</script>';
        $h .= '<nav class="snav">';
        foreach ($m['groups'] as $g) {
            $subs = '';
            $open = false;
            foreach ($g['items'] as $it) {
                $on = $it['app'] === 'core' && $keyOf($it['href']) === $want;
                $open = $open || $on;
                $subs .= '<a class="sub' . ($on ? ' on' : '') . '" href="' . ag_h($it['href']) . '"' . (str_contains($it['href'], 'org.php?s=') ? ' data-q="?r=' . ag_h(substr($it['href'], strpos($it['href'], 's=') + 2)) . '"' : '')
                    . '><i class="fas ' . ag_h($it['icon']) . '"></i><span>' . ag_h($it['label']) . '</span>' . (!empty($it['count']) ? '<em class="ucnt">' . (int) $it['count'] . '</em>' : '') . '</a>';
            }
            if ($g['key'] === 'home' && count($g['items']) === 1) {   // a Home with only the dashboard is one plain item
                $it = $g['items'][0];
                $h .= '<a class="nv' . ($want === 'home' ? ' on' : '') . '" href="' . ag_h($it['href']) . '"><i class="fas fa-gauge-high"></i><span>Dashboard</span></a>';
                continue;
            }
            $h .= '<details class="grp"' . ($open ? ' open' : '') . ' data-k="' . ag_h($g['key']) . '"><summary title="' . ag_h($g['label']) . '"><i class="fas ' . ag_h($g['icon']) . '"></i><span>' . ag_h($g['label']) . '</span><i class="fas fa-chevron-down car"></i></summary>' . $subs . '</details>';
        }
        $h .= '</nav>' . (!empty($m['prs_pending']) ? '<script>try{if(!sessionStorage.agPrsNav){sessionStorage.agPrsNav=1;fetch(' . json_encode($m['prs_nav']) . ',{credentials:"same-origin"}).then(function(){location.reload()})}}catch(e){}</script>' : '')
            . '<div class="side-foot"><span class="dotlive"></span><span>All systems normal</span><button type="button" class="sbtog2" data-sbtog title="Hide / show menu"><i class="fas fa-angles-left"></i></button></div></aside>';
        return $h;
    }
}

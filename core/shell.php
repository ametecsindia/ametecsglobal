<?php
/**
 * THE CLIENT SHELL (owner, 18 Nov 2026 - Phase 4): one sidebar and one notification list for every client page
 * (core/index.php and core/org.php), grouped the way the AmetecsGlobal spec lists the administrator's sections:
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
                $n[] = ['level' => 'red', 'icon' => 'fa-hourglass-end', 'title' => $name . ' licence expired', 'detail' => 'Ended ' . date('d M Y', (int) strtotime($until)) . '. Renew to keep using it.', 'link' => '?v=plans'];
            } elseif ($st === 'suspended') {
                $n[] = ['level' => 'red', 'icon' => 'fa-pause', 'title' => $name . ' licence is suspended', 'detail' => 'Contact Ametecs to reactivate it.', 'link' => '?v=support'];
            } elseif ($until !== '' && $until <= $soon) {
                $n[] = ['level' => 'amber', 'icon' => 'fa-calendar-xmark', 'title' => $name . ' renews ' . date('d M Y', (int) strtotime($until)), 'detail' => max(0, (int) ((strtotime($until) - strtotime($today)) / 86400)) . ' days left.', 'link' => '?v=plans'];
            }
            if ($l['deployment'] === 'onprem' && $st === 'on') {
                if (trim((string) ($l['key_hash'] ?? '')) !== '' && trim((string) ($l['fingerprint'] ?? '')) === '') {
                    $n[] = ['level' => 'blue', 'icon' => 'fa-server', 'title' => $name . ' is not activated on your server yet', 'detail' => 'Enter the licence key on the installed app\'s activation screen.', 'link' => '?v=installs'];
                } elseif (($seen = (string) ($l['last_seen_at'] ?? '')) !== '' && $seen < (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d')) {
                    $n[] = ['level' => 'amber', 'icon' => 'fa-plug-circle-xmark', 'title' => $name . ' server has not checked in for a week', 'detail' => 'Last seen ' . date('d M Y', (int) strtotime($seen)) . '. Check the server\'s internet connection.', 'link' => '?v=installs'];
                }
            }
        }
        foreach (AmetecsReleases::ready() ? AmetecsReleases::events(['tenant_id' => $tenant, 'action' => 'failed'], 3) : [] as $e) {
            if ((string) $e['created_at'] >= (new DateTimeImmutable($today))->modify('-14 days')->format('Y-m-d')) {
                $n[] = ['level' => 'red', 'icon' => 'fa-triangle-exclamation', 'title' => ($apps[$e['app']]['name'] ?? $e['app']) . ' ' . $e['to_version'] . ' failed to install', 'detail' => mb_strimwidth((string) $e['detail'], 0, 90, '…'), 'link' => '?v=upgrades'];
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
    public static function nav(string $company, bool $isAdmin, string $active, array $counts = [], string $base = ''): string
    {
        $v = static fn (string $k): string => $k === 'home' ? ($base === '' ? './' : $base) : $base . '?v=' . $k;
        $item = static function (string $href, string $icon, string $label, bool $on, int $count = 0, string $extra = ''): string {
            return '<a class="nv' . ($on ? ' on' : '') . '" href="' . ag_h($href) . '"' . $extra . '><i class="fas ' . $icon . '"></i><span>' . ag_h($label) . '</span>'
                . ($count > 0 ? '<em class="ucnt">' . $count . '</em>' : '') . '</a>';
        };
        $h = '<aside class="side"><a class="brand" href="' . ag_h($v('home')) . '"><span class="logo-mark">AG</span><span>Ametecs<b>Global</b></span></a>';
        if ($company !== '') {
            $h .= '<div class="ws"><span class="ws-i">' . ag_h(mb_strtoupper(mb_substr($company, 0, 1))) . '</span><span><b>' . ag_h($company) . '</b><small>Workspace</small></span></div>';
        }
        $h .= '<nav class="snav"><div class="nav-l">Overview</div>' . $item($v('home'), 'fa-gauge-high', 'Dashboard', $active === 'home');
        if ($isAdmin) {
            $h .= $item($v('apps'), 'fa-grip', 'My applications', $active === 'apps')
                . '<div class="nav-l">Software</div>'
                . $item($v('plugins'), 'fa-puzzle-piece', 'Modules & plugins', $active === 'plugins')
                . $item($v('updates'), 'fa-cloud-arrow-down', 'Available updates', $active === 'updates', (int) ($counts['updates'] ?? 0))
                . $item($v('upgrades'), 'fa-code-branch', 'Upgrade history', $active === 'upgrades')
                . $item($v('installs'), 'fa-server', 'Installations', $active === 'installs')
                . '<div class="nav-l">Licensing &amp; billing</div>'
                . $item($v('licences'), 'fa-key', 'Licences', $active === 'licences')
                . $item($v('plans'), 'fa-layer-group', 'Subscription & plans', $active === 'plans')
                . $item($v('billing'), 'fa-file-invoice', 'Payments & invoices', $active === 'billing', (int) ($counts['invoices'] ?? 0))
                . '<div class="nav-l">Organization</div>'
                . $item($base . 'org.php?s=setup-companies', 'fa-building', 'Company profile', $active === 'org:setup-companies', 0, ' data-q="?r=setup-companies"')
                . $item($base . 'org.php?s=users', 'fa-user-shield', 'Users & permissions', $active === 'org:users');
            $orgIcons = ['Organization' => 'fa-sitemap', 'Employees' => 'fa-users', 'Logins & access' => 'fa-id-card', 'Attendance devices' => 'fa-fingerprint'];
            foreach (AmetecsSetup::CORE as $grp => $items) {
                $open = false;
                $subs = '';
                foreach ($items as $label => $it) {
                    $route = 'org:' . $it[0] . ($it[1] !== '' ? ':' . $it[1] : '');
                    $open = $open || $route === $active;
                    $q = AmetecsSetup::href($it);
                    $subs .= '<a class="sub' . ($route === $active ? ' on' : '') . '" href="' . ag_h($base . 'org.php' . str_replace('?r=', '?s=', $q)) . '" data-q="' . ag_h($q) . '"><i class="fas ' . ag_h($it[2]) . '"></i><span>' . ag_h($label) . '</span></a>';
                }
                $h .= '<details class="grp"' . ($open ? ' open' : '') . '><summary><i class="fas ' . ($orgIcons[$grp] ?? 'fa-folder') . '"></i><span>' . ag_h($grp) . '</span><i class="fas fa-chevron-down car"></i></summary>' . $subs . '</details>';
            }
            $h .= $item($base . 'org.php?s=ag-apps', 'fa-toggle-on', 'App access', $active === 'org:ag-apps');
        }
        $h .= '<div class="nav-l">Account</div>'
            . $item($v('notifications'), 'fa-bell', 'Notifications', $active === 'notifications', (int) ($counts['notifications'] ?? 0))
            . ($isAdmin ? $item($v('activity'), 'fa-clock-rotate-left', 'Activity history', $active === 'activity') : '')
            . $item($v('support'), 'fa-life-ring', 'Support & help', $active === 'support')
            . $item($v('account'), 'fa-user-gear', 'Account settings', $active === 'account')
            . '</nav><div class="side-foot"><span class="dotlive"></span><span>All systems normal</span></div></aside>';
        return $h;
    }
}

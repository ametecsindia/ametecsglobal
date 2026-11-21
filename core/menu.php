<?php
/**
 * AMETECS GLOBAL — ONE MENU (owner, 20 Nov 2026: "one complete product, not four applications"). Every task of SmartPRS,
 * ROVEXY, QRONEX and SmartEPT sits in one menu, grouped by the work it is for (People, Attendance, Field work, ...), never
 * by application. The same list is drawn by the core pages (AmetecsShell::nav) and inside every app page
 * (assets/ag-topbar.js, from topbar.php), so the menu never changes as people move between screens.
 *
 * What a person sees:
 *   - licence: an app's items only while Super Admin has it on, installed and not in maintenance (AmetecsAccess / Licences)
 *   - App access: an app switched off for this employee is left out
 *   - role: Admin / Manager / Team Lead / Employee (AmetecsRoles::level); each item names the roles that may open it,
 *     matching the role each app is given at sign-in (AgBridge::admin in each app); the app still checks every request
 *   - SmartPRS: exactly the items its own permissions allow, saved by app.php in the shared session (ag_prs_nav)
 * Items of other apps open through core/open.php?app=..&to=.. (one sign-in hand-over); inside the same app the menu
 * goes straight to the page (ag-topbar.js).
 */
declare(strict_types=1);

final class AmetecsMenu
{
    private const LEAD = ['admin', 'manager', 'team_lead'];
    private const MGR = ['admin', 'manager'];
    private const ADM = ['admin'];
    private const ALL = ['admin', 'manager', 'team_lead', 'employee'];

    /** work area => [label, icon] in menu order */
    public const AREAS = [
        'home' => ['Home', 'fa-house'],
        'people' => ['People', 'fa-users'],
        'attendance' => ['Attendance', 'fa-user-clock'],
        'leave' => ['Leave', 'fa-umbrella-beach'],
        'payroll' => ['Payroll', 'fa-indian-rupee-sign'],
        'field' => ['Field work', 'fa-route'],
        'productivity' => ['Productivity', 'fa-display'],
        'performance' => ['Performance & learning', 'fa-chart-line'],
        'services' => ['HR services', 'fa-headset'],
        'reports' => ['Reports', 'fa-chart-column'],
        'apps' => ['Applications & billing', 'fa-grip'],
        'settings' => ['Settings', 'fa-gear'],
        'help' => ['Help', 'fa-life-ring'],
    ];

    /** SmartPRS menu section => work area */
    private const PRS_AREA = ['main' => 'home', 'hiring' => 'people', 'people' => 'people', 'time' => 'attendance', 'leave' => 'leave',
        'payroll' => 'payroll', 'compensation' => 'payroll', 'statutory' => 'payroll', 'performance' => 'performance', 'learning' => 'performance',
        'helpdesk-section' => 'services', 'letters' => 'services', 'communication' => 'services', 'field' => 'field', 'reports' => 'reports',
        'admin' => 'settings', 'setup' => 'settings'];

    /** SmartPRS routes Ametecs Global shows itself (its own screens or Organization setup), left out of the SmartPRS part */
    private const PRS_SKIP = ['dashboard', 'notifications', 'how-it-works', 'employees', 'roles', 'configure-menu'];

    /**
     * The other apps' tasks: app => [[area, label, fa-icon, to, roles]]. `to` is the page inside the app
     * (ROVEXY console path, QRONEX portal path, SmartEPT view[:report]). Roles follow what each app gives the role
     * Ametecs Global hands it (ROVEXY company_admin / supervisor, QRONEX company_admin / office_manager, SmartEPT
     * COMPANY_ADMIN / MANAGER / TEAM_LEADER / EMPLOYEE). App screens Ametecs Global already has (employees, logins,
     * licence, company profile, branding) are not listed.
     */
    private const APPS = [
        'rovexy' => [
            ['field', 'Field dashboard', 'fa-gauge', '', self::LEAD],
            ['field', 'Calls & recordings', 'fa-phone', 'calls', self::LEAD],
            ['field', 'Follow-ups', 'fa-calendar-check', 'follow-ups', self::LEAD],
            ['field', 'Payments & slips', 'fa-receipt', 'payments', self::LEAD],
            ['field', 'Customers', 'fa-address-book', 'customers', self::LEAD],
            ['field', 'Portfolios', 'fa-file-import', 'imports', self::LEAD],
            ['field', 'Assignment', 'fa-people-arrows', 'allocation', self::LEAD],
            ['field', 'Field planning', 'fa-map-location-dot', 'field-planning', self::LEAD],
            ['field', 'Live location', 'fa-location-dot', 'location', self::LEAD],
            ['field', 'Messages', 'fa-comments', 'chat', self::LEAD],
            ['field', 'Live phone screen', 'fa-mobile-screen', 'screen', self::LEAD],
            ['field', 'Camera sessions', 'fa-video', 'camera', self::LEAD],
            ['field', 'Field phones', 'fa-mobile-button', 'devices', self::LEAD],
            ['reports', 'Field activity', 'fa-person-walking', 'employee-activity', self::LEAD],
            ['reports', 'Portfolio reports', 'fa-chart-pie', 'portfolio-reports', self::LEAD],
            ['reports', 'Call & visit extracts', 'fa-file-export', 'reports', self::LEAD],
            ['reports', 'Address & reachability', 'fa-map-pin', 'address-report', self::LEAD],
            ['reports', 'Recording export', 'fa-file-audio', 'recording-export', self::ADM],
            ['settings', 'Calling setup', 'fa-tower-cell', 'calling/setup', self::ADM],
            ['settings', 'Calling options', 'fa-phone-volume', 'calling/options', self::ADM],
            ['settings', 'Company numbers', 'fa-hashtag', 'calling/numbers', self::ADM],
            ['settings', 'Calling access', 'fa-user-check', 'calling/people', self::ADM],
            ['settings', 'Call outcome codes', 'fa-list-ol', 'calling/outcomes', self::ADM],
            ['settings', 'Test calls', 'fa-phone-flip', 'calling/test-calls', self::ADM],
            ['settings', 'Recording file names', 'fa-file-signature', 'recording-formats', self::LEAD],
            ['settings', 'Field phone policies', 'fa-shield-halved', 'policies', self::ADM],
            ['settings', 'Storage & retention', 'fa-box-archive', 'retention', self::ADM],
            ['settings', 'Field audit log', 'fa-clipboard-list', 'audit', self::ADM],
            ['settings', 'Field integrations', 'fa-plug', 'integrations', self::ADM],
            ['settings', 'Field system health', 'fa-heart-pulse', 'health', self::ADM],
        ],
        'qronex' => [
            ['attendance', 'QR attendance today', 'fa-qrcode', '', self::LEAD],
            ['attendance', 'QR attendance ledger', 'fa-list', 'attendance', self::LEAD],
            ['attendance', 'Attendance approvals', 'fa-check-double', 'approvals', self::LEAD],
            ['attendance', 'Remote attendance', 'fa-house-laptop', 'remote', self::LEAD],
            ['attendance', 'Attendance phones', 'fa-mobile-screen-button', 'devices', self::LEAD],
            ['reports', 'QR attendance reports', 'fa-file-csv', 'reports', self::LEAD],
            ['settings', 'Offices, gates & QR screens', 'fa-door-open', 'offices', self::ADM],
            ['settings', 'QR attendance policy', 'fa-sliders', 'policy', self::ADM],
            ['settings', 'QR API & webhooks', 'fa-code', 'api', self::ADM],
        ],
        'smartept' => [
            ['productivity', 'Productivity dashboard', 'fa-gauge-high', 'dashboard', self::ALL],
            ['productivity', 'Live view', 'fa-tv', 'liveview', self::ADM],
            ['productivity', 'Screenshots', 'fa-images', 'screenshots', self::ALL],
            ['productivity', 'Webcam', 'fa-camera', 'webcam', self::MGR],
            ['productivity', 'Usage & compliance', 'fa-chart-simple', 'usage', self::ALL],
            ['productivity', 'Violations', 'fa-triangle-exclamation', 'violations', self::ALL],
            ['productivity', 'Meetings', 'fa-people-group', 'meetings', self::LEAD],
            ['productivity', 'PC agents', 'fa-desktop', 'devices', self::ADM],
            ['productivity', 'Endpoint security', 'fa-shield-virus', 'endsec', self::ADM],
            ['productivity', 'PC audit log', 'fa-clipboard-check', 'pcaudit', self::ADM],
            ['productivity', 'Screen recording', 'fa-record-vinyl', 'screcord', self::ADM],
            ['attendance', 'PC login attendance', 'fa-computer', 'attendance', self::ALL],
            ['reports', 'Productivity report', 'fa-chart-line', 'reports:prod', self::MGR],
            ['reports', 'Break report', 'fa-mug-hot', 'reports:break', self::MGR],
            ['reports', 'Meeting report', 'fa-handshake', 'reports:meet', self::MGR],
            ['reports', 'Schedule report', 'fa-calendar-days', 'schedrep', self::MGR],
            ['reports', 'Extra hours', 'fa-business-time', 'extrahours', self::MGR],
            ['settings', 'PC policies', 'fa-file-shield', 'policies', ['admin', 'employee']],
            ['settings', 'App & web rules', 'fa-filter', 'rules', ['admin', 'employee']],
            ['settings', 'Gate-to-PC exclusions', 'fa-door-closed', 'gateexcl', self::ADM],
            ['settings', 'PC email alerts', 'fa-envelope', 'mailalerts', self::ADM],
            ['settings', 'PC API & integrations', 'fa-plug-circle-bolt', 'integrations', self::ADM],
            ['settings', 'PC audit & operations', 'fa-screwdriver-wrench', 'ops', self::ADM],
        ],
    ];

    /**
     * The menu for this signed-in person: list of ['key','label','icon','items' => [['label','icon','href','app','count'?]]].
     * $root = the web path of Ametecs Global's folder, e.g. "/AmetecsGlobal/".
     */
    public static function groups(\PDO $pdo, array $user, string $root): array
    {
        $tid = (string) $user['tenant_id'];
        $level = AmetecsRoles::level($pdo, $user);
        $admin = $level === 'admin';
        $lead = in_array($level, self::LEAD, true);
        $emp = (string) ($user['employee_id'] ?? '');
        $core = $root . 'core/';
        $prs = $root . 'plugins/smartprs3/public/app.php';
        $org = static fn (string $r): string => $core . 'org.php?s=' . rawurlencode($r);
        $g = array_fill_keys(array_keys(self::AREAS), []);
        $add = static function (string $area, string $label, string $icon, string $href, string $app, ?int $count = null) use (&$g): void {
            $g[$area][] = ['label' => $label, 'icon' => $icon, 'href' => $href, 'app' => $app] + ($count ? ['count' => $count] : []);
        };

        // Ametecs Global's own screens
        $add('home', $level === 'employee' ? 'My space' : 'Dashboard', $level === 'employee' ? 'fa-house-user' : 'fa-gauge-high', $level === 'employee' ? $core . '?v=me' : $core, 'core');
        if ($emp !== '' && $level !== 'employee') {
            $add('home', 'My space', 'fa-house-user', $core . '?v=me', 'core');
        }
        if ($admin) {
            $add('people', 'Employees', 'fa-users', $org('employees'), 'core');
            $add('people', 'Employee fields', 'fa-list-check', $org('employee-fields'), 'core');
        }
        if ($lead) {
            $add('people', 'Employee 360', 'fa-id-card-clip', $core . '?v=people', 'core');
            $add('reports', 'All reports', 'fa-chart-column', $core . '?v=reports', 'core');
        }
        if ($admin) {
            foreach (['Biometric devices' => ['biometric-setup', 'fa-fingerprint'], 'Biometric mapping & sync' => ['biometric-mapping', 'fa-arrows-rotate'],
                'Biometric API keys' => ['api-keys', 'fa-key'], 'QR attendance (office screens)' => ['qr-attendance', 'fa-qrcode']] as $l => [$r, $i]) {
                $add('attendance', $l, $i, $org($r), 'core');
            }
            $add('apps', 'My applications', 'fa-grip', $core . '?v=apps', 'core');
            $add('apps', 'Modules & plugins', 'fa-puzzle-piece', $core . '?v=plugins', 'core');
            $add('apps', 'App access', 'fa-toggle-on', $org('ag-apps'), 'core');
            $add('apps', 'Licences & plans', 'fa-key', $core . '?v=licences', 'core');
            $add('apps', 'Payments & invoices', 'fa-file-invoice', $core . '?v=billing', 'core');
            $add('settings', 'Company profile', 'fa-building', $org('setup-companies'), 'core');
            foreach (['Branches' => ['setup-branches', 'fa-code-branch'], 'Departments' => ['setup-departments', 'fa-sitemap'], 'Designations' => ['setup-designations', 'fa-id-badge'],
                'Teams' => ['setup-teams', 'fa-people-group'], 'Shifts' => ['setup-shifts', 'fa-clock'], 'Logins' => ['users', 'fa-user-shield'],
                'Mobile devices' => ['mobile-devices', 'fa-mobile-screen']] as $l => [$r, $i]) {
                $add('settings', $l, $i, $org($r), 'core');
            }
            $add('help', 'Activity history', 'fa-clock-rotate-left', $core . '?v=activity', 'core');
        }
        $add('help', 'Support & help', 'fa-life-ring', $core . '?v=support', 'core');

        // SmartPRS: what its own permissions allow (saved by app.php); the central setup screens open in Organization setup
        $on = static fn (string $app): bool => AmetecsAccess::state($tid, $app) === 'on' && AmetecsLicences::installed(AmetecsLicences::row($tid, $app))
            && !AmetecsLicences::maintenance($tid, $app);
        $pending = false;
        if ($on('smartprs3')) {
            $prsNav = $_SESSION['ag_prs_nav'] ?? null;
            $pending = !is_array($prsNav);
            $master = [];
            foreach (AmetecsSetup::CORE as $items) { foreach ($items as [$r]) { $master[$r] = true; } }
            foreach ((array) $prsNav as $sec) {
                $area = self::PRS_AREA[(string) $sec['key']] ?? 'settings';
                foreach ((array) $sec['items'] as $it) {
                    parse_str(ltrim((string) $it['href'], '?'), $q);
                    $r = (string) ($q['r'] ?? '');
                    if (($r === 'employees' ? $admin : in_array($r, self::PRS_SKIP, true)) || isset($master[$r]) && $r !== 'employees') {
                        continue;
                    }
                    $add($area, $r === 'employees' ? 'Employee directory' : (string) $it['label'], (string) ($it['icon'] ?: 'fa-circle'), $prs . (string) $it['href'], 'smartprs3', (int) ($it['count'] ?? 0));
                }
            }
        }

        // QRONEX scanner for everyone with an employee record (core/scan.php -> QRONEX's own checks)
        if ($emp !== '' && $on('qronex') && !in_array((int) $emp, array_map('intval', AmetecsAppAccess::hidden($pdo, $tid, 'qronex')), true)) {
            array_unshift($g['attendance'], ['label' => 'Scan QR attendance', 'icon' => 'fa-qrcode', 'href' => $core . 'scan.php', 'app' => 'core']);
        }

        // ROVEXY, QRONEX, SmartEPT: licence, App access, role
        $off = static function (string $app) use ($pdo, $tid, $emp, $admin): bool {
            return !$admin && $emp !== '' && in_array((int) $emp, array_map('intval', AmetecsAppAccess::hidden($pdo, $tid, $app)), true);
        };
        foreach (self::APPS as $app => $items) {
            if (!$on($app) || $off($app)) {
                continue;
            }
            foreach ($items as [$area, $label, $icon, $to, $roles]) {
                if (in_array($level, $roles, true)) {
                    $add($area, $label, $icon, $core . 'open.php?app=' . $app . '&to=' . rawurlencode($to), $app);
                }
            }
        }

        if ($level === 'employee') {   // an employee's settings are the PC rules they must follow: keep them with Productivity
            $g['productivity'] = array_merge($g['productivity'], $g['settings']);
            $g['settings'] = [];
        }
        $out = [];
        foreach (self::AREAS as $k => [$label, $icon]) {
            if ($g[$k] !== []) {
                $out[] = ['key' => $k, 'label' => $label, 'icon' => $icon, 'items' => $g[$k]];
            }
        }
        return ['groups' => $out, 'prs_pending' => $pending, 'prs_nav' => $prs . '?r=dashboard&ag_nav=1'];
    }

    /** Ametecs Global's web path ("/AmetecsGlobal/") from a script inside core/ */
    public static function root(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/core/index.php'))), '/');
        return (str_ends_with($dir, '/core') ? substr($dir, 0, -5) : $dir) . '/';
    }
}

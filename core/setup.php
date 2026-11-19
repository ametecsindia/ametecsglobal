<?php
/**
 * ORGANIZATION SETUP — the one fixed skeleton every client gets (owner, 17 Nov 2026): the organisation structure,
 * employee create / edit / delete / import, logins, passwords and roles, and attendance devices (biometric, QR) live
 * here, in the core, for every client alike. Formats and rules (salary structure, payslip, ID card, leave, etc.) stay
 * in each app. The central database is the one source of employee data; each app keeps its
 * own screens and its own settings exactly as they are (owner: "other structure of the plugins ... will remain as is")
 * and uses these employee details for its actions and reports. Which apps a client sees is all that differs.
 *
 * Each entry: label => [route, module key or '', icon]. The screens themselves are SmartPRS3's (today the core's
 * database is SmartPRS3's), shown inside core/org.php; the gate keeps them out of the app.
 */
declare(strict_types=1);

final class AmetecsSetup
{
    /** The skeleton: same for every client, whatever apps are on. */
    public const CORE = [
        'Organization' => [
            'Companies' => ['setup-companies', '', 'fa-building'],
            'Branches' => ['setup-branches', '', 'fa-code-branch'],
            'Departments' => ['setup-departments', '', 'fa-sitemap'],
            'Designations' => ['setup-designations', '', 'fa-id-badge'],
            'Teams' => ['setup-teams', '', 'fa-people-group'],
            'Shifts' => ['setup-shifts', '', 'fa-clock'],
        ],
        'Employees' => [
            'Employee directory' => ['employees', '', 'fa-users'],
            'Employee fields' => ['employee-fields', '', 'fa-list-check'],
        ],
        'Logins & access' => [
            'User Management' => ['users', '', 'fa-user-shield'],
            'Mobile devices' => ['mobile-devices', '', 'fa-mobile-screen'],
        ],
        'Attendance devices' => [
            'Biometric devices' => ['biometric-setup', '', 'fa-fingerprint'],
            'Biometric mapping & sync' => ['biometric-mapping', '', 'fa-arrows-rotate'],
            'Biometric API keys' => ['api-keys', '', 'fa-key'],
            'QR attendance' => ['qr-attendance', '', 'fa-qrcode'],
        ],
    ];

    /** Saves that change the employee master: refused unless they come from Organization Setup. */
    public const MASTER_ACTIONS = ['add-employee', 'update-employee', 'save-employee-fields', 'add-employee-field',
        'import-preview', 'import-commit', 'employee-delete', 'employee-rejoin', 'employee-status',
        'add-user', 'bind-user', 'reset-employee-password', 'employee-import-sample', 'employee-delete-cancel'];

    /** Shown in the app too (the directory is a view every app needs); only adding/editing is central. */
    /** Shown in the app too: the employee directory is a view every app needs; only adding/editing is central. */
    private const SHARED = ['employees', 'm:employees'];

    /**
     * QR ATTENDANCE, MOBILE DEVICES AND BIOMETRIC ARE CORE MODULES (owner, 17 Nov 2026): they live only in Organization
     * Setup; any app's own copy (SmartPRS3's today, any future app's) is hidden and that app uses the core's records -
     * one registration, every app. Their saves are refused unless they come from Organization Setup; employees' own
     * punches (self-punch, the mobile app, QR gates) are not affected.
     */
    public const DEVICE_ACTIONS = ['mobile-device', 'mobile-activation', 'qr-attendance', 'biometric'];

    /** Module keys that are the same screen as a setup-* route. */
    private const MODULE_ALIAS = ['companies' => 'setup-companies', 'branches' => 'setup-branches', 'departments' => 'setup-departments',
        'designations' => 'setup-designations', 'teams' => 'setup-teams', 'shifts' => 'setup-shifts',
        'employee-import' => 'employee-import'];

    /** "route" or "m:key" for the screen a request opens. */
    public static function screenOf(string $r, string $k, array $get = []): string
    {
        if ($r === 'm') {
            return self::MODULE_ALIAS[$k] ?? 'm:' . $k;
        }
        if ($r === 'employees' && isset($get['edit'])) {
            return 'employee-edit';
        }
        if ($r === 'employees' && isset($get['add'])) {
            return 'add-employee';   // ?r=employees&add=1 is the Add form, not the directory
        }
        return $r;
    }

    /** 'master' (skeleton), 'shared' (directory) or '' (an ordinary app screen). */
    public static function kind(string $screen): string
    {
        static $map = null;
        if ($map === null) {
            $map = ['employee-edit' => 'master', 'add-employee' => 'master', 'employee-import' => 'master'];   // opened from the directory's own buttons
            foreach (self::CORE as $items) {
                foreach ($items as [$r, $k]) { $map[$r === 'm' ? 'm:' . $k : $r] = 'master'; }
            }
            foreach (self::SHARED as $s) { $map[$s] = 'shared'; }
        }
        return $map[$screen] ?? '';
    }

    public static function href(array $item): string
    {
        [$r, $k] = $item;
        return '?r=' . rawurlencode($r) . ($k !== '' ? '&k=' . rawurlencode($k) : '');
    }

    /** CSS for a SmartPRS3 page: framed = screen only; in the app = no configuration. */
    public static function css(bool $framed): string
    {
        if ($framed) {
            // inside AmetecsGlobal: its gold look too (core/assets/frame-gold.css), so the panel is one product
            return '<link rel="stylesheet" href="../../../core/assets/frame-gold.css?v=9"><script>try{var T=localStorage.getItem("agTheme");T&&T!=="gold"&&document.documentElement.classList.add("th-"+(T==="classic"?"navy":T))}catch(e){}</script><style>.sidebar,.topbar{display:none!important}.main-wrap{margin-left:0!important}</style>';
        }
        // Hidden by the server's own CSS, so nothing depends on the script below running: every way to add, import,
        // edit or bulk-upload employees inside the app (the Directory stays view and reports only).
        $ag = '<link rel="stylesheet" href="../../../core/assets/ag-prs.css?v=1"><script>(function(h){function s(){var t;try{t=localStorage.getItem("agTheme")}catch(e){}'
            . 'h.classList.remove("th-navy","th-light","th-dark");if(t&&t!="gold")h.classList.add("th-"+t);h.setAttribute("data-mode",t==="dark"?"dark":"light")}'
            . 's();addEventListener("storage",s);document.addEventListener("DOMContentLoaded",s)})(document.documentElement)</script>';   // same everywhere (19 Nov 2026)
        return $ag . '<style>.topbar a[href="index.php"],a[href*="r=my-subscription"],a[href*="r=sys-updates"],#add-employment,#add-employee-link,.dir-empty-acts,'
            . 'a[href*="#add-employment"],a[href*="add=1"],a[href*="r=employee-import"],a[href*="edit="][href*="r=employees"],'
            . 'form:has(input[name=action][value="employee-import-sample"]),form:has(input[name=action][value="employee-status"]),'
            . 'form:has(input[name=action][value="employee-delete"]),form:has(input[name=action][value="employee-delete-cancel"]){display:none!important}'
            . '#ag-back{display:flex;align-items:center;gap:8px;margin:10px 14px 4px;padding:8px 12px;border-radius:8px;background:rgba(255,255,255,.1);'
            . 'color:#fff;font:600 13px system-ui,sans-serif;text-decoration:none}#ag-back:hover{background:rgba(255,255,255,.18)}</style>';
    }

    /** JS for a SmartPRS3 page in the app: "<- All apps", and links/buttons to central screens taken out. */
    public static function js(bool $framed): string
    {
        return self::fieldChecks() . self::jsMode($framed);
    }

    /**
     * EMAIL ID IS REQUIRED ON ADD / EDIT EMPLOYEE (owner, 17 Nov 2026: it is the employee's login). Everything else about
     * field checks is SmartPRS3's own and already field-level: the format under each field (Employee fields -> pattern
     * and its description), the field turning red, and the "Not saved - fill this field first / Go to ..." dialog on Save.
     * This only marks Email required so that same dialog includes it; the server refuses it too (SYNC fix in app.php).
     */
    public static function fieldChecks(): string
    {
        return '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("form").forEach(function(f){'
            . 'var a=f.querySelector("input[name=action]"),m=f.querySelector("input[name=f_email]");'
            . 'if(a&&m&&(a.value==="add-employee"||a.value==="update-employee"))m.required=true})})</script>';
    }

    private static function jsMode(bool $framed): string
    {
        if ($framed) {
            // Plain-http addresses get no Sec-Fetch-Dest header, so every link and form in the frame carries ag_embed=1.
            return '<script>(function(){function t(h){try{var u=new URL(h,location.href);if(u.origin!==location.origin||u.searchParams.has("ag_embed"))return h;'
                . 'u.searchParams.set("ag_embed","1");return u.pathname+u.search+u.hash}catch(e){return h}}'
                . 'document.querySelectorAll("a[href]").forEach(function(a){var h=a.getAttribute("href");if(h&&h[0]!=="#"&&h.indexOf("javascript:")!==0)a.setAttribute("href",t(h))});'
                . 'document.querySelectorAll("form").forEach(function(f){if((f.method||"get").toLowerCase()==="get"){var i=document.createElement("input");i.type="hidden";i.name="ag_embed";i.value="1";f.appendChild(i)}else{f.setAttribute("action",t(f.getAttribute("action")||location.pathname+location.search))}});'
                . '})();</script>';
        }
        $central = ['add-employee', 'employee-import'];   // no menu entries: Add / Import are buttons in the Employee directory
        foreach (self::CORE as $items) {
            foreach ($items as [$r, $k]) { $central[] = $r === 'm' ? 'm:' . $k : $r; }
        }
        foreach (array_keys(self::MODULE_ALIAS) as $k) { $central[] = 'm:' . $k; }
        $central = array_values(array_diff($central, self::SHARED));
        return '<script>(function(){var C=' . json_encode($central) . ',M=' . json_encode([...self::MASTER_ACTIONS, ...self::DEVICE_ACTIONS]) . ';'
            . 'function scr(h){try{var u=new URL(h,location.href);if(u.pathname!==location.pathname)return"";var r=u.searchParams.get("r")||"";'
            . 'if(r==="employees"&&u.searchParams.has("edit"))return"employee-edit";return r==="m"?"m:"+(u.searchParams.get("k")||""):r}catch(e){return""}}'
            . 'function central(s){return s==="employee-edit"||C.indexOf(s)>=0}'
            . 'document.querySelectorAll("a[href]").forEach(function(a){if(central(scr(a.getAttribute("href"))))a.remove()});'
            . 'document.querySelectorAll("form").forEach(function(f){var a=f.querySelector("input[name=action]");if(a&&M.indexOf(a.value)>=0)(f.closest("details")||f).remove()});'
            . 'document.querySelectorAll(".nav-section").forEach(function(s){if(!s.querySelector(".nav-items a"))s.remove()});'
            . 'var sb=document.querySelector(".sidebar-nav");if(sb){var b=document.createElement("a");b.id="ag-back";b.href="../../../core/";'
            . 'b.innerHTML="&larr; All apps";sb.parentNode.insertBefore(b,sb)}'
            . '})();</script><script src="../../../core/assets/ag-topbar.js?v=4" defer></script>';   // AmetecsGlobal's top bar (19 Nov 2026)
    }
}

/**
 * WHICH EMPLOYEES USE WHICH APP (owner, 17 Nov 2026). An app can be switched on or off for a company, branch,
 * department or team, and employees inherit it; any one employee can be set on or off by hand. The most specific
 * setting wins (employee, team, department, branch, company); nothing set = on. An employee who is off for an app is
 * hidden from that app (its screens, reports and payroll) and uses none of its seats.
 * Kept in core/data/app-access.json: {tenant: {app: {level: {id: 1|0}}}} — per PC, like the rest of the data.
 */
final class AmetecsAppAccess
{
    public const LEVELS = ['employee' => 'id', 'team' => 'team_id', 'department' => 'department_id', 'branch' => 'branch_id', 'company' => 'employer_company_id'];
    private const FILE = __DIR__ . '/data/app-access.json';

    public static function rules(string $tenant, string $app): array
    {
        $all = is_file(self::FILE) ? json_decode((string) file_get_contents(self::FILE), true) : [];
        return (array) ($all[$tenant][$app] ?? []);
    }

    /** $on: true / false, or null to go back to inheriting. */
    public static function set(string $tenant, string $app, string $level, array $ids, ?bool $on): void
    {
        if (!isset(self::LEVELS[$level])) { return; }
        $fh = fopen(self::FILE, 'c+');
        flock($fh, LOCK_EX);
        $all = json_decode((string) stream_get_contents($fh), true) ?: [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($on === null) { unset($all[$tenant][$app][$level][$id]); } else { $all[$tenant][$app][$level][$id] = $on ? 1 : 0; }
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($all, JSON_PRETTY_PRINT));
        fclose($fh);
    }

    /** [on, where it comes from: 'employee' | 'team' | ... | ''] */
    public static function resolve(array $rules, array $emp): array
    {
        foreach (self::LEVELS as $level => $col) {
            $v = $rules[$level][(string) ($emp[$col] ?? '')] ?? null;
            if ($v !== null && (string) ($emp[$col] ?? '') !== '') { return [(bool) $v, $level]; }
        }
        return [true, ''];
    }

    /** Employee ids that are off for this app (empty when nothing is set — the usual case, no query). */
    public static function hidden(\PDO $pdo, string $tenant, string $app): array
    {
        $rules = self::rules($tenant, $app);
        if (array_filter($rules) === []) { return []; }
        $q = $pdo->prepare('SELECT id, team_id, department_id, branch_id, employer_company_id FROM employees WHERE tenant_id = ?');
        $q->execute([$tenant]);
        $off = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            if (!self::resolve($rules, $e)[0]) { $off[] = (int) $e['id']; }
        }
        return $off;
    }
}

/**
 * EVERY EMPLOYEE GETS A LOGIN THE MOMENT THEY ARE SAVED (owner, 17 Nov 2026). Before, logins were made only when
 * someone opened User Management. Now, after any save in any app (add, edit, import, self-onboarding approval),
 * each employee with an email and no login gets one - SmartPRS3's own rules: an unlinked login on the same email
 * is linked, the role follows the designation, an inactive employee's login starts disabled.
 * DEFAULT PASSWORD (User Management, per client, per PC like the rest of core/data): set = new logins get it and
 * can sign in straight away; not set = a random one, and User Management -> Reset password gives them theirs.
 */
final class AmetecsLogins
{
    private const FILE = __DIR__ . '/data/user-management.json';

    public static function defaultPassword(string $tenant): ?string
    {
        $all = is_file(self::FILE) ? (json_decode((string) file_get_contents(self::FILE), true) ?: []) : [];
        $enc = (string) ($all[$tenant]['default_password'] ?? '');
        return $enc === '' ? null : \SmartPRS\Surface\Secret::decrypt($enc, AG_BASE . '/.env');
    }

    /** $plain = '' clears it. */
    public static function setDefaultPassword(string $tenant, string $plain): void
    {
        $all = is_file(self::FILE) ? (json_decode((string) file_get_contents(self::FILE), true) ?: []) : [];
        $all[$tenant]['default_password'] = $plain === '' ? '' : \SmartPRS\Surface\Secret::encrypt($plain, AG_BASE . '/.env');
        file_put_contents(self::FILE, json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** Runs at the end of a SmartPRS3 request (after its save and redirect). Uses app.php's own objects. */
    public static function syncAfterSave(): void
    {
        $g = $GLOBALS;
        if (!isset($g['users'], $g['access'], $g['pdo'], $g['session']) || !function_exists('employeeLoginRole')) { return; }
        $tenant = (string) ($g['ametecs_tenant'] ?? '');
        if ($tenant === '') { return; }
        try {
            /** @var \PDO $pdo */
            $pdo = $g['pdo'];
            // Straight from the table: employees switched off for this app still get their login.
            $q = $pdo->prepare("SELECT e.* FROM employees e WHERE e.tenant_id = ? AND (e.deleted_at IS NULL) AND TRIM(COALESCE(e.email, '')) <> ''
                AND NOT EXISTS (SELECT 1 FROM users u WHERE u.tenant_id = e.tenant_id AND u.employee_id = e.id)");
            $q->execute([$tenant]);
            $todo = $q->fetchAll(\PDO::FETCH_ASSOC);
            $users = $g['users'];
            $store = (new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant);
            $actor = (array) $g['session']->user();
            $default = $todo === [] ? null : self::defaultPassword($tenant);
            $byEmail = [];
            foreach ($todo === [] ? [] : ($users->all()['users'] ?? []) as $u) { $byEmail[strtolower(trim((string) $u['email']))] = $u; }
            foreach ($todo as $e) {
                $mail = trim((string) $e['email']);
                $same = $byEmail[strtolower($mail)] ?? null;
                if ($same !== null) {
                    if ((string) $same['employment_id'] === '') { $users->bind((string) $same['id'], (string) $e['id']); }
                    continue;   // already someone else's login: User Management lists it as a problem
                }
                $r = $users->create($mail, $default ?? bin2hex(random_bytes(12)), employeeLoginRole($store, $g['access'], $e, (string) ($actor['role'] ?? '')), (string) $e['id']);
                if (!$r['ok']) { continue; }
                isset($g['audit']) && ($g['audit'])('user.created', 'user', (string) $r['user']['id'], null, ['email' => $mail, 'employment' => (string) $e['id'],
                    'default_password' => $default !== null], 'Login created when the employee was saved (AmetecsGlobal)');
                if (!in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true)) {
                    $users->setDisabled((string) $r['user']['id'], true, (string) ($actor['id'] ?? ''));
                }
            }
            isset($g['employeeSync']) && $g['employeeSync']->syncLogins();   // email / name changes follow onto the login
        } catch (\Throwable $x) {
            error_log('AmetecsGlobal login sync: ' . $x->getMessage());   // never breaks the save; User Management catches up
        }
    }
}

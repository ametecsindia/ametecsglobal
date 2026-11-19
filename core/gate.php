<?php
/**
 * AMETECSGLOBAL GATE — runs inside plugins/smartprs3/public/app.php (SYNC adds the one `require` line after boot-hooks).
 *
 *   - nobody signed in  -> the AmetecsGlobal front door (core/), not SmartPRS3's own sign-in page
 *   - signed in, but Super Admin has not switched SmartPRS3 on (or it expired) for this client -> back to the front door
 *   - THE ORGANISATION AND EMPLOYEE MASTER ARE CENTRAL (owner, 17 Nov 2026): those screens (core/setup.php) open only from
 *     core/org.php, framed. Inside the app their menus, buttons and addresses are gone; everything else in the app,
 *     its own settings included, stays as it is and uses the central employee data.
 *   - ametecs_seats() feeds Super Admin's seat number into SmartPRS3's existing "add employee" limit
 *   - every app page gets "<- All apps"
 * The Super Admin console (public/super.php) is never gated.
 */
declare(strict_types=1);

require_once __DIR__ . '/access.php';

if (!$superConsole) {
    // Framed inside core/org.php? The browser says so on every request in the frame (Sec-Fetch-Dest), redirects and saves included.
    $agFramed = ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe' || isset($_GET['ag_embed']);
    if (!$session->isSignedIn()) {
        // A SmartPRS3 company link (/c/<slug> -> ?ws=) opens the AmetecsGlobal company link instead; the mobile app's (?app=1) stays.
        if (isset($_GET['ws']) && !isset($_GET['app']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            header('Location: ../../../core/?c=' . rawurlencode((string) $_GET['ws']));
            exit;
        }
        // Every SmartPRS3 page opened while signed out goes to the AmetecsGlobal front door, except the pages that
        // must work without a sign-in: emailed set-password links (?t=), the mobile app's company link, forgot password, first-run enrolment.
        // Field formats and live checks on the pages that work signed out too (self-onboarding, set password).
        ob_start(static fn (string $html): string => stripos($html, '</body>') === false ? $html : str_ireplace('</body>', AmetecsSetup::fieldChecks() . '</body>', $html));
        $agOpen = isset($_GET['t']) || isset($_GET['ws']) || in_array((string) ($_GET['r'] ?? ''), ['set-password', 'forgot-password'], true);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$agOpen && !$session->needsEnrolment()) {
            if ($agFramed) {
                echo '<!doctype html><script>top.location.href="../../../core/";</script>';
                exit;
            }
            header('Location: ../../../core/');
            exit;
        }
    } else {
        $GLOBALS['ametecs_tenant'] = (string) ($session->user()['tenant_id'] ?? '');
        $agScreen = AmetecsSetup::screenOf((string) ($_GET['r'] ?? ''), (string) ($_GET['k'] ?? ''), $_GET);
        $agKind = AmetecsSetup::kind($agScreen);   // 'master' | 'shared' | '' (an ordinary app screen)
        $agOn = AmetecsAccess::state($GLOBALS['ametecs_tenant'], 'smartprs3') === 'on'
            && AmetecsLicences::installed(AmetecsLicences::row($GLOBALS['ametecs_tenant'], 'smartprs3'))
            && !AmetecsLicences::maintenance($GLOBALS['ametecs_tenant'], 'smartprs3');   // maintenance mode while it installs / updates   // Download first (owner, 19 Nov 2026)
        $agPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $agStop = static function (string $why) use ($agPost): never {
            if ($agPost) {
                http_response_code(403);
                echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:30px">' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8')
                    . ' <a href="../../../core/org.php">Open Organization Setup</a></p>';
                exit;
            }
            header('Location: ../../../core/org.php?go=' . rawurlencode((string) ($_SERVER['QUERY_STRING'] ?? '')));   // the whole address: ?r=employees&edit=7 opens that employee
            exit;
        };
        if ($agKind === 'master' && !$agFramed) {
            $agStop('Settings and the employee master are managed centrally, in AmetecsGlobal Organization Setup.');
        }
        $agAction = (string) ($_POST['action'] ?? '');
        // LICENCES ONLY IN SUPER ADMIN (owner, 19 Nov 2026): SmartPRS3's own subscription, renewal, invoices and self-update
        // are closed inside AmetecsGlobal; the client sees its licence under My applications.
        if (in_array((string) ($_GET['r'] ?? ''), ['my-subscription', 'sys-updates'], true) || in_array($agAction, ['my-renew', 'my-invoice', 'system-update', 'plan', 'subscription', 'onprem', 'feature-flag'], true)) {
            $_SESSION['flash_problem'] = 'Licences, renewals and updates are managed by Ametecs in AmetecsGlobal Super Admin. Your licence is shown under My applications.';
            if ($agFramed) {
                echo '<!doctype html><script>top.location.href="../../../core/?v=apps";</script>';
                exit;
            }
            header('Location: ../../../core/?v=apps');
            exit;
        }
        if ($agPost) {
            register_shutdown_function([AmetecsLogins::class, 'syncAfterSave']);   // a login for every saved employee, straight away
        }
        $agModuleKind = str_starts_with($agAction, 'module-') ? AmetecsSetup::kind(AmetecsSetup::screenOf('m', (string) ($_POST['k'] ?? $_GET['k'] ?? ''))) : '';
        if (!$agFramed && $agPost && (in_array($agAction, AmetecsSetup::MASTER_ACTIONS, true) || in_array($agAction, AmetecsSetup::DEVICE_ACTIONS, true) || $agModuleKind === 'master')) {
            $agStop('Employees are added and changed centrally, in AmetecsGlobal Organization Setup.');
        }
        // The central master works whatever apps the client has; the app's own screens only while it is switched on.
        if (!$agOn && $agKind !== 'master' && $agKind !== 'shared') {
            if ($agFramed) {
                echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:30px">This screen belongs to SmartPRS3, which is not switched on for your company.</p>';
                exit;
            }
            header('Location: ../../../core/?blocked=smartprs3');
            exit;
        }
        // Framed: redirects after a save stay framed (they carry ag_embed=1 too).
        if ($agFramed) {
            header_register_callback(static function (): void {
                foreach (headers_list() as $hdr) {
                    if (stripos($hdr, 'Location:') === 0 && stripos($hdr, 'ag_embed') === false) {
                        $to = trim(substr($hdr, 9));
                        $hash = '';
                        if (($p = strpos($to, '#')) !== false) { $hash = substr($to, $p); $to = substr($to, 0, $p); }
                        header('Location: ' . $to . (str_contains($to, '?') ? '&' : '?') . 'ag_embed=1' . $hash);
                    }
                }
            });
        }
        // WHICH EMPLOYEES USE THIS APP (Organization Setup -> App access). Outside Organization Setup, employees switched off
        // for SmartPRS3 are left out of every list, report and payroll run (surface/SqlStore.php, a SYNC fix), and a
        // switched-off employee cannot open the app. Organization Setup itself always sees everyone.
        if (!$agFramed && ($pdo ?? null) instanceof \PDO) {
            $GLOBALS['ametecs_hidden_employees'] = AmetecsAppAccess::hidden($pdo, $GLOBALS['ametecs_tenant'], 'smartprs3');
            $agEmp = (int) ($session->user()['employee_id'] ?? 0);
            if ($agEmp > 0 && in_array($agEmp, $GLOBALS['ametecs_hidden_employees'], true)) {
                header('Location: ../../../core/?blocked=smartprs3');
                exit;
            }
        }
        // The page itself: framed = no app menu or top bar; in the app = no configuration menus or master buttons, plus "<- All apps".
        ob_start(static function (string $html) use ($agFramed): string {
            $isHtml = true;
            foreach (headers_list() as $hdr) {
                if (stripos($hdr, 'Content-Type:') === 0 && stripos($hdr, 'text/html') === false) {
                    $isHtml = false;
                }
            }
            if (!$isHtml || stripos($html, '</head>') === false) {
                return $html;
            }
            return str_ireplace(['</head>', '</body>'], [AmetecsSetup::css($agFramed) . '</head>', AmetecsSetup::js($agFramed) . '</body>'], $html);
        });
    }
}

function ametecs_seats(string $plugin): ?int
{
    return AmetecsAccess::seats((string) ($GLOBALS['ametecs_tenant'] ?? ''), $plugin);
}

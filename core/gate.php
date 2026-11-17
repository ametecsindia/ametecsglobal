<?php
/**
 * AMETECSGLOBAL GATE — runs inside plugins/smartprs3/public/app.php (SYNC adds the one `require` line after boot-hooks).
 *   - nobody signed in  -> the AmetecsGlobal front door (core/), not SmartPRS3's own sign-in page
 *   - signed in, but Super Admin has not switched SmartPRS3 on (or it expired) for this client -> back to the front door
 *   - ametecs_seats() feeds Super Admin's seat number into SmartPRS3's existing "add employee" limit
 * The Super Admin console (public/super.php) is never gated.
 */
declare(strict_types=1);

require_once __DIR__ . '/access.php';

if (!$superConsole) {
    if (!$session->isSignedIn()) {
        // Every SmartPRS3 page opened while signed out goes to the AmetecsGlobal front door, except the pages that
        // must work without a sign-in: emailed set-password links (?t=), company links (?ws=), forgot password, first-run enrolment.
        $agOpen = isset($_GET['t']) || isset($_GET['ws']) || in_array((string) ($_GET['r'] ?? ''), ['set-password', 'forgot-password'], true);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$agOpen && !$session->needsEnrolment()) {
            header('Location: ../../../core/');
            exit;
        }
    } else {
        $GLOBALS['ametecs_tenant'] = (string) ($session->user()['tenant_id'] ?? '');
        if (AmetecsAccess::state($GLOBALS['ametecs_tenant'], 'smartprs3') !== 'on') {
            header('Location: ../../../core/?blocked=smartprs3');
            exit;
        }
    }
}

function ametecs_seats(string $plugin): ?int
{
    return AmetecsAccess::seats((string) ($GLOBALS['ametecs_tenant'] ?? ''), $plugin);
}

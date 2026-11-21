<?php

namespace App\Http\Controllers;

use App\Services\AgBridge;
use Illuminate\Http\Request;

/**
 * Ametecs Global "Open SmartEPT": the signed hand-over from core/open.php becomes the console's own ?sso= ticket
 * (the same sign-in SmartEPT Central used), on the company's branded address.
 */
class AgSsoController extends Controller
{
    public function enter(Request $r)
    {
        $p = AgBridge::verify((string) $r->input('t'));
        $p !== null && ($p['kind'] ?? '') !== '' && $p = null;   // a scan ticket is not a sign-in
        if ($p === null) {
            return response('This link has expired. Go back to Ametecs Global and open SmartEPT again.', 403);
        }
        $first = !\App\Models\Company::withoutGlobalScopes()->where('external_tenant_id', 'ag-' . preg_replace('/[^0-9]/', '', (string) $p['tid']))->exists();
        $c = AgBridge::workspace($p);
        AgBridge::licence($c, true);
        $copy = static function () use ($c, $p): void {
            try {
                AgBridge::sync($c, (array) ($p['off'] ?? []));
            } catch (\Throwable $x) {
                report($x);   // the console still opens; the copy is tried again next time
            }
        };
        // first open: copy now so the console has its people; later opens: after the page is sent, so it opens at once
        $first ? $copy() : app()->terminating($copy);
        $u = AgBridge::admin($c, $p);
        $to = preg_match('#^[a-z0-9][a-z0-9/_:-]{0,79}$#', (string) ($p['to'] ?? '')) === 1 ? (string) $p['to'] : '';   // Ametecs Global's one menu: the console view to open (ag-topbar.js reads #v=)
        return redirect('/' . $c->slug . '?sso=' . rawurlencode(AgBridge::ticket($u)) . ($to !== '' ? '#v=' . $to : ''));   // url() adds the sub-folder
    }
}

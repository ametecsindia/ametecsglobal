<?php

namespace App\Http\Controllers;

use App\Services\AgBridge;
use Illuminate\Http\Request;

/**
 * AmetecsGlobal "Open SmartEPT": the signed hand-over from core/open.php becomes the console's own ?sso= ticket
 * (the same sign-in SmartEPT Central used), on the company's branded address.
 */
class AgSsoController extends Controller
{
    public function enter(Request $r)
    {
        $p = AgBridge::verify((string) $r->input('t'));
        if ($p === null) {
            return response('This link has expired. Go back to AmetecsGlobal and open SmartEPT again.', 403);
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
        return redirect('/' . $c->slug . '?sso=' . rawurlencode(AgBridge::ticket($u)));   // url() adds the sub-folder
    }
}

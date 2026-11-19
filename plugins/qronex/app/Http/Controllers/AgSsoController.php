<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AgBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** AmetecsGlobal "Open QRONEX": the signed hand-over from core/open.php becomes a portal session (see AgBridge). */
final class AgSsoController extends Controller
{
    public function enter(Request $r)
    {
        $p = AgBridge::verify((string) $r->input('t'));
        if ($p === null) {
            return response('This link has expired. Go back to AmetecsGlobal and open QRONEX again.', 403);
        }
        $first = !\App\Models\Tenant::query()->where('ag_tenant', (string) $p['tid'])->exists();
        $t = AgBridge::workspace($p);
        AgBridge::licence($t);
        $copy = static function () use ($t, $p): void {
            try {
                AgBridge::sync($t, (array) ($p['off'] ?? []));
            } catch (\Throwable $x) {
                report($x);   // the portal still opens; the copy is tried again next time
            }
        };
        // first open: copy now so the screens have people; later opens: copy after the page is sent, so it opens at once
        $first ? $copy() : app()->terminating($copy);
        $u = AgBridge::admin($t, $p);
        AuditLog::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'action' => 'auth.login', 'subject' => $u->email, 'meta' => ['via' => 'ametecsglobal'], 'ip' => $r->ip()]);
        Auth::login($u);
        $r->session()->regenerate();
        return redirect('/' . $t->slug);   // url() adds the sub-folder
    }
}

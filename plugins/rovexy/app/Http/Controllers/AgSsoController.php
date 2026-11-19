<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AgBridge;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** AmetecsGlobal "Open ROVEXY": the signed hand-over from core/open.php becomes a console session (see AgBridge). */
final class AgSsoController extends Controller
{
    public function enter(Request $r)
    {
        $p = AgBridge::verify((string) $r->input('t'));
        if ($p === null) {
            return response('This link has expired. Go back to AmetecsGlobal and open ROVEXY again.', 403);
        }
        $c = AgBridge::workspace($p);
        $user = app(CurrentCompany::class)->run($c, function () use ($c, $p) {
            AgBridge::licence($c, $p);
            $copy = static function () use ($c, $p): void {
                try {
                    app(CurrentCompany::class)->run($c, fn () => AgBridge::sync($c, null, (array) ($p['off'] ?? [])));
                } catch (\Throwable $x) {
                    report($x);   // the console still opens; the copy is tried again next time
                }
            };
            // first open copies now (the console needs its people); later opens copy after the page is sent, so it opens at once
            \App\Models\User::query()->where('employee_code', 'like', 'ag-%')->exists() ? app()->terminating($copy) : $copy();
            $u = AgBridge::admin($c, $p);
            Audit::record('admin.login', $u, ['via' => 'ametecsglobal']);
            return $u;
        });
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->session()->put(['auth_started_at' => time(), 'mfa_verified_at' => time()]);   // AmetecsGlobal already checked who this is
        return redirect('/w/' . $c->slug . '/admin/');   // url() adds the sub-folder
    }
}

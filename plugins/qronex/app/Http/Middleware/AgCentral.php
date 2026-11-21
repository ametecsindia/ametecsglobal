<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;

/**
 * AMETECSGLOBAL (19 Nov 2026): inside Ametecs Global, QRONEX's own website, sign-up, Super Admin, plans and billing are
 * Ametecs Global's; company profile, offices (= branches) and employees are added and edited in Ametecs Global
 * Organization Setup. Those addresses go there instead (a GET is redirected, anything else is refused).
 */
final class AgCentral
{
    private const TO = [
        'home' => 'core/', 'pricing' => 'core/', 'contact' => 'core/', 'register' => 'core/', 'login' => 'core/open.php?app=qronex',   // signed out: straight back in through Ametecs Global
         'password.' => 'core/', 'two-factor.' => 'core/',
        'admin.' => 'core/super.php',
        'portal.billing.' => 'core/?v=licences&app=qronex',
        'portal.company.' => 'core/org.php?s=setup-companies',
        'portal.employees.create' => 'core/org.php?s=employees', 'portal.employees.store' => 'core/org.php?s=employees',
        'portal.employees.import' => 'core/org.php?s=employees', 'portal.employees.template' => 'core/org.php?s=employees',
        'portal.offices.create' => 'core/org.php?s=setup-branches', 'portal.offices.store' => 'core/org.php?s=setup-branches',
    ];

    public function handle(Request $request, Closure $next)
    {
        // ONE LOGIN (19 Nov 2026): a portal session opened from Ametecs Global ends when that Ametecs Global session ends.
        if (AgBridge::managed() && auth()->check() && !AgBridge::sameSession($request->session()->get('ag_sid'))) {
            auth()->logout();
            $request->session()->invalidate();
            return redirect()->away(AgBridge::agUrl('core/open.php?app=qronex'));
        }
        $name = (string) $request->route()?->getName();
        if ($name !== '' && AgBridge::managed()) {
            foreach (self::TO as $prefix => $to) {
                if ($name === $prefix || (str_ends_with($prefix, '.') && str_starts_with($name, $prefix))) {
                    abort_unless($request->isMethod('GET'), 403, 'Managed in Ametecs Global.');
                    return redirect()->away(AgBridge::agUrl($to));
                }
            }
        }
        return $next($request);
    }
}

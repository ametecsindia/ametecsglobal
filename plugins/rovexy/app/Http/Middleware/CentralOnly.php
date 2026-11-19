<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Licensing\LicenceClient;
use Closure;
use Illuminate\Http\Request;

/** Super Admin, Accounts, website, payments and the licence server exist only on the central server, not on an on-prem install. */
final class CentralOnly
{
    public function handle(Request $request, Closure $next)
    {
        // AmetecsGlobal: its Super Admin, billing and website are AmetecsGlobal's - ROVEXY's own are closed (no second place to sell or issue licences).
        if (\App\Services\AgBridge::managed()) {
            return $request->isMethod('GET') && $request->path() === '/' ? redirect()->away(dirname($request->getBasePath(), 3) . '/core/') : abort(404);
        }
        if (LicenceClient::isClient()) {
            return $request->isMethod('GET') && $request->path() === '/' ? redirect('/admin') : abort(404);
        }
        return $next($request);
    }
}

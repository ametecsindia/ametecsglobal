<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AgBridge;
use App\Services\Platform\Licensing;
use Closure;
use Illuminate\Http\Request;

/**
 * AMETECSGLOBAL LICENCE LOCK (19 Nov 2026, owner): inside AmetecsGlobal, when the ROVEXY licence is expired, suspended or
 * cancelled the console stops working (only sign-in and GET /licence still answer); the console sends the user to the
 * licence page in AmetecsGlobal, where a licence code, a .lic file or a payment opens it again.
 */
final class AgLocked
{
    public function handle(Request $request, Closure $next)
    {
        if (AgBridge::managed() && !str_ends_with($request->path(), 'admin/licence') && !app(Licensing::class)->usable()) {
            return response()->json(['error' => 'licence_locked',
                'message' => 'ROVEXY is locked: its licence is not active. Renew it in AmetecsGlobal → Licences & plans.'], 402);
        }
        return $next($request);
    }
}

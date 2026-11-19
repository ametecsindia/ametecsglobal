<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;

/**
 * AMETECSGLOBAL (19 Nov 2026, owner): every licence is created, changed, renewed and activated ONLY in AmetecsGlobal
 * Super Admin. Inside AmetecsGlobal, ROVEXY's own licence actions (renew, buy, licence key/file, self-update) answer 403.
 */
final class AgManaged
{
    public function handle(Request $request, Closure $next)
    {
        if (AgBridge::managed()) {
            return response()->json(['error' => 'managed_centrally',
                'message' => 'Your ROVEXY licence is managed by Ametecs in AmetecsGlobal. Contact Ametecs to renew, add users or change the plan.'], 403);
        }
        return $next($request);
    }
}

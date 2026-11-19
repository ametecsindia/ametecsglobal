<?php

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ONE LOGIN (19 Nov 2026, owner): a console sign-in that came from AmetecsGlobal (auth/sso) ends when that AmetecsGlobal
 * session ends - signed out there = signed out here. Only those console tokens are checked: desktop agents, the phone app
 * and SmartEPT's own sign-ins carry no AmetecsGlobal session and are untouched.
 */
class AgSession
{
    public function handle(Request $request, Closure $next)
    {
        if (AgBridge::managed() && $request->bearerToken() && ($t = auth('sanctum')->user()?->currentAccessToken())
            && method_exists($t, 'getKey') && ($sid = Cache::get('ag-sid:' . $t->getKey())) !== null && !AgBridge::sameSession($sid)) {
            $t->delete();
            Cache::forget('ag-sid:' . $t->getKey());
            return response()->json(['error' => ['code' => 'AG_SIGNED_OUT', 'message' => 'Signed out in AmetecsGlobal.']], 401);
        }
        return $next($request);
    }
}

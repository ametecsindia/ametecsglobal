<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ONE LOGIN (19 Nov 2026, owner): inside Ametecs Global the console has no sign-in of its own. A password sign-in here is
 * refused (the phone app's own sign-in is a different route and is untouched), and a console session opened from
 * Ametecs Global ends when that Ametecs Global session ends (signed out there = signed out here); the console then goes
 * back through Ametecs Global's one sign-in.
 */
final class AgSession
{
    public function handle(Request $request, Closure $next)
    {
        if (!AgBridge::managed()) {
            return $next($request);
        }
        if ($request->is('*auth/login', '*auth/find-workspace')) {
            return response()->json(['message' => 'Sign in through Ametecs Global.'], 403);
        }
        if (Auth::guard('web')->check() && !AgBridge::sameSession($request->session()->get('ag_sid'))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            return response()->json(['message' => 'Signed out in Ametecs Global.'], 401);
        }
        return $next($request);
    }
}

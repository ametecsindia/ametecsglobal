<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ONE LOGIN (19 Nov 2026, owner): inside AmetecsGlobal the console has no sign-in of its own. A password sign-in here is
 * refused (the phone app's own sign-in is a different route and is untouched), and a console session opened from
 * AmetecsGlobal ends when that AmetecsGlobal session ends (signed out there = signed out here); the console then goes
 * back through AmetecsGlobal's one sign-in.
 */
final class AgSession
{
    public function handle(Request $request, Closure $next)
    {
        if (!AgBridge::managed()) {
            return $next($request);
        }
        if ($request->is('*auth/login', '*auth/find-workspace')) {
            return response()->json(['message' => 'Sign in through AmetecsGlobal.'], 403);
        }
        if (Auth::guard('web')->check() && !AgBridge::sameSession($request->session()->get('ag_sid'))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            return response()->json(['message' => 'Signed out in AmetecsGlobal.'], 401);
        }
        return $next($request);
    }
}

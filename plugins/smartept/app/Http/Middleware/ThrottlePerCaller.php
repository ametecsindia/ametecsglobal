<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * 08-Nov-2026 (Ejaz: agent sign-in "HTTP 429", scanner "Too Many Attempts"): Laravel's `throttle:N,M` keyed every
 * caller that is not a signed-in user by NETWORK ADDRESS ONLY, with one key for every route. The whole office reaches
 * the server from one address, so every phone (QR app: me / history / scan / my-day), every gate screen's QR refresh,
 * the console's photo thumbnails, agent sign-ins and forgot-password all shared ONE counter — and the 10-a-minute
 * sign-in limit was used up by phone traffic. Now every limit counts per action (the request path) and per caller:
 * the signed-in user, else the device/app token, else the email being signed in with (+ address), else the address.
 * Registered as the `throttle` alias in bootstrap/app.php, so every existing throttle:N,M uses it.
 */
class ThrottlePerCaller extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $who = $request->user()?->getAuthIdentifier();
        if ($who !== null) {
            $who = 'u:' . $who;
        } elseif ($t = $request->bearerToken() ?: $request->header('X-Device-Token')) {
            $who = 't:' . hash('sha256', (string) $t);
        } elseif (is_string($email = $request->input('email')) && $email !== '') {
            $who = 'e:' . strtolower(trim($email)) . '|' . $request->ip();
        } else {
            $who = 'ip:' . $request->ip();
        }

        return sha1($request->path() . '|' . $who);
    }
}

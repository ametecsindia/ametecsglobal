<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployeeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 29-Oct-2026 (Altaf): SmartDCM Sign on — signing in to the SmartEPT agent also signs the
 * employee in to SmartDCM, and signing out of either ends both.
 *
 * One shared secret per company ties the two systems together (encrypted at rest, shown once).
 * No password ever passes between them:
 *   - ticket():  the agent asks for a one-time, 60-second ticket signed with the secret and
 *                opens SmartDCM with it in the browser. SmartDCM checks it and signs the user in.
 *   - session(): SmartDCM asks (server-to-server, HMAC-signed) whether the SmartEPT session is
 *                still live, or ends it when the employee logs out of SmartDCM.
 * Which employees may use it is decided in SmartDCM ("SmartEPT auto login" on the profile).
 */
class SmartDcmSsoController extends Controller
{
    public const TICKET_SECONDS = 60;
    public const CLOCK_SKEW_SECONDS = 60;
    public const SSO_PATH = '/sso/smartept';

    /** GET /api/integrations/smartdcm — the Organisation card. The secret is never returned. */
    public function show(Request $request): JsonResponse
    {
        $c = $this->company($request);

        return response()->json(['data' => [
            'enabled'    => (bool) $c->smartdcm_sso_enabled,
            'url'        => $c->smartdcm_url,
            'has_secret' => filled($c->getRawOriginal('smartdcm_secret')),
        ]]);
    }

    /**
     * PUT /api/integrations/smartdcm {enabled, url, regenerate_secret?}
     * A secret is created on the first switch-on (or on "Generate new secret") and returned
     * ONCE in this response, for the admin to paste into SmartDCM.
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'           => ['required', 'boolean'],
            'url'               => ['nullable', 'required_if:enabled,true', 'url:http,https', 'max:500'],
            'regenerate_secret' => ['nullable', 'boolean'],
        ]);
        $c = $this->company($request);

        $c->smartdcm_sso_enabled = (bool) $data['enabled'];
        $c->smartdcm_url = filled($data['url'] ?? null) ? rtrim(trim($data['url']), '/') : null;

        $secret = null;
        if (! empty($data['regenerate_secret']) || ($c->smartdcm_sso_enabled && blank($c->getRawOriginal('smartdcm_secret')))) {
            $secret = Str::random(48);
            $c->smartdcm_secret = $secret; // 'encrypted' cast — stored encrypted with APP_KEY
        }
        $c->save();

        $this->audit($request, 'SMARTDCM_SSO_UPDATE', Company::class, $c->id, [
            'enabled' => $c->smartdcm_sso_enabled, 'url' => $c->smartdcm_url, 'new_secret' => $secret !== null,
        ]);

        return response()->json(['data' => [
            'enabled'    => (bool) $c->smartdcm_sso_enabled,
            'url'        => $c->smartdcm_url,
            'has_secret' => filled($c->getRawOriginal('smartdcm_secret')),
            'secret'     => $secret, // null unless just generated — shown once
        ]]);
    }

    /**
     * POST /api/agent/smartdcm-ticket (device token) — called right after agent sign-in.
     * {enabled:false} when off or not set up; the agent then does nothing.
     */
    public function ticket(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->tokenCan('agent'), 403, 'Agent token required.');

        $c = Company::withoutGlobalScopes()->find($user->company_id);
        $secret = $c?->smartdcm_sso_enabled ? $c->smartdcm_secret : null;
        if (! $c || ! $secret || blank($c->smartdcm_url)) {
            return response()->json(['enabled' => false]);
        }

        $now = now()->timestamp;
        $body = self::b64(json_encode([
            'v'     => 1,
            'iss'   => 'smartept',
            'aud'   => 'smartdcm',
            'email' => Str::lower((string) $user->email), // SmartDCM finds the user by this
            'name'  => (string) $user->name,
            'cid'   => $c->id,
            'via'   => 'agent',
            'sid'   => $user->currentAccessToken()->id, // lets the two systems sign out together
            'ept'   => rtrim($request->getSchemeAndHttpHost(), '/'), // the address the agent signed in to — never a stored one
            'iat'   => $now,
            'exp'   => $now + self::TICKET_SECONDS,
            'nonce' => Str::random(32), // SmartDCM rejects a second use
        ], JSON_UNESCAPED_SLASHES));
        $ticket = $body . '.' . self::b64(hash_hmac('sha256', $body, $secret, true));

        $base = $c->smartdcm_url;
        if (! str_contains(strtolower(parse_url($base, PHP_URL_PATH) ?? ''), trim(self::SSO_PATH, '/'))) {
            $base .= self::SSO_PATH; // admin entered only the SmartDCM address
        }

        return response()->json([
            'enabled' => true,
            'url'     => $base . (str_contains($base, '?') ? '&' : '?') . 'ticket=' . rawurlencode($ticket),
        ]);
    }

    /**
     * POST /api/sso/smartdcm/session {cid, sid, ts, action, sig} — public, server-to-server.
     * sig = HMAC-SHA256("action|cid|sid|ts", secret). action: status | logout.
     */
    public function session(Request $request): JsonResponse
    {
        $d = $request->validate([
            'cid'    => ['required', 'integer'],
            'sid'    => ['required', 'integer'],
            'ts'     => ['required', 'integer'],
            'action' => ['required', 'in:status,logout'],
            'sig'    => ['required', 'string', 'max:128'],
        ]);

        $c = Company::withoutGlobalScopes()->find($d['cid']);
        $secret = $c?->smartdcm_sso_enabled ? $c->smartdcm_secret : null;
        $expected = $secret ? hash_hmac('sha256', "{$d['action']}|{$d['cid']}|{$d['sid']}|{$d['ts']}", $secret) : '';
        if (! $secret || abs(now()->timestamp - (int) $d['ts']) > self::CLOCK_SKEW_SECONDS
            || ! hash_equals($expected, strtolower($d['sig']))) {
            return response()->json(['error' => ['code' => 'BAD_SIGNATURE', 'message' => 'Signature check failed.']], 401);
        }

        // The token must belong to a user of THIS company — company A can never end company B's sessions.
        $token = PersonalAccessToken::find($d['sid']);
        $user = $token?->tokenable;
        if (! $token || ! $user || (int) $user->company_id !== (int) $c->id || ! str_starts_with((string) $token->name, 'device:')) {
            return response()->json(['active' => false]);
        }
        $device = EmployeeDevice::withoutGlobalScopes()->where('device_uuid', substr($token->name, 7))->first();

        if ($d['action'] === 'logout') {
            $device ? $device->endSession() : $token->delete();
            AuditLog::create([
                'company_id' => $c->id, 'user_id' => $user->id, 'action' => 'LOGOUT',
                'subject_type' => EmployeeDevice::class, 'subject_id' => $device?->id,
                'changes' => ['note' => 'signed out of SmartDCM', 'device_uuid' => $device?->device_uuid],
                'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json(['active' => false]);
        }

        return response()->json(['active' => ! $device || ($device->session_status === 'ACTIVE' && ! $device->unbound_at)]);
    }

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->user()->company_id);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

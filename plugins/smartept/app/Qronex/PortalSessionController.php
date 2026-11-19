<?php

namespace App\Qronex;

use Ametecs\Qronex\Services\AttendanceVerifier;
use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 07-Nov-2026 (Ejaz): the EPT QR Attendance app shows the employee's SmartEPT portal without a second login.
 * POST /api/qronex/v1/portal-session (phone device token + signature, same as /scan). A portal token is issued only while
 * the phone is APPROVED and the employee is punched IN today; punching OUT revokes it (EptHost::recordPunch).
 * 07-Nov-2026 (Ejaz): every login gets it, whatever its role, but the app session is ALWAYS the employee's own
 * Employee Self-Service view and view-only: bootViewOnly() swaps the role to EMPLOYEE for these tokens on every request
 * and refuses anything but reading. An approved phone never opens an admin console.
 */
class PortalSessionController extends Controller
{
    public const TOKEN_NAME = 'qr-app';

    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $deny = fn (string $code, string $msg) => response()->json(['message' => $msg, 'reason_code' => $code], 403);

        if ($device->status !== 'approved') {
            return $deny('device_not_approved', 'Your phone must be approved first.');
        }
        if (AttendanceVerifier::nextType($device->employee_id) !== 'OUT') {
            return $deny('punched_out', 'Punch IN to see your SmartEPT dashboard.');
        }

        $emp = Employee::withoutGlobalScope('company')->where('company_id', $device->tenant_id)->find($device->employee->host_employee_id);
        $user = $emp?->user;
        if (! $user || $user->status === 'DISABLED' || optional($user->company)->status === 'SUSPENDED') {
            return $deny('no_portal_account', 'You have no active SmartEPT portal login. Ask your administrator.');
        }

        self::revoke($user);
        // ponytail: 12 h ceiling in case a punch OUT never arrives; the OUT punch revokes it sooner.
        $token = $user->createToken(self::TOKEN_NAME, ['role:EMPLOYEE'], now()->addHours(12))->plainTextToken;

        return response()->json(['token' => $token, 'slug' => $user->company?->slug]);
    }

    /** Called once from AppServiceProvider::boot. */
    public static function bootViewOnly(): void
    {
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $e) {
            if ($e->token->name !== self::TOKEN_NAME || ! $e->token->tokenable instanceof User) {
                return;
            }
            $user = $e->token->tokenable;
            $user->setRelation('role', Role::query()->whereNull('company_id')->where('slug', 'EMPLOYEE')->first());
            $user->setAttribute('must_change_password', false); // in memory only: no password screen inside the app
            $r = request();
            if (! in_array($r->method(), ['GET', 'HEAD'], true) && ! $r->is('api/auth/logout')) {
                abort(403, 'The SmartEPT dashboard in the EPT QR Attendance app is view-only. Use the web portal to make changes.');
            }
        });
    }

    public static function revoke(?User $user): void
    {
        $user?->tokens()->where('name', self::TOKEN_NAME)->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\AgBridge;
use App\Services\AttendanceVerifier;
use Illuminate\Http\Request;

/**
 * AMETECS GLOBAL - QRONEX SCANNER INSIDE AMETECS GLOBAL (owner, 20 Nov 2026: "the actual attendance scanning
 * functionality should work within Ametecs Global"). The employee signs in once to Ametecs Global; its "Scan QR" page
 * (core/scan.php, on the phone app's web view or any browser with a camera) reads the gate's QR and sends it here with
 * a one-time ticket that core signs for this employee (kind "scan", 60 s, single use - AgBridge::verify).
 * The punch goes through QRONEX's own AttendanceVerifier, so every QRONEX rule still applies: gate signature and
 * rotation window, office/gate assignment, geofence, GPS accuracy, replay, employee status. The employee's
 * "Ametecs Global" device is a normal QRONEX device: approved automatically only when the company does not require
 * device approval; otherwise it waits in Attendance phones like any phone.
 */
final class AgScanController extends Controller
{
    public function scan(Request $r, AttendanceVerifier $verifier)
    {
        $p = AgBridge::verify((string) $r->input('t'));
        if ($p === null || ($p['kind'] ?? '') !== 'scan') {
            return response()->json(['ok' => false, 'message' => 'This scan has expired. Open Scan QR again.'], 403);
        }
        $d = $r->validate([
            'qr' => 'required|string|max:200', 'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0|max:100000', 'type' => 'nullable|in:IN,OUT', 'agent' => 'nullable|string|max:120', 'mocked' => 'nullable|boolean',
        ]);
        $t = Tenant::query()->where('ag_tenant', (string) $p['tid'])->first();
        $e = $t ? Employee::withoutGlobalScopes()->where('tenant_id', $t->id)->where('hrms_ref', 'ag-' . (int) $p['emp'])->first() : null;
        if ($e === null) {
            return response()->json(['ok' => false, 'message' => 'You are not set up for QR attendance yet. Ask your administrator to open QR attendance once in Ametecs Global.'], 422);
        }
        $settings = TenantSetting::withoutGlobalScopes()->where('tenant_id', $t->id)->first() ?? new TenantSetting(TenantSetting::defaults());
        $device = Device::withoutGlobalScopes()->firstOrCreate(['install_ref' => 'ag-' . $t->id . '-' . $e->id], [
            'tenant_id' => $t->id, 'employee_id' => $e->id, 'platform' => 'android', 'model' => 'Ametecs Global', 'os_version' => mb_substr((string) ($d['agent'] ?? ''), 0, 60),
            'status' => $settings->require_device_approval ? 'pending' : 'approved', 'approved_at' => $settings->require_device_approval ? null : now(),
            'approval_code' => $settings->require_device_approval ? Device::newApprovalCode() : null,
        ]);
        if ($device->status !== 'approved') {
            return response()->json(['ok' => false, 'message' => $device->status === 'pending'
                ? 'Your Ametecs Global sign-in is waiting for approval for QR attendance (code ' . $device->approval_code . '). Ask your administrator to approve it in Attendance phones.'
                : 'QR attendance from Ametecs Global is ' . $device->status . ' for you. Ask your administrator.'], 422);
        }
        $device->forceFill(['last_seen_at' => now()])->save();
        $res = $verifier->scan($device, ['qr' => $d['qr'], 'lat' => (float) $d['lat'], 'lng' => (float) $d['lng'], 'accuracy' => (float) $d['accuracy'], 'mocked' => (bool) ($d['mocked'] ?? false), 'type' => $d['type'] ?? null], $r->ip());
        if (!$res['ok']) {
            return response()->json($res + ['message' => self::why($res)], 422);
        }
        /** @var AttendanceEvent $ev */
        $ev = $res['event'];
        return response()->json(['ok' => true, 'type' => $ev->type, 'at' => $ev->recorded_at->timezone('Asia/Kolkata')->format('H:i'), 'office' => $ev->office?->name, 'gate' => $ev->gate?->name]);
    }

    /** QRONEX's own reason for the refusal, in its words. */
    private static function why(array $res): string
    {
        return (string) ($res['reason'] ?? 'The punch was refused.');
    }
}

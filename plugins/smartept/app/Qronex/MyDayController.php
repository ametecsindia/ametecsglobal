<?php

namespace App\Qronex;

use App\Models\Employee;
use App\Services\DayTimeline;
use App\Support\ResolvesLocalNow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 07-Nov-2026 (Ejaz): "My day" card in the EPT QR Attendance app — the employee's own day in one place.
 * GET /api/qronex/v1/my-day (phone device token + signature). Read-only; the figures are the SAME day ledger the
 * Productivity report and the admin day-detail use (DayTimeline), on the company's clock.
 */
class MyDayController extends Controller
{
    use ResolvesLocalNow;

    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        if ($device->status !== 'approved') {
            return response()->json(['message' => 'Your phone must be approved first.', 'reason_code' => 'device_not_approved'], 403);
        }
        // Same rule as the Dashboard tab: EPT information only while punched IN.
        if (\Ametecs\Qronex\Services\AttendanceVerifier::nextType($device->employee_id) !== 'OUT') {
            return response()->json(['message' => 'Punch IN to see your day.', 'reason_code' => 'punched_out'], 403);
        }
        $emp = Employee::withoutGlobalScope('company')->where('company_id', $device->tenant_id)->find($device->employee->host_employee_id);
        abort_unless($emp, 404, 'Employee no longer exists in SmartEPT.');

        return response()->json($this->onCompanyClock((int) $device->tenant_id, function () use ($emp) {
            $date = DayTimeline::currentDate($emp, now()->toDateString());
            $L = DayTimeline::ledger($emp, $date);
            $f = $L ? DayTimeline::figures($emp, $L) : null;
            $d = DayTimeline::build($emp, $date);
            $hm = fn ($t) => $t ? substr($t, 0, 5) : null; // "H:i" on the company clock; the app shows 12-hour

            return [
                'date' => $date,
                'punches' => array_map(fn ($p) => ['in' => $hm($p['in']), 'out' => $hm($p['out'])], $d['door']),
                'sign_ins' => array_map(fn ($s) => ['in' => $hm($s['sign_in']), 'out' => $hm($s['sign_out'])], $d['sessions']),
                'seconds' => [
                    'present' => (int) ($f['present'] ?? 0), 'active' => (int) ($f['work'] ?? 0), 'idle' => (int) ($f['idle'] ?? 0),
                    'away' => (int) ($f['away'] ?? 0), 'break' => (int) ($f['break'] ?? 0), 'meeting' => (int) ($f['meeting'] ?? 0),
                ],
                'break_count' => (int) ($L['break_count'] ?? 0),
                // Taken vs left (Ejaz, 07-Nov): the shift's limits — the same ones the agent's over-limit check and the report use.
                'break_allowed' => (int) ($f['allotted'] ?? 0),
                'break_left' => max(0, (int) ($f['allotted'] ?? 0) - (int) ($f['break'] ?? 0)),
                'break_limits' => $this->limits($emp, $date, $d['breaks']),
                'breaks' => array_map(fn ($b) => ['type' => self::label($b['type']), 'from' => $hm($b['from']), 'to' => $hm($b['to']), 'seconds' => (int) $b['seconds']], $d['breaks']),
                'idle' => array_map(fn ($i) => ['from' => $hm($i['from']), 'to' => $hm($i['to']), 'seconds' => (int) $i['counted_seconds']], $d['idle']),
                'late_minutes' => (int) ($f['late'] ?? 0),
                'productivity' => $f['productivity'] ?? null,
            ];
        }));
    }

    /** Lunch / Tea / Other: limit from the shift and what is used so far. */
    private function limits(Employee $emp, string $date, array $breaks): array
    {
        $sh = $emp->shiftOn($date);
        $out = [];
        foreach (['LUNCH', 'TEA', 'OTHER'] as $type) {
            $used = array_sum(array_map(fn ($b) => (int) $b['seconds'], array_filter($breaks, fn ($b) => ($b['type'] === 'CUSTOM' ? 'OTHER' : $b['type']) === $type)));
            $limit = $sh ? (int) $sh->breakLimitMinutes($type) * 60 : null;
            $out[] = ['type' => self::label($type), 'limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used)];
        }

        return $out;
    }

    private static function label(?string $type): string
    {
        return match ($type) { 'LUNCH' => 'Lunch', 'TEA' => 'Tea', 'OTHER', 'CUSTOM' => 'Other', default => ucfirst(strtolower((string) $type)) ?: 'Break' };
    }
}

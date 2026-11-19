<?php

namespace App\Qronex;

use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Qronex;
use App\Http\Controllers\Controller;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 07-Nov-2026 (Ejaz): "Attendance scanner" in the SmartEPT Agent window opens the entrance QR display of the employee's
 * own office gate, so they can scan it with the EPT QR Attendance app. GET /api/agent/qr-scanner (agent token).
 * Gate = the employee's bound gate, else the first active gate of their assigned office. The phone still checks
 * location against the office geofence, so the screen alone does not let anyone punch from elsewhere.
 */
class AgentScannerController extends Controller
{
    use ResolvesAgentContext;

    public function __invoke(Request $request): JsonResponse
    {
        $emp = $this->agentEmployee($request);
        $tid = (int) $emp->company_id;
        if (! Qronex::enabled($tid)) {
            return response()->json(['message' => 'QR attendance is turned off for your company.'], 404);
        }
        $qx = QxEmployee::withoutGlobalScopes()->where('tenant_id', $tid)->where('host_employee_id', $emp->id)->first();
        if (! $qx || $qx->status !== 'active') {
            return response()->json(['message' => 'You are not set up for QR attendance yet. Ask your administrator to assign your office in QR Attendance.'], 404);
        }
        // 07-Nov-2026 (Ejaz): EVERY gate this employee may punch at — the module's own rule (scanBlockedAt), so the list
        // here and the scan check on the phone can never disagree. The phone then also checks its GPS against that
        // gate's office (geofence, accuracy, mock location) before the punch counts.
        $gates = Gate::withoutGlobalScopes()->where('tenant_id', $tid)->where('is_active', true)
            ->with(['office' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('office_id')->orderBy('id')->get()
            ->filter(fn (Gate $g) => $g->office?->is_active && $qx->scanBlockedAt($g) === null)->values();
        if ($gates->isEmpty()) {
            return response()->json(['message' => 'No office gate is assigned to you yet. Ask your administrator to assign your office in QR Attendance.'], 404);
        }
        $list = $gates->map(fn (Gate $g) => [
            'gate' => $g->name,
            'office' => $g->office?->name,
            'url' => route('qronex.display', ['gate' => $g->id, 'key' => $g->display_secret]),
        ])->all();

        return response()->json(['gates' => $list] + $list[0]); // url / gate / office of the first kept for older agents
    }
}

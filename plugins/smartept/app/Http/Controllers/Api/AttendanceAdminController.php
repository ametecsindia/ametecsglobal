<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Http\Requests\ManualAttendanceRequest;
use App\Models\EmployeeAttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * HR attendance regularization: list the sheet, correct a day, or add a missed
 * day. Every change requires a reason (kept on the row's notes with time +
 * actor) and is audit-logged — corrections feed payroll, so they must be
 * accountable. Tenant isolation comes from the model's company scope.
 */
class AttendanceAdminController extends Controller
{
    use ScopesVisibleEmployees;

    /** GET /api/attendance?date=&employee_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $visible = $this->visibleEmployeeIds($request->user());
        $from = $request->query('from');
        $to = $request->query('to');
        // 04-Nov-2026 (Ejaz): the sheet shows BOTH late figures — from the shift start and after the grace.
        // late_minutes is stored by the "Late from" setting; the other figure is that shift's grace added / taken away
        // (same rule as the late-login email and popup).
        $fromStart = ((\App\Services\MailService::prefs((int) $request->user()->company_id ?: null)['late_login']['count_from'] ?? 'GRACE') === 'START');
        $logs = EmployeeAttendanceLog::with('employee:id,company_id,employee_code,first_name,last_name,shift_id,photo_path,updated_at', 'employee.shift')
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->when($from && $to, fn ($q) => $q->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to))
            ->when(! ($from && $to) && $request->query('date'), fn ($q) => $q->whereDate('work_date', $request->query('date')))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('work_date')->orderBy('employee_id')
            ->paginate((int) $request->integer('per_page', 50));

        $qr = EmployeeAttendanceLog::qrDays($logs->getCollection());
        $lbl = fn ($r, $v, $end = 'in') => EmployeeAttendanceLog::sourceLabel($v, $qr[$r->employee_id.'|'.$r->work_date?->toDateString()][$end] ?? false);
        $logs->getCollection()->transform(fn ($r) => [
            'id'                   => $r->id,
            'employee_id'          => $r->employee_id,
            'employee_name'        => $r->employee?->fullName(),
            'employee_code'        => $r->employee?->employee_code,
            'photo_url'            => \App\Qronex\PhotoController::url($r->employee), // 07-Nov-2026: photo the employee set in the app
            'work_date'            => $r->work_date?->toDateString(),
            'status'               => $r->status,
            'source'               => $lbl($r, $r->source),
            'check_in_at'          => $r->check_in_at?->toDateTimeString(),
            'check_out_at'         => $r->check_out_at?->toDateTimeString(),
            'late_minutes'         => $r->late_minutes,
            ...self::bothLate($r, $fromStart),
            'early_logout_minutes' => $r->early_logout_minutes,
            // QA Phase 3 (B7/B8): how each value was derived (audit — raw stays raw).
            'check_in_source'      => $lbl($r, $r->check_in_source),
            'check_out_source'     => $lbl($r, $r->check_out_source, 'out'),
            'arrival_source_used'  => $r->arrival_source_used,
            'derivation_note'      => $r->derivation_note,
            'notes'                => $r->notes,
        ]);

        return response()->json($logs);
    }

    /** ['late_from_start' => min, 'late_post_grace' => min] for one attendance row. Not late = 0 for both. */
    public static function bothLate(EmployeeAttendanceLog $r, bool $fromStart): array
    {
        return self::bothLateMinutes((int) ($r->late_minutes ?? 0), (int) ($r->employee?->shiftOn($r->work_date)?->grace_minutes ?? 0), $fromStart);
    }

    /** Same rule for any stored late figure + that day's shift grace (also used by the Day / Today Excel). */
    public static function bothLateMinutes(int $m, int $grace, bool $fromStart): array
    {
        if ($m <= 0) {
            return ['late_from_start' => 0, 'late_post_grace' => 0];
        }

        return ['late_from_start' => $fromStart ? $m : $m + $grace, 'late_post_grace' => $fromStart ? max(0, $m - $grace) : $m];
    }

    /** PUT /api/attendance/{attendance} — regularize an existing day. */
    public function update(ManualAttendanceRequest $request, EmployeeAttendanceLog $attendance): JsonResponse
    {
        // QA Phase 6 (B9): validation (incl. no future check-out) lives in the shared
        // ManualAttendanceRequest so PUT and POST enforce the same payroll-safe rules.
        $data = $request->validated();

        $before = $attendance->only(['status', 'source', 'check_in_at', 'check_out_at']);

        $attendance->update([
            'status'       => $data['status'],
            'source'       => 'MANUAL', // the row no longer reflects raw agent/biometric data
            'check_in_at'  => $data['check_in_at'] ?? $attendance->check_in_at,
            'check_out_at' => $data['check_out_at'] ?? $attendance->check_out_at,
            'notes'        => $this->appendNote($attendance->notes, $request, $data['reason']),
        ]);

        $this->audit($request, 'UPDATE', EmployeeAttendanceLog::class, $attendance->id, [
            'before' => $before, 'after' => $data,
        ]);

        return response()->json(['data' => $attendance->fresh()]);
    }

    /** POST /api/attendance — add a missed day (e.g. leave never recorded). */
    public function store(ManualAttendanceRequest $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        // QA Phase 6 (B9): shared rules (tenant-scoped employee, no future check-out, etc.).
        $data = $request->validated();

        // One attendance verdict per employee per day, regardless of source — a second
        // row would double-count in payroll.
        $exists = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $data['employee_id'])
            ->whereDate('work_date', $data['work_date'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'work_date' => 'An attendance record already exists for this employee and date. Use PUT to modify it.',
            ]);
        }

        $log = EmployeeAttendanceLog::create([
            'company_id'   => $companyId,
            'employee_id'  => $data['employee_id'],
            'work_date'    => $data['work_date'],
            'status'       => $data['status'],
            'source'       => 'MANUAL',
            'check_in_at'  => $data['check_in_at'] ?? null,
            'check_out_at' => $data['check_out_at'] ?? null,
            'notes'        => $this->appendNote(null, $request, $data['reason']),
        ]);

        $this->audit($request, 'CREATE', EmployeeAttendanceLog::class, $log->id, $data);

        return response()->json(['data' => $log], 201);
    }

    /** Append "[time] actor: reason" so the row carries its full correction history. */
    private function appendNote(?string $notes, Request $request, string $reason): string
    {
        $line = sprintf('[%s] %s: %s', now()->toDateTimeString(), $request->user()->email, $reason);

        return trim(($notes ? $notes . "\n" : '') . $line);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\ScopesVisibleEmployees;

/**
 * CSV exports (open directly in Excel). XLSX via PhpSpreadsheet is a later add-on; CSV keeps
 * the MVP dependency-free. Every export is audit-logged.
 */
class ExportController extends Controller
{
    use ScopesVisibleEmployees;

    /** GET /api/export/attendance?from=&to= */
    public function attendance(Request $request): StreamedResponse
    {
        $from = $request->query('from', now()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, compact('from', 'to'));

        $visible = $this->visibleEmployeeIds($request->user());
        $rows = EmployeeAttendanceLog::with('employee:id,employee_code,first_name,last_name')
            ->whereBetween('work_date', [$from, $to])
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->orderBy('work_date')->get();

        $qr = EmployeeAttendanceLog::qrDays($rows);
        $lbl = fn ($r, $v, $end = 'in') => EmployeeAttendanceLog::sourceLabel($v, $qr[$r->employee_id.'|'.$r->work_date?->toDateString()][$end] ?? false);

        return $this->stream('attendance.csv',
            ['Employee Code', 'Name', 'Date', 'Source', 'Check In', 'Check In Via', 'Check Out', 'Check Out Via',
                'Late (hh:mm)', 'Arrival Source', 'Early Logout (hh:mm)', 'Status'],
            $rows->map(fn ($r) => [
                $r->employee?->employee_code, $r->employee?->fullName(), $r->work_date?->toDateString(),
                $lbl($r, $r->source), $r->check_in_at, $lbl($r, $r->check_in_source), $r->check_out_at, $lbl($r, $r->check_out_source, 'out'),
                $this->hmFromMinutes($r->late_minutes), $r->arrival_source_used,
                $this->hmFromMinutes($r->early_logout_minutes), $r->status,
            ]));
    }

    /** GET /api/export/productivity?date= or ?from=&to= — per-employee active/idle/break totals. */
    public function productivity(Request $request): StreamedResponse
    {
        // Range support (from/to) with single-day date= kept for backward compatibility.
        [$from, $to] = $this->range($request, now()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeActivityEvent::class, null, compact('from', 'to'));

        // 26-Oct-2026 (Ejaz): the same Working / Idle / Break as the Productivity report (one day ledger) —
        // raw sums double-counted idle during breaks and booked door punch-outs as Break.
        $visible = $this->visibleEmployeeIds($request->user());
        $emps = Employee::with('shift')->where('employment_status', 'ACTIVE')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->get();
        $active = $idle = $break = [];
        foreach (\App\Services\DayTimeline::days($emps, $from, $to) as $d) {
            $id = $d['emp']->id;
            $active[$id] = ($active[$id] ?? 0) + $d['ledger']['sec']['work'];
            $idle[$id] = ($idle[$id] ?? 0) + $d['ledger']['sec']['idle'];
            $break[$id] = ($break[$id] ?? 0) + $d['ledger']['sec']['break'];
        }
        $rows = $emps->map(fn ($e) => [
            $e->employee_code, $e->fullName(),
            $this->hmFromSeconds($active[$e->id] ?? 0),
            $this->hmFromSeconds($idle[$e->id] ?? 0),
            $this->hmFromSeconds($break[$e->id] ?? 0),
        ]);

        return $this->stream('productivity_' . ($from === $to ? $from : "{$from}_{$to}") . '.csv',
            ['Employee Code', 'Name', 'Active (hh:mm)', 'Idle (hh:mm)', 'Break (hh:mm)'], $rows);
    }

    /** GET /api/export/compliance?from=&to= */
    public function compliance(Request $request): StreamedResponse
    {
        $from = $request->query('from', now()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeComplianceEvent::class, null, compact('from', 'to'));

        // started_at is a DATETIME — a bare 'YYYY-MM-DD' upper bound is 00:00:00 and would
        // drop every same-day event (EPT25-03: the CSV came back with only a header). Use
        // inclusive whole-day boundaries so from=to=today returns that day's violations.
        $visible = $this->visibleEmployeeIds($request->user());
        $rows = EmployeeComplianceEvent::with('employee:id,employee_code,first_name,last_name')
            ->whereBetween('started_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->orderBy('started_at')->get();

        return $this->stream('compliance.csv',
            ['Employee Code', 'Name', 'Time', 'Category', 'Type', 'Severity', 'Detected', 'Action'],
            $rows->map(fn ($r) => [
                $r->employee?->employee_code, $r->employee?->fullName(), $r->started_at,
                $r->event_category, $r->event_type, $r->severity, $r->detected_value, $r->action_taken,
            ]));
    }

    /** GET /api/export/daily-summary?date= or ?from=&to= — computed scores + totals per employee. */
    public function dailySummary(Request $request): StreamedResponse
    {
        // Range support (from/to) with single-day date= kept for backward compatibility.
        [$from, $to] = $this->range($request, now()->subDay()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeDailySummary::class, null, compact('from', 'to'));

        // 26-Oct-2026 (Ejaz): Active / Idle / Break / Late / Productivity are the Productivity report's own
        // figures (the one day ledger) — never a second calculation. Compliance stays the stored daily score.
        $visible = $this->visibleEmployeeIds($request->user());
        $employees = \App\Models\Employee::with('shift')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->get();
        $compliance = EmployeeDailySummary::whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
            ->get(['employee_id', 'work_date', 'compliance_score'])
            ->mapWithKeys(fn ($r) => [$r->employee_id . '|' . $r->work_date->toDateString() => $r->compliance_score]);
        $days = \App\Services\DayTimeline::days($employees, $from, $to);
        uasort($days, fn ($a, $b) => [$a['date'], $a['emp']->employee_code] <=> [$b['date'], $b['emp']->employee_code]);

        return $this->stream('daily_summary_' . ($from === $to ? $from : "{$from}_{$to}") . '.csv',
            ['Employee Code', 'Name', 'Date', 'Active (hh:mm)', 'Idle (hh:mm)', 'Break (hh:mm)', 'Late (hh:mm)', 'Violations', 'Productivity', 'Compliance'],
            collect($days)->map(function ($d, $k) use ($compliance) {
                $f = \App\Services\DayTimeline::figures($d['emp'], $d['ledger']);

                return [
                    $d['emp']->employee_code, $d['emp']->fullName(), $d['date'],
                    $this->hmFromSeconds($f['work']), $this->hmFromSeconds($f['idle']), $this->hmFromSeconds($f['break']),
                    $this->hmFromMinutes($f['late']), $d['ledger']['violations'], $f['productivity'] ?? '', $compliance[$k] ?? '',
                ];
            })->values());
    }

    /**
     * GET /api/export/audit-logs — B14: the FULL filtered audit trail as CSV (not just
     * the current page). Respects every viewer filter (from/to/action/user/subject/ip),
     * streams via chunkById so a large range never buffers in memory, neutralises CSV
     * formula-injection, redacts secret-looking values, and is itself audit-logged.
     */
    public function auditLogs(Request $request): StreamedResponse
    {
        $user = $request->user();
        $tz = $user->company?->timezone ?: config('app.timezone', 'UTC');
        [$from, $to] = $this->auditBounds($request, $tz);

        // Audit WHO exported WHAT (filters), before streaming.
        $this->audit($request, 'EXPORT', AuditLog::class, null, [
            'filters' => $request->only(['from', 'to', 'action', 'user_id', 'subject_type', 'ip']),
        ]);

        $query = AuditLog::query()->with('user:id,name,email')
            ->when($user->company_id, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('company_id', $user->company_id)
                ->orWhereIn('user_id', User::where('company_id', $user->company_id)->pluck('id'))))
            ->when($request->action, fn ($q, $v) => $q->where('action', 'like', '%' . $v . '%'))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('subject_type'), fn ($q, $v) => $q->where('subject_type', 'like', '%' . $v . '%'))
            ->when($request->query('ip'), fn ($q, $v) => $q->where('ip', $v))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $header = ['Time', 'Timezone', 'Action', 'Actor', 'Actor Email', 'Subject Type', 'Subject Id', 'IP', 'Details'];

        return response()->streamDownload(function () use ($query, $tz, $header) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            $query->chunkById(1000, function ($rows) use ($out, $tz) {
                foreach ($rows as $r) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        optional($r->created_at)->toDateTimeString(),
                        $tz,
                        $r->action,
                        $r->user?->name,
                        $r->user?->email,
                        $r->subject_type,
                        $r->subject_id,
                        $r->ip,
                        $this->redactChanges($r->changes),
                    ]));
                }
            });
            fclose($out);
        }, 'audit-logs.csv', ['Content-Type' => 'text/csv']);
    }

    /** B13/B14: parse from/to as org-tz datetimes; a bare date spans the whole local day. */
    private function auditBounds(Request $request, string $tz): array
    {
        $parse = function ($v, bool $isEnd) use ($tz) {
            if (! $v) {
                return null;
            }
            try {
                $c = Carbon::parse($v, $tz);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $v))) {
                    return $isEnd ? $c->endOfDay() : $c->startOfDay();
                }
                return $c;
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [$parse($request->query('from'), false), $parse($request->query('to'), true)];
    }

    /** B14: neutralise a leading = + - @ TAB CR so a cell can't run as a spreadsheet formula. */
    private function csvSafe($v): string
    {
        if ($v === null) {
            return '';
        }
        $s = (string) $v;
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /** B14: never leak secrets — blank out values for sensitive-looking keys in the changes blob. */
    private function redactChanges($changes): string
    {
        if (empty($changes)) {
            return '';
        }
        $redact = function ($v) use (&$redact) {
            if (is_array($v)) {
                $out = [];
                foreach ($v as $k => $val) {
                    $out[$k] = preg_match('/pass|token|secret|hash|api[_-]?key|password/i', (string) $k)
                        ? '***' : $redact($val);
                }
                return $out;
            }
            return $v;
        };

        return (string) json_encode($redact($changes));
    }

    /** R4 item 6: durations in reports as hh:mm, not raw minutes/decimal hours. */
    private function hmFromMinutes($minutes): string
    {
        $m = max(0, (int) $minutes);
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    private function hmFromSeconds($seconds): string
    {
        return $this->hmFromMinutes((int) round(((int) $seconds) / 60));
    }

    /** Resolve [from, to]: explicit from/to range, else the legacy single date= param. */
    private function range(Request $request, string $default): array
    {
        $date = $request->query('date', $default);
        $from = $request->query('from', $date);
        $to = $request->query('to', $request->query('from') ? $from : $date);

        return [$from, max($from, $to)];
    }

    private function stream(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_null($v) ? '' : (string) $v, (array) $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

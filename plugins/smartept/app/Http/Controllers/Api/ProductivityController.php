<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Support\ResolvesBusinessDay;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeeMeetingSession;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeWebsiteUsageLog;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * All-employee, day-wise PRODUCTIVITY report (Ejaz 17-Jul). One row per
 * employee per day: login/logout, working (active) time, idle, breaks (count +
 * time), time-outs, non-productive time, violations and a productivity score —
 * over any from→to range (or week / month), CSV + PDF from the console.
 *
 * 26-Oct-2026: every day (today and past) is measured from the raw records by the one day
 * ledger, App\Services\DayTimeline — the nightly summaries are no longer read here.
 */
use App\Services\ScoringService;

class ProductivityController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesBusinessDay;

    /** GET /api/reports/productivity — the day-wise productivity report (JSON). */
    public function report(Request $request): JsonResponse
    {
        [$fromStr, $toStr, $rows] = $this->buildReport($request);

        return response()->json([
            'from' => $fromStr, 'to' => $toStr,
            'count' => count($rows), 'data' => $rows,
        ]);
    }

    /**
     * GET /api/reports/productivity/detail?employee_id=&date= — 29-Sep-2026 (Ejaz): the "+"
     * row. Every door IN/OUT, PC sign-in, Idle spell (and when Active resumed), Away (and when
     * they were back at the desk) and Break for one employee on one day, with totals.
     */
    public function detail(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $this->assertEmployeeVisible($request, (int) $data['employee_id']);
        $emp = Employee::where('company_id', $request->user()->company_id)->findOrFail($data['employee_id']);

        return response()->json(['data' => \App\Services\DayTimeline::build($emp, $data['date'])]);
    }

    /**
     * Build the day-wise productivity rows for a range. Shared by the JSON report(), the Excel export
     * and the scheduled emails, so the screen, the .xlsx and the email are guaranteed identical.
     * 26-Oct-2026 (Ejaz): every row — today AND past days — is measured from the raw records by the ONE
     * day ledger (DayTimeline): no stale nightly totals mixed with fresh ones, nothing counted twice,
     * night shifts as one shift-day. Returns [fromDate, toDate, rows[]].
     */
    private function buildReport(Request $request): array
    {
        $companyId = $request->user()->company_id;
        $today = $this->bizToday($this->bizTz($request));
        $fromDate = $request->query('from', $today);      // local Y-m-d
        $toDate = $request->query('to', $today);
        $empId = $request->query('employee_id');

        $visible = $this->scopedEmployeeIds($request);
        $employees = Employee::where('company_id', $companyId)
            ->when($empId, fn ($q) => $q->where('id', $empId))
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->with(['department:id,name', 'team:id,name', 'reportingManager:id,name', 'shift'])
            ->get();

        $rows = [];
        foreach (\App\Services\DayTimeline::days($employees, $fromDate, $toDate) as $day) {
            $rows[] = $this->row($day['emp'], $day['date'], $day['ledger']);
        }

        // Sort: newest date first, then employee name.
        usort($rows, fn ($a, $b) => [$b['work_date'], $a['name']] <=> [$a['work_date'], $b['name']]);

        return [$fromDate, $toDate, $rows];
    }

    /**
     * GET /api/export/productivity-report — the SAME day-wise productivity report as a real .xlsx,
     * with the client's exact template column headers and Excel-safe cell formats:
     *   durations as [h]:mm, clock times as h:mm, Productive% as a 0% percentage, Date as a date —
     * so nothing is coerced into a wrong type. Needs phpoffice/phpspreadsheet (composer).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            abort(503, 'Excel export needs the PhpSpreadsheet library. In the app folder run:  composer require phpoffice/phpspreadsheet');
        }

        [$fromStr, $toStr, $rows] = $this->buildReport($request);
        $this->audit($request, 'EXPORT', null, null, [
            'report' => 'productivity', 'format' => 'xlsx', 'from' => $fromStr, 'to' => $toStr, 'rows' => count($rows),
        ]);

        $ss = $this->spreadsheet($rows);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $fname = 'SmartEPT-Productivity-Report-' . $fromStr . '_' . $toStr . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fname, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * 30-Sep-2026: the report rows for a (possibly synthetic) request — Reports → Schedule Report
     * builds the emailed report from exactly the same code as the screen and the .xlsx.
     */
    public function rowsFor(Request $request): array
    {
        return $this->buildReport($request);
    }

    /** The .xlsx workbook for a set of report rows (the export and the scheduled emails share it). */
    public function spreadsheet(array $rows): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // Same column order as the client's Productivity template (A–S). 23-Oct-2026 (Ejaz): every
        // title now states EXACTLY how its value is calculated — no title may describe a different
        // formula than the code uses. Keep in step with prCSV() / the screen in admin.blade.php.
        $headers = [
            'Emp. ID', 'Employee', 'Department', 'Reporting Manager', 'Date',
            'Logged in', 'Logged out', 'Actual Present Hrs (Logged out − Gate IN or Logged in, whichever is earlier)',
            'Working (hh:mm)', 'Idle (hh:mm)', 'Number of Breaks', 'Break time Availed (hh:mm)',
            'Allotted break (hh:mm)', 'Meeting Time (hh:mm)', 'Break Exceed (hh:mm) (Break time Availed − Allotted break)',
            'Productive Hrs (Working + Meeting + Gate to PC up to 10 min)', 'Non Productive Hrs (Idle + Break Exceed + Away + Gate to PC beyond 10 min)',
            'Net Hrs (Actual Present Hrs − Break time Availed up to Allotted break)', 'Productive% [Productive Hrs ÷ Net Hrs]',
            // Columns T onward are OURS, not the client template's, so A–S still line up 1:1 with
            // their sheet. T added 26-Aug (Ejaz): minutes past the shift start. U names why a row
            // was repaired. (29-Sep-2026: the Unaccounted column is gone — every window is now
            // Working, Idle, Break or Away.)
            'Late Login (hh:mm) — Gate IN, or Logged in if no Gate IN, after shift start', 'Data Issue',
            // V–W added 23-Sep-2026 (Ejaz): the door punch and the gate-to-desk time.
            'Gate IN', 'Gate to PC (hh:mm) — door IN to first PC sign-in, excluding time out of the door; first 10 min Productive, rest Non Productive',
            // X: punched / signed out without a break and back in later — non-productive.
            'Away (hh:mm) — out of the door or signed out without a break',
            // Y (13-Oct-2026): from the W.OFF & Holiday Schedule.
            'Day type',
        ];

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Productivity');

        foreach ($headers as $i => $h) {
            $sheet->setCellValue($this->col($i + 1) . '1', $h);
        }
        $sheet->getStyle('A1:Y1')->getFont()->setBold(true);
        $sheet->getStyle('A1:Y1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E3F4F7');
        $sheet->getStyle('A1:Y1')->getAlignment()->setWrapText(true);

        $DUR = '[h]:mm';   // duration — Excel-compatible, valid past 24h, never a date
        $CLK = 'h:mm';     // clock time for login/logout

        $r = 2;
        foreach ($rows as $x) {
            $sheet->setCellValueExplicit('A' . $r, (string) ($x['employee_code'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $r, $x['name'] ?? '');
            $sheet->setCellValue('C' . $r, $x['department'] ?? '');
            $sheet->setCellValue('D' . $r, $x['reporting_manager'] ?? '');
            // The date cell is built from a DateTime, not strtotime() on a concatenated
            // string: the old form silently produced boolean FALSE in every Date cell the
            // moment work_date arrived carrying a time component (26-Aug-2026). If a date
            // ever fails to parse, write the text rather than a stray FALSE.
            if (! empty($x['work_date'])) {
                try {
                    $sheet->setCellValue('E' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(
                        new \DateTime(Carbon::parse($x['work_date'])->toDateString() . ' 00:00:00')
                    ));
                    $this->fmt($sheet, 5, $r, 'mm-dd-yy');
                } catch (\Throwable $e) {
                    $sheet->setCellValueExplicit('E' . $r, (string) $x['work_date'],
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            $this->putTime($sheet, 6, $r, $x['first_in'] ?? null, $CLK);
            $this->putTime($sheet, 7, $r, $x['last_out'] ?? null, $CLK);
            $this->putDur($sheet, 8,  $r, $x['present_seconds'] ?? 0, $DUR);        // Actual Present Hrs
            $this->putDur($sheet, 9,  $r, $x['work_seconds'] ?? 0, $DUR);           // Working
            $this->putDur($sheet, 10, $r, $x['idle_seconds'] ?? 0, $DUR);           // Idle
            $sheet->setCellValue('K' . $r, (int) ($x['break_count'] ?? 0));         // Number of Breaks
            $this->fmt($sheet, 11, $r, '0');
            $this->putDur($sheet, 12, $r, $x['break_seconds'] ?? 0, $DUR);          // Break time Availed
            $this->putDur($sheet, 13, $r, $x['allotted_break_seconds'] ?? 0, $DUR); // Allotted break
            $this->putDur($sheet, 14, $r, $x['meeting_seconds'] ?? 0, $DUR);        // Meeting Time
            $this->putDur($sheet, 15, $r, $x['break_exceed_seconds'] ?? 0, $DUR);   // Break Exceed
            $this->putDur($sheet, 16, $r, $x['productive_seconds'] ?? 0, $DUR);     // Productive Hrs
            $this->putDur($sheet, 17, $r, $x['non_productive_seconds'] ?? 0, $DUR); // Non Productive Hrs
            $this->putDur($sheet, 18, $r, $x['net_working_seconds'] ?? 0, $DUR);    // Net Hrs
            if ($x['productivity'] !== null) {                                       // Productive% (real 0% cell)
                $sheet->setCellValue('S' . $r, round(((float) $x['productivity']) / 100, 4));
                $this->fmt($sheet, 19, $r, '0%');
            }
            $this->putDur($sheet, 20, $r, 60 * (int) ($x['late_minutes'] ?? 0), $DUR); // Late Login (hh:mm) — 31-Oct-2026
            if (! empty($x['data_issue_text'])) {                                   // Data Issue (ours, col U)
                $sheet->setCellValueExplicit('U' . $r, (string) $x['data_issue_text'],
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $this->putTime($sheet, 22, $r, $x['gate_in'] ?? null, $CLK);                  // Gate IN (V)
            $this->putDur($sheet, 23, $r, 60 * (int) ($x['gate_to_pc_minutes'] ?? 0), $DUR); // Gate to PC (W, hh:mm)
            $this->putDur($sheet, 24, $r, $x['away_seconds'] ?? 0, $DUR);                // Away (X)
            $sheet->setCellValue('Y' . $r, ['WEEKLY_OFF' => 'Weekly off', 'HOLIDAY' => 'Holiday'][$x['day_type'] ?? ''] ?? 'Working day'); // Y
            // 08-Oct-2026 (Ejaz): red font for out-of-limit values — same limits as the screen/PDF (PR_RULES).
            $red = [
                'O' => ($x['break_exceed_seconds'] ?? 0) > 5 * 60,                               // break exceed > 5 min
                'S' => $x['productivity'] !== null && (float) $x['productivity'] < 60,           // productivity < 60%
                'T' => ($x['late_minutes'] ?? 0) > 0,                                            // any late login (shift grace already applied)
            ];
            foreach (array_keys(array_filter($red)) as $c) {
                $sheet->getStyle($c . $r)->getFont()->setBold(true)->getColor()->setRGB('D92D20');
            }
            $r++;
        }

        foreach (range(1, 25) as $c) {
            $sheet->getColumnDimension($this->col($c))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        // 08-Oct-2026 (Ejaz): filter + sort arrows on every column header (export and scheduled emails).
        $sheet->setAutoFilter('A1:Y' . max(1, $r - 1));

        return $ss;
    }

    /** 1-based column index → column letter (A, B, …, S). */
    private function col(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    /** Apply a number format to one cell (col is 1-based). */
    private function fmt($sheet, int $col, int $row, string $code): void
    {
        $sheet->getStyle($this->col($col) . $row)->getNumberFormat()->setFormatCode($code);
    }

    /** Write a clock time (H:i) as an Excel time value; blank when null. */
    private function putTime($sheet, int $col, int $row, $hi, string $fmt): void
    {
        if (! $hi) {
            return;
        }
        [$h, $m] = array_pad(explode(':', (string) $hi), 2, '0');
        $sheet->setCellValue($this->col($col) . $row, ((int) $h * 3600 + (int) $m * 60) / 86400);
        $this->fmt($sheet, $col, $row, $fmt);
    }

    /** Write a duration (seconds) as an Excel day-fraction with a duration format; never a date. */
    private function putDur($sheet, int $col, int $row, $seconds, string $fmt): void
    {
        $sheet->setCellValue($this->col($col) . $row, ((int) $seconds) / 86400);
        $this->fmt($sheet, $col, $row, $fmt);
    }

    /**
     * POST /api/reports/productivity/rebuild?from=&to= — backfill any MISSING daily summaries for
     * the range on demand. The nightly smartept:daily-summary only runs at 00:30; when the OS
     * scheduler is not running, past days never get summarised and the report shows only today.
     * This rebuilds them synchronously (idempotent, role-scoped) so history appears immediately.
     */
    public function rebuildSummaries(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $fromDate = $request->query('from', $today);
        $toDate = $request->query('to', $today);

        $start = Carbon::parse($fromDate);
        $end = Carbon::parse($toDate);
        abort_if($start->diffInDays($end) > 62, 422, 'Please rebuild at most about two months at a time.');

        // ?force=1 RECOMPUTES days that already have a summary — needed after a calculation
        // fix so historical rows pick up the corrected first-login / duration logic. It is
        // non-destructive: buildSummary recomputes in place for THIS range only (never a
        // blanket regeneration of production history).
        $force = $request->boolean('force');

        $visible = $this->scopedEmployeeIds($request);
        $employees = Employee::where('company_id', $companyId)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->where('employment_status', 'ACTIVE')
            ->with('shift')->get();

        // Days already summarised -> skip (keep the rebuild cheap + idempotent) UNLESS forced.
        $existing = $force ? collect() : EmployeeDailySummary::where('company_id', $companyId)
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->whereBetween('work_date', [$fromDate, $toDate])
            ->get(['employee_id', 'work_date'])
            ->mapWithKeys(fn ($r) => [$r->employee_id . '|' . $r->work_date->toDateString() => true]);

        $scoring = app(ScoringService::class);
        $calendar = app(\App\Services\WorkCalendar::class);
        $built = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $ds = $d->toDateString();
            if ($ds >= $today) {
                continue; // today + future are computed live by the report itself
            }
            foreach ($employees as $emp) {
                if (isset($existing[$emp->id . '|' . $ds])) {
                    continue;
                }
                // Build working days, or any day the employee actually has an attendance row.
                if (! $calendar->isWorkingDay($emp, $ds)
                    && ! EmployeeAttendanceLog::where('employee_id', $emp->id)->where('work_date', $ds)->exists()) {
                    continue;
                }
                $scoring->buildSummary($emp, $ds);
                $built++;
            }
        }

        $this->audit($request, 'PRODUCTIVITY_REBUILD', null, null, [
            'from' => $fromDate, 'to' => $toDate, 'built' => $built, 'force' => $force,
        ]);

        return response()->json(['ok' => true, 'built' => $built, 'forced' => $force,
            'message' => $built > 0
                ? ($built . ' day-summaries ' . ($force ? 'recomputed' : 'rebuilt') . ' for ' . $fromDate . ' to ' . $toDate . '.')
                : 'Nothing to rebuild — those days are already summarised, or have no attendance yet.']);
    }

    private ?\App\Services\WorkCalendar $calendar = null;

    /**
     * One productivity row = the day ledger's figures (DayTimeline::figures — each formula IS the
     * column title) plus the display fields. Productive + Non-Productive = Net Hrs on every row.
     */
    private function row(Employee $emp, string $date, array $L): array
    {
        $f = \App\Services\DayTimeline::figures($emp, $L);
        $sh = $emp->shiftOn($date);
        $hm = fn ($ts) => $ts === null ? null : date('H:i', $ts);
        $issue = $this->describeIssue($L);
        $full = \App\Services\ScoringService::allottedBreakSeconds($sh, PHP_INT_MAX >> 8);
        $exp = \App\Services\ScoringService::shiftExpectedSeconds($sh);
        $dur = fn (int $s) => sprintf('%dh%02dm', intdiv($s, 3600), intdiv($s % 3600, 60));

        return [
            'work_date' => $date,
            // 13-Oct-2026: Working day / Weekly off / Holiday (W.OFF & Holiday Schedule) — Excel column Y + screen tag.
            'day_type' => ($this->calendar ??= app(\App\Services\WorkCalendar::class))->isHoliday((int) $emp->company_id, $date) ? 'HOLIDAY'
                : ($this->calendar->isShiftDay($emp, $date) ? 'WORKING' : 'WEEKLY_OFF'),
            'employee_id' => $emp->id, // 29-Sep-2026: for the "+" detail row
            'employee_code' => $emp->employee_code,
            'name' => trim($emp->first_name . ' ' . $emp->last_name),
            'department' => $emp->department?->name,
            'team' => $emp->team?->name,
            'reporting_manager' => $emp->reportingManager?->name,
            // Logged in = first PC sign-in (blank when only the door punch exists).
            'first_in' => $hm($L['pc_in']),
            'gate_in' => $hm($L['gate_in']),
            'gate_to_pc_seconds' => $f['gate_to_pc'],
            'gate_to_pc_minutes' => (int) round($f['gate_to_pc'] / 60),
            'gate_to_pc_productive_seconds' => $f['gate_to_pc_ok'],     // first 10 min → in Productive Hrs
            'gate_to_pc_exceed_seconds' => $f['gate_to_pc_exceed'],     // beyond 10 min → in Non Productive Hrs
            'last_out' => $L['live'] ? $hm($L['end']) : $hm($L['last_out']),
            'last_out_live' => $L['live'], // "now" tag only while still signed in
            'still_in_shift' => $this->stillInShift($emp, $date, $L['live']),
            'present_seconds' => $f['present'],
            'clock_gap_seconds' => $L['clock_gap'],
            // Always 0 — every second of the day has one status (kept: the console and exports read it).
            'unaccounted_seconds' => 0,
            // The signed-in stretches the agent sent nothing for (counted as Idle), named in Data Issue.
            'unaccounted_gaps' => array_map(fn ($g) => ['from' => date('H:i', $g[0]), 'to' => date('H:i', $g[1]),
                'seconds' => $g[1] - $g[0], 'cause' => $this->gapCause($g)], $L['gaps']),
            'late_minutes' => $f['late'],
            'data_issue' => $issue['code'],
            'data_issue_text' => $issue['text'],
            'away_seconds' => $f['away'],
            'work_seconds' => $f['work'],
            'idle_seconds' => $f['idle'],
            'break_seconds' => $f['break'],
            'break_count' => $L['break_count'],
            'allotted_break_seconds' => $f['allotted'],
            'allotted_break_basis' => ($sh?->name ?? 'No shift') . ': Break allowed ' . \App\Services\ScheduledReports::hm($full)
                . ($f['allotted'] < $full ? ' × ' . $dur($f['allot_basis']) . ' present ÷ ' . $dur($exp) . ' shift (pro rata)' : ' (full shift)'),
            'break_exceed_seconds' => $f['exceed'],
            'meeting_seconds' => $f['meeting'],
            'productive_seconds' => $f['productive'],
            'non_productive_seconds' => $f['non_productive'],
            'timeouts' => $L['timeouts'],
            'net_working_seconds' => $f['net'],
            'violations' => $L['violations'],
            'productivity' => $f['productivity'],
            'live' => $L['live'],
        ];
    }

    /** Plain-English note on why a row's day end is not simply the sign-out, and any no-data stretches. */
    private function describeIssue(array $L): array
    {
        $hm = fn (int $s) => sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
        $issue = match ($L['end_reason']) {
            'NO_SIGN_OUT_CAPPED' => ['code' => 'NO_SIGN_OUT_CAPPED', 'text' => 'No sign-out recorded — present time capped at shift end ('
                . $hm($L['present']) . '); tracking continued past the shift and was not credited'],
            'NO_SIGN_OUT' => ['code' => 'NO_SIGN_OUT', 'text' => 'No sign-out recorded — present time taken from tracked activity'],
            'ACTIVITY_AFTER_SIGN_OUT' => ['code' => 'ACTIVITY_AFTER_SIGN_OUT', 'text' => 'Tracked activity exceeds the sign-in→sign-out span by '
                . $hm($L['clock_gap']) . ' — present time runs to the last activity (agent kept running after sign-out)'],
            default => ['code' => null, 'text' => null],
        };
        if ($L['gaps']) {   // signed in, nothing from the agent — counted as Idle, but worth a look
            $note = implode('; ', array_map(fn ($g) => date('H:i', $g[0]) . '–' . date('H:i', $g[1]) . ' (' . $hm($g[1] - $g[0])
                . ') ' . $this->gapCause($g), $L['gaps']));
            $issue = $issue['code'] === null ? ['code' => 'NO_AGENT_DATA', 'text' => $note] : ['code' => $issue['code'], 'text' => $issue['text'] . ' — ' . $note];
        }

        return $issue;
    }

    /** Why a signed-in stretch has no agent data — a live tail may just be data still on its way. */
    private function gapCause(array $g): string
    {
        return ! empty($g[2])
            ? 'nothing received from the agent since ' . date('g:i A', $g[0]) . ' — counted as Idle (agent closed / PC off, or data still pending on the agent)'
            : 'signed in, no activity from the agent (PC off / agent closed) — counted as Idle';
    }

    /** 14-Oct-2026 (Ejaz): signed in, not signed out, and today's shift has not ended yet. */
    private function stillInShift(Employee $emp, string $date, bool $openToday): bool
    {
        $end = $openToday ? \App\Services\DayTimeline::shiftEnd($emp->shiftOn($date), $date) : null;

        return $end !== null && now()->getTimestamp() < $end;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDailySummary;
use App\Services\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\ScopesVisibleEmployees;

/**
 * Monthly payroll pack: the per-employee month summary payroll runs on, plus the
 * classic attendance-register CSV matrix (one letter per employee per day).
 *
 * Payable days = present + 0.5 * half_day + on_leave (paid leave); weekly offs and
 * holidays are not counted as payable here — most Indian payrolls add them
 * separately as paid non-working days.
 */
class MonthlyReportController extends Controller
{
    use ScopesVisibleEmployees;

    /** Attendance status → register letter. MISMATCH still carries presence evidence → P. */
    private const STATUS_LETTERS = [
        'PRESENT' => 'P', 'ABSENT' => 'A', 'HALF_DAY' => 'H', 'ON_LEAVE' => 'L', 'MISMATCH' => 'P',
    ];

    /** When a date somehow has rows from several sources, the most favourable verdict wins. */
    private const STATUS_PRECEDENCE = ['PRESENT', 'HALF_DAY', 'ON_LEAVE', 'MISMATCH', 'ABSENT'];

    /** GET /api/reports/monthly-summary?month=YYYY-MM */
    public function summary(Request $request, WorkCalendar $calendar): JsonResponse
    {
        [$start, $end] = $this->monthRange($request);

        $employees = Employee::with(['shift', 'team:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();

        $statusByEmployee = $this->statusesByEmployeeAndDate($start, $end);

        // 26-Oct-2026 (Ejaz): the month's Working, Late and Productivity come from the SAME day ledger and
        // formula as the Productivity report — Productivity = month's Productive Hrs ÷ month's Net Hrs
        // (weighted by hours, not an average of daily percentages).
        $totals = [];
        $lastDay = min($end->toDateString(), now()->toDateString());
        if ($start->toDateString() <= $lastDay) {
            foreach (\App\Services\DayTimeline::days($employees, $start->toDateString(), $lastDay) as $day) {
                $f = \App\Services\DayTimeline::figures($day['emp'], $day['ledger']);
                $t = &$totals[$day['emp']->id];
                $t['work'] = ($t['work'] ?? 0) + $f['work'];
                $t['late'] = ($t['late'] ?? 0) + $f['late'];
                $t['prod'] = ($t['prod'] ?? 0) + $f['productive'];
                $t['net'] = ($t['net'] ?? 0) + $f['net'];
                unset($t);
            }
        }

        $rows = $employees->map(function ($e) use ($calendar, $start, $end, $statusByEmployee, $totals) {
            $counts = $this->statusCounts($statusByEmployee[$e->id] ?? []);
            $workingDays = 0;
            $holidays = 0;
            foreach ($start->toPeriod($end) as $day) {
                $date = $day->toDateString();
                if ($calendar->isHoliday($e->company_id, $date)) {
                    $holidays++;
                } elseif ($calendar->isShiftDay($e, $date)) {
                    $workingDays++;
                }
            }

            $t = $totals[$e->id] ?? null;

            return [
                'employee_id'            => $e->id,
                'employee_code'          => $e->employee_code,
                'name'                   => $e->fullName(),
                'team'                   => $e->team?->name,
                'working_days_in_month'  => $workingDays,
                'present'                => $counts['PRESENT'],
                'absent'                 => $counts['ABSENT'],
                'half_day'               => $counts['HALF_DAY'],
                'on_leave'               => $counts['ON_LEAVE'],
                'holidays_count'         => $holidays,
                'total_active_seconds'   => (int) ($t['work'] ?? 0),
                'total_late_minutes'     => (int) ($t['late'] ?? 0),
                'avg_productivity_score' => ! empty($t['net']) ? round($t['prod'] / $t['net'] * 100, 1) : null,
                'payable_days'           => $counts['PRESENT'] + 0.5 * $counts['HALF_DAY'] + $counts['ON_LEAVE'],
            ];
        });

        return response()->json(['month' => $start->format('Y-m'), 'data' => $rows]);
    }

    /** 07-Oct-2026 (Ejaz): register letters, with the legend printed under the Excel sheet. */
    private const LEGEND = [
        'P' => 'Present', 'A' => 'Absent', 'H' => 'Half day', 'L' => 'Leave',
        'WOFF' => 'Weekly off', 'HOL' => 'Holiday', '-' => 'No record / future / not yet joined',
    ];

    /**
     * GET /api/export/attendance-register?month=YYYY-MM  — or ?from=Y-m-d&to=Y-m-d (max 93 days)
     * &format=xlsx for Excel (07-Oct-2026: the Attendance tab's Export), CSV otherwise.
     * One row per employee with their details, one column per date (P/A/H/L, WOFF, HOL), then totals.
     */
    public function attendanceRegister(Request $request, WorkCalendar $calendar)
    {
        [$start, $end] = $this->registerRange($request);
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, ['register_from' => $start->toDateString(), 'register_to' => $end->toDateString()]);

        $employees = Employee::with(['shift', 'department', 'designation', 'branch'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();
        $statusByEmployee = $this->statusesByEmployeeAndDate($start, $end);
        $today = now()->toDateString();
        $days = iterator_to_array($start->toPeriod($end));

        // 10-Oct-2026 (Ejaz): Team and Date of Joining removed; day headings on two lines keep columns narrow.
        $totalsHead = ['Present', 'Absent', 'Half Day', 'Leave', 'Weekly Off', 'Holiday', 'Payable Days'];
        $header = array_merge(
            ['Employee Code', 'Name', 'Branch', 'Department', 'Designation', 'Shift'],
            array_map(fn ($d) => $d->format('d-M') . "\n" . $d->format('D'), $days),
            $totalsHead
        );

        $rows = $employees->map(function ($e) use ($calendar, $days, $statusByEmployee, $today) {
            $letters = [];
            foreach ($days as $day) {
                $letters[] = $this->dayLetter($calendar, $e, $day->toDateString(), $statusByEmployee[$e->id] ?? [], $today);
            }

            $tally = array_count_values($letters);
            $p = $tally['P'] ?? 0;
            $h = $tally['H'] ?? 0;
            $l = $tally['L'] ?? 0;

            return array_merge(
                [$e->employee_code, $e->fullName(), $e->branch?->name, $e->department?->name, $e->designation?->name,
                 $e->shift?->name],
                $letters,
                [$p, $tally['A'] ?? 0, $h, $l, $tally['WOFF'] ?? 0, $tally['HOL'] ?? 0, $p + 0.5 * $h + $l]
            );
        })->all();

        $name = 'attendance_' . $start->toDateString() . '_' . $end->toDateString();
        if ($request->query('format') === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return $this->registerXlsx($request, $name . '.xlsx', $header, $rows, 6, count($days), $start, $end);
        }

        return $this->stream($name . '.csv', $header, $rows);
    }

    /** The register as a formatted Excel sheet: section row, single borders, coloured day codes, legend. */
    private function registerXlsx(Request $request, string $filename, array $header, array $rows, int $detailCols, int $dayCols, Carbon $start, Carbon $end): StreamedResponse
    {
        $colors = ['P' => 'D1FAE5', 'A' => 'FEE2E2', 'H' => 'FEF3C7', 'L' => 'E0E7FF', 'WOFF' => 'E5E7EB', 'HOL' => 'DBEAFE'];
        $col = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $fills = [];
        foreach ($rows as $i => $row) {
            for ($c = $detailCols + 1; $c <= $detailCols + $dayCols; $c++) {
                $v = (string) ($row[$c - 1] ?? '');
                if (isset($colors[$v])) {
                    $fills[$col($c) . ($i + 6)] = $colors[$v];
                }
            }
        }
        $sections = [['Employee details', 1, $detailCols, '1F3A5F'],
            ['Daily attendance', $detailCols + 1, $detailCols + $dayCols, '0F766E'],
            ['Summary', $detailCols + $dayCols + 1, count($header), '6D28D9']];
        $notes = ['Legend:'];
        foreach (self::LEGEND as $code => $label) {
            $notes[] = [$code, $label, $colors[$code] ?? null];
        }
        $notes[] = 'Payable Days = Present + 0.5 × Half Day + Leave. Weekly offs and holidays are listed separately.';
        $notes[] = 'Half Day = present, but the time worked (app sign-in sessions; door punch span when there are none) was below half of the shift\'s Minimum working hours (blank = shift length).';

        return $this->sheet($request, $filename, 'Attendance Register', $start->format('d M Y') . ' to ' . $end->format('d M Y'),
            $header, $rows, $sections, $fills, $detailCols, $notes);
    }

    /**
     * 10-Oct-2026 (Ejaz): DAY report — one date only, one row per employee:
     * Gate IN, Sign IN, Late (min), Gate OUT, Sign OUT and the overall present time.
     * Overall present = last gate punch-out − first gate punch-in; where the company has no
     * biometric gate, the app's last sign-out − first sign-in. Blank until the day has an "out".
     * GET /api/export/attendance-day?date=Y-m-d
     */
    public function attendanceDay(Request $request, WorkCalendar $calendar)
    {
        // 10-Oct-2026 (Ejaz): completed days only. 14-Oct-2026 (Ejaz): plus TODAY, as of now —
        // the "Today's attendance" box; a working day with no punch / sign-in yet reads Absent.
        $request->validate(['date' => ['required', 'date', 'before_or_equal:today']], ['date.before_or_equal' => 'Pick today or an earlier day.']);
        $date = Carbon::parse($request->query('date'))->toDateString();
        $isToday = $date === now()->toDateString();
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, ['day_report' => $date]);

        $employees = Employee::with(['shift', 'department', 'designation', 'branch'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();
        $companyId = (int) $request->user()->company_id;
        $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);
        $gated = $company && app(\App\Services\GateService::class)->enabledFor($company);

        $punches = \App\Models\BiometricLog::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereDate('punched_at', $date)->orderBy('punched_at')->get(['employee_id', 'punch_type', 'punched_at'])->groupBy('employee_id');
        $sessions = \App\Models\EmployeeLoginSession::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereDate('login_at', $date)->get(['employee_id', 'login_at', 'logout_at'])->groupBy('employee_id');
        // 10-Oct-2026 (Ejaz): Break availed = breaks the employee started (Lunch/Tea/Other), in minutes.
        // A door punch-out with no Break started (source BIOMETRIC, no device) is Away, not a break.
        // A break still open counts up to now.
        $breakMin = \App\Models\EmployeeBreakLog::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereDate('start_at', $date)->get(['employee_id', 'start_at', 'end_at', 'duration_seconds', 'source', 'device_uuid'])
            ->reject(fn ($b) => $b->source === 'BIOMETRIC' && $b->device_uuid === null)
            ->groupBy('employee_id')
            ->map(fn ($g) => intdiv($g->sum(fn ($b) => $b->end_at ? (int) $b->duration_seconds
                : max(0, (int) now()->diffInSeconds($b->start_at, true))), 60));
        $att = EmployeeAttendanceLog::query()->whereDate('work_date', $date)->get()->groupBy('employee_id');
        $statuses = $this->statusesByEmployeeAndDate(Carbon::parse($date), Carbon::parse($date));
        $today = now()->toDateString();
        $t = fn ($v) => $v ? Carbon::parse($v)->format('g:i A') : '';
        $hm = fn (int $s) => sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
        // 04-Nov-2026 (Ejaz): both late figures — from the shift start and after the grace (same rule as the Attendance sheet).
        $fromStart = ((\App\Services\MailService::prefs($companyId ?: null)['late_login']['count_from'] ?? 'GRACE') === 'START');
        $words = ['P' => 'Present', 'A' => 'Absent', 'H' => 'Half day', 'L' => 'Leave', 'WOFF' => 'Weekly off', 'HOL' => 'Holiday', '-' => 'No record'];

        $rows = $employees->map(function ($e) use ($date, $gated, $punches, $sessions, $att, $statuses, $today, $calendar, $t, $hm, $words, $breakMin, $isToday, $fromStart) {
            $p = $punches[$e->id] ?? collect();
            $gateIn = $p->whereIn('punch_type', ['IN', 'BREAK_IN'])->min('punched_at');
            $lastPunch = $p->last();
            $gateOut = ($lastPunch && in_array($lastPunch->punch_type, ['OUT', 'BREAK_OUT'], true)) ? $lastPunch->punched_at : null;
            $s = $sessions[$e->id] ?? collect();
            $a = ($att[$e->id] ?? collect())->first();
            $signIn = $a?->first_login_at ?? $s->min('login_at');
            $signOut = ($s->isNotEmpty() && $s->whereNull('logout_at')->isEmpty()) ? $s->max('logout_at') : null;
            $late = (int) (($att[$e->id] ?? collect())->max('late_minutes') ?? 0);
            $both = AttendanceAdminController::bothLateMinutes($late, (int) ($e->shiftOn($date)?->grace_minutes ?? 0), $fromStart);

            [$from, $to, $basis] = ($gated && $gateIn) ? [$gateIn, $gateOut, 'Gate'] : [$signIn, $signOut, $signIn ? 'App' : ''];
            $present = ($from && $to && Carbon::parse($to)->gt(Carbon::parse($from)))
                ? $hm((int) Carbon::parse($from)->diffInSeconds(Carbon::parse($to), true)) : '';

            $came = $signIn || $gateIn;
            $letter = $this->dayLetter($calendar, $e, $date, $statuses[$e->id] ?? [], $today);
            // Today, before the nightly marking: no record on a working day = Absent (or Present if
            // a punch / sign-in exists but its row is still being written).
            if ($isToday && $letter === '-' && ! ($e->date_of_joining && $date < $e->date_of_joining->toDateString())) {
                $letter = $came ? 'P' : 'A';
            }

            return [$e->employee_code, $e->fullName(), $e->branch?->name, $e->department?->name, $e->designation?->name,
                $e->shift ? $e->shift->name . ' (' . $e->shift->windowLabel() . ')' : '',
                $words[$letter] ?? '',
                $t($gateIn), $t($signIn), $came ? $hm(60 * $both['late_from_start']) : '', $came ? $hm(60 * $both['late_post_grace']) : '', $t($gateOut), $t($signOut), $present, $basis,
                $came ? $hm(60 * (int) ($breakMin[$e->id] ?? 0)) : ''];
        })->all();

        $header = ['Employee Code', 'Name', 'Branch', 'Department', 'Designation', 'Shift', 'Status',
            'Gate IN', 'Sign IN', 'Late from shift start (h:mm)', 'Late post grace (h:mm)', 'Gate OUT', 'Sign OUT', 'Overall Present (h:mm)', 'Measured from', 'Break Availed (h:mm)'];
        $sections = [['Employee details', 1, 6, '1F3A5F'], ['Day', 7, 7, '475569'], ['Arrival', 8, 11, '0F766E'],
            ['Departure', 12, 13, '9A3412'], ['Presence', 14, 15, '6D28D9'], ['Break', 16, 16, '0E7490']];
        $note = 'Overall Present = last gate punch-out − first gate punch-in' . ($gated ? '' : ' (no biometric gate here: app sign-out − sign-in)')
            . '. Blank until the day has a punch-out / sign-out. Late from shift start = minutes after the shift start time; Late post grace = minutes after shift start + the shift\'s late grace.'
            . ' Break Availed = Lunch / Tea / Other breaks started by the employee (door punch-outs without a break are Away, not counted).';
        $d = Carbon::parse($date);

        // 10-Oct-2026 (Ejaz): late in red. Late already counts only the minutes past shift start
        // + the shift's late grace (e.g. 10), so any value above 0 is beyond the threshold.
        $red = [];
        foreach ($rows as $i => $row) {
            if (! in_array($row[10] ?? '', ['', '0:00'], true)) { // late (post grace > 0): both late cells red
                $red['J' . ($i + 6)] = 'C00000';
                $red['K' . ($i + 6)] = 'C00000';
            }
        }

        return $this->sheet($request, 'attendance_day_' . $date . '.xlsx',
            $isToday ? "Today's Attendance Report" : 'Day Attendance Report',
            $d->format('l, d M Y') . ($isToday ? ' (as of ' . now()->format('g:i A') . ')' : ''),
            $header, $rows, $sections, [], 6, [$note], $red);
    }

    /**
     * One formatted sheet: title, a coloured SECTION row above the column headings, the table with
     * single thin borders, optional per-cell fills, and notes underneath.
     * 10-Oct-2026 (Ejaz): no frozen panes; prints landscape, all columns on one page width;
     * title and notes are merged across the table so they never widen column A.
     */
    private function sheet(Request $request, string $filename, string $title, string $sub, array $header, array $rows,
        array $sections, array $fills, int $detailCols, array $notes, array $fontColors = []): StreamedResponse
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getDefaultStyle()->getFont()->setSize(9);
        $ws = $book->getActiveSheet()->setTitle(mb_substr($title, 0, 31));
        $col = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $last = $col(count($header));
        $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($request->user()->company_id)->value('name') ?: '');

        $ws->setCellValue('A1', $title . ' — ' . $company);
        $ws->setCellValue('A2', $sub . ' · generated ' . now()->format('d M Y g:i A'));
        $ws->mergeCells('A1:' . $last . '1');
        $ws->mergeCells('A2:' . $last . '2');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F3A5F');
        $ws->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('555555');

        // Row 4: section bands (merged, coloured, white bold text).
        foreach ($sections as [$label, $c1, $c2, $rgb]) {
            $range = $col($c1) . '4:' . $col($c2) . '4';
            if ($c2 > $c1) {
                $ws->mergeCells($range);
            }
            $ws->setCellValue($col($c1) . '4', $label);
            $st = $ws->getStyle($range);
            $st->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $st->getFill()->setFillType('solid')->getStartColor()->setRGB($rgb);
            $st->getAlignment()->setHorizontal('center')->setVertical('center');
        }
        $ws->getRowDimension(4)->setRowHeight(22);

        // Row 5: column headings; data from row 6.
        $ws->fromArray($header, null, 'A5');
        $hs = $ws->getStyle('A5:' . $last . '5');
        $hs->getFont()->setBold(true)->getColor()->setRGB('1F2937');
        $hs->getFill()->setFillType('solid')->getStartColor()->setRGB('E8EEF5');
        $hs->getAlignment()->setWrapText(true)->setVertical('center')->setHorizontal('center');
        $ws->getRowDimension(5)->setRowHeight(32);
        $ws->fromArray(array_map(fn ($r) => array_map(fn ($v) => $v ?? '', $r), $rows), null, 'A6', true);
        foreach ($fills as $cell => $rgb) {
            $ws->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB($rgb);
        }
        foreach ($fontColors as $cell => $rgb) {
            $ws->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB($rgb);
        }

        $n = count($rows);
        $end = max(5, $n + 5);
        // Single thin border on every cell of the table (sections + headings + data).
        $ws->getStyle('A4:' . $last . $end)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('7F7F7F');
        if ($n) {
            $ws->getStyle($col($detailCols + 1) . '6:' . $last . $end)->getAlignment()->setHorizontal('center');
        }
        // Notes go in merged cells, so the column auto-size ignores their text.
        $r = $end + 2;
        $labelEnd = $col(min(4, count($header)));
        foreach ($notes as $line) {
            if (is_array($line)) { // [code, label, fill] legend line
                [$code, $label, $rgb] = $line + [2 => null];
                $ws->setCellValue('A' . $r, $code);
                $ws->setCellValue('B' . $r, $label);
                $ws->mergeCells('B' . $r . ':' . $labelEnd . $r);
                $ws->getStyle('A' . $r . ':' . $labelEnd . $r)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('7F7F7F');
                if ($rgb) {
                    $ws->getStyle('A' . $r)->getFill()->setFillType('solid')->getStartColor()->setRGB($rgb);
                }
            } else {
                $ws->setCellValue('A' . $r, $line);
                $ws->mergeCells('A' . $r . ':' . $last . $r);
                $ws->getStyle('A' . $r)->getFont()->setBold(str_ends_with($line, ':'));
            }
            $r++;
        }
        for ($c = 1; $c <= count($header); $c++) {
            $ws->getColumnDimension($col($c))->setAutoSize(true);
        }

        // Print: A4 landscape, narrow margins, every column on one page width, headings repeat.
        $ps = $ws->getPageSetup();
        $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setHorizontalCentered(true)
            ->setRowsToRepeatAtTopByStartAndEnd(4, 5);
        $ws->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.25)->setRight(0.25)->setHeader(0.2)->setFooter(0.2);
        $ws->getHeaderFooter()->setOddFooter('&L' . $title . '&RPage &P of &N');

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function dayLetter(WorkCalendar $calendar, Employee $employee, string $date, array $statuses, string $today): string
    {
        if ($date > $today || ($employee->date_of_joining && $date < $employee->date_of_joining->toDateString())) {
            return '-'; // future, or before they joined
        }
        $st = $statuses[$date] ?? null;
        // Worked on a holiday / weekly off → that still shows as worked.
        if (in_array($st, ['PRESENT', 'HALF_DAY', 'MISMATCH'], true)) {
            return self::STATUS_LETTERS[$st];
        }
        // 07-Oct-2026 (Ejaz): a holiday or weekly off is HOL / WOFF even when the nightly job
        // wrote an ABSENT (or a leave) row for it — those are not absences.
        if ($calendar->isHoliday($employee->company_id, $date)) {
            return 'HOL';
        }
        if (! $calendar->isShiftDay($employee, $date)) {
            return 'WOFF';
        }

        return $st ? (self::STATUS_LETTERS[$st] ?? '-') : '-'; // '-' = working day with no record
    }

    /** ?from&to (max 93 days) or ?month=YYYY-MM (default this month) → [first, last]. */
    private function registerRange(Request $request): array
    {
        if (! $request->filled('from')) {
            return $this->monthRange($request);
        }
        $request->validate(['from' => ['required', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($request->query('from'))->startOfDay();
        $to = Carbon::parse($request->query('to', $request->query('from')))->startOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Pick at most 93 days.');

        return [$from, $to];
    }

    /** [employee_id => [Y-m-d => status]] for the month, tenant-scoped via the model. */
    private function statusesByEmployeeAndDate(Carbon $start, Carbon $end): array
    {
        $map = [];
        EmployeeAttendanceLog::query()
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get(['employee_id', 'work_date', 'status'])
            ->each(function ($r) use (&$map) {
                $date = $r->work_date->toDateString();
                $current = $map[$r->employee_id][$date] ?? null;
                if ($current === null
                    || array_search($r->status, self::STATUS_PRECEDENCE) < array_search($current, self::STATUS_PRECEDENCE)) {
                    $map[$r->employee_id][$date] = $r->status;
                }
            });

        return $map;
    }

    private function statusCounts(array $statusesByDate): array
    {
        $counts = ['PRESENT' => 0, 'ABSENT' => 0, 'HALF_DAY' => 0, 'ON_LEAVE' => 0];
        foreach ($statusesByDate as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            } elseif ($status === 'MISMATCH') {
                $counts['PRESENT']++; // presence evidence, just conflicting sources
            }
        }

        return $counts;
    }

    /** Validate ?month=YYYY-MM (defaults to the current month) → [firstDay, lastDay]. */
    private function monthRange(Request $request): array
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $start = Carbon::createFromFormat('Y-m-d', $request->query('month', now()->format('Y-m')) . '-01')->startOfDay();

        return [$start->copy(), $start->copy()->endOfMonth()->startOfDay()];
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

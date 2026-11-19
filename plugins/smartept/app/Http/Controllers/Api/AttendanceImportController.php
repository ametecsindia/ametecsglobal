<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 07-Nov-2026 (Ejaz): Attendance → Bulk upload. Excel (.xlsx / .xls) or CSV, one row per employee per day.
 * Each row follows the SAME rules as "+ Add missed day" / Edit (ManualAttendanceRequest): known employee of this
 * company, status Present / Absent / Half day / Leave, check-out not in the future and not before check-in.
 * A day with no record is added; a day that already has one is corrected (source MANUAL, reason appended to the notes —
 * exactly what Edit does). Preview (dry run) checks every row and saves nothing.
 */
class AttendanceImportController extends Controller
{
    public const HEADERS = ['Employee Code', 'Date', 'Status', 'Check-in', 'Check-out', 'Reason'];

    private const STATUS = [
        'P' => 'PRESENT', 'PRESENT' => 'PRESENT',
        'A' => 'ABSENT', 'ABSENT' => 'ABSENT',
        'H' => 'HALF_DAY', 'HD' => 'HALF_DAY', 'HALFDAY' => 'HALF_DAY',
        'L' => 'ON_LEAVE', 'LEAVE' => 'ON_LEAVE', 'ONLEAVE' => 'ON_LEAVE',
    ];

    /** GET /api/attendance/import-template — the sample file (Excel), with example rows and a notes sheet. */
    public function template(): StreamedResponse
    {
        $book = new Spreadsheet();
        $s = $book->getActiveSheet()->setTitle('Attendance');
        $s->fromArray([self::HEADERS,
            ['E-1001', now()->subDay()->format('d-m-Y'), 'Present', '9:05 AM', '6:10 PM', 'Reader was down'],
            ['E-1002', now()->subDay()->format('d-m-Y'), 'Half day', '9:30 AM', '1:30 PM', 'Approved half day'],
            ['E-1003', now()->subDay()->format('d-m-Y'), 'Leave', '', '', 'Sick leave'],
        ]);
        $s->getStyle('A1:F1')->getFont()->setBold(true);
        foreach (range('A', 'F') as $col) {
            $s->getColumnDimension($col)->setWidth($col === 'F' ? 32 : 16);
        }
        $n = $book->createSheet()->setTitle('How to fill');
        $n->fromArray(array_map(fn ($l) => [$l], [
            'One row per employee per day. Keep the header row exactly as on the Attendance sheet.',
            'Employee Code: as in Employees (required).',
            'Date: DD-MM-YYYY, e.g. 07-11-2026 (YYYY-MM-DD and Excel dates also work).',
            'Status: Present, Absent, Half day or Leave (P / A / H / L also work).',
            'Check-in / Check-out: 12-hour time, e.g. 9:05 AM / 6:10 PM. Leave blank for Absent or Leave.',
            'Reason: why the day is being added or corrected. Blank = "Bulk upload".',
            'A day that already has attendance is corrected; a day without one is added. Save as Excel or CSV.',
        ]));
        $n->getColumnDimension('A')->setWidth(110);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'smartept-attendance-upload-sample.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** POST /api/attendance/import (file, dry_run) */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120'], 'dry_run' => ['nullable', 'boolean']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, ['csv', 'xlsx', 'xls'], true), 422, 'Upload an Excel (.xlsx / .xls) or CSV file.');
        $dry = $request->boolean('dry_run');
        $companyId = (int) $request->user()->company_id;

        try {
            $reader = IOFactory::createReader(['csv' => 'Csv', 'xlsx' => 'Xlsx', 'xls' => 'Xls'][$ext]);
            $reader->setReadDataOnly(true);
            $rows = $reader->load($file->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            abort(422, 'This file could not be read. Save it again as Excel or CSV from the sample file.');
        }

        $head = array_map(fn ($h) => preg_replace('/[^a-z]/', '', strtolower((string) $h)), array_shift($rows) ?? []);
        $col = function (array $names) use ($head) {
            foreach ($names as $n) {
                if (($i = array_search($n, $head, true)) !== false) {
                    return $i;
                }
            }

            return null;
        };
        $c = ['code' => $col(['employeecode', 'empcode', 'code']), 'date' => $col(['date', 'workdate']), 'status' => $col(['status']),
            'in' => $col(['checkin', 'intime', 'in']), 'out' => $col(['checkout', 'outtime', 'out']), 'reason' => $col(['reason', 'notes', 'remarks'])];
        abort_if($c['code'] === null || $c['date'] === null || $c['status'] === null, 422,
            'The first row must be the header: '.implode(', ', self::HEADERS).'. Download the sample file for the format.');

        $codes = Employee::where('company_id', $companyId)->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $k) => [strtoupper(trim($k)) => $id]);
        $results = [];
        $sum = ['created' => 0, 'updated' => 0, 'failed' => 0];
        $seen = [];

        foreach ($rows as $i => $r) {
            $get = fn ($k) => $c[$k] === null ? null : (is_string($v = $r[$c[$k]] ?? null) ? trim($v) : $v);
            if (collect($r)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                continue; // blank line
            }
            $line = $i + 2;
            $code = (string) $get('code');
            try {
                $empId = $codes[strtoupper($code)] ?? null;
                throw_unless($empId, new \InvalidArgumentException("Employee code \"{$code}\" not found."));
                $date = self::date($get('date'));
                throw_unless($date, new \InvalidArgumentException('Date not understood — use DD-MM-YYYY.'));
                $status = self::STATUS[preg_replace('/[^A-Z]/', '', strtoupper((string) $get('status')))] ?? null;
                throw_unless($status, new \InvalidArgumentException('Status must be Present, Absent, Half day or Leave.'));
                $in = self::time($date, $get('in'));
                $out = self::time($date, $get('out'));
                throw_if($get('in') && ! $in, new \InvalidArgumentException('Check-in time not understood — use e.g. 9:05 AM.'));
                throw_if($get('out') && ! $out, new \InvalidArgumentException('Check-out time not understood — use e.g. 6:10 PM.'));
                throw_if($out && $out->isFuture(), new \InvalidArgumentException('Check-out time cannot be later than the current time.'));
                throw_if($in && $out && $out->lt($in), new \InvalidArgumentException('Check-out is before check-in.'));
                throw_if($in && $in->isFuture(), new \InvalidArgumentException('Check-in time cannot be in the future.'));
                $key = $empId.'|'.$date;
                if (isset($seen[$key])) { // (not throw_if: its message would be built — and crash — on every row)
                    throw new \InvalidArgumentException("Same employee and date as line {$seen[$key]}.");
                }
                $seen[$key] = $line;

                $reason = (string) ($get('reason') ?: 'Bulk upload');
                $existing = EmployeeAttendanceLog::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $empId)
                    ->whereDate('work_date', $date)->orderBy('id')->get();
                $row = $existing->firstWhere('source', 'MANUAL') ?? $existing->firstWhere('source', 'BIOMETRIC') ?? $existing->first();
                $action = $row ? 'updated' : 'created';
                if (! $dry) {
                    DB::transaction(fn () => $this->save($request, $companyId, $empId, $date, $status, $in, $out, $reason, $row, $file->getClientOriginalName()));
                }
                $sum[$action]++;
                $results[] = ['line' => $line, 'code' => $code, 'date' => $date, 'ok' => true, 'action' => $action];
            } catch (\InvalidArgumentException $e) {
                $sum['failed']++;
                $results[] = ['line' => $line, 'code' => $code, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        if (! $dry) {
            $this->audit($request, 'IMPORT', EmployeeAttendanceLog::class, null, ['file' => $file->getClientOriginalName()] + $sum);
        }

        return response()->json(['dry_run' => $dry, 'summary' => $sum + ['total' => count($results)], 'results' => $results]);
    }

    private function save(Request $request, int $companyId, int $empId, string $date, string $status, ?Carbon $in, ?Carbon $out, string $reason, ?EmployeeAttendanceLog $row, string $fileName): void
    {
        $note = sprintf('[%s] %s: %s (bulk upload: %s)', now()->toDateTimeString(), $request->user()->email, $reason, $fileName);
        $off = in_array($status, ['ABSENT', 'ON_LEAVE'], true); // no punch times on an absent / leave day
        if ($row) {
            $row->update(['status' => $status, 'source' => 'MANUAL',
                'check_in_at' => $off ? null : ($in ?? $row->check_in_at), 'check_out_at' => $off ? null : ($out ?? $row->check_out_at),
                'notes' => trim(($row->notes ? $row->notes."\n" : '').$note)]);
        } else {
            EmployeeAttendanceLog::create(['company_id' => $companyId, 'employee_id' => $empId, 'work_date' => $date, 'status' => $status,
                'source' => 'MANUAL', 'check_in_at' => $off ? null : $in, 'check_out_at' => $off ? null : $out, 'notes' => $note]);
        }
    }

    /** Excel date number, DD-MM-YYYY (also / and .), YYYY-MM-DD, or "07 Nov 2026" → Y-m-d. Day-first, never US month-first. */
    public static function date($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && $v > 20000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));
        foreach (['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd-m-y', 'd/m/y', 'd M Y', 'd-M-Y', 'j M Y', 'd F Y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $v);
            if ($d && $d->format($f) === $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Excel time fraction, "9:05 AM", "09:05", "21:15", or a full date-time → that moment on $date. */
    public static function time(string $date, $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $secs = (int) round(fmod((float) $v, 1) * 86400);

            return $secs > 0 || (float) $v == 0.0 ? Carbon::parse($date)->addSeconds($secs) : null;
        }
        $v = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v)));
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?\s*(AM|PM)?$/', $v, $m)) {
            $h = (int) $m[1];
            if (! empty($m[4])) {
                if ($h < 1 || $h > 12) {
                    return null;
                }
                $h = $h % 12 + ($m[4] === 'PM' ? 12 : 0);
            }
            if ($h > 23 || (int) $m[2] > 59) {
                return null;
            }

            return Carbon::parse($date)->setTime($h, (int) $m[2], (int) ($m[3] ?? 0));
        }
        try {
            $t = Carbon::parse($v);

            return Carbon::parse($date)->setTime($t->hour, $t->minute, $t->second);
        } catch (\Throwable) {
            return null;
        }
    }
}

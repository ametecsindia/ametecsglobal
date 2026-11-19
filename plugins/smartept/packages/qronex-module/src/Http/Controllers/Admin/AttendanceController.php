<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\AttendanceRejection;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Base
{
    private function filtered(Request $request, string $model)
    {
        $tz = \Ametecs\Qronex\Qronex::tz(); // 09-Nov-2026: Organisation time zone
        $from = Carbon::parse($request->query('from', today($tz)->toDateString()), $tz)->startOfDay()->setTimezone(config('app.timezone'));
        $to = Carbon::parse($request->query('to', $request->query('from', today($tz)->toDateString())), $tz)->endOfDay()->setTimezone(config('app.timezone'));
        $col = $model === AttendanceEvent::class ? 'recorded_at' : 'created_at';

        return $model::with(['employee.office', 'gate.office'])
            ->whereBetween($col, [$from, $to])
            // 08-Nov-2026: a punch belongs to the office where it was made (employees may have several offices).
            ->when($request->office, fn ($q, $o) => $model === AttendanceEvent::class ? $q->where('office_id', $o) : $q->whereHas('employee', fn ($e) => $e->where('office_id', $o)))
            ->when($request->q, function ($q, $s) {
                // 08-Nov-2026: picked from the list ("Name (CODE)") → that employee exactly; typed text → name or code contains it.
                if (preg_match('/\(([^()]+)\)\s*$/', $s, $m)) {
                    return $q->whereHas('employee', fn ($e) => $e->where('employee_code', trim($m[1])));
                }

                return $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('employee_code', 'like', "%$s%")));
            })
            ->latest($col);
    }

    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'rejected' ? 'rejected' : 'verified';

        return view('qronex::attendance', [
            'tab' => $tab,
            'rows' => $this->filtered($request, $tab === 'rejected' ? AttendanceRejection::class : AttendanceEvent::class)->paginate(100)->withQueryString(),
            'offices' => Office::orderBy('name')->get(),
            // 08-Nov-2026 (Ejaz): Company filter (platform admins switch company; others see their own) + registered employees list.
            'companies' => $this->companies($request),
            'tenantId' => $this->tid(),
            'people' => Employee::whereHas('devices')->orderBy('name')->get(['id', 'name', 'employee_code']),
        ]);
    }

    /** [id => name] — every company for a platform admin, else only the user's own. */
    private function companies(Request $request): array
    {
        $host = Qronex::host();

        return $host->tenantIdFor($request->user()) === null ? $host->tenants() : [$this->tid() => $host->tenant($this->tid())['name']];
    }

    /**
     * 07-Nov-2026 (Ejaz): export as CSV, Excel or PDF, laid out either as IN–OUT pairs (each IN with the next OUT,
     * at any gate, plus the duration) or one row per punch. Company, Office, Gate, Employee ID and Name come first.
     */
    public function export(Request $request)
    {
        $format = in_array($request->query('format'), ['csv', 'xlsx', 'pdf'], true) ? $request->query('format') : 'csv';
        $pairs = $request->query('layout', 'pairs') !== 'punches';
        [$head, $rows] = $pairs ? $this->pairRows($request) : $this->punchRows($request);
        $company = Qronex::host()->tenant($this->tid())['name'];
        $tz = \Ametecs\Qronex\Qronex::tz(); // 09-Nov-2026: Organisation time zone
        $name = 'qr-attendance-'.($pairs ? 'in-out' : 'punches').'-'.now($tz)->format('Ymd-His');
        array_unshift($head, 'Company');
        $rows = array_map(fn ($r) => array_merge([$company], $r), $rows);

        if ($format === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sh = $x->getActiveSheet()->setTitle($pairs ? 'IN-OUT' : 'Punches');
            $sh->fromArray($head, null, 'A1');
            $sh->fromArray($rows ?: [[]], null, 'A2', true);
            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($head));
            $sh->getStyle("A1:{$last}1")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0B1F33']]]);
            $sh->freezePane('A2');
            foreach (range(1, count($head)) as $c) {
                $sh->getColumnDimensionByColumn($c)->setAutoSize(true);
            }

            return response()->streamDownload(fn () => (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save('php://output'), $name.'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        if ($format === 'pdf') { // print-ready page: the browser's "Save as PDF" (same as the other SmartEPT PDF reports)
            return response()->view('qronex::attendance-print', [
                'company' => $company, 'head' => $head, 'rows' => $rows, 'title' => $pairs ? 'Attendance — IN / OUT' : 'Attendance — every punch',
                'period' => $this->period($request), 'office' => $request->office ? Office::find($request->office)?->name : null,
            ]);
        }

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads names correctly
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(Request $request): string
    {
        $tz = \Ametecs\Qronex\Qronex::tz(); // 09-Nov-2026: Organisation time zone
        $f = Carbon::parse($request->query('from', today($tz)->toDateString()), $tz);
        $t = Carbon::parse($request->query('to', $request->query('from', today($tz)->toDateString())), $tz);

        return $f->isSameDay($t) ? $f->format('d M Y') : $f->format('d M Y').' to '.$t->format('d M Y');
    }

    private static function dur(int $sec): string
    {
        if ($sec < 60) {
            return $sec.'s';
        }
        $m = intdiv($sec, 60);

        return $m >= 60 ? intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m' : $m.'m';
    }

    /** One row per punch. */
    private function punchRows(Request $request): array
    {
        $head = ['Office', 'Gate', 'Employee ID', 'Employee name', 'Date', 'Time', 'Type', 'Distance (m)', 'Accuracy (m)', 'Mode', 'Adjusted', 'In SmartEPT', 'Ref'];
        $rows = [];
        foreach ($this->filtered($request, AttendanceEvent::class)->reorder('recorded_at')->cursor() as $e) {
            $t = $e->recorded_at->timezone(\Ametecs\Qronex\Qronex::tz());
            $rows[] = [$e->gate?->office?->name ?? $e->employee->office?->name, $e->gate?->name, $e->employee->employee_code, $e->employee->name,
                $t->format('d-m-Y'), $t->format('h:i:s A'), $e->type, $e->distance_m, $e->accuracy_m, $e->mode === 'AGENT_SIGNOUT' ? AttendanceEvent::AGENT_SIGNOUT_LABEL : $e->mode,
                $e->adjusted ? 'Yes' : 'No', $e->host_synced_at ? 'Yes' : 'Pending', $e->shownRef()];
        }

        return [$head, $rows];
    }

    /** Each IN with the next OUT of the same employee (any gate), and the time between them. */
    private function pairRows(Request $request): array
    {
        $head = ['Office', 'Gate', 'Employee ID', 'Employee name', 'Date', 'IN time', 'OUT time', 'Duration', 'OUT at', 'Note'];
        $rows = [];
        $now = now();
        $events = $this->filtered($request, AttendanceEvent::class)->reorder('recorded_at')->get()->groupBy('employee_id');
        foreach ($events as $list) {
            $open = null;
            $emit = function (?AttendanceEvent $in, ?AttendanceEvent $out) use (&$rows, $now) {
                $e = $in ?? $out;
                $where = fn (?AttendanceEvent $x) => $x ? trim(($x->gate?->office?->name ?? $x->employee->office?->name).' · '.$x->gate?->name, ' ·') : '';
                $ti = $in?->recorded_at->timezone(\Ametecs\Qronex\Qronex::tz());
                $to = $out?->recorded_at->timezone(\Ametecs\Qronex\Qronex::tz());
                $note = ! $in ? 'OUT without an IN' : (! $out ? ($in->recorded_at->isToday() ? 'Still IN' : 'No OUT punch') : '');
                $rows[] = [$e->employee->employee_code.'|'.($in ?? $out)->recorded_at->timestamp, [
                    $e->gate?->office?->name ?? $e->employee->office?->name, $e->gate?->name, $e->employee->employee_code, $e->employee->name,
                    ($ti ?? $to)->format('d-m-Y'), $ti?->format('h:i:s A') ?? '—', $to?->format('h:i:s A') ?? '—',
                    $in && $out ? self::dur($in->recorded_at->diffInSeconds($out->recorded_at, true)) : '—',
                    $in && $out && $where($in) !== $where($out) ? $where($out) : '', $note,
                ]];
            };
            foreach ($list as $ev) {
                if ($ev->type === 'IN') {
                    if ($open) {
                        $emit($open, null);
                    }
                    $open = $ev;
                } else {
                    $emit($open, $ev);
                    $open = null;
                }
            }
            if ($open) {
                $emit($open, null);
            }
        }
        usort($rows, fn ($a, $b) => strnatcmp($a[0], $b[0])); // employee, then time

        return [$head, array_column($rows, 1)];
    }
}

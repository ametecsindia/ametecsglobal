<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ExtraHour;
use App\Services\ExtraHours;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 10-Oct-2026 (Ejaz): Reports → Extra Hours. Work outside shift hours per employee per day,
 * Approve / Reject (when the shift asks for approval), totals and "top contributors", Excel.
 * Rows are limited to the employees the signed-in user can see.
 */
class ExtraHoursController extends Controller
{
    use ScopesVisibleEmployees;

    /** GET /api/extra-hours?from&to[&employee_id][&status] */
    public function index(Request $request, ExtraHours $svc): JsonResponse
    {
        [$from, $to, $rows] = $this->load($request, $svc);
        $out = $rows->map(fn ($r) => $this->present($r))->values();
        $totals = $out->groupBy('employee_id')->map(function ($g) {
            $counted = $g->whereIn('status', ['RECORDED', 'APPROVED']);

            return [
                'employee_id' => $g[0]['employee_id'], 'employee' => $g[0]['employee'], 'employee_code' => $g[0]['employee_code'],
                'department' => $g[0]['department'], 'days' => $counted->count(), 'seconds' => $counted->sum('total_seconds'),
                'pending' => $g->where('status', 'PENDING')->count(),
            ];
        })->sortByDesc('seconds')->values();

        return response()->json(['from' => $from, 'to' => $to, 'data' => $out, 'totals' => $totals]);
    }

    /** POST /api/extra-hours/{id}/decision {decision: APPROVED|REJECTED, note?} */
    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:APPROVED,REJECTED'], 'note' => ['nullable', 'string', 'max:500']]);
        $row = ExtraHour::where('company_id', $request->user()->company_id)->findOrFail($id);
        $visible = $this->visibleEmployeeIds($request->user());
        abort_if($visible !== null && ! in_array((int) $row->employee_id, array_map('intval', $visible), true), 403, 'This employee is not in your scope.');
        $row->update(['status' => $d['decision'], 'note' => $d['note'] ?? $row->note, 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        $this->audit($request, $d['decision'] === 'APPROVED' ? 'APPROVE' : 'REJECT', 'extra_hours', $row->id,
            ['employee_id' => $row->employee_id, 'work_date' => $row->work_date->toDateString(), 'note' => $d['note'] ?? null]);

        return response()->json(['data' => $this->present($row->fresh(['employee.department', 'employee.shift', 'decider']))]);
    }

    /** GET /api/export/extra-hours?from&to — Excel: day-wise sheet + totals sheet. */
    public function export(Request $request, ExtraHours $svc): StreamedResponse
    {
        [$from, $to, $rows] = $this->load($request, $svc);
        $this->audit($request, 'EXPORT', 'extra_hours', null, ['from' => $from, 'to' => $to]);
        $data = $rows->map(fn ($r) => $this->present($r))->values();
        $hm = fn ($s) => ExtraHours::hm((int) $s);
        $words = ['RECORDED' => 'Recorded', 'PENDING' => 'Pending approval', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'];

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getDefaultStyle()->getFont()->setSize(10);
        $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($request->user()->company_id)->value('name') ?: '');
        $sheet = function ($ws, string $title, array $head, array $rows) use ($company, $from, $to) {
            $col = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $last = $col(count($head));
            $ws->setCellValue('A1', $title . ' — ' . $company);
            $ws->setCellValue('A2', Carbon::parse($from)->format('d M Y') . ' to ' . Carbon::parse($to)->format('d M Y') . ' · generated ' . now()->format('d M Y g:i A'));
            $ws->mergeCells('A1:' . $last . '1');
            $ws->mergeCells('A2:' . $last . '2');
            $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F3A5F');
            $ws->fromArray($head, null, 'A4');
            $hs = $ws->getStyle('A4:' . $last . '4');
            $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $hs->getFill()->setFillType('solid')->getStartColor()->setRGB('1F3A5F');
            $ws->fromArray($rows, null, 'A5', true);
            $end = max(4, count($rows) + 4);
            $ws->getStyle('A4:' . $last . $end)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('7F7F7F');
            for ($c = 1; $c <= count($head); $c++) {
                $ws->getColumnDimension($col($c))->setAutoSize(true);
            }
            $ws->getPageSetup()->setOrientation('landscape')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(4, 4);
        };

        $sheet($book->getActiveSheet()->setTitle('Extra Hours by day'), 'Extra Hours by day',
            ['Date', 'Employee Code', 'Name', 'Department', 'Shift', 'Day', 'Before shift', 'After shift', 'Total extra', 'Last active', 'Status', 'Decided by', 'Note'],
            $data->map(fn ($r) => [Carbon::parse($r['work_date'])->format('d-m-Y'), $r['employee_code'], $r['employee'], $r['department'], $r['shift'],
                $r['off_day'] ? 'Weekly off / holiday' : 'Working day', $hm($r['before_seconds']), $hm($r['after_seconds']), $hm($r['total_seconds']),
                $r['last_active_at'] ? Carbon::parse($r['last_active_at'])->format('g:i A') : '', $words[$r['status']] ?? $r['status'], $r['decided_by'] ?? '', $r['note'] ?? ''])->all());

        $tot = $data->groupBy('employee_id')->map(function ($g) use ($hm) {
            $c = $g->whereIn('status', ['RECORDED', 'APPROVED']);

            return [$g[0]['employee_code'], $g[0]['employee'], $g[0]['department'], $c->count(), $hm($c->sum('total_seconds')),
                $g->where('status', 'PENDING')->count(), $g->where('status', 'REJECTED')->count(), $c->sum('total_seconds')];
        })->sortByDesc(fn ($r) => $r[7])->values()->map(fn ($r, $i) => array_merge([$i + 1], array_slice($r, 0, 7)))->all();
        $sheet($book->createSheet()->setTitle('Totals'), 'Extra Hours — totals (top contributors first)',
            ['Rank', 'Employee Code', 'Name', 'Department', 'Days counted', 'Extra time counted', 'Pending', 'Rejected'], $tot);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, 'extra_hours_' . $from . '_' . $to . '.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Refresh the period (up to 93 days), then read it back. */
    private function load(Request $request, ExtraHours $svc): array
    {
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:RECORDED,PENDING,APPROVED,REJECTED']]);
        $from = Carbon::parse($request->query('from'))->toDateString();
        $to = Carbon::parse($request->query('to'))->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Pick at most 93 days.');
        $companyId = (int) $request->user()->company_id;
        $visible = $this->visibleEmployeeIds($request->user());
        $emps = Employee::where('company_id', $companyId)->where('employment_status', 'ACTIVE')->with('shift')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('id', (int) $id))->get();
        $svc->refresh($companyId, $emps, $from, $to);

        // 14-Oct-2026 (Ejaz): only people allowed to work outside shift hours, and only from the day
        // the feature went live.
        $rows = ExtraHour::where('company_id', $companyId)->whereIn('employee_id', $emps->where('work_outside_shift', true)->pluck('id'))
            ->whereDate('work_date', '>=', max($from, ExtraHours::since()))->whereDate('work_date', '<=', $to)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['employee.department', 'employee.shift', 'decider'])->orderByDesc('work_date')->get();

        return [$from, $to, $rows];
    }

    private function present(ExtraHour $r): array
    {
        $e = $r->employee;

        return [
            'id' => $r->id, 'employee_id' => $r->employee_id, 'employee' => $e?->fullName(), 'employee_code' => $e?->employee_code,
            'department' => $e?->department?->name, 'shift' => $e?->shift ? $e->shift->name . ($e->shift->start_time && $e->shift->end_time ? ' (' . $e->shift->windowLabel() . ')' : '') : '',
            'work_date' => $r->work_date->toDateString(), 'off_day' => (bool) $r->off_day,
            'before_seconds' => (int) $r->before_seconds, 'after_seconds' => (int) $r->after_seconds, 'total_seconds' => $r->totalSeconds(),
            'last_active_at' => $r->last_active_at?->toDateTimeString(), 'status' => $r->status, 'note' => $r->note,
            'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->toDateTimeString(),
            'needs_approval' => (bool) ($e?->shift?->extra_needs_approval),
        ];
    }
}

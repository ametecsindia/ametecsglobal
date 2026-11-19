<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company holiday calendar. Holidays feed the WorkCalendar service: no
 * late/absent marking on them and they render as HD in the attendance register.
 */
class HolidayController extends Controller
{
    /** GET /api/holidays?year= */
    public function index(Request $request): JsonResponse
    {
        $holidays = Holiday::query()
            ->when($request->query('year'), fn ($q, $y) => $q->whereYear('holiday_date', (int) $y))
            ->orderBy('holiday_date')
            ->get()
            ->map(fn ($h) => [
                'id'           => $h->id,
                'holiday_date' => $h->holiday_date->toDateString(),
                'name'         => $h->name,
                'type'         => $h->type,
            ]);

        return response()->json(['data' => $holidays]);
    }

    /** POST /api/holidays */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'holiday_date' => ['required', 'date'],
            'name'         => ['required', 'string', 'max:190'],
            'type'         => ['nullable', Rule::in(['PUBLIC', 'COMPANY'])],
        ]);

        // Tenant-scoped uniqueness: the same date may be a holiday for another company.
        // Checked via whereDate (not Rule::unique) because the column stores a midnight timestamp.
        $exists = Holiday::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('holiday_date', $data['holiday_date'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['holiday_date' => 'This date is already a holiday for the company.']);
        }

        $holiday = Holiday::create($data + ['company_id' => $companyId, 'type' => $data['type'] ?? 'PUBLIC']);
        $this->audit($request, 'CREATE', Holiday::class, $holiday->id, $data);

        return response()->json(['data' => $holiday->fresh()], 201);
    }

    /**
     * GET /api/weekly-off — 13-Oct-2026 (Ejaz): the company's W.OFF schedule.
     * saved=false → never saved; the old rule (shift working days, else Mon–Sat) is in force,
     * shown as every Sunday off.
     */
    public function weeklyOff(Request $request): JsonResponse
    {
        $wo = app(\App\Services\WorkCalendar::class)->weeklyOff((int) $request->user()->company_id);

        return response()->json(['data' => ['saved' => $wo !== null, 'schedule' => $wo ?? \App\Services\WorkCalendar::DEFAULT_WEEKLY_OFF]]);
    }

    /** PUT /api/weekly-off {schedule: {SAT: [2,4], SUN: [1,2,3,4,5]}} */
    public function saveWeeklyOff(Request $request): JsonResponse
    {
        $data = $request->validate(['schedule' => ['present', 'array'], 'schedule.*' => ['array'], 'schedule.*.*' => ['integer', 'between:1,5']]);
        $company = \App\Models\Company::findOrFail($request->user()->company_id);
        $company->weekly_off = \App\Services\WorkCalendar::normalise($data['schedule'] ?? []);
        $company->save();
        $this->audit($request, 'WEEKLY_OFF_UPDATE', \App\Models\Company::class, $company->id, $company->weekly_off);

        return response()->json(['data' => ['saved' => true, 'schedule' => $company->weekly_off]]);
    }

    /** DELETE /api/holidays/{holiday} — tenant scope on the model makes cross-company ids a 404. */
    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $this->audit($request, 'DELETE', Holiday::class, $holiday->id, ['holiday_date' => $holiday->holiday_date->toDateString()]);
        $holiday->delete();

        return response()->json(null, 204);
    }
}

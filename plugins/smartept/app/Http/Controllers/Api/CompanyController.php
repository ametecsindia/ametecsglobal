<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /** GET /api/companies — Super Admin sees all; others see their own company only. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Company::query()->withCount(['branches', 'departments', 'teams', 'employees']);

        if (! $user->isSuperAdmin()) {
            $query->whereKey($user->company_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    /** GET /api/companies/{company} */
    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        // 25-Sep-2026: settings readers via a card tick never see storage credentials.
        if (! $request->user()->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN')) {
            $company->makeHidden('storage_settings');
        }
        return response()->json(['data' => $company->loadCount(['branches', 'departments', 'teams', 'employees'])]);
    }

    /** POST /api/companies — Super Admin only (tenant provisioning). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'code'                => ['required', 'string', 'max:64', 'unique:companies,code'],
            'legal_name'          => ['nullable', 'string', 'max:255'],
            'timezone'            => ['nullable', 'string', 'max:64'],
            'deployment_model'    => ['nullable', 'in:LAN,PRIVATE_CLOUD,HYBRID,AMETECS_SAAS'],
            'storage_driver'      => ['nullable', 'in:MINIO,S3,AZURE,GCP,NAS,LOCAL'],
            'data_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'attendance_mode'     => ['nullable', 'in:BIOMETRIC,QR,QR_BIOMETRIC,AGENT_ONLY'], // 07-Nov-2026: QR Attendance; 12-Nov-2026: QR + Biometric
            // Biometric Gate: auto = follow device setup, on/off = explicit override.
            'biometric_gate'      => ['nullable', 'in:auto,on,off'],
            // Privacy: skip capturing raw-IP / local-IP websites (logs them as "Unknown source").
            'exclude_ip_sites'    => ['nullable', 'boolean'],
            // Company-wide default tracking mode (org levels below can still override).
            'tracking_mode'       => ['nullable', 'in:FULL,NO_SCREENSHOTS,PRESENCE_ONLY,EXCLUDED'],
        ]);

        $company = Company::create($data);
        $this->audit($request, 'CREATE', Company::class, $company->id, $data);

        return response()->json(['data' => $company], 201);
    }

    /** PUT /api/companies/{company} */
    public function update(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $data = $request->validate([
            'name'                => ['sometimes', 'string', 'max:255'],
            'legal_name'          => ['nullable', 'string', 'max:255'],
            'timezone'            => ['nullable', 'string', 'max:64'],
            'deployment_model'    => ['nullable', 'in:LAN,PRIVATE_CLOUD,HYBRID,AMETECS_SAAS'],
            'storage_driver'      => ['nullable', 'in:MINIO,S3,AZURE,GCP,NAS,LOCAL'],
            'storage_settings'    => ['nullable', 'array'],
            'data_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status'              => ['nullable', 'in:ACTIVE,SUSPENDED'],
            'attendance_mode'     => ['nullable', 'in:BIOMETRIC,QR,QR_BIOMETRIC,AGENT_ONLY'], // 07-Nov-2026: QR Attendance; 12-Nov-2026: QR + Biometric
            // Biometric Gate: auto = follow device setup, on/off = explicit override.
            'biometric_gate'      => ['nullable', 'in:auto,on,off'],
            // Privacy: skip capturing raw-IP / local-IP websites (logs them as "Unknown source").
            'exclude_ip_sites'    => ['nullable', 'boolean'],
            // Company-wide default tracking mode (org levels below can still override).
            'tracking_mode'       => ['nullable', 'in:FULL,NO_SCREENSHOTS,PRESENCE_ONLY,EXCLUDED'],
            // 20-Oct-2026 (Ejaz, DPDP Act): hide card / Aadhaar / PAN / account numbers in LiveView, Live Wall and recordings.
            'mask_sensitive'      => ['nullable', 'boolean'],
            'mask_types'          => ['nullable', 'array'],
            'mask_types.*'        => ['in:' . implode(',', \App\Services\SensitiveData::TYPES)],
            // Section 3: per-company break-time limits (minutes). Positive, capped at 10h.
            'break_limit_lunch_min' => ['nullable', 'integer', 'min:1', 'max:600'],
            'break_limit_tea_min'   => ['nullable', 'integer', 'min:1', 'max:600'],
            'break_limit_other_min' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        // 25-Sep-2026: a non-admin reaches here only through an Organisation/Biometric
        // card's Edit tick — each card may change its own settings and nothing else.
        if (! $request->user()->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN')) {
            $fieldCards = [
                'attendance_mode'       => ['org.attendance_source'],
                'biometric_gate'        => ['org.attendance_source', 'biometric.gate_to_pc'],
                'timezone'              => ['org.company_timezone'],
                'break_limit_lunch_min' => ['org.break_limits'],
                'break_limit_tea_min'   => ['org.break_limits'],
                'break_limit_other_min' => ['org.break_limits'],
                'exclude_ip_sites'      => ['org.privacy_rawip'],
                'tracking_mode'         => ['org.privacy_rawip'], // company-wide tracking mode sits on the same Organisation card group
                'mask_sensitive'        => ['org.privacy_rawip'], // 20-Oct-2026: same Privacy card group
                'mask_types'            => ['org.privacy_rawip'],
            ];
            $held = $request->user()->permissionSlugs();
            foreach (array_keys($data) as $field) {
                $cards = $fieldCards[$field] ?? [];
                abort_unless($cards && \App\Support\CardAccess::holds($held, $cards, 'edit'), 403, "Your role cannot change {$field}.");
            }
        }

        // Section 3: record who changed a break limit and its old→new values.
        $breakKeys = ['break_limit_lunch_min', 'break_limit_tea_min', 'break_limit_other_min'];
        $before = $company->only($breakKeys);

        // 07-Nov-2026 (Ejaz): Attendance source = QR Attendance needs the licence feature, like the module itself.
        if (in_array($data['attendance_mode'] ?? null, ['QR', 'QR_BIOMETRIC'], true) && ! \Ametecs\Qronex\Qronex::host()->licensed((int) $company->id)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['attendance_mode' => 'Your licence does not include QR Attendance. Ask Ametecs to switch it on (Central → licence → QR Attendance: Yes).']);
        }

        $company->update($data);

        // The Attendance source is the ONE switch for the QR module: QR / QR + Biometric → module on; Biometric / agent only → off.
        if (array_key_exists('attendance_mode', $data) && $data['attendance_mode']) {
            $qrOn = in_array($data['attendance_mode'], ['QR', 'QR_BIOMETRIC'], true);
            \Ametecs\Qronex\Qronex::settings((int) $company->id)->fill(['enabled' => $qrOn, 'tenant_id' => (int) $company->id])->save();
            if ($qrOn) {
                \Ametecs\Qronex\Services\EmployeeSync::run((int) $company->id); // same as the module's own Enable did
            }
        }

        $meta = $data;
        $changed = array_intersect_key($data, array_flip($breakKeys));
        if ($changed) {
            $meta['break_limits_before'] = array_intersect_key($before, $changed);
        }
        $this->audit($request, 'UPDATE', Company::class, $company->id, $meta);

        return response()->json(['data' => $company]);
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        $user = $request->user();
        abort_if(! $user->isSuperAdmin() && $user->company_id !== $company->id, 403, 'Outside your tenant.');
    }
}

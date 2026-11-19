<?php

namespace App\Qronex;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * 07-Nov-2026 (Ejaz): the employee changes their own photo in the EPT QR Attendance app (tap the initials);
 * admins see the same photo in Attendance. Stored once, on the EPT employee (employees.photo_path, private disk).
 *   GET  /api/qronex/v1/photo   → {photo: base64 JPEG} or 404   (phone, device token + signature)
 *   POST /api/qronex/v1/photo   {photo: base64 JPEG/PNG}         (phone)
 *   GET  /api/employee-photo/{id}  signed link, for <img> in the console (Attendance rows)
 */
class PhotoController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private function employee(Request $request): Employee
    {
        $device = $request->attributes->get('device');
        abort_unless($device->status === 'approved', 403, 'Your phone must be approved first.');
        $emp = Employee::withoutGlobalScope('company')->where('company_id', $device->tenant_id)->find($device->employee->host_employee_id);
        abort_unless($emp, 404, 'Employee no longer exists in SmartEPT.');

        return $emp;
    }

    public function show(Request $request): JsonResponse
    {
        $emp = $this->employee($request);
        $disk = Storage::disk('local');
        abort_unless($emp->photo_path && $disk->exists($emp->photo_path), 404, 'No photo yet.');

        return response()->json(['photo' => base64_encode($disk->get($emp->photo_path))]);
    }

    public function store(Request $request): JsonResponse
    {
        $emp = $this->employee($request);
        $raw = base64_decode((string) $request->input('photo'), true);
        $info = $raw && strlen($raw) <= self::MAX_BYTES ? @getimagesizefromstring($raw) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 64 || $info[1] < 64) {
            return response()->json(['message' => 'Please choose a JPEG or PNG photo (at least 64 × 64, up to 2 MB).'], 422);
        }
        $disk = Storage::disk('local');
        $path = 'employee-photos/'.$emp->company_id.'/'.$emp->id.($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        if ($emp->photo_path && $emp->photo_path !== $path) {
            $disk->delete($emp->photo_path);
        }
        $disk->put($path, $raw);
        $emp->forceFill(['photo_path' => $path])->save();

        return response()->json(['ok' => true]);
    }

    /** Console <img>: signed, short-lived link (an <img> can't send the admin's token). */
    public function file(int $employee)
    {
        $emp = Employee::withoutGlobalScope('company')->find($employee);
        $disk = Storage::disk('local');
        abort_unless($emp && $emp->photo_path && $disk->exists($emp->photo_path), 404);

        return response($disk->get($emp->photo_path), 200, [
            'Content-Type' => str_ends_with($emp->photo_path, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public static function url(?Employee $emp): ?string
    {
        return $emp && $emp->photo_path
            ? URL::temporarySignedRoute('employee.photo', now()->addHours(6), ['employee' => $emp->id, 'v' => substr(md5((string) $emp->updated_at), 0, 8)])
            : null;
    }
}

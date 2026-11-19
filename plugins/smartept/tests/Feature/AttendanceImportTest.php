<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AttendanceImportController;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** 07-Nov-2026 (Ejaz): Attendance → Bulk upload (CSV / Excel) and its sample file. */
class AttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_adds_corrects_and_reports_bad_rows_and_preview_saves_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@ametecs.io')->firstOrFail();
        $emp = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $d1 = now()->subDays(2)->format('d-m-Y');
        $d2 = now()->subDays(3);
        EmployeeAttendanceLog::create(['company_id' => $admin->company_id, 'employee_id' => $emp->id, 'work_date' => $d2->toDateString(),
            'status' => 'ABSENT', 'source' => 'CLIENT']);

        $csv = "Employee Code,Date,Status,Check-in,Check-out,Reason\n"
            ."E-1001,{$d1},Present,9:05 AM,6:10 PM,Reader down\n"
            ."E-1001,{$d2->format('d-m-Y')},Half day,9:30 AM,1:30 PM,\n"
            ."NOPE-1,{$d1},Present,9:00 AM,,x\n"
            ."E-1001,31-31-2026,Present,,,x\n";
        $file = fn () => UploadedFile::fake()->createWithContent('att.csv', $csv);
        $token = $admin->createToken('admin-web', ['role:COMPANY_ADMIN'])->plainTextToken;

        $this->withToken($token)->post('/api/attendance/import', ['file' => $file(), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.created', 1)->assertJsonPath('summary.updated', 1)->assertJsonPath('summary.failed', 2);
        $this->assertSame(1, EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp->id)->count()); // preview saved nothing

        $this->withToken($token)->post('/api/attendance/import', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.created', 1)->assertJsonPath('results.2.error', 'Employee code "NOPE-1" not found.');

        $new = EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp->id)->whereDate('work_date', now()->subDays(2)->toDateString())->firstOrFail();
        $this->assertSame(['PRESENT', 'MANUAL', '09:05', '18:10'], [$new->status, $new->source, $new->check_in_at->format('H:i'), $new->check_out_at->format('H:i')]);
        $fixed = EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp->id)->whereDate('work_date', $d2->toDateString())->firstOrFail();
        $this->assertSame(['HALF_DAY', 'MANUAL'], [$fixed->status, $fixed->source]);
        $this->assertStringContainsString('Bulk upload', $fixed->notes);

        $this->withToken($token)->get('/api/attendance/import-template')->assertOk();
    }

    public function test_date_and_time_parsing(): void
    {
        $this->assertSame('2026-11-07', AttendanceImportController::date('07-11-2026'));
        $this->assertSame('2026-11-07', AttendanceImportController::date('2026-11-07'));
        $this->assertSame('2026-11-07', AttendanceImportController::date('07/11/2026'));
        $this->assertNull(AttendanceImportController::date('31-31-2026'));
        $this->assertSame('18:10', AttendanceImportController::time('2026-11-07', '6:10 PM')->format('H:i'));
        $this->assertSame('00:05', AttendanceImportController::time('2026-11-07', '12:05 AM')->format('H:i'));
        $this->assertSame('09:30', AttendanceImportController::time('2026-11-07', 0.395833333)->format('H:i'));
        $this->assertNull(AttendanceImportController::time('2026-11-07', '25:00'));
    }
}

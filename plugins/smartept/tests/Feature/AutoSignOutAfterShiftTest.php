<?php

namespace Tests\Feature;

use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 10-Nov-2026 (Ejaz: Altaf's laptop, lid closed at 18:04, was still signed in at 20:52): once shift end + N has passed the
 * PC is signed out whatever the punches say; an Idle / locked PC is signed out without waiting. Seeded GEN shift 09:00–18:00,
 * 2026-07-06 = MON.
 */
class AutoSignOutAfterShiftTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone'), 'gate_enabled' => true]);
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $e->shift->update(['post_shift_auto_logout_minutes' => 15]); // cutoff 18:15
        $e->update(['work_outside_shift' => true]);

        return $e;
    }

    public function test_pc_locked_after_shift_with_a_punch_out_is_signed_out_at_the_cutoff(): void
    {
        $e = $this->employee();
        EmployeeLoginSession::create(['company_id' => 1, 'employee_id' => $e->id, 'session_type' => 'CLIENT',
            'login_at' => '2026-07-06 16:10:08', 'logout_at' => '2026-07-06 18:04:36', 'logout_reason' => 'LOCK']);
        BiometricLog::insert([['company_id' => 1, 'biometric_device_id' => null, 'biometric_employee_id' => (string) $e->employee_code,
            'employee_id' => $e->id, 'punch_type' => 'OUT', 'punched_at' => '2026-07-06 18:06:00', 'processed' => true,
            'created_at' => now(), 'updated_at' => now()]]);
        $this->travelTo(Carbon::parse('2026-07-06 18:20:00'));
        $dev = EmployeeDevice::create(['company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-lid',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE', 'last_login_at' => '2026-07-06 16:10:07', 'last_heartbeat_at' => now()]);

        $this->artisan('smartept:auto-logout', ['--employee' => $e->id])->assertExitCode(0);
        $this->assertSame('FORCE_LOGOUT', $dev->refresh()->session_status);
    }

    public function test_idle_pc_past_the_cutoff_is_signed_out_without_waiting(): void
    {
        $e = $this->employee();
        EmployeeLoginSession::create(['company_id' => 1, 'employee_id' => $e->id, 'session_type' => 'CLIENT', 'login_at' => '2026-07-06 09:05:00']);
        $this->travelTo(Carbon::parse('2026-07-06 18:16:00'));
        EmployeeDevice::create(['company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-idle',
            'session_status' => 'ACTIVE', 'current_status' => 'IDLE', 'last_login_at' => '2026-07-06 09:04:59', 'last_heartbeat_at' => now()]);

        $this->artisan('smartept:auto-logout', ['--employee' => $e->id])->assertExitCode(0);
        $this->assertNotNull(EmployeeLoginSession::where('employee_id', $e->id)->value('logout_at'));
    }
}

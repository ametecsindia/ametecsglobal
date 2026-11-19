<?php

namespace Tests\Feature;

use App\Console\Commands\AutoLogoutPostShift;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 20-Oct-2026 (Ejaz): "Still working?" after shift end is asked on the shift's Nth minute of
 * continuous Idle — not every N minutes. Working = never asked; each "Yes" restarts the Idle count.
 * Seeded GEN shift is 09:00–18:00; 2026-07-06 = MON.
 */
class StillWorkingIdleTest extends TestCase
{
    use RefreshDatabase;

    public function test_asked_only_after_n_minutes_idle_beyond_shift(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $e->shift->update(['post_shift_auto_logout_minutes' => 240, 'still_working_every_min' => 30]);
        $e->update(['work_outside_shift' => true]);
        EmployeeLoginSession::create(['company_id' => 1, 'employee_id' => $e->id, 'session_type' => 'CLIENT', 'login_at' => '2026-07-06 09:05:00']);
        $dev = EmployeeDevice::create(['company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-sw-1',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE']);

        $at = function (string $t, string $status) use ($dev, $e) {
            $this->travelTo(Carbon::parse('2026-07-06 ' . $t));
            $dev->refresh()->forceFill(['current_status' => $status === 'ACTIVE' ? 'ONLINE' : $status, 'last_heartbeat_at' => now()])->save();
            AutoLogoutPostShift::noteIdle($dev, $status);
            $this->artisan('smartept:auto-logout', ['--employee' => $e->id])->assertExitCode(0);

            return $dev->refresh()->still_working_asked_at;
        };

        $this->assertNull($at('18:20:00', 'ACTIVE'), 'working beyond shift: no popup (was asked at 18:10 before)');
        $this->assertNull($at('19:30:00', 'ACTIVE'), 'still working: no fixed-interval popup');
        $this->assertNull($at('19:31:00', 'IDLE'), 'Idle starts');
        $this->assertNull($at('20:00:00', 'IDLE'), '29 min Idle: not yet');
        $this->assertNotNull($at('20:01:00', 'IDLE'), '30th minute Idle: asked');

        // "Yes" at 20:01:30 → Idle counted again from the answer.
        $dev->forceFill(['still_working_asked_at' => null, 'still_working_until' => Carbon::parse('2026-07-06 20:01:30')])->save();
        $this->assertNull($at('20:20:00', 'IDLE'), '19 min Idle after Yes: not yet');
        $this->assertNotNull($at('20:32:00', 'IDLE'), '30 min Idle after Yes: asked again');

        // No answer within the window → signed out.
        $this->travelTo(Carbon::parse('2026-07-06 20:34:00'));
        $dev->forceFill(['last_heartbeat_at' => now()])->save();
        $this->artisan('smartept:auto-logout', ['--employee' => $e->id])->assertExitCode(0);
        $this->assertNotNull(EmployeeLoginSession::where('employee_id', $e->id)->value('logout_at'));
    }
}

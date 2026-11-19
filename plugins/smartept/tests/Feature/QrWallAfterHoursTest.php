<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 08-Nov-2026 (Ejaz): "punched in at the QR scanner, the app says checked IN, but the agent signed out". The walk to the
 * scanner (phone to the locker, back to the desk) can take minutes, during which the agent sends no heartbeats. Neither
 * that wait nor the first heartbeat after the gate opens may sign an after-hours worker out.
 */
class QrWallAfterHoursTest extends TestCase
{
    use RefreshDatabase;

    private function signInAt(string $at, string $uuid): array
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse($at)); // MON, after the seeded 18:00 shift end
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => $uuid, 'computer_name' => 'QR-PC'])
            ->assertCreated()->json('device_token');
        $e = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
        $e->shift->update(['post_shift_auto_logout_minutes' => 120]); // cutoff 20:00
        Employee::withoutGlobalScopes()->whereKey($e->id)->update(['work_outside_shift' => true]);

        return [$e, $device];
    }

    public function test_the_first_heartbeat_after_a_long_wait_at_the_scanner_keeps_the_agent_signed_in(): void
    {
        [$e, $device] = $this->signInAt('2026-07-06 20:10:00', 'QR-1');

        // 20:18 — the gate opened after an 8-minute walk to the scanner; the work session starts now.
        $this->travelTo(Carbon::parse('2026-07-06 20:18:00'));
        $session = EmployeeLoginSession::create(['company_id' => $e->company_id, 'employee_id' => $e->id, 'session_type' => 'CLIENT',
            'device_uuid' => 'QR-1', 'login_at' => '2026-07-06 20:18:00']);
        $this->app['auth']->forgetGuards();
        Cache::flush();
        $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'QR-1'])->assertOk();
        $this->assertNull($session->fresh()->logout_at);
    }

    public function test_a_pc_waiting_on_the_scan_screen_is_signed_out_after_ten_minutes(): void
    {
        [$e] = $this->signInAt('2026-07-06 20:10:00', 'QR-2');
        \App\Models\Company::withoutGlobalScopes()->whereKey($e->company_id)->update(['gate_enabled' => true]); // no punch yet = wall up

        // 10-Nov-2026 (Ejaz): walking to the scanner is given at most 10 minutes, then the agent is signed out.
        $this->travelTo(Carbon::parse('2026-07-06 20:19:00'));
        Artisan::call('smartept:auto-logout');
        $this->assertSame('ACTIVE', EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'QR-2')->value('session_status'));

        $this->travelTo(Carbon::parse('2026-07-06 20:21:00'));
        Artisan::call('smartept:auto-logout');
        $this->assertSame('FORCE_LOGOUT', EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'QR-2')->value('session_status'));
    }

    public function test_the_first_heartbeat_landing_before_the_sign_in_record_does_not_sign_out(): void
    {
        [$e, $device] = $this->signInAt('2026-07-06 20:10:00', 'QR-3');
        \App\Models\Company::withoutGlobalScopes()->whereKey($e->company_id)->update(['gate_enabled' => true]);

        // 20:18 — QR IN; the agent's heartbeat reaches the server before its sign-in record (no session row yet).
        $this->travelTo(Carbon::parse('2026-07-06 20:18:00'));
        \App\Models\BiometricLog::insert([['company_id' => $e->company_id, 'biometric_device_id' => null, 'biometric_employee_id' => (string) $e->employee_code,
            'employee_id' => $e->id, 'punch_type' => 'IN', 'punched_at' => now(), 'external_id' => 'qx:test-3', 'processed' => true,
            'created_at' => now(), 'updated_at' => now()]]);
        $this->app['auth']->forgetGuards();
        Cache::flush();
        $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'QR-3'])->assertOk();
        $this->assertSame('ACTIVE', EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'QR-3')->value('session_status'));
    }

    public function test_sign_in_after_hours_is_refused_up_front_unless_work_outside_shift_is_allowed(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $e = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
        $e->shift->update(['post_shift_auto_logout_minutes' => 120]); // cutoff 20:00

        $this->travelTo(Carbon::parse('2026-07-06 20:10:00'));
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => 'QR-4', 'computer_name' => 'QR-PC'])
            ->assertStatus(403)->assertJsonPath('error.code', 'OUTSIDE_SHIFT'); // told why — no QR wall, no scan, no later kick

        Employee::withoutGlobalScopes()->whereKey($e->id)->update(['work_outside_shift' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => 'QR-4', 'computer_name' => 'QR-PC'])
            ->assertCreated();
    }
}

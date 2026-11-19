<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 09-Nov-2026 (Ejaz): check-in showed 2:31 AM — an agent sign-in stamped before the day's QR IN (8:51 AM) became the check-in. */
class SignInBeforeGateInTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sign_in_stamped_before_the_gate_in_never_becomes_the_check_in(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone'), 'gate_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-07-06 08:50:00'));
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => 'GI-1', 'computer_name' => 'GI-PC'])->assertCreated()->json('device_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($device)->postJson('/api/agent/consent', ['device_uuid' => 'GI-1', 'acknowledged' => true, 'consent_text_hash' => str_repeat('a', 64)]);
        $e = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();

        // 08:51 — QR IN at the entrance; the gate opens.
        $this->travelTo(Carbon::parse('2026-07-06 08:51:00'));
        \App\Models\BiometricLog::insert([['company_id' => $e->company_id, 'biometric_device_id' => null, 'biometric_employee_id' => (string) $e->employee_code,
            'employee_id' => $e->id, 'punch_type' => 'IN', 'punched_at' => '2026-07-06 08:51:00', 'external_id' => 'qx:gi-1', 'processed' => true,
            'created_at' => now(), 'updated_at' => now()]]);

        // 08:53 — the agent's queue sends a sign-in + lock stamped 02:31 (PC on the wrong time zone at start-up).
        $this->travelTo(Carbon::parse('2026-07-06 08:53:00'));
        foreach (['LOGIN', 'LOCK'] as $type) {
            $this->app['auth']->forgetGuards();
            $this->withToken($device)->postJson('/api/agent/attendance-event', ['device_uuid' => 'GI-1', 'event_type' => $type, 'occurred_at' => '2026-07-06 02:31:18'])
                ->assertOk()->assertJsonPath('ignored', 'BEFORE_GATE_IN');
        }
        $this->assertSame(0, EmployeeLoginSession::where('employee_id', $e->id)->count());

        // The real sign-in after the scan counts as usual.
        $this->app['auth']->forgetGuards();
        $this->withToken($device)->postJson('/api/agent/attendance-event', ['device_uuid' => 'GI-1', 'event_type' => 'LOGIN', 'occurred_at' => '2026-07-06 08:53:00'])->assertSuccessful();
        $this->assertSame('2026-07-06 08:53:00', EmployeeLoginSession::where('employee_id', $e->id)->value('login_at')->toDateTimeString());
        $in = EmployeeAttendanceLog::where('employee_id', $e->id)->whereDate('work_date', '2026-07-06')->value('check_in_at');
        $this->assertNotSame('02:31:18', Carbon::parse($in)->format('H:i:s'));
    }
}

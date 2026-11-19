<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\InstallationLicense;
use App\Models\ScreenRecording;
use App\Models\ScreenRecordingSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 15-Oct-2026 — Screen Recording: Commander only, seats from the licence, upload → play back → scheduled cleanup. */
class ScreenRecordingTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private string $deviceToken;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('evidence');
        $this->companyId = (int) Company::query()->orderBy('id')->value('id');

        $userToken = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', ['device_uuid' => 'SR-DEVICE'])->json('device_token');
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', ['device_uuid' => 'SR-DEVICE', 'acknowledged' => true])->assertCreated();
        $this->employeeId = (int) Employee::withoutGlobalScopes()->whereRelation('user', 'email', 'priya.raman@ametecs.io')->value('id');
        // On a real server the LiveView migration granted liveview.* to every existing role; in a
        // fresh test DB the roles are seeded after it ran, so give the company admin those slugs here.
        \App\Models\Role::where('slug', 'COMPANY_ADMIN')->first()?->permissions()
            ->syncWithoutDetaching(\App\Models\Permission::where('slug', 'like', 'liveview.%')->pluck('id'));
    }

    private function plan(string $tier, int $seats): void
    {
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-SREC-0000-ABCD', 'status' => 'active', 'last_checked_at' => now(),
            'bundle' => ['kind' => 'subscription', 'tier' => $tier, 'status' => 'active',
                'features' => ['enforcement' => $tier !== 'standard', 'live_view' => $tier === 'commander', 'recording_max_users' => $seats],
                'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7, 'device_limit' => 100],
        ])->save();
    }

    private function admin(): void
    {
        $u = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk()->json('token'));
    }

    private function upload(array $over = [])
    {
        return $this->withToken($this->deviceToken)->post('/api/agent/recording/upload', $over + [
            'device_uuid' => 'SR-DEVICE', 'trigger' => 'continuous',
            'started_at' => now()->subMinute()->toIso8601String(), 'ended_at' => now()->toIso8601String(),
            'video' => UploadedFile::fake()->create('segment.webm', 300, 'video/webm'),
        ], ['Accept' => 'application/json']);
    }

    public function test_enforcer_plan_gets_nothing(): void
    {
        $this->plan('enforcer', 5);
        $this->withToken($this->deviceToken)->getJson('/api/agent/recording/config?device_uuid=SR-DEVICE')->assertOk()->assertJsonPath('enabled', false);
        $this->upload()->assertStatus(403)->assertJsonPath('error.code', 'RECORDING_NOT_PERMITTED');
        $this->admin();
        $this->getJson('/api/screen-recording/access')->assertOk()->assertJsonPath('data.plan', false);
        $this->getJson('/api/screen-recording/settings')->assertStatus(403);
    }

    public function test_seat_limit_record_upload_play_and_cleanup(): void
    {
        $this->plan('commander', 1);
        $this->admin();
        $this->getJson('/api/screen-recording/access')->assertOk()->assertJsonPath('data.can_manage', true);

        // Not seated yet → the agent stays idle and uploads are refused.
        $this->withToken($this->deviceToken)->getJson('/api/agent/recording/config?device_uuid=SR-DEVICE')->assertJsonPath('enabled', false);

        $this->admin();
        $this->postJson('/api/screen-recording/seat', ['employee_id' => $this->employeeId, 'enabled' => true])->assertOk()->assertJsonPath('data.used', 1);
        $other = Employee::withoutGlobalScopes()->where('company_id', $this->companyId)->where('id', '!=', $this->employeeId)->value('id');
        $this->postJson('/api/screen-recording/seat', ['employee_id' => $other, 'enabled' => true])->assertStatus(409)->assertJsonPath('error.code', 'RECORDING_LIMIT_REACHED');

        $this->putJson('/api/screen-recording/settings', [
            'modes' => ['continuous', 'on_demand'], 'quality' => 'economy', 'watched_apps' => [' excel ', ''], 'excluded_apps' => ['keepass'],
            'pre_seconds' => 60, 'post_seconds' => 300, 'pause_on_idle' => true, 'pause_on_break' => true, 'monitors' => 'all',
            'show_indicator' => true, 'upload_kbps' => 256, 'local_cap_mb' => 1024, 'retention_days' => 7,
            'cleanup_frequency' => 'weekly', 'cleanup_time' => '03:30', 'cleanup_every_days' => 1, 'cleanup_weekday' => 1, 'cleanup_month_day' => 1,
        ])->assertOk()->assertJsonPath('data.watched_apps', ['excel']);
        $this->postJson('/api/screen-recording/record-now', ['employee_id' => $this->employeeId, 'minutes' => 30])->assertOk();

        $cfg = $this->withToken($this->deviceToken)->getJson('/api/agent/recording/config?device_uuid=SR-DEVICE')->assertOk();
        $cfg->assertJsonPath('enabled', true)->assertJsonPath('fps', 1)->assertJsonPath('monitors', 'all')->assertJsonPath('upload_kbps', 256);
        $this->assertNotNull($cfg->json('on_demand_until'));

        $this->upload()->assertCreated();
        $this->upload(['trigger' => 'dashcam', 'trigger_detail' => 'BLOCKED APP: whatsapp.exe'])->assertCreated();
        $this->assertSame(2, ScreenRecording::withoutGlobalScopes()->count());

        $this->admin();
        $day = $this->getJson('/api/screen-recording/recordings?employee_id=' . $this->employeeId . '&date=' . now()->toDateString())->assertOk();
        $this->assertCount(2, $day->json('data.segments'));
        $id = $day->json('data.segments.0.id');
        $this->get('/api/screen-recording/recordings/' . $id . '/file')->assertOk();

        // Cleanup: not due before Monday 03:30 on the company clock; --force removes anything past retention.
        $s = ScreenRecordingSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->first();
        $this->assertFalse($s->cleanupDue(Carbon::parse('2026-10-19 03:00')));  // Monday, before the time
        $this->assertTrue($s->cleanupDue(Carbon::parse('2026-10-19 03:31')));   // Monday, after the time
        $this->assertFalse($s->cleanupDue(Carbon::parse('2026-10-20 03:31')));  // Tuesday
        ScreenRecording::withoutGlobalScopes()->whereKey($id)->update(['started_at' => now()->subDays(10)]);
        Artisan::call('smartept:recordings-cleanup', ['--company' => $this->companyId, '--force' => true]);
        $this->assertSame(1, ScreenRecording::withoutGlobalScopes()->count());

        // Revoking the seat stops the agent at once and refuses further uploads.
        $this->postJson('/api/screen-recording/seat', ['employee_id' => $this->employeeId, 'enabled' => false])->assertOk();
        $this->withToken($this->deviceToken)->getJson('/api/agent/recording/config?device_uuid=SR-DEVICE')->assertJsonPath('enabled', false);
        $this->upload()->assertStatus(403);
    }

    public function test_cleanup_schedules(): void
    {
        $s = new ScreenRecordingSetting(['cleanup_time' => '02:00', 'cleanup_frequency' => 'daily']);
        $this->assertTrue($s->cleanupDue(Carbon::parse('2026-10-15 02:05')));
        $s->last_cleanup_at = Carbon::parse('2026-10-15 02:05');
        $this->assertFalse($s->cleanupDue(Carbon::parse('2026-10-15 23:00'))); // once per day
        $this->assertTrue($s->cleanupDue(Carbon::parse('2026-10-16 02:00')));

        $s->cleanup_frequency = 'every_n_days'; $s->cleanup_every_days = 3;
        $this->assertFalse($s->cleanupDue(Carbon::parse('2026-10-17 02:10')));
        $this->assertTrue($s->cleanupDue(Carbon::parse('2026-10-18 02:10')));

        $s->cleanup_frequency = 'monthly'; $s->cleanup_month_day = 31; $s->last_cleanup_at = null;
        $this->assertTrue($s->cleanupDue(Carbon::parse('2026-11-30 02:10')));  // 31 → last day of a 30-day month
        $this->assertFalse($s->cleanupDue(Carbon::parse('2026-11-29 02:10')));
    }
}

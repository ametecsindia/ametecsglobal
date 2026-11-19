<?php

namespace Tests\Feature;

use Ametecs\Qronex\Models\AttendanceEvent;
use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Office;
use App\Demo\DemoAccount;
use App\Demo\DemoSeeder;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 10-Nov-2026 (Ejaz): Explore Demo. smartept.com → secret-signed /api/provision/demo → a demo company with 10 employees,
 * two months of simulated QR punches / sessions / activity, derived by the console's own code; phones auto-approved, QR
 * accepted anywhere, the demo activation code always works. Wednesday 15:00 so today is a working day half done.
 */
class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['services.provision.secret' => 'demo-secret']);
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(15, 0));
    }

    private function provision(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Provision-Secret' => 'demo-secret'])->postJson('/api/provision/demo', $extra + [
            'external_tenant_id' => '9001', 'company_name' => 'Sunrise Finance', 'admin_email' => 'owner@sunrise.test', 'admin_name' => 'Asha',
            'slug' => 'sunrise', 'city' => 'chennai', 'admin_password' => 'Secret#123',
        ]);
    }

    public function test_demo_company_is_built_with_two_months_of_reportable_data(): void
    {
        $r = $this->provision()->assertCreated();
        $this->assertSame('sunrise', $r->json('slug'));
        $company = Company::where('slug', 'sunrise')->firstOrFail();
        $acc = DemoAccount::where('company_id', $company->id)->firstOrFail();

        $this->assertSame('ready', $acc->status); // built after the response (afterResponse runs in tests too)
        $this->assertCount(10, $acc->employee_ids);
        $this->assertSame('QR', $company->fresh()->attendance_mode);
        $this->assertStringEndsWith('Chennai Office', Office::withoutGlobalScopes()->where('tenant_id', $company->id)->value('name'));

        // The owner signs in with the password they chose on smartept.com — no temp password, no forced change.
        $token = $this->postJson('/api/auth/login', ['email' => 'owner@sunrise.test', 'password' => 'Secret#123'])->assertOk()->json('token');

        // History: present days have a derived QR attendance row, absent working days are marked, today is under way.
        $days = EmployeeAttendanceLog::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertGreaterThan(300, $days->where('status', 'PRESENT')->count());
        $this->assertGreaterThan(0, AttendanceEvent::withoutGlobalScopes()->where('tenant_id', $company->id)->where('mode', 'AGENT_SIGNOUT')->count());
        $this->assertTrue($days->contains(fn ($d) => $d->work_date->isToday() || (string) $d->work_date === now()->toDateString()));

        // The console's own Productivity report reads it: a week of rows with worked time, breaks and idle.
        $rep = $this->withToken($token)->getJson('/api/reports/productivity?from=' . now()->subDays(7)->toDateString() . '&to=' . now()->subDay()->toDateString())->assertOk();
        $this->assertGreaterThan(20, $rep->json('count'));

        // Status for the console notice: demo, ready, nobody online.
        $this->withToken($token)->getJson('/api/demo/status')->assertOk()->assertJson(['demo' => true, 'status' => 'ready', 'online' => 0, 'company_code' => 'sunrise']);

        // Top-up is idempotent: the same day regenerated gives the same punches, never duplicates.
        $before = AttendanceEvent::withoutGlobalScopes()->where('tenant_id', $company->id)->count();
        DemoSeeder::topUp($acc->fresh());
        DemoSeeder::topUp($acc->fresh());
        $this->assertSame($before, AttendanceEvent::withoutGlobalScopes()->where('tenant_id', $company->id)->count());

        // 11-Nov-2026: EMP001 is the visitor's / reviewer's own phone — nothing at all is simulated for it; EMP002 has history.
        $DB = \Illuminate\Support\Facades\DB::class;
        $emp1 = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', DemoSeeder::OWN_PHONE_EMPLOYEE)->firstOrFail();
        $emp2 = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', 'EMP002')->firstOrFail();
        $qx1 = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $emp1->id)->value('id');
        $qx2 = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $emp2->id)->value('id');
        $none = fn () => [AttendanceEvent::withoutGlobalScopes()->where('employee_id', $qx1)->count(),
            EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp1->id)->count(),
            \App\Models\EmployeeDailySummary::withoutGlobalScopes()->where('employee_id', $emp1->id)->count(),
            $DB::table('employee_devices')->where('employee_id', $emp1->id)->count(),
            $DB::table('employee_login_sessions')->where('employee_id', $emp1->id)->count(),
            Device::withoutGlobalScopes()->where('employee_id', $qx1)->count()];
        $this->assertSame([0, 0, 0, 0, 0, 0], $none());
        $this->assertGreaterThan(20, EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp2->id)->count());

        // A demo built before this change (EMP001 simulated too) is cleaned once by the next top-up; EMP002 is untouched.
        $emp2Before = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $qx2)->count();
        $old = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $qx2)->first()->replicate();
        $old->forceFill(['employee_id' => $qx1, 'ref' => 'QXD' . \Illuminate\Support\Str::random(10)])->save();
        EmployeeAttendanceLog::create(['company_id' => $company->id, 'employee_id' => $emp1->id, 'work_date' => now()->subDays(3)->toDateString(),
            'source' => 'MANUAL', 'status' => 'ABSENT', 'notes' => 'Auto-marked absent']);
        DemoSeeder::topUp($acc->fresh());
        $this->assertSame([0, 0, 0, 0, 0, 0], $none());
        $this->assertSame($emp2Before, AttendanceEvent::withoutGlobalScopes()->where('employee_id', $qx2)->count());

        // 11-Nov-2026: keyboard/mouse_activity are booleans — live MySQL (tinyint) refused counts and the whole build stopped.
        $this->assertSame([], \Illuminate\Support\Facades\DB::table('employee_activity_events')->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('keyboard_activity', '>', 1)->orWhere('mouse_activity', '>', 1))->pluck('id')->all());

        // A build that stopped part-way is finished by the 15-minute job.
        \Illuminate\Support\Facades\DB::table('demo_accounts')->where('id', $acc->id)->update(['status' => 'building', 'updated_at' => now()->subMinutes(20)]);
        $this->artisan('smartept:demo-topup')->assertSuccessful();
        $this->assertSame('ready', $acc->fresh()->status);
        $this->assertSame($before, AttendanceEvent::withoutGlobalScopes()->where('tenant_id', $company->id)->count());

        // 16-Nov-2026: the Live Dashboard looks like a real office — at 11:30 AM on a working day the sample PCs of those at
        // work send live heartbeats (Active or Idle); EMP001 has no PC.
        $this->travelTo(\Illuminate\Support\Carbon::parse(now('Asia/Kolkata')->startOfWeek()->addDays(2)->toDateString() . ' 11:30', 'Asia/Kolkata'));
        DemoSeeder::topUp($acc->fresh());
        $live = \Illuminate\Support\Facades\DB::table('employee_devices')->where('company_id', $company->id)
            ->whereIn('current_status', ['ONLINE', 'IDLE'])->where('last_heartbeat_at', '>=', now()->subMinutes(2))->count();
        $this->assertGreaterThanOrEqual(5, $live);
        $this->assertLessThanOrEqual(9, $live);
        $this->travelTo(now()->addHours(10));   // 9:30 PM: everyone has gone home
        \App\Demo\DemoSeeder::live($acc->fresh());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('employee_devices')->where('company_id', $company->id)->where('current_status', '!=', 'OFFLINE')->count());
    }

    /** 11-Nov-2026: a demo with an e-mail that already has a login (even a disabled one) is refused and that login is untouched. */
    public function test_existing_email_is_refused_and_its_login_untouched(): void
    {
        $real = Company::create(['name' => 'Real Co', 'code' => 'REAL', 'slug' => 'realco2']);
        $u = \App\Models\User::create(['name' => 'Old Hand', 'email' => 'owner@sunrise.test', 'password' => 'Original#1', 'company_id' => $real->id, 'status' => 'DISABLED']);
        $this->provision()->assertStatus(409);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Original#1', $u->fresh()->password));
        $this->assertSame($real->id, $u->fresh()->company_id);
        $this->assertFalse(Company::where('slug', 'sunrise')->exists());
    }

    public function test_phone_activates_with_the_demo_code_and_is_approved_without_an_admin(): void
    {
        $this->provision()->assertCreated();
        $company = Company::where('slug', 'sunrise')->firstOrFail();
        $code = DemoAccount::where('company_id', $company->id)->value('activation_code');
        $emp = QxEmployee::withoutGlobalScopes()->where('tenant_id', $company->id)->where('employee_code', DemoSeeder::OWN_PHONE_EMPLOYEE)->firstOrFail();

        // 12-Nov-2026: the reviewer signs in as EMP001 with the demo password; a new phone is then asked only for the activation code.
        $this->postJson('/api/qronex/v1/sign-in', ['company' => 'sunrise', 'login' => 'EMP001', 'password' => 'Secret#123', 'install_ref' => str_repeat('r', 20)])
            ->assertOk()->assertJson(['registered' => false, 'employee' => ['code' => 'EMP001']]);

        foreach (['a', 'b'] as $n) { // two different phones (e.g. two Google reviewers) — both approved, the code still works
            $r = $this->postJson('/api/qronex/v1/activate', ['company' => 'sunrise', 'employee_code' => $emp->employee_code,
                'activation_code' => $code, 'platform' => 'android', 'model' => 'Pixel 9', 'install_ref' => str_repeat($n, 20)])->assertCreated();
            $this->assertSame('approved', $r->json('device.status'));
        }
        $this->assertSame(0, Device::withoutGlobalScopes()->where('tenant_id', $company->id)->where('status', 'pending')->count());

        // A brand-new office made in the demo also skips the location check; a real company's office never does.
        $o = Office::withoutGlobalScopes()->create(['tenant_id' => $company->id, 'code' => 'BR2', 'slug' => 'br2', 'name' => 'Branch 2', 'lat' => 0, 'lng' => 0]);
        $this->assertTrue($o->fresh()->skip_geofence);
        $real = Company::create(['name' => 'Real Co', 'code' => 'REAL', 'slug' => 'real']);
        $o2 = Office::withoutGlobalScopes()->create(['tenant_id' => $real->id, 'code' => 'HQ', 'slug' => 'hq', 'name' => 'HQ', 'lat' => 0, 'lng' => 0]);
        $this->assertFalse((bool) $o2->fresh()->skip_geofence);
    }

    public function test_wrong_secret_is_refused_and_no_demo_is_made(): void
    {
        $this->withHeaders(['X-Provision-Secret' => 'nope'])->postJson('/api/provision/demo', [
            'external_tenant_id' => '1', 'company_name' => 'X', 'admin_email' => 'x@x.test', 'city' => 'pune', 'admin_password' => 'Secret#123',
        ])->assertStatus(401);
        $this->assertSame(0, DemoAccount::count());
    }

    public function test_real_company_console_has_no_demo_notice(): void
    {
        $this->provision()->assertCreated();
        Company::create(['name' => 'Real Co', 'code' => 'REAL', 'slug' => 'realco']);
        // views are not in this test copy; the notice middleware is checked directly
        $mw = new \App\Demo\DemoNotice();
        $html = fn ($path) => $mw->handle(\Illuminate\Http\Request::create($path), fn () => response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html']))->getContent();
        $this->assertStringContainsString('ept-demo-note', $html('/sunrise'));
        $this->assertStringNotContainsString('ept-demo-note', $html('/realco'));
        $this->assertStringNotContainsString('ept-demo-note', $html('/admin'));
    }
}

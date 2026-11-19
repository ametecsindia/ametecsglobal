<?php

namespace Tests\Feature;

use Ametecs\Qronex\Models\Device;
use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\QrToken;
use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QR attendance module (packages/qronex-module) inside SmartEPT: off by default, opened from the console by a one-time
 * sign-in link, employees synced, QR punch → biometric_logs → the same merge/gate/derive fan-out → attendance day row.
 * Wednesday 08:00 like the other attendance tests, so the seeded General shift (09:00–18:00) is a working day.
 */
class QronexModuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(8, 0));
        $this->admin = User::where('email', 'admin@ametecs.io')->firstOrFail();
        $this->company = Company::findOrFail($this->admin->company_id);
        $this->company->update(['slug' => 'ametecs']);
        $this->emp = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    /** EPT's TestCase forgets auth guards before every request, so sign in through the web session like the SSO link does. */
    private function as(User $u): static
    {
        return $this->withSession([$this->app['auth']->guard('web')->getName() => $u->id]);
    }

    /** Organisation → Attendance source (07-Nov-2026: the ONE switch for QR attendance). */
    private function setSource(string $mode): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('admin-web', ['role:COMPANY_ADMIN'])->plainTextToken)
            ->putJson('/api/companies/'.$this->company->id, ['attendance_mode' => $mode]);
    }

    private function enableWithGate(): Gate
    {
        $this->setSource('QR')->assertOk();
        $this->assertTrue(Qronex::enabled($this->company->id));
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->as($this->admin);
        $this->post('/qronex/offices', ['code' => 'HQ', 'name' => 'Head Office', 'lat' => 17.44862, 'lng' => 78.39081, 'geofence_radius_m' => 150, 'is_active' => 1])->assertSessionHasNoErrors();
        Qronex::setTenant(null);

        return Gate::withoutGlobalScopes()->firstOrFail();
    }

    private function phone(QxEmployee $e, string $ref = 'e5'): array
    {
        $e->forceFill(['activation_code_hash' => bcrypt('ABCD2345'), 'activation_expires_at' => now()->addHour()])->save();
        $r = $this->postJson('/api/qronex/v1/activate', ['company' => 'ametecs', 'employee_code' => $e->employee_code, 'activation_code' => 'ABCD2345',
            'platform' => 'android', 'model' => 'Pixel 8', 'install_ref' => str_repeat($ref, 12)]);

        return [$r, $r->json('token')];
    }

    /**
     * 12-Nov-2026 (Ejaz): the app signs in with the SmartEPT login (e-mail or employee code + password). The phone already
     * registered to the employee — proved by its hardware key — gets straight in; sign out ends only the token; a phone
     * that is not registered is told so and only then asks for the activation code.
     */
    public function test_phone_signs_in_with_the_smartept_login_and_needs_no_new_code(): void
    {
        $this->enableWithGate();
        $qx = QxEmployee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $user = User::create(['name' => 'E One', 'email' => 'e1001@ametecs.test', 'password' => 'Pass#1234', 'company_id' => $this->company->id,
            'role_id' => \App\Models\Role::where('slug', 'EMPLOYEE')->whereNull('company_id')->value('id'), 'status' => 'ACTIVE']);
        $this->emp->forceFill(['user_id' => $user->id])->save();

        // A fixed test key: Windows PHP cannot make an EC key without an openssl.cnf (openssl_pkey_new returns false there).
        $key = openssl_pkey_get_private(<<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgLaiJK3oJ4sggcjYd
3YKjTAEBZR7E/7UjvgBvenVYizWhRANCAARGH7hAXCQyhRLu7IwUBH/M4Jukk/gg
77G69BMwvN/iqxUV/ZKxxABQfAYa5xWJqYNyPpZyocqX2+c3KDcpvYWL
-----END PRIVATE KEY-----
PEM);
        $der = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($key)['key'])));
        $ref = str_repeat('s1', 10);
        $qx->forceFill(['activation_code_hash' => bcrypt('ABCD2345'), 'activation_expires_at' => now()->addHour()])->save();
        $this->postJson('/api/qronex/v1/activate', ['company' => 'ametecs', 'employee_code' => 'E-1001', 'activation_code' => 'ABCD2345',
            'platform' => 'android', 'install_ref' => $ref, 'public_key' => $der])->assertCreated();

        // What the app sends: JSON body, signed with the phone's key when it has one (Api.call).
        $send = function (string $path, array $data, ?string $token = null, bool $signed = true) use ($key) {
            $uri = '/api/qronex/v1/'.$path;
            $body = json_encode($data);
            $h = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : []);
            if ($signed) {
                $ts = (string) time();
                $nonce = \Illuminate\Support\Str::random(24);
                openssl_sign(\Ametecs\Qronex\Services\DeviceSignature::canonical('POST', $uri, $ts, $nonce, $body), $sig, $key, OPENSSL_ALGO_SHA256);
                $h += ['HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => base64_encode($sig)];
            }

            return $this->call('POST', $uri, [], [], [], $h, $body);
        };
        $in = fn (string $login, string $pw = 'Pass#1234', ?string $r = null, bool $signed = true) => $send('sign-in', ['company' => 'ametecs', 'login' => $login, 'password' => $pw, 'install_ref' => $r ?? $ref], null, $signed);
        $device = fn () => Device::withoutGlobalScopes()->where('install_ref', $ref)->firstOrFail();

        $in('e1001@ametecs.test', 'wrong')->assertStatus(422);

        $r = $in('e1001@ametecs.test')->assertOk()->assertJson(['registered' => true, 'employee' => ['code' => 'E-1001']]);
        $this->assertSame(hash('sha256', $r->json('token')), $device()->token_hash);

        $status = $device()->status;
        $send('sign-out', [], $r->json('token'))->assertOk();
        $this->assertNull($device()->token_hash);
        $this->assertSame($status, $device()->status); // still registered

        $r = $in('E-1001')->assertOk()->assertJson(['registered' => true]); // employee code works too, no activation code needed
        $this->assertSame(hash('sha256', $r->json('token')), $device()->token_hash);

        // Another phone (or this install without its key) is not let in — the app then asks only for the activation code.
        $in('e1001@ametecs.test', 'Pass#1234', str_repeat('o9', 10), false)->assertOk()->assertJson(['registered' => false, 'employee' => ['code' => 'E-1001']])->assertJsonMissing(['token']);
        $in('e1001@ametecs.test', 'Pass#1234', null, false)->assertOk()->assertJson(['registered' => false]);

        // A login from another company is refused even with the right password.
        $other = Company::create(['name' => 'Other', 'code' => 'OTH', 'slug' => 'oth']);
        $user->forceFill(['company_id' => $other->id])->save();
        $in('e1001@ametecs.test')->assertStatus(422);
    }

    public function test_console_opens_the_module_with_a_one_time_link(): void
    {
        $token = $this->admin->createToken('admin-web', ['role:COMPANY_ADMIN'])->plainTextToken;
        $url = $this->withToken($token)->getJson('/api/qronex/sso-link')->assertOk()->json('url');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('qronex.dashboard'));
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->get($url)->assertForbidden(); // single use

        $this->flushSession(); // Sanctum would otherwise reuse the admin's web session
        $empUser = User::where('email', 'priya.raman@ametecs.io')->first();
        $this->withToken($empUser->createToken('x')->plainTextToken)->getJson('/api/qronex/sso-link')->assertForbidden();
    }

    public function test_off_by_default_and_only_admins_switch_it(): void
    {
        $this->as($this->admin)->get('/qronex')->assertRedirect('/qronex/settings');
        $this->get('/qronex/settings')->assertOk()->assertSee('QR attendance is OFF');
        $this->as(User::where('email', 'manager@ametecs.io')->first())->post('/qronex/settings/toggle', ['enabled' => 1])->assertForbidden();
        $this->as(User::where('email', 'priya.raman@ametecs.io')->first())->get('/qronex/settings')->assertForbidden();
        $this->postJson('/api/qronex/v1/activate', ['company' => 'ametecs', 'employee_code' => 'E-1001', 'activation_code' => 'X', 'platform' => 'android', 'install_ref' => str_repeat('a', 20)])->assertStatus(422);
    }

    public function test_qr_punch_becomes_smartept_attendance_like_a_biometric_punch(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        $this->assertSame('Priya Raman', $e->name);

        [$r, $token] = $this->phone($e);
        $r->assertCreated();
        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate'])
            ->assertSessionHas('ok');

        $this->travelTo(now()->setTime(9, 25));
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr'], 'lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false])
            ->assertOk()->assertJsonPath('event.type', 'IN');

        $log = BiometricLog::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($this->emp->id, $log->employee_id);
        $this->assertSame('IN', $log->punch_type);
        $this->assertStringStartsWith('qx:', $log->external_id);
        $this->assertSame('09:25', $log->punched_at->format('H:i'));

        $day = EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $this->emp->id)->whereDate('work_date', now()->toDateString())->firstOrFail();
        $this->assertSame('09:25', $day->check_in_at->format('H:i'));
        $this->assertGreaterThan(0, (int) $day->late_minutes); // 09:00 shift, 10 min grace → late, exactly as for a door punch

        \Ametecs\Qronex\Services\HostSync::push(\Ametecs\Qronex\Models\AttendanceEvent::withoutGlobalScopes()->first());
        $this->assertSame(1, BiometricLog::withoutGlobalScopes()->where('company_id', $this->company->id)->count()); // retry-safe

        // 07-Nov-2026: the Attendance sheet shows the source as "QR Attendance" (stored as a door punch underneath).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin->createToken('admin-web', ['role:COMPANY_ADMIN'])->plainTextToken)
            ->getJson('/api/attendance?date='.now()->toDateString().'&employee_id='.$this->emp->id)
            ->assertOk()->assertJsonPath('data.0.source', 'QR Attendance');
    }

    /** 07-Nov-2026: the app's Dashboard tab — portal token only for an approved phone while punched IN; OUT revokes it. */
    public function test_portal_session_only_while_punched_in(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        [$r, $token] = $this->phone($e);
        $this->withToken($token)->postJson('/api/qronex/v1/portal-session')->assertForbidden()->assertJsonPath('reason_code', 'device_not_approved');

        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate']);
        $this->flushSession();
        $this->withToken($token)->postJson('/api/qronex/v1/portal-session')->assertForbidden()->assertJsonPath('reason_code', 'punched_out');

        // 10-Nov-2026: QR codes follow the app clock (QrToken uses now()), so travelTo moves them too and each scan
        // below gets its own rotation; $ago still lets a scan use the previous rotation.
        $scan = fn (int $ago = 0) => $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3, now()->getTimestamp() - $ago)['qr'], 'lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false])->assertOk();
        $this->travelTo(now()->setTime(9, 5));
        $scan();
        // Any role gets in (here a Manager), but the app session is always the employee's own view, read-only.
        $this->emp->user->update(['role_id' => \App\Models\Role::whereNull('company_id')->where('slug', 'MANAGER')->value('id'), 'must_change_password' => true]);
        $portal = $this->withToken($token)->postJson('/api/qronex/v1/portal-session')->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($portal)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', $this->emp->user->email)
            ->assertJsonPath('user.role', 'EMPLOYEE')->assertJsonPath('user.must_change_password', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($portal)->postJson('/api/auth/refresh')->assertForbidden(); // view-only: no writes, no escape to a full token
        $this->assertSame('MANAGER', $this->emp->user->fresh()->roleSlug()); // the real login is untouched

        $this->travelTo(now()->setTime(18, 10));
        $scan(3); // OUT
        $this->assertSame(0, $this->emp->user->tokens()->where('name', 'qr-app')->count());
        $this->withToken($token)->postJson('/api/qronex/v1/portal-session')->assertForbidden()->assertJsonPath('reason_code', 'punched_out');
    }

    /** 07-Nov-2026: the employee sets their photo from the app; admins get a signed link to it in Attendance. */
    public function test_photo_from_app_shows_for_admins(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        [$r, $token] = $this->phone($e);
        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate']);
        $this->flushSession();

        $this->withToken($token)->getJson('/api/qronex/v1/photo')->assertNotFound();
        $this->withToken($token)->postJson('/api/qronex/v1/photo', ['photo' => base64_encode('not an image')])->assertStatus(422);
        ob_start(); imagejpeg(imagecreatetruecolor(128, 128)); $jpg = ob_get_clean();
        $this->withToken($token)->postJson('/api/qronex/v1/photo', ['photo' => base64_encode($jpg)])->assertOk();
        $this->assertSame(base64_encode($jpg), $this->withToken($token)->getJson('/api/qronex/v1/photo')->assertOk()->json('photo'));

        $url = \App\Qronex\PhotoController::url($this->emp->fresh());
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=x', $url))->assertForbidden();
        // Thumbnails: QR Attendance › Employees and SmartEPT › Employees.
        $this->as($this->admin)->get('/qronex/employees')->assertOk()->assertSee('employee-photo/'.$this->emp->id, false);
        $this->flushSession();
        $tok = $this->admin->createToken('admin-web', ['role:COMPANY_ADMIN'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $row = collect($this->withToken($tok)->getJson('/api/employees?per_page=100')->assertOk()->json('data'))->firstWhere('id', $this->emp->id);
        $this->assertStringContainsString('employee-photo/'.$this->emp->id, $row['photo_url']);
    }

    /** 07-Nov-2026: activation (and every QR email) goes out through SmartEPT's MailService — company SMTP + mail log — not Laravel's default mailer. */
    public function test_activation_email_goes_through_smartept_mail(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        $e->update(['office_id' => $gate->office_id]);
        // 09-Nov-2026: the details come back under the clicked employee's row (page returns scrolled to it), not as a toast.
        $r = $this->as($this->admin)->post("/qronex/employees/{$e->id}/activation")->assertSessionHas('activation.id', $e->id);
        $this->assertStringEndsWith('#emp-'.$e->id, $r->headers->get('Location'));

        $log = \App\Models\MailLog::query()->where('to', $e->email)->latest('id')->firstOrFail();
        $this->assertSame((int) $this->company->id, (int) $log->company_id);
        $this->assertSame('Activate the EPT QR Attendance app', $log->subject);
    }

    /** 07-Nov-2026: "My day" — the QR punch shows as today's IN, with the day-ledger figures; unapproved phones get nothing. */
    public function test_my_day_shows_todays_punch_and_figures(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        [$r, $token] = $this->phone($e);
        $this->withToken($token)->getJson('/api/qronex/v1/my-day')->assertForbidden();

        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate']);
        $this->flushSession();
        $this->travelTo(now()->setTime(9, 5));
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr'], 'lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false])->assertOk();

        $this->withToken($token)->getJson('/api/qronex/v1/my-day')->assertOk()
            ->assertJsonPath('date', now()->toDateString())
            ->assertJsonPath('punches.0.in', '09:05')
            ->assertJsonStructure(['sign_ins', 'seconds' => ['present', 'active', 'idle', 'away', 'break', 'meeting'], 'break_count', 'late_minutes', 'productivity', 'break_allowed', 'break_left', 'breaks', 'idle',
                'break_limits' => [['type', 'limit', 'used', 'left']]])
            ->assertJsonPath('break_limits.0.type', 'Lunch');
    }

    /** 07-Nov-2026: agent window "Attendance scanner" → the entrance display of the employee's own office gate. */
    public function test_agent_scanner_opens_own_office_gate_display(): void
    {
        $gate = $this->enableWithGate();
        $this->flushSession();
        $agent = $this->emp->user->createToken('device:test-pc', ['agent'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($agent)->getJson('/api/agent/qr-scanner')->assertNotFound(); // no office assigned yet

        QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->update(['office_id' => $gate->office_id]);
        $this->app['auth']->forgetGuards();
        $res = $this->withToken($agent)->getJson('/api/agent/qr-scanner')->assertOk()->assertJsonCount(1, 'gates');
        $url = $res->json('url');
        $this->assertSame($url, $res->json('gates.0.url'));
        $this->assertStringContainsString("/qronex/display/{$gate->id}?key=", $url);
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk();
    }

    public function test_disable_stops_phones_and_keeps_data(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        $e->update(['office_id' => $gate->office_id]);
        [, $token] = $this->phone($e);
        $this->withToken($token)->getJson('/api/qronex/v1/me')->assertOk();

        $this->setSource('BIOMETRIC')->assertOk(); // switching the Attendance source away from QR turns the module off
        $this->withToken($token)->getJson('/api/qronex/v1/me')->assertForbidden()->assertJsonPath('reason_code', 'module_disabled');
        $this->getJson("/qronex/display/{$gate->id}/token?key={$gate->display_secret}")->assertJsonPath('active', false);
        $this->assertSame(1, Device::withoutGlobalScopes()->count());
    }

    public function test_licence_without_qr_attendance_blocks_enabling(): void
    {
        $host = new class extends \App\Qronex\EptHost { public function licensed(int $tenantId): bool { return false; } };
        $this->app->instance(\Ametecs\Qronex\Contracts\Host::class, $host);
        $this->as($this->admin)->get('/qronex/settings')->assertOk()->assertSee('does not include QR attendance');
        $this->post('/qronex/settings/toggle', ['enabled' => 1])->assertForbidden();
        $this->setSource('QR')->assertStatus(422)->assertJsonValidationErrors('attendance_mode');
    }

    /** 07-Nov-2026 (Ejaz): ledger export — IN/OUT pairs or every punch; CSV, Excel, PDF; Company, Office, Gate, ID, Name first. */
    public function test_ledger_export_layouts_and_formats(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        $e->update(['office_id' => $gate->office_id]);
        $day = now('Asia/Kolkata')->setTime(9, 0);
        foreach ([['IN', 0], ['OUT', 150], ['IN', 155], ['OUT', 9 * 60]] as $i => [$type, $min]) {
            \Ametecs\Qronex\Models\AttendanceEvent::create(['ref' => 'QRX-T'.$i, 'tenant_id' => $e->tenant_id, 'employee_id' => $e->id,
                'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'type' => $type, 'qr_window' => $i, 'recorded_at' => $day->copy()->addMinutes($min)->timezone(config('app.timezone'))]);
        }
        $this->as($this->admin);
        $q = '/qronex/attendance/export?from='.$day->toDateString();

        $csv = $this->get($q.'&format=csv&layout=pairs')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(substr($csv, 3))))));
        $this->assertSame(['Company', 'Office', 'Gate', 'Employee ID', 'Employee name', 'Date', 'IN time', 'OUT time', 'Duration'], array_slice($lines[0], 0, 9));
        $this->assertCount(3, $lines); // header + 2 pairs
        $this->assertSame(['09:00:00 AM', '11:30:00 AM', '2h 30m'], array_slice($lines[1], 6, 3));
        $this->assertSame(['11:35:00 AM', '06:00:00 PM', '6h 25m'], array_slice($lines[2], 6, 3));

        $punch = $this->get($q.'&format=csv&layout=punches')->assertOk()->streamedContent();
        $this->assertCount(5, array_filter(explode("\n", trim($punch)))); // header + 4 punches
        $this->assertStringStartsWith('PK', $this->get($q.'&format=xlsx&layout=pairs')->assertOk()->streamedContent());
        $this->get($q.'&format=pdf&layout=pairs')->assertOk()->assertSee('Attendance — IN / OUT')->assertSee('2h 30m');

        // Filters: Company shown; employee picked from the list ("Name (CODE)") matches exactly.
        \Ametecs\Qronex\Models\Device::withoutGlobalScopes()->create(['tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'platform' => 'android', 'install_ref' => 'dev-x', 'status' => 'approved']);
        $this->get('/qronex/attendance?from='.$day->toDateString())->assertOk()->assertSee('Company')->assertSee($e->name.' ('.$e->employee_code.')');
        $this->assertCount(3, array_filter(explode("\n", trim($this->get($q.'&format=csv&layout=pairs&q='.urlencode($e->name.' ('.$e->employee_code.')'))->streamedContent()))));
        $this->assertCount(1, array_filter(explode("\n", trim($this->get($q.'&format=csv&layout=pairs&q='.urlencode('Nobody (ZZ9)'))->streamedContent()))));
    }

    /** 08-Nov-2026 (Ejaz): an employee ticked for two offices may punch at either; the Main office stays theirs. */
    public function test_employee_can_be_given_several_offices(): void
    {
        $hq = $this->enableWithGate();
        $this->post('/qronex/offices', ['code' => 'BR', 'name' => 'Branch', 'lat' => 17.38, 'lng' => 78.41, 'geofence_radius_m' => 150, 'is_active' => 1])->assertSessionHasNoErrors();
        Qronex::setTenant(null);
        $br = Gate::withoutGlobalScopes()->where('office_id', '!=', $hq->office_id)->firstOrFail();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();

        $this->get("/qronex/employees/{$e->id}/edit")->assertOk()->assertSee('name="office_ids[]"', false)->assertSee('Branch');
        $this->put("/qronex/employees/{$e->id}", ['office_ids' => [], 'scan_scope' => 'office'])->assertSessionHasErrors('office_ids');
        $this->put("/qronex/employees/{$e->id}", ['office_ids' => [$hq->office_id, $br->office_id], 'office_id' => $br->office_id, 'scan_scope' => 'office'])->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame((int) $br->office_id, (int) $e->office_id); // Main
        $this->assertNull($e->scanBlockedAt($hq->load('office')));
        $this->assertNull($e->scanBlockedAt($br));
        $e->update(['office_ids' => [$hq->office_id], 'office_id' => $hq->office_id]);
        $this->assertStringContainsString('Assigned office', (string) $e->fresh()->scanBlockedAt($br));
        $this->get('/qronex/employees?office='.$hq->office_id)->assertOk()->assertSee($e->name);
    }

    /** 10-Nov-2026 (Ejaz): a demo / testing office can skip the location check (Google Play reviewer); other checks stay. */
    public function test_demo_office_can_skip_the_location_check(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        [$r, $token] = $this->phone($e);
        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate'])
            ->assertSessionHas('ok');
        $delhi = ['lat' => 28.6139, 'lng' => 77.2090, 'accuracy' => 12, 'mocked' => false]; // ~1,250 km from the office

        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr']] + $delhi)
            ->assertStatus(422)->assertJsonPath('reason_code', 'OUTSIDE_GEOFENCE');

        $office = ['code' => 'HQ', 'name' => 'Head Office', 'lat' => 17.44862, 'lng' => 78.39081, 'geofence_radius_m' => 150, 'is_active' => 1];
        $this->as($this->admin)->get("/qronex/offices/{$gate->office_id}/edit")->assertOk()->assertSee('name="skip_geofence"', false);
        $this->put("/qronex/offices/{$gate->office_id}", $office + ['skip_geofence' => 1])->assertSessionHasNoErrors();
        $this->get('/qronex/offices')->assertOk()->assertSee('Location check skipped');
        Qronex::setTenant(null);

        $ok = $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr'], 'accuracy' => 450] + $delhi)->assertOk()->assertJsonPath('event.type', 'IN'); // far away and weak GPS
        $this->assertStringContainsString('Skipped', collect($ok->json('checks'))->firstWhere('label', 'Location requirement')['detail']);
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr'], 'mocked' => true] + $delhi)
            ->assertStatus(422)->assertJsonPath('reason_code', 'MOCK_LOCATION'); // fake-location still refused

        $this->as($this->admin)->put("/qronex/offices/{$gate->office_id}", $office)->assertSessionHasNoErrors(); // untick
        Qronex::setTenant(null);
        $this->assertFalse(\Ametecs\Qronex\Models\Office::withoutGlobalScopes()->find($gate->office_id)->skip_geofence);
    }

    /** 10-Nov-2026 (Ejaz): QR Attendance — sign out = punch OUT, punch OUT = sign out, so every sign-in needs a fresh QR IN. */
    public function test_sign_out_is_punch_out_and_punch_out_is_sign_out(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        [$r, $token] = $this->phone($e);
        $d = Device::withoutGlobalScopes()->first();
        $this->as($this->admin)->post("/qronex/devices/{$d->id}/approve", ['code' => $r->json('device.approval_code'), 'office_id' => $gate->office_id, 'gate_id' => $gate->id, 'scan_scope' => 'gate'])
            ->assertSessionHas('ok');
        $at = ['lat' => 17.4488, 'lng' => 78.3909, 'accuracy' => 12, 'mocked' => false];
        $pc = \App\Models\EmployeeDevice::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->emp->id,
            'device_uuid' => 'pc-qr-signout', 'session_status' => 'ACTIVE', 'current_status' => 'ONLINE']);
        $gateState = fn () => app(\App\Services\GateService::class)->stateFor($this->emp->fresh());
        $events = fn () => \Ametecs\Qronex\Models\AttendanceEvent::withoutGlobalScopes()->where('employee_id', $e->id)->orderBy('id')->get();

        $this->travelTo(now()->setTime(9, 5));
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr']] + $at)->assertOk()->assertJsonPath('event.type', 'IN');
        $this->assertSame('IN', $gateState()['state']);

        // Sign out of the agent → OUT in the QR ledger and the gate closes: the next sign-in needs a QR punch IN.
        $this->travelTo(now()->setTime(13, 0));
        $pc->update(['last_heartbeat_at' => now()->subMinute()]);
        $pc->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
        $this->assertSame(['IN', 'OUT'], $events()->pluck('type')->all());
        $this->assertSame('AGENT_SIGNOUT', $events()->last()->mode);
        $this->assertSame('OUT', $gateState()['state']);
        $pc->update(['session_status' => 'ACTIVE']);
        $pc->update(['session_status' => 'FORCE_LOGOUT']); // signing out again while already OUT adds nothing
        $this->assertCount(2, $events());

        // The next QR scan is a punch IN (not a second OUT) and opens the gate again.
        $this->travelTo(now()->setTime(14, 0));
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr']] + $at)->assertOk()->assertJsonPath('event.type', 'IN');
        $this->assertSame('IN', $gateState()['state']);

        // Punch OUT on the phone → the agent is signed out (SmartDCM follows the agent session).
        $pc->update(['session_status' => 'ACTIVE']);
        $this->travelTo(now()->setTime(18, 5));
        $this->withToken($token)->postJson('/api/qronex/v1/scan', ['qr' => QrToken::make($gate, 3)['qr']] + $at)->assertOk()->assertJsonPath('event.type', 'OUT');
        $this->assertSame('FORCE_LOGOUT', $pc->fresh()->session_status);
        $this->assertSame(['IN', 'OUT', 'IN', 'OUT'], $events()->pluck('type')->all()); // no extra OUT from that sign-out
    }

    public function test_every_screen_renders_and_relieved_employees_are_revoked(): void
    {
        $gate = $this->enableWithGate();
        $e = QxEmployee::withoutGlobalScopes()->where('host_employee_id', $this->emp->id)->firstOrFail();
        foreach (['/qronex', '/qronex/attendance', '/qronex/devices', '/qronex/employees', "/qronex/employees/{$e->id}/edit", '/qronex/offices', '/qronex/offices/create',
            '/qronex/approvals', '/qronex/remote', '/qronex/settings'] as $url) {
            $this->as($this->admin)->get($url)->assertOk();
        }
        $this->get("/qronex/display/{$gate->id}?key={$gate->display_secret}")->assertOk();

        $e->update(['office_id' => $gate->office_id]);
        $this->phone($e);
        $this->emp->update(['employment_status' => 'RELIEVED']);
        $this->post('/qronex/employees/sync')->assertSessionHas('ok');
        $this->assertSame('inactive', $e->fresh()->status);
        $this->assertSame('revoked', Device::withoutGlobalScopes()->first()->status);
    }

    public function test_qronex_path_opens_the_module_not_the_company_catch_all(): void
    {
        $this->get('/qronex')->assertRedirect('/admin'); // the module (sends you to sign in), not the tenant catch-all 404
    }

    /** 11-Nov-2026 (Ejaz): people only ever see SmartEPT names — the gate screen names the EPT QR Attendance app, never QRONEX. */
    public function test_gate_display_names_the_ept_app_never_qronex(): void
    {
        $gate = $this->enableWithGate();
        $this->get(route('qronex.display', [$gate->id, 'key' => $gate->display_secret]))->assertOk()
            ->assertSee('Open the EPT QR Attendance app')->assertDontSee('QRONEX');
    }
}

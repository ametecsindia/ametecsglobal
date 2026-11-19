<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Services\StatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 29-Oct-2026 (Altaf): SmartDCM Sign on — agent sign-in opens SmartDCM signed in; signing out
 * of either ends both; a door punch-out (Away) signs the agent out.
 */
class SmartDcmSsoTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = 'uuid-dcm-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse('2026-07-07 10:00:00'));
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])
            ->assertOk()->json('token');
    }

    /** Switch it on through the Organisation card; returns the one-time secret. */
    private function enable(string $url = 'https://dcm.example.com'): string
    {
        return $this->withToken($this->adminToken())->putJson('/api/integrations/smartdcm', ['enabled' => true, 'url' => $url])
            ->assertOk()->json('data.secret');
    }

    private function signInAgent(): string
    {
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $userToken = $this->postJson('/api/auth/login', ['email' => $e->user->email, 'password' => 'password'])->assertOk()->json('token');

        return $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => self::UUID, 'computer_name' => 'TEST-PC',
        ])->assertSuccessful()->json('device_token');
    }

    private static function b64d(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private function sessionCall(string $secret, int $cid, int $sid, string $action, ?int $ts = null)
    {
        $ts ??= now()->timestamp;

        return $this->postJson('/api/sso/smartdcm/session', [
            'cid' => $cid, 'sid' => $sid, 'ts' => $ts, 'action' => $action,
            'sig' => hash_hmac('sha256', "$action|$cid|$sid|$ts", $secret),
        ]);
    }

    public function test_secret_is_shown_once_encrypted_at_rest_and_never_returned(): void
    {
        $secret = $this->enable();
        $this->assertSame(48, strlen($secret));

        $raw = Company::withoutGlobalScopes()->whereKey(1)->toBase()->value('smartdcm_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, decrypt($raw, false));

        $admin = $this->adminToken();
        $this->withToken($admin)->getJson('/api/integrations/smartdcm')
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.has_secret', true)->assertJsonMissingPath('data.secret');
        $this->assertStringNotContainsString($secret, $this->withToken($admin)->getJson('/api/companies/1')->getContent());

        // A second save keeps the secret; "Generate new secret" replaces it.
        $this->withToken($admin)->putJson('/api/integrations/smartdcm', ['enabled' => true, 'url' => 'https://dcm.example.com'])
            ->assertJsonPath('data.secret', null);
        $new = $this->withToken($admin)->putJson('/api/integrations/smartdcm', ['enabled' => true, 'url' => 'https://dcm.example.com', 'regenerate_secret' => true])->json('data.secret');
        $this->assertNotSame($secret, $new);
    }

    public function test_only_company_admins_reach_the_setting(): void
    {
        $hr = $this->postJson('/api/auth/login', ['email' => 'hr@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($hr)->getJson('/api/integrations/smartdcm')->assertForbidden();
    }

    public function test_ticket_is_off_until_switched_on(): void
    {
        $this->withToken($this->signInAgent())->postJson('/api/agent/smartdcm-ticket')
            ->assertOk()->assertExactJson(['enabled' => false]);
    }

    public function test_ticket_is_signed_short_lived_and_names_the_employee(): void
    {
        $secret = $this->enable();
        $token = $this->signInAgent();

        $url = $this->withToken($token)->postJson('/api/agent/smartdcm-ticket')
            ->assertOk()->assertJsonPath('enabled', true)->json('url');
        $this->assertStringStartsWith('https://dcm.example.com/sso/smartept?ticket=', $url);

        [$body, $sig] = explode('.', rawurldecode(substr($url, strpos($url, '=') + 1)));
        $this->assertTrue(hash_equals(hash_hmac('sha256', $body, $secret, true), self::b64d($sig)));

        $p = json_decode(self::b64d($body), true);
        $this->assertSame(['smartept', 'smartdcm', 'priya.raman@ametecs.io', 1, 'agent'], [$p['iss'], $p['aud'], $p['email'], $p['cid'], $p['via']]);
        $this->assertSame(60, $p['exp'] - $p['iat']);
        $this->assertSame(32, strlen($p['nonce']));
        $this->assertSame(PersonalAccessToken::findToken($token)->id, $p['sid']);
    }

    public function test_session_call_checks_signature_time_and_company(): void
    {
        $secret = $this->enable();
        $sid = PersonalAccessToken::findToken($this->signInAgent())->id;

        $this->sessionCall($secret, 1, $sid, 'status')->assertOk()->assertJsonPath('active', true);
        $this->sessionCall('wrong-secret', 1, $sid, 'status')->assertStatus(401);
        $this->sessionCall($secret, 1, $sid, 'status', now()->timestamp - 120)->assertStatus(401);
        $this->sessionCall($secret, 1, 999999, 'status')->assertOk()->assertJsonPath('active', false);
    }

    public function test_smartdcm_logout_signs_the_agent_out(): void
    {
        $secret = $this->enable();
        $token = $this->signInAgent();
        $sid = PersonalAccessToken::findToken($token)->id;

        $this->sessionCall($secret, 1, $sid, 'logout')->assertOk()->assertJsonPath('active', false);

        $this->assertSame('FORCE_LOGOUT', EmployeeDevice::withoutGlobalScopes()->where('device_uuid', self::UUID)->value('session_status'));
        $this->assertNull(PersonalAccessToken::find($sid));
        $this->withToken($token)->postJson('/api/agent/heartbeat', ['device_uuid' => self::UUID])->assertStatus(401);
        $this->sessionCall($secret, 1, $sid, 'status')->assertJsonPath('active', false);
    }

    public function test_door_punch_out_away_signs_the_agent_out_only_with_smartdcm_sign_on(): void
    {
        $token = $this->signInAgent();
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-07-07 10:05:00'));
        app(StatusService::class)->transition($e, 'OTHER_BREAK', now(), ['manual' => false, 'source' => 'BIOMETRIC']);

        // Off: the door break stays a break, the agent stays signed in.
        $this->withToken($token)->postJson('/api/agent/heartbeat', ['device_uuid' => self::UUID])->assertOk();

        // On: Away → signed out on the next heartbeat.
        $this->enable();
        $this->withToken($token)->postJson('/api/agent/heartbeat', ['device_uuid' => self::UUID])
            ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_ENDED');
    }

    public function test_signing_back_in_while_the_old_away_is_still_open_is_not_kicked_again(): void
    {
        $this->enable();
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        app(StatusService::class)->transition($e, 'OTHER_BREAK', now()->subMinutes(5), ['manual' => false, 'source' => 'BIOMETRIC']);

        $token = $this->signInAgent(); // signed in AFTER the walk-out (late IN punch not here yet)
        $this->withToken($token)->postJson('/api/agent/heartbeat', ['device_uuid' => self::UUID])->assertOk();
    }
}

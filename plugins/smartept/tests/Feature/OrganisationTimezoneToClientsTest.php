<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 09-Nov-2026 (Ejaz): the agent and the phone app show every time in the Organisation time zone — the server tells them which. */
class OrganisationTimezoneToClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_is_told_the_organisation_time_zone(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => 'Asia/Dubai']);
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => 'TZ-1', 'computer_name' => 'TZ-PC'])
            ->assertCreated()->assertJsonPath('timezone', 'Asia/Dubai')->json('device_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($device)->getJson('/api/agent/today')->assertOk()->assertJsonPath('timezone', 'Asia/Dubai');
        $this->app['auth']->forgetGuards();
        $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'TZ-1'])->assertOk()->assertJsonPath('timezone', 'Asia/Dubai');
    }

    public function test_phone_app_is_told_the_organisation_time_zone(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => 'Asia/Dubai']);
        $this->assertSame('Asia/Dubai', (new \App\Qronex\EptHost)->tenant(1)['timezone']);
    }
}

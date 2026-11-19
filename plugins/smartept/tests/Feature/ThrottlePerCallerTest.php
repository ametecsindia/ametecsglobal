<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 08-Nov-2026: one office address must not share one rate-limit counter across every phone, PC and action. */
class ThrottlePerCallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_traffic_from_the_same_address_never_blocks_agent_sign_in(): void
    {
        $this->seed(DatabaseSeeder::class);
        // A busy office: 70 requests from the same address on another throttled route.
        for ($i = 0; $i < 70; $i++) {
            $this->getJson('/api/tenant-branding/nobody');
        }
        $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->assertOk();
    }

    public function test_sign_in_limit_is_per_email_and_explains_itself(): void
    {
        $this->seed(DatabaseSeeder::class);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'someone@ametecs.io', 'password' => 'wrong']);
        }
        $this->postJson('/api/auth/login', ['email' => 'someone@ametecs.io', 'password' => 'wrong'])
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
        // A colleague at the next desk (same office address) still signs in.
        $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->assertOk();
    }
}

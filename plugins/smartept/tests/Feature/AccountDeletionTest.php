<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 16-Nov-2026 (Ejaz, Google Play): "Delete my account" in SmartEPT Admin Console → POST /api/auth/delete-account.
 * The password is checked, the request is audited and mailed, and every session of the login ends; the login itself
 * stays until support / the organisation completes the deletion (a reviewer's tap must not wipe the demo account).
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_my_account_needs_the_password_then_signs_every_session_out(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $company = Company::create(['name' => 'Acme', 'code' => 'ACM', 'slug' => 'acme']);
        $user = User::create(['name' => 'Asha', 'email' => 'asha@acme.test', 'password' => 'Secret#123', 'company_id' => $company->id,
            'role_id' => Role::where('slug', 'COMPANY_ADMIN')->whereNull('company_id')->value('id'), 'status' => 'ACTIVE']);
        $phone = $user->createToken('admin-web')->plainTextToken;
        $user->createToken('admin-web');                       // a second session (e.g. the web console)

        $this->postJson('/api/auth/delete-account', ['password' => 'Secret#123'])->assertStatus(401); // no session, no request
        $this->withToken($phone)->postJson('/api/auth/delete-account', ['password' => 'wrong'])->assertStatus(422);
        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($phone)->postJson('/api/auth/delete-account', ['password' => 'Secret#123', 'reason' => 'Leaving'])
            ->assertOk()->assertJsonFragment(['message' => 'Your request has been sent. Your organisation\'s administrator and Ametecs support '
                . 'will delete your account and its data within 7 working days and confirm to you by e-mail. You have been signed out.']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'ACTIVE']);  // removed later by support, not on the spot
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACCOUNT_DELETION_REQUESTED']);
    }
}

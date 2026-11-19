<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 08-Nov-2026 (Ejaz): the top-right company name is editable from Organisation → Company name. */
class CompanyNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_renames_company_and_me_returns_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $t = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->assertOk()->json('token');
        $me = $this->withToken($t)->getJson('/api/auth/me')->assertOk()->json();
        $me = $me['user'] ?? $me['data'] ?? $me;
        $id = $me['company_id'];

        $this->withToken($t)->putJson('/api/companies/' . $id, ['name' => 'Ametecs India Private Limited'])->assertOk();

        $this->assertSame('Ametecs India Private Limited', \App\Models\Company::find($id)->name);
        $this->assertStringContainsString('Ametecs India Private Limited', $this->withToken($t)->getJson('/api/auth/me')->getContent());

        // A non-admin cannot rename it.
        $u = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($u)->putJson('/api/companies/' . $id, ['name' => 'X'])->assertForbidden();
    }
}

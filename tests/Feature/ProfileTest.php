<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    public function test_profile_is_read_only_and_masks_sensitive_fields(): void
    {
        $u = $this->makeUser(['user', 'senior_security_specialist'], ['phone' => '09131234567']);
        $res = $this->actingAs($u)->getJson('/api/profile')->assertOk();
        $p = $res->json('profile');

        $this->assertSame($u->personnel_code, $p['personnel_code']);
        $this->assertSame('محمد', $p['father_name']);
        $this->assertSame('امور حراست', $p['department']);
        $this->assertSame('شهربابک', $p['work_location']);
        $this->assertSame('رسمی', $p['employment_type']);
        $this->assertCount(2, $p['roles']);
        $this->assertSame('0913****567', $p['phone_masked']);
        $nc = $u->national_code;
        $this->assertSame(substr($nc, 0, 3).'*****'.substr($nc, -2), $p['national_code_masked']);
        $this->assertStringNotContainsString($nc, $res->getContent());
        $this->assertStringNotContainsString('09131234567', $res->getContent());
    }

    public function test_user_cannot_modify_own_profile_fields_via_api(): void
    {
        $u = $this->makeUser(['user']);
        $payload = ['first_name' => 'هکر', 'last_name' => 'X', 'department' => 'Y', 'personnel_code' => '000001', 'is_active' => true];

        // no self-update endpoint exists
        foreach (['put', 'patch', 'post'] as $m) {
            $this->actingAs($u)->json($m, '/api/profile', $payload)->assertStatus(405);
        }
        // the admin endpoint is forbidden for normal users, including on their own record
        $this->actingAs($u)->putJson("/api/admin/users/{$u->id}", $payload)->assertStatus(403);
        $this->actingAs($u)->putJson("/api/admin/users/{$u->id}/roles", ['roles' => []])->assertStatus(403);

        // change-password ignores any extra profile fields
        $this->actingAs($u)->postJson('/api/auth/change-password', $payload + [
            'current_password' => 'Str0ng!Passw0rd', 'password' => 'An0ther!Secure', 'password_confirmation' => 'An0ther!Secure',
        ])->assertOk();

        $fresh = $u->fresh();
        $this->assertSame('علی', $fresh->first_name);
        $this->assertSame('کاربر', $fresh->last_name);
        $this->assertSame('امور حراست', $fresh->department);
        $this->assertSame($u->personnel_code, $fresh->personnel_code);
        $this->assertTrue(Hash::check('An0ther!Secure', $fresh->password));
    }

    public function test_admin_can_edit_user_profile(): void
    {
        $admin = $this->makeUser(['admin']);
        $u = $this->makeUser(['user']);
        $this->actingAs($admin)->putJson("/api/admin/users/{$u->id}", ['first_name' => 'رضا', 'department' => 'امور مالی'])
            ->assertOk()->assertJsonPath('user.first_name', 'رضا');
        $this->assertSame('امور مالی', $u->fresh()->department);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => $u->id]);
    }
}

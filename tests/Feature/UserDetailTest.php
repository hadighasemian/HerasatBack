<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Tests\TestCase;

class UserDetailTest extends TestCase
{
    public function test_admin_sees_user_detail_without_clear_national_code(): void
    {
        $admin = $this->makeUser(['admin']);
        $u = $this->makeUser(['user'], ['father_name' => 'علی', 'employment_type' => 'رسمی', 'department' => 'حراست', 'work_location' => 'شهربابک']);
        $u->setNationalCode('0499370899');
        $u->phone = '09131234567';
        $u->save();

        $res = $this->actingAs($admin)->getJson("/api/admin/users/{$u->id}")->assertOk();
        $res->assertJsonPath('user.father_name', 'علی')
            ->assertJsonPath('user.employment_type', 'رسمی')
            ->assertJsonPath('user.department', 'حراست')
            ->assertJsonPath('user.work_location', 'شهربابک');
        $this->assertStringNotContainsString('0499370899', $res->getContent());
        $this->assertStringNotContainsString('09131234567', $res->getContent());
        $this->assertStringContainsString('*', $res->json('user.national_code_masked'));
    }

    public function test_sensitive_fields_need_permission_and_are_audited(): void
    {
        $admin = $this->makeUser(['admin']);
        $u = $this->makeUser(['user']);
        $u->setNationalCode('0499370899');
        $u->phone = '09131234567';
        $u->save();

        $res = $this->actingAs($admin)->getJson("/api/admin/users/{$u->id}/sensitive")->assertOk()
            ->assertJsonPath('national_code', '0499370899')
            ->assertJsonPath('phone', '09131234567');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertTrue(AuditLog::where('action', 'user.sensitive_viewed')->where('subject_id', $u->id)->where('user_id', $admin->id)->exists());

        // users.view alone is not enough
        $viewer = $this->makeUser(['user']);
        $viewer->givePermissionTo('users.view');
        $this->actingAs($viewer)->getJson("/api/admin/users/{$u->id}")->assertOk();
        $this->actingAs($viewer)->getJson("/api/admin/users/{$u->id}/sensitive")->assertStatus(403);

        // nor is it reachable by a plain user
        $plain = $this->makeUser(['user']);
        $this->actingAs($plain)->getJson("/api/admin/users/{$u->id}/sensitive")->assertStatus(403);
        $this->actingAs($plain)->getJson("/api/admin/users/{$u->id}/login-history")->assertStatus(403);
    }

    public function test_admin_sees_a_users_login_history_only_for_that_user(): void
    {
        $admin = $this->makeUser(['admin']);
        $a = $this->makeUser(['user']);
        $b = $this->makeUser(['user']);
        AuditLogger::log('auth.login', $a, [], $a->id);
        AuditLogger::log('auth.login_failed', $a, [], $a->id);
        AuditLogger::log('auth.login', $b, [], $b->id);
        AuditLogger::log('user.updated', $a, [], $a->id); // not a login event

        $res = $this->actingAs($admin)->getJson("/api/admin/users/{$a->id}/login-history")->assertOk();
        $this->assertEqualsCanonicalizing(['auth.login', 'auth.login_failed'], collect($res->json('data'))->pluck('action')->all());
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame('auth.login', $res->json('last_successful_login.action'));
    }

    public function test_seeder_grants_view_sensitive_to_admin_only(): void
    {
        $admin = $this->makeUser(['admin']);
        $this->assertTrue($admin->hasPermissionTo('users.view_sensitive'));
        $this->assertFalse($this->makeUser(['user'])->hasPermissionTo('users.view_sensitive'));
    }
}

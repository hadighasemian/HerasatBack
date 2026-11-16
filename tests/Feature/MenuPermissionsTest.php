<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Menu visibility is computed on the frontend from /api/auth/me permissions.
 * These tests lock the permission set that gates each dashboard area.
 */
class MenuPermissionsTest extends TestCase
{
    public function test_default_user_has_todos_permission_but_not_admin_or_reports(): void
    {
        $u = $this->makeUser(['user']);
        $res = $this->actingAs($u)->getJson('/api/auth/me')->assertOk();
        $perms = $res->json('user.permissions');
        $this->assertSame(['todos.use'], $perms);

        $this->actingAs($u)->getJson('/api/todos')->assertOk();
        $this->actingAs($u)->getJson('/api/profile')->assertOk();
        $this->actingAs($u)->getJson('/api/profile/login-history')->assertOk();

        $this->actingAs($u)->getJson('/api/reports')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/roles')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/permissions')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/audit-logs')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/content/slides')->assertStatus(403);
    }

    public function test_personnel_security_reviewer_can_open_reports_but_not_admin(): void
    {
        $u = $this->makeUser(['user', 'head_personnel_security']);
        $perms = $this->actingAs($u)->getJson('/api/auth/me')->assertOk()->json('user.permissions');
        $this->assertContains('reports.review', $perms);
        $this->assertContains('todos.use', $perms);
        $this->assertNotContains('users.view', $perms);

        $this->actingAs($u)->getJson('/api/reports')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($u)->getJson('/api/admin/roles')->assertStatus(403);
    }

    public function test_supervisor_can_open_approved_reports_only(): void
    {
        $u = $this->makeUser(['user', 'personnel_security_supervisor']);
        $perms = $this->actingAs($u)->getJson('/api/auth/me')->assertOk()->json('user.permissions');
        $this->assertContains('reports.view_approved', $perms);
        $this->assertNotContains('reports.review', $perms);
        $this->actingAs($u)->getJson('/api/reports')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_admin_has_all_menu_permissions(): void
    {
        $u = $this->makeUser(['admin']);
        $perms = $this->actingAs($u)->getJson('/api/auth/me')->assertOk()->json('user.permissions');
        foreach (['todos.use', 'users.view', 'users.import', 'roles.view', 'permissions.manage', 'audit.view', 'content.manage', 'reports.review', 'reports.view_approved'] as $p) {
            $this->assertContains($p, $perms, "admin missing {$p}");
        }
        $this->actingAs($u)->getJson('/api/todos')->assertOk();
        $this->actingAs($u)->getJson('/api/reports')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/users')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/roles')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/audit-logs')->assertOk();
        $this->actingAs($u)->getJson('/api/admin/content/slides')->assertOk();
    }
}

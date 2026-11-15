<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    public function test_admin_endpoints_work_through_a_real_spa_session(): void
    {
        // regression: under auth:sanctum the default guard is "sanctum"; role->users must still resolve
        $admin = $this->makeUser(['admin'], ['personnel_code' => '777001']);
        $admin->password = 'Adm1n!Strong#Pass';
        $admin->save();
        $this->withHeaders($this->spa)->postJson('/api/auth/login', ['username' => '777001', 'password' => 'Adm1n!Strong#Pass'])->assertOk();
        $this->app['auth']->forgetGuards();

        $roles = $this->withHeaders($this->spa)->getJson('/api/admin/roles')->assertOk();
        $this->assertSame(1, collect($roles->json('roles'))->firstWhere('name', 'admin')['users_count']);
        $this->withHeaders($this->spa)->getJson('/api/admin/permissions')->assertOk();
        $this->withHeaders($this->spa)->getJson('/api/admin/users')->assertOk();
        $this->withHeaders($this->spa)->getJson('/api/admin/audit-logs')->assertOk();
        $this->withHeaders($this->spa)->getJson('/api/admin/content/slides')->assertOk();
    }

    public function test_seeded_roles_have_persian_names(): void
    {
        $this->assertSame(13, Role::count());
        $this->assertSame('رئیس حفاظت پرسنلی و سلامت اقتصادی', Role::findByName('head_personnel_security', 'web')->display_name);
        $this->assertSame('Personnel Security Supervisor', Role::findByName('personnel_security_supervisor', 'web')->title_en);
        $this->assertSame(Permission::count(), Role::findByName('admin', 'web')->permissions()->count());
    }

    public function test_normal_user_gets_403_on_every_admin_endpoint(): void
    {
        $u = $this->makeUser(['user']);
        $target = $this->makeUser(['user']);
        $role = Role::findByName('head_it_support', 'web');
        $endpoints = [
            ['get', '/api/admin/users'], ['post', '/api/admin/users'], ['get', "/api/admin/users/{$target->id}"],
            ['put', "/api/admin/users/{$target->id}"], ['delete', "/api/admin/users/{$target->id}"],
            ['post', "/api/admin/users/{$target->id}/reset-password"], ['post', "/api/admin/users/{$target->id}/unlock"],
            ['put', "/api/admin/users/{$target->id}/roles"], ['post', '/api/admin/users/import'],
            ['get', '/api/admin/roles'], ['post', '/api/admin/roles'], ['put', "/api/admin/roles/{$role->id}"],
            ['put', "/api/admin/roles/{$role->id}/permissions"], ['delete', "/api/admin/roles/{$role->id}"],
            ['get', '/api/admin/permissions'], ['post', '/api/admin/permissions'],
            ['get', '/api/admin/audit-logs'], ['get', '/api/admin/content/slides'], ['post', '/api/admin/content/tips'],
            ['get', '/api/reports'],
        ];
        foreach ($endpoints as [$m, $url]) {
            $this->actingAs($u)->json($m, $url)->assertStatus(403);
        }
    }

    public function test_admin_can_create_user_with_national_code_as_password(): void
    {
        $admin = $this->makeUser(['admin']);
        $nc = UserFactory::validNationalCode();
        $res = $this->actingAs($admin)->postJson('/api/admin/users', [
            'personnel_code' => '654321', 'national_code' => $nc, 'first_name' => 'سارا', 'last_name' => 'احمدی',
            'roles' => [Role::findByName('head_it_support', 'web')->id],
            'is_active' => false, 'must_change_password' => false, 'password' => 'x', // not mass assignable
        ])->assertCreated();
        $this->assertStringNotContainsString($nc, $res->getContent());
        $u = User::where('personnel_code', '654321')->first();
        $this->assertTrue(Hash::check($nc, $u->password));
        $this->assertTrue($u->must_change_password);
        $this->assertTrue($u->is_active);
        $this->assertEqualsCanonicalizing(['user', 'head_it_support'], $u->roles->pluck('name')->all());
    }

    public function test_admin_create_user_rejects_invalid_national_code(): void
    {
        $admin = $this->makeUser(['admin']);
        $this->actingAs($admin)->postJson('/api/admin/users', [
            'personnel_code' => '654321', 'national_code' => '1234567890', 'first_name' => 'a', 'last_name' => 'b',
        ])->assertStatus(422)->assertJsonValidationErrors('national_code');
    }

    public function test_reset_password_deactivate_and_delete(): void
    {
        $admin = $this->makeUser(['admin']);
        $u = $this->makeUser(['user']);
        $this->actingAs($admin)->postJson("/api/admin/users/{$u->id}/reset-password")->assertOk();
        $u->refresh();
        $this->assertTrue(Hash::check($u->national_code, $u->password));
        $this->assertTrue($u->must_change_password);

        $this->actingAs($admin)->putJson("/api/admin/users/{$u->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($u->fresh()->is_active);

        // cannot deactivate / delete yourself
        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$u->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $u->id]);
    }

    public function test_permissions_are_data_driven(): void
    {
        $admin = $this->makeUser(['admin']);
        $u = $this->makeUser(['user', 'head_it_support']);
        $this->actingAs($u)->getJson('/api/admin/audit-logs')->assertStatus(403);

        // admin defines a role, grants an existing action and assigns the role
        $res = $this->actingAs($admin)->postJson('/api/admin/roles', [
            'name' => 'auditor', 'display_name' => 'ممیز', 'permissions' => ['audit.view'],
        ])->assertCreated();
        $this->actingAs($admin)->putJson("/api/admin/users/{$u->id}/roles", ['roles' => [$res->json('role.id')]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u->fresh())->getJson('/api/admin/audit-logs')->assertOk();

        // a brand-new action can be created and is granted to admin automatically
        $this->actingAs($admin)->postJson('/api/admin/permissions', ['name' => 'meetings.manage', 'display_name' => 'مدیریت جلسات'])->assertCreated();
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('meetings.manage', 'web'));

        // and granted to another role
        $support = Role::findByName('head_it_support', 'web');
        $this->actingAs($admin)->putJson("/api/admin/roles/{$support->id}/permissions", ['permissions' => ['meetings.manage']])->assertOk();
        $this->assertTrue($support->fresh()->hasPermissionTo('meetings.manage', 'web'));
    }

    public function test_system_roles_and_permissions_are_protected(): void
    {
        $admin = $this->makeUser(['admin']);
        $adminRole = Role::findByName('admin', 'web');
        $this->actingAs($admin)->deleteJson("/api/admin/roles/{$adminRole->id}")->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => []])->assertStatus(422);
        $p = Permission::findByName('reports.review', 'web');
        $this->actingAs($admin)->deleteJson("/api/admin/permissions/{$p->id}")->assertStatus(422);
    }

    public function test_no_privilege_escalation_by_delegated_user_managers(): void
    {
        // a role that may manage users and roles but is not admin
        $manager = Role::create(['name' => 'user_manager', 'guard_name' => 'web', 'display_name' => 'مدیر کاربران']);
        $manager->syncPermissions(['users.view', 'users.assign_roles', 'roles.view', 'roles.manage']);
        $m = $this->makeUser(['user', 'user_manager']);
        $victim = $this->makeUser(['user']);

        // cannot hand out the admin role
        $this->actingAs($m)->putJson("/api/admin/users/{$victim->id}/roles", ['roles' => [Role::findByName('admin', 'web')->id]])->assertStatus(422);
        // cannot change own roles
        $this->actingAs($m)->putJson("/api/admin/users/{$m->id}/roles", ['roles' => [Role::findByName('admin', 'web')->id]])->assertStatus(422);
        // cannot grant permissions it does not hold
        $this->actingAs($m)->postJson('/api/admin/roles', ['name' => 'evil', 'display_name' => 'x', 'permissions' => ['audit.view']])->assertStatus(422);
        $this->assertFalse($victim->fresh()->hasRole('admin'));
    }

    public function test_me_returns_permissions_for_menu(): void
    {
        $u = $this->makeUser(['user', 'head_personnel_security']);
        $this->actingAs($u)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.permissions', ['reports.review']);
    }
}

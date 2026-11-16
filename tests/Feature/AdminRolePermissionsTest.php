<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

class AdminRolePermissionsTest extends TestCase
{
    public function test_admin_role_permissions_can_be_edited_but_locked_ones_stay(): void
    {
        $admin = $this->makeUser(['admin']);
        $adminRole = Role::findByName('admin', 'web');

        $roles = $this->actingAs($admin)->getJson('/api/admin/roles')->assertOk();
        $this->assertEqualsCanonicalizing(Role::ADMIN_LOCKED_PERMISSIONS, collect($roles->json('roles'))->firstWhere('name', 'admin')['locked_permissions']);
        $this->assertSame([], collect($roles->json('roles'))->firstWhere('name', 'user')['locked_permissions']);

        // remove report and content permissions, keep the locked ones
        $keep = array_merge(Role::ADMIN_LOCKED_PERMISSIONS, ['audit.view']);
        $this->actingAs($admin)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => $keep])
            ->assertOk()->assertJsonPath('role.name', 'admin');
        $this->assertEqualsCanonicalizing($keep, $adminRole->fresh()->permissions->pluck('name')->all());

        // the change is effective: admin lost content.manage
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin->fresh())->getJson('/api/admin/content/slides')->assertStatus(403);
        $this->actingAs($admin->fresh())->getJson('/api/admin/roles')->assertOk();

        // and it can be granted back
        $this->actingAs($admin->fresh())->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => array_merge($keep, ['content.manage'])])->assertOk();
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('content.manage', 'web'));
    }

    public function test_locked_admin_permissions_cannot_be_removed(): void
    {
        $admin = $this->makeUser(['admin']);
        $adminRole = Role::findByName('admin', 'web');
        $before = $adminRole->permissions->pluck('name')->sort()->values()->all();

        foreach (Role::ADMIN_LOCKED_PERMISSIONS as $locked) {
            $perms = array_values(array_diff($before, [$locked]));
            $this->actingAs($admin)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => $perms])
                ->assertStatus(422)->assertJsonValidationErrors('permissions');
        }
        $this->actingAs($admin)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => []])->assertStatus(422);
        $this->assertSame($before, $adminRole->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_only_an_administrator_can_edit_the_admin_role(): void
    {
        // a delegated role manager holding every permission but not the admin role
        $manager = $this->makeUser(['user']);
        $manager->givePermissionTo(Permission::all());
        $adminRole = Role::findByName('admin', 'web');

        $this->actingAs($manager)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => Role::ADMIN_LOCKED_PERMISSIONS])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->assertSame(Permission::count(), $adminRole->fresh()->permissions()->count());
    }

    public function test_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->makeUser(['admin']);
        $adminRole = Role::findByName('admin', 'web');
        $this->actingAs($admin)->deleteJson("/api/admin/roles/{$adminRole->id}")->assertStatus(422);
        $this->assertNotNull(Role::find($adminRole->id));
    }

    public function test_reseeding_keeps_admin_edits_but_restores_locked_permissions(): void
    {
        $adminRole = Role::findByName('admin', 'web');
        $adminRole->syncPermissions(['audit.view']); // simulate a broken state (locked ones missing)

        $this->seed(RolesAndPermissionsSeeder::class);

        $names = $adminRole->fresh()->permissions->pluck('name')->all();
        foreach (Role::ADMIN_LOCKED_PERMISSIONS as $locked) {
            $this->assertContains($locked, $names);
        }
        $this->assertContains('audit.view', $names);
        $this->assertNotContains('content.manage', $names); // the admin's removal is preserved
    }
}

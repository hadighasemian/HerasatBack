<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleAssignmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

class RoleAssignmentSeederTest extends TestCase
{
    public function test_assigns_roles_idempotently_and_keeps_existing_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $boss = $this->makeUser(['user', 'senior_security_specialist'], ['personnel_code' => '136534']);
        $newRole = $this->makeUser(['user'], ['personnel_code' => '831187']);

        $this->seed(RoleAssignmentSeeder::class);
        $this->seed(RoleAssignmentSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $boss->refresh();
        $this->assertTrue($boss->hasAllRoles(['user', 'senior_security_specialist', 'head_personnel_security']));
        $this->assertTrue($boss->can('reports.review'));
        $this->assertSame(3, $boss->roles()->count());
        $this->assertTrue($newRole->refresh()->hasAllRoles(['user', 'industrial_automation_manager']));
        $this->assertSame('مدیر اتوماسیون صنعتی', Role::where('name', 'industrial_automation_manager')->value('display_name'));
        $this->assertSame(1, Role::where('name', 'industrial_automation_manager')->count());
    }

    public function test_missing_users_are_skipped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->artisan('db:seed', ['--class' => RoleAssignmentSeeder::class])->assertSuccessful();
    }
}

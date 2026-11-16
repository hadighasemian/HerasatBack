<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: adds roles by personnel_code (assignRole only, never removes). Missing users are skipped with a warning. */
class RoleAssignmentSeeder extends Seeder
{
    public const ASSIGNMENTS = [
        '101523' => 'admin',
        '820113' => 'security_affairs_manager',
        '136534' => 'head_personnel_security',
        '165710' => 'head_document_it_security',
        '175948' => 'head_electronic_security',
        '131828' => 'head_explosives_security',
        '153570' => 'head_physical_security',
        '831620' => 'ict_affairs_manager',
        '169112' => 'head_communications',
        '854171' => 'head_it_support',
        '188160' => 'personnel_security_supervisor',
        '831187' => 'industrial_automation_manager',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ASSIGNMENTS as $code => $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                $this->command?->warn("Role '{$roleName}' not found (run RolesAndPermissionsSeeder first); skipping {$code}.");
                continue;
            }
            $user = User::where('personnel_code', $code)->first();
            if (! $user) {
                $this->command?->warn("User with personnel code {$code} not found; skipping.");
                continue;
            }
            foreach (array_unique([$roleName, 'user']) as $r) {
                if (! $user->hasRole($r) && Role::where('name', $r)->exists()) {
                    $user->assignRole($r);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

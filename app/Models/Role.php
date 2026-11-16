<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'display_name', 'title_en', 'description'];

    protected $casts = ['is_system' => 'boolean'];

    public const ADMIN = 'admin';

    /**
     * Permissions the admin role can never lose, so nobody can lock the system out of managing
     * roles and users. Everything else on the admin role is editable.
     */
    public const ADMIN_LOCKED_PERMISSIONS = ['roles.view', 'roles.manage', 'users.view', 'users.update', 'users.assign_roles'];

    public function isAdminRole(): bool
    {
        return $this->name === self::ADMIN && $this->guard_name === 'web';
    }

    /** @return list<string> */
    public function lockedPermissions(): array
    {
        return $this->isAdminRole() ? self::ADMIN_LOCKED_PERMISSIONS : [];
    }

    /**
     * Spatie resolves the user model from the *current default guard*; under `auth:sanctum`
     * that is the provider-less "sanctum" guard, which breaks e.g. withCount('users').
     * All roles belong to users, so bind the relation to the User model explicitly.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }
}

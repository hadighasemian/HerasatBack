<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'display_name', 'title_en', 'description'];

    protected $casts = ['is_system' => 'boolean'];

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

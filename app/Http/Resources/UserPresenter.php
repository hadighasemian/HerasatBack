<?php

namespace App\Http\Resources;

use App\Models\User;

/** Central place deciding which user fields leave the API. Never exposes national code/phone in clear. */
class UserPresenter
{
    public static function me(User $user): array
    {
        return [
            'id' => $user->id,
            'personnel_code' => $user->personnel_code,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'must_change_password' => (bool) $user->must_change_password,
            'roles' => $user->roles->map(fn ($r) => ['name' => $r->name, 'display_name' => $r->display_name])->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }

    public static function profile(User $user): array
    {
        return [
            'personnel_code' => $user->personnel_code,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'father_name' => $user->father_name,
            'employment_type' => $user->employment_type,
            'department' => $user->department,
            'work_location' => $user->work_location,
            'national_code_masked' => $user->maskedNationalCode(),
            'phone_masked' => $user->maskedPhone(),
            'roles' => $user->roles->map(fn ($r) => ['name' => $r->name, 'display_name' => $r->display_name])->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
        ];
    }

    public static function listItem(User $user): array
    {
        return [
            'id' => $user->id,
            'personnel_code' => $user->personnel_code,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'department' => $user->department,
            'work_location' => $user->work_location,
            'is_active' => (bool) $user->is_active,
            'is_locked' => $user->isLocked(),
            'must_change_password' => (bool) $user->must_change_password,
            'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name])->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    public static function adminDetail(User $user): array
    {
        return self::listItem($user) + [
            'father_name' => $user->father_name,
            'employment_type' => $user->employment_type,
            'national_code_masked' => $user->maskedNationalCode(),
            'phone_masked' => $user->maskedPhone(),
            'failed_login_attempts' => $user->failed_login_attempts,
            'locked_until' => $user->locked_until?->toIso8601String(),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
        ];
    }
}

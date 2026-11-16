<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')->withCount('users')->orderBy('id')->get()
            ->map(fn (Role $r) => $this->json($r));

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/', Rule::unique('roles', 'name')],
            'display_name' => ['required', 'string', 'max:191'],
            'title_en' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        $this->assertCanGrant($request, $data['permissions'] ?? []);

        $role = Role::create([
            'name' => $data['name'], 'guard_name' => 'web', 'display_name' => $data['display_name'],
            'title_en' => $data['title_en'] ?? null, 'description' => $data['description'] ?? null,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);
        AuditLogger::log('role.created', $role, ['name' => $role->name, 'permissions' => $data['permissions'] ?? []]);

        return response()->json(['role' => $this->json($role->load('permissions')->loadCount('users'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:191'],
            'title_en' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $role->update($data);
        AuditLogger::log('role.updated', $role, ['name' => $role->name]);

        return response()->json(['role' => $this->json($role->load('permissions')->loadCount('users'))]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        if ($role->isAdminRole()) {
            // only an administrator may change the administrator role
            if (! $request->user()->hasRole(Role::ADMIN)) {
                throw ValidationException::withMessages(['permissions' => 'فقط مدیر سیستم می‌تواند دسترسی‌های نقش مدیر سیستم را تغییر دهد.']);
            }
            $missingLocked = array_diff($role->lockedPermissions(), $data['permissions']);
            if ($missingLocked) {
                throw ValidationException::withMessages(['permissions' => 'دسترسی‌های مدیریت کاربران و نقش‌ها برای نقش مدیر سیستم الزامی است و قابل حذف نیست: '.implode('، ', $missingLocked)]);
            }
        }
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $added = array_diff($data['permissions'], $before);
        // An administrator editing the administrator role may restore permissions it removed earlier
        // (otherwise a removed permission could never be granted again). Everyone else: no escalation.
        if (! $role->isAdminRole()) {
            $this->assertCanGrant($request, $added);
        }

        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLogger::log('role.permissions_changed', $role, ['name' => $role->name, 'before' => $before, 'after' => array_values($data['permissions'])]);

        return response()->json(['role' => $this->json($role->fresh()->load('permissions')->loadCount('users'))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system || $role->isAdminRole()) {
            throw ValidationException::withMessages(['role' => 'نقش‌های سیستمی قابل حذف نیستند.']);
        }
        AuditLogger::log('role.deleted', $role, ['name' => $role->name]);
        $role->delete();

        return response()->json(['message' => 'نقش حذف شد.']);
    }

    private function assertCanGrant(Request $request, array $permissions): void
    {
        $mine = $request->user()->load('roles.permissions', 'permissions')->getAllPermissions()->pluck('name')->all();
        $missing = array_diff($permissions, $mine);
        if ($missing) {
            throw ValidationException::withMessages(['permissions' => 'نمی‌توانید دسترسی‌ای را که خودتان ندارید اعطا کنید.']);
        }
    }

    private function json(Role $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name, 'title_en' => $r->title_en,
            'description' => $r->description, 'is_system' => (bool) $r->is_system,
            'users_count' => $r->users_count ?? null,
            'permissions' => $r->permissions->pluck('name')->sort()->values(),
            'locked_permissions' => $r->lockedPermissions(),
        ];
    }
}

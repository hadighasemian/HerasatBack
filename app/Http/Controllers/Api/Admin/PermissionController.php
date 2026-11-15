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

/**
 * Permissions ("actions") are data: new ones can be defined at runtime and granted to roles.
 * Code checks permissions by name (never role names), so a new feature only needs a permission row.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $items = Permission::orderBy('group')->orderBy('id')->get()->map(fn (Permission $p) => $this->json($p));

        return response()->json(['permissions' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', Rule::unique('permissions', 'name')],
            'display_name' => ['required', 'string', 'max:191'],
            'group' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $permission = Permission::create($data + ['guard_name' => 'web']);
        // The system administrator role always holds every permission
        Role::where('name', 'admin')->where('is_system', true)->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLogger::log('permission.created', $permission, ['name' => $permission->name]);

        return response()->json(['permission' => $this->json($permission)], 201);
    }

    public function update(Request $request, Permission $permission): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:191'],
            'group' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $permission->update($data);
        AuditLogger::log('permission.updated', $permission, ['name' => $permission->name]);

        return response()->json(['permission' => $this->json($permission)]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        if ($permission->is_system) {
            throw ValidationException::withMessages(['permission' => 'دسترسی‌های سیستمی قابل حذف نیستند.']);
        }
        AuditLogger::log('permission.deleted', $permission, ['name' => $permission->name]);
        $permission->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'دسترسی حذف شد.']);
    }

    private function json(Permission $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'display_name' => $p->display_name, 'group' => $p->group, 'description' => $p->description, 'is_system' => (bool) $p->is_system];
    }
}

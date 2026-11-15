<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserPresenter;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserImportService;
use App\Support\NationalCode;
use App\Support\Persian;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'locked'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $q = Persian::normalizeText(Persian::toEnglishDigits($data['q'] ?? ''));

        $users = User::query()->with('roles')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('personnel_code', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('department', 'like', $like));
            })
            ->when($data['role'] ?? null, fn ($query, $role) => $query->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when(($data['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($data['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(($data['status'] ?? null) === 'locked', fn ($query) => $query->where('locked_until', '>', now()))
            ->orderBy('id', 'desc')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($users->items())->map(fn ($u) => UserPresenter::listItem($u)),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total(), 'per_page' => $users->perPage()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['user' => UserPresenter::adminDetail($user->load('roles'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $roleIds = $request->validate(['roles' => ['nullable', 'array'], 'roles.*' => ['integer', 'exists:roles,id']])['roles'] ?? [];
        if ($roleIds) {
            Gate::authorize('users.assign_roles');
        }

        $user = DB::transaction(function () use ($data, $roleIds, $request) {
            $user = new User($data['profile']);
            $user->setNationalCode($data['national_code']);
            $user->password = $data['national_code'];
            $user->must_change_password = true;
            $user->is_active = true;
            $user->save();
            $this->syncRolesChecked($request->user(), $user, $roleIds);

            return $user;
        });

        AuditLogger::log('user.created', $user, ['roles' => $user->roles->pluck('name')->all()]);

        return response()->json(['user' => UserPresenter::adminDetail($user->load('roles'))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        $extra = $request->validate(['is_active' => ['nullable', 'boolean']]);

        if (array_key_exists('is_active', $extra) && $extra['is_active'] !== null) {
            if ($user->is($request->user()) && ! $extra['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'نمی‌توانید حساب خود را غیرفعال کنید.']);
            }
            $user->is_active = (bool) $extra['is_active'];
        }
        $user->fill($data['profile']);
        if (! empty($data['national_code'])) {
            $user->setNationalCode($data['national_code']);
        }
        $changed = array_keys($user->getDirty());
        $user->save();

        AuditLogger::log('user.updated', $user, ['fields' => array_values(array_diff($changed, ['national_code_hash', 'updated_at']))]);

        return response()->json(['user' => UserPresenter::adminDetail($user->load('roles'))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'نمی‌توانید حساب خود را حذف کنید.']);
        }
        $user->delete();
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        AuditLogger::log('user.deleted', $user);

        return response()->json(['message' => 'کاربر حذف شد.']);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $user->password = $user->national_code;
        $user->must_change_password = true;
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        AuditLogger::log('user.password_reset', $user);

        return response()->json(['message' => 'رمز عبور کاربر به کد ملی بازنشانی شد و در ورود بعدی باید تغییر کند.']);
    }

    public function unlock(User $user): JsonResponse
    {
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();
        AuditLogger::log('user.unlocked', $user);

        return response()->json(['message' => 'حساب کاربر از حالت قفل خارج شد.']);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['integer', 'exists:roles,id']]);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['roles' => 'نمی‌توانید نقش‌های خود را تغییر دهید.']);
        }
        $before = $user->roles->pluck('name')->all();
        $this->syncRolesChecked($request->user(), $user, $data['roles']);
        $after = $user->fresh()->roles->pluck('name')->all();
        AuditLogger::log('user.roles_changed', $user, ['before' => $before, 'after' => $after]);

        return response()->json(['user' => UserPresenter::adminDetail($user->fresh()->load('roles'))]);
    }

    public function import(Request $request, UserImportService $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.config('herasat.import.max_upload_kb'), 'extensions:csv,xlsx,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
            'update_existing' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        @set_time_limit(900);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        // keep the upload in a private temp file with the right extension for the reader
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.'.($ext === 'xlsx' ? 'xlsx' : 'csv');
        copy($file->getRealPath(), $tmp);
        try {
            $result = $importer->import($tmp, ['update' => $request->boolean('update_existing'), 'dry_run' => $request->boolean('dry_run')]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw ValidationException::withMessages(['file' => 'فایل قابل خواندن نیست.']);
        } finally {
            @unlink($tmp);
        }
        $summary = $result;
        unset($summary['errors']);
        AuditLogger::log('user.import', null, $summary + ['dry_run' => $request->boolean('dry_run')]);

        return response()->json(['result' => $result]);
    }

    /**
     * Prevents privilege escalation: an actor may only grant roles whose permissions are all held by the actor.
     * The default 'user' role is always kept.
     */
    private function syncRolesChecked(User $actor, User $target, array $roleIds): void
    {
        $roles = Role::whereIn('id', $roleIds)->with('permissions')->get();
        $actorPermissions = $actor->load('roles.permissions', 'permissions')->getAllPermissions()->pluck('name')->all();
        $current = $target->roles->pluck('id')->all();
        foreach ($roles as $role) {
            if (in_array($role->id, $current, true)) {
                continue; // already assigned
            }
            $missing = array_diff($role->permissions->pluck('name')->all(), $actorPermissions);
            if ($missing) {
                throw ValidationException::withMessages(['roles' => "اجازه اعطای نقش «{$role->display_name}» را ندارید."]);
            }
        }
        $default = Role::where('name', 'user')->first();
        $ids = $roles->pluck('id')->all();
        if ($default) {
            $ids[] = $default->id;
        }
        $target->syncRoles(Role::whereIn('id', array_unique($ids))->get());
    }

    private function validated(Request $request, ?User $user): array
    {
        $request->merge([
            'personnel_code' => $request->has('personnel_code') ? Persian::toEnglishDigits(trim((string) $request->input('personnel_code'))) : null,
            'national_code' => $request->filled('national_code') ? NationalCode::normalize($request->input('national_code')) ?? (string) $request->input('national_code') : null,
        ]);
        $creating = $user === null;
        $data = $request->validate([
            'personnel_code' => [$creating ? 'required' : 'sometimes', 'nullable', 'regex:/^\d{3,20}$/', Rule::unique('users', 'personnel_code')->ignore($user?->id)],
            'national_code' => [$creating ? 'required' : 'nullable', 'string', function ($attr, $value, $fail) use ($user) {
                if ($value === null) {
                    return;
                }
                if (! NationalCode::isValid($value)) {
                    $fail('کد ملی نامعتبر است.');

                    return;
                }
                if (User::withTrashed()->where('national_code_hash', NationalCode::hash($value))->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists()) {
                    $fail('این کد ملی قبلاً ثبت شده است.');
                }
            }],
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'last_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'father_name' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:191'],
            'work_location' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9۰-۹+\-\s]{5,20}$/u'],
        ]);

        $profile = [];
        foreach (['personnel_code', 'first_name', 'last_name', 'father_name', 'employment_type', 'department', 'work_location'] as $f) {
            if (array_key_exists($f, $data) && ! ($f === 'personnel_code' && $data[$f] === null)) {
                $profile[$f] = is_string($data[$f]) ? Persian::normalizeText($data[$f]) : $data[$f];
            }
        }
        if (array_key_exists('phone', $data)) {
            $profile['phone'] = Phone::normalize($data['phone']);
        }

        return ['profile' => $profile, 'national_code' => $data['national_code'] ?? null];
    }
}

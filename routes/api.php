<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\ContentController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\CaptchaController;
use App\Http\Controllers\Api\Public\PublicContentController;
use App\Http\Controllers\Api\Public\PublicReportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

/*
| Public endpoints (no login). There is intentionally NO register / forgot-password endpoint.
*/
Route::prefix('public')->group(function () {
    Route::get('captcha', CaptchaController::class)->middleware('throttle:captcha');
    Route::get('report-categories', [PublicReportController::class, 'categories']);
    Route::post('reports', [PublicReportController::class, 'store'])->middleware('throttle:report-submit');
    Route::post('reports/track', [PublicReportController::class, 'track'])->middleware('throttle:report-track');
    Route::get('slides', [PublicContentController::class, 'slides']);
    Route::get('slides/{slide}/image', [PublicContentController::class, 'slideImage'])->whereNumber('slide');
    Route::get('tips', [PublicContentController::class, 'tips']);
});

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', EnforceSessionPolicy::class])->group(function () {
    // Allowed even while the default password has not been changed yet
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:password-change');

    Route::middleware(EnsurePasswordChanged::class)->group(function () {
        // Own profile: read-only. There is no self-update endpoint for profile fields.
        Route::get('profile', [AuthController::class, 'profile']);
        Route::get('profile/login-history', [AuthController::class, 'loginHistory']);

        // Personal to-do list (owner-only; also gated by todos.use)
        Route::middleware('can:todos.use')->group(function () {
            Route::get('todos', [TodoController::class, 'index']);
            Route::get('todos/{todo}', [TodoController::class, 'show'])->whereNumber('todo');
            Route::post('todos', [TodoController::class, 'store'])->middleware('throttle:todos');
            Route::put('todos/{todo}', [TodoController::class, 'update'])->middleware('throttle:todos')->whereNumber('todo');
            Route::delete('todos/{todo}', [TodoController::class, 'destroy'])->middleware('throttle:todos')->whereNumber('todo');
        });

        // Reports inbox (visibility enforced by ReportPolicy)
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/{report}', [ReportController::class, 'show'])->whereNumber('report');
        Route::post('reports/{report}/review', [ReportController::class, 'review'])->whereNumber('report');
        Route::get('reports/{report}/attachments/{attachment}', [ReportController::class, 'attachment'])->whereNumber(['report', 'attachment']);

        Route::prefix('admin')->group(function () {
            // Users
            Route::get('users', [UserController::class, 'index'])->middleware('can:users.view');
            Route::post('users', [UserController::class, 'store'])->middleware('can:users.create');
            Route::post('users/import', [UserController::class, 'import'])->middleware(['can:users.import', 'throttle:import']);
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->whereNumber('user');
            Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->whereNumber('user');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->whereNumber('user');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('can:users.reset_password')->whereNumber('user');
            Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->middleware('can:users.update')->whereNumber('user');
            Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('can:users.assign_roles')->whereNumber('user');

            // Roles & permissions
            Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view');
            Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->whereNumber('role');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('can:roles.manage')->whereNumber('role');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->whereNumber('role');
            Route::get('permissions', [PermissionController::class, 'index'])->middleware('can:roles.view');
            Route::post('permissions', [PermissionController::class, 'store'])->middleware('can:permissions.manage');
            Route::put('permissions/{permission}', [PermissionController::class, 'update'])->middleware('can:permissions.manage')->whereNumber('permission');
            Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('can:permissions.manage')->whereNumber('permission');

            // Audit log
            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');

            // Site content
            Route::middleware('can:content.manage')->prefix('content')->group(function () {
                Route::get('slides', [ContentController::class, 'slides']);
                Route::post('slides', [ContentController::class, 'storeSlide']);
                Route::post('slides/{slide}', [ContentController::class, 'updateSlide'])->whereNumber('slide'); // multipart update
                Route::get('slides/{slide}/image', [ContentController::class, 'slideImage'])->whereNumber('slide');
                Route::delete('slides/{slide}', [ContentController::class, 'destroySlide'])->whereNumber('slide');
                Route::get('tips', [ContentController::class, 'tips']);
                Route::post('tips', [ContentController::class, 'storeTip']);
                Route::put('tips/{tip}', [ContentController::class, 'updateTip'])->whereNumber('tip');
                Route::delete('tips/{tip}', [ContentController::class, 'destroyTip'])->whereNumber('tip');
            });
        });
    });
});

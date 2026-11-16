<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserPresenter;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\AuditLogger;
use App\Services\CaptchaService;
use App\Support\Persian;
use App\Support\UserAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const GENERIC_ERROR = 'نام کاربری یا رمز عبور نادرست است. پس از چند تلاش ناموفق، حساب به‌طور موقت قفل می‌شود.';

    public function login(Request $request, CaptchaService $captcha): JsonResponse
    {
        // Session (cookie) auth only: the request must come from the configured SPA origin.
        if (! $request->hasSession()) {
            return response()->json(['message' => 'درخواست نامعتبر است.', 'code' => 'stateful_required'], 400);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:128'],
            'captcha_id' => ['nullable', 'string', 'max:64'],
            'captcha' => ['nullable', 'string', 'max:10'],
        ]);
        $username = preg_replace('/\s+/', '', Persian::toEnglishDigits($data['username'])) ?? '';
        $ip = (string) $request->ip();
        $userKey = 'login-fail:'.sha1(mb_strtolower($username).'|'.$ip);
        $ipKey = 'login-fail-ip:'.sha1($ip);

        // Captcha becomes mandatory after a few failures for this username/IP
        $threshold = (int) config('herasat.auth.captcha_after_failures', 2);
        $needsCaptcha = RateLimiter::attempts($userKey) >= $threshold || RateLimiter::attempts($ipKey) >= $threshold * 3;
        if ($needsCaptcha && ! $captcha->verify($data['captcha_id'] ?? null, $data['captcha'] ?? null)) {
            return response()->json([
                'message' => 'کد امنیتی نادرست یا منقضی شده است.',
                'errors' => ['captcha' => ['کد امنیتی نادرست یا منقضی شده است.']],
                'captcha_required' => true,
            ], 422);
        }

        $user = User::where('personnel_code', $username)->first();
        // Constant-ish time: always run a hash check
        $passwordOk = Hash::check($data['password'], $user?->password ?? '$2y$12$t7SlCPN5hFkunzTA3oIOVebMQw63mbPJtbOoZKw5n601R7UYL4r7O');

        if (! $user || ! $passwordOk || ! $user->is_active || $user->isLocked()) {
            RateLimiter::hit($userKey, 900);
            RateLimiter::hit($ipKey, 900);
            $reason = ! $user ? 'unknown_user' : ($user->isLocked() ? 'locked' : (! $user->is_active ? 'inactive' : 'bad_password'));

            if ($user && $reason === 'bad_password') {
                $user->failed_login_attempts++;
                if ($user->failed_login_attempts >= (int) config('herasat.auth.max_failed_attempts', 5)) {
                    $user->locked_until = now()->addMinutes((int) config('herasat.auth.lockout_minutes', 15));
                    $user->failed_login_attempts = 0;
                    AuditLogger::log('auth.account_locked', $user, [], $user->id);
                }
                $user->save();
            }
            AuditLogger::log('auth.login_failed', $user, ['reason' => $reason], $user?->id);

            return response()->json([
                'message' => self::GENERIC_ERROR,
                'captcha_required' => RateLimiter::attempts($userKey) >= $threshold,
            ], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_started_at', now()->getTimestamp());

        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->last_login_ip = $ip;
        $user->save();
        RateLimiter::clear($userKey);

        AuditLogger::log('auth.login', $user, [], $user->id);

        return response()->json(['user' => UserPresenter::me($user->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            AuditLogger::log('auth.logout', $user, [], $user->id);
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'خروج انجام شد.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserPresenter::me($request->user())]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['profile' => UserPresenter::profile($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new StrongPassword($user)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            AuditLogger::log('auth.password_change_failed', $user);
            throw ValidationException::withMessages(['current_password' => 'رمز عبور فعلی نادرست است.']);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();

        // Invalidate every other session of this user and rotate the current one
        if ($request->hasSession()) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
            $request->session()->regenerate();
        }

        AuditLogger::log('auth.password_changed', $user);

        return response()->json(['message' => 'رمز عبور با موفقیت تغییر کرد.', 'user' => UserPresenter::me($user->fresh())]);
    }

    private const LOGIN_ACTIONS = [
        'auth.login' => ['success', 'موفق'],
        'auth.login_failed' => ['failed', 'ناموفق'],
        'auth.logout' => ['logout', 'خروج'],
        'auth.account_locked' => ['locked', 'قفل حساب'],
    ];

    /** The current user's own recent login attempts (successful and failed). Last 50, paginated. */
    public function loginHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);
        $perPage = $data['per_page'] ?? 10;
        $user = $request->user();
        $actions = array_keys(self::LOGIN_ACTIONS);

        $latestIds = AuditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('action', $actions)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        $logs = AuditLog::query()
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->paginate($perPage);

        $lastSuccess = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'auth.login')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => $this->loginHistoryItem($l)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
            ],
            'last_successful_login' => $lastSuccess ? $this->loginHistoryItem($lastSuccess) : null,
        ]);
    }

    private function loginHistoryItem(AuditLog $l): array
    {
        [$result, $label] = self::LOGIN_ACTIONS[$l->action] ?? ['other', $l->action];
        $ua = UserAgent::parse($l->user_agent);

        return [
            'id' => $l->id,
            'action' => $l->action,
            'result' => $result,
            'result_label' => $label,
            'occurred_at' => $l->created_at?->toIso8601String(),
            'ip' => $l->ip,
            'browser' => $ua['browser'],
            'os' => $ua['os'],
        ];
    }
}

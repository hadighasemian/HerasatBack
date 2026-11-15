<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * - absolute session lifetime (idle lifetime is SESSION_LIFETIME)
 * - deactivated / deleted / locked users are logged out immediately
 */
class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $startedAt = (int) $request->session()->get('auth_started_at', 0);
            $maxMinutes = (int) config('herasat.auth.absolute_session_minutes', 480);
            $expired = $startedAt > 0 && (now()->getTimestamp() - $startedAt) > $maxMinutes * 60;

            if ($expired || ! $user->is_active || $user->trashed() || $user->isLocked()) {
                AuditLogger::log($expired ? 'auth.session_expired' : 'auth.forced_logout', $user, [], $user->id);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['message' => 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.', 'code' => 'session_expired'], 401);
            }
        }

        return $next($request);
    }
}

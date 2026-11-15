<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks every authenticated endpoint (except me/logout/change-password) until the default password is changed. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'لطفاً ابتدا رمز عبور خود را تغییر دهید.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}

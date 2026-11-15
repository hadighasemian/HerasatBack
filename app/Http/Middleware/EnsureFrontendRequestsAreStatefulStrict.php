<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum forces SameSite=Lax on the session cookie for SPA requests. The SPA is served
 * same-origin (Next.js proxies /api), so we keep the stricter configured value (default Strict).
 */
class EnsureFrontendRequestsAreStatefulStrict extends EnsureFrontendRequestsAreStateful
{
    protected function configureSecureCookieSessions()
    {
        config([
            'session.http_only' => true,
            'session.same_site' => config('session.same_site') ?: 'strict',
        ]);
    }
}

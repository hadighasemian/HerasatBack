<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) authentication with CSRF protection for the Next.js frontend
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->append(SecurityHeaders::class);
        // Only trust the local reverse proxy (Next.js / Apache) for X-Forwarded-* headers
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')))));
        $middleware->encryptCookies();
        // Never redirect API clients to a login page
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'national_code', 'captcha']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'ابتدا وارد سامانه شوید.', 'code' => 'unauthenticated'], 401);
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return response()->json(['message' => 'شما مجوز دسترسی به این بخش را ندارید.', 'code' => 'forbidden'], 403);
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'موردی یافت نشد.', 'code' => 'not_found'], 404);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return response()->json([
                'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
                'code' => 'too_many_requests',
                'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
            ], 429, $e->getHeaders());
        });
        $exceptions->render(function (TokenMismatchException|HttpException $e, Request $request) {
            if ($e instanceof TokenMismatchException || $e->getStatusCode() === 419) {
                return response()->json(['message' => 'نشست شما منقضی شده است. صفحه را دوباره بارگذاری کنید.', 'code' => 'csrf_mismatch'], 419);
            }

            return null;
        });
    })->create();

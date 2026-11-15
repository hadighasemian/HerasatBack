<?php

namespace App\Providers;

use App\Models\Report;
use App\Policies\ReportPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // keep SameSite=Strict for the SPA session cookie (Sanctum would downgrade it to Lax)
        $this->app->bind(
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\EnsureFrontendRequestsAreStatefulStrict::class,
        );
    }

    public function boot(): void
    {
        // Fail loudly on mass-assignment of non-fillable attributes and lazy loading in dev/tests
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::policy(Report::class, ReportPolicy::class);

        Password::defaults(fn () => Password::min((int) config('herasat.password.min_length', 10))->mixedCase()->numbers()->symbols());

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by('login-ip:'.$r->ip()),
            Limit::perMinute(5)->by('login-user:'.mb_strtolower((string) $r->input('username')).'|'.$r->ip()),
        ]);
        RateLimiter::for('captcha', fn (Request $r) => Limit::perMinute(30)->by('captcha:'.$r->ip()));
        RateLimiter::for('report-submit', fn (Request $r) => [
            Limit::perMinute(3)->by('report-min:'.$r->ip()),
            Limit::perHour(10)->by('report-hour:'.$r->ip()),
        ]);
        RateLimiter::for('report-track', fn (Request $r) => Limit::perMinute(10)->by('report-track:'.$r->ip()));
        RateLimiter::for('password-change', fn (Request $r) => Limit::perMinute(5)->by('pwd:'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('import', fn (Request $r) => Limit::perMinute(3)->by('import:'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}

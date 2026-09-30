<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(hash('sha256', (string) $request->ip()));
        });
        // P0.7 Physical Locations: own per-user buckets (reads, writes, audited sensitive reads) so a normal page
        // journey never drains the write budget shared by numeric route throttles.
        RateLimiter::for('physical', function (Request $request) {
            return Limit::perMinute(240)->by('physical|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('physical-write', function (Request $request) {
            return Limit::perMinute(60)->by('physical-write|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('physical-sensitive', function (Request $request) {
            return Limit::perMinute(30)->by('physical-sensitive|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        // P0.8 Documents/Files (ADR 0019 D02): files-upload 20/min and files-download 60/min per actor, plus own read
        // and write buckets.
        RateLimiter::for('files', function (Request $request) {
            return Limit::perMinute(240)->by('files|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('files-write', function (Request $request) {
            return Limit::perMinute(60)->by('files-write|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('files-upload', function (Request $request) {
            return Limit::perMinute(20)->by('files-upload|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('files-download', function (Request $request) {
            return Limit::perMinute(60)->by('files-download|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        // P0.9 Membership (ADR 0020 D10): reads, writes, and searches (a lookup by official number or legacy identifier
        // is limited against enumeration).
        RateLimiter::for('membership', function (Request $request) {
            return Limit::perMinute(240)->by('membership|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('membership-write', function (Request $request) {
            return Limit::perMinute(60)->by('membership-write|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('membership-search', function (Request $request) {
            return Limit::perMinute(60)->by('membership-search|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        // P0.10 Finance (ADR 0021): reads and writes (stage postings, contributions).
        RateLimiter::for('finance', function (Request $request) {
            return Limit::perMinute(240)->by('finance|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('finance-write', function (Request $request) {
            return Limit::perMinute(60)->by('finance-write|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}

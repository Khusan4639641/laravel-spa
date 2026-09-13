<?php

namespace App\Providers;

use App\Services\Yandex\PlaywrightYandexMapsParser;
use App\Services\Yandex\YandexMapsParserInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(YandexMapsParserInterface::class, PlaywrightYandexMapsParser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This application intentionally supports session cookies only, no personal access tokens.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(6)->by($request->user()->id));
    }
}

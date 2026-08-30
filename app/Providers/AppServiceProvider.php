<?php

namespace App\Providers;

use App\Models\Role;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        /*
        |--------------------------------------------------------------------------
        | Authorization Policies
        |--------------------------------------------------------------------------
        */

        Gate::policy(
            Role::class,
            RolePolicy::class
        );

        Gate::policy(
            Permission::class,
            PermissionPolicy::class
        );

        /*
        |--------------------------------------------------------------------------
        | Login Rate Limiting
        |--------------------------------------------------------------------------
        */

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(
                trim((string) $request->input('email'))
            );

            $ip = $request->ip();

            return [
                // Maximum 10 login requests per minute from one IP
                Limit::perMinute(10)
                    ->by("login-ip:{$ip}"),

                // Maximum 5 login requests per minute for one account
                Limit::perMinute(5)
                    ->by("login-account:{$email}"),
            ];
        });
    }
}
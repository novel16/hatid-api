<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers());

        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $identity.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('authenticated', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->user()->getAuthIdentifier()));

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $client = $user->role === UserRole::Admin ? 'admin_url' : 'rider_url';

            return config('clients.'.$client).'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });
    }
}

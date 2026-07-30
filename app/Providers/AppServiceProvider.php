<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Keeps unique indexes under the 767-byte limit on MySQL 5.7 /
        // MariaDB 10.1 shared hosting.
        \Illuminate\Support\Facades\Schema::defaultStringLength(191);

        // Role middleware must re-run on Livewire update requests, not just
        // the initial page load — otherwise a demoted user's open page
        // keeps executing privileged actions.
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\EnsureRole::class,
        ]);

        // Throttle login per IP AND per email+IP.
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('ip:'.$request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            ];
        });

        // Role → ability map. admin/manager run the business; loan
        // officers originate; cashiers take money; accountants read.
        $abilities = [
            'manage-users' => ['admin'],
            'manage-products' => ['admin', 'manager'],
            'create-loans' => ['admin', 'manager', 'loan_officer'],
            'activate-loans' => ['admin', 'manager'],
            'record-payments' => ['admin', 'manager', 'cashier'],
            'reverse-payments' => ['admin', 'manager'],
            'payoff-loans' => ['admin', 'manager'],
            'write-off-loans' => ['admin', 'manager'],
        ];

        foreach ($abilities as $ability => $roles) {
            Gate::define($ability, fn (User $user) => in_array($user->role, $roles, true));
        }
    }
}

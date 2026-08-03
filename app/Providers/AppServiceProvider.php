<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use App\Support\CurrencyScale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One products→scale lookup per request, shared by every report
        // that renders per-currency aggregates.
        $this->app->singleton(CurrencyScale::class);
    }

    public function boot(): void
    {
        // Keeps unique indexes under the 767-byte limit on MySQL 5.7 /
        // MariaDB 10.1 shared hosting.
        Schema::defaultStringLength(191);

        // Honour X-Forwarded-* only from proxies the operator explicitly
        // trusts (TRUSTED_PROXIES env: comma-separated IPs, or "*").
        // Default is trust none — otherwise any client could spoof its IP
        // and turn every per-IP rate limiter (login lockout) into a no-op.
        $trustedProxies = (string) config('app.trusted_proxies', '');

        if ($trustedProxies !== '') {
            TrustProxies::at($trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))));
        }

        // Role middleware must re-run on Livewire update requests, not just
        // the initial page load — otherwise a demoted user's open page
        // keeps executing privileged actions.
        Livewire::addPersistentMiddleware([
            EnsureRole::class,
        ]);

        // Throttle login per IP AND per email+IP.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
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
            'send-sms' => ['admin', 'manager'],
        ];

        foreach ($abilities as $ability => $roles) {
            Gate::define($ability, fn (User $user) => in_array($user->role, $roles, true));
        }
    }
}

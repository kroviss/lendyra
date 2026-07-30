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

<?php

namespace App\Livewire;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use LoanEngine\Money;

class Dashboard extends Component
{
    public function render(): View
    {
        $activeLoans = Loan::where('status', LoanStatus::Active)->count();

        $outstandingMinor = (int) LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->sum(DB::raw('principal_minor - principal_paid_minor'));

        $overdueCount = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->whereNull('settled_at')
            ->whereDate('due_date', '<', today())
            ->count();

        $collectedThisMonthMinor = (int) LoanPayment::query()
            ->whereNull('reversed_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_minor');

        return view('livewire.dashboard', [
            'activeLoans' => $activeLoans,
            'outstanding' => Money::minor($outstandingMinor)->toDecimalString(),
            'overdueCount' => $overdueCount,
            'collectedThisMonth' => Money::minor($collectedThisMonthMinor)->toDecimalString(),
        ]);
    }
}

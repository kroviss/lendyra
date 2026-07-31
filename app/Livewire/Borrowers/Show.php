<?php

namespace App\Livewire\Borrowers;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Support\CurrencyScale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $borrowerId;

    public function mount(int $borrower): void
    {
        $this->borrowerId = $borrower;
    }

    public function deleteBorrower(): void
    {
        if (config('lms.demo')) {
            $this->dispatch('toast', message: __('Deleting is disabled in demo mode.'));

            return;
        }

        Gate::authorize('create-loans');

        $borrower = Borrower::findOrFail($this->borrowerId);

        if ($borrower->loans()->withTrashed()->exists()) {
            $this->dispatch('toast', message: __('Cannot delete: this borrower has loan history.'));

            return;
        }

        $borrower->delete();

        session()->flash('status', __('Borrower deleted'));
        $this->redirectRoute('borrowers.index');
    }

    public function render(): View
    {
        $borrower = Borrower::with(['loans.product', 'loans.installments'])
            ->findOrFail($this->borrowerId);

        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && $borrower->branch_id !== null && (int) $borrower->branch_id !== $scoped, 403);

        $outstanding = [];
        foreach ($borrower->loans->where('status', LoanStatus::Active) as $loan) {
            $outstanding[$loan->currency] = ($outstanding[$loan->currency] ?? 0) + $loan->principalOutstanding()->minor;
        }

        $scales = app(CurrencyScale::class);
        $outstandingLabel = $outstanding === []
            ? '0.00'
            : collect($outstanding)
                ->map(fn (int $minor, string $currency) => $scales->money($minor, $currency)->formatted().' '.$currency)
                ->implode(' · ');

        return view('livewire.borrowers.show', [
            'canDelete' => ! $borrower->loans()->withTrashed()->exists(),
            'borrower' => $borrower,
            'outstandingLabel' => $outstandingLabel,
            'activeCount' => $borrower->loans->where('status', LoanStatus::Active)->count(),
            'overdueCount' => $borrower->loans->where('status', LoanStatus::Active)
                ->filter(fn ($loan) => $loan->installments->contains(
                    fn ($i) => $i->settled_at === null && $i->due_date->isPast()
                ))->count(),
        ]);
    }
}

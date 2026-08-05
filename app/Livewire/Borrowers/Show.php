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

        // Re-check branch scope on the write: mount's check can be stale
        // (borrower reassigned since the page loaded).
        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && $borrower->branch_id !== null && (int) $borrower->branch_id !== $scoped, 403);

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
        $scoped = auth()->user()?->scopedBranchId();

        // Scope the eager-loaded loans the same way every other loan surface
        // does: a branch-scoped user must not see out-of-branch loans on a
        // borrower profile they can otherwise reach.
        $scopeLoans = fn ($q) => $q->when(
            $scoped,
            fn ($qq, $branch) => $qq->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))
        );

        $borrower = Borrower::with([
            'loans' => $scopeLoans,
            'loans.product',
            // Only active loans' installments are ever read (outstanding +
            // overdue stats). Skip hydrating the schedule of every closed and
            // written-off loan a repeat borrower has ever had.
            'loans.installments' => fn ($q) => $q->whereHas('loan', fn ($l) => $l->where('status', LoanStatus::Active)),
        ])->findOrFail($this->borrowerId);

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

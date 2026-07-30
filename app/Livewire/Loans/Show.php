<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LoanEngine\Money;
use Throwable;

class Show extends Component
{
    use \Livewire\WithFileUploads;

    #[Locked]
    public int $loanId;

    // Payment modal state
    public bool $showPaymentModal = false;
    public ?float $paymentAmount = null;
    public string $paymentDate = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';

    // Payoff modal state
    public bool $showPayoffModal = false;
    public string $payoffDate = '';
    public string $payoffMethod = 'cash';

    // Collateral modal state
    public bool $showCollateralModal = false;
    public string $collateralType = '';
    public string $collateralDescription = '';
    public ?float $collateralValue = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public array $collateralPhotos = [];

    // Guarantor modal state
    public bool $showGuarantorModal = false;
    public string $guarantorName = '';
    public string $guarantorPhone = '';
    public string $guarantorIdNumber = '';

    public ?string $actionError = null;

    public function mount(int $loan): void
    {
        $this->loanId = $loan;
        $this->paymentDate = today()->format('Y-m-d');
        $this->payoffDate = today()->format('Y-m-d');
    }

    private function loan(): Loan
    {
        return Loan::with(['borrower', 'product', 'installments', 'payments.allocations'])
            ->findOrFail($this->loanId);
    }

    public function activate(): void
    {
        $this->actionError = null;
        $loan = $this->loan();

        try {
            \Illuminate\Support\Facades\Gate::authorize('activate-loans');

            \Illuminate\Support\Facades\DB::transaction(function () {
                // Lock + re-check inside the transaction: double-clicks and
                // concurrent tabs must not disburse twice.
                $loan = Loan::lockForUpdate()->findOrFail($this->loanId);

                if ($loan->status !== LoanStatus::Approved) {
                    throw new \LogicException(__('Only approved loans can be activated.'));
                }

                // Money moves NOW — the schedule anchors on the actual
                // disbursement date, not the application date.
                $loan->update(['disbursed_at' => today()]);
                $loan->refresh();

                app(LoanScheduleService::class)->generateAndPersist($loan);
                $loan->update(['status' => LoanStatus::Active, 'disbursed_by' => auth()->id()]);
                app(\App\Services\LedgerService::class)->postDisbursement($loan);
            });

            $this->dispatch('toast', message: __('Loan disbursed and activated'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function approve(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('activate-loans');

            $loan = $this->loan();

            if ($loan->status !== LoanStatus::PendingApproval) {
                throw new \LogicException(__('Only pending loans can be approved.'));
            }

            $loan->update([
                'status' => LoanStatus::Approved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $this->dispatch('toast', message: __('Loan approved'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function deleteLoan(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('activate-loans');

            $loan = $this->loan();

            if (! in_array($loan->status, [LoanStatus::Draft, LoanStatus::PendingApproval, LoanStatus::Approved, LoanStatus::Rejected], true)) {
                throw new \LogicException(__('Only loans that were never disbursed can be deleted.'));
            }

            $loan->delete();

            session()->flash('status', __('Loan deleted'));
            $this->redirectRoute('loans.index');
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function waivePenalty(int $installmentId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('write-off-loans');

            $installment = $this->loan()->installments()->findOrFail($installmentId);
            $waived = $installment->penaltyDue();

            if ($waived->minor <= 0) {
                throw new \LogicException(__('Nothing to waive on this installment.'));
            }

            $installment->update(['penalty_minor' => (int) $installment->penalty_paid_minor]);

            if ($installment->fresh()->isSettled() && $installment->settled_at === null) {
                $installment->update(['settled_at' => today()]);
            }

            $loan = $this->loan();
            if ($loan->status === LoanStatus::Active
                && $loan->installments->every(fn ($i) => $i->isSettled())) {
                $loan->update(['status' => LoanStatus::Closed, 'closed_at' => today()]);
            }

            $this->dispatch('toast', message: __('Penalty of :amount waived', ['amount' => $waived->toDecimalString()]));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    /** Opening any modal clears a stale error banner. */
    public function updated($property): void
    {
        if (str_starts_with($property, 'show') && str_ends_with($property, 'Modal')) {
            $this->actionError = null;
        }
    }

    public function reject(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('activate-loans');

            $loan = $this->loan();

            if (! in_array($loan->status, [LoanStatus::Approved, LoanStatus::PendingApproval], true)) {
                throw new \LogicException(__('Only pending or approved loans can be rejected.'));
            }

            $loan->update([
                'status' => LoanStatus::Rejected,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $this->dispatch('toast', message: __('Loan rejected'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function writeOff(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('write-off-loans');

            \Illuminate\Support\Facades\DB::transaction(function () {
                $loan = Loan::lockForUpdate()->findOrFail($this->loanId);

                if ($loan->status !== LoanStatus::Active) {
                    throw new \LogicException(__('Only active loans can be written off.'));
                }

                $loan->installments()->lockForUpdate()->get();
                $loan->unsetRelation('installments');
                $remaining = $loan->principalOutstanding();

                $loan->update([
                    'status' => LoanStatus::WrittenOff,
                    'written_off_at' => today(),
                ]);

                if ($remaining->minor > 0) {
                    app(\App\Services\LedgerService::class)->postWriteOff($loan, $remaining);
                }
            });

            $this->dispatch('toast', message: __('Loan written off'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function reversePayment(int $paymentId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('reverse-payments');

            $payment = $this->loan()->payments()->findOrFail($paymentId);
            app(RepaymentService::class)->reverse($payment, auth()->id());
            $this->dispatch('toast', message: __('Payment reversed'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function accruePenalties(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('record-payments');

            $before = $this->loan()->installments->sum('penalty_minor');
            app(PenaltyService::class)->accrue($this->loan(), new DateTimeImmutable(today()->format('Y-m-d')));
            $delta = $this->loan()->installments->sum('penalty_minor') - $before;

            $this->dispatch('toast', message: $delta > 0
                ? __('Penalties updated (+:amount)', ['amount' => Money::minor((int) $delta, $this->loan()->currency, (int) $this->loan()->scale)->toDecimalString()])
                : __('Penalties are up to date'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function recordPayment(): void
    {
        $this->actionError = null;

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date|before_or_equal:today|after_or_equal:'.($this->loan()->disbursed_at?->format('Y-m-d') ?? '2000-01-01'),
            'paymentMethod' => 'required|in:cash,bank,mobile',
            'paymentReference' => 'nullable|max:64',
        ]);

        $loan = $this->loan();

        try {
            \Illuminate\Support\Facades\Gate::authorize('record-payments');

            app(RepaymentService::class)->record(
                $loan,
                Money::of((string) $this->paymentAmount, $loan->currency, (int) $loan->scale),
                new DateTimeImmutable($this->paymentDate),
                method: $this->paymentMethod,
                reference: $this->paymentReference ?: null,
                receivedBy: auth()->id(),
            );

            $this->reset('showPaymentModal', 'paymentAmount', 'paymentReference');
            $this->dispatch('toast', message: __('Payment recorded'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function getPayoffQuoteProperty(): ?array
    {
        try {
            $quote = app(PayoffService::class)->quote($this->loan(), new DateTimeImmutable($this->payoffDate));

            return [
                'principal' => $quote->principalOutstanding->toDecimalString(),
                'pastDueInterest' => $quote->pastDueInterest->toDecimalString(),
                'accruedInterest' => $quote->accruedInterest->toDecimalString(),
                'penalty' => $quote->penalty->toDecimalString(),
                'total' => $quote->total()->toDecimalString(),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function settlePayoff(): void
    {
        $this->actionError = null;
        $this->validate([
            'payoffDate' => 'required|date|before_or_equal:today',
            'payoffMethod' => 'required|in:cash,bank,mobile',
        ]);

        try {
            \Illuminate\Support\Facades\Gate::authorize('payoff-loans');

            app(PayoffService::class)->settle(
                $this->loan(),
                new DateTimeImmutable($this->payoffDate),
                method: $this->payoffMethod,
                receivedBy: auth()->id(),
            );

            $this->showPayoffModal = false;
            $this->dispatch('toast', message: __('Loan settled in full'));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function addCollateral(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('create-loans');

        $this->validate([
            'collateralType' => 'required|max:64',
            'collateralDescription' => 'nullable|max:2000',
            'collateralValue' => 'required|numeric|min:0',
            'collateralPhotos.*' => 'image|max:4096',
        ]);

        $loan = $this->loan();

        $photos = collect($this->collateralPhotos)
            ->map(fn ($photo) => $photo->store('collaterals', 'public'))
            ->all();

        $loan->collaterals()->create([
            'type' => $this->collateralType,
            'description' => $this->collateralDescription ?: null,
            'estimated_value_minor' => Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor,
            'photos' => $photos ?: null,
        ]);

        $this->reset('showCollateralModal', 'collateralType', 'collateralDescription', 'collateralValue', 'collateralPhotos');
        $this->dispatch('toast', message: __('Collateral added'));
    }

    public function releaseCollateral(int $collateralId): void
    {
        \Illuminate\Support\Facades\Gate::authorize('create-loans');

        $this->loan()->collaterals()
            ->whereKey($collateralId)
            ->update(['status' => 'released', 'released_at' => today()]);

        $this->dispatch('toast', message: __('Collateral released'));
    }

    public function addGuarantor(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('create-loans');

        $this->validate([
            'guarantorName' => 'required|min:2|max:255',
            'guarantorPhone' => 'nullable|max:32',
            'guarantorIdNumber' => 'nullable|max:64',
        ]);

        $this->loan()->guarantors()->create([
            'name' => $this->guarantorName,
            'phone' => $this->guarantorPhone ?: null,
            'id_number' => $this->guarantorIdNumber ?: null,
        ]);

        $this->reset('showGuarantorModal', 'guarantorName', 'guarantorPhone', 'guarantorIdNumber');
        $this->dispatch('toast', message: __('Guarantor added'));
    }

    public function removeGuarantor(int $guarantorId): void
    {
        \Illuminate\Support\Facades\Gate::authorize('create-loans');

        $this->loan()->guarantors()->whereKey($guarantorId)->delete();

        $this->dispatch('toast', message: __('Guarantor removed'));
    }

    public function render(): View
    {
        return view('livewire.loans.show', [
            'loan' => $this->loan()->load(['collaterals', 'guarantors']),
        ]);
    }
}

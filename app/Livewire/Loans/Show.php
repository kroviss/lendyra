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

    public ?int $editingCollateralId = null;
    public ?int $editingGuarantorId = null;

    // Guarantor modal state
    public bool $showGuarantorModal = false;
    public string $guarantorName = '';
    public string $guarantorPhone = '';
    public string $guarantorIdNumber = '';
    public string $guarantorRelationship = '';

    public ?string $actionError = null;

    public function mount(int $loan): void
    {
        $this->loanId = $loan;
        $this->paymentDate = today()->format('Y-m-d');
        $this->payoffDate = today()->format('Y-m-d');
    }

    private ?Loan $loanCache = null;

    private function loan(): Loan
    {
        if ($this->loanCache !== null) {
            return $this->loanCache;
        }

        $loan = Loan::with([
            'borrower', 'product', 'installments',
            'payments.allocations.installment', 'payments.receivedBy',
            'createdBy', 'approvedBy', 'disbursedBy',
        ])->findOrFail($this->loanId);

        // Branch-scoped staff must not open another branch's loan by URL.
        // Loans with no branch stay visible to everyone — they predate
        // scoping or were created by a branchless admin.
        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && $loan->branch_id !== null && (int) $loan->branch_id !== $scoped, 403);

        return $this->loanCache = $loan;
    }

    /** Drop the per-request cache after any write. */
    private function forgetLoan(): void
    {
        $this->loanCache = null;
    }

    /** Never surface framework internals (SQL, model class names) to staff. */
    private function friendlyError(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return __('That record no longer exists — refresh the page.');
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return $e->getMessage() ?: __('You do not have permission to do that.');
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            report($e);

            return __('The database rejected that change. Please try again.');
        }

        return $e->getMessage();
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function deleteLoan(): void
    {
        $this->actionError = null;

        try {
            $loan = $this->loan();

            // The maker may withdraw their own application while it is
            // still pending; anything else needs approval rights.
            $isOwnPending = in_array($loan->status, [LoanStatus::PendingApproval, LoanStatus::Rejected], true)
                && (int) $loan->created_by === (int) auth()->id()
                && \Illuminate\Support\Facades\Gate::allows('create-loans');

            if (! $isOwnPending) {
                \Illuminate\Support\Facades\Gate::authorize('activate-loans');
            }

            if (! in_array($loan->status, [LoanStatus::Draft, LoanStatus::PendingApproval, LoanStatus::Approved, LoanStatus::Rejected], true)) {
                throw new \LogicException(__('Only loans that were never disbursed can be deleted.'));
            }

            $loan->delete();

            session()->flash('status', __('Loan deleted'));
            $this->redirectRoute('loans.index');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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

            $this->dispatch('toast', message: __('Penalty of :amount waived', ['amount' => $waived->formatted()]));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    /** Opening/closing modals clears stale errors and edit state. */
    public function updated($property, $value): void
    {
        if (str_starts_with($property, 'show') && str_ends_with($property, 'Modal')) {
            $this->actionError = null;

            if ($value === false && $property === 'showCollateralModal') {
                $this->reset('collateralType', 'collateralDescription', 'collateralValue', 'collateralPhotos', 'editingCollateralId');
            }

            if ($value === false && $property === 'showGuarantorModal') {
                $this->reset('guarantorName', 'guarantorPhone', 'guarantorIdNumber', 'guarantorRelationship', 'editingGuarantorId');
            }
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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
                ? __('Penalties updated (+:amount)', ['amount' => Money::minor((int) $delta, $this->loan()->currency, (int) $this->loan()->scale)->formatted()])
                : __('Penalties are up to date'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function getPayoffQuoteProperty(): ?array
    {
        try {
            $quote = app(PayoffService::class)->quote($this->loan(), new DateTimeImmutable($this->payoffDate));

            return [
                'principal' => $quote->principalOutstanding->formatted(),
                'pastDueInterest' => $quote->pastDueInterest->formatted(),
                'accruedInterest' => $quote->accruedInterest->formatted(),
                'penalty' => $quote->penalty->formatted(),
                'total' => $quote->total()->formatted(),
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    /** Security and guarantors may only change while a loan is live. */
    private function assertLoanMutable(): void
    {
        $status = $this->loan()->status;

        if (in_array($status, [LoanStatus::Closed, LoanStatus::WrittenOff, LoanStatus::Rejected], true)) {
            throw new \LogicException(__('This loan is :status — its collateral and guarantors are locked.', ['status' => $status->label()]));
        }
    }

    public function addCollateral(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('create-loans');
            $this->assertLoanMutable();

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

            if ($this->editingCollateralId) {
                $collateral = $loan->collaterals()->findOrFail($this->editingCollateralId);
                $collateral->update([
                    'type' => $this->collateralType,
                    'description' => $this->collateralDescription ?: null,
                    'estimated_value_minor' => Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor,
                    'photos' => array_merge($collateral->photos ?? [], $photos) ?: null,
                ]);
                $message = __('Collateral updated');
            } else {
                $loan->collaterals()->create([
                    'type' => $this->collateralType,
                    'description' => $this->collateralDescription ?: null,
                    'estimated_value_minor' => Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor,
                    'photos' => $photos ?: null,
                ]);
                $message = __('Collateral added');
            }

            $this->reset('showCollateralModal', 'collateralType', 'collateralDescription', 'collateralValue', 'collateralPhotos', 'editingCollateralId');
            $this->dispatch('toast', message: $message);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function releaseCollateral(int $collateralId): void
    {
        $this->actionError = null;

        try {
            // Releasing security on a live loan is a management decision —
            // and on a closed loan it is the whole point, so no status gate.
            \Illuminate\Support\Facades\Gate::authorize('write-off-loans');

            $this->loan()->collaterals()
                ->whereKey($collateralId)
                ->update(['status' => 'released', 'released_at' => today()]);

            $this->dispatch('toast', message: __('Collateral released'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function editCollateral(int $collateralId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $collateral = $this->loan()->collaterals()->findOrFail($collateralId);

            $this->editingCollateralId = $collateral->id;
            $this->collateralType = $collateral->type;
            $this->collateralDescription = (string) ($collateral->description ?? '');
            $this->collateralValue = (float) \LoanEngine\Money::minor((int) $collateral->estimated_value_minor, $this->loan()->currency, (int) $this->loan()->scale)->toDecimalString();
            $this->showCollateralModal = true;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function deleteCollateral(int $collateralId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('write-off-loans');
            $this->assertLoanMutable();

            $this->loan()->collaterals()->whereKey($collateralId)->delete();

            $this->dispatch('toast', message: __('Collateral deleted'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function editGuarantor(int $guarantorId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $guarantor = $this->loan()->guarantors()->findOrFail($guarantorId);

            $this->editingGuarantorId = $guarantor->id;
            $this->guarantorName = $guarantor->name;
            $this->guarantorPhone = (string) ($guarantor->phone ?? '');
            $this->guarantorIdNumber = (string) ($guarantor->id_number ?? '');
            $this->guarantorRelationship = (string) ($guarantor->relationship ?? '');
            $this->showGuarantorModal = true;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function addGuarantor(): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $this->validate([
                'guarantorName' => 'required|min:2|max:255',
                'guarantorPhone' => 'nullable|max:32',
                'guarantorIdNumber' => 'nullable|max:64',
                'guarantorRelationship' => 'nullable|max:64',
            ]);

            if ($this->editingGuarantorId) {
                $this->loan()->guarantors()->whereKey($this->editingGuarantorId)->update([
                    'name' => $this->guarantorName,
                    'phone' => $this->guarantorPhone ?: null,
                    'id_number' => $this->guarantorIdNumber ?: null,
                    'relationship' => $this->guarantorRelationship ?: null,
                ]);
                $message = __('Guarantor updated');
            } else {
                $this->loan()->guarantors()->create([
                    'name' => $this->guarantorName,
                    'phone' => $this->guarantorPhone ?: null,
                    'id_number' => $this->guarantorIdNumber ?: null,
                    'relationship' => $this->guarantorRelationship ?: null,
                ]);
                $message = __('Guarantor added');
            }

            $this->reset('showGuarantorModal', 'guarantorName', 'guarantorPhone', 'guarantorIdNumber', 'guarantorRelationship', 'editingGuarantorId');
            $this->dispatch('toast', message: $message);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function removeGuarantor(int $guarantorId): void
    {
        $this->actionError = null;

        try {
            \Illuminate\Support\Facades\Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $this->loan()->guarantors()->whereKey($guarantorId)->delete();

            $this->dispatch('toast', message: __('Guarantor removed'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function render(): View
    {
        // Actions in this request already mutated the loan; always render
        // from fresh state.
        $this->forgetLoan();

        return view('livewire.loans.show', [
            'loan' => $this->loan()->load(['collaterals', 'guarantors']),
        ]);
    }
}

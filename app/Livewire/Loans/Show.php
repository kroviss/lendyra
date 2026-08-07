<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\LedgerService;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use LoanEngine\AccrualBasis;
use LoanEngine\Money;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Show extends Component
{
    use WithFileUploads;

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

    // Reject modal state
    public bool $showRejectModal = false;

    public string $rejectReason = '';

    // Collateral modal state
    public bool $showCollateralModal = false;

    public string $collateralType = '';

    public string $collateralDescription = '';

    public ?float $collateralValue = null;

    /** @var TemporaryUploadedFile[] */
    public array $collateralPhotos = [];

    /** Stored photo paths kept while editing; entries the user removes are deleted on save. */
    public array $existingCollateralPhotos = [];

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
            'createdBy', 'approvedBy', 'disbursedBy', 'rejectedBy',
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
        if ($e instanceof ModelNotFoundException) {
            return __('That record no longer exists — refresh the page.');
        }

        if ($e instanceof HttpException) {
            return $e->getMessage() ?: __('You do not have permission to do that.');
        }

        if ($e instanceof QueryException) {
            report($e);

            return __('The database rejected that change. Please try again.');
        }

        if ($e instanceof AuthorizationException) {
            return $e->getMessage() ?: __('You do not have permission to do that.');
        }

        // Domain guards raise LogicException/InvalidArgumentException with
        // messages written for staff — safe to surface as-is.
        if ($e instanceof \LogicException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }

        // Anything else may carry class names or absolute paths — log it
        // for the operator, show staff a generic message.
        report($e);

        return __('Something went wrong. Please try again.');
    }

    public function activate(): void
    {
        $this->actionError = null;
        $loan = $this->loan();

        try {
            Gate::authorize('activate-loans');

            DB::transaction(function () {
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

                // The first due date was planned against the ORIGINAL
                // disbursement date; re-anchoring on today can leave it
                // anywhere relative to the real one. Nobody chose the new
                // first-period length, so every direction is an error the
                // officer must resolve by editing the loan — never a silent
                // repricing.
                if ($loan->first_due_date !== null) {
                    $disbursed = $loan->disbursed_at->format('Y-m-d');
                    $planned = $loan->first_due_date->format('Y-m-d');
                    $ceiling = Form::firstDueDateCeiling($disbursed, $loan->frequency);
                    $floor = Form::firstDueDateFloor($disbursed, $loan->frequency);

                    // Too far out: the first installment spans several
                    // periods but equal-periods pricing bills only one.
                    if ($planned > $ceiling->format('Y-m-d')) {
                        throw new \LogicException(__('First due date must be within two repayment periods of disbursement (on or before :date).', [
                            'date' => $ceiling->format('Y-m-d'),
                        ]));
                    }

                    // Already past: the engine would reject this with a bare
                    // "must be after disbursement" that says nothing about
                    // what to do next.
                    if ($planned <= $disbursed) {
                        throw new \LogicException(__('The planned first due date (:planned) is on or before today\'s disbursement. Edit the loan and set a later first due date before disbursing.', [
                            'planned' => $planned,
                        ]));
                    }

                    // Too close: under equal-periods pricing a days-long
                    // stub still bills a FULL period of interest — the
                    // borrower would be overcharged by a re-anchoring they
                    // never agreed to. Daily bases price stubs correctly.
                    if ($loan->basis === AccrualBasis::EqualPeriods && $planned < $floor->format('Y-m-d')) {
                        throw new \LogicException(__('The planned first due date (:planned) is only :days day(s) after today\'s disbursement, but equal-periods pricing would bill a full period of interest. Edit the loan and set a first due date on or after :floor.', [
                            'planned' => $planned,
                            'days' => $loan->disbursed_at->diffInDays($loan->first_due_date),
                            'floor' => $floor->format('Y-m-d'),
                        ]));
                    }
                }

                app(LoanScheduleService::class)->generateAndPersist($loan);
                $loan->update(['status' => LoanStatus::Active, 'disbursed_by' => auth()->id()]);
                app(LedgerService::class)->postDisbursement($loan);
            });

            $this->dispatch('toast', message: __('Loan disbursed and activated'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function approve(): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('activate-loans');

            DB::transaction(function () {
                // Lock + re-check: racing activate()/reject() must not
                // re-stamp a loan that already left the pending queue.
                $loan = Loan::lockForUpdate()->findOrFail($this->loanId);

                if ($loan->status !== LoanStatus::PendingApproval) {
                    throw new \LogicException(__('Only pending loans can be approved.'));
                }

                $loan->update([
                    'status' => LoanStatus::Approved,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            });

            $this->dispatch('toast', message: __('Loan approved'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function deleteLoan(): void
    {
        $this->actionError = null;

        try {
            DB::transaction(function () {
                // Lock + re-check: a concurrent activation must not let a
                // freshly disbursed loan slip through the status guard.
                $loan = Loan::lockForUpdate()->findOrFail($this->loanId);

                // The maker may withdraw their own application while it is
                // still pending; anything else needs approval rights.
                $isOwnPending = in_array($loan->status, [LoanStatus::PendingApproval, LoanStatus::Rejected], true)
                    && (int) $loan->created_by === (int) auth()->id()
                    && Gate::allows('create-loans');

                if (! $isOwnPending) {
                    Gate::authorize('activate-loans');
                }

                if (! in_array($loan->status, [LoanStatus::Draft, LoanStatus::PendingApproval, LoanStatus::Approved, LoanStatus::Rejected], true)) {
                    throw new \LogicException(__('Only loans that were never disbursed can be deleted.'));
                }

                $loan->delete();
            });

            session()->flash('status', __('Loan deleted'));
            $this->redirectRoute('loans.index');
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function waivePenalty(int $installmentId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('write-off-loans');

            // The service locks the loan + installments, waives on fresh
            // rows and closes the loan when nothing else is owed.
            $waived = app(PenaltyService::class)->waive($this->loan(), $installmentId);
            $this->forgetLoan();

            $this->dispatch('toast', message: __('Penalty of :amount waived', ['amount' => $waived->formatted()]));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    /** Opening/closing modals clears stale errors, edit state and validation. */
    public function updated($property, $value): void
    {
        if (str_starts_with($property, 'show') && str_ends_with($property, 'Modal')) {
            $this->actionError = null;
            $this->resetValidation();

            if ($value === false && $property === 'showCollateralModal') {
                $this->reset('collateralType', 'collateralDescription', 'collateralValue', 'collateralPhotos', 'existingCollateralPhotos', 'editingCollateralId');
            }

            if ($value === false && $property === 'showGuarantorModal') {
                $this->reset('guarantorName', 'guarantorPhone', 'guarantorIdNumber', 'guarantorRelationship', 'editingGuarantorId');
            }

            if ($value === false && $property === 'showPaymentModal') {
                // Reset the method too (like the payoff modal) — a sticky
                // "mobile" from one borrower must not carry into the next
                // cashier's cash receipt.
                $this->reset('paymentAmount', 'paymentReference', 'paymentMethod');
                $this->paymentDate = today()->format('Y-m-d');
            }

            if ($value === false && $property === 'showPayoffModal') {
                $this->reset('payoffMethod');
                $this->payoffDate = today()->format('Y-m-d');
            }

            if ($value === false && $property === 'showRejectModal') {
                $this->reset('rejectReason');
            }
        }
    }

    public function reject(): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('activate-loans');

            $this->validate(['rejectReason' => 'required|min:3|max:500']);

            DB::transaction(function () {
                // Lock + re-check: rejecting must not race a concurrent
                // activation and orphan a disbursed, active loan.
                $loan = Loan::lockForUpdate()->findOrFail($this->loanId);

                if (! in_array($loan->status, [LoanStatus::Approved, LoanStatus::PendingApproval], true)) {
                    throw new \LogicException(__('Only pending or approved loans can be rejected.'));
                }

                $loan->update([
                    'status' => LoanStatus::Rejected,
                    'approved_by' => null,
                    'approved_at' => null,
                    'reject_reason' => $this->rejectReason,
                    'rejected_by' => auth()->id(),
                    'rejected_at' => now(),
                ]);
            });

            $this->reset('showRejectModal', 'rejectReason');
            $this->dispatch('toast', message: __('Loan rejected'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function writeOff(): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('write-off-loans');

            DB::transaction(function () {
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
                    app(LedgerService::class)->postWriteOff($loan, $remaining);
                }
            });

            $this->dispatch('toast', message: __('Loan written off'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function reversePayment(int $paymentId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('reverse-payments');

            $payment = $this->loan()->payments()->findOrFail($paymentId);
            app(RepaymentService::class)->reverse($payment, auth()->id());
            $this->dispatch('toast', message: __('Payment reversed'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function accruePenalties(): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('record-payments');

            $before = $this->loan()->installments->sum('penalty_minor');
            app(PenaltyService::class)->accrue($this->loan(), new DateTimeImmutable(today()->format('Y-m-d')));
            $delta = $this->loan()->installments->sum('penalty_minor') - $before;

            $this->dispatch('toast', message: $delta > 0
                ? __('Penalties updated (+:amount)', ['amount' => Money::minor((int) $delta, $this->loan()->currency, (int) $this->loan()->scale)->formatted()])
                : __('Penalties are up to date'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function recordPayment(): void
    {
        $this->actionError = null;

        // Cashiers may only backdate within a short window: dating a payment on
        // or before an overdue due date silently erases accrued penalties, which
        // is a manager-only waiver in disguise. write-off-loans holders (admin/
        // manager) may backdate all the way to disbursement.
        $disbursedFloor = $this->loan()->disbursed_at?->format('Y-m-d') ?? '2000-01-01';
        $earliestDate = Gate::allows('write-off-loans')
            ? $disbursedFloor
            : max($disbursedFloor, today()->subDays((int) config('lms.payment_backdate_days', 7))->format('Y-m-d'));

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date|before_or_equal:today|after_or_equal:'.$earliestDate,
            'paymentMethod' => 'required|in:cash,bank,mobile',
            'paymentReference' => 'nullable|max:64',
        ], [
            'paymentDate.after_or_equal' => __('You can only backdate a payment up to :days days. Ask a manager to record older payments.', ['days' => (int) config('lms.payment_backdate_days', 7)]),
        ]);

        $loan = $this->loan();

        try {
            Gate::authorize('record-payments');

            app(RepaymentService::class)->record(
                $loan,
                Money::of((string) $this->paymentAmount, $loan->currency, (int) $loan->scale),
                new DateTimeImmutable($this->paymentDate),
                method: $this->paymentMethod,
                reference: $this->paymentReference ?: null,
                receivedBy: auth()->id(),
            );

            $this->reset('showPaymentModal', 'paymentAmount', 'paymentReference', 'paymentMethod');
            // Re-init here, not just in updated(): that hook only fires on
            // client-initiated closes, so a backdated date would otherwise
            // silently prefill the next payment.
            $this->paymentDate = today()->format('Y-m-d');
            $this->dispatch('toast', message: __('Payment recorded'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    /**
     * What the cashier needs while typing an amount: the next unpaid
     * installment, total arrears, and the borrower's unused credit.
     */
    public function getPaymentContextProperty(): ?array
    {
        $loan = $this->loan();

        if ($loan->installments->isEmpty()) {
            return null;
        }

        $money = fn (int $minor) => Money::minor($minor, $loan->currency, (int) $loan->scale)->formatted();

        $next = $loan->installments->first(fn ($i) => ! $i->isSettled());
        $arrears = $loan->installments
            ->filter(fn ($i) => ! $i->isSettled() && $i->due_date->isPast())
            ->sum(fn ($i) => $i->toDue()->totalDue()->minor);
        $credit = $loan->payments->whereNull('reversed_at')->sum('unallocated_minor');

        return [
            'nextDueLabel' => $next
                ? $money($next->toDue()->totalDue()->minor).' ('.$next->due_date->format('Y-m-d').')'
                : null,
            'arrears' => $arrears > 0 ? $money((int) $arrears) : null,
            'credit' => $credit > 0 ? $money((int) $credit) : null,
        ];
    }

    public function getPayoffQuoteProperty(): ?array
    {
        // An empty or half-typed date must not quote: new DateTimeImmutable('')
        // is valid PHP and silently means "now", so clearing the field would
        // keep showing a confident number for a date the user never chose.
        $parsed = \DateTime::createFromFormat('Y-m-d', $this->payoffDate);

        if (! $parsed || $parsed->format('Y-m-d') !== $this->payoffDate) {
            return null;
        }

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
            'payoffDate' => 'required|date|before_or_equal:today|after_or_equal:'.($this->loan()->disbursed_at?->format('Y-m-d') ?? '2000-01-01'),
            'payoffMethod' => 'required|in:cash,bank,mobile',
        ]);

        try {
            Gate::authorize('payoff-loans');

            app(PayoffService::class)->settle(
                $this->loan(),
                new DateTimeImmutable($this->payoffDate),
                method: $this->payoffMethod,
                receivedBy: auth()->id(),
            );

            $this->showPayoffModal = false;
            // Same stale-state guard as recordPayment(): a backdated payoff
            // date or non-default method must not leak into the next quote.
            $this->reset('payoffMethod');
            $this->payoffDate = today()->format('Y-m-d');
            $this->dispatch('toast', message: __('Loan settled in full'));
        } catch (ValidationException $e) {
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
            Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $this->validate([
                'collateralType' => 'required|max:64',
                'collateralDescription' => 'nullable|max:2000',
                'collateralValue' => 'required|numeric|min:0',
                'collateralPhotos.*' => 'image|max:4096',
            ]);

            $loan = $this->loan();

            // Private disk: photos are served through the authenticated
            // /media route, never as world-readable /storage URLs. Nothing
            // is written until every guard below has passed — a throw after
            // the upload would strand orphaned files on the private disk.
            $store = fn () => collect($this->collateralPhotos)
                ->map(fn ($photo) => $photo->store('collaterals', 'local'))
                ->all();

            if ($this->editingCollateralId) {
                $collateral = $loan->collaterals()->findOrFail($this->editingCollateralId);

                // existingCollateralPhotos round-trips through the client, so
                // treat it as untrusted: keep only entries that really belong
                // to this collateral. A forged path must neither survive into
                // the DB nor trick the diff below into deleting another
                // record's file on the private disk.
                $kept = array_values(array_intersect($collateral->photos ?? [], $this->existingCollateralPhotos));

                // Photos the user removed in the modal are gone from the
                // kept list.
                $removed = array_diff($collateral->photos ?? [], $kept);
                $newValueMinor = Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor;

                // Destroying photos or writing down the appraised value on a
                // live loan is the substance of a delete/release, which is
                // reserved for managers — otherwise a loan officer could erase
                // security evidence through the edit path. Additive edits
                // (new photos, higher value, description) stay open.
                $isDestructive = ! empty($removed) || $newValueMinor < (int) $collateral->estimated_value_minor;
                if ($isDestructive && ! Gate::allows('write-off-loans')) {
                    throw new \LogicException(__('Removing collateral photos or lowering its value requires a manager.'));
                }

                foreach ($removed as $path) {
                    Storage::disk('local')->delete($path);
                }

                $collateral->update([
                    'type' => $this->collateralType,
                    'description' => $this->collateralDescription ?: null,
                    'estimated_value_minor' => $newValueMinor,
                    'photos' => array_merge($kept, $store()) ?: null,
                ]);
                $message = __('Collateral updated');
            } else {
                $loan->collaterals()->create([
                    'type' => $this->collateralType,
                    'description' => $this->collateralDescription ?: null,
                    'estimated_value_minor' => Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor,
                    'photos' => $store() ?: null,
                ]);
                $message = __('Collateral added');
            }

            $this->reset('showCollateralModal', 'collateralType', 'collateralDescription', 'collateralValue', 'collateralPhotos', 'existingCollateralPhotos', 'editingCollateralId');
            $this->dispatch('toast', message: $message);
        } catch (ValidationException $e) {
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
            Gate::authorize('write-off-loans');

            $updated = $this->loan()->collaterals()
                ->whereKey($collateralId)
                ->update(['status' => 'released', 'released_at' => today()]);

            $this->dispatch('toast', message: $updated
                ? __('Collateral released')
                : __('That record no longer exists — refresh the page.'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function editCollateral(int $collateralId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $collateral = $this->loan()->collaterals()->findOrFail($collateralId);

            $this->editingCollateralId = $collateral->id;
            $this->collateralType = $collateral->type;
            $this->collateralDescription = (string) ($collateral->description ?? '');
            $this->collateralValue = (float) Money::minor((int) $collateral->estimated_value_minor, $this->loan()->currency, (int) $this->loan()->scale)->toDecimalString();
            $this->existingCollateralPhotos = array_values($collateral->photos ?? []);
            $this->showCollateralModal = true;
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    /** Drop one stored photo from the collateral being edited (applies on save). */
    public function removeExistingCollateralPhoto(int $index): void
    {
        unset($this->existingCollateralPhotos[$index]);
        $this->existingCollateralPhotos = array_values($this->existingCollateralPhotos);
    }

    public function deleteCollateral(int $collateralId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('write-off-loans');
            $this->assertLoanMutable();

            $deleted = $this->loan()->collaterals()->whereKey($collateralId)->delete();

            $this->dispatch('toast', message: $deleted
                ? __('Collateral deleted')
                : __('That record no longer exists — refresh the page.'));
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function editGuarantor(int $guarantorId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $guarantor = $this->loan()->guarantors()->findOrFail($guarantorId);

            $this->editingGuarantorId = $guarantor->id;
            $this->guarantorName = $guarantor->name;
            $this->guarantorPhone = (string) ($guarantor->phone ?? '');
            $this->guarantorIdNumber = (string) ($guarantor->id_number ?? '');
            $this->guarantorRelationship = (string) ($guarantor->relationship ?? '');
            $this->showGuarantorModal = true;
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function addGuarantor(): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('create-loans');
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
        } catch (ValidationException $e) {
            throw $e; // field errors stay inline
        } catch (Throwable $e) {
            $this->actionError = $this->friendlyError($e);
        }
    }

    public function removeGuarantor(int $guarantorId): void
    {
        $this->actionError = null;

        try {
            Gate::authorize('create-loans');
            $this->assertLoanMutable();

            $deleted = $this->loan()->guarantors()->whereKey($guarantorId)->delete();

            $this->dispatch('toast', message: $deleted
                ? __('Guarantor removed')
                : __('That record no longer exists — refresh the page.'));
        } catch (ValidationException $e) {
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

        $loan = $this->loan()->load(['collaterals', 'guarantors']);

        return view('livewire.loans.show', [
            'loan' => $loan,
            'overpaymentCredit' => (int) $loan->payments->whereNull('reversed_at')->sum('unallocated_minor'),
        ])->title($loan->loan_number.' — '.$loan->borrower->fullName());
    }
}

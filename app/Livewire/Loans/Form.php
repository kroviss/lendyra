<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\RepaymentFrequency;
use LoanEngine\Schedule;
use LoanEngine\ScheduleGenerator;
use Throwable;

class Form extends Component
{
    public ?Loan $loan = null;

    public ?int $borrower_id = null;

    public ?int $loan_product_id = null;

    public ?float $amount = null;

    public string $annual_rate = '';

    public string $term_count = '';

    public string $disbursed_at = '';

    public string $first_due_date = '';

    public string $purpose = '';

    public function mount(?Loan $loan = null): void
    {
        $this->disbursed_at = today()->format('Y-m-d');

        if ($loan === null && request()->filled('borrower')) {
            $this->borrower_id = (int) request()->query('borrower');
        }

        if ($loan !== null && $loan->exists) {
            Gate::authorize('create-loans');

            // Branch-scoped staff must not edit another branch's loan by
            // URL. Loans with no branch stay editable by everyone.
            $scoped = auth()->user()?->scopedBranchId();
            abort_if($scoped !== null && $loan->branch_id !== null && (int) $loan->branch_id !== $scoped, 403);

            $this->loan = $loan;

            if (! $this->loan->status->scheduleIsMutable()) {
                abort(403, __('Active or closed loans cannot be edited.'));
            }

            $this->borrower_id = $this->loan->borrower_id;
            $this->loan_product_id = $this->loan->loan_product_id;
            $this->amount = (float) $this->loan->principal()->toDecimalString();
            $this->annual_rate = (string) $this->loan->annual_rate;
            $this->term_count = (string) $this->loan->term_count;
            $this->disbursed_at = $this->loan->disbursed_at->format('Y-m-d');
            $this->first_due_date = $this->loan->first_due_date?->format('Y-m-d') ?? '';
            $this->purpose = (string) ($this->loan->purpose ?? '');
        }
    }

    /** Prefill rate/term from the chosen product. */
    public function updatedLoanProductId(): void
    {
        if ($product = LoanProduct::find($this->loan_product_id)) {
            $this->annual_rate = (string) $product->annual_rate;
            $this->term_count = (string) $product->term_count;
        }
    }

    protected function rules(): array
    {
        return [
            'borrower_id' => [
                'required',
                'exists:borrowers,id',
                // Branch-scoped officers must not originate loans against
                // another branch's borrowers (branchless borrowers allowed).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $scoped = auth()->user()?->scopedBranchId();
                    if ($scoped === null) {
                        return;
                    }

                    $branchId = Borrower::whereKey($value)->value('branch_id');
                    if ($branchId !== null && (int) $branchId !== $scoped) {
                        $fail(__('This borrower belongs to another branch.'));
                    }
                },
            ],
            'loan_product_id' => 'required|exists:loan_products,id',
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                // Product principal limits — configured per product but
                // never enforced anywhere else.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $product = LoanProduct::find($this->loan_product_id);
                    if ($product === null || ! is_numeric($value)) {
                        return;
                    }

                    $minor = Money::of((string) $value, $product->currency, (int) $product->scale)->minor;
                    $min = (int) $product->min_principal_minor;
                    $max = (int) $product->max_principal_minor;

                    if ($min > 0 && $minor < $min) {
                        $fail(__('Amount is below this product\'s minimum of :min.', [
                            'min' => Money::minor($min, $product->currency, (int) $product->scale)->formatted(),
                        ]));
                    }

                    if ($max > 0 && $minor > $max) {
                        $fail(__('Amount exceeds this product\'s maximum of :max.', [
                            'max' => Money::minor($max, $product->currency, (int) $product->scale)->formatted(),
                        ]));
                    }
                },
            ],
            'annual_rate' => 'required|numeric|min:0|max:1000',
            'term_count' => 'required|integer|min:1|max:600',
            'disbursed_at' => 'required|date',
            'first_due_date' => [
                'nullable',
                'date',
                'after:disbursed_at',
                // Under equal-periods pricing period 1 always charges exactly
                // one periodic rate no matter how long it really is, so an
                // unbounded first due date silently misprices months of
                // interest. Daily bases price stubs correctly, but a first
                // due date beyond two periods is a data-entry error there
                // too — the bound applies to every product.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $product = LoanProduct::find($this->loan_product_id);

                    if (! $value || $product === null
                        || ! strtotime((string) $value) || ! strtotime((string) $this->disbursed_at)) {
                        return; // required/date rules already cover these
                    }

                    $ceiling = self::firstDueDateCeiling($this->disbursed_at, $product->frequency);

                    if (CarbonImmutable::parse((string) $value)->startOfDay()->gt($ceiling)) {
                        $fail(__('First due date must be within two repayment periods of disbursement (on or before :date).', [
                            'date' => $ceiling->format('Y-m-d'),
                        ]));
                    }
                },
            ],
            'purpose' => 'nullable|max:2000',
        ];
    }

    /**
     * Latest acceptable first due date: two period-lengths after
     * disbursement. Shared with activation, which re-anchors the
     * schedule on the actual disbursement day.
     */
    public static function firstDueDateCeiling(DateTimeImmutable|string $disbursedAt, RepaymentFrequency $frequency): CarbonImmutable
    {
        $anchor = CarbonImmutable::parse(
            is_string($disbursedAt) ? $disbursedAt : $disbursedAt->format('Y-m-d')
        )->startOfDay();

        return match ($frequency) {
            RepaymentFrequency::Monthly => $anchor->addMonthsNoOverflow(2),
            RepaymentFrequency::Biweekly => $anchor->addDays(28),
            RepaymentFrequency::Weekly => $anchor->addDays(14),
        };
    }

    /** Processing fee in minor units: principal × % + flat. */
    private function feeMinor(LoanProduct $product): int
    {
        $principal = Money::of((string) $this->amount, $product->currency, (int) $product->scale);

        return $principal->multiply((float) $product->processing_fee_percent / 100)->minor
            + (int) $product->processing_fee_flat_minor;
    }

    private function buildTerms(LoanProduct $product): LoanTerms
    {
        return new LoanTerms(
            principal: Money::of((string) $this->amount, $product->currency, (int) $product->scale),
            annualRatePercent: (float) $this->annual_rate,
            termCount: (int) $this->term_count,
            frequency: $product->frequency,
            method: $product->method,
            disbursedAt: new DateTimeImmutable($this->disbursed_at),
            firstDueDate: $this->first_due_date !== '' ? new DateTimeImmutable($this->first_due_date) : null,
            basis: $product->basis,
        );
    }

    public function getFeePreviewProperty(): ?string
    {
        if (! $this->loan_product_id || ! $this->amount) {
            return null;
        }

        $product = LoanProduct::find($this->loan_product_id);
        $fee = $product ? $this->feeMinor($product) : 0;

        return $fee > 0
            ? Money::minor($fee, $product->currency, (int) $product->scale)->formatted().' '.$product->currency
            : null;
    }

    public function getPreviewProperty(): ?Schedule
    {
        try {
            $this->validate();

            return ScheduleGenerator::generate(
                $this->buildTerms(LoanProduct::findOrFail($this->loan_product_id))
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function save(): void
    {
        Gate::authorize('create-loans');

        $this->validate();

        $product = LoanProduct::findOrFail($this->loan_product_id);
        $terms = $this->buildTerms($product); // throws on impossible combinations

        if ($this->loan) {
            // Editing invalidates any prior approval: either the editor can
            // approve (re-stamp them as approver) or the loan goes back to
            // the pending queue. Terms must never change under a checker's
            // signature.
            $canApprove = Gate::allows('activate-loans');

            // Validation stays outside, but the mutability re-check and the
            // write are atomic: a concurrent activation may have locked the
            // schedule since this page loaded.
            DB::transaction(function () use ($product, $terms, $canApprove) {
                $loan = Loan::lockForUpdate()->findOrFail($this->loan->id);

                // Re-check branch scope on the fresh row: mount's check can
                // be stale (loan reassigned, or a forged Livewire snapshot).
                $scoped = auth()->user()?->scopedBranchId();
                abort_if($scoped !== null && $loan->branch_id !== null && (int) $loan->branch_id !== $scoped, 403);

                if (! $loan->status->scheduleIsMutable()) {
                    abort(403);
                }

                $loan->update([
                    'status' => $canApprove ? LoanStatus::Approved : LoanStatus::PendingApproval,
                    'approved_by' => $canApprove ? auth()->id() : null,
                    'approved_at' => $canApprove ? now() : null,
                    'borrower_id' => $this->borrower_id,
                    'loan_product_id' => $product->id,
                    'currency' => $product->currency,
                    'scale' => $product->scale,
                    'principal_minor' => $terms->principal->minor,
                    'fee_minor' => $this->feeMinor($product),
                    'annual_rate' => (float) $this->annual_rate,
                    'term_count' => (int) $this->term_count,
                    'method' => $product->method->value,
                    'frequency' => $product->frequency->value,
                    'basis' => $product->basis->value,
                    'disbursed_at' => $this->disbursed_at,
                    'first_due_date' => $this->first_due_date ?: null,
                    'purpose' => $this->purpose ?: null,
                ]);
            });

            session()->flash('status', __('Loan updated'));
            $this->redirectRoute('loans.show', $this->loan);

            return;
        }

        $attributes = [
            'borrower_id' => $this->borrower_id,
            'loan_product_id' => $product->id,
            'branch_id' => auth()->user()?->branch_id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => $terms->principal->minor,
            'fee_minor' => $this->feeMinor($product),
            'annual_rate' => (float) $this->annual_rate,
            'term_count' => (int) $this->term_count,
            'method' => $product->method->value,
            'frequency' => $product->frequency->value,
            'basis' => $product->basis->value,
            'disbursed_at' => $this->disbursed_at,
            'first_due_date' => $this->first_due_date ?: null,
            'application_date' => today(),
            'status' => Gate::allows('activate-loans')
                ? LoanStatus::Approved
                : LoanStatus::PendingApproval,
            'approved_by' => Gate::allows('activate-loans') ? auth()->id() : null,
            'approved_at' => Gate::allows('activate-loans') ? now() : null,
            'purpose' => $this->purpose ?: null,
            'created_by' => auth()->id(),
        ];

        // Two concurrent saves can mint the same sequential number; on a
        // duplicate-key hit regenerate and retry instead of surfacing a
        // raw SQL error, forcing the random suffix on the last attempt.
        $loan = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $loan = Loan::create(['loan_number' => $this->nextLoanNumber(forceSuffix: $attempt === 3)] + $attributes);

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 3) {
                    throw $e;
                }
            }
        }

        session()->flash('status', Gate::allows('activate-loans')
            ? __('Loan created — review and disburse when ready')
            : __('Loan submitted — a manager must approve it before disbursement'));
        $this->redirectRoute('loans.show', $loan);
    }

    /** Sequential number with a collision-proof suffix fallback. */
    private function nextLoanNumber(bool $forceSuffix = false): string
    {
        $base = 'LN-'.now()->format('y').'-'.str_pad((string) (Loan::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT);

        return $forceSuffix || Loan::withTrashed()->where('loan_number', $base)->exists()
            ? $base.'-'.strtoupper(substr(uniqid(), -4))
            : $base;
    }

    public function render(): View
    {
        // Cap the embedded option list; very large books need the
        // async select on the roadmap, but 500 covers the target scale.
        // Branch-scoped staff only see their own branch (+ branchless).
        $borrowerOptions = Borrower::query()
            ->when(auth()->user()?->scopedBranchId(), fn ($q, $branch) => $q->where(
                fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id')
            ))
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn ($b) => ['value' => $b->id, 'label' => $b->fullName().($b->phone ? " ({$b->phone})" : '')]);

        // A selected borrower older than the cap (prefill from the profile
        // page, or an existing loan being edited) must still appear —
        // otherwise the select silently shows its placeholder while a
        // value is set.
        if ($this->borrower_id !== null && ! $borrowerOptions->contains('value', $this->borrower_id)) {
            // Same branch scope as the option list — without it a scoped
            // officer could $set() arbitrary IDs and read foreign borrowers'
            // names and phones. A null hit renders as no selection.
            $selected = Borrower::query()
                ->when(auth()->user()?->scopedBranchId(), fn ($q, $branch) => $q->where(
                    fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id')
                ))
                ->find($this->borrower_id);
            if ($selected !== null) {
                $borrowerOptions->prepend([
                    'value' => $selected->id,
                    'label' => $selected->fullName().($selected->phone ? " ({$selected->phone})" : ''),
                ]);
            }
        }

        return view('livewire.loans.form', [
            'borrowerOptions' => $borrowerOptions,
            'productOptions' => LoanProduct::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($p) => ['value' => $p->id, 'label' => $p->name]),
        ])->title($this->loan?->exists ? __('Edit loan') : __('New loan'));
    }
}

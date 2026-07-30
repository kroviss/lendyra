<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
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
            \Illuminate\Support\Facades\Gate::authorize('create-loans');

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
            'borrower_id' => 'required|exists:borrowers,id',
            'loan_product_id' => 'required|exists:loan_products,id',
            'amount' => 'required|numeric|min:0.01',
            'annual_rate' => 'required|numeric|min:0|max:1000',
            'term_count' => 'required|integer|min:1|max:600',
            'disbursed_at' => 'required|date',
            'first_due_date' => 'nullable|date|after:disbursed_at',
            'purpose' => 'nullable|max:2000',
        ];
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
        \Illuminate\Support\Facades\Gate::authorize('create-loans');

        $this->validate();

        $product = LoanProduct::findOrFail($this->loan_product_id);
        $terms = $this->buildTerms($product); // throws on impossible combinations

        if ($this->loan) {
            if (! $this->loan->status->scheduleIsMutable()) {
                abort(403);
            }

            // Editing invalidates any prior approval: either the editor can
            // approve (re-stamp them as approver) or the loan goes back to
            // the pending queue. Terms must never change under a checker's
            // signature.
            $canApprove = \Illuminate\Support\Facades\Gate::allows('activate-loans');

            $this->loan->update([
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

            session()->flash('status', __('Loan updated'));
            $this->redirectRoute('loans.show', $this->loan);

            return;
        }

        $loan = Loan::create([
            'loan_number' => $this->nextLoanNumber(),
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
            'status' => \Illuminate\Support\Facades\Gate::allows('activate-loans')
                ? LoanStatus::Approved
                : LoanStatus::PendingApproval,
            'approved_by' => \Illuminate\Support\Facades\Gate::allows('activate-loans') ? auth()->id() : null,
            'approved_at' => \Illuminate\Support\Facades\Gate::allows('activate-loans') ? now() : null,
            'purpose' => $this->purpose ?: null,
            'created_by' => auth()->id(),
        ]);

        session()->flash('status', __('Loan created — review and disburse when ready'));
        $this->redirectRoute('loans.show', $loan);
    }

    /** Sequential number with a collision-proof suffix fallback. */
    private function nextLoanNumber(): string
    {
        $base = 'LN-'.now()->format('y').'-'.str_pad((string) (Loan::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT);

        return Loan::withTrashed()->where('loan_number', $base)->exists()
            ? $base.'-'.strtoupper(substr(uniqid(), -4))
            : $base;
    }

    public function render(): View
    {
        return view('livewire.loans.form', [
            // Cap the embedded option list; very large books need the
            // async select on the roadmap, but 500 covers the target scale.
            'borrowerOptions' => Borrower::orderByDesc('id')
                ->limit(500)
                ->get()
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->fullName().($b->phone ? " ({$b->phone})" : '')]),
            'productOptions' => LoanProduct::where('is_active', true)
                ->orderBy('name')
                ->get(['id as value', 'name as label']),
        ]);
    }
}

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
    public ?int $borrower_id = null;
    public ?int $loan_product_id = null;
    public ?float $amount = null;
    public string $annual_rate = '';
    public string $term_count = '';
    public string $disbursed_at = '';
    public string $first_due_date = '';
    public string $purpose = '';

    public function mount(): void
    {
        $this->disbursed_at = today()->format('Y-m-d');
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
            ? Money::minor($fee, $product->currency, (int) $product->scale)->toDecimalString().' '.$product->currency
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
        $this->validate();

        $product = LoanProduct::findOrFail($this->loan_product_id);
        $terms = $this->buildTerms($product); // throws on impossible combinations

        $loan = Loan::create([
            'loan_number' => 'LN-'.now()->format('y').'-'.str_pad((string) (Loan::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
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
            'status' => LoanStatus::Approved,
            'purpose' => $this->purpose ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->redirectRoute('loans.show', $loan);
    }

    public function render(): View
    {
        return view('livewire.loans.form', [
            'borrowerOptions' => Borrower::orderBy('first_name')
                ->get()
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->fullName().($b->phone ? " ({$b->phone})" : '')]),
            'productOptions' => LoanProduct::where('is_active', true)
                ->orderBy('name')
                ->get(['id as value', 'name as label']),
        ]);
    }
}

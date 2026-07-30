<?php

namespace App\Livewire\Products;

use App\Models\LoanProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use LoanEngine\AccrualBasis;
use LoanEngine\InterestMethod;
use LoanEngine\PenaltyBase;
use LoanEngine\RepaymentFrequency;

class Form extends Component
{
    public ?LoanProduct $product = null;

    public string $name = '';
    public string $code = '';
    public string $currency = 'USD';
    public string $method = 'declining_equal_principal';
    public string $frequency = 'monthly';
    public string $basis = 'equal_periods';
    public string $annual_rate = '';
    public string $term_count = '';
    public string $penalty_daily_rate = '0';
    public string $penalty_grace_days = '0';
    public string $penalty_base = 'overdue_principal';
    public string $penalty_cap_percent = '';
    public bool $is_active = true;

    public function mount(?int $product = null): void
    {
        if ($product !== null) {
            $this->product = LoanProduct::findOrFail($product);

            $this->name = $this->product->name;
            $this->code = $this->product->code;
            $this->currency = $this->product->currency;
            $this->method = $this->product->method->value;
            $this->frequency = $this->product->frequency->value;
            $this->basis = $this->product->basis->value;
            $this->annual_rate = (string) $this->product->annual_rate;
            $this->term_count = (string) $this->product->term_count;
            $this->penalty_daily_rate = (string) $this->product->penalty_daily_rate;
            $this->penalty_grace_days = (string) $this->product->penalty_grace_days;
            $this->penalty_base = $this->product->penalty_base->value;
            $this->penalty_cap_percent = (string) ($this->product->penalty_cap_percent ?? '');
            $this->is_active = $this->product->is_active;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'code' => [
                'required', 'max:32', 'alpha_dash',
                Rule::unique('loan_products', 'code')->ignore($this->product?->id),
            ],
            'currency' => 'required|size:3|alpha',
            'method' => Rule::enum(InterestMethod::class),
            'frequency' => Rule::enum(RepaymentFrequency::class),
            'basis' => Rule::enum(AccrualBasis::class),
            'annual_rate' => 'required|numeric|min:0|max:1000',
            'term_count' => 'required|integer|min:1|max:600',
            'penalty_daily_rate' => 'required|numeric|min:0|max:100',
            'penalty_grace_days' => 'required|integer|min:0|max:365',
            'penalty_base' => Rule::enum(PenaltyBase::class),
            'penalty_cap_percent' => 'nullable|numeric|min:0|max:1000',
            'is_active' => 'boolean',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['currency'] = strtoupper($data['currency']);
        $data['penalty_cap_percent'] = $data['penalty_cap_percent'] === '' ? null : $data['penalty_cap_percent'];

        // Annuity math is only defined for equal periods — force-correct
        // the basis rather than persist an impossible combination.
        if ($data['method'] === InterestMethod::Annuity->value) {
            $data['basis'] = AccrualBasis::EqualPeriods->value;
        }

        if ($this->product) {
            $this->product->update($data);
        } else {
            LoanProduct::create($data);
        }

        session()->flash('status', __('Product saved'));
        $this->redirectRoute('products.index');
    }

    public function render(): View
    {
        return view('livewire.products.form', [
            'methodOptions' => collect(InterestMethod::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => ucwords(str_replace('_', ' ', $c->value))]),
            'frequencyOptions' => collect(RepaymentFrequency::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)]),
            'basisOptions' => collect(AccrualBasis::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => ucwords(str_replace('_', ' ', $c->value))]),
            'penaltyBaseOptions' => collect(PenaltyBase::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => ucwords(str_replace('_', ' ', $c->value))]),
        ]);
    }
}

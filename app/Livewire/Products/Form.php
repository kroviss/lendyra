<?php

namespace App\Livewire\Products;

use App\Models\LoanProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use LoanEngine\AccrualBasis;
use LoanEngine\AllocationComponent;
use LoanEngine\AllocationMode;
use LoanEngine\InterestMethod;
use LoanEngine\Money;
use LoanEngine\PenaltyBase;
use LoanEngine\RepaymentFrequency;

class Form extends Component
{
    public ?LoanProduct $product = null;

    public string $name = '';

    public string $code = '';

    public string $currency = 'USD';

    public string $scale = '2';

    public string $method = 'declining_equal_principal';

    public string $frequency = 'monthly';

    public string $basis = 'equal_periods';

    public string $annual_rate = '';

    public string $term_count = '';

    public string $min_principal = '';

    public string $max_principal = '';

    public string $processing_fee_percent = '0';

    public string $processing_fee_flat = '';

    public string $penalty_daily_rate = '0';

    public string $penalty_grace_days = '0';

    public string $penalty_base = 'overdue_principal';

    public string $penalty_cap_percent = '';

    public string $min_term_count = '';

    public string $max_term_count = '';

    public string $allocation_mode = 'oldest_installment_first';

    /** Comma-joined waterfall, chosen from the presets below. */
    public string $allocation_order = 'penalty,interest,principal';

    public bool $is_active = true;

    /**
     * The waterfall orders offered in the UI. Restricting the choice to a
     * preset list guarantees every order is a full permutation of the
     * three components — a partial order would strand one forever.
     */
    public const ALLOCATION_ORDERS = [
        'penalty,interest,principal',
        'interest,principal,penalty',
        'principal,interest,penalty',
    ];

    // Livewire binds the {product} route parameter to the typed public
    // property as a model — accept both shapes like Users\Form does.
    public function mount(LoanProduct|int|null $product = null): void
    {
        if ($product !== null) {
            $this->product = $product instanceof LoanProduct ? $product : LoanProduct::findOrFail($product);

            $this->name = $this->product->name;
            $this->code = $this->product->code;
            $this->currency = $this->product->currency;
            $this->scale = (string) $this->product->scale;
            $this->method = $this->product->method->value;
            $this->frequency = $this->product->frequency->value;
            $this->basis = $this->product->basis->value;
            $this->annual_rate = (string) $this->product->annual_rate;
            $this->term_count = (string) $this->product->term_count;
            $this->min_principal = $this->product->min_principal_minor !== null
                ? Money::minor((int) $this->product->min_principal_minor, $this->product->currency, (int) $this->product->scale)->toDecimalString()
                : '';
            $this->max_principal = $this->product->max_principal_minor !== null
                ? Money::minor((int) $this->product->max_principal_minor, $this->product->currency, (int) $this->product->scale)->toDecimalString()
                : '';
            $this->processing_fee_percent = (string) $this->product->processing_fee_percent;
            $this->processing_fee_flat = (int) $this->product->processing_fee_flat_minor > 0
                ? Money::minor((int) $this->product->processing_fee_flat_minor, $this->product->currency, (int) $this->product->scale)->toDecimalString()
                : '';
            $this->penalty_daily_rate = (string) $this->product->penalty_daily_rate;
            $this->penalty_grace_days = (string) $this->product->penalty_grace_days;
            $this->penalty_base = $this->product->penalty_base->value;
            $this->penalty_cap_percent = (string) ($this->product->penalty_cap_percent ?? '');
            $this->min_term_count = (string) ($this->product->min_term_count ?? '');
            $this->max_term_count = (string) ($this->product->max_term_count ?? '');
            $this->allocation_mode = $this->product->allocationPolicy()->mode->value;
            // Read back through allocationPolicy() so a hand-edited or
            // legacy row still maps onto one of the offered presets.
            $this->allocation_order = implode(',', array_map(
                fn ($c) => $c->value,
                $this->product->allocationPolicy()->order
            ));
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
            'scale' => [
                'required', 'integer', 'min:0', 'max:6',
                // Aggregates (trial balance, dashboards) group by bare
                // currency, so two products sharing a currency must agree
                // on its minor-unit scale.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $conflict = LoanProduct::where('currency', strtoupper($this->currency))
                        ->when($this->product, fn ($q) => $q->whereKeyNot($this->product->id))
                        ->where('scale', '!=', (int) $value)
                        ->value('scale');

                    if ($conflict !== null) {
                        $fail(__('Other :currency products use :scale decimal places — a currency must use one scale everywhere.', [
                            'currency' => strtoupper($this->currency),
                            'scale' => $conflict,
                        ]));
                    }
                },
            ],
            'method' => Rule::enum(InterestMethod::class),
            'frequency' => Rule::enum(RepaymentFrequency::class),
            'basis' => Rule::enum(AccrualBasis::class),
            'annual_rate' => 'required|numeric|min:0|max:1000',
            'term_count' => 'required|integer|min:1|max:600',
            // Term limits are enforced on every loan originated against
            // this product, exactly like the principal limits.
            'min_term_count' => 'nullable|integer|min:1|max:600',
            'max_term_count' => 'nullable|integer|min:1|max:600|gte:min_term_count',
            'allocation_mode' => Rule::enum(AllocationMode::class),
            'allocation_order' => Rule::in(self::ALLOCATION_ORDERS),
            'min_principal' => 'nullable|numeric|min:0',
            'max_principal' => 'nullable|numeric|min:0|gte:min_principal',
            'processing_fee_percent' => 'required|numeric|min:0|max:100',
            'processing_fee_flat' => 'nullable|numeric|min:0',
            'penalty_daily_rate' => 'required|numeric|min:0|max:100',
            'penalty_grace_days' => 'required|integer|min:0|max:365',
            'penalty_base' => Rule::enum(PenaltyBase::class),
            'penalty_cap_percent' => 'nullable|numeric|min:0|max:1000',
            'is_active' => 'boolean',
        ];
    }

    public function save(): void
    {
        Gate::authorize('manage-products');

        $data = $this->validate();
        $data['currency'] = strtoupper($data['currency']);
        $data['penalty_cap_percent'] = $data['penalty_cap_percent'] === '' ? null : $data['penalty_cap_percent'];

        // Changing the scale after loans exist would reinterpret every
        // stored minor amount (100 cents ≠ 100 fils) — lock it.
        if ($this->product
            && (int) $data['scale'] !== (int) $this->product->scale
            && $this->product->loans()->withTrashed()->exists()) {
            $this->addError('scale', __('Scale cannot change once loans exist for this product.'));

            return;
        }

        $toMinor = fn (string $decimal): int => Money::of($decimal, $data['currency'], (int) $data['scale'])->minor;

        $data['min_principal_minor'] = $data['min_principal'] === '' ? null : $toMinor($data['min_principal']);
        $data['max_principal_minor'] = $data['max_principal'] === '' ? null : $toMinor($data['max_principal']);
        $data['processing_fee_flat_minor'] = $data['processing_fee_flat'] === '' ? 0 : $toMinor($data['processing_fee_flat']);
        unset($data['min_principal'], $data['max_principal'], $data['processing_fee_flat']);

        $data['min_term_count'] = $data['min_term_count'] === '' ? null : (int) $data['min_term_count'];
        $data['max_term_count'] = $data['max_term_count'] === '' ? null : (int) $data['max_term_count'];
        // Stored as JSON so the engine reads a real list, not a CSV string.
        $data['allocation_order'] = explode(',', $data['allocation_order']);

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

    public function delete(): void
    {
        if (config('lms.demo')) {
            $this->dispatch('toast', message: __('Deleting is disabled in demo mode.'));

            return;
        }

        Gate::authorize('manage-products');

        if (! $this->product) {
            return;
        }

        if ($this->product->loans()->withTrashed()->exists()) {
            $this->addError('name', __('Cannot delete: loans exist for this product. Deactivate it instead.'));

            return;
        }

        $this->product->delete();

        session()->flash('status', __('Product deleted'));
        $this->redirectRoute('products.index');
    }

    public function render(): View
    {
        return view('livewire.products.form', [
            'methodOptions' => collect(InterestMethod::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'frequencyOptions' => collect(RepaymentFrequency::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'basisOptions' => collect(AccrualBasis::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'penaltyBaseOptions' => collect(PenaltyBase::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'allocationModeOptions' => collect(AllocationMode::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'allocationOrderOptions' => collect(self::ALLOCATION_ORDERS)
                ->map(fn (string $order) => [
                    'value' => $order,
                    'label' => implode(' → ', array_map(
                        fn (string $c) => AllocationComponent::from($c)->label(),
                        explode(',', $order)
                    )),
                ]),
            // tryFrom: render must survive a forged/half-typed value; the
            // validator is what actually rejects it on save.
            'allocationModeHint' => (AllocationMode::tryFrom($this->allocation_mode)
                ?? AllocationMode::OldestInstallmentFirst)->hint(),
        ])->title($this->product ? __('Edit product') : __('New product'));
    }
}

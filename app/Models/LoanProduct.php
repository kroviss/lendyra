<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LoanEngine\AccrualBasis;
use LoanEngine\AllocationComponent;
use LoanEngine\AllocationMode;
use LoanEngine\AllocationPolicy;
use LoanEngine\EarlyPayoffInterestMode;
use LoanEngine\InterestMethod;
use LoanEngine\PenaltyBase;
use LoanEngine\PenaltyConfig;
use LoanEngine\RepaymentFrequency;

class LoanProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'method' => InterestMethod::class,
            'frequency' => RepaymentFrequency::class,
            'basis' => AccrualBasis::class,
            'penalty_base' => PenaltyBase::class,
            'allocation_mode' => AllocationMode::class,
            'payoff_interest_mode' => EarlyPayoffInterestMode::class,
            'allocation_order' => 'array',
            'annual_rate' => 'float',
            'penalty_daily_rate' => 'float',
            'penalty_cap_percent' => 'float',
            'processing_fee_percent' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function penaltyConfig(): PenaltyConfig
    {
        return new PenaltyConfig(
            dailyRatePercent: $this->penalty_daily_rate,
            graceDays: (int) $this->penalty_grace_days,
            base: $this->penalty_base,
            capPercentOfBase: $this->penalty_cap_percent,
        );
    }

    /** The default waterfall, used to fill any gap in a stored order. */
    public const DEFAULT_ALLOCATION_ORDER = ['penalty', 'interest', 'principal'];

    /**
     * The waterfall this product allocates payments by.
     *
     * The stored order is sanitized rather than trusted: unknown or
     * duplicated entries are dropped and any missing component is appended
     * in the default order. AllocationPolicy rejects a partial order (it
     * would strand a component forever), and a product row edited straight
     * in SQL must degrade to a sane waterfall, not fatal the payment page.
     */
    public function allocationPolicy(): AllocationPolicy
    {
        $order = collect($this->allocation_order ?: self::DEFAULT_ALLOCATION_ORDER)
            ->filter(fn ($c) => is_string($c) && AllocationComponent::tryFrom($c) !== null)
            ->unique()
            ->merge(self::DEFAULT_ALLOCATION_ORDER)
            ->unique()
            ->map(fn (string $c) => AllocationComponent::from($c))
            ->values()
            ->all();

        return new AllocationPolicy(
            order: $order,
            mode: $this->allocation_mode ?? AllocationMode::OldestInstallmentFirst,
        );
    }
}

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

    public function allocationPolicy(): AllocationPolicy
    {
        $order = collect($this->allocation_order ?: ['penalty', 'interest', 'principal'])
            ->map(fn (string $c) => AllocationComponent::from($c))
            ->all();

        return new AllocationPolicy(order: $order, mode: $this->allocation_mode);
    }
}

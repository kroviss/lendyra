<?php

namespace App\Models;

use App\Enums\LoanStatus;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LoanEngine\AccrualBasis;
use LoanEngine\EarlyPayoffInterestMode;
use LoanEngine\InstallmentDue;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\PenaltyBase;
use LoanEngine\PenaltyConfig;
use LoanEngine\RepaymentFrequency;

class Loan extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'method' => InterestMethod::class,
            'frequency' => RepaymentFrequency::class,
            'basis' => AccrualBasis::class,
            'penalty_base' => PenaltyBase::class,
            'payoff_interest_mode' => EarlyPayoffInterestMode::class,
            'penalty_daily_rate' => 'float',
            'penalty_cap_percent' => 'float',
            'annual_rate' => 'decimal:4',
            'application_date' => 'date',
            'disbursed_at' => 'date',
            'first_due_date' => 'date',
            'closed_at' => 'date',
            'written_off_at' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function installments(): HasMany
    {
        // chaperone() back-fills the inverse relation, so an installment
        // never lazy-loads its own loan for currency/scale lookups.
        return $this->hasMany(LoanInstallment::class)->orderBy('number')->chaperone();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->chaperone();
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function principal(): Money
    {
        return Money::minor((int) $this->principal_minor, $this->currency, (int) $this->scale);
    }

    public function zero(): Money
    {
        return Money::zero($this->currency, (int) $this->scale);
    }

    /** Engine terms built from this loan's own (product-snapshot) fields. */
    public function terms(): LoanTerms
    {
        return new LoanTerms(
            principal: $this->principal(),
            annualRatePercent: $this->annual_rate,
            termCount: (int) $this->term_count,
            frequency: $this->frequency,
            method: $this->method,
            disbursedAt: new DateTimeImmutable($this->disbursed_at->format('Y-m-d')),
            firstDueDate: $this->first_due_date
                ? new DateTimeImmutable($this->first_due_date->format('Y-m-d'))
                : null,
            basis: $this->basis,
        );
    }

    /**
     * The penalty terms this loan was originated under. Snapshotted onto the
     * loan (like annual_rate/basis) so re-pricing the product never rewrites a
     * live loan's penalty history. Falls back to the product only for a loan
     * predating the snapshot columns that somehow escaped the backfill.
     */
    public function penaltyConfig(): PenaltyConfig
    {
        if ($this->penalty_base === null) {
            return $this->product->penaltyConfig();
        }

        return new PenaltyConfig(
            dailyRatePercent: (float) $this->penalty_daily_rate,
            graceDays: (int) $this->penalty_grace_days,
            base: $this->penalty_base,
            capPercentOfBase: $this->penalty_cap_percent !== null ? (float) $this->penalty_cap_percent : null,
        );
    }

    /** The early-payoff interest treatment this loan was originated under. */
    public function payoffInterestMode(): EarlyPayoffInterestMode
    {
        return $this->payoff_interest_mode ?? $this->product->payoff_interest_mode;
    }

    /** @return InstallmentDue[] the engine's view of what is still owed */
    public function duesState(): array
    {
        return $this->installments
            ->map(fn (LoanInstallment $i) => $i->toDue())
            ->all();
    }

    public function principalOutstanding(): Money
    {
        return $this->installments->reduce(
            fn (Money $carry, LoanInstallment $i) => $carry->add($i->principalDue()),
            $this->zero()
        );
    }
}

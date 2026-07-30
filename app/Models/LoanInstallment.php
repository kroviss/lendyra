<?php

namespace App\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LoanEngine\InstallmentDue;
use LoanEngine\Money;

class LoanInstallment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'settled_at' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    private function money(int $minor): Money
    {
        return Money::minor($minor, $this->loan->currency, (int) $this->loan->scale);
    }

    public function principalDue(): Money
    {
        return $this->money((int) $this->principal_minor - (int) $this->principal_paid_minor);
    }

    public function interestDue(): Money
    {
        return $this->money((int) $this->interest_minor - (int) $this->interest_paid_minor);
    }

    public function penaltyDue(): Money
    {
        return $this->money((int) $this->penalty_minor - (int) $this->penalty_paid_minor);
    }

    public function isSettled(): bool
    {
        return $this->principalDue()->isZero()
            && $this->interestDue()->isZero()
            && $this->penaltyDue()->isZero();
    }

    public function toDue(): InstallmentDue
    {
        return new InstallmentDue(
            number: (int) $this->number,
            dueDate: new DateTimeImmutable($this->due_date->format('Y-m-d')),
            principalDue: $this->principalDue(),
            interestDue: $this->interestDue(),
            penaltyDue: $this->penaltyDue(),
        );
    }
}

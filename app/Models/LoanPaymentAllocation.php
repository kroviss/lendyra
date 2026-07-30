<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LoanEngine\AllocationComponent;

class LoanPaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['component' => AllocationComponent::class];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(LoanInstallment::class, 'loan_installment_id');
    }
}

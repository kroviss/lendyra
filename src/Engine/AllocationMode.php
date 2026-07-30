<?php

declare(strict_types=1);

namespace LoanEngine;

enum AllocationMode: string
{
    /** Settle the oldest installment completely before touching the next. */
    case OldestInstallmentFirst = 'oldest_installment_first';

    /** Settle one component across ALL installments before the next component. */
    case ComponentAcrossLoan = 'component_across_loan';
}

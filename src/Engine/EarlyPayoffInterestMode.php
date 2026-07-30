<?php

declare(strict_types=1);

namespace LoanEngine;

enum EarlyPayoffInterestMode: string
{
    /** Charge interest only for days actually elapsed in the current period. */
    case Prorated = 'prorated';

    /** Charge the current installment's full scheduled interest (lender-friendly). */
    case FullPeriod = 'full_period';
}

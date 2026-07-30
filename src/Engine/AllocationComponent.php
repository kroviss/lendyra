<?php

declare(strict_types=1);

namespace LoanEngine;

enum AllocationComponent: string
{
    case Penalty = 'penalty';
    case Interest = 'interest';
    case Principal = 'principal';
}

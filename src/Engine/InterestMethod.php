<?php

declare(strict_types=1);

namespace LoanEngine;

enum InterestMethod: string
{
    case Flat = 'flat';
    case DecliningEqualPrincipal = 'declining_equal_principal';
    case Annuity = 'annuity';
    case InterestOnlyBalloon = 'interest_only_balloon';
}

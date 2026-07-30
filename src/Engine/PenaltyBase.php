<?php

declare(strict_types=1);

namespace LoanEngine;

enum PenaltyBase: string
{
    case OverduePrincipal = 'overdue_principal';
    case OverdueInstallment = 'overdue_installment';
}

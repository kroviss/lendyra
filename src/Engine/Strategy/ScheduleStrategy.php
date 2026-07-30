<?php

declare(strict_types=1);

namespace LoanEngine\Strategy;

use DateTimeImmutable;
use LoanEngine\Installment;
use LoanEngine\LoanTerms;

interface ScheduleStrategy
{
    /**
     * @param DateTimeImmutable[] $dueDates one per installment
     * @return Installment[]
     */
    public function generate(LoanTerms $terms, array $dueDates): array;
}

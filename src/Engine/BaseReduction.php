<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A dated reduction of a penalty base: a payment (or payment-shaped
 * event) that shrank the amount penalty accrues on, effective the day
 * it was made. Days up to and including the date accrue on the old
 * base; days after it accrue on the reduced base.
 */
final class BaseReduction
{
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly Money $amount,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A base reduction cannot be negative.');
        }
    }
}

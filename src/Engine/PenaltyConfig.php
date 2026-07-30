<?php

declare(strict_types=1);

namespace LoanEngine;

use InvalidArgumentException;

final class PenaltyConfig
{
    public function __construct(
        public readonly float $dailyRatePercent,
        public readonly int $graceDays = 0,
        public readonly PenaltyBase $base = PenaltyBase::OverduePrincipal,
        /** Total penalty never exceeds this percentage of its base (null = no cap). */
        public readonly ?float $capPercentOfBase = null,
    ) {
        if ($dailyRatePercent < 0 || $graceDays < 0) {
            throw new InvalidArgumentException('Penalty rate and grace days cannot be negative.');
        }
        if ($capPercentOfBase !== null && $capPercentOfBase < 0) {
            throw new InvalidArgumentException('Penalty cap cannot be negative.');
        }
    }
}

<?php

namespace App\Support;

use App\Models\LoanProduct;
use LoanEngine\Money;

/**
 * Currency → minor-unit scale map, sourced from loan_products (the only
 * place a scale is configured; fallback 2). Detail rows carry their own
 * loan's scale — aggregates grouped by bare currency use this so a
 * 0-decimal currency is never rendered with phantom cents.
 *
 * Registered as a container singleton, so the lookup runs at most once
 * per request.
 */
class CurrencyScale
{
    /** @var array<string, int>|null */
    private ?array $map = null;

    public function scale(string $currency): int
    {
        $this->map ??= LoanProduct::query()
            ->pluck('scale', 'currency')
            ->map(fn ($scale) => (int) $scale)
            ->all();

        return $this->map[$currency] ?? 2;
    }

    public function money(int $minor, string $currency): Money
    {
        return Money::minor($minor, $currency, $this->scale($currency));
    }
}

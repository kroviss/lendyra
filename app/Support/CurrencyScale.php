<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanProduct;
use LoanEngine\Money;

/**
 * Currency → minor-unit scale map. Detail rows carry their own loan's
 * scale — aggregates grouped by bare currency use this so a 0-decimal
 * currency is never rendered with phantom cents.
 *
 * Products are the live configuration and answer first. A currency that
 * no longer belongs to ANY product (the product was re-pointed at another
 * currency after loans were originated) still exists in the books, so the
 * loans themselves — which snapshot currency AND scale at origination —
 * are the fallback. Without it such a currency silently dropped to scale
 * 2 and every grouped total rendered 100× too small.
 *
 * Registered as a container singleton, so each lookup runs at most once
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

        if (! array_key_exists($currency, $this->map)) {
            // One LIMIT 1 lookup, only on a miss, memoized either way —
            // the common path never touches the loans table.
            $this->map[$currency] = (int) (Loan::withTrashed()
                ->where('currency', $currency)
                ->value('scale') ?? 2);
        }

        return $this->map[$currency];
    }

    public function money(int $minor, string $currency): Money
    {
        return Money::minor($minor, $currency, $this->scale($currency));
    }
}

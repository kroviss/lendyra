<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use InvalidArgumentException;
use LoanEngine\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_string_parsing_is_exact(): void
    {
        $this->assertSame(1000050, Money::of('10000.50')->minor);
        $this->assertSame(1000000, Money::of('10000')->minor);
        $this->assertSame(999, Money::of('9.999')->minor); // truncated to scale, not rounded up
        $this->assertSame(-50025, Money::of('-500.25')->minor);
        $this->assertSame(5, Money::of('0.05')->minor);
    }

    public function test_invalid_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('10,000.50');
    }

    public function test_split_gives_remainder_to_last_part(): void
    {
        $parts = Money::of('10000.00')->split(12);

        $this->assertCount(12, $parts);
        $this->assertSame(83333, $parts[0]->minor);   // 833.33
        $this->assertSame(83333, $parts[10]->minor);
        $this->assertSame(83337, $parts[11]->minor);  // 833.37 absorbs remainder

        $sum = array_sum(array_map(fn (Money $p) => $p->minor, $parts));
        $this->assertSame(1000000, $sum);
    }

    public function test_multiply_rounds_half_up(): void
    {
        $this->assertSame(9212, Money::minor(921151)->multiply(0.01)->minor); // 9211.51 → 9212
        $this->assertSame(8333, Money::minor(833334)->multiply(0.01)->minor); // 8333.34 → 8333
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('10.00', 'USD')->add(Money::of('10.00', 'MNT'));
    }

    public function test_decimal_string_round_trip(): void
    {
        $this->assertSame('10000.50', Money::of('10000.50')->toDecimalString());
        $this->assertSame('-0.05', Money::minor(-5)->toDecimalString());
        $this->assertSame('833.37', Money::minor(83337)->toDecimalString());
    }
}

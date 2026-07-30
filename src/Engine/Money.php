<?php

declare(strict_types=1);

namespace LoanEngine;

use InvalidArgumentException;

/**
 * Immutable money value in integer minor units. No floats are ever
 * stored; floats only appear transiently in multiply() and are rounded
 * immediately.
 */
final class Money
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
        public readonly int $scale,
    ) {
    }

    public static function minor(int $minor, string $currency = 'USD', int $scale = 2): self
    {
        return new self($minor, $currency, $scale);
    }

    /** Accepts "10000.50", 10000.5, or 10000. Strings are parsed exactly. */
    public static function of(int|float|string $major, string $currency = 'USD', int $scale = 2): self
    {
        if (is_string($major)) {
            if (! preg_match('/^-?\d+(\.\d+)?$/', $major)) {
                throw new InvalidArgumentException("Invalid money string: {$major}");
            }
            $negative = str_starts_with($major, '-');
            [$whole, $fraction] = array_pad(explode('.', ltrim($major, '-'), 2), 2, '');
            $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
            $minor = ((int) $whole) * (10 ** $scale) + (int) $fraction;

            return new self($negative ? -$minor : $minor, $currency, $scale);
        }

        return new self((int) round($major * (10 ** $scale)), $currency, $scale);
    }

    public static function zero(string $currency = 'USD', int $scale = 2): self
    {
        return new self(0, $currency, $scale);
    }

    public function add(self $other): self
    {
        $this->assertSame($other);

        return new self($this->minor + $other->minor, $this->currency, $this->scale);
    }

    public function sub(self $other): self
    {
        $this->assertSame($other);

        return new self($this->minor - $other->minor, $this->currency, $this->scale);
    }

    public function multiply(float $factor): self
    {
        return new self((int) round($this->minor * $factor, 0, PHP_ROUND_HALF_UP), $this->currency, $this->scale);
    }

    /** Split into $parts near-equal amounts; the LAST part absorbs the remainder. */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('parts must be >= 1');
        }

        $base = intdiv($this->minor, $parts);
        $result = array_fill(0, $parts, new self($base, $this->currency, $this->scale));
        $result[$parts - 1] = new self($this->minor - $base * ($parts - 1), $this->currency, $this->scale);

        return $result;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor
            && $this->currency === $other->currency
            && $this->scale === $other->scale;
    }

    /** Human display form with thousands separators: "1,234,567.89". */
    public function formatted(): string
    {
        $abs = abs($this->minor);
        $divisor = 10 ** $this->scale;
        $whole = number_format(intdiv($abs, $divisor));
        $fraction = str_pad((string) ($abs % $divisor), $this->scale, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        return $this->scale > 0 ? "{$sign}{$whole}.{$fraction}" : "{$sign}{$whole}";
    }

    public function toDecimalString(): string
    {
        $abs = abs($this->minor);
        $divisor = 10 ** $this->scale;
        $whole = intdiv($abs, $divisor);
        $fraction = str_pad((string) ($abs % $divisor), $this->scale, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        return $this->scale > 0 ? "{$sign}{$whole}.{$fraction}" : "{$sign}{$whole}";
    }

    private function assertSame(self $other): void
    {
        if ($this->currency !== $other->currency || $this->scale !== $other->scale) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency}/{$this->scale} vs {$other->currency}/{$other->scale}"
            );
        }
    }
}

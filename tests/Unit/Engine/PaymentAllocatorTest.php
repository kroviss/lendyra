<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\AllocationComponent;
use LoanEngine\AllocationMode;
use LoanEngine\AllocationPolicy;
use LoanEngine\InstallmentDue;
use LoanEngine\Money;
use LoanEngine\PaymentAllocator;
use PHPUnit\Framework\TestCase;

class PaymentAllocatorTest extends TestCase
{
    /** @return InstallmentDue[] */
    private function dues(): array
    {
        return [
            new InstallmentDue(
                number: 1,
                dueDate: new DateTimeImmutable('2026-02-15'),
                principalDue: Money::of('833.33'),
                interestDue: Money::of('100.00'),
                penaltyDue: Money::of('5.00'),
            ),
            new InstallmentDue(
                number: 2,
                dueDate: new DateTimeImmutable('2026-03-15'),
                principalDue: Money::of('833.33'),
                interestDue: Money::of('91.67'),
                penaltyDue: Money::zero(),
            ),
        ];
    }

    public function test_oldest_first_waterfall(): void
    {
        // 1000.00 → pen1 5.00, int1 100.00, prin1 833.33, then int2 61.67 (partial)
        $result = PaymentAllocator::allocate(Money::of('1000.00'), $this->dues());

        $this->assertSame('0.00', $result->remainder->toDecimalString());
        $this->assertCount(4, $result->lines);

        [$a, $b, $c, $d] = $result->lines;
        $this->assertSame([1, AllocationComponent::Penalty, '5.00'], [$a->installmentNumber, $a->component, $a->amount->toDecimalString()]);
        $this->assertSame([1, AllocationComponent::Interest, '100.00'], [$b->installmentNumber, $b->component, $b->amount->toDecimalString()]);
        $this->assertSame([1, AllocationComponent::Principal, '833.33'], [$c->installmentNumber, $c->component, $c->amount->toDecimalString()]);
        $this->assertSame([2, AllocationComponent::Interest, '61.67'], [$d->installmentNumber, $d->component, $d->amount->toDecimalString()]);
    }

    public function test_component_across_loan_mode(): void
    {
        // 1000.00 → pen1 5.00 | int1 100.00, int2 91.67 | prin1 803.33 (partial)
        $result = PaymentAllocator::allocate(
            Money::of('1000.00'),
            $this->dues(),
            new AllocationPolicy(mode: AllocationMode::ComponentAcrossLoan)
        );

        $this->assertSame('0.00', $result->remainder->toDecimalString());
        $this->assertSame('191.67', $result->allocatedTo(AllocationComponent::Interest)->toDecimalString());
        $this->assertSame('803.33', $result->allocatedTo(AllocationComponent::Principal)->toDecimalString());

        $last = $result->lines[count($result->lines) - 1];
        $this->assertSame(1, $last->installmentNumber); // principal goes to oldest first
    }

    public function test_overpayment_leaves_remainder(): void
    {
        $result = PaymentAllocator::allocate(Money::of('2000.00'), $this->dues());

        // Total owed: 5 + 100 + 833.33 + 91.67 + 833.33 = 1863.33
        $this->assertSame('1863.33', $result->totalAllocated()->toDecimalString());
        $this->assertSame('136.67', $result->remainder->toDecimalString());
    }

    public function test_custom_order_interest_before_penalty(): void
    {
        $result = PaymentAllocator::allocate(
            Money::of('100.00'),
            $this->dues(),
            new AllocationPolicy(order: [AllocationComponent::Interest, AllocationComponent::Penalty, AllocationComponent::Principal])
        );

        $this->assertSame(AllocationComponent::Interest, $result->lines[0]->component);
        $this->assertSame('100.00', $result->lines[0]->amount->toDecimalString());
        $this->assertCount(1, $result->lines);
    }

    public function test_settled_installments_are_skipped(): void
    {
        $dues = [
            new InstallmentDue(1, new DateTimeImmutable('2026-02-15'), Money::zero(), Money::zero(), Money::zero()),
            new InstallmentDue(2, new DateTimeImmutable('2026-03-15'), Money::of('50.00'), Money::of('10.00'), Money::zero()),
        ];

        $result = PaymentAllocator::allocate(Money::of('30.00'), $dues);

        $this->assertSame(2, $result->lines[0]->installmentNumber);
        $this->assertSame(AllocationComponent::Interest, $result->lines[0]->component);
        $this->assertSame('20.00', $result->lines[1]->amount->toDecimalString()); // partial principal
    }

    public function test_allocation_is_deterministic(): void
    {
        $first = PaymentAllocator::allocate(Money::of('500.00'), $this->dues());
        $second = PaymentAllocator::allocate(Money::of('500.00'), $this->dues());

        $this->assertEquals($first, $second);
    }
}

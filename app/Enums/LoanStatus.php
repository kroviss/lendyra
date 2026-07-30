<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Active = 'active';
    case Closed = 'closed';
    case WrittenOff = 'written_off';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Closed => 'Closed',
            self::WrittenOff => 'Written off',
            self::Rejected => 'Rejected',
        });
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-700',
            self::Approved => 'bg-blue-100 text-blue-700',
            self::PendingApproval => 'bg-yellow-100 text-yellow-700',
            self::Closed => 'bg-gray-100 text-gray-600',
            self::WrittenOff => 'bg-red-100 text-red-700',
            self::Rejected => 'bg-orange-100 text-orange-700',
            self::Draft => 'bg-gray-100 text-gray-500',
        };
    }

    /** Statuses whose schedule may still be regenerated. */
    public function scheduleIsMutable(): bool
    {
        // Rejected applications stay editable so the maker can revise and
        // resubmit instead of re-keying the whole thing.
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved, self::Rejected], true);
    }

    public function acceptsPayments(): bool
    {
        return $this === self::Active;
    }
}

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

    /** Statuses whose schedule may still be regenerated. */
    public function scheduleIsMutable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved], true);
    }

    public function acceptsPayments(): bool
    {
        return $this === self::Active;
    }
}

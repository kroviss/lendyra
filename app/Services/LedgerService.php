<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanPayment;
use InvalidArgumentException;
use LoanEngine\AllocationComponent;
use LogicException;

/**
 * Double-entry postings for loan lifecycle events. Every entry is
 * balanced by construction (post() throws otherwise), so the trial
 * balance can never drift.
 */
class LedgerService
{
    /** Dr Loan Portfolio / Cr Cash for the disbursed principal. */
    public function postDisbursement(Loan $loan): JournalEntry
    {
        return $this->post(
            date: $loan->disbursed_at->format('Y-m-d'),
            reference: ['loan', $loan->id],
            memo: "Disbursement {$loan->loan_number}",
            lines: [
                ['account' => 'portfolio', 'debit' => $loan->principal_minor, 'credit' => 0],
                ['account' => 'cash', 'debit' => 0, 'credit' => $loan->principal_minor],
            ],
            currency: $loan->currency,
        );
    }

    /**
     * Dr Cash for the full amount; Cr Portfolio (principal), Interest
     * Income, Penalty Income per the payment's allocation lines, and
     * Cr Borrower Overpayments for any unallocated remainder.
     */
    public function postPayment(LoanPayment $payment): JournalEntry
    {
        $byComponent = [
            AllocationComponent::Principal->value => 0,
            AllocationComponent::Interest->value => 0,
            AllocationComponent::Penalty->value => 0,
        ];

        foreach ($payment->allocations as $allocation) {
            $byComponent[$allocation->component->value] += (int) $allocation->amount_minor;
        }

        $lines = [['account' => 'cash', 'debit' => (int) $payment->amount_minor, 'credit' => 0]];

        foreach ([
            AllocationComponent::Principal->value => 'portfolio',
            AllocationComponent::Interest->value => 'interest_income',
            AllocationComponent::Penalty->value => 'penalty_income',
        ] as $component => $account) {
            if ($byComponent[$component] > 0) {
                $lines[] = ['account' => $account, 'debit' => 0, 'credit' => $byComponent[$component]];
            }
        }

        if ((int) $payment->unallocated_minor > 0) {
            $lines[] = ['account' => 'overpayment', 'debit' => 0, 'credit' => (int) $payment->unallocated_minor];
        }

        return $this->post(
            date: $payment->paid_at->format('Y-m-d'),
            reference: ['loan_payment', $payment->id],
            memo: "Payment {$payment->loan->loan_number}",
            lines: $lines,
            currency: $payment->loan->currency,
        );
    }

    /** @param array<int, array{account: string, debit: int, credit: int}> $lines */
    private function post(string $date, array $reference, string $memo, array $lines, string $currency): JournalEntry
    {
        $debits = array_sum(array_column($lines, 'debit'));
        $credits = array_sum(array_column($lines, 'credit'));

        if ($debits !== $credits || $debits === 0) {
            throw new LogicException("Unbalanced journal entry: Dr {$debits} vs Cr {$credits}.");
        }

        $entry = JournalEntry::create([
            'entry_date' => $date,
            'reference_type' => $reference[0],
            'reference_id' => $reference[1],
            'memo' => $memo,
            'created_by' => auth()->id(),
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create([
                'ledger_account_id' => $this->account($line['account'])->id,
                'debit_minor' => $line['debit'],
                'credit_minor' => $line['credit'],
                'currency' => $currency,
            ]);
        }

        return $entry;
    }

    private function account(string $key): LedgerAccount
    {
        $config = config("lms.accounts.{$key}")
            ?? throw new InvalidArgumentException("Unknown ledger account key: {$key}");

        return LedgerAccount::firstOrCreate(
            ['code' => $config['code']],
            ['name' => $config['name'], 'type' => $config['type']]
        );
    }
}

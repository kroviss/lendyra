<?php

namespace App\Livewire\Reports;

use App\Models\LedgerAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use LoanEngine\Money;

class TrialBalance extends Component
{
    public function render(): View
    {
        // Unqualified columns keep this table-prefix safe.
        $sums = \App\Models\JournalLine::query()
            ->select('ledger_account_id', DB::raw('SUM(debit_minor) as debits'), DB::raw('SUM(credit_minor) as credits'))
            ->groupBy('ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        $rows = LedgerAccount::orderBy('code')->get()
            ->map(function (LedgerAccount $account) use ($sums) {
                $debits = (int) ($sums[$account->id]->debits ?? 0);
                $credits = (int) ($sums[$account->id]->credits ?? 0);
                $net = $debits - $credits;

                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debits' => Money::minor($debits)->toDecimalString(),
                    'credits' => Money::minor($credits)->toDecimalString(),
                    'balance' => Money::minor(abs($net))->toDecimalString().($net < 0 ? ' Cr' : ($net > 0 ? ' Dr' : '')),
                ];
            });

        $totalDebits = (int) DB::table('journal_lines')->sum('debit_minor');
        $totalCredits = (int) DB::table('journal_lines')->sum('credit_minor');

        return view('livewire.reports.trial-balance', [
            'rows' => $rows,
            'totalDebits' => Money::minor($totalDebits)->toDecimalString(),
            'totalCredits' => Money::minor($totalCredits)->toDecimalString(),
            'balanced' => $totalDebits === $totalCredits,
        ]);
    }
}

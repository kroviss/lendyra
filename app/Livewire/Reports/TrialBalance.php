<?php

namespace App\Livewire\Reports;

use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\CurrencyScale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TrialBalance extends Component
{
    public function render(): View
    {
        // Journal lines carry no branch dimension, so the trial balance is
        // always org-wide — branch-scoped staff must not see it at all.
        abort_if(auth()->user()?->scopedBranchId() !== null, 403, __('The trial balance covers all branches and is not available to branch-scoped accounts.'));

        $scales = app(CurrencyScale::class);

        // Grouped by account AND currency — cents of different currencies
        // must never be added together.
        $sums = JournalLine::query()
            ->select(
                'ledger_account_id',
                'currency',
                DB::raw('SUM(debit_minor) as debits'),
                DB::raw('SUM(credit_minor) as credits')
            )
            ->groupBy('ledger_account_id', 'currency')
            ->get()
            ->groupBy('ledger_account_id');

        $accounts = LedgerAccount::orderBy('code')->get();
        $rows = [];

        foreach ($accounts as $account) {
            $lines = $sums->get($account->id, collect());

            foreach ($lines as $line) {
                $debits = (int) $line->debits;
                $credits = (int) $line->credits;
                $net = $debits - $credits;

                $rows[] = [
                    'code' => $account->code,
                    'name' => $account->name.($lines->count() > 1 ? ' ('.$line->currency.')' : ''),
                    'type' => $account->type,
                    'debits' => $scales->money($debits, $line->currency)->formatted(),
                    'credits' => $scales->money($credits, $line->currency)->formatted(),
                    'balance' => $scales->money(abs($net), $line->currency)->formatted().($net < 0 ? ' '.__('Cr') : ($net > 0 ? ' '.__('Dr') : '')),
                ];
            }
        }

        // Per-currency grand totals; books balance iff every currency does.
        $currencyTotals = JournalLine::query()
            ->select('currency', DB::raw('SUM(debit_minor) as debits'), DB::raw('SUM(credit_minor) as credits'))
            ->groupBy('currency')
            ->get();

        $balanced = $currencyTotals->every(fn ($t) => (int) $t->debits === (int) $t->credits);

        $format = fn (string $column) => $currencyTotals
            ->map(fn ($t) => $scales->money((int) $t->{$column}, $t->currency)->formatted().' '.$t->currency)
            ->implode(' · ');

        return view('livewire.reports.trial-balance', [
            'rows' => collect($rows),
            'totalDebits' => $currencyTotals->isEmpty() ? '0.00' : $format('debits'),
            'totalCredits' => $currencyTotals->isEmpty() ? '0.00' : $format('credits'),
            'balanced' => $balanced,
        ])->title(__('Trial balance'));
    }
}

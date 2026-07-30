<?php

namespace App\Livewire\SmsLogs;

use App\Models\SmsLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    protected function query(): Builder
    {
        return SmsLog::query()->with('installment.loan');
    }

    protected function columns(): array
    {
        return [
            Column::make('created_at', __('Sent at'))->date('Y-m-d H:i')->sortable(),
            Column::make('to', __('To'))->searchable(),
            Column::make('kind', __('Type'))->center()->badge([
                'upcoming' => 'bg-blue-100 text-blue-700',
                'overdue' => 'bg-red-100 text-red-700',
            ]),
            Column::make('installment.loan.loan_number', __('Loan #')),
            Column::make('message', __('Message'))
                ->searchable()
                ->format(fn ($value) => \Illuminate\Support\Str::limit($value, 80)),
            Column::make('status', __('Status'))->center()->badge([
                'sent' => 'bg-green-100 text-green-700',
                'failed' => 'bg-red-100 text-red-700',
            ]),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return $row->installment?->loan
            ? route('loans.show', $row->installment->loan)
            : null;
    }

    public function render(): View
    {
        return view('livewire.sms-logs.index', ['columns' => $this->columns()]);
    }
}

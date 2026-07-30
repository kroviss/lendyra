<?php

namespace App\Livewire\SmsLogs;

use App\Models\SmsLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $kindFilter = '';
    public string $statusFilter = '';

    protected function queryString(): array
    {
        return parent::queryString() + [
            'kindFilter' => ['except' => '', 'as' => 'kind'],
            'statusFilter' => ['except' => '', 'as' => 'status'],
        ];
    }

    public function updated($property): void
    {
        if (in_array($property, ['kindFilter', 'statusFilter'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    protected function query(): Builder
    {
        return SmsLog::query()
            ->with('installment.loan')
            ->when($this->kindFilter !== '', fn (Builder $q) => $q->where('kind', $this->kindFilter))
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter));
    }

    protected function searchAlso(): array
    {
        return ['installment.loan.loan_number'];
    }

    public function resend(int $logId): void
    {
        $log = SmsLog::findOrFail($logId);

        $ok = \App\Services\Sms\SmsFactory::make()->send($log->to, $log->message);

        $log->update(['status' => $ok ? 'sent' : 'failed']);

        $this->dispatch('toast', message: $ok ? __('SMS resent') : __('Resend failed — check the SMS driver settings'));
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
            Column::make('id', __(''))->center()->format(
                fn ($value, $row) => $row->status === 'failed'
                    ? '<button type="button" wire:click.stop="resend('.$value.')" wire:confirm="'.e(__('Resend this SMS?')).'" class="text-xs text-indigo-600 hover:underline">'.e(__('Resend')).'</button>'
                    : '',
                html: true
            ),
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

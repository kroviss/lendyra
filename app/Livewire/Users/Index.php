<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $roleFilter = '';

    public string $activeFilter = '';

    protected function queryString(): array
    {
        return parent::queryString() + [
            'roleFilter' => ['except' => '', 'as' => 'role'],
            'activeFilter' => ['except' => '', 'as' => 'active'],
        ];
    }

    public function updated($property): void
    {
        if (in_array($property, ['roleFilter', 'activeFilter'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    protected function query(): Builder
    {
        return User::query()
            ->with('branch')
            ->when($this->roleFilter !== '', fn (Builder $q) => $q->where('role', $this->roleFilter))
            ->when($this->activeFilter !== '', fn (Builder $q) => $q->where('is_active', $this->activeFilter === '1'));
    }

    protected function canExport(): bool
    {
        return in_array(auth()->user()?->role, ['admin'], true);
    }

    protected function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable(),
            Column::make('email', __('Email'))->searchable(),
            Column::make('role', __('Role'))->center()->format(
                fn ($value) => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium '.match ($value) {
                    'admin' => 'bg-purple-100 text-purple-700',
                    'manager' => 'bg-blue-100 text-blue-700',
                    'loan_officer' => 'bg-green-100 text-green-700',
                    'cashier' => 'bg-yellow-100 text-yellow-700',
                    default => 'bg-gray-100 text-gray-700',
                }.'">'.e(User::roleLabel($value)).'</span>',
                html: true
            ),
            Column::make('branch.name', __('Branch')),
            Column::make('is_active', __('Status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">'.__('Active').'</span>'
                    : '<span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">'.__('Disabled').'</span>',
                html: true
            ),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('users.edit', $row);
    }

    public function render(): View
    {
        return view('livewire.users.index', ['columns' => $this->columns()])->title(__('Users'));
    }
}

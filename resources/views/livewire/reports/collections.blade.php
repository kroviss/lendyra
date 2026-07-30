<div>
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('Collections') }}</h1>
            <select wire:model.live="window"
                class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                <option value="today">{{ __('Due today') }}</option>
                <option value="week">{{ __('Due this week') }}</option>
                <option value="month">{{ __('Due this month') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search...') }}"
                class="w-56 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40" />
        </div>
        <div class="flex gap-2 text-sm">
            @if (in_array(auth()->user()?->role, ['admin', 'manager', 'accountant'], true))
                <a href="{{ route('reports.portfolio') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Portfolio') }}</a>
            @endif
            <span class="rounded-lg bg-indigo-600 px-3 py-1.5 font-medium text-white">{{ __('Collections') }}</span>
            @if (in_array(auth()->user()?->role, ['admin', 'manager', 'accountant'], true))
                <a href="{{ route('reports.trial-balance') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Trial Balance') }}</a>
            @endif
        </div>
    </div>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">{{ __('Expected in this window') }}</p>
        <p class="mt-1 text-2xl font-semibold">{{ $totalLabel }}</p>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('Due date') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Loan #') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Borrower') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Phone') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Principal') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Interest') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Penalty') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Total due') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($installments as $installment)
                    @php $due = $installment->toDue(); @endphp
                    <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 {{ $installment->due_date->isPast() ? 'bg-red-50/40' : '' }}"
                        x-on:click="window.location = '{{ route('loans.show', $installment->loan_id) }}'">
                        <td class="px-4 py-2">{{ $installment->due_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-2 font-medium">{{ $installment->loan->loan_number }}</td>
                        <td class="px-4 py-2">{{ $installment->loan->borrower->fullName() }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $installment->loan->borrower->phone }}</td>
                        <td class="px-4 py-2 text-right">{{ $due->principalDue->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right">{{ $due->interestDue->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right">{{ $due->penaltyDue->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $due->totalDue()->toDecimalString() }} {{ $installment->loan->currency }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">{{ __('Nothing due in this window — all caught up!') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($installments->hasPages())
        <div class="mt-4">{{ $installments->links() }}</div>
    @endif
</div>

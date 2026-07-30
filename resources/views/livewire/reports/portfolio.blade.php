<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('Portfolio Report') }}</h1>
        <div class="flex gap-2 text-sm">
            <span class="rounded-lg bg-indigo-600 px-3 py-1.5 font-medium text-white">{{ __('Portfolio') }}</span>
            <a href="{{ route('reports.trial-balance') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Trial Balance') }}</a>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Portfolio outstanding') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ $totalOutstanding }}</p>
        </div>
        @foreach ([['label' => 'PAR 30', 'value' => $par30], ['label' => 'PAR 60', 'value' => $par60], ['label' => 'PAR 90', 'value' => $par90]] as $stat)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold {{ $stat['value'] !== '—' && (float) $stat['value'] > 5 ? 'text-red-600' : '' }}">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    <h2 class="mb-3 text-base font-semibold">{{ __('Overdue loans') }}</h2>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('Loan #') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Borrower') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Days overdue') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount overdue') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Outstanding') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($overdueRows as $row)
                    <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50"
                        x-on:click="window.location = '{{ route('loans.show', $row['id']) }}'">
                        <td class="px-4 py-2 font-medium">{{ $row['loan_number'] }}</td>
                        <td class="px-4 py-2">{{ $row['borrower'] }}</td>
                        <td class="px-4 py-2 text-right">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $row['days'] > 90 ? 'bg-red-100 text-red-700' : ($row['days'] > 30 ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                {{ $row['days'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">{{ $row['overdue'] }} {{ $row['currency'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['outstanding'] }} {{ $row['currency'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">{{ __('No overdue loans — well done!') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

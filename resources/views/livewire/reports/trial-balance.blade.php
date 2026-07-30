<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('Trial Balance') }}</h1>
        <div class="flex gap-2 text-sm">
            <a href="{{ route('reports.portfolio') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Portfolio') }}</a>
            <span class="rounded-lg bg-indigo-600 px-3 py-1.5 font-medium text-white">{{ __('Trial Balance') }}</span>
        </div>
    </div>

    @unless ($balanced)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ __('WARNING: books are out of balance!') }}
        </div>
    @endunless

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('Code') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Debits') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Credits') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-gray-100">
                        <td class="px-4 py-2 font-mono text-xs">{{ $row['code'] }}</td>
                        <td class="px-4 py-2 font-medium">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 capitalize text-gray-500">{{ $row['type'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['debits'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['credits'] }}</td>
                        <td class="px-4 py-2 text-right font-medium">{{ $row['balance'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ __('No journal entries yet.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                    <tr>
                        <td colspan="3" class="px-4 py-2">{{ __('Totals') }}</td>
                        <td class="px-4 py-2 text-right">{{ $totalDebits }}</td>
                        <td class="px-4 py-2 text-right">{{ $totalCredits }}</td>
                        <td class="px-4 py-2 text-right">{{ $balanced ? '✓' : '✗' }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<div>
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Dashboard') }}</h1>

    @can('activate-loans')
        @if ($pendingCount > 0)
            <a href="{{ route('loans.index', ['status' => 'pending_approval']) }}"
                class="mb-4 flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 hover:bg-amber-100">
                <span>⏳ {{ trans_choice(':count loan is awaiting your approval|:count loans are awaiting your approval', $pendingCount, ['count' => $pendingCount]) }}</span>
                <span class="font-medium">{{ __('Review') }} →</span>
            </a>
        @endif
    @endcan

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Active loans'), 'value' => $activeLoans, 'url' => route('loans.index', ['status' => 'active'])],
            ['label' => __('Portfolio outstanding'), 'value' => $outstanding, 'url' => route('loans.index', ['status' => 'active'])],
            ['label' => __('Overdue installments'), 'value' => $overdueCount, 'url' => route('reports.collections', ['window' => 'overdue'])],
            ['label' => __('Collected this month'), 'value' => $collectedThisMonth, 'url' => route('payments.index', ['from' => now()->startOfMonth()->format('Y-m-d'), 'to' => now()->endOfMonth()->format('Y-m-d')])],
        ] as $stat)
            <a href="{{ $stat['url'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-200 hover:shadow">
                <p class="text-sm text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $stat['value'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold">{{ __('Collections — last 6 months') }}</h2>
            <span class="text-xs text-gray-400">{{ $chartCurrency }}</span>
        </div>
        <div class="flex items-end gap-3">
            @foreach ($chartBars as $bar)
                <div class="group flex flex-1 flex-col items-center gap-1">
                    <span class="text-xs font-medium text-gray-500">{{ $bar["value"] }}</span>
                    {{-- Fixed-height track so the percentage height of the bar resolves --}}
                    <div class="flex h-32 w-full items-end">
                        <div class="w-full rounded-t-md bg-indigo-500 transition group-hover:bg-indigo-600" style="height: {{ max(2, $bar['height']) }}%"></div>
                    </div>
                    <span class="text-xs text-gray-500">{{ $bar['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

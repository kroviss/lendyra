<div>
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Dashboard') }}</h1>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Active loans'), 'value' => $activeLoans],
            ['label' => __('Portfolio outstanding'), 'value' => $outstanding],
            ['label' => __('Overdue installments'), 'value' => $overdueCount],
            ['label' => __('Collected this month'), 'value' => $collectedThisMonth],
        ] as $stat)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>
</div>

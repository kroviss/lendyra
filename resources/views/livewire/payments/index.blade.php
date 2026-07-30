<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('Payments') }}</h1>
            <select wire:model.live="methodFilter"
                class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                <option value="">{{ __('All methods') }}</option>
                <option value="cash">{{ __('Cash') }}</option>
                <option value="bank">{{ __('Bank transfer') }}</option>
                <option value="mobile">{{ __('Mobile money') }}</option>
            </select>
            <input type="date" wire:model.live="from" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm" />
            <span class="text-gray-400">—</span>
            <input type="date" wire:model.live="to" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm" />
            <button
                wire:click="$set('from', '{{ today()->format('Y-m-d') }}'); $set('to', '{{ today()->format('Y-m-d') }}')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ __('Today') }}
            </button>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Total collected (filtered)') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ $totalLabel }}</p>
        </div>
        @foreach ($methodTotals as $method => $label)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm capitalize text-gray-500">{{ __(ucfirst($method)) }}</p>
                <p class="mt-1 text-lg font-semibold">{{ $label }}</p>
            </div>
        @endforeach
    </div>

    @include('tablewire::table.table')
</div>

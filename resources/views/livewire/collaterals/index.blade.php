<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('Collateral Registry') }}</h1>
            <select wire:model.live="statusFilter"
                class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                <option value="">{{ __('All') }}</option>
                <option value="held">{{ __('Held') }}</option>
                <option value="released">{{ __('Released') }}</option>
            </select>
        </div>
        <p class="text-sm text-gray-500">{{ __('Add collateral from the loan page') }}</p>
    </div>

    @include('tablewire::table.table')
</div>

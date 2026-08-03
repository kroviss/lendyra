<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $product ? __('Edit product') : __('New product') }}</h1>
        <a href="{{ route('products.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ __('Back') }}</a>
    </div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Basics') }}</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-tablewire::inputs.text label="{{ __('Name') }}" wire:model.blur="name" required />
                <x-tablewire::inputs.text label="{{ __('Code') }}" wire:model.blur="code" required hint="{{ __('Unique, e.g. BIZ-12') }}" />
                <x-tablewire::inputs.text label="{{ __('Currency (ISO)') }}" wire:model.blur="currency" required />
                <x-tablewire::inputs.text label="{{ __('Decimal places') }}" type="number" min="0" max="6" wire:model.blur="scale" required
                    hint="{{ __('Minor units per whole unit, e.g. 2 for cents. Locked once loans exist.') }}" />
                <x-tablewire::inputs.toggle label="{{ __('Active') }}" wire:model="is_active" />
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Limits & fees') }}</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-tablewire::inputs.text label="{{ __('Minimum principal') }}" type="number" min="0" step="0.01" wire:model.blur="min_principal" hint="{{ __('Empty = no minimum') }}" />
                <x-tablewire::inputs.text label="{{ __('Maximum principal') }}" type="number" min="0" step="0.01" wire:model.blur="max_principal" hint="{{ __('Empty = no maximum') }}" />
                <x-tablewire::inputs.text label="{{ __('Processing fee %') }}" type="number" min="0" step="0.01" wire:model.blur="processing_fee_percent" required
                    hint="{{ __('Charged on disbursement, % of principal') }}" />
                <x-tablewire::inputs.text label="{{ __('Processing fee flat') }}" type="number" min="0" step="0.01" wire:model.blur="processing_fee_flat" hint="{{ __('Fixed amount added to the fee') }}" />
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Interest') }}</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-tablewire::inputs.select label="{{ __('Method') }}" wire:model.live="method" :options="$methodOptions" required />
                <x-tablewire::inputs.select label="{{ __('Repayment frequency') }}" wire:model="frequency" :options="$frequencyOptions" required />
                <x-tablewire::inputs.select label="{{ __('Accrual basis') }}" wire:model="basis" :options="$basisOptions" required
                    hint="{{ $method === 'annuity' ? __('Annuity always uses equal periods.') : '' }}" />
                <x-tablewire::inputs.text label="{{ __('Annual rate %') }}" type="number" step="0.01" wire:model.blur="annual_rate" required />
                <x-tablewire::inputs.text label="{{ __('Default term (installments)') }}" type="number" wire:model.blur="term_count" required />
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Penalty') }}</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-tablewire::inputs.text label="{{ __('Daily rate %') }}" type="number" step="0.01" wire:model.blur="penalty_daily_rate" required />
                <x-tablewire::inputs.text label="{{ __('Grace days') }}" type="number" wire:model.blur="penalty_grace_days" required />
                <x-tablewire::inputs.select label="{{ __('Base') }}" wire:model="penalty_base" :options="$penaltyBaseOptions" required />
                <x-tablewire::inputs.text label="{{ __('Cap % of base') }}" type="number" step="0.01" wire:model.blur="penalty_cap_percent" hint="{{ __('Empty = no cap') }}" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            @if ($product)
                <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this product?') }}"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-red-600">
                    {{ __('Delete') }}
                </button>
            @endif
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                <span wire:loading.remove>{{ __('Save') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </button>
        </div>
    </form>
</div>

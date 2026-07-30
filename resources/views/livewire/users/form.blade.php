<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $user ? __('Edit user') : __('New user') }}</h1>
        <a href="{{ route('users.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ __('Back') }}</a>
    </div>

    <form wire:submit="save" class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-tablewire::inputs.text label="{{ __('Name') }}" wire:model.blur="name" required />
            <x-tablewire::inputs.text label="{{ __('Email') }}" type="email" wire:model.blur="email" required />
            <x-tablewire::inputs.text label="{{ __('Password') }}" type="password" wire:model.blur="password"
                :required="$user === null" hint="{{ $user ? __('Leave empty to keep the current password') : __('Minimum 8 characters') }}" />
            <x-tablewire::inputs.select label="{{ __('Role') }}" wire:model="role" :options="$roleOptions" required />
            <x-tablewire::inputs.select label="{{ __('Branch') }}" wire:model="branch_id" :options="$branchOptions" placeholder="—" />
            <div class="flex items-end pb-1">
                <x-tablewire::inputs.toggle label="{{ __('Active') }}" wire:model="is_active" />
            </div>
        </div>

        <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-500">
            <p class="mb-1 font-medium text-gray-600">{{ __('Role permissions') }}:</p>
            <p>admin — {{ __('everything, incl. users') }} · manager — {{ __('approve, payoff, reverse, products') }} · loan_officer — {{ __('create borrowers & loans') }} · cashier — {{ __('record payments') }} · accountant — {{ __('view reports & ledger') }}</p>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                <span wire:loading.remove>{{ __('Save') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </button>
        </div>
    </form>
</div>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $borrower ? __('Edit borrower') : __('New borrower') }}</h1>
        <a href="{{ route('borrowers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ __('Back') }}</a>
    </div>

    <form wire:submit="save" class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-tablewire::inputs.text label="{{ __('First name') }}" wire:model.blur="first_name" required />
            <x-tablewire::inputs.text label="{{ __('Last name') }}" wire:model.blur="last_name" />
            <x-tablewire::inputs.text label="{{ __('Phone') }}" wire:model.blur="phone" />
            <x-tablewire::inputs.text label="{{ __('Email') }}" type="email" wire:model.blur="email" />
            <x-tablewire::inputs.text label="{{ __('ID number') }}" wire:model.blur="id_number" />
            <div class="md:col-span-2">
                <x-tablewire::inputs.textarea label="{{ __('Address') }}" wire:model="address" rows="2" />
            </div>
            <div class="md:col-span-2">
                <x-tablewire::inputs.textarea label="{{ __('Notes') }}" wire:model="notes" rows="3" />
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                <span wire:loading.remove>{{ __('Save') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </button>
        </div>
    </form>
</div>

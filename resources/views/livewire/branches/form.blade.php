<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $branch ? __('Edit branch') : __('New branch') }}</h1>
        <a href="{{ route('branches.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ __('Back') }}</a>
    </div>

    <form wire:submit="save" class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-tablewire::inputs.text label="{{ __('Name') }}" wire:model.blur="name" required />
            <x-tablewire::inputs.text label="{{ __('Code') }}" wire:model.blur="code" required hint="{{ __('Short unique code, e.g. HQ, BR1') }}" />
            <x-tablewire::inputs.text label="{{ __('Phone') }}" wire:model.blur="phone" />
            <div class="flex items-end pb-1">
                <x-tablewire::inputs.toggle label="{{ __('Active') }}" wire:model="is_active" />
            </div>
            <div class="md:col-span-2">
                <x-tablewire::inputs.textarea label="{{ __('Address') }}" wire:model="address" rows="2" />
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            @if ($branch)
                <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this branch?') }}"
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

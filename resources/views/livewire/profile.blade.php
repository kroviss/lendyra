<div>
    <h1 class="mb-6 text-2xl font-semibold">{{ __('My Profile') }}</h1>

    <form wire:submit="save" class="max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="space-y-4">
            <x-tablewire::inputs.text label="{{ __('Name') }}" wire:model.blur="name" required />
            <x-tablewire::inputs.text label="{{ __('Email') }}" :value="auth()->user()->email" disabled name="email_display" />
            <x-tablewire::inputs.text label="{{ __('Current password') }}" type="password" wire:model="current_password" required />
            <x-tablewire::inputs.text label="{{ __('New password') }}" type="password" wire:model="password" hint="{{ __('Leave empty to keep the current password') }}" />
            <x-tablewire::inputs.text label="{{ __('Confirm new password') }}" type="password" wire:model="password_confirmation" />
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                {{ __('Save') }}
            </button>
        </div>
    </form>
</div>

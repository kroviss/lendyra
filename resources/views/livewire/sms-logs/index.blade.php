<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('SMS Log') }}</h1>
            <select wire:model.live="kindFilter" class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm shadow-sm">
                <option value="">{{ __('All types') }}</option>
                <option value="upcoming">{{ __('Upcoming') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm shadow-sm">
                <option value="">{{ __('All statuses') }}</option>
                <option value="sent">{{ __('Sent') }}</option>
                <option value="failed">{{ __('Failed') }}</option>
            </select>
        </div>
        <p class="text-sm text-gray-500">{{ __('Reminders are sent daily at 09:00') }}</p>
    </div>

    @include('tablewire::table.table')
</div>

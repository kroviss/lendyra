<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('Loans') }}</h1>
            <select wire:model.live="statusFilter"
                class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                <option value="">{{ __('All statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
                <option value="pending_approval">{{ __('Pending approval') }}</option>
                <option value="approved">{{ __('Awaiting disbursement') }}</option>
                <option value="closed">{{ __('Closed') }}</option>
                <option value="written_off">{{ __('Written off') }}</option>
                <option value="rejected">{{ __('Rejected') }}</option>
            </select>
        </div>
        @can('create-loans')
            <a href="{{ route('loans.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                {{ __('New loan') }}
            </a>
        @endcan
    </div>

    @include('tablewire::table.table')
</div>

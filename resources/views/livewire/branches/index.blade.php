<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('Branches') }}</h1>
        <a href="{{ route('branches.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            {{ __('New branch') }}
        </a>
    </div>

    @include('tablewire::table.table')
</div>

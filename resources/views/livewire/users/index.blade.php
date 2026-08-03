<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ __('Users') }}</h1>
            <select wire:model.live="roleFilter" class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm shadow-sm">
                <option value="">{{ __('All roles') }}</option>
                @foreach (\App\Livewire\Users\Form::ROLES as $role)
                    <option value="{{ $role }}">{{ \App\Models\User::roleLabel($role) }}</option>
                @endforeach
            </select>
            <select wire:model.live="activeFilter" class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm shadow-sm">
                <option value="">{{ __('All') }}</option>
                <option value="1">{{ __('Active') }}</option>
                <option value="0">{{ __('Disabled') }}</option>
            </select>
        </div>
        <a href="{{ route('users.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            {{ __('New user') }}
        </a>
    </div>

    @include('tablewire::table.table')
</div>

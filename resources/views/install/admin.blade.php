@component('install.layout', ['step' => 3])
    <h2 class="mb-4 text-lg font-semibold">{{ __('Create admin account') }}</h2>

    @if ($errors->any())
        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif

    @if (session('install.storage_link_warning'))
        <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ session('install.storage_link_warning') }}</p>
    @endif

    <form method="POST" action="{{ route('install.admin.save') }}" class="space-y-4">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Name') }}</label>
            <input name="name" value="{{ old('name') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
            <input name="email" type="email" value="{{ old('email') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
            <input name="password" type="password" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Confirm password') }}</label>
            <input name="password_confirmation" type="password" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>

        <button type="submit" class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            {{ __('Finish installation') }} ✓
        </button>
    </form>
@endcomponent

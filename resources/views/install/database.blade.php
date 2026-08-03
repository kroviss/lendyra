@component('install.layout', ['step' => 2])
    <h2 class="mb-4 text-lg font-semibold">{{ __('Database connection') }}</h2>

    @if ($errors->any())
        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('install.database.save') }}" class="space-y-4">
        @csrf
        <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2">
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Host') }}</label>
                <input name="host" value="{{ old('host', '127.0.0.1') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Port') }}</label>
                <input name="port" value="{{ old('port', '3306') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Database name') }}</label>
            <input name="database" value="{{ old('database') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                <input name="username" value="{{ old('username') }}" required class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                <input name="password" type="password" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </div>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Table prefix (optional)') }}</label>
            <input name="prefix" value="{{ old('prefix') }}" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="lms_" />
        </div>
        <div>
            <label for="timezone" class="mb-1 block text-sm font-medium text-gray-700">{{ __('Timezone') }}</label>
            <select id="timezone" name="timezone" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                @foreach (DateTimeZone::listIdentifiers() as $tz)
                    <option value="{{ $tz }}" @selected(old('timezone', 'UTC') === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-400">{{ __('Due dates, penalty accrual and the daily schedule all run in this timezone.') }}</p>
        </div>

        <button type="submit" class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            {{ __('Test connection & migrate') }} →
        </button>
    </form>
@endcomponent

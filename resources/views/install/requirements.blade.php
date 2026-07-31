@component('install.layout', ['step' => 1])
    <h2 class="mb-4 text-lg font-semibold">{{ __('Server requirements') }}</h2>

    <ul class="mb-6 space-y-2">
        @foreach ($checks as $label => $passed)
            <li class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 text-sm">
                <span>{{ $label }}</span>
                @if ($passed)
                    <span class="font-medium text-green-600">✓</span>
                @else
                    <span class="font-medium text-red-600">✗</span>
                @endif
            </li>
        @endforeach
    </ul>

    @if (! empty($warnings))
        <ul class="mb-6 space-y-2">
            @foreach ($warnings as $warning)
                <li class="flex items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span>{{ $warning }}</span>
                    <span class="font-medium text-amber-600">!</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if (in_array(false, $checks, true))
        <p class="mb-4 text-sm text-red-600">{{ __('Fix the failed requirements above, then refresh this page.') }}</p>
        <a href="{{ route('install.requirements') }}" class="block w-full rounded-lg bg-gray-200 py-2 text-center text-sm font-medium text-gray-700">{{ __('Re-check') }}</a>
    @else
        <a href="{{ route('install.database') }}" class="block w-full rounded-lg bg-indigo-600 py-2 text-center text-sm font-medium text-white hover:bg-indigo-500">{{ __('Continue') }} →</a>
    @endif
@endcomponent

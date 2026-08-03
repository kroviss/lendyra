@php $switcherId = 'locale-switcher-'.uniqid(); @endphp
<form method="POST" action="{{ route('locale.switch') }}" {{ $attributes->merge(['class' => 'flex items-center gap-1.5']) }}>
    @csrf
    <svg class="size-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3 7.5 7.03 7.5 12s2.015 9 4.5 9Zm-8.716-6.747h17.432M3.284 9.747h17.432" />
    </svg>
    <label class="sr-only" for="{{ $switcherId }}">{{ __('Language') }}</label>
    <select
        id="{{ $switcherId }}"
        name="locale"
        onchange="this.form.submit()"
        class="rounded-lg border border-gray-200 bg-white py-1 pl-2 pr-7 text-xs font-medium text-gray-600 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
    >
        @foreach (\App\Http\Middleware\SetLocale::available() as $code => $label)
            <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
        @endforeach
    </select>
</form>

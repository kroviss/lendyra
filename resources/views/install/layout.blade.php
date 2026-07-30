<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Installation') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 font-sans antialiased">
    <div class="w-full max-w-lg">
        <div class="mb-6 flex items-center justify-center gap-2">
            <div class="flex size-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">L</div>
            <h1 class="text-xl font-semibold text-gray-900">{{ config('app.name') }} {{ __('Installer') }}</h1>
        </div>

        <div class="mb-6 flex items-center justify-center gap-2 text-xs font-medium">
            @foreach ([1 => __('Requirements'), 2 => __('Database'), 3 => __('Admin account')] as $n => $label)
                <span class="flex items-center gap-1.5 {{ $step >= $n ? 'text-indigo-600' : 'text-gray-400' }}">
                    <span class="flex size-5 items-center justify-center rounded-full {{ $step >= $n ? 'bg-indigo-600 text-white' : 'bg-gray-200' }}">{{ $n }}</span>
                    {{ $label }}
                </span>
                @if ($n < 3)<span class="h-px w-8 bg-gray-300"></span>@endif
            @endforeach
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
            {{ $slot }}
        </div>
    </div>
</body>
</html>

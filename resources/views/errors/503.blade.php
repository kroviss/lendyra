<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>503 — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 font-sans antialiased">
    <div class="text-center">
        <p class="text-6xl font-black text-gray-300">503</p>
        <p class="mt-3 text-lg font-medium text-gray-700">{{ __('We are down for maintenance.') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('Please check back in a few minutes.') }}</p>
        <a href="{{ url('/') }}" class="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">← {{ __('Back to dashboard') }}</a>
    </div>
</body>
</html>

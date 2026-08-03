<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Reset password') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 font-sans antialiased">
    <div class="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-2 text-xl font-semibold text-gray-900">{{ __('Forgot your password?') }}</h1>
        <p class="mb-6 text-sm text-gray-500">{{ __('Enter your email and we will send you a reset link.') }}</p>

        @if (session('status'))
            <p class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">{{ session('status') }}</p>
        @endif

        @if (config('mail.default') === 'log')
            <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">{{ __('Password reset requires a configured mail server. Ask your administrator to set the MAIL_* settings.') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40" />
                @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                {{ __('Send reset link') }}
            </button>

            <p class="text-center">
                <a href="{{ route('login') }}" class="text-sm text-gray-500 hover:text-indigo-600">← {{ __('Back to login') }}</a>
            </p>
        </form>
    </div>
</body>
</html>

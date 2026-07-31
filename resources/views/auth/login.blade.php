<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Log in') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 font-sans antialiased">
    <div class="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
        <div class="mb-6 flex items-center gap-2">
            <div class="flex size-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">L</div>
            <h1 class="text-xl font-semibold text-gray-900">{{ config('app.name') }}</h1>
        </div>

        @if (config('lms.demo'))
            <p class="mb-4 rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
                {{ __('Demo') }}: <span class="font-mono">admin@example.com</span> / <span class="font-mono">password</span>
            </p>
        @endif

        @if (session('status'))
            <p class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40" />
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                <input id="password" type="password" name="password" required
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40" />
            </div>

            <label for="remember" class="flex items-center gap-2 text-sm text-gray-700">
                <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))
                    class="size-4 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-2 focus:ring-indigo-500/40" />
                {{ __('Remember me') }}
            </label>

            @if ($errors->any())
                <p class="text-sm text-red-600">{{ $errors->first() }}</p>
            @endif

            <button type="submit" class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                {{ __('Log in') }}
            </button>

            <p class="text-center">
                <a href="{{ route('password.request') }}" class="text-sm text-gray-500 hover:text-indigo-600">{{ __('Forgot your password?') }}</a>
            </p>
        </form>
    </div>
</body>
</html>

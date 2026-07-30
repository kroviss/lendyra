<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 font-sans text-gray-900 antialiased">
    <div class="flex min-h-screen">
        {{-- Sidebar --}}
        <aside class="fixed inset-y-0 left-0 z-20 flex w-60 flex-col border-r border-gray-200 bg-white">
            <div class="flex h-16 items-center gap-2 border-b border-gray-100 px-5">
                <div class="flex size-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">L</div>
                <span class="text-lg font-semibold">{{ config('app.name') }}</span>
            </div>
            <nav class="flex-1 space-y-1 p-3">
                @foreach (array_filter([
                    ['route' => 'dashboard', 'label' => __('Dashboard')],
                    ['route' => 'borrowers.index', 'label' => __('Borrowers')],
                    ['route' => 'loans.index', 'label' => __('Loans')],
                    ['route' => 'reports.portfolio', 'label' => __('Reports')],
                    in_array(auth()->user()?->role, ['admin', 'manager'], true)
                        ? ['route' => 'products.index', 'label' => __('Loan Products')]
                        : null,
                ]) as $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="block rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($item['route'].'*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
                    >{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="border-t border-gray-100 p-3">
                <div class="flex items-center justify-between gap-2 px-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ auth()->user()?->name }}</p>
                        <p class="truncate text-xs text-gray-400">{{ auth()->user()?->role }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md p-2 text-gray-400 hover:bg-gray-50 hover:text-gray-600" title="{{ __('Log out') }}">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Main --}}
        <main class="ml-60 flex-1 p-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>

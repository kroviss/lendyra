<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 font-sans text-gray-900 antialiased" x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false">
    <div class="flex min-h-screen">
        {{-- Mobile top bar --}}
        <div class="fixed inset-x-0 top-0 z-30 flex h-14 items-center gap-3 border-b border-gray-200 bg-white px-4 lg:hidden">
            <button x-on:click="sidebarOpen = true" class="rounded-md p-2 text-gray-500 hover:bg-gray-50" aria-label="{{ __('Menu') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
            <span class="font-semibold">{{ config('app.name') }}</span>
        </div>

        {{-- Mobile overlay --}}
        <div x-show="sidebarOpen" x-cloak x-on:click="sidebarOpen = false" class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"></div>

        {{-- Sidebar --}}
        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform lg:z-20 lg:translate-x-0">
            <div class="flex h-16 items-center gap-2 border-b border-gray-100 px-5">
                <div class="flex size-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">L</div>
                <span class="text-lg font-semibold">{{ config('app.name') }}</span>
                <button x-on:click="sidebarOpen = false" class="ml-auto rounded-md p-1.5 text-gray-400 hover:bg-gray-50 lg:hidden" aria-label="{{ __('Close menu') }}">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <nav class="flex-1 space-y-1 p-3">
                @foreach (array_filter([
                    ['route' => 'dashboard', 'label' => __('Dashboard'), 'match' => 'dashboard'],
                    ['route' => 'borrowers.index', 'label' => __('Borrowers'), 'match' => 'borrowers.*'],
                    ['route' => 'loans.index', 'label' => __('Loans'), 'match' => 'loans.*'],
                    ['route' => 'payments.index', 'label' => __('Payments'), 'match' => 'payments.*'],
                    ['route' => 'collaterals.index', 'label' => __('Collateral'), 'match' => 'collaterals.*'],
                    ['route' => 'guarantors.index', 'label' => __('Guarantors'), 'match' => 'guarantors.*'],
                    ['route' => 'reports.collections', 'label' => __('Reports'), 'match' => 'reports.*'],
                    in_array(auth()->user()?->role, ['admin', 'manager'], true)
                        ? ['route' => 'products.index', 'label' => __('Loan Products'), 'match' => 'products.*']
                        : null,
                    in_array(auth()->user()?->role, ['admin', 'manager'], true)
                        ? ['route' => 'branches.index', 'label' => __('Branches'), 'match' => 'branches.*']
                        : null,
                    in_array(auth()->user()?->role, ['admin', 'manager'], true)
                        ? ['route' => 'sms-logs.index', 'label' => __('SMS Log'), 'match' => 'sms-logs.*']
                        : null,
                    auth()->user()?->role === 'admin'
                        ? ['route' => 'users.index', 'label' => __('Users'), 'match' => 'users.*']
                        : null,
                ]) as $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="block rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($item['match'] ?? $item['route'].'*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
                    >{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="border-t border-gray-100 p-3">
                <div class="flex items-center justify-between gap-2 px-2">
                    <a href="{{ route('profile') }}" class="min-w-0 rounded-md px-1 hover:bg-gray-50">
                        <p class="truncate text-sm font-medium">{{ auth()->user()?->name }}</p>
                        <p class="truncate text-xs text-gray-400">{{ \App\Models\User::roleLabel(auth()->user()?->role) }} · v{{ config('app.version') }}</p>
                    </a>
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
        <main class="flex-1 p-4 pt-20 lg:ml-60 lg:p-8 lg:pt-8">
            @if (config('lms.demo'))
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    {{ __('Demo mode — data resets nightly; account changes are disabled.') }}
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>

    {{-- Toasts --}}
    <div
        x-data="{ toasts: [] }"
        x-on:toast.window="const t = { id: Date.now() + Math.random(), msg: $event.detail.message ?? '' }; toasts.push(t); setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), 3500)"
        class="fixed bottom-6 right-6 z-50 space-y-2"
    >
        <template x-for="t in toasts" :key="t.id">
            <div class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm text-white shadow-lg" x-text="t.msg"></div>
        </template>
    </div>
    @if (session('status'))
        <div x-data x-init="$dispatch('toast', { message: @js(session('status')) })"></div>
    @endif
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} #{{ $payment->id }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-gray-100 p-8 font-sans text-gray-900 antialiased">
    <div class="mx-auto max-w-sm bg-white p-6 shadow-sm print:shadow-none">
        <div class="no-print mb-4 flex justify-end gap-2">
            <a href="{{ route('loans.show', $loan) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">← {{ __('Back') }}</a>
            <button onclick="window.print()" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white">{{ __('Print') }}</button>
        </div>

        @if ($payment->reversed_at)
            <div class="mb-4 rounded-lg border-2 border-red-500 py-2 text-center text-xl font-black uppercase tracking-widest text-red-600">
                {{ __('REVERSED — VOID') }}
            </div>
        @endif

        <div class="mb-4 border-b border-dashed border-gray-300 pb-4 text-center">
            <h1 class="text-lg font-bold">{{ config('app.name') }}</h1>
            <p class="text-xs text-gray-500">{{ __('Payment Receipt') }} #{{ $payment->id }}</p>
        </div>

        <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Date') }}</dt><dd>{{ $payment->paid_at->format('Y-m-d') }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Loan') }}</dt><dd class="font-medium">{{ $loan->loan_number }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Borrower') }}</dt><dd>{{ $loan->borrower->fullName() }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Method') }}</dt><dd>{{ ucfirst($payment->method) }}</dd></div>
            @if ($payment->reference)
                <div class="flex justify-between"><dt class="text-gray-500">{{ __('Reference') }}</dt><dd>{{ $payment->reference }}</dd></div>
            @endif
        </dl>

        <div class="my-4 border-t border-dashed border-gray-300 pt-3">
            <p class="mb-1 text-xs font-medium uppercase text-gray-400">{{ __('Allocation') }}</p>
            @foreach ($payment->allocations as $allocation)
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">#{{ $allocation->installment?->number }} {{ __(ucfirst($allocation->component->value)) }}</span>
                    <span>{{ \LoanEngine\Money::minor((int) $allocation->amount_minor, $loan->currency, (int) $loan->scale)->formatted() }}</span>
                </div>
            @endforeach
            @if ((int) $payment->unallocated_minor > 0)
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">{{ __('Overpayment (credit)') }}</span>
                    <span>{{ \LoanEngine\Money::minor((int) $payment->unallocated_minor, $loan->currency, (int) $loan->scale)->formatted() }}</span>
                </div>
            @endif
        </div>

        <div class="flex justify-between border-t-2 border-gray-800 pt-2 text-base font-bold">
            <span>{{ __('Total') }}</span>
            <span>{{ $payment->amount()->formatted() }} {{ $loan->currency }}</span>
        </div>

        <p class="mt-4 text-center text-xs text-gray-400">
            {{ __('Received by') }}: {{ $payment->receivedBy?->name ?? '—' }} · {{ $payment->created_at->format('Y-m-d H:i') }}
        </p>
    </div>
</body>
</html>

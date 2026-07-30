<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Statement') }} — {{ $loan->loan_number }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-gray-100 p-8 font-sans text-gray-900 antialiased">
    <div class="mx-auto max-w-3xl bg-white p-8 shadow-sm print:shadow-none">
        <div class="no-print mb-6 flex justify-end gap-3">
            <a href="{{ route('loans.show', $loan) }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">← {{ __('Back') }}</a>
            <button onclick="window.print()" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Print') }}</button>
        </div>

        <div class="mb-8 flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold">{{ config('app.name') }}</h1>
                <p class="text-sm text-gray-500">{{ __('Loan Statement') }}</p>
            </div>
            <div class="text-right text-sm">
                <p class="font-semibold">{{ $loan->loan_number }}</p>
                <p class="text-gray-500">{{ __('Generated') }}: {{ now()->format('Y-m-d') }}</p>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-2 gap-6 text-sm">
            <div>
                <h2 class="mb-2 font-semibold text-gray-500">{{ __('Borrower') }}</h2>
                <p class="font-medium">{{ $loan->borrower->fullName() }}</p>
                <p>{{ $loan->borrower->phone }}</p>
                <p>{{ $loan->borrower->id_number }}</p>
            </div>
            <div>
                <h2 class="mb-2 font-semibold text-gray-500">{{ __('Loan terms') }}</h2>
                <p>{{ __('Principal') }}: <span class="font-medium">{{ $loan->principal()->toDecimalString() }} {{ $loan->currency }}</span></p>
                <p>{{ __('Rate') }}: {{ $loan->annual_rate }}% / {{ __('yr') }} ({{ str_replace('_', ' ', $loan->method->value) }})</p>
                <p>{{ __('Term') }}: {{ $loan->term_count }} × {{ $loan->frequency->value }}</p>
                <p>{{ __('Disbursed') }}: {{ $loan->disbursed_at?->format('Y-m-d') }}</p>
                <p>{{ __('Status') }}: {{ str_replace('_', ' ', $loan->status->value) }}</p>
            </div>
        </div>

        <h2 class="mb-2 text-base font-semibold">{{ __('Repayment schedule') }}</h2>
        <table class="mb-8 w-full text-sm">
            <thead class="border-b-2 border-gray-300 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="py-2">#</th>
                    <th class="py-2">{{ __('Due date') }}</th>
                    <th class="py-2 text-right">{{ __('Principal') }}</th>
                    <th class="py-2 text-right">{{ __('Interest') }}</th>
                    <th class="py-2 text-right">{{ __('Penalty') }}</th>
                    <th class="py-2 text-right">{{ __('Paid') }}</th>
                    <th class="py-2 text-right">{{ __('Balance due') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($loan->installments as $installment)
                    @php $money = fn (int $minor) => \LoanEngine\Money::minor($minor, $loan->currency, (int) $loan->scale)->toDecimalString(); @endphp
                    <tr class="border-b border-gray-100">
                        <td class="py-1.5">{{ $installment->number }}</td>
                        <td class="py-1.5">{{ $installment->due_date->format('Y-m-d') }}</td>
                        <td class="py-1.5 text-right">{{ $money((int) $installment->principal_minor) }}</td>
                        <td class="py-1.5 text-right">{{ $money((int) $installment->interest_minor) }}</td>
                        <td class="py-1.5 text-right">{{ $money((int) $installment->penalty_minor) }}</td>
                        <td class="py-1.5 text-right">{{ $money((int) $installment->principal_paid_minor + (int) $installment->interest_paid_minor + (int) $installment->penalty_paid_minor) }}</td>
                        <td class="py-1.5 text-right font-medium">{{ $installment->toDue()->totalDue()->toDecimalString() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($loan->payments->isNotEmpty())
            <h2 class="mb-2 text-base font-semibold">{{ __('Payments received') }}</h2>
            <table class="w-full text-sm">
                <thead class="border-b-2 border-gray-300 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2">{{ __('Date') }}</th>
                        <th class="py-2">{{ __('Method') }}</th>
                        <th class="py-2">{{ __('Reference') }}</th>
                        <th class="py-2 text-right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loan->payments->whereNull('reversed_at')->sortBy('paid_at') as $payment)
                        <tr class="border-b border-gray-100">
                            <td class="py-1.5">{{ $payment->paid_at->format('Y-m-d') }}</td>
                            <td class="py-1.5">{{ $payment->method }}</td>
                            <td class="py-1.5">{{ $payment->reference }}</td>
                            <td class="py-1.5 text-right font-medium">{{ $payment->amount()->toDecimalString() }} {{ $loan->currency }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>

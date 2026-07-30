<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ $loan->loan_number }}</h1>
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ match ($loan->status->value) {
                    'active' => 'bg-green-100 text-green-700',
                    'approved' => 'bg-blue-100 text-blue-700',
                    'closed' => 'bg-gray-100 text-gray-600',
                    'written_off' => 'bg-red-100 text-red-700',
                    default => 'bg-yellow-100 text-yellow-700',
                } }}">{{ str_replace('_', ' ', $loan->status->value) }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                {{ $loan->borrower->fullName() }} · {{ $loan->product->name }} ·
                {{ $loan->principal()->toDecimalString() }} {{ $loan->currency }} ·
                {{ $loan->annual_rate }}%/{{ __('yr') }} · {{ $loan->term_count }} {{ $loan->frequency->value }}
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('loans.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">← {{ __('Back') }}</a>
            <a href="{{ route('loans.statement', $loan) }}" target="_blank" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Statement') }}</a>

            @if ($loan->status === \App\Enums\LoanStatus::Approved)
                <button wire:click="activate" wire:loading.attr="disabled"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50">
                    {{ __('Disburse & activate') }}
                </button>
            @endif

            @if ($loan->status === \App\Enums\LoanStatus::Active)
                <button wire:click="accruePenalties" wire:loading.attr="disabled"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    {{ __('Update penalties') }}
                </button>
                <button wire:click="$set('showPaymentModal', true)"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    {{ __('Record payment') }}
                </button>
                <button wire:click="$set('showPayoffModal', true)"
                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                    {{ __('Payoff') }}
                </button>
            @endif
        </div>
    </div>

    @if ($actionError)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $actionError }}
        </div>
    @endif

    @if ($loan->status === \App\Enums\LoanStatus::Active || $loan->status === \App\Enums\LoanStatus::Closed)
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ __('Principal outstanding') }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $loan->principalOutstanding()->toDecimalString() }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ __('Overdue installments') }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $loan->installments->filter(fn ($i) => ! $i->isSettled() && $i->due_date->isPast())->count() }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ __('Paid installments') }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $loan->installments->whereNotNull('settled_at')->count() }} / {{ $loan->installments->count() }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ __('Payments received') }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $loan->payments->whereNull('reversed_at')->count() }}</p>
            </div>
        </div>
    @endif

    {{-- Installments --}}
    <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">#</th>
                    <th class="px-4 py-3 text-left">{{ __('Due date') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Principal') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Interest') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Penalty') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Due') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($loan->installments as $installment)
                    <tr class="border-t border-gray-100 {{ ! $installment->isSettled() && $installment->due_date->isPast() ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-2">{{ $installment->number }}</td>
                        <td class="px-4 py-2">{{ $installment->due_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-2 text-right">{{ \LoanEngine\Money::minor((int) $installment->principal_minor, $loan->currency, (int) $loan->scale)->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right">{{ \LoanEngine\Money::minor((int) $installment->interest_minor, $loan->currency, (int) $loan->scale)->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right">{{ \LoanEngine\Money::minor((int) $installment->penalty_minor, $loan->currency, (int) $loan->scale)->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-right font-medium">{{ $installment->toDue()->totalDue()->toDecimalString() }}</td>
                        <td class="px-4 py-2 text-center">
                            @if ($installment->isSettled())
                                <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">{{ __('Paid') }}</span>
                            @elseif ($installment->due_date->isPast())
                                <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('Overdue') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ __('Upcoming') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">{{ __('No schedule yet — activate the loan to generate it.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Payments --}}
    @if ($loan->payments->isNotEmpty())
        <h2 class="mb-3 text-base font-semibold">{{ __('Payments') }}</h2>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('Method') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('Reference') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('Allocation') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loan->payments->sortByDesc('paid_at') as $payment)
                        <tr class="border-t border-gray-100">
                            <td class="px-4 py-2">{{ $payment->paid_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-2 text-right font-medium">{{ $payment->amount()->toDecimalString() }}</td>
                            <td class="px-4 py-2">{{ $payment->method }}</td>
                            <td class="px-4 py-2">{{ $payment->reference }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">
                                @foreach ($payment->allocations as $allocation)
                                    <span class="mr-2">#{{ $allocation->installment?->number }} {{ $allocation->component->value }}: {{ \LoanEngine\Money::minor((int) $allocation->amount_minor, $loan->currency, (int) $loan->scale)->toDecimalString() }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Collaterals & guarantors --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ __('Collateral') }}</h2>
                <button wire:click="$set('showCollateralModal', true)" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">+ {{ __('Add') }}</button>
            </div>
            @forelse ($loan->collaterals as $collateral)
                <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm">
                    <div>
                        <p class="font-medium">{{ $collateral->type }}
                            @if ($collateral->status === 'released')
                                <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ __('Released') }} {{ $collateral->released_at?->format('Y-m-d') }}</span>
                            @endif
                        </p>
                        <p class="text-gray-500">{{ $collateral->description }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium">{{ \LoanEngine\Money::minor((int) $collateral->estimated_value_minor, $loan->currency, (int) $loan->scale)->toDecimalString() }}</span>
                        @if ($collateral->status === 'held')
                            <button wire:click="releaseCollateral({{ $collateral->id }})" wire:confirm="{{ __('Release this collateral?') }}" class="text-xs text-gray-400 hover:text-gray-600">{{ __('Release') }}</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-400">{{ __('No collateral registered.') }}</p>
            @endforelse
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ __('Guarantors') }}</h2>
                <button wire:click="$set('showGuarantorModal', true)" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">+ {{ __('Add') }}</button>
            </div>
            @forelse ($loan->guarantors as $guarantor)
                <div class="flex items-center justify-between border-t border-gray-100 py-2 text-sm">
                    <div>
                        <p class="font-medium">{{ $guarantor->name }}</p>
                        <p class="text-gray-500">{{ $guarantor->phone }} {{ $guarantor->id_number ? '· '.$guarantor->id_number : '' }}</p>
                    </div>
                    <button wire:click="removeGuarantor({{ $guarantor->id }})" wire:confirm="{{ __('Remove this guarantor?') }}" class="text-xs text-gray-400 hover:text-red-600">{{ __('Remove') }}</button>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-400">{{ __('No guarantors.') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Collateral modal --}}
    @if ($showCollateralModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/50 p-4" wire:click.self="$set('showCollateralModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">{{ __('Add collateral') }}</h3>
                <div class="space-y-4">
                    <x-tablewire::inputs.text label="{{ __('Type') }}" wire:model="collateralType" required hint="{{ __('e.g. Vehicle, Land title, Equipment') }}" />
                    <x-tablewire::inputs.money label="{{ __('Estimated value') }}" wire:model="collateralValue" required />
                    <x-tablewire::inputs.textarea label="{{ __('Description') }}" wire:model="collateralDescription" rows="2" />
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showCollateralModal', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button wire:click="addCollateral" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">{{ __('Add') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Guarantor modal --}}
    @if ($showGuarantorModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/50 p-4" wire:click.self="$set('showGuarantorModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">{{ __('Add guarantor') }}</h3>
                <div class="space-y-4">
                    <x-tablewire::inputs.text label="{{ __('Name') }}" wire:model="guarantorName" required />
                    <x-tablewire::inputs.text label="{{ __('Phone') }}" wire:model="guarantorPhone" />
                    <x-tablewire::inputs.text label="{{ __('ID number') }}" wire:model="guarantorIdNumber" />
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showGuarantorModal', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button wire:click="addGuarantor" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">{{ __('Add') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Record payment modal --}}
    @if ($showPaymentModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/50 p-4" wire:click.self="$set('showPaymentModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">{{ __('Record payment') }}</h3>
                <div class="space-y-4">
                    <x-tablewire::inputs.money label="{{ __('Amount') }}" wire:model="paymentAmount" required />
                    <x-tablewire::inputs.text label="{{ __('Date') }}" type="date" wire:model="paymentDate" required />
                    <x-tablewire::inputs.select label="{{ __('Method') }}" wire:model="paymentMethod"
                        :options="['cash' => __('Cash'), 'bank' => __('Bank transfer'), 'mobile' => __('Mobile money')]" />
                    <x-tablewire::inputs.text label="{{ __('Reference') }}" wire:model="paymentReference" />
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showPaymentModal', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button wire:click="recordPayment" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        {{ __('Save payment') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Payoff modal --}}
    @if ($showPayoffModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/50 p-4" wire:click.self="$set('showPayoffModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">{{ __('Early payoff') }}</h3>

                <x-tablewire::inputs.text label="{{ __('Payoff date') }}" type="date" wire:model.live="payoffDate" required />

                @if ($quote = $this->payoffQuote)
                    <dl class="mt-4 space-y-2 rounded-lg bg-gray-50 p-4 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Principal outstanding') }}</dt><dd class="font-medium">{{ $quote['principal'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Past-due interest') }}</dt><dd class="font-medium">{{ $quote['pastDueInterest'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Accrued interest') }}</dt><dd class="font-medium">{{ $quote['accruedInterest'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Penalties') }}</dt><dd class="font-medium">{{ $quote['penalty'] }}</dd></div>
                        <div class="flex justify-between border-t border-gray-200 pt-2 text-base"><dt class="font-semibold">{{ __('Total') }}</dt><dd class="font-bold">{{ $quote['total'] }} {{ $loan->currency }}</dd></div>
                    </dl>
                @endif

                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showPayoffModal', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button wire:click="settlePayoff" wire:loading.attr="disabled" wire:confirm="{{ __('Settle this loan in full? This cannot be undone.') }}"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        {{ __('Confirm payoff') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

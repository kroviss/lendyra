<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('New loan') }}</h1>
        <a href="{{ route('loans.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ __('Back') }}</a>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <form wire:submit="save" class="space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <x-tablewire::inputs.searchable-select
                            label="{{ __('Borrower') }}"
                            wire:model="borrower_id"
                            :options="$borrowerOptions"
                            placeholder="{{ __('Select borrower...') }}"
                            required
                        />
                    </div>
                    <div class="md:col-span-2">
                        <x-tablewire::inputs.select
                            label="{{ __('Product') }}"
                            wire:model.live="loan_product_id"
                            :options="$productOptions"
                            placeholder="{{ __('Select product...') }}"
                            required
                        />
                    </div>
                    <x-tablewire::inputs.money label="{{ __('Amount') }}" wire:model.live.debounce.600ms="amount" required />
                    <x-tablewire::inputs.text label="{{ __('Annual rate %') }}" type="number" step="0.01" wire:model.live.debounce.600ms="annual_rate" required />
                    <x-tablewire::inputs.text label="{{ __('Term (installments)') }}" type="number" wire:model.live.debounce.600ms="term_count" required />
                    <x-tablewire::inputs.text label="{{ __('Disbursement date') }}" type="date" wire:model.live="disbursed_at" required />
                    <x-tablewire::inputs.text label="{{ __('First due date') }}" type="date" wire:model.live="first_due_date" hint="{{ __('Optional — defaults to one period after disbursement') }}" />
                </div>
                <div class="mt-4">
                    <x-tablewire::inputs.textarea label="{{ __('Purpose') }}" wire:model="purpose" rows="2" />
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                    <span wire:loading.remove>{{ __('Create loan') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </button>
            </div>
        </form>

        {{-- Live schedule preview --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Schedule preview') }}</h2>

            @if ($schedule = $this->preview)
                @if ($fee = $this->feePreview)
                    <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Processing fee (collected at disbursement)') }}: <span class="font-semibold">{{ $fee }}</span></p>
                @endif
                <div class="mb-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-gray-500">{{ __('Total interest') }}</p>
                        <p class="text-lg font-semibold">{{ $schedule->totalInterest()->toDecimalString() }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-gray-500">{{ __('Total payable') }}</p>
                        <p class="text-lg font-semibold">{{ $schedule->totalPayable()->toDecimalString() }}</p>
                    </div>
                </div>

                <div class="max-h-96 overflow-y-auto overflow-x-auto rounded-lg border border-gray-100">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left">#</th>
                                <th class="px-3 py-2 text-left">{{ __('Due date') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Principal') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Interest') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedule->installments as $installment)
                                <tr class="border-t border-gray-100">
                                    <td class="px-3 py-1.5">{{ $installment->number }}</td>
                                    <td class="px-3 py-1.5">{{ $installment->dueDate->format('Y-m-d') }}</td>
                                    <td class="px-3 py-1.5 text-right">{{ $installment->principal->toDecimalString() }}</td>
                                    <td class="px-3 py-1.5 text-right">{{ $installment->interest->toDecimalString() }}</td>
                                    <td class="px-3 py-1.5 text-right font-medium">{{ $installment->total()->toDecimalString() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="py-12 text-center text-sm text-gray-400">
                    {{ __('Fill in the loan details to preview the repayment schedule.') }}
                </p>
            @endif
        </div>
    </div>
</div>

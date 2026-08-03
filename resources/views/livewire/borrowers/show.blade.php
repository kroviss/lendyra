<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-4">
            @if ($borrower->photo_path)
                <img src="{{ url('media/'.$borrower->photo_path) }}" class="size-14 rounded-full object-cover ring-1 ring-gray-200" alt="" />
            @else
                <div class="flex size-14 items-center justify-center rounded-full bg-indigo-100 text-lg font-semibold text-indigo-600">
                    {{ mb_substr($borrower->first_name, 0, 1) }}{{ mb_substr($borrower->last_name ?? '', 0, 1) }}
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-semibold">{{ $borrower->fullName() }}</h1>
                <p class="text-sm text-gray-500">
                    {{ $borrower->phone }} {{ $borrower->id_number ? '· '.$borrower->id_number : '' }}
                    {{ $borrower->email ? '· '.$borrower->email : '' }}
                </p>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('borrowers.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">← {{ __('Back') }}</a>
            @can('create-loans')
                <a href="{{ route('borrowers.edit', $borrower->id) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Edit') }}</a>
                @if ($canDelete)
                    <button wire:click="deleteBorrower" wire:confirm="{{ __('Delete this borrower?') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-red-600">{{ __('Delete') }}</button>
                @endif
                <a href="{{ route('loans.create', ['borrower' => $borrower->id]) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('New loan') }}</a>
            @endcan
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Total loans') }}</p>
            <p class="mt-1 text-xl font-semibold">{{ $borrower->loans->count() }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Active loans') }}</p>
            <p class="mt-1 text-xl font-semibold">{{ $activeCount }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Outstanding') }}</p>
            <p class="mt-1 text-xl font-semibold">{{ $outstandingLabel }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Loans in arrears') }}</p>
            <p class="mt-1 text-xl font-semibold {{ $overdueCount > 0 ? 'text-red-600' : '' }}">{{ $overdueCount }}</p>
        </div>
    </div>

    @if ($borrower->address || $borrower->notes)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
            @if ($borrower->address)<p><span class="text-gray-500">{{ __('Address') }}:</span> {{ $borrower->address }}</p>@endif
            @if ($borrower->notes)<p class="mt-1"><span class="text-gray-500">{{ __('Notes') }}:</span> {{ $borrower->notes }}</p>@endif
        </div>
    @endif

    <h2 class="mb-3 text-base font-semibold">{{ __('Loans') }}</h2>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('Loan #') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Product') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Principal') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Outstanding') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('Disbursed') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($borrower->loans->sortByDesc('id') as $loan)
                    <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50"
                        x-on:click="window.location = @js(route('loans.show', $loan))">
                        <td class="px-4 py-2 font-medium">{{ $loan->loan_number }}</td>
                        <td class="px-4 py-2">{{ $loan->product?->name }}</td>
                        <td class="px-4 py-2 text-right">{{ $loan->principal()->formatted() }} {{ $loan->currency }}</td>
                        <td class="px-4 py-2 text-right">{{ $loan->principalOutstanding()->formatted() }}</td>
                        <td class="px-4 py-2">{{ $loan->disbursed_at?->format('Y-m-d') }}</td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $loan->status->badgeClass() }}">{{ $loan->status->label() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ __('No loans yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div>
    {{-- Toolbar --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.34-4.34M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
            </svg>
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Search...') }}"
                class="w-64 rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
            />
        </div>

        <div class="flex items-center gap-3">
            @if (count($this->bulkActions()) && count($selected))
                <div class="flex items-center gap-2 rounded-lg bg-indigo-50 px-3 py-1.5">
                    <span class="text-sm font-medium text-indigo-700">{{ count($selected) }} {{ __('selected') }}</span>
                    @foreach ($this->bulkActions() as $method => $label)
                        <button
                            type="button"
                            wire:click="{{ $method }}"
                            wire:loading.attr="disabled"
                            class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >{{ $label }}</button>
                    @endforeach
                    <button type="button" wire:click="clearSelection" class="text-xs text-indigo-500 hover:text-indigo-700">{{ __('Clear') }}</button>
                </div>
            @endif

            @if ($this->exportAllowed)
            <button
                type="button"
                wire:click="exportCsv"
                wire:target="exportCsv"
                wire:loading.attr="disabled"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
            >{{ __('Export CSV') }}</button>
            @endif

            <select
                wire:model.live="perPage"
                class="rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-8 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
            >
                @foreach (config('tablewire.per_page_options', [10, 25, 50, 100]) as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="relative overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div wire:loading.delay wire:target="search, sort, perPage, sortBy, gotoPage, previousPage, nextPage" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60">
            <svg class="size-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
        </div>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    @if (count($this->bulkActions()))
                        <th class="w-10 px-4 py-3">
                            <input
                                type="checkbox"
                                wire:model.live="selectPage"
                                class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500/40"
                            />
                        </th>
                    @endif

                    @foreach ($columns as $column)
                        <th class="px-4 py-3 {{ $column->align === 'right' ? 'text-right' : ($column->align === 'center' ? 'text-center' : '') }}">
                            @if ($column->sortable)
                                <button
                                    type="button"
                                    wire:click="sortBy('{{ $column->sortField() }}')"
                                    class="inline-flex items-center gap-1 uppercase hover:text-gray-700"
                                >
                                    {{ $column->label }}
                                    @if ($sort === $column->sortField())
                                        <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10 5l5 6H5l5-6z"/></svg>
                                    @elseif ($sort === '-'.$column->sortField())
                                        <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10 15l-5-6h10l-5 6z"/></svg>
                                    @else
                                        <svg class="size-3 text-gray-300" fill="currentColor" viewBox="0 0 20 20"><path d="M10 3l4 5H6l4-5zM10 17l-4-5h8l-4 5z"/></svg>
                                    @endif
                                </button>
                            @else
                                {{ $column->label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @forelse ($this->rows as $row)
                    <tr
                        wire:key="tk-row-{{ $row->getKey() }}"
                        @if ($url = $this->rowUrl($row))
                            x-on:click="if (!$event.target.closest('a, button, input, select, textarea')) window.location = '{{ $url }}'"
                        @endif
                        class="border-b border-gray-100 last:border-0 hover:bg-gray-50 {{ $this->rowUrl($row) ? 'cursor-pointer' : '' }}"
                    >
                        @if (count($this->bulkActions()))
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="selected"
                                    value="{{ $row->getKey() }}"
                                    class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500/40"
                                />
                            </td>
                        @endif

                        @foreach ($columns as $column)
                            <td class="px-4 py-3 text-gray-700 {{ $column->align === 'right' ? 'text-right' : ($column->align === 'center' ? 'text-center' : '') }}">
                                @if ($column->html)
                                    {!! $column->render($row) !!}
                                @else
                                    {{ $column->render($row) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + (count($this->bulkActions()) ? 1 : 0) }}" class="px-4 py-14 text-center">
                            <p class="text-sm text-gray-400">{{ __('No records found') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->rows->hasPages())
        <div class="mt-4">
            {{ $this->rows->links() }}
        </div>
    @endif
</div>

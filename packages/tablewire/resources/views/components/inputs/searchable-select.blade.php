@props([
    'label' => null,
    'name' => null,
    'options' => [],
    'placeholder' => null,
    'searchPlaceholder' => null,
    'clearable' => true,
    'hint' => null,
    'required' => false,
])

@php
    $placeholder ??= __('Select...');
    $searchPlaceholder ??= __('Search...');
    $model = $attributes->whereStartsWith('wire:model')->first();
    $modelName = $attributes->wire('model')->value();
    $name ??= $modelName ?? \Illuminate\Support\Str::random(8);
    $errorKey = $modelName ?? $name;

    $normalized = collect($options)->map(function ($option, $key) {
        if (is_array($option)) {
            return [
                'value' => $option['value'] ?? $option['id'] ?? null,
                'label' => (string) ($option['label'] ?? $option['name'] ?? ''),
            ];
        }

        return ['value' => $key, 'label' => (string) $option];
    })->values();
@endphp

<div>
    @if ($label)
        <label class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif

    <div
        x-data="{
            open: false,
            search: '',
            value: @if ($modelName) $wire.entangle('{{ $modelName }}') @else null @endif,
            options: {{ \Illuminate\Support\Js::from($normalized) }},
            get filtered() {
                const term = this.search.toLowerCase().trim();
                return term ? this.options.filter(o => o.label.toLowerCase().includes(term)) : this.options;
            },
            get selectedLabel() {
                const match = this.options.find(o => String(o.value) === String(this.value ?? ''));
                return match ? match.label : null;
            },
            choose(option) {
                this.value = option.value;
                this.open = false;
                this.search = '';
            },
            toggle() {
                this.open = !this.open;
                if (this.open) this.$nextTick(() => this.$refs.search.focus());
            },
        }"
        x-on:keydown.escape.stop="open = false"
        class="relative"
    >
        <button
            type="button"
            x-on:click="toggle()"
            class="flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 {{ $errors->has($errorKey) ? 'border-red-400' : 'border-gray-300' }}"
        >
            <span x-text="selectedLabel ?? {{ \Illuminate\Support\Js::from($placeholder) }}" :class="selectedLabel ? 'text-gray-900' : 'text-gray-400'"></span>
            <span class="flex items-center gap-1">
                @if ($clearable)
                    <svg
                        x-show="value !== null && value !== ''"
                        x-on:click.stop="value = null"
                        class="size-4 cursor-pointer text-gray-400 hover:text-gray-600"
                        fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                    ><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                @endif
                <svg class="size-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </span>
        </button>

        <div
            x-show="open"
            x-transition.opacity.duration.100ms
            x-on:click.outside="open = false"
            x-cloak
            class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg"
        >
            <div class="border-b border-gray-100 p-2">
                <input
                    x-ref="search"
                    x-model="search"
                    type="text"
                    placeholder="{{ $searchPlaceholder }}"
                    class="block w-full rounded-md border border-gray-200 px-2.5 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/40"
                />
            </div>
            <ul class="max-h-60 overflow-y-auto py-1">
                <template x-for="option in filtered" :key="option.value">
                    <li>
                        <button
                            type="button"
                            x-on:click="choose(option)"
                            class="w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
                            :class="String(option.value) === String(value ?? '') && 'bg-indigo-50 font-medium text-indigo-600'"
                            x-text="option.label"
                        ></button>
                    </li>
                </template>
                <li x-show="!filtered.length" class="px-3 py-2 text-sm text-gray-400">{{ __('No results') }}</li>
            </ul>
        </div>
    </div>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif

    @error($errorKey)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

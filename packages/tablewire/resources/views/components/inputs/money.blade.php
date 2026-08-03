@props([
    'label' => null,
    'name' => null,
    'symbol' => null,
    'decimals' => 2,
    'hint' => null,
    'required' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $name ??= $model ?? \Illuminate\Support\Str::random(8);
    $errorKey = $model ?? $name;
    $symbol ??= config('tablewire.currency_symbol', '');
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif

    <div
        x-data="{
            raw: @if ($model) $wire.entangle('{{ $model }}') @else null @endif,
            display: '',
            format(value) {
                if (value === null || value === '' || isNaN(Number(value))) return '';
                return Number(value).toLocaleString('en-US', { maximumFractionDigits: {{ (int) $decimals }} });
            },
            sync() {
                {{-- Money is never negative in any form here — reversals
                     and write-offs have their own flows. --}}
                const cleaned = this.display.replace(/[^0-9.]/g, '');
                this.raw = cleaned === '' ? null : Number(cleaned);
            },
            init() {
                this.display = this.format(this.raw);
                this.$watch('raw', value => {
                    if (document.activeElement !== this.$refs.input) this.display = this.format(value);
                });
            },
        }"
        class="relative"
    >
        <input
            x-ref="input"
            x-model="display"
            x-on:input="sync()"
            x-on:blur="display = format(raw)"
            type="text"
            inputmode="decimal"
            id="{{ $name }}"
            name="{{ $name }}"
            {{ $attributes->whereDoesntStartWith('wire:model')->merge([
                'class' => 'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 disabled:cursor-not-allowed disabled:bg-gray-50 '
                    .($symbol !== '' ? 'pr-10 ' : '')
                    .($errors->has($errorKey) ? 'border-red-400' : 'border-gray-300'),
            ]) }}
        />

        @if ($symbol !== '')
            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">{{ $symbol }}</span>
        @endif
    </div>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif

    @error($errorKey)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

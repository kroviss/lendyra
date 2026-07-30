@props([
    'label' => null,
    'name' => null,
    'hint' => null,
])

@php
    $model = $attributes->wire('model')->value();
    $name ??= $model ?? \Illuminate\Support\Str::random(8);
    $errorKey = $model ?? $name;
@endphp

<div>
    <label class="inline-flex cursor-pointer items-start gap-2.5">
        <input
            type="checkbox"
            id="{{ $name }}"
            name="{{ $name }}"
            {{ $attributes->merge([
                'class' => 'mt-0.5 size-4 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500/40',
            ]) }}
        />
        <span>
            @if ($label)
                <span class="block text-sm font-medium text-gray-700">{{ $label }}</span>
            @endif
            @if ($hint)
                <span class="block text-xs text-gray-400">{{ $hint }}</span>
            @endif
        </span>
    </label>

    @error($errorKey)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

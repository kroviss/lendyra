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
    <label class="inline-flex cursor-pointer items-center gap-3">
        <input
            type="checkbox"
            id="{{ $name }}"
            name="{{ $name }}"
            {{ $attributes->merge(['class' => 'peer sr-only']) }}
        />
        <span class="relative h-6 w-11 shrink-0 rounded-full bg-gray-200 transition peer-checked:bg-indigo-600 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500/40 after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"></span>
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

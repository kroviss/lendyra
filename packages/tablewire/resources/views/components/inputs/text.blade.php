@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'hint' => null,
    'required' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $name ??= $model ?? \Illuminate\Support\Str::random(8);
    $errorKey = $model ?? $name;
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        id="{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 disabled:cursor-not-allowed disabled:bg-gray-50 '
                .($errors->has($errorKey) ? 'border-red-400' : 'border-gray-300'),
        ]) }}
    />

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif

    @error($errorKey)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

@props([
    'label' => null,
    'name' => null,
    'options' => [],
    'placeholder' => null,
    'hint' => null,
    'required' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $name ??= $model ?? \Illuminate\Support\Str::random(8);
    $errorKey = $model ?? $name;

    $normalized = collect($options)->map(function ($option, $key) {
        // Eloquent models (including aliased selects like `id as value`)
        // and other objects must normalize like arrays — falling through
        // to the scalar branch would render the model's JSON as the label
        // and the collection index as the value.
        if ($option instanceof \Illuminate\Contracts\Support\Arrayable) {
            $option = $option->toArray();
        } elseif (is_object($option)) {
            $option = (array) $option;
        }

        if (is_array($option)) {
            return [
                'value' => $option['value'] ?? $option['id'] ?? null,
                'label' => $option['label'] ?? $option['name'] ?? '',
            ];
        }

        return ['value' => $key, 'label' => $option];
    })->values();
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif

    <select
        id="{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border bg-white py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 disabled:cursor-not-allowed disabled:bg-gray-50 '
                .($errors->has($errorKey) ? 'border-red-400' : 'border-gray-300'),
        ]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($normalized as $option)
            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
        @endforeach
    </select>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif

    @error($errorKey)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

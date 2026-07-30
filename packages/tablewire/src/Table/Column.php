<?php

namespace TableWire\Table;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Column
{
    public bool $sortable = false;

    public bool $searchable = false;

    public string $align = 'left';

    public bool $html = false;

    protected ?Closure $formatCallback = null;

    final protected function __construct(
        public string $field,
        public string $label,
        public ?string $sortAs = null,
    ) {
    }

    public static function make(string $field, ?string $label = null): static
    {
        $label ??= Str::of($field)->afterLast('.')->replace('_', ' ')->title()->toString();

        return new static($field, $label);
    }

    /**
     * Allow sorting by this column. Pass $as to sort by a different
     * database column than the displayed field (e.g. a joined column).
     */
    public function sortable(?string $as = null): static
    {
        $this->sortable = true;
        $this->sortAs = $as;

        return $this;
    }

    public function searchable(): static
    {
        $this->searchable = true;

        return $this;
    }

    public function right(): static
    {
        $this->align = 'right';

        return $this;
    }

    public function center(): static
    {
        $this->align = 'center';

        return $this;
    }

    /**
     * Custom cell renderer. Receives (mixed $value, mixed $row).
     * Set $html to true only when the callback returns trusted markup —
     * it will be rendered unescaped.
     */
    public function format(Closure $callback, bool $html = false): static
    {
        $this->formatCallback = $callback;
        $this->html = $html;

        return $this;
    }

    public function date(?string $format = null): static
    {
        $format ??= config('tablewire.date_format', 'Y-m-d');

        return $this->format(
            fn ($value) => $value ? Carbon::parse($value)->format($format) : ''
        );
    }

    public function money(?string $symbol = null, int $decimals = 0): static
    {
        $symbol ??= config('tablewire.currency_symbol', '');

        return $this->right()->format(function ($value) use ($symbol, $decimals) {
            if ($value === null || $value === '') {
                return '';
            }

            $formatted = number_format((float) $value, $decimals);

            return $symbol === '' ? $formatted : $formatted.' '.$symbol;
        });
    }

    /**
     * Render the value as a colored pill. $map is [value => tailwind classes]
     * or [value => ['label' => ..., 'class' => ...]] to relabel values.
     */
    public function badge(array $map, string $default = 'bg-gray-100 text-gray-700'): static
    {
        return $this->format(function ($value) use ($map, $default) {
            $entry = $map[$value] ?? null;
            $label = is_array($entry) ? ($entry['label'] ?? $value) : $value;
            $class = is_array($entry) ? ($entry['class'] ?? $default) : ($entry ?? $default);

            return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '
                .e($class).'">'.e($label).'</span>';
        }, html: true);
    }

    public function render(mixed $row): mixed
    {
        $value = data_get($row, $this->field);

        if ($this->formatCallback) {
            return call_user_func($this->formatCallback, $value, $row);
        }

        return $value;
    }

    public function sortField(): string
    {
        return $this->sortAs ?? $this->field;
    }
}

# TableWire

Beautiful datatables and form components for **Laravel Livewire 3** + **Tailwind CSS**.
No JS build step, no npm dependency — powered by Alpine.js (already bundled with Livewire).

- 🔍 Instant search, column sorting, pagination — state synced to the URL (shareable/bookmarkable)
- ✅ Page-scoped bulk selection with built-in safety (selection clears on page/filter change)
- 🧱 Declarative column API: `Column::make('name')->sortable()->searchable()`
- 💰 Formatters out of the box: `money()`, `date()`, `badge()`, custom closures
- 📝 Form components: text, textarea, select, searchable-select, money, checkbox, toggle
- 🎨 Pure Tailwind — restyle everything by publishing the views

## Requirements

- PHP 8.2+
- Laravel 10 / 11 / 12
- Livewire 3
- Tailwind CSS 3 or 4

## Installation

```bash
composer require tablewire/tablewire
```

Tell Tailwind to scan the package views.

**Tailwind 4** — in your `app.css`:

```css
@source "../../vendor/tablewire/tablewire/resources/views";
```

**Tailwind 3** — in `tailwind.config.js`:

```js
content: [
    // ...
    './vendor/tablewire/tablewire/resources/views/**/*.blade.php',
],
```

Optionally publish config and views:

```bash
php artisan vendor:publish --tag=tablewire-config
php artisan vendor:publish --tag=tablewire-views
```

## Datatable

Create a Livewire component that extends `BaseTable`:

```php
<?php

namespace App\Livewire;

use App\Models\Order;
use Illuminate\Contracts\Database\Eloquent\Builder;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class OrdersTable extends BaseTable
{
    protected function query(): Builder
    {
        return Order::query()->with('customer');
    }

    protected function columns(): array
    {
        return [
            Column::make('number', 'Order #')->sortable()->searchable(),
            Column::make('customer.name', 'Customer')->searchable(),
            Column::make('total')->money('$', 2)->sortable(),
            Column::make('status')->badge([
                'paid'     => 'bg-green-100 text-green-700',
                'pending'  => 'bg-yellow-100 text-yellow-700',
                'refunded' => ['label' => 'Refunded', 'class' => 'bg-red-100 text-red-700'],
            ]),
            Column::make('created_at', 'Date')->date()->sortable(),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('orders.show', $row);
    }

    public function bulkActions(): array
    {
        return ['markPaid' => 'Mark as paid'];
    }

    public function markPaid(): void
    {
        Order::whereIn('id', $this->selected)->update(['status' => 'paid']);
        $this->clearSelection();
    }
}
```

Then drop it anywhere:

```blade
<livewire:orders-table />
```

### Column API

| Method | Description |
|---|---|
| `Column::make($field, $label = null)` | `$field` supports dot notation for relations (`customer.name`) |
| `->sortable($as = null)` | Enable sorting; `$as` sorts by a different DB column |
| `->searchable()` | Include in global search (relations use `whereHas`) |
| `->money($symbol = null, $decimals = 0)` | Right-aligned, thousands-separated |
| `->date($format = null)` | Format via Carbon |
| `->badge($map, $default = ...)` | Colored pill per value |
| `->format(fn ($value, $row) => ..., html: false)` | Custom renderer; `html: true` renders unescaped |
| `->right()` / `->center()` | Alignment |

### Overridable methods on your table

| Method | Purpose |
|---|---|
| `query()` | Base query — scopes, eager loads, default filters |
| `columns()` | Column definitions |
| `bulkActions()` | `[method => label]` shown when rows are selected |
| `rowUrl($row)` | Make rows clickable |
| `defaultSort($query)` | Sort when the user hasn't picked one (default: newest first) |

Sorting is **whitelisted** against your declared sortable columns — client payloads can never inject arbitrary `ORDER BY` values.

## Form components

Every component reads its validation error from the `wire:model` key automatically.

```blade
<x-tablewire::inputs.text label="Name" wire:model.blur="form.name" required />
<x-tablewire::inputs.text label="Email" type="email" wire:model.blur="form.email" />
<x-tablewire::inputs.text label="Birthday" type="date" wire:model="form.birthday" />

<x-tablewire::inputs.textarea label="Notes" wire:model="form.notes" rows="5" />

<x-tablewire::inputs.select
    label="Status"
    wire:model="form.status"
    placeholder="Choose..."
    :options="['draft' => 'Draft', 'active' => 'Active']"
/>

<x-tablewire::inputs.searchable-select
    label="Customer"
    wire:model="form.customer_id"
    :options="$customers"  {{-- ['value'=>,'label'=>] pairs, ['id'=>,'name'=>] rows, or key => label --}}
/>

<x-tablewire::inputs.money label="Amount" wire:model="form.amount" symbol="$" :decimals="2" />

<x-tablewire::inputs.checkbox label="Send invoice" wire:model="form.send_invoice" />
<x-tablewire::inputs.toggle label="Active" wire:model="form.is_active" />
```

> `searchable-select` and `money` use `$wire.entangle()`, so they must be rendered inside a Livewire component.

Add `[x-cloak] { display: none !important; }` to your CSS if you don't have it already.

## Configuration

```php
// config/tablewire.php
return [
    'per_page' => 25,
    'per_page_options' => [10, 25, 50, 100],
    'currency_symbol' => '',   // used by Column::money() and inputs.money
    'date_format' => 'Y-m-d',
];
```

## Roadmap

- Dark mode variants
- Column filters (select/date-range per column)
- Excel/CSV export
- Row actions dropdown
- Multi-select & async (AJAX) options for searchable-select

## License

Commercial — one license per project. See LICENSE.md.

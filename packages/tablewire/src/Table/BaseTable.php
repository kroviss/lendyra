<?php

namespace TableWire\Table;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

abstract class BaseTable extends Component
{
    use WithPagination;

    public string $search = '';

    /** Sort state: "field" for ascending, "-field" for descending. */
    public string $sort = '';

    public int $perPage = 0;

    /** @var array<int, string> Primary keys of selected rows (current page only). */
    public array $selected = [];

    public bool $selectPage = false;

    /**
     * The base query for the table. Apply your own scopes, eager loads
     * and default filters here — search and sort are applied on top.
     */
    abstract protected function query(): Builder;

    /** @return Column[] */
    abstract protected function columns(): array;

    /**
     * Bulk actions offered when rows are selected: [method => label].
     * Each method receives the selection via $this->selected.
     */
    public function bulkActions(): array
    {
        return [];
    }

    /** Return a URL to make the whole row clickable, or null. */
    public function rowUrl(mixed $row): ?string
    {
        return null;
    }

    /**
     * Extra search fields (plain or relation-dotted) that are searched
     * without being displayed as columns.
     *
     * @return string[]
     */
    protected function searchAlso(): array
    {
        return [];
    }

    public function mount(): void
    {
        if ($this->perPage <= 0) {
            $this->perPage = (int) config('tablewire.per_page', 25);
        }
    }

    protected function queryString(): array
    {
        return [
            'search' => ['except' => '', 'as' => 'q'],
            'sort' => ['except' => ''],
            'perPage' => ['except' => (int) config('tablewire.per_page', 25)],
        ];
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->query();

        $this->applySearch($query);
        $this->applySort($query);

        return $query->paginate(max(1, min((int) $this->perPage, 200)));
    }

    public function sortBy(string $field): void
    {
        // Whitelist against declared sortable columns so arbitrary
        // client-side payloads can never reach ORDER BY.
        if (! $this->sortableFields()->contains($field)) {
            return;
        }

        $this->sort = $this->sort === $field ? '-'.$field : $field;
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min((int) $this->perPage, 200));
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Selection is page-scoped: any page change clears it so a bulk
     * action never silently includes rows the user can no longer see.
     */
    public function updatedPaginators(): void
    {
        $this->clearSelection();
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? $this->rows->getCollection()->map(fn ($row) => (string) $row->getKey())->all()
            : [];
    }

    public function updatedSelected(): void
    {
        $this->selectPage = false;
    }

    protected function applySearch(Builder $query): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $columns = collect($this->columns())->filter(
            fn (Column $column) => $column->searchable
        );

        if ($columns->isEmpty()) {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $fields = $columns->map(fn (Column $column) => $column->field)
            ->merge($this->searchAlso())
            ->unique()
            ->values();

        $query->where(function (Builder $outer) use ($fields, $like) {
            foreach ($fields as $field) {
                if (str_contains($field, '.')) {
                    // Support nested relations: "loan.borrower.phone"
                    $relation = substr($field, 0, strrpos($field, '.'));
                    $leaf = substr($field, strrpos($field, '.') + 1);
                    $outer->orWhereHas($relation, fn (Builder $sub) => $sub->where($leaf, 'like', $like));
                } else {
                    $outer->orWhere($outer->getModel()->qualifyColumn($field), 'like', $like);
                }
            }
        });
    }

    protected function applySort(Builder $query): void
    {
        $field = ltrim($this->sort, '-');

        if ($this->sort === '' || ! $this->sortableFields()->contains($field)) {
            $this->defaultSort($query);

            return;
        }

        $direction = str_starts_with($this->sort, '-') ? 'desc' : 'asc';

        // reorder() drops any orderBy baked into query() — an explicit user
        // sort must win. The key tiebreaker keeps pagination deterministic.
        $query->reorder()
            ->orderBy($field, $direction)
            ->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    /** Sort applied when the user has not chosen one. Override as needed. */
    protected function defaultSort(Builder $query): void
    {
        if (empty($query->getQuery()->orders)) {
            $query->orderByDesc($query->getModel()->getQualifiedKeyName());
        }
    }

    protected function sortableFields(): \Illuminate\Support\Collection
    {
        return collect($this->columns())
            ->filter(fn (Column $column) => $column->sortable)
            ->map(fn (Column $column) => $column->sortField())
            ->values();
    }

    /**
     * Export the CURRENT filtered+sorted view as CSV (Excel-friendly BOM).
     *
     * Livewire buffers download responses in memory, so the row count is
     * capped: shared hosting cannot hold an unbounded book in RAM.
     * Override exportLimit() or canExport() per table as needed.
     */
    protected function exportLimit(): int
    {
        return (int) config('tablewire.export_limit', 10000);
    }

    protected function canExport(): bool
    {
        return true;
    }

    #[Computed]
    public function exportAllowed(): bool
    {
        return $this->canExport();
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $query = $this->query();
        $this->applySearch($query);
        $this->applySort($query);

        $columns = collect($this->columns())->filter(fn (Column $c) => $c->exportable)->values();
        $filename = \Illuminate\Support\Str::kebab(class_basename(static::class) === 'Index'
            ? class_basename(dirname(str_replace('\\', '/', static::class)))
            : class_basename(static::class)).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns->map(fn (Column $c) => $c->label)->all());

            $query->take($this->exportLimit())->chunk(500, function ($rows) use ($out, $columns) {
                foreach ($rows as $row) {
                    fputcsv($out, $columns->map(function (Column $c) use ($row) {
                        $value = $c->render($row);
                        $value = $c->html ? trim(strip_tags((string) $value)) : (string) $value;

                        // Neutralize spreadsheet formula injection: a cell
                        // starting with = + - @ or a tab executes in Excel.
                        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                    })->all());
                }
            });

            fclose($out);
        }, $filename);
    }

    public function render(): View
    {
        return view('tablewire::table.table', [
            'columns' => $this->columns(),
        ]);
    }
}

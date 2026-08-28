<?php

declare(strict_types=1);

namespace App\Support\Query;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Server-side filtering, searching, sorting and pagination for index endpoints.
 *
 * Everything is opt-in by allow-list. A column that has not been declared
 * sortable or filterable is ignored rather than passed through, so a client
 * cannot sort by an unindexed column and take the database down, or filter on a
 * column it was never meant to see.
 *
 *   GET /api/v1/users?search=sok&filter[status]=active&sort=-created_at&per_page=50
 *
 * Use paginate() for admin tables that need a page count, and cursorPaginate()
 * for anything that scans a large table — offset pagination has to walk every
 * skipped row, so deep pages on a million-row table are the classic way these
 * endpoints fall over.
 */
final class ApiQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private array $searchable = [];

    /** @var array<string, string> exposed name => column */
    private array $filterable = [];

    /** @var array<string, string> exposed name => column */
    private array $sortable = [];

    private ?string $defaultSort = null;

    private int $maxPerPage = self::MAX_PER_PAGE;

    /**
     * @param  Builder<*>  $query
     */
    public function __construct(
        private readonly Builder $query,
        private readonly Request $request,
    ) {}

    /**
     * @param  Builder<*>  $query
     */
    public static function for(Builder $query, ?Request $request = null): self
    {
        return new self($query, $request ?? request());
    }

    /**
     * Columns matched by ?search=. Kept small on purpose: each one is another
     * ILIKE against the same rows.
     */
    public function searchable(string ...$columns): self
    {
        $this->searchable = $columns;

        return $this;
    }

    /**
     * @param  array<int|string, string>  $columns  ['status', 'role' => 'roles.slug']
     */
    public function filterable(array $columns): self
    {
        $this->filterable = $this->normalise($columns);

        return $this;
    }

    /**
     * @param  array<int|string, string>  $columns
     */
    public function sortable(array $columns, ?string $default = null): self
    {
        $this->sortable = $this->normalise($columns);
        $this->defaultSort = $default;

        return $this;
    }

    public function maxPerPage(int $max): self
    {
        $this->maxPerPage = max(1, $max);

        return $this;
    }

    /**
     * @return Builder<*>
     */
    public function build(): Builder
    {
        return $this->applySort($this->applyFilters($this->applySearch($this->query)));
    }

    public function paginate(): LengthAwarePaginator
    {
        return $this->build()
            ->paginate($this->perPage())
            ->withQueryString();
    }

    /**
     * Keyset pagination. Constant cost at any depth, so this is the right
     * default for exports, feeds and anything over ~100k rows.
     */
    public function cursorPaginate(): CursorPaginator
    {
        return $this->build()
            ->cursorPaginate($this->perPage())
            ->withQueryString();
    }

    public function perPage(): int
    {
        $requested = (int) $this->request->input('per_page', self::DEFAULT_PER_PAGE);

        // Clamped rather than rejected: a client asking for 10,000 rows gets the
        // maximum, not an error, but never gets to choose the query's cost.
        return max(1, min($requested ?: self::DEFAULT_PER_PAGE, $this->maxPerPage));
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function applySearch(Builder $query): Builder
    {
        $term = trim((string) $this->request->input('search', ''));

        if ($term === '' || $this->searchable === []) {
            return $query;
        }

        // Escape LIKE wildcards so a search for "100%" is not a full scan
        // dressed up as a prefix match.
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped) {
            foreach ($this->searchable as $column) {
                // ILIKE: Postgres-native case-insensitive match, and it can use
                // a trigram or lower() index, unlike LOWER(col) LIKE LOWER(?).
                $inner->orWhere($column, 'ILIKE', '%'.$escaped.'%');
            }
        });
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function applyFilters(Builder $query): Builder
    {
        $filters = $this->request->input('filter', []);

        if (! is_array($filters)) {
            return $query;
        }

        foreach ($filters as $name => $value) {
            if (! is_string($name) || ! isset($this->filterable[$name]) || $value === null || $value === '') {
                continue;
            }

            $column = $this->filterable[$name];

            // filter[status]=active,suspended
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_values(array_filter(array_map('trim', $values), fn ($v) => $v !== ''));

            if ($values === []) {
                continue;
            }

            count($values) === 1
                ? $query->where($column, $values[0])
                : $query->whereIn($column, $values);
        }

        return $query;
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function applySort(Builder $query): Builder
    {
        $requested = (string) $this->request->input('sort', '');
        $applied = false;

        foreach (array_filter(array_map('trim', explode(',', $requested))) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $name = ltrim($field, '-+');

            if (! isset($this->sortable[$name])) {
                continue;
            }

            $query->orderBy($this->sortable[$name], $direction);
            $applied = true;
        }

        if (! $applied && $this->defaultSort !== null) {
            $direction = str_starts_with($this->defaultSort, '-') ? 'desc' : 'asc';
            $query->orderBy(ltrim($this->defaultSort, '-+'), $direction);
        }

        // Cursor pagination requires a deterministic total order, and any
        // paginated list needs a tiebreak or rows can repeat across pages.
        return $query->orderBy($query->getModel()->qualifyColumn($query->getModel()->getKeyName()), 'desc');
    }

    /**
     * @param  array<int|string, string>  $columns
     * @return array<string, string>
     */
    private function normalise(array $columns): array
    {
        $normalised = [];

        foreach ($columns as $key => $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('Column allow-lists must contain non-empty strings.');
            }

            $normalised[is_int($key) ? Str::afterLast($value, '.') : $key] = $value;
        }

        return $normalised;
    }
}

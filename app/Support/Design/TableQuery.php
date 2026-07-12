<?php

namespace App\Support\Design;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Server-driven table state: search, multi-column sort, per-column search,
 * filters, page size — all expressed as URL query parameters so any table view
 * stays shareable and bookmarkable.
 *
 * This replaces the per-page sort/search logic that pages used to hand-roll
 * (see the old OrderController::index). There is deliberately NO client-side
 * data store: every interaction is a plain GET round-trip.
 *
 * Query-parameter contract:
 *   ?search=foo            global search
 *   ?sort=-total,name      multi-column sort ('-' prefix = desc), left-to-right priority
 *   ?c[city]=tehran        per-column search
 *   ?per_page=50           page size
 *   ?status=completed      arbitrary filters (whitelisted by the caller)
 *
 * Every URL builder preserves the other params, so sorting never drops the
 * active filter and paginating never drops the sort.
 */
class TableQuery
{
    public const PAGE_SIZES = [15, 25, 50, 100];

    /**
     * @param  array<string,string>  $sortable  public sort key => DB column
     * @param  array<string,string>  $searchable  public column key => DB column (per-column search)
     * @param  list<string>  $filters  filter param names the page understands
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $sortable = [],
        private readonly array $searchable = [],
        private readonly array $filters = [],
        private readonly string $defaultSort = '',
    ) {}

    public function search(): ?string
    {
        $s = trim((string) $this->request->query('search', ''));

        return $s === '' ? null : $s;
    }

    public function perPage(): int
    {
        $p = (int) $this->request->query('per_page', 15);

        return in_array($p, self::PAGE_SIZES, true) ? $p : 15;
    }

    /**
     * Parsed, whitelisted sort list, in priority order.
     *
     * @return list<array{key: string, column: string, dir: string}>
     */
    public function sorts(): array
    {
        $raw = (string) $this->request->query('sort', $this->defaultSort);

        $out = [];
        foreach (array_filter(explode(',', $raw)) as $part) {
            $desc = str_starts_with($part, '-');
            $key = ltrim($part, '-');

            // Unknown keys are dropped, never passed to the query builder.
            if (! isset($this->sortable[$key])) {
                continue;
            }

            $out[] = ['key' => $key, 'column' => $this->sortable[$key], 'dir' => $desc ? 'desc' : 'asc'];
        }

        return $out;
    }

    /** Current direction for one column, or null when it isn't part of the sort. */
    public function sortDir(string $key): ?string
    {
        foreach ($this->sorts() as $s) {
            if ($s['key'] === $key) {
                return $s['dir'];
            }
        }

        return null;
    }

    /** 1-based position of a column in a multi-sort (null when not sorted). */
    public function sortPriority(string $key): ?int
    {
        foreach ($this->sorts() as $i => $s) {
            if ($s['key'] === $key) {
                return count($this->sorts()) > 1 ? $i + 1 : null;
            }
        }

        return null;
    }

    /** Per-column search terms (whitelisted). @return array<string,string> */
    public function columnSearch(): array
    {
        $raw = (array) $this->request->query('c', []);

        return array_filter(
            array_intersect_key($raw, $this->searchable),
            fn ($v) => is_string($v) && trim($v) !== ''
        );
    }

    /**
     * URL that toggles sorting on a column, preserving every other param.
     *
     * Plain click  → sort by this column only (asc → desc → off).
     * Ctrl/⌘-click → append to the existing sort (multi-column); the Blade
     *                header renders both URLs and Alpine picks based on the event.
     */
    public function sortUrl(string $key, bool $append = false): string
    {
        if (! isset($this->sortable[$key])) {
            return $this->request->fullUrl();
        }

        $current = $this->sorts();
        $dir = $this->sortDir($key);
        $next = match ($dir) {
            'asc' => 'desc',
            'desc' => null,   // third click clears this column
            default => 'asc',
        };

        if ($append) {
            $list = array_values(array_filter($current, fn ($s) => $s['key'] !== $key));
            if ($next !== null) {
                $list[] = ['key' => $key, 'dir' => $next];
            }
        } else {
            $list = $next === null ? [] : [['key' => $key, 'dir' => $next]];
        }

        $sort = implode(',', array_map(fn ($s) => ($s['dir'] === 'desc' ? '-' : '').$s['key'], $list));

        return $this->urlWith(['sort' => $sort ?: null, 'page' => null]);
    }

    /** Same URL with `page_size` swapped; resets to page 1. */
    public function perPageUrl(int $size): string
    {
        return $this->urlWith(['per_page' => $size, 'page' => null]);
    }

    /** Same URL with one param removed — powers the active-filter chips. */
    public function withoutUrl(string $param, ?string $key = null): string
    {
        if ($key === null) {
            return $this->urlWith([$param => null, 'page' => null]);
        }

        // Nested param, e.g. removing one per-column search from c[...]
        $nested = (array) $this->request->query($param, []);
        unset($nested[$key]);

        return $this->urlWith([$param => $nested ?: null, 'page' => null]);
    }

    /** URL with every filter/search/sort cleared. */
    public function clearUrl(): string
    {
        return $this->request->url();
    }

    public function hasActiveFilters(): bool
    {
        return $this->activeFilters() !== [];
    }

    /**
     * Chips describing what is currently narrowing the result set. Each carries
     * the URL that removes just that one thing.
     *
     * @param  array<string,string>  $labels  param name => Persian label
     * @return list<array{label: string, value: string, url: string}>
     */
    public function activeFilters(array $labels = []): array
    {
        $chips = [];

        if ($s = $this->search()) {
            $chips[] = ['label' => 'جستجو', 'value' => $s, 'url' => $this->withoutUrl('search')];
        }

        foreach ($this->columnSearch() as $key => $value) {
            $chips[] = [
                'label' => $labels[$key] ?? $key,
                'value' => $value,
                'url' => $this->withoutUrl('c', $key),
            ];
        }

        foreach ($this->filters as $param) {
            $v = $this->request->query($param);
            if ($v === null || $v === '') {
                continue;
            }
            $chips[] = [
                'label' => $labels[$param] ?? $param,
                'value' => is_array($v) ? implode('، ', $v) : (string) $v,
                'url' => $this->withoutUrl($param),
            ];
        }

        return $chips;
    }

    /**
     * Apply the parsed sort + per-column search to a query builder. Global
     * search stays with the caller: only the page knows which relations and
     * columns it means.
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->columnSearch() as $key => $term) {
            $query->where($this->searchable[$key], 'like', '%'.$term.'%');
        }

        foreach ($this->sorts() as $s) {
            $query->orderBy($s['column'], $s['dir']);
        }

        return $query;
    }

    /** Merge params into the current URL, dropping any set to null. */
    private function urlWith(array $params): string
    {
        $query = array_filter(
            array_merge($this->request->query(), $params),
            fn ($v) => $v !== null && $v !== '' && $v !== []
        );

        return $this->request->url().($query ? '?'.http_build_query($query) : '');
    }
}

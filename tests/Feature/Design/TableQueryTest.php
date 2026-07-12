<?php

use App\Support\Design\TableQuery;
use Illuminate\Http\Request;

/*
 * The whole point of TableQuery is that table state lives in the URL: sorting,
 * filtering and paging must never silently drop each other, or a shared link
 * shows different data than the sender saw.
 */

function tq(array $query = []): TableQuery
{
    return new TableQuery(
        Request::create('https://app.test/orders', 'GET', $query),
        sortable: ['total' => 'orders.total', 'date' => 'orders.order_date'],
        searchable: ['city' => 'orders.city'],
        filters: ['status', 'channel'],
        defaultSort: '-date',
    );
}

it('parses a multi-column sort in priority order and drops unknown keys', function () {
    $sorts = tq(['sort' => '-total,date,evil_injection'])->sorts();

    expect($sorts)->toHaveCount(2)                       // the unknown key is discarded
        ->and($sorts[0])->toMatchArray(['key' => 'total', 'column' => 'orders.total', 'dir' => 'desc'])
        ->and($sorts[1])->toMatchArray(['key' => 'date', 'column' => 'orders.order_date', 'dir' => 'asc']);
});

it('cycles a column asc → desc → off', function () {
    expect(tq([])->sortUrl('total'))->toContain('sort=total');            // off → asc
    expect(tq(['sort' => 'total'])->sortUrl('total'))->toContain('sort=-total'); // asc → desc

    // desc → off: the sort param disappears entirely rather than lingering empty
    expect(tq(['sort' => '-total'])->sortUrl('total'))->not->toContain('sort=');
});

it('appends to an existing sort for multi-column sorting', function () {
    $url = tq(['sort' => '-total'])->sortUrl('date', append: true);

    expect(urldecode($url))->toContain('sort=-total,date');
});

it('preserves other query params when sorting, paging or changing page size', function () {
    $q = tq(['status' => 'completed', 'search' => 'اسپری', 'page' => '3']);

    // Sorting keeps the filter + search, but must reset to page 1 (page 3 of the
    // old ordering is meaningless under a new one).
    $sortUrl = urldecode($q->sortUrl('total'));
    expect($sortUrl)->toContain('status=completed')
        ->toContain('search=اسپری')
        ->not->toContain('page=3');

    $sizeUrl = urldecode($q->perPageUrl(50));
    expect($sizeUrl)->toContain('status=completed')
        ->toContain('per_page=50')
        ->not->toContain('page=3');
});

it('only accepts whitelisted page sizes', function () {
    expect(tq(['per_page' => '50'])->perPage())->toBe(50)
        ->and(tq(['per_page' => '9999'])->perPage())->toBe(15)   // absurd size ignored
        ->and(tq([])->perPage())->toBe(15);
});

it('lists active filters as removable chips', function () {
    $q = tq(['search' => 'اسپری', 'status' => 'completed', 'c' => ['city' => 'تهران']]);

    $chips = $q->activeFilters(['status' => 'وضعیت', 'city' => 'شهر']);

    expect($q->hasActiveFilters())->toBeTrue()
        ->and($chips)->toHaveCount(3);

    // Removing one chip keeps the others.
    $removeStatus = urldecode(collect($chips)->firstWhere('label', 'وضعیت')['url']);
    expect($removeStatus)->toContain('search=اسپری')->not->toContain('status=completed');
});

it('reports no active filters on a clean request', function () {
    expect(tq(['sort' => '-total'])->hasActiveFilters())->toBeFalse(); // sorting is not a filter
});

it('ignores per-column search on non-searchable columns', function () {
    $q = tq(['c' => ['city' => 'تهران', 'secret_column' => 'x']]);

    expect($q->columnSearch())->toBe(['city' => 'تهران']);
});

<?php

use App\Support\Design\StatusPresenter;
use Illuminate\Support\Facades\Blade;

/*
 * Guards the Reporting Design System foundation (Phase 2).
 */

it('maps every canonical status to a unique, non-empty token and label', function () {
    $all = StatusPresenter::all();

    expect($all)->toHaveCount(9);

    $tokens = array_column($all, 'token');
    expect($tokens)->toHaveCount(count(array_unique($tokens))); // no two statuses share a token

    foreach ($all as $key => $meta) {
        expect($meta['label'])->not->toBeEmpty("status {$key} has no Persian label");
        expect($meta['token'])->toMatch('/^[a-z-]+$/');
        expect(StatusPresenter::resolve($key)['token'])->toBe($meta['token']);
    }
});

it('never breaks on an unknown status', function () {
    $r = StatusPresenter::resolve('some_status_that_does_not_exist');

    expect($r['label'])->toBe('some_status_that_does_not_exist') // shows the raw value, does not throw
        ->and($r['token'])->toBe('draft');                       // neutral fallback
});

it('resolves a financial delta to a trend direction', function () {
    expect(StatusPresenter::trend(10))->toBe('up')
        ->and(StatusPresenter::trend(-10))->toBe('down')
        ->and(StatusPresenter::trend(0))->toBe('flat');
});

it('renders numeric cells LTR but right-aligned with tabular figures', function () {
    // The core regression guard: dir="ltr" must never drag the value to the left
    // edge while the header stays right — that desync broke every money column.
    $html = Blade::render('<x-tables.num :value="1250000" />');

    expect($html)->toContain('dir="ltr"')      // digits + minus sign read correctly
        ->toContain('text-right')              // pinned to the same edge as the <th>
        ->toContain('tabular-fig')             // digits stack by place value
        ->toContain('1,250,000');
});

it('colours and signs a profit/loss figure without relying on colour alone', function () {
    $profit = Blade::render('<x-tables.num :value="284000" :signed="true" />');
    $loss = Blade::render('<x-tables.num :value="-45000" :signed="true" />');

    expect($profit)->toContain('text-profit')->toContain('+284,000')  // sign, not just colour
        ->and($loss)->toContain('text-loss')->toContain('-45,000');
});

it('renders a status badge with both a dot and a text label', function () {
    $html = Blade::render('<x-ui.status status="completed" />');

    expect($html)->toContain('تکمیل‌شده')                        // text label
        ->toContain('--color-status-completed')                  // semantic token, not a raw hex
        ->toContain('rounded-full');                             // the dot
});

it('gives every chart a unique generated id so a preset can repeat on one page', function () {
    $a = Blade::render('<x-charts.chart preset="bar" :series="[1,2,3]" />');
    $b = Blade::render('<x-charts.chart preset="bar" :series="[1,2,3]" />');

    preg_match('/id="(chart-[A-Za-z0-9]+)"/', $a, $ma);
    preg_match('/id="(chart-[A-Za-z0-9]+)"/', $b, $mb);

    expect($ma[1] ?? null)->not->toBeNull()
        ->and($mb[1] ?? null)->not->toBeNull()
        ->and($ma[1])->not->toBe($mb[1])   // two instances never collide
        ->and($a)->toContain('data-chart'); // config travels with the element
});

it('renders every data state variant', function (string $variant) {
    expect(Blade::render('<x-states.state variant="'.$variant.'" />'))->not->toBeEmpty();
})->with(['empty', 'no-results', 'error', 'permission', 'loading', 'skeleton', 'stale', 'partial', 'offline']);

it('formats every numeric type with its own unit and precision', function () {
    expect(Blade::render('<x-tables.num :value="1250000" type="toman" />'))->toContain('1,250,000')->toContain('تومان')
        ->and(Blade::render('<x-tables.num :value="1250000" type="rial" />'))->toContain('ریال')
        ->and(Blade::render('<x-tables.num :value="12.5" type="percent" />'))->toContain('12.5')->toContain('٪')
        ->and(Blade::render('<x-tables.num :value="3.14159" type="decimal" />'))->toContain('3.14')   // 2dp
        ->and(Blade::render('<x-tables.num :value="42" type="int" />'))->toContain('42');
});

it('renders Persian digits on demand but keeps latin by default', function () {
    // Default stays latin: the IRANSansXFaNum font renders ASCII digits as
    // Persian glyphs while keeping them tabular, which is what tables need.
    expect(Blade::render('<x-tables.num :value="1000" />'))->toContain('1,000')
        ->and(Blade::render('<x-tables.num :value="1000" digits="fa" />'))->toContain('۱,۰۰۰');
});

it('distinguishes null from zero', function () {
    expect(Blade::render('<x-tables.num :value="null" />'))->toContain('—')            // unavailable
        ->and(Blade::render('<x-tables.num :value="0" />'))->toContain('0')            // a real zero
        ->and(Blade::render('<x-tables.num :value="0" zero="بدون تراکنش" />'))->toContain('بدون تراکنش');
});

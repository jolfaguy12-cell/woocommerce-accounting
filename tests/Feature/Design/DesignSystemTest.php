<?php

use App\Support\Design\StatusPresenter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

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

it('never hand-writes a numeric cell outside the design system', function () {
    // The alignment bug came back one <td dir="ltr"> at a time. A raw dir="ltr"
    // cell resolves text-align:start to LEFT while its <th> stays right, so the
    // column silently desyncs. Every numeric/LTR cell must go through
    // <x-tables.num> / <x-tables.ltr>, which own direction AND alignment.
    //
    // Two cells in orders/show are allowed: their fallback is a badge, not a
    // number, so they must stay a <td> — but they carry an explicit text-right.
    //
    // components/ is scanned too: the Showcase demos escaped an earlier version
    // of this guard that only looked at pages/, so the catalog itself shipped
    // the very bug it exists to teach against.
    $offenders = [];

    $files = array_merge(
        File::allFiles(resource_path('views/pages')),
        File::allFiles(resource_path('views/components')),
    );

    foreach ($files as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
            if (! preg_match('/<td[^>]*dir="ltr"/', $line)) {
                continue;
            }

            // Explicitly aligned cells are fine — they cannot desync from the header.
            if (str_contains($line, 'text-right')) {
                continue;
            }

            $offenders[] = $file->getRelativePathname().':'.($n + 1);
        }
    }

    expect($offenders)->toBe([], 'Use <x-tables.num>/<x-tables.ltr> (or add text-right): '.implode(', ', $offenders));
});

it('gives every figure a readable colour of its own, in both themes', function () {
    // `body` sets no text colour, so a figure that inherited its colour from the
    // caller's <td> fell back to the browser default (pure black): illegible on
    // a dark background, and heavier than its neighbours on a light one. The
    // primitive must therefore supply the colour itself — the caller passing
    // nothing is the common case, and it has to look right.
    $html = Blade::render('<x-tables.num :value="1250000" />');

    expect($html)->toContain('text-gray-800')       // readable on light
        ->toContain('dark:text-white/90');          // and on dark

    // A semantic tone, never a raw colour class from the caller.
    expect(Blade::render('<x-tables.num :value="1" tone="muted" />'))->toContain('text-gray-600')
        ->and(Blade::render('<x-tables.num :value="1" tone="positive" />'))->toContain('text-success-700')
        ->and(Blade::render('<x-tables.num :value="1" tone="negative" />'))->toContain('text-error-600')
        ->and(Blade::render('<x-tables.ltr value="IR12" />'))->toContain('dark:text-white/90');

    // Every tone inverts with the background: the darker shade on light, the
    // lighter shade on dark. Pairing them the other way round (gray-400 on white,
    // gray-500 on black) fails WCAG AA at BOTH ends — `subtle` was doing exactly
    // that, measured at 2.6:1 on light and 3.0:1 on dark.
    foreach (['subtle', 'muted', 'positive', 'negative'] as $tone) {
        $html = Blade::render('<x-tables.num :value="1" tone="'.$tone.'" />');

        preg_match('/(?<!dark:)text-\w+-(\d00)/', $html, $light);
        preg_match('/dark:text-\w+-(\d00)/', $html, $dark);

        expect((int) $light[1])->toBeGreaterThan(
            (int) $dark[1],
            "tone={$tone} must use a darker shade on light than it does on dark"
        );
    }

    // An unavailable value is never emphasised — but it still has to be legible.
    expect(Blade::render('<x-tables.num :value="null" tone="default" />'))
        ->toContain('text-gray-500')->toContain('dark:text-gray-400');

    // The sign carries the meaning, so profit/loss colour outranks the tone.
    expect(Blade::render('<x-tables.num :value="-45000" :signed="true" tone="muted" />'))
        ->toContain('text-loss')->not->toContain('text-gray-600');
});

it('never lets a caller hand-colour a figure', function () {
    // A text-* colour on the <td> is now dead code: the tone sits on the figure
    // span, which beats anything merely inherited from the cell. Leaving one in
    // place is a silent no-op that will confuse the next reader.
    $offenders = [];

    $files = array_merge(
        File::allFiles(resource_path('views/pages')),
        File::allFiles(resource_path('views/components')),
    );

    foreach ($files as $file) {
        if (in_array($file->getFilename(), ['num.blade.php', 'ltr.blade.php'], true)) {
            continue;   // the primitives are where colour is allowed to live
        }

        foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
            if (! str_contains($line, '<x-tables.num') && ! str_contains($line, '<x-tables.ltr')) {
                continue;
            }

            if (preg_match('/class="[^"]*\btext-(gray|success|error|warning|brand|white)-?/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Use tone="…" instead of a colour class: '.implode(', ', $offenders));
});

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

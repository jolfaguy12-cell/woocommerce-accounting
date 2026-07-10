<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use App\Domain\Sync\Models\RawOrder;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['hub.base_url' => 'https://hub.test/api/v1', 'hub.api_key' => 'k']);
});

it('acc:hub:check reports hub health in json mode', function () {
    Http::fake(['hub.test/api/v1/health' => Http::response(['status' => 'ok'])]);

    $this->artisan('acc:hub:check --json')
        ->expectsOutputToContain('"ok":true')
        ->assertSuccessful();
});

it('acc:hub:check fails cleanly when the hub is unreachable', function () {
    Http::fake(['hub.test/api/v1/health' => Http::response(null, 500)]);

    $this->artisan('acc:hub:check --json')->assertFailed();
});

it('acc:sync:order fetches and stores one raw order', function () {
    Http::fake([
        'hub.test/api/v1/orders/600' => Http::response(['id' => 600, 'status' => 'completed']),
    ]);

    $this->artisan('acc:sync:order 600')->assertSuccessful();

    expect(RawOrder::first()->hub_order_id)->toBe(600)
        ->and(RawOrder::first()->fetched_via)->toBe('manual');
});

it('acc:sync:poll-orders sends a since parameter even on the very first run (hub requires it)', function () {
    Http::fake(['hub.test/api/v1/sync/changed/orders*' => Http::response(['orders' => []])]);

    $this->artisan('acc:sync:poll-orders')->assertSuccessful();

    Http::assertSent(function ($request) {
        $since = $request->data()['since'] ?? '';

        // plain ISO datetime, no timezone suffix — the shape the hub documents
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $since) === 1;
    });
});

it('acc:sync:poll-products sends a since parameter even on the very first run', function () {
    Http::fake(['hub.test/api/v1/sync/changed/products*' => Http::response(['products' => []])]);

    $this->artisan('acc:sync:poll-products')->assertSuccessful();

    Http::assertSent(fn ($request) => preg_match(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
        $request->data()['since'] ?? ''
    ) === 1);
});

it('acc:sync:poll-orders walks every page of the changed feed (hub pages cap at 100)', function () {
    Http::fake(function ($request) {
        if (preg_match('#/orders/(\d+)$#', $request->url(), $m)) {
            return Http::response(['id' => (int) $m[1], 'status' => 'completed', 'total' => '1000']);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $page = (int) ($q['page'] ?? 1);

        $rows = $page === 1
            ? array_map(fn ($i) => ['id' => $i, 'status' => 'completed', 'total' => '1000'], range(1000, 1099))
            : [['id' => 1100, 'status' => 'completed', 'total' => '1000']];

        return Http::response(['data' => $rows, 'pagination' => ['page' => $page]]);
    });

    $this->artisan('acc:sync:poll-orders')->assertSuccessful();

    expect(RawOrder::count())->toBe(101);
});

it('acc:sync:poll-orders fetches the full order — feed rows are stubs without items or meta', function () {
    Http::fake([
        'hub.test/api/v1/sync/changed/orders*' => Http::response([
            // the changed feed carries a status, but no meta_data/line_items
            'orders' => [['id' => 702, 'status' => 'completed', 'total' => '5000']],
        ]),
        'hub.test/api/v1/orders/702' => Http::response([
            'id' => 702, 'status' => 'completed', 'total' => '5000',
            'order_source' => 'basalam', 'meta_data' => [], 'line_items' => [],
        ]),
    ]);

    $this->artisan('acc:sync:poll-orders')->assertSuccessful();

    expect(RawOrder::first()->payload)->toHaveKey('order_source');
});

it('acc:sync:poll-orders walks the changed cursor and upserts, overlap-safe with webhooks', function () {
    Http::fake([
        'hub.test/api/v1/sync/changed/orders*' => Http::response([
            'orders' => [['id' => 700], ['id' => 701]],
        ]),
        'hub.test/api/v1/orders/700' => Http::response(['id' => 700, 'status' => 'completed']),
        'hub.test/api/v1/orders/701' => Http::response(['id' => 701, 'status' => 'processing']),
    ]);

    // order 700 already arrived via webhook
    RawOrder::create([
        'hub_order_id' => 700,
        'payload' => ['id' => 700, 'status' => 'completed'],
        'payload_hash' => hash('sha256', json_encode(['id' => 700, 'status' => 'completed'])),
        'fetched_via' => 'webhook',
        'received_at' => now(),
    ]);

    $this->artisan('acc:sync:poll-orders')->assertSuccessful();

    expect(RawOrder::count())->toBe(2) // 700 not duplicated
        ->and(SyncRun::where('type', 'poll_orders')->where('status', 'done')->count())->toBe(1);
});

it('acc:sync:backfill-orders imports only orders missing locally, skipping ones already synced', function () {
    Http::fake(function ($request) {
        if (preg_match('#/orders/(\d+)$#', $request->url(), $m)) {
            return Http::response(['id' => (int) $m[1], 'status' => 'completed', 'total' => '1000']);
        }

        return Http::response(['data' => [['id' => 9001], ['id' => 9002], ['id' => 9003]], 'pagination' => ['total' => 3]]);
    });

    app(OrderIngestPipeline::class)->ingest(9001, ['id' => 9001, 'status' => 'completed', 'total' => '1000'], 'manual');

    $this->artisan('acc:sync:backfill-orders')->assertSuccessful();

    expect(Order::count())->toBe(3)
        ->and(Order::whereIn('hub_order_id', [9002, 9003])->count())->toBe(2);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders/9001'));
});

it('acc:sync:backfill-orders --dry-run reports counts without importing', function () {
    Http::fake([
        'hub.test/api/v1/orders*' => Http::response(['data' => [['id' => 9101], ['id' => 9102]], 'pagination' => ['total' => 2]]),
    ]);

    $this->artisan('acc:sync:backfill-orders --dry-run --json')
        ->expectsOutputToContain('missing')
        ->assertSuccessful();

    expect(Order::count())->toBe(0);
});

it('acc:sync:backfill-orders --limit caps how many orders are imported this run', function () {
    Http::fake(function ($request) {
        if (preg_match('#/orders/(\d+)$#', $request->url(), $m)) {
            return Http::response(['id' => (int) $m[1], 'status' => 'completed', 'total' => '1000']);
        }

        return Http::response(['data' => [['id' => 9201], ['id' => 9202], ['id' => 9203]], 'pagination' => ['total' => 3]]);
    });

    $this->artisan('acc:sync:backfill-orders --limit=1')->assertSuccessful();

    expect(Order::count())->toBe(1);
});

it('acc:sync:backfill-orders keeps going past a single failed order and records it', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/orders/9302')) {
            return Http::response(['error' => 'boom'], 500);
        }
        if (preg_match('#/orders/(\d+)$#', $request->url(), $m)) {
            return Http::response(['id' => (int) $m[1], 'status' => 'completed', 'total' => '1000']);
        }

        return Http::response(['data' => [['id' => 9301], ['id' => 9302], ['id' => 9303]], 'pagination' => ['total' => 3]]);
    });

    $this->artisan('acc:sync:backfill-orders')->assertFailed(); // non-zero exit when any order fails

    expect(Order::whereIn('hub_order_id', [9301, 9303])->count())->toBe(2)
        ->and(Order::where('hub_order_id', 9302)->exists())->toBeFalse()
        ->and(SyncRun::where('type', 'backfill_orders')->latest()->first()->stats)
        ->toMatchArray(['imported' => 2, 'failed' => 1, 'failed_ids' => [9302]]);
});

function fakeProduct(int $id): array
{
    return [
        'id' => $id, 'name' => "Product {$id}", 'status' => 'publish', 'sku' => "SKU-{$id}",
        'global_unique_id' => null, 'parent_id' => 0, 'price' => 100000.0, 'regular_price' => 100000.0,
        'sale_price' => null, 'stock_quantity' => 5.0, 'stock_status' => 'instock',
        'date_modified' => '2026-07-07T10:00:00', 'variations' => [],
    ];
}

it('acc:sync:backfill-products imports only products missing locally, skipping ones already synced', function () {
    Http::fake(function ($request) {
        if (preg_match('#/products/(\d+)/variations#', $request->url())) {
            return Http::response(['data' => []]);
        }
        if (preg_match('#/products/(\d+)$#', $request->url(), $m)) {
            return Http::response(['data' => fakeProduct((int) $m[1])]);
        }

        return Http::response(['data' => [['id' => 8001], ['id' => 8002], ['id' => 8003]], 'pagination' => ['total' => 3]]);
    });

    app(ProductSyncer::class)->sync(8001, 'manual', 'corr-existing');

    $this->artisan('acc:sync:backfill-products')->assertSuccessful();

    expect(ProductMirror::count())->toBe(3)
        ->and(ProductMirror::whereIn('hub_product_id', [8002, 8003])->count())->toBe(2);

    // Product 8001 was only ever fetched once — during setup, before the
    // backfill ran — proving the backfill itself skipped it as already synced.
    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/products/8001') && ! str_contains($request->url(), 'variations')))
        ->toHaveCount(1);
});

it('acc:sync:backfill-products --dry-run reports counts without importing', function () {
    Http::fake([
        'hub.test/api/v1/products*' => Http::response(['data' => [['id' => 8101], ['id' => 8102]], 'pagination' => ['total' => 2]]),
    ]);

    $this->artisan('acc:sync:backfill-products --dry-run --json')
        ->expectsOutputToContain('missing')
        ->assertSuccessful();

    expect(ProductMirror::count())->toBe(0);
});

it('acc:sync:backfill-products --limit caps how many products are imported this run', function () {
    Http::fake(function ($request) {
        if (preg_match('#/products/(\d+)/variations#', $request->url())) {
            return Http::response(['data' => []]);
        }
        if (preg_match('#/products/(\d+)$#', $request->url(), $m)) {
            return Http::response(['data' => fakeProduct((int) $m[1])]);
        }

        return Http::response(['data' => [['id' => 8201], ['id' => 8202], ['id' => 8203]], 'pagination' => ['total' => 3]]);
    });

    $this->artisan('acc:sync:backfill-products --limit=1')->assertSuccessful();

    expect(ProductMirror::count())->toBe(1);
});

it('acc:sync:backfill-products keeps going past a single failed product and records it', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/products/8302')) {
            return Http::response(['error' => 'boom'], 500);
        }
        if (preg_match('#/products/(\d+)/variations#', $request->url())) {
            return Http::response(['data' => []]);
        }
        if (preg_match('#/products/(\d+)$#', $request->url(), $m)) {
            return Http::response(['data' => fakeProduct((int) $m[1])]);
        }

        return Http::response(['data' => [['id' => 8301], ['id' => 8302], ['id' => 8303]], 'pagination' => ['total' => 3]]);
    });

    $this->artisan('acc:sync:backfill-products')->assertFailed();

    expect(ProductMirror::whereIn('hub_product_id', [8301, 8303])->count())->toBe(2)
        ->and(ProductMirror::where('hub_product_id', 8302)->exists())->toBeFalse()
        ->and(SyncRun::where('type', 'backfill_products')->latest()->first()->stats)
        ->toMatchArray(['imported' => 2, 'failed' => 1, 'failed_ids' => [8302]]);
});

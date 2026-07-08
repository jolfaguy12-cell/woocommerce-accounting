<?php

use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Models\ProductPriceHistory;
use App\Domain\Products\Models\ProductStockHistory;
use App\Domain\Products\Services\ProductSyncer;
use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\WebhookProcessor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['hub.base_url' => 'https://hub.test/api/v1', 'hub.api_key' => 'k', 'hub.webhook_max_attempts' => 3]);
});

function fakeSimpleProduct(int $id = 100, float $price = 375000.0, ?float $stock = 12.0): array
{
    return [
        'id' => $id, 'name' => 'پک رژ لب مخملی', 'type' => 'product', 'status' => 'publish',
        'sku' => 'SKU-100', 'global_unique_id' => '6972437472948', 'parent_id' => 0,
        'price' => $price, 'regular_price' => 400000.0, 'sale_price' => $price,
        'stock_quantity' => $stock, 'stock_status' => 'instock',
        'date_modified' => '2026-07-07T19:15:45', 'variations' => [],
    ];
}

it('mirrors a simple product with integer Toman prices', function () {
    Http::fake([
        'hub.test/api/v1/products/100' => Http::response(['data' => fakeSimpleProduct()]),
        'hub.test/api/v1/products/100/variations' => Http::response(['data' => []]),
    ]);

    $mirror = app(ProductSyncer::class)->sync(100, 'manual', 'corr-1');

    expect($mirror->hub_product_id)->toBe(100)
        ->and($mirror->type)->toBe('simple')
        ->and($mirror->price)->toBe(375000)
        ->and($mirror->stock_quantity)->toBe(12)
        ->and($mirror->gtin)->toBe('6972437472948')
        ->and(ProductMirror::count())->toBe(1);
});

it('mirrors a variable product together with its variations', function () {
    $parent = fakeSimpleProduct(200) + [];
    $parent['variations'] = [201, 202];

    Http::fake([
        'hub.test/api/v1/products/200' => Http::response(['data' => $parent]),
        'hub.test/api/v1/products/200/variations' => Http::response(['data' => [
            ['id' => 201, 'parent_id' => 200, 'name' => 'عطر - صورتی', 'price' => 210000.0,
                'regular_price' => 250000.0, 'sale_price' => 210000.0, 'sku' => null,
                'stock_quantity' => 4.0, 'stock_status' => 'instock', 'status' => 'publish',
                'date_modified' => '2026-07-05T21:25:48'],
            ['id' => 202, 'parent_id' => 200, 'name' => 'عطر - آبی', 'price' => 210000.0,
                'regular_price' => 250000.0, 'sale_price' => null, 'sku' => null,
                'stock_quantity' => 0.0, 'stock_status' => 'outofstock', 'status' => 'publish',
                'date_modified' => '2026-07-05T21:25:48'],
        ]]),
    ]);

    app(ProductSyncer::class)->sync(200, 'manual', 'corr-2');

    expect(ProductMirror::count())->toBe(3)
        ->and(ProductMirror::firstWhere('hub_product_id', 200)->type)->toBe('variable')
        ->and(ProductMirror::firstWhere('hub_product_id', 201)->type)->toBe('variation')
        ->and(ProductMirror::firstWhere('hub_product_id', 201)->parent_hub_id)->toBe(200)
        ->and(ProductMirror::firstWhere('hub_product_id', 202)->stock_quantity)->toBe(0);
});

it('writes price and stock history rows with correlation id on change only', function () {
    Http::fake([
        'hub.test/api/v1/products/100' => Http::sequence()
            ->push(['data' => fakeSimpleProduct(100, 375000.0, 12.0)])
            ->push(['data' => fakeSimpleProduct(100, 375000.0, 12.0)]) // unchanged
            ->push(['data' => fakeSimpleProduct(100, 399000.0, 7.0)]), // price+stock change
        'hub.test/api/v1/products/100/variations' => Http::response(['data' => []]),
    ]);

    $syncer = app(ProductSyncer::class);
    $syncer->sync(100, 'manual', 'c1'); // initial — history for creation not required
    $syncer->sync(100, 'manual', 'c2'); // no change → no history
    $syncer->sync(100, 'poll', 'c3');   // change → two history rows

    expect(ProductPriceHistory::count())->toBe(1)
        ->and(ProductPriceHistory::first())
        ->old_price->toBe(375000)
        ->new_price->toBe(399000)
        ->source->toBe('poll')
        ->correlation_id->toBe('c3')
        ->and(ProductStockHistory::count())->toBe(1)
        ->and(ProductStockHistory::first())
        ->old_quantity->toBe(12)
        ->new_quantity->toBe(7);
});

it('consumes product.upserted webhooks end to end', function () {
    Http::fake([
        'hub.test/api/v1/products/100' => Http::response(['data' => fakeSimpleProduct()]),
        'hub.test/api/v1/products/100/variations' => Http::response(['data' => []]),
    ]);

    $event = WebhookEvent::create([
        'event_uuid' => 'pe-1', 'event_type' => 'product.upserted',
        'payload' => ['product_id' => 100], 'status' => 'received', 'correlation_id' => 'wh-corr',
    ]);

    app(WebhookProcessor::class)->process($event);

    expect($event->refresh()->status)->toBe('done')
        ->and(ProductMirror::firstWhere('hub_product_id', 100))->not->toBeNull();
});

it('acc:sync:product mirrors one product from the CLI', function () {
    Http::fake([
        'hub.test/api/v1/products/100' => Http::response(['data' => fakeSimpleProduct()]),
        'hub.test/api/v1/products/100/variations' => Http::response(['data' => []]),
    ]);

    $this->artisan('acc:sync:product 100')->assertSuccessful();

    expect(ProductMirror::count())->toBe(1);
});

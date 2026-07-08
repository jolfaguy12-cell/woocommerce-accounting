<?php

use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\WebhookEvent;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
    config(['hub.base_url' => 'https://hub.test/api/v1', 'hub.api_key' => 'k', 'hub.webhook_max_attempts' => 3]);
});

function cliOrder(int $id): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 771000,
        'discount_total' => 0, 'shipping_total' => 90000, 'created_via' => 'checkout',
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ];
}

it('acc:inspect:order explains a stored order with its profit breakdown', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    CostHistory::create(['cost_item_id' => $item->id, 'unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    app(OrderIngestPipeline::class)->ingest(4001, cliOrder(4001), 'manual');

    expect(Artisan::call('acc:inspect:order 4001 --json'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('"operational_profit"')
        ->toContain('"cost_breakdown"')
        ->toContain('"shipping_basis"');
});

it('acc:inspect:sources lists discovered sources with mapping status', function () {
    app(OrderIngestPipeline::class)->ingest(4002, cliOrder(4002) + ['order_source' => 'gemini', 'created_via' => null], 'manual');

    expect(Artisan::call('acc:inspect:sources --json'))->toBe(0);

    expect(Artisan::output())
        ->toContain('gemini')
        ->toContain('unknown');

    expect(ChannelSource::firstWhere('raw_value', 'gemini'))->not->toBeNull();
});

it('acc:sync:errors lists dead events and retries them on demand', function () {
    Http::fake(['hub.test/api/v1/orders/4003' => Http::response(cliOrder(4003))]);

    $event = WebhookEvent::create([
        'event_uuid' => 'dead-1', 'event_type' => 'order.upserted',
        'payload' => ['order_id' => 4003], 'status' => 'dead', 'attempts' => 3,
        'last_error' => 'connection timeout',
    ]);

    $this->artisan('acc:sync:errors --json')
        ->expectsOutputToContain('dead-1')
        ->assertSuccessful();

    $this->artisan('acc:sync:errors --retry')->assertSuccessful();

    expect($event->refresh()->status)->toBe('done');
});

it('acc:health reports overall system status', function () {
    Http::fake(['hub.test/api/v1/health' => Http::response(['status' => 'ok'])]);

    expect(Artisan::call('acc:health --json'))->toBe(0);

    expect(Artisan::output())
        ->toContain('"database":true')
        ->toContain('"hub":true');
});

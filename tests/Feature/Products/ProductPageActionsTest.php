<?php

use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, ChannelSeeder::class, CostCenterSeeder::class, ExpenseCategorySeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
});

it('stores a product note with an optional multiplier', function () {
    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/notes", [
        'title' => 'بسته سه‌تایی',
        'body' => 'هر بسته شامل سه عدد است.',
        'multiplier' => 3,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->mirror->notes()->count())->toBe(1)
        ->and((float) $this->mirror->notes()->first()->multiplier)->toBe(3.0);

    // Warehouse users can view products but never mutate financial data.
    $this->actingAs($this->warehouse)->post("/products/{$this->mirror->id}/notes", [
        'title' => 'x',
    ])->assertForbidden();
});

it('records a manual cost and unblocks orders for the product', function () {
    $item = CostItem::create(['name' => 'اسپری']);
    ProductCostMapping::create([
        'product_mirror_id' => $this->mirror->id,
        'cost_item_id' => $item->id,
        'multiplier' => 1,
        'status' => 'mapped',
    ]);

    app(OrderIngestPipeline::class)->ingest(6001, [
        'id' => 6001, 'status' => 'completed', 'currency' => 'IRT', 'total' => 771000,
        'discount_total' => 0, 'shipping_total' => 90000, 'created_via' => 'checkout',
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00', 'meta' => [],
        'line_items' => [['id' => 61, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ], 'manual');

    expect(Order::firstWhere('hub_order_id', 6001)->profit_status)->toBe('blocked_missing_cost');

    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/cost", [
        'unit_cost' => 400_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($item->costHistory()->where('source', 'manual')->count())->toBe(1)
        ->and(Order::firstWhere('hub_order_id', 6001)->refresh()->profit_status)->toBe('ok');
});

it('rejects a manual cost when the product has no mapping', function () {
    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/cost", [
        'unit_cost' => 400_000,
    ])->assertSessionHasErrors('unit_cost');
});

it('renders the product detail page with notes, purchases and sync info', function () {
    $item = CostItem::create(['name' => 'اسپری']);
    $item->costHistory()->create(['unit_cost' => 400_000, 'landed_unit_cost' => 410_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create([
        'product_mirror_id' => $this->mirror->id,
        'cost_item_id' => $item->id,
        'multiplier' => 1,
        'status' => 'mapped',
    ]);
    $this->mirror->notes()->create(['title' => 'یادداشت', 'body' => 'متن', 'multiplier' => 2]);

    $this->actingAs($this->admin)->get("/products/{$this->mirror->id}")
        ->assertOk()
        ->assertViewIs('pages.products.show')
        ->assertViewHas('product', function ($product) {
            return count($product['notes']) === 1
                && $product['purchase_history']->count() === 1
                && $product['sync'] !== null;
        });
});

it('refreshes the mirror from the hub on demand', function () {
    $this->mock(ProductSyncer::class)
        ->shouldReceive('sync')->once()
        ->with(5732, 'manual', Mockery::type('string'))
        ->andReturn($this->mirror);

    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/sync")
        ->assertRedirect()->assertSessionHasNoErrors();
});

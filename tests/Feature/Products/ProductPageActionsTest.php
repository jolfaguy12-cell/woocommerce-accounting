<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\WholesalePrice;
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

it('silently creates a 1:1 cost item/mapping the first time a cost is registered for an unmapped product', function () {
    expect($this->mirror->costMapping)->toBeNull();

    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/cost", [
        'unit_cost' => 400_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $mapping = $this->mirror->fresh()->costMapping;

    expect($mapping)->not->toBeNull()
        ->and($mapping->status)->toBe('mapped')
        ->and((float) $mapping->multiplier)->toBe(1.0)
        ->and($mapping->costItem->name)->toBe($this->mirror->name)
        ->and($mapping->costItem->costHistory()->where('source', 'manual')->count())->toBe(1);
});

it('silently creates a cost item/mapping when a wholesale price is set for an unmapped product', function () {
    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/wholesale", [
        'price' => 500_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->mirror->fresh()->costMapping)->not->toBeNull();
});

it('cascades a wholesale price set on a variable product to all its current variations', function () {
    $parent = ProductMirror::create(['hub_product_id' => 9001, 'type' => 'variable', 'name' => 'کفش مدل X', 'payload' => []]);
    $variantA = ProductMirror::create(['hub_product_id' => 9002, 'parent_hub_id' => 9001, 'type' => 'variation', 'name' => 'کفش مدل X - سایز 40', 'payload' => []]);
    $variantB = ProductMirror::create(['hub_product_id' => 9003, 'parent_hub_id' => 9001, 'type' => 'variation', 'name' => 'کفش مدل X - سایز 41', 'payload' => []]);

    expect($parent->fresh()->sold_as_set)->toBeTrue();

    $this->actingAs($this->admin)->post("/products/{$parent->id}/wholesale", [
        'price' => 900_000,
        'sold_as_set' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$parent, $variantA, $variantB] as $product) {
        $mapping = $product->fresh()->costMapping;
        expect($mapping)->not->toBeNull()
            ->and($mapping->costItem->latestWholesalePrice()->price)->toBe(900_000);
    }
});

it('turns off sold_as_set on a variable product when the checkbox is left unticked', function () {
    $parent = ProductMirror::create(['hub_product_id' => 9101, 'type' => 'variable', 'name' => 'کفش مدل Y', 'payload' => []]);

    $this->actingAs($this->admin)->post("/products/{$parent->id}/wholesale", [
        'price' => 700_000,
    ])->assertRedirect();

    expect($parent->fresh()->sold_as_set)->toBeFalse();
});

it('does not cascade a wholesale price for a simple product or a lone variation', function () {
    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/wholesale", [
        'price' => 300_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(WholesalePrice::count())->toBe(1);
});

it('cascades a manual cost entered on a variable product to all its current variations', function () {
    $parent = ProductMirror::create(['hub_product_id' => 9201, 'type' => 'variable', 'name' => 'کیف مدل Z', 'payload' => []]);
    $variantA = ProductMirror::create(['hub_product_id' => 9202, 'parent_hub_id' => 9201, 'type' => 'variation', 'name' => 'کیف مدل Z - قرمز', 'payload' => []]);
    $variantB = ProductMirror::create(['hub_product_id' => 9203, 'parent_hub_id' => 9201, 'type' => 'variation', 'name' => 'کیف مدل Z - مشکی', 'payload' => []]);

    $this->actingAs($this->admin)->post("/products/{$parent->id}/cost", [
        'unit_cost' => 350_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$parent, $variantA, $variantB] as $product) {
        $mapping = $product->fresh()->costMapping;
        expect($mapping)->not->toBeNull()
            ->and($mapping->costItem->latestCost()->unit_cost)->toBe(350_000);
    }
});

it('registers cost and wholesale price in one click from the order page and unblocks the order', function () {
    app(OrderIngestPipeline::class)->ingest(6101, [
        'id' => 6101, 'status' => 'completed', 'currency' => 'IRT', 'total' => 771000,
        'discount_total' => 0, 'shipping_total' => 90000, 'created_via' => 'checkout',
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00', 'meta' => [],
        'line_items' => [['id' => 71, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ], 'manual');

    expect(Order::firstWhere('hub_order_id', 6101)->profit_status)->toBe('blocked_missing_cost');

    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/quick-cost", [
        'unit_cost' => 350_000,
        'wholesale_price' => 500_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $mapping = $this->mirror->fresh()->costMapping;

    expect($mapping->costItem->latestCost()->unit_cost)->toBe(350_000)
        ->and($mapping->costItem->latestWholesalePrice()->price)->toBe(500_000)
        ->and(Order::firstWhere('hub_order_id', 6101)->refresh()->profit_status)->toBe('ok');
});

it('cascades a quick cost registered on an ordered variation to the parent and all sibling variations', function () {
    $parent = ProductMirror::create(['hub_product_id' => 9301, 'type' => 'variable', 'name' => 'کوله مدل W', 'payload' => []]);
    $variantA = ProductMirror::create(['hub_product_id' => 9302, 'parent_hub_id' => 9301, 'type' => 'variation', 'name' => 'کوله مدل W - آبی', 'payload' => []]);
    $variantB = ProductMirror::create(['hub_product_id' => 9303, 'parent_hub_id' => 9301, 'type' => 'variation', 'name' => 'کوله مدل W - قرمز', 'payload' => []]);

    app(OrderIngestPipeline::class)->ingest(6102, [
        'id' => 6102, 'status' => 'completed', 'currency' => 'IRT', 'total' => 600000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00', 'meta' => [],
        'line_items' => [['id' => 72, 'name' => 'کوله مدل W - آبی', 'quantity' => 1, 'subtotal' => 600000, 'total' => 600000, 'product_id' => 9301, 'variation_id' => 9302]],
    ], 'manual');

    $this->actingAs($this->admin)->post("/products/{$variantA->id}/quick-cost", [
        'unit_cost' => 250_000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$parent, $variantA, $variantB] as $product) {
        $mapping = $product->fresh()->costMapping;
        expect($mapping)->not->toBeNull()
            ->and($mapping->costItem->latestCost()->unit_cost)->toBe(250_000);
    }

    expect(Order::firstWhere('hub_order_id', 6102)->refresh()->profit_status)->toBe('ok');
});

it('never creates a supplier, purchase invoice, or journal entry from the cost-entry form (profit discovery only)', function () {
    $item = CostItem::create(['name' => 'اسپری']);
    ProductCostMapping::create([
        'product_mirror_id' => $this->mirror->id,
        'cost_item_id' => $item->id,
        'multiplier' => 1,
        'status' => 'mapped',
    ]);
    $partiesBefore = Party::count();
    $journalEntriesBefore = JournalEntry::count();

    $this->actingAs($this->admin)->post("/products/{$this->mirror->id}/cost", [
        'unit_cost' => 400_000,
        'qty' => 5,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $history = $item->costHistory()->where('source', 'manual')->first();

    expect(PurchaseInvoice::count())->toBe(0)
        ->and(Party::count())->toBe($partiesBefore)
        ->and(JournalEntry::count())->toBe($journalEntriesBefore)
        ->and($history->qty)->toBe(5)
        ->and($history->unit_cost)->toBe(400_000);
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

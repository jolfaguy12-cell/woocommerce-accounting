<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Products\Models\ProductMirror;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری رکسونا']);
    $this->lipstick = CostItem::create(['name' => 'رژ لب Von Gee']);
});

it('allocates purchase shipping by quantity by default and computes landed unit cost', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 300_000,
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 20, 'unit_price' => 500_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 10, 'unit_price' => 200_000],
        ],
    ]);

    $spray = $invoice->lines->firstWhere('cost_item_id', $this->spray->id);
    $lipstick = $invoice->lines->firstWhere('cost_item_id', $this->lipstick->id);

    // 300000 / 30 units = 10000 per unit
    expect($spray->shipping_allocated)->toBe(200_000)
        ->and($spray->landed_unit_cost)->toBe(510_000)
        ->and($lipstick->shipping_allocated)->toBe(100_000)
        ->and($lipstick->landed_unit_cost)->toBe(210_000);
});

it('honours manual shipping allocation overrides', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 300_000,
        'shipping_allocation' => 'manual',
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 20, 'unit_price' => 500_000, 'shipping_allocated' => 250_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 10, 'unit_price' => 200_000, 'shipping_allocated' => 50_000],
        ],
    ]);

    expect($invoice->lines->firstWhere('cost_item_id', $this->spray->id)->landed_unit_cost)->toBe(512_500)
        ->and($invoice->lines->firstWhere('cost_item_id', $this->lipstick->id)->landed_unit_cost)->toBe(205_000);
});

it('writes cost history and posts a balanced journal when the invoice is received', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);

    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    $invoice->refresh();

    expect($invoice->status)->toBe('received')
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1)
        ->and(CostHistory::first()->landed_unit_cost)->toBe(480_000)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(4_800_000)
        ->and($invoice->journalEntry->lines->firstWhere('credit', '>', 0)->party_id)->toBe($this->supplier->id);

    // receiving again must not double anything
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    expect(CostHistory::count())->toBe(1);
});

it('supports partial delivery and marks the invoice partial', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);

    $service->receive($invoice, [$invoice->lines->first()->id => 4]);

    expect($invoice->refresh()->status)->toBe('partial')
        ->and($invoice->lines->first()->received_qty)->toBe(4);
});

it('reallocates shipping across lines when a draft invoice is edited (no journal yet)', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);

    // Shipping cost is often only known a day or two later.
    $service->update($invoice, ['shipping_cost' => 100_000]);

    expect($invoice->refresh()->lines->first()->shipping_allocated)->toBe(100_000)
        ->and($invoice->lines->first()->landed_unit_cost)->toBe(490_000)
        ->and($invoice->journal_entry_id)->toBeNull(); // still draft, nothing posted yet
});

it('reverses and reposts the journal with a corrected total when an already-received invoice is edited', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    $originalEntry = $invoice->refresh()->journalEntry;

    expect($originalEntry->lines->sum('debit'))->toBe(4_800_000);

    // Shipping cost arrives late and gets corrected after the invoice was fully received.
    $service->update($invoice, ['shipping_cost' => 200_000]);
    $invoice->refresh();

    expect($originalEntry->refresh()->status)->toBe('reversed')
        ->and($invoice->journal_entry_id)->not->toBe($originalEntry->id)
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(5_000_000)
        ->and($invoice->lines->first()->landed_unit_cost)->toBe(500_000)
        // Non-destructive correction: an extra cost_history row for the new landed cost,
        // the original manual-receipt row is untouched.
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(2)
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->latest('id')->first()->landed_unit_cost)->toBe(500_000);
});

it('cascades landed cost to every variation when a line is purchased against a variable parent product', function () {
    $parent = ProductMirror::create(['hub_product_id' => 9001, 'type' => 'variable', 'name' => 'کفش مدل X', 'payload' => []]);
    $variantA = ProductMirror::create(['hub_product_id' => 9002, 'parent_hub_id' => 9001, 'type' => 'variation', 'name' => 'کفش مدل X - سایز 40', 'payload' => []]);
    $variantB = ProductMirror::create(['hub_product_id' => 9003, 'parent_hub_id' => 9001, 'type' => 'variation', 'name' => 'کفش مدل X - سایز 41', 'payload' => []]);

    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'product_mirror_id' => $parent->id, 'qty' => 5, 'unit_price' => 300_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 5]);

    foreach ([$variantA, $variantB] as $variant) {
        $mapping = $variant->fresh()->costMapping;
        expect($mapping)->not->toBeNull()
            ->and($mapping->costItem->latestCost()->landed_unit_cost)->toBe(300_000);
    }

    // The invoice total/journal must only reflect what was actually purchased —
    // no extra qty or payable was invented for the variations.
    expect($invoice->journalEntry->lines->sum('debit'))->toBe(1_500_000);
});

it('increases the qty of a received line, recalculating its landed cost and journal', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 100_000,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    $service->receive($invoice, [$line->id => 10]);

    $service->update($invoice, ['lines' => [['id' => $line->id, 'qty' => 20, 'unit_price' => 500_000]]]);

    $invoice->refresh();
    expect($invoice->lines->first()->qty)->toBe(20)
        // shipping (100_000) now spread over 20 units instead of 10
        ->and($invoice->lines->first()->shipping_allocated)->toBe(100_000)
        ->and($invoice->lines->first()->landed_unit_cost)->toBe(505_000)
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(10_100_000);
});

it('throws when reducing a received line below its received qty', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);
    $line = $invoice->lines->first();
    $service->receive($invoice, [$line->id => 10]);

    $service->update($invoice, ['lines' => [['id' => $line->id, 'qty' => 5, 'unit_price' => 480_000]]]);
})->throws(InvalidArgumentException::class);

it('throws when removing an already-received line', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);
    $line = $invoice->lines->first();
    $service->receive($invoice, [$line->id => 10]);

    $service->update($invoice, ['lines' => [['id' => $line->id, '_remove' => true]]]);
})->throws(InvalidArgumentException::class);

it('freely removes an unreceived line on a partially-received invoice and reallocates shipping', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 300_000,
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 20, 'unit_price' => 100_000],
        ],
    ]);
    $sprayLine = $invoice->lines->firstWhere('cost_item_id', $this->spray->id);
    $lipstickLine = $invoice->lines->firstWhere('cost_item_id', $this->lipstick->id);
    $service->receive($invoice, [$sprayLine->id => 10, $lipstickLine->id => 0]);

    $service->update($invoice, ['lines' => [
        ['id' => $sprayLine->id, 'unit_price' => 480_000],
        ['id' => $lipstickLine->id, '_remove' => true],
    ]]);

    $invoice->refresh();
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->shipping_allocated)->toBe(300_000);
});

it('throws when an update would leave the invoice with zero lines', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);
    $line = $invoice->lines->first();

    $service->update($invoice, ['lines' => [['id' => $line->id, '_remove' => true]]]);
})->throws(InvalidArgumentException::class);

it('adds a new line to an already-received invoice, reversing and reposting the journal with the new total', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);
    $line = $invoice->lines->first();
    $service->receive($invoice, [$line->id => 10]);
    $originalEntry = $invoice->refresh()->journalEntry;

    $service->update($invoice, ['lines' => [
        ['id' => $line->id, 'unit_price' => 480_000],
        ['cost_item_id' => $this->lipstick->id, 'qty' => 5, 'unit_price' => 100_000],
    ]]);

    $invoice->refresh();
    expect($originalEntry->refresh()->status)->toBe('reversed')
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(5_300_000);
});

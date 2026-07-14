<?php

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Products\Models\ProductMirror;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
    $this->lipstick = CostItem::create(['name' => 'رژ لب']);
});

it('saves a new purchase as a draft by default — no journal, no cost history yet', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'invoice_no' => 'INV-1',
        'shipping_cost' => 100_000,
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 5, 'unit_price' => 200_000],
        ],
        'action' => 'draft',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $invoice = PurchaseInvoice::firstWhere('invoice_no', 'INV-1');

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->journal_entry_id)->toBeNull()
        ->and(CostHistory::count())->toBe(0);

    // Warehouse users can view products/orders but never mutate financial data.
    $this->actingAs($this->warehouse)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 1]],
    ])->assertForbidden();
});

it('finalizes immediately when action=finalize, posting the journal and cost history', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'invoice_no' => 'INV-2',
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
        'action' => 'finalize',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $invoice = PurchaseInvoice::firstWhere('invoice_no', 'INV-2');

    expect($invoice->status)->toBe('received')
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1);
});

it('finalizes a previously saved draft from its own finalize action', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 4, 'unit_price' => 250_000]],
    ]);

    expect($invoice->status)->toBe('draft');

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoice->id}/finalize")
        ->assertRedirect(route('purchases.show', $invoice));

    expect($invoice->refresh()->status)->toBe('received')
        ->and($invoice->journal_entry_id)->not->toBeNull();
});

it('quick-creates a supplier from the purchase form when new_supplier_name is given', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => '__new__',
        'new_supplier_name' => 'تامین‌کننده جدید',
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 2, 'unit_price' => 100_000]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $supplier = Party::withRole('supplier')->firstWhere('name', 'تامین‌کننده جدید');

    expect($supplier)->not->toBeNull()
        ->and(PurchaseInvoice::where('supplier_party_id', $supplier->id)->count())->toBe(1);
});

it('quick-creates a cost item from a line when new_item_name is given instead of an id', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [
            ['new_item_name' => 'روژ لب جدید', 'qty' => 3, 'unit_price' => 150_000, 'note' => 'رنگ قرمز'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item = CostItem::firstWhere('name', 'روژ لب جدید');
    $invoice = PurchaseInvoice::latest('id')->first();

    expect($item)->not->toBeNull()
        ->and($invoice->lines->first()->cost_item_id)->toBe($item->id)
        ->and($invoice->lines->first()->note)->toBe('رنگ قرمز');
});

it('resolves a line to a searched product, auto-mapping it if unmapped', function () {
    $product = ProductMirror::create(['hub_product_id' => 7001, 'type' => 'simple', 'name' => 'کرم ضدآفتاب', 'payload' => []]);

    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [['product_mirror_id' => $product->id, 'qty' => 6, 'unit_price' => 220_000]],
        'action' => 'finalize',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $mapping = $product->fresh()->costMapping;
    $invoice = PurchaseInvoice::latest('id')->first();

    expect($mapping)->not->toBeNull()
        ->and($invoice->lines->first()->cost_item_id)->toBe($mapping->cost_item_id)
        ->and($invoice->lines->first()->product_mirror_id)->toBe($product->id);
});

it('cascades landed cost to variations when finalizing a draft line bought for a variable parent', function () {
    $parent = ProductMirror::create(['hub_product_id' => 8001, 'type' => 'variable', 'name' => 'کفش X', 'payload' => []]);
    $variant = ProductMirror::create(['hub_product_id' => 8002, 'parent_hub_id' => 8001, 'type' => 'variation', 'name' => 'کفش X - 40', 'payload' => []]);

    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [['product_mirror_id' => $parent->id, 'qty' => 3, 'unit_price' => 400_000]],
        'action' => 'draft',
    ]);
    $invoice = PurchaseInvoice::latest('id')->first();

    // Not yet finalized: no cascade should have happened.
    expect($variant->fresh()->costMapping)->toBeNull();

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoice->id}/finalize");

    $variantMapping = $variant->fresh()->costMapping;
    expect($variantMapping)->not->toBeNull()
        ->and($variantMapping->costItem->latestCost()->landed_unit_cost)->toBe(400_000);
});

it('attaches multiple uploaded images to the purchase invoice', function () {
    Storage::fake('local');

    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
        'images' => [UploadedFile::fake()->image('invoice.jpg'), UploadedFile::fake()->image('invoice2.jpg')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $invoice = PurchaseInvoice::latest('id')->first();

    expect($invoice->attachments)->toHaveCount(2)
        ->and($invoice->attachments->pluck('original_name')->all())->toContain('invoice.jpg', 'invoice2.jpg');
    Storage::disk('local')->assertExists($invoice->attachments->first()->path);
});

it('adds an image after creation, then deletes it, via the dedicated image routes', function () {
    Storage::fake('local');
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoice->id}/images", [
        'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ])->assertRedirect();

    expect($invoice->attachments()->count())->toBe(2);

    $attachment = $invoice->attachments()->first();
    $this->actingAs($this->admin)->delete("/new-buy-order/{$invoice->id}/images/{$attachment->id}")
        ->assertRedirect();

    expect($invoice->attachments()->count())->toBe(1);
    Storage::disk('local')->assertMissing($attachment->path);
});

it('refuses to delete an attachment that belongs to a different invoice', function () {
    Storage::fake('local');
    $invoiceA = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    $invoiceB = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoiceA->id}/images", [
        'images' => [UploadedFile::fake()->image('a.jpg')],
    ]);
    $attachment = $invoiceA->attachments()->first();

    $this->actingAs($this->admin)->delete("/new-buy-order/{$invoiceB->id}/images/{$attachment->id}")
        ->assertNotFound();
});

it('rejects a purchase with no line items', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});

it('lists purchase invoices on the index page', function () {
    app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->get('/new-buy-order')
        ->assertOk()
        ->assertViewHas('invoices', fn ($invoices) => $invoices->total() === 1);
});

it('shows an invoice with its per-line purchase price, shipping share, and landed cost', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'shipping_cost' => 50_000,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 5, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->get("/new-buy-order/{$invoice->id}")
        ->assertOk()
        ->assertViewHas('invoice', fn ($i) => $i->lines->first()->shipping_allocated === 50_000
            && $i->lines->first()->landed_unit_cost === 110_000);
});

it('updates shipping cost on a draft, reallocating without posting anything (still no journal)', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", [
        'shipping_cost' => 100_000,
    ])->assertRedirect(route('purchases.show', $invoice));

    expect($invoice->refresh()->lines->first()->landed_unit_cost)->toBe(510_000)
        ->and($invoice->journal_entry_id)->toBeNull();
});

it('reverses and reposts the journal when shipping cost is corrected after the invoice was already received', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    app(PurchaseInvoiceService::class)->receive($invoice, [$invoice->lines->first()->id => 10]);
    $originalEntryId = $invoice->refresh()->journal_entry_id;

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", [
        'shipping_cost' => 200_000,
    ])->assertRedirect();

    $invoice->refresh();
    expect($invoice->journal_entry_id)->not->toBe($originalEntryId)
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(5_200_000);
});

it('searches products by name for the item picker (the one approved AJAX exception)', function () {
    ProductMirror::create(['hub_product_id' => 6001, 'type' => 'simple', 'name' => 'اسپری رکسونا', 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 6002, 'type' => 'simple', 'name' => 'شامپو سر', 'payload' => []]);

    $response = $this->actingAs($this->admin)->get('/new-buy-order/items/search?q='.urlencode('اسپری'))
        ->assertOk()
        ->json();

    expect($response)->toHaveCount(1)
        ->and($response[0]['name'])->toBe('اسپری رکسونا');
});

it('renders the create and edit pages', function () {
    $this->actingAs($this->admin)->get('/new-buy-order/create')
        ->assertOk()->assertViewIs('pages.purchases.create');

    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->get("/new-buy-order/{$invoice->id}/edit")
        ->assertOk()->assertViewIs('pages.purchases.edit');
});

it('increases qty and removes an unreceived line via the update form', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 5, 'unit_price' => 200_000],
        ],
    ]);
    $sprayLine = $invoice->lines->firstWhere('cost_item_id', $this->spray->id);
    $lipstickLine = $invoice->lines->firstWhere('cost_item_id', $this->lipstick->id);

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", [
        'lines' => [
            ['id' => $sprayLine->id, 'qty' => 15, 'unit_price' => 500_000],
            ['id' => $lipstickLine->id, 'qty' => 5, 'unit_price' => 200_000, '_remove' => 1],
        ],
    ])->assertRedirect(route('purchases.show', $invoice))->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->qty)->toBe(15);
});

it('rejects reducing a received line below its received qty via the update form', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    app(PurchaseInvoiceService::class)->receive($invoice, [$line->id => 10]);

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", [
        'lines' => [['id' => $line->id, 'qty' => 5, 'unit_price' => 500_000]],
    ])->assertSessionHasErrors('lines');

    expect($invoice->refresh()->lines->first()->qty)->toBe(10);
});

it('rejects removing an already-received line via the update form', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    app(PurchaseInvoiceService::class)->receive($invoice, [$line->id => 10]);

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", [
        'lines' => [['id' => $line->id, 'unit_price' => 500_000, '_remove' => 1]],
    ])->assertSessionHasErrors('lines');

    expect($invoice->refresh()->lines)->toHaveCount(1);
});

it('surfaces a locked accounting period as a session error when finalizing', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    AccountingPeriod::forDate($invoice->invoice_date)->update(['status' => 'locked']);

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoice->id}/finalize")
        ->assertSessionHasErrors('invoice_date');

    expect($invoice->refresh()->journal_entry_id)->toBeNull();
});

it('surfaces a locked accounting period as a session error when a correction would repost the journal', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    app(PurchaseInvoiceService::class)->receive($invoice, [$invoice->lines->first()->id => 1]);
    $originalEntryId = $invoice->refresh()->journal_entry_id;

    AccountingPeriod::forDate($invoice->invoice_date)->update(['status' => 'locked']);

    $this->actingAs($this->admin)->put("/new-buy-order/{$invoice->id}", ['shipping_cost' => 10_000])
        ->assertSessionHasErrors('invoice_date');

    expect($invoice->refresh()->journal_entry_id)->toBe($originalEntryId);
});

it('links a purchase line to its product page when the line has a product', function () {
    $product = ProductMirror::create(['hub_product_id' => 7101, 'type' => 'simple', 'name' => 'کرم مرطوب‌کننده', 'payload' => []]);
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'product_mirror_id' => $product->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->get("/new-buy-order/{$invoice->id}")
        ->assertOk()
        ->assertSee(route('products.show', $product))
        ->assertSee('target="_blank"', false);
});

<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceReceipt;
use App\Domain\Costing\Models\PurchaseInvoiceReceiptLine;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Costing\Services\PurchaseReturnService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
});

function editInvoice(int $qty = 10): PurchaseInvoice
{
    return app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => test()->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => test()->spray->id, 'qty' => $qty, 'unit_price' => 500_000]],
    ]);
}

it('corrects an already-recorded receipt qty within bounds and logs the reason', function () {
    $invoice = editInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 4]], ['received_at' => '2026-07-02'], $this->admin->id);
    $receiptLine = $receipt->lines->first();

    $service->updateReceiptLine($receiptLine, 6, 'اشتباه شمارش شده بود', $this->admin->id);

    expect($receiptLine->refresh()->qty)->toBe(6)
        ->and($line->refresh()->received_qty)->toBe(6)
        ->and($invoice->refresh()->status)->toBe('partial');

    // Two log rows exist for this line (the initial "created" from recordReceipt, then this "updated") —
    // order by id, not created_at, since both can land in the same second.
    $activity = Activity::where('subject_type', 'purchase_invoice_receipt_line')->where('subject_id', $receiptLine->id)->orderByDesc('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('reason'))->toBe('اشتباه شمارش شده بود')
        ->and($activity->properties->get('attributes')['qty'])->toBe(6);
});

it('rejects a correction that would drop received qty below what has already been returned', function () {
    $invoice = editInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 8]], ['received_at' => '2026-07-02'], $this->admin->id);
    app(PurchaseReturnService::class)->create($invoice->refresh(), [['line_id' => $line->id, 'qty' => 5]], 'خراب', $this->admin->id);

    $service->updateReceiptLine($receipt->lines->first(), 3, 'کاهش نامعتبر', $this->admin->id);
})->throws(InvalidArgumentException::class);

it('rejects a correction that would push received qty above what was ordered', function () {
    $invoice = editInvoice(qty: 10);
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 4]], ['received_at' => '2026-07-02'], $this->admin->id);

    $service->updateReceiptLine($receipt->lines->first(), 999, 'افزایش نامعتبر', $this->admin->id);
})->throws(InvalidArgumentException::class);

it('reverses the posted journal when an edit drops the invoice out of fully-received, and reposts once complete again', function () {
    $invoice = editInvoice(qty: 10);
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 10]], ['received_at' => '2026-07-02'], $this->admin->id);
    $invoice->refresh();
    expect($invoice->status)->toBe('received')->and($invoice->journal_entry_id)->not->toBeNull();
    $firstJournalId = $invoice->journal_entry_id;

    $service->updateReceiptLine($receipt->lines->first(), 7, 'اصلاح به مقدار واقعی', $this->admin->id);

    $invoice->refresh();
    expect($invoice->status)->toBe('partial')
        ->and($invoice->journal_entry_id)->toBeNull();

    $service->updateReceiptLine($receipt->lines->first()->fresh(), 10, 'برگردانده شد', $this->admin->id);

    $invoice->refresh();
    expect($invoice->status)->toBe('received')
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->journal_entry_id)->not->toBe($firstJournalId);
});

it('removes the receipt line entirely when corrected down to zero, deleting an emptied receipt header too', function () {
    $invoice = editInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 4]], ['received_at' => '2026-07-02'], $this->admin->id);
    $receiptLine = $receipt->lines->first();

    $service->updateReceiptLine($receiptLine, 0, 'ثبت اشتباه بود', $this->admin->id);

    expect(PurchaseInvoiceReceiptLine::find($receiptLine->id))->toBeNull()
        ->and(PurchaseInvoiceReceipt::find($receipt->id))->toBeNull()
        ->and($line->refresh()->received_qty)->toBe(0);
});

it('lets admin correct a receipt qty over HTTP with a reason, and rejects a missing reason', function () {
    $invoice = editInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);
    $receipt = $service->recordReceipt($invoice, [$line->id => ['qty' => 4]], ['received_at' => '2026-07-02'], $this->admin->id);
    $receiptLine = $receipt->lines->first();

    $this->actingAs($this->admin)
        ->post(route('purchases.receipt-lines.update', [$invoice, $receiptLine]), ['qty' => 5, 'reason' => 'اصلاح'])
        ->assertRedirect(route('purchases.show', $invoice));

    expect($receiptLine->refresh()->qty)->toBe(5);

    $this->actingAs($this->admin)
        ->post(route('purchases.receipt-lines.update', [$invoice, $receiptLine]), ['qty' => 6])
        ->assertSessionHasErrors('reason');
});

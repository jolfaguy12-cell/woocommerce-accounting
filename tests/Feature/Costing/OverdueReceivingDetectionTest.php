<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Alerts\Models\AlertDelivery;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\OverdueReceivingService;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Sync\Models\ReviewItem;
use App\Models\User;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, AlertTypeSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create(['telegram_id' => '123456'])->assignRole('warehouse');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
});

function overdueInvoice(?string $invoiceDate = null, ?string $expectedDelivery = null): PurchaseInvoice
{
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => test()->supplier->id,
        'invoice_date' => $invoiceDate ?? Carbon::today()->subDays(6)->toDateString(),
        'lines' => [['cost_item_id' => test()->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);

    if ($expectedDelivery) {
        $invoice->update(['expected_delivery_date' => $expectedDelivery]);
    }

    return $invoice;
}

it('is not overdue within the 5-day grace period, and overdue right after it, with no expected_delivery_date', function () {
    $fresh = overdueInvoice(Carbon::today()->subDays(3)->toDateString());
    $old = overdueInvoice(Carbon::today()->subDays(6)->toDateString());

    $overdue = app(OverdueReceivingService::class)->overdueInvoicesQuery()->pluck('id');

    expect($overdue)->not->toContain($fresh->id)->toContain($old->id);
});

it('is overdue based on expected_delivery_date even within the default 5-day grace period', function () {
    $invoice = overdueInvoice(Carbon::today()->subDay()->toDateString(), Carbon::today()->subDay()->toDateString());

    $overdue = app(OverdueReceivingService::class)->overdueInvoicesQuery()->pluck('id');

    expect($overdue)->toContain($invoice->id);
});

it('flags an overdue invoice exactly once, dispatching an alert with in-app + telegram deliveries, and never duplicates on rerun', function () {
    Queue::fake();
    $invoice = overdueInvoice();

    Artisan::call('acc:purchases:detect-overdue-receipts');

    expect(ReviewItem::where('type', 'purchase_receipt_overdue')->where('subject_id', $invoice->id)->where('status', 'open')->count())->toBe(1);

    $deliveries = AlertDelivery::where('user_id', $this->warehouse->id)->get();
    expect($deliveries->where('channel', 'in_app')->count())->toBe(1)
        ->and($deliveries->where('channel', 'telegram')->count())->toBe(1)
        ->and($deliveries->where('channel', 'telegram')->first()->status)->toBe('pending');

    Artisan::call('acc:purchases:detect-overdue-receipts');

    expect(ReviewItem::where('type', 'purchase_receipt_overdue')->where('subject_id', $invoice->id)->count())->toBe(1)
        ->and(AlertDelivery::where('user_id', $this->warehouse->id)->count())->toBe(2);
});

it('auto-resolves the overdue flag and its deliveries once the invoice is fully received', function () {
    Queue::fake();
    $invoice = overdueInvoice();
    Artisan::call('acc:purchases:detect-overdue-receipts');

    $reviewItem = ReviewItem::where('type', 'purchase_receipt_overdue')->where('subject_id', $invoice->id)->first();
    $delivery = AlertDelivery::where('user_id', $this->warehouse->id)->where('channel', 'in_app')->first();
    expect($reviewItem->status)->toBe('open')->and($delivery->resolved_at)->toBeNull();

    app(PurchaseInvoiceService::class)->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $this->admin->id);

    expect($reviewItem->refresh()->status)->toBe('resolved')
        ->and($delivery->refresh()->resolved_at)->not->toBeNull();
});

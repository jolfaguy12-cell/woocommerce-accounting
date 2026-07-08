<?php

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Services\ChannelCostRecorder;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Reports\Exceptions\ReportNotReadyException;
use App\Domain\Reports\Services\PartnerReportService;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);

    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    CostHistory::create(['cost_item_id' => $item->id, 'unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-06-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->service = app(PartnerReportService::class);
});

function reportOrder(int $id, string $date = '2026-07-08T10:00:00', array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT',
        'total' => 771000, 'discount_total' => 10000, 'shipping_total' => 90000,
        'created_via' => 'checkout', 'order_source' => null,
        'date_created' => $date, 'date_modified' => $date, 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 691000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ], $overrides);
}

it('builds period aggregates: sales, profit, expenses, channel costs, balances', function () {
    app(OrderIngestPipeline::class)->ingest(3001, reportOrder(3001), 'manual');

    $category = ExpenseCategory::create(['name' => 'تبلیغات', 'slug' => 'ads', 'account_code' => '6200']);
    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id, 'bank_account_id' => $this->bank->id,
        'amount' => 100_000, 'expense_date' => Carbon::parse('2026-07-08', 'Asia/Tehran'), 'description' => 'کمپین',
    ]);

    app(ChannelCostRecorder::class)->record(
        Channel::firstWhere('slug', 'torob'), 'topup', 200_000,
        Carbon::parse('2026-07-08', 'Asia/Tehran'), $this->bank->id,
    );

    $report = $this->service->build('1405-04');
    $data = $report->readiness['ready'] ? $report->draftData() : $report->draftData();

    expect($data['orders']['count'])->toBe(1)
        ->and($data['orders']['net_sales'])->toBe(681_000)
        ->and($data['orders']['gross_profit'])->toBe(281_000)
        ->and($data['orders']['operational_profit'])->toBe(281_000)
        ->and($data['expenses']['total_affecting_partner'])->toBe(100_000)
        ->and($data['channel_costs']['torob'])->toBe(200_000)
        ->and($data['net_period_profit'])->toBe(281_000 - 100_000 - 200_000)
        ->and($data['balances']['receivables'])->toBe(771_000);
});

it('refuses to finalize while review items are open, unless explicitly acknowledged', function () {
    $orderData = reportOrder(3002);
    $orderData['line_items'][0]['product_id'] = 9999; // missing mapping → review item

    app(OrderIngestPipeline::class)->ingest(3002, $orderData, 'manual');

    $report = $this->service->build('1405-04');
    expect($report->readiness['ready'])->toBeFalse()
        ->and(fn () => $this->service->finalize($report))->toThrow(ReportNotReadyException::class);

    $this->service->finalize($report, acknowledgeWarnings: true);
    expect($report->refresh()->state)->toBe('final');
});

it('finalizing snapshots the report immutably and locks the period', function () {
    // order in a PAST period (1405-03 spans 2026-05-22 → 2026-06-21)
    app(OrderIngestPipeline::class)->ingest(3003, reportOrder(3003, '2026-06-10T10:00:00'), 'manual');

    $report = $this->service->build('1405-03');
    $this->service->finalize($report, acknowledgeWarnings: true);
    $snapshot = $report->refresh()->snapshot;

    expect($report->state)->toBe('final')
        ->and($snapshot['orders']['count'])->toBe(1)
        ->and(AccountingPeriod::firstWhere('jalali_period', '1405-03')->status)->toBe('locked');

    // a late order dated inside the locked period must not corrupt the snapshot
    app(OrderIngestPipeline::class)->ingest(3004, reportOrder(3004, '2026-06-15T10:00:00'), 'manual');

    expect($report->refresh()->snapshot)->toBe($snapshot)
        ->and(Order::firstWhere('hub_order_id', 3004)->profit->journalEntry->jalali_period)
        ->toBe('1405-04'); // late entry lands in the open period, not the locked one
});

it('registers corrections as adjustments without touching the snapshot', function () {
    app(OrderIngestPipeline::class)->ingest(3005, reportOrder(3005, '2026-06-10T10:00:00'), 'manual');

    $report = $this->service->build('1405-03');
    $this->service->finalize($report, acknowledgeWarnings: true);
    $snapshot = $report->refresh()->snapshot;

    $adjustment = $this->service->addAdjustment($report, 'اصلاح هزینه حمل اسفند', [
        ['account' => '5100', 'debit' => 50_000],
        ['account' => '2000', 'credit' => 50_000],
    ]);

    expect($report->refresh()->state)->toBe('adjusted')
        ->and($report->snapshot)->toBe($snapshot)
        ->and($adjustment->journalEntry->jalali_period)->toBe('1405-04'); // current open period
});

it('acc:validate confirms ledger integrity', function () {
    app(OrderIngestPipeline::class)->ingest(3006, reportOrder(3006), 'manual');

    $this->artisan('acc:validate --json')
        ->expectsOutputToContain('"ok":true')
        ->assertSuccessful();
});

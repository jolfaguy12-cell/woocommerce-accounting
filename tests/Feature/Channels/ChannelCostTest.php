<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Services\ChannelCostRecorder;
use App\Domain\Channels\Services\ChannelCostService;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->torob = Channel::firstWhere('slug', 'torob');
});

it('sums wallet top-ups per jalali period as the channel period cost', function () {
    $recorder = app(ChannelCostRecorder::class);

    // 1405-04 spans 2026-06-22 → 2026-07-22
    $recorder->record($this->torob, 'topup', 2_000_000, Carbon::parse('2026-06-25', 'Asia/Tehran'), $this->bank->id);
    $recorder->record($this->torob, 'topup', 1_500_000, Carbon::parse('2026-07-10', 'Asia/Tehran'), $this->bank->id);
    $recorder->record($this->torob, 'topup', 900_000, Carbon::parse('2026-07-25', 'Asia/Tehran'), $this->bank->id); // 1405-05

    $service = app(ChannelCostService::class);

    expect($service->periodCost($this->torob, '1405-04'))->toBe(3_500_000)
        ->and($service->periodCost($this->torob, '1405-05'))->toBe(900_000);
});

it('posts a balanced journal for each top-up crediting the bank', function () {
    $cost = app(ChannelCostRecorder::class)
        ->record($this->torob, 'topup', 2_000_000, Carbon::parse('2026-07-10', 'Asia/Tehran'), $this->bank->id);

    $entry = $cost->journalEntry;

    expect($entry)->not->toBeNull()
        ->and($entry->lines->sum('debit'))->toBe(2_000_000)
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'))
        ->and($entry->lines->firstWhere('credit', '>', 0)->account_id)->toBe($this->bank->account_id);
});

it('aggregates metadata commissions as the period cost of commission channels', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    CostHistory::create(['cost_item_id' => $item->id, 'unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    $base = [
        'status' => 'bslm-completed', 'currency' => 'IRT', 'total' => 771000, 'discount_total' => 0,
        'shipping_total' => 90000, 'order_source' => 'basalam',
        'date_created' => '2026-07-08T16:04:29', 'date_modified' => '2026-07-08T16:04:29',
        'line_items' => [['id' => 1, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ];

    app(OrderIngestPipeline::class)->ingest(2001, $base + ['id' => 2001, 'meta' => ['_basalam_fee_amount' => '-81720']], 'manual');
    $base['line_items'][0]['id'] = 2;
    app(OrderIngestPipeline::class)->ingest(2002, $base + ['id' => 2002, 'meta' => ['_basalam_fee_amount' => '-50000']], 'manual');

    $basalam = Channel::firstWhere('slug', 'basalam');

    expect(app(ChannelCostService::class)->periodCost($basalam, '1405-04'))->toBe(131_720);
});

<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Services\CreditOrderAllocator;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([ChartOfAccountsSeeder::class, ChannelSeeder::class]);
    $this->customer = Party::createWithRole('customer', ['name' => 'مشتری']);
});

function makeCreditOrder(array $attributes): CreditOrder
{
    return CreditOrder::create(['uuid' => (string) Str::uuid(), 'paid_total' => 0] + $attributes);
}

function allocatorTestOrder(int $id, string $date): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'date_created' => $date.'T10:00:00', 'date_modified' => $date.'T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ];
}

it('settles the oldest open order first, spilling the remainder into the next-oldest', function () {
    $older = app(OrderIngestPipeline::class)->ingest(7001, allocatorTestOrder(7001, '2026-07-01'), 'manual');
    $newer = app(OrderIngestPipeline::class)->ingest(7002, allocatorTestOrder(7002, '2026-07-05'), 'manual');

    $olderCredit = makeCreditOrder(['order_id' => $older->id, 'party_id' => $this->customer->id, 'total_due' => 100]);
    $newerCredit = makeCreditOrder(['order_id' => $newer->id, 'party_id' => $this->customer->id, 'total_due' => 300]);

    $result = app(CreditOrderAllocator::class)->apply($this->customer, 200);

    expect($result['applied'])->toBe(200)
        ->and($olderCredit->refresh()->status)->toBe('settled')
        ->and($olderCredit->remaining())->toBe(0)
        ->and($newerCredit->refresh()->status)->toBe('open')
        ->and($newerCredit->remaining())->toBe(200);
});

it('applies only what open balances can absorb, reporting the rest as unapplied', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7003, allocatorTestOrder(7003, '2026-07-01'), 'manual');
    makeCreditOrder(['order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 100]);

    $result = app(CreditOrderAllocator::class)->apply($this->customer, 300);

    expect($result['applied'])->toBe(100);
});

it('ignores credit orders that are already settled', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7004, allocatorTestOrder(7004, '2026-07-01'), 'manual');
    makeCreditOrder(['order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 100, 'paid_total' => 100, 'status' => 'settled']);

    $result = app(CreditOrderAllocator::class)->apply($this->customer, 100);

    expect($result['applied'])->toBe(0)
        ->and($result['lines'])->toBe([]);
});

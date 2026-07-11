<?php

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Reports\Models\MonthlyDashboardSnapshot;
use App\Domain\Reports\Services\DashboardMetricsService;
use Database\Seeders\ChannelSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChannelSeeder::class);
    $this->metrics = app(DashboardMetricsService::class);
});

function dashboardOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 100000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => ['first_name' => 'کاربر', 'last_name' => (string) $id, 'phone' => '0912000'.$id],
        'date_created' => '2026-05-10T10:00:00', 'date_modified' => '2026-05-10T10:00:00',
        'date_paid' => '2026-05-10T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'product_id' => 1, 'variation_id' => null]],
    ], $overrides);
}

it('counts only valid orders toward orders_count and gross_sales, excluding pending and cancelled', function () {
    app(OrderIngestPipeline::class)->ingest(6001, dashboardOrder(6001), 'manual'); // completed -> valid
    app(OrderIngestPipeline::class)->ingest(6002, dashboardOrder(6002, ['status' => 'pending', 'date_paid' => null]), 'manual'); // pending
    app(OrderIngestPipeline::class)->ingest(6003, dashboardOrder(6003, ['status' => 'cancelled', 'date_paid' => null]), 'manual'); // cancelled

    $stats = $this->metrics->monthStats('1405-02');

    expect($stats['orders_count'])->toBe(1)
        ->and($stats['gross_sales'])->toBe(100000);
});

it('counts a customer as "new" only in the period their first valid order fell in', function () {
    app(OrderIngestPipeline::class)->ingest(6101, dashboardOrder(6101, [
        'billing' => ['first_name' => 'یک', 'last_name' => 'مشتری', 'phone' => '09120006101'],
    ]), 'manual');
    // A second valid order for the SAME customer, same month, must not double-count as another new customer.
    app(OrderIngestPipeline::class)->ingest(6102, dashboardOrder(6102, [
        'billing' => ['first_name' => 'یک', 'last_name' => 'مشتری', 'phone' => '09120006101'],
    ]), 'manual');

    expect($this->metrics->monthStats('1405-02')['new_customers'])->toBe(1);
});

it('freezes a closed month into a snapshot instead of recomputing it every time', function () {
    app(OrderIngestPipeline::class)->ingest(6201, dashboardOrder(6201), 'manual');

    $this->travelTo(Carbon::parse('2026-07-10')); // now in a later Jalali month, so 1405-02 is "closed"
    $first = $this->metrics->monthStats('1405-02');
    expect(MonthlyDashboardSnapshot::where('jalali_period', '1405-02')->exists())->toBeTrue();

    // New order added to the same closed month AFTER the snapshot was taken must not change the frozen value.
    app(OrderIngestPipeline::class)->ingest(6202, dashboardOrder(6202), 'manual');
    $second = $this->metrics->monthStats('1405-02');

    expect($second['orders_count'])->toBe($first['orders_count']);

    $this->travelBack();
});

it('never freezes the current (still-open) month — it always reflects live data', function () {
    $this->travelTo(Carbon::parse('2026-05-15'));
    app(OrderIngestPipeline::class)->ingest(6301, dashboardOrder(6301), 'manual');
    $before = $this->metrics->monthStats('1405-02')['orders_count'];

    app(OrderIngestPipeline::class)->ingest(6302, dashboardOrder(6302), 'manual');
    $after = $this->metrics->monthStats('1405-02')['orders_count'];

    expect($after)->toBe($before + 1);

    $this->travelBack();
});

it('records a null stock_count for a month that closed before this feature ever ran, and shows "no comparison" instead of a percentage', function () {
    app(OrderIngestPipeline::class)->ingest(6401, dashboardOrder(6401), 'manual');
    $this->travelTo(Carbon::parse('2026-07-10'));

    $stats = $this->metrics->monthStats('1405-02');
    expect($stats['stock_count'])->toBeNull()
        ->and($this->metrics->percentChange('stock_count', '1405-03'))->toBeNull();

    $this->travelBack();
});

it('reports zero-to-zero as 0% but "no percentage" once there is real growth from zero', function () {
    expect($this->metrics->percentChange('orders_count', '1405-02'))->toBe(0.0); // 0 -> 0

    app(OrderIngestPipeline::class)->ingest(6501, dashboardOrder(6501, ['date_created' => '2026-06-10T10:00:00', 'date_paid' => '2026-06-10T10:05:00']), 'manual');
    expect($this->metrics->percentChange('orders_count', '1405-03'))->toBeNull(); // 0 -> 1, undefined %
});

it('returns 12 months for the yearly chart, with months after the given period at zero', function () {
    $counts = $this->metrics->yearlyOrderCounts('1405-02');

    expect($counts)->toHaveCount(12)
        ->and(array_keys($counts))->toBe(['1405-01', '1405-02', '1405-03', '1405-04', '1405-05', '1405-06', '1405-07', '1405-08', '1405-09', '1405-10', '1405-11', '1405-12'])
        ->and($counts['1405-03'])->toBe(0);
});

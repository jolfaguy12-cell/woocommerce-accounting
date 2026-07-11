<?php

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Reports\Services\PartnerReportService;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, ChannelSeeder::class]);

    $mirror = ProductMirror::create(['hub_product_id' => 6001, 'type' => 'simple', 'name' => 'کیف', 'payload' => []]);
    $item = CostItem::create(['name' => 'کیف']);
    CostHistory::create(['cost_item_id' => $item->id, 'unit_cost' => 200_000, 'landed_unit_cost' => 200_000, 'source' => 'manual', 'effective_at' => '2026-05-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');

    $this->service = app(PartnerReportService::class);
});

function pageReportOrder(int $id, string $date, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT',
        'total' => 561000, 'discount_total' => 10000, 'shipping_total' => 60000,
        'created_via' => 'checkout', 'order_source' => null,
        'date_created' => $date, 'date_modified' => $date, 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کیف', 'quantity' => 1, 'subtotal' => 501000, 'total' => 491000, 'product_id' => 6001, 'variation_id' => null]],
    ], $overrides);
}

it('renders the periods list with state badge and readiness for admin/accountant/partner_viewer', function (string $role) {
    $current = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));

    $this->actingAs($this->{$role})->get('/reports')->assertOk()
        ->assertViewIs('pages.reports.index')
        ->assertViewHas('current_period', $current)
        ->assertViewHas('reports', fn ($rows) => $rows->firstWhere('jalali_period', $current) !== null);
})->with(['admin', 'accountant', 'partner']);

it('blocks the periods list and a period page for a role outside admin/accountant/partner_viewer', function () {
    $current = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));

    $this->actingAs($this->warehouse)->get('/reports')->assertForbidden();
    $this->actingAs($this->warehouse)->get("/reports/{$current}")->assertForbidden();
});

it('shows an open readiness checklist for a draft period with unmapped orders', function () {
    $orderData = pageReportOrder(6101, '2026-07-08T10:00:00');
    $orderData['line_items'][0]['product_id'] = 9999; // unmapped → review item
    app(OrderIngestPipeline::class)->ingest(6101, $orderData, 'manual');

    $current = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));

    $this->actingAs($this->accountant)->get("/reports/{$current}")->assertOk()
        ->assertViewIs('pages.reports.show')
        ->assertViewHas('report', fn ($report) => $report['is_snapshot'] === false
            && $report['readiness']['ready'] === false
            && $report['readiness']['issues'] !== []);
});

it('serves the frozen snapshot for a finalized period instead of live draft data', function () {
    app(OrderIngestPipeline::class)->ingest(6102, pageReportOrder(6102, '2026-06-10T10:00:00'), 'manual');

    $report = $this->service->build('1405-03');
    $this->service->finalize($report, acknowledgeWarnings: true, by: $this->admin->id);

    $this->actingAs($this->partner)->get('/reports/1405-03')->assertOk()
        ->assertViewHas('report', fn ($r) => $r['is_snapshot'] === true
            && $r['state'] === 'final'
            && $r['data']['orders']['count'] === 1);
});

it('shows post-finalization adjustments on the report page', function () {
    app(OrderIngestPipeline::class)->ingest(6103, pageReportOrder(6103, '2026-06-11T10:00:00'), 'manual');

    $report = $this->service->build('1405-03');
    $this->service->finalize($report, acknowledgeWarnings: true, by: $this->admin->id);
    $this->service->addAdjustment($report, 'اصلاح آزمایشی', [
        ['account' => '5100', 'debit' => 10_000],
        ['account' => '2000', 'credit' => 10_000],
    ], $this->admin->id);

    $this->actingAs($this->admin)->get('/reports/1405-03')->assertOk()
        ->assertViewHas('report', fn ($r) => $r['adjustments']->isNotEmpty()
            && $r['adjustments']->first()->description === 'اصلاح آزمایشی');
});

it('lets admin finalize a ready report and locks the accounting period', function () {
    app(OrderIngestPipeline::class)->ingest(6104, pageReportOrder(6104, '2026-06-12T10:00:00'), 'manual');
    $this->service->build('1405-03');

    $this->actingAs($this->admin)->post('/reports/1405-03/finalize')
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(PartnerReport::firstWhere('jalali_period', '1405-03')->state)->toBe('final')
        ->and(AccountingPeriod::firstWhere('jalali_period', '1405-03')->status)->toBe('locked');
});

it('blocks finalize for non-admin roles', function (string $role) {
    app(OrderIngestPipeline::class)->ingest(6105, pageReportOrder(6105, '2026-06-13T10:00:00'), 'manual');
    $this->service->build('1405-03');

    $this->actingAs($this->{$role})->post('/reports/1405-03/finalize')->assertForbidden();
})->with(['accountant', 'partner', 'warehouse']);

it('redirects back with an error when finalizing a not-ready report without acknowledging', function () {
    $orderData = pageReportOrder(6106, '2026-06-14T10:00:00');
    $orderData['line_items'][0]['product_id'] = 9999; // unmapped → review item, not ready
    app(OrderIngestPipeline::class)->ingest(6106, $orderData, 'manual');
    $this->service->build('1405-03');

    $this->actingAs($this->admin)->post('/reports/1405-03/finalize')
        ->assertRedirect()->assertSessionHasErrors('finalize');

    expect(PartnerReport::firstWhere('jalali_period', '1405-03')->state)->not->toBe('final');
});

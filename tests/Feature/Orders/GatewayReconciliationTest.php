<?php

use App\Domain\Alerts\Models\AlertEvent;
use App\Domain\Orders\Models\OrderGatewayCheck;
use App\Domain\Orders\Services\GatewayReconciliationService;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Sync\Models\ReviewItem;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([RoleSeeder::class, AlertTypeSeeder::class, ChannelSeeder::class]);
});

function zibalHubOrder(int $id, string $trackingCode): array
{
    return [
        'id' => $id,
        'status' => 'completed',
        'currency' => 'IRT',
        'total' => 500000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'created_via' => 'checkout',
        'order_source' => null, 'source_channel' => null, 'external_marketplace' => null,
        'payment_method' => 'WC_Zibal',
        'payment_method_title' => 'زیبال',
        'transaction_id' => $trackingCode,
        'date_created' => '2026-07-08T16:04:29',
        'date_modified' => '2026-07-08T16:04:29',
        'date_paid' => '2026-07-08T16:05:00',
        'meta' => [],
        'line_items' => [],
    ];
}

it('opens a review item and dispatches an alert when Zibal reports the transaction as not paid', function () {
    Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'status' => 3, 'amount' => 500000])]);

    $order = app(OrderIngestPipeline::class)->ingest(9001, zibalHubOrder(9001, 'tc-1'), 'manual');
    expect($order->financial_state)->toBe('valid')
        ->and($order->gateway_transaction_id)->toBe('tc-1');

    $check = app(GatewayReconciliationService::class)->checkOrder($order);

    expect($check->mismatch)->toBeTrue();

    expect(ReviewItem::where('type', 'gateway_status_mismatch')
        ->where('subject_type', $order->getMorphClass())
        ->where('subject_id', $order->id)
        ->where('status', 'open')
        ->exists())->toBeTrue();

    expect(AlertEvent::whereHas('alertType', fn ($q) => $q->where('code', 'zibal_gateway_mismatch'))->exists())->toBeTrue();
});

it('does not duplicate the review item or alert on repeated mismatched checks', function () {
    Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'status' => 3, 'amount' => 500000])]);

    $order = app(OrderIngestPipeline::class)->ingest(9002, zibalHubOrder(9002, 'tc-2'), 'manual');
    $service = app(GatewayReconciliationService::class);

    $service->checkOrder($order);
    $service->checkOrder($order);

    expect(ReviewItem::where('type', 'gateway_status_mismatch')->count())->toBe(1)
        ->and(AlertEvent::whereHas('alertType', fn ($q) => $q->where('code', 'zibal_gateway_mismatch'))->count())->toBe(1);
});

it('does not flag a mismatch when Zibal confirms the transaction was paid', function () {
    Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'status' => 1, 'amount' => 500000])]);

    $order = app(OrderIngestPipeline::class)->ingest(9003, zibalHubOrder(9003, 'tc-3'), 'manual');
    $check = app(GatewayReconciliationService::class)->checkOrder($order);

    expect($check->mismatch)->toBeFalse();
    expect(ReviewItem::where('type', 'gateway_status_mismatch')->exists())->toBeFalse();
});

it('does not flag a mismatch when the trackingCode lookup itself fails', function () {
    Http::fake(['gateway.zibal.ir/*' => Http::response(['message' => 'invalid trackId', 'result' => 203])]);

    $order = app(OrderIngestPipeline::class)->ingest(9004, zibalHubOrder(9004, 'tc-4'), 'manual');
    $check = app(GatewayReconciliationService::class)->checkOrder($order);

    expect($check->mismatch)->toBeFalse();
    expect(ReviewItem::where('type', 'gateway_status_mismatch')->exists())->toBeFalse();
    expect(OrderGatewayCheck::count())->toBe(1);
});

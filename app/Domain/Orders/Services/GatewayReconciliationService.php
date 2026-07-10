<?php

namespace App\Domain\Orders\Services;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderGatewayCheck;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Support\Carbon;

/**
 * Detects the scenario the user flagged as a real business risk: WooCommerce
 * marks an order paid/processing, but Zibal's own record of the transaction
 * disagrees. Zibal status codes below follow their publicly documented
 * inquiry/verify table (1/2 = paid, -1 = still pending, everything else is a
 * definitive failure) — this mapping has NOT yet been validated against a
 * real, currently-inquirable trackingCode (see plan's validation step), so
 * treat early results with some caution before fully trusting the schedule.
 */
class GatewayReconciliationService
{
    private const PAID_STATUSES = ['1', '2'];

    private const PENDING_STATUSES = ['-1'];

    public function __construct(
        private readonly ZibalGatewayClient $client,
        private readonly AlertDispatcher $alerts,
    ) {}

    public function checkOrder(Order $order): OrderGatewayCheck
    {
        $result = $this->client->inquiry($order->gateway_transaction_id);

        $mismatch = $result['ok']
            && $order->financial_state === 'valid'
            && ! in_array((string) $result['status'], [...self::PAID_STATUSES, ...self::PENDING_STATUSES], true);

        $check = OrderGatewayCheck::create([
            'order_id' => $order->id,
            'tracking_code' => $order->gateway_transaction_id,
            'checked_at' => Carbon::now(),
            'zibal_result_code' => $result['resultCode'],
            'zibal_status' => $result['status'],
            'zibal_amount' => $result['amount'],
            'mismatch' => $mismatch,
            'raw_response' => $result['raw'],
        ]);

        if ($mismatch) {
            $this->flagMismatch($order, $check);
        }

        return $check;
    }

    private function flagMismatch(Order $order, OrderGatewayCheck $check): void
    {
        $alreadyOpen = ReviewItem::where('type', 'gateway_status_mismatch')
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->where('status', 'open')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        ReviewItem::open('gateway_status_mismatch', $order, [
            'order_status' => $order->status,
            'gateway_status' => $check->zibal_status,
            'tracking_code' => $check->tracking_code,
        ]);

        $this->alerts->dispatch('zibal_gateway_mismatch', [
            'order_id' => $order->hub_order_id,
            'order_status' => $order->status,
            'gateway_status' => $check->zibal_status,
            'amount' => number_format($order->total),
        ], $order);
    }
}

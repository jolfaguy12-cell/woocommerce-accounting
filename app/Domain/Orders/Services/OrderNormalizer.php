<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Services\ChannelResolver;
use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderNormalizer
{
    public function __construct(
        private readonly ChannelResolver $channels,
        private readonly CustomerResolver $customers,
    ) {}

    /** Turn a raw hub payload into the normalized order + items (idempotent by hub_order_id). */
    public function normalize(RawOrder $raw): Order
    {
        return DB::transaction(function () use ($raw) {
            $payload = $raw->payload;
            $source = $this->channels->resolve($payload);

            $orderDate = isset($payload['date_created'])
                ? JalaliPeriod::parseHubGmt($payload['date_created'])
                : $raw->received_at;
            $status = (string) ($payload['status'] ?? 'unknown');
            [$paymentStatus, $datePaid] = $this->paymentStatus($payload, $status, $orderDate, $source->channel);

            $order = Order::updateOrCreate(['hub_order_id' => $raw->hub_order_id], [
                'raw_order_id' => $raw->id,
                'status' => $status,
                'created_via' => $payload['created_via'] ?? null,
                'order_date' => $orderDate,
                'jalali_period' => JalaliPeriod::fromDate($orderDate),
                'customer_party_id' => $this->customers->resolve($payload),
                'currency_raw' => $payload['currency'] ?? null,
                'discount_total' => $this->toman($payload['discount_total'] ?? 0),
                'shipping_charged' => $this->toman($payload['shipping_total'] ?? 0),
                'total' => $this->toman($payload['total'] ?? 0),
                'payment_method' => $payload['payment_method'] ?? null,
                'payment_method_title' => $payload['payment_method_title'] ?? null,
                'gateway_transaction_id' => $this->gatewayTransactionId($payload),
                'payment_status' => $paymentStatus,
                'date_paid' => $datePaid,
                'external_order_id' => $payload['external_order_id'] ?? null,
                'raw_source_value' => $source->raw_value,
                'channel_id' => $source->channel_id,
                'channel_source_id' => $source->id,
                'financial_state' => $this->financialState($status),
                'profit_status' => $source->channel_id ? 'pending' : 'unknown_source',
                'normalized_at' => now(),
            ]);

            $this->syncItems($order, (array) ($payload['line_items'] ?? []));

            return $order->load('items');
        });
    }

    private function syncItems(Order $order, array $items): void
    {
        $seenItemIds = [];

        foreach ($items as $item) {
            if (! isset($item['id'])) {
                continue;
            }

            $seenItemIds[] = $item['id'];

            $hubProductId = $item['variation_id'] ?: ($item['product_id'] ?? null);
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $subtotal = $this->toman($item['subtotal'] ?? 0);

            $order->items()->updateOrCreate(['hub_item_id' => $item['id']], [
                'hub_product_id' => $item['product_id'] ?? null,
                'hub_variation_id' => $item['variation_id'] ?: null,
                'product_mirror_id' => $hubProductId
                    ? ProductMirror::where('hub_product_id', $hubProductId)->value('id')
                    : null,
                'name' => $item['name'] ?? '',
                'sku' => $item['sku'] ?? null,
                'qty' => $qty,
                'unit_price' => (int) round($subtotal / $qty),
                'line_subtotal' => $subtotal,
                'line_total' => $this->toman($item['total'] ?? 0),
            ]);
        }

        // WooCommerce order edits can remove line items entirely (not just
        // change quantities) — a hub_item_id no longer present in the payload
        // means it was deleted upstream, so drop it here too rather than
        // leaving a stale item on an otherwise up-to-date order.
        $order->items()->whereNotIn('hub_item_id', $seenItemIds)->delete();
    }

    /**
     * Most channels only mark paid when the hub reports date_paid. Some
     * (Basalam) settle with the vendor upfront and never set it — for
     * those, config flags every order paid unless it's one of the
     * channel's own "never actually paid" statuses (README §11: configurable
     * mapping, not a hard-coded per-channel rule).
     */
    private function paymentStatus(array $payload, string $status, Carbon $orderDate, ?Channel $channel): array
    {
        if ($channel && ($channel->config['payment_prepaid_by_channel'] ?? false)) {
            $unpaidStatuses = $channel->config['payment_prepaid_unless_statuses'] ?? [];
            if (in_array($status, $unpaidStatuses, true)) {
                return ['unpaid', null];
            }

            return ['paid', empty($payload['date_paid']) ? $orderDate : JalaliPeriod::parseHubGmt($payload['date_paid'])];
        }

        if (empty($payload['date_paid'])) {
            return ['unpaid', null];
        }

        return ['paid', JalaliPeriod::parseHubGmt($payload['date_paid'])];
    }

    private function financialState(string $status): string
    {
        return match (true) {
            str_contains($status, 'cancel') => 'cancelled',
            str_contains($status, 'refund') => 'refunded',
            str_contains($status, 'fail') => 'void',
            default => 'pending', // the M7 validity gate promotes to 'valid'
        };
    }

    /**
     * Zibal-paid orders carry the gateway transaction id in the hub payload's
     * top-level transaction_id — needed later to look up the transaction's
     * real status via Zibal's inquiry API (gateway reconciliation).
     */
    private function gatewayTransactionId(array $payload): ?string
    {
        $method = (string) ($payload['payment_method'] ?? '');
        $title = (string) ($payload['payment_method_title'] ?? '');
        $isZibal = stripos($method, 'zibal') !== false || str_contains($title, 'زیبال');

        if (! $isZibal || empty($payload['transaction_id'])) {
            return null;
        }

        return (string) $payload['transaction_id'];
    }

    private function toman(mixed $value): int
    {
        return (int) round((float) $value / max(1, (int) config('accounting.currency_divisor', 1)));
    }
}

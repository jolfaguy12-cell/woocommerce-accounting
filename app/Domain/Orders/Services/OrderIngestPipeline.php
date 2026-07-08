<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Services\RawOrderUpserter;

/** Single entry point for order data from any source: raw store → normalize → profit. */
class OrderIngestPipeline
{
    public function __construct(
        private readonly RawOrderUpserter $rawOrders,
        private readonly OrderNormalizer $normalizer,
        private readonly ProfitEngine $profit,
    ) {}

    public function ingest(int $hubOrderId, array $payload, string $via): Order
    {
        $raw = $this->rawOrders->upsert($hubOrderId, $payload, $via);
        $order = $this->normalizer->normalize($raw);

        $this->profit->evaluate($order);

        return $order->refresh();
    }
}

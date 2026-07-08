<?php

namespace App\Domain\Sync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Read-only client for the mirror hub data API. Never talks to production WooCommerce. */
class HubClient
{
    private function request(): PendingRequest
    {
        return Http::baseUrl(config('hub.base_url'))
            ->withHeaders(['X-Hub-API-Key' => config('hub.api_key')])
            ->timeout(config('hub.timeout'))
            ->acceptJson();
    }

    /** The hub wraps single resources in a "data" envelope; unwrap when present. */
    private function get(string $path, array $query = []): array
    {
        $json = $this->request()->get($path, $query)->throw()->json() ?? [];

        return is_array($json['data'] ?? null) && ! array_is_list($json) && count($json) <= 2
            ? $json['data']
            : $json;
    }

    public function health(): array
    {
        return $this->get('/health');
    }

    public function order(int $hubOrderId): array
    {
        return $this->get("/orders/{$hubOrderId}");
    }

    public function orderItems(int $hubOrderId): array
    {
        return $this->get("/orders/{$hubOrderId}/items");
    }

    public function product(int $hubProductId): array
    {
        return $this->get("/products/{$hubProductId}");
    }

    public function productVariations(int $hubProductId): array
    {
        return $this->get("/products/{$hubProductId}/variations");
    }

    /** IDs/rows of orders changed since the cursor (hub handles the format). */
    public function changedOrders(?string $since): array
    {
        return $this->get('/sync/changed/orders', array_filter(['since' => $since]));
    }

    public function changedProducts(?string $since): array
    {
        return $this->get('/sync/changed/products', array_filter(['since' => $since]));
    }
}

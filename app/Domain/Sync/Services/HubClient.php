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

    /** Every order changed since the cursor, walking all result pages. */
    public function changedOrders(?string $since): array
    {
        return $this->paginated('/sync/changed/orders', ['since' => $since], 'orders');
    }

    /** Every order in the hub (full listing, not just the changed feed) — used for one-off/repair backfills. */
    public function allOrders(array $filters = []): array
    {
        return $this->paginated('/orders', $filters, 'data');
    }

    /** Every product changed since the cursor, walking all result pages. */
    public function changedProducts(?string $since): array
    {
        return $this->paginated('/sync/changed/products', ['since' => $since], 'products');
    }

    /** Every product in the hub (full listing, not just the changed feed) — used for one-off/repair backfills. */
    public function allProducts(array $filters = []): array
    {
        return $this->paginated('/products', $filters, 'data');
    }

    /** The hub pages list endpoints (default 20, cap 100); collect every page. */
    private function paginated(string $path, array $query, string $listKey): array
    {
        $perPage = 100;
        $rows = [];

        for ($page = 1; ; $page++) {
            $response = $this->get($path, array_filter($query) + ['page' => $page, 'per_page' => $perPage]);
            $batch = array_is_list($response) ? $response : ($response[$listKey] ?? []);

            $rows = array_merge($rows, $batch);

            if (count($batch) < $perPage) {
                return $rows;
            }
        }
    }
}

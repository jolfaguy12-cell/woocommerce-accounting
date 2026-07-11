<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    private const MAX_RESULTS = 8;

    private const CANDIDATE_LIMIT = 20;

    public function index(Request $request): View
    {
        $query = $request->string('q')->trim()->value();
        $user = $request->user();
        $results = collect();

        if ($query !== '') {
            if ($user->hasAnyRole(['admin', 'accountant', 'warehouse'])) {
                $results = $results->merge($this->searchOrders($query))
                    ->merge($this->searchProducts($query));
            }

            if ($user->hasAnyRole(['admin', 'accountant'])) {
                $results = $results->merge($this->searchCustomers($query));
            }

            $results = $results->sortByDesc('score')->take(self::MAX_RESULTS)->values();
        }

        return view('pages.search.index', [
            'title' => 'جستجو',
            'query' => $query,
            'results' => $results,
        ]);
    }

    private function searchOrders(string $query): Collection
    {
        return Order::query()
            ->with('customerParty')
            ->where(function ($q) use ($query) {
                $q->where('hub_order_id', 'like', "%{$query}%")
                    ->orWhereHas('customerParty', fn ($c) => $c->where('name', 'like', "%{$query}%"));
            })
            ->latest('order_date')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->map(function (Order $order) use ($query) {
                $customerName = $order->customerParty?->name ?? '';
                $score = max(
                    $this->matchScore((string) $order->hub_order_id, $query),
                    $this->matchScore($customerName, $query),
                );

                return $score > 0 ? [
                    'type' => 'order',
                    'type_label' => 'سفارش',
                    'badge_color' => 'primary',
                    'title' => 'سفارش #'.$order->hub_order_id,
                    'subtitle' => $customerName,
                    'url' => route('orders.show', $order),
                    'score' => $score,
                ] : null;
            })
            ->filter()
            ->values();
    }

    private function searchProducts(string $query): Collection
    {
        return ProductMirror::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('sku', 'like', "%{$query}%")
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->map(function (ProductMirror $product) use ($query) {
                $score = max(
                    $this->matchScore($product->name, $query),
                    $this->matchScore($product->sku, $query),
                );

                return $score > 0 ? [
                    'type' => 'product',
                    'type_label' => 'محصول',
                    'badge_color' => 'success',
                    'title' => $product->name,
                    'subtitle' => $product->sku,
                    'url' => route('products.show', $product),
                    'score' => $score,
                ] : null;
            })
            ->filter()
            ->values();
    }

    private function searchCustomers(string $query): Collection
    {
        return Party::query()
            ->where('type', 'customer')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->map(function (Party $party) use ($query) {
                $score = max(
                    $this->matchScore($party->name, $query),
                    $this->matchScore($party->phone, $query),
                    $this->matchScore($party->email, $query),
                );

                return $score > 0 ? [
                    'type' => 'customer',
                    'type_label' => 'مشتری',
                    'badge_color' => 'warning',
                    'title' => $party->name,
                    'subtitle' => $party->phone ?? $party->email,
                    'url' => route('customers.show', $party),
                    'score' => $score,
                ] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Closest match wins: exact > starts-with > contains > no match.
     */
    private function matchScore(?string $haystack, string $needle): int
    {
        $haystack = mb_strtolower(trim((string) $haystack));
        $needle = mb_strtolower(trim($needle));

        if ($haystack === '' || $needle === '') {
            return 0;
        }

        return match (true) {
            $haystack === $needle => 3,
            str_starts_with($haystack, $needle) => 2,
            str_contains($haystack, $needle) => 1,
            default => 0,
        };
    }
}

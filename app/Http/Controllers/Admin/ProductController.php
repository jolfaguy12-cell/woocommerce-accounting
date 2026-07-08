<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Models\WholesalePrice;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use App\Domain\Products\Models\ProductMirror;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, CostResolver $resolver): Response
    {
        $products = ProductMirror::whereIn('type', ['simple', 'variation', 'variable'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('sku', 'like', '%'.$request->string('q').'%')
                ->orWhere('hub_product_id', $request->string('q'))))
            ->when($request->string('mapping') === 'unmapped', fn ($q) => $q
                ->whereIn('type', ['simple', 'variation'])
                ->whereDoesntHave('costMapping', fn ($m) => $m->where('status', 'mapped')))
            ->orderByDesc('hub_modified_at')
            ->paginate(25)->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id,
                'hub_product_id' => $p->hub_product_id,
                'name' => $p->name,
                'type' => $p->type,
                'sku' => $p->sku,
                'price' => $p->price,
                'stock_quantity' => $p->stock_quantity,
                'mapping_status' => $p->costMapping?->status ?? 'unmapped',
            ]);

        return Inertia::render('products/index', ['products' => $products, 'filters' => $request->only('q', 'mapping')]);
    }

    public function show(ProductMirror $product, CostResolver $resolver): Response
    {
        $product->load('costMapping.costItem', 'variations');

        return Inertia::render('products/show', [
            'product' => [
                'id' => $product->id,
                'hub_product_id' => $product->hub_product_id,
                'name' => $product->name,
                'type' => $product->type,
                'sku' => $product->sku,
                'gtin' => $product->gtin,
                'status' => $product->status,
                'price' => $product->price,
                'regular_price' => $product->regular_price,
                'stock_quantity' => $product->stock_quantity,
                'stock_status' => $product->stock_status,
                'pricing' => $resolver->pricingSummary($product),
                'mapping' => $product->costMapping ? [
                    'cost_item' => $product->costMapping->costItem?->name,
                    'cost_item_id' => $product->costMapping->cost_item_id,
                    'multiplier' => $product->costMapping->multiplier,
                    'status' => $product->costMapping->status,
                ] : null,
                'variations' => $product->variations->map(fn ($v) => [
                    'id' => $v->id, 'hub_product_id' => $v->hub_product_id, 'name' => $v->name,
                    'price' => $v->price, 'stock_quantity' => $v->stock_quantity,
                ]),
                'price_history' => $product->priceHistory()->latest('changed_at')->limit(20)->get(),
                'stock_history' => $product->stockHistory()->latest('changed_at')->limit(20)->get(),
            ],
            'cost_items' => CostItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /** Map product/variation to a (possibly new) Cost Item, then unblock its orders. */
    public function map(Request $request, ProductMirror $product, ProfitEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'cost_item_id' => 'nullable|exists:cost_items,id',
            'new_item_name' => 'nullable|string|max:150|required_without:cost_item_id',
            'multiplier' => 'nullable|numeric|min:0.001|max:1000',
        ]);

        $costItemId = $data['cost_item_id']
            ?? CostItem::create(['name' => $data['new_item_name'], 'sku' => $product->sku])->id;

        ProductCostMapping::updateOrCreate(['product_mirror_id' => $product->id], [
            'cost_item_id' => $costItemId,
            'multiplier' => $data['multiplier'] ?? 1,
            'status' => 'mapped',
            'mapped_by' => $request->user()->id,
        ]);

        // Controlled recalculation: only orders blocked on missing cost re-run.
        Order::where('profit_status', 'blocked_missing_cost')
            ->whereHas('items', fn ($q) => $q->where('product_mirror_id', $product->id))
            ->get()->each(fn ($order) => $engine->evaluate($order));

        return back()->with('success', 'نگاشت بهای تمام‌شده ذخیره شد و سفارش‌های مسدود بازمحاسبه شدند.');
    }

    public function setWholesale(Request $request, ProductMirror $product): RedirectResponse
    {
        $data = $request->validate(['price' => 'required|integer|min:0']);

        $mapping = $product->costMapping;
        if (! $mapping?->cost_item_id) {
            return back()->withErrors(['price' => 'ابتدا محصول را به قلم بهای تمام‌شده نگاشت کنید.']);
        }

        WholesalePrice::create([
            'cost_item_id' => $mapping->cost_item_id,
            'price' => $data['price'],
            'effective_at' => now()->toDateString(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'قیمت عمده داخلی ثبت شد.');
    }
}

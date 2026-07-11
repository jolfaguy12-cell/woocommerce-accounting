<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Models\WholesalePrice;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Costing\Services\ProductMappingResolver;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request, CostResolver $resolver): View
    {
        $sort = $request->string('sort', 'hub_modified_at')->value();
        $dir = $request->string('dir', 'desc')->value() === 'asc' ? 'asc' : 'desc';

        $sortable = ['name', 'price', 'stock_quantity', 'hub_modified_at'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'hub_modified_at';
        }

        $products = ProductMirror::with('costMapping')
            ->whereIn('type', ['simple', 'variation', 'variable'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('sku', 'like', '%'.$request->string('q').'%')
                ->orWhere('hub_product_id', $request->string('q'))))
            ->when($request->input('mapping') === 'unmapped', fn ($q) => $q
                ->whereIn('type', ['simple', 'variation'])
                ->whereDoesntHave('costMapping', fn ($m) => $m->where('status', 'mapped')))
            ->orderBy($sort, $dir)
            ->paginate(25)->withQueryString();

        return view('pages.products.index', [
            'title' => 'محصولات',
            'products' => $products,
            'filters' => $request->only('q', 'mapping'),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(ProductMirror $product, CostResolver $resolver): View
    {
        $product->load('costMapping.costItem', 'variations', 'parent');

        $purchaseHistory = $product->costMapping?->cost_item_id
            ? $product->costMapping->costItem->costHistory()
                ->latest('effective_at')->latest('id')->limit(20)
                ->get(['id', 'unit_cost', 'qty', 'landed_unit_cost', 'source', 'effective_at'])
            : collect();

        return view('pages.products.show', [
            'title' => 'جزئیات محصول — '.$product->name,
            'product' => [
                'id' => $product->id,
                'hub_product_id' => $product->hub_product_id,
                'parent_hub_id' => $product->parent_hub_id,
                'name' => $product->name,
                'type' => $product->type,
                'sku' => $product->sku,
                'gtin' => $product->gtin,
                'status' => $product->status,
                'sold_as_set' => $product->sold_as_set,
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
                'parent' => $product->parent ? [
                    'id' => $product->parent->id, 'hub_product_id' => $product->parent->hub_product_id,
                    'name' => $product->parent->name, 'sold_as_set' => $product->parent->sold_as_set,
                ] : null,
                'price_history' => $product->priceHistory()->latest('changed_at')->limit(20)->get(),
                'stock_history' => $product->stockHistory()->latest('changed_at')->limit(20)->get(),
                'purchase_history' => $purchaseHistory,
                'notes' => $product->notes()->with('author:id,name')->latest()->limit(30)->get()
                    ->map(fn ($note) => [
                        'id' => $note->id,
                        'title' => $note->title,
                        'body' => $note->body,
                        'multiplier' => $note->multiplier,
                        'author' => $note->author?->name,
                        'created_at' => $note->created_at->toIso8601String(),
                    ]),
                'sync' => [
                    'hub_modified_at' => $product->hub_modified_at?->toIso8601String(),
                    'mirrored_at' => $product->updated_at?->toIso8601String(),
                ],
            ],
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

    /**
     * Internal wholesale price for profit discovery only — never touches the
     * ledger (see storeCost()'s docblock; the same boundary applies here).
     *
     * Setting a wholesale price on a variable product also applies the same
     * price to every variation that exists right now (each keeps its own Cost
     * Item — this doesn't merge them). New variations synced later don't
     * inherit it automatically; re-run this on the parent if needed.
     */
    public function setWholesale(Request $request, ProductMirror $product, ProductMappingResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'price' => 'required|integer|min:0',
            'sold_as_set' => 'boolean',
        ]);

        if ($product->type === 'variable') {
            $product->update(['sold_as_set' => $request->boolean('sold_as_set')]);
        }

        $targets = $product->type === 'variable'
            ? $product->variations->prepend($product)
            : collect([$product]);

        foreach ($targets as $target) {
            $mapping = $resolver->resolveOrCreate($target);

            WholesalePrice::create([
                'cost_item_id' => $mapping->cost_item_id,
                'price' => $data['price'],
                'effective_at' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);
        }

        $message = $product->type === 'variable'
            ? 'قیمت عمده داخلی برای این محصول و همه تنوع‌های آن ثبت شد.'
            : 'قیمت عمده داخلی ثبت شد.';

        return back()->with('success', $message);
    }

    /**
     * Landed cost entry for the mapped Cost Item, then unblock this product's orders.
     *
     * NON-NEGOTIABLE: this never touches the ledger. It exists purely to feed
     * CostResolver/ProfitEngine so order profit/loss can be computed — it must
     * never create a Party, PurchaseInvoice, or JournalEntry. Real purchases
     * (with a real supplier and a real accounts-payable entry) are recorded
     * exclusively through PurchaseInvoiceController (/new-buy-order). `qty` is
     * display-only context (e.g. "bought 10 units at this price") and is never
     * used in any calculation.
     *
     * Entering a cost on a variable product also applies the same cost to
     * every variation that exists right now (each keeps its own Cost Item) —
     * mirrors the same cascade already done for wholesale price.
     */
    public function storeCost(Request $request, ProductMirror $product, ProfitEngine $engine, ProductMappingResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'unit_cost' => 'required|integer|min:1',
            'qty' => 'nullable|integer|min:1',
            'effective_at' => 'nullable|date',
        ]);

        $targets = $product->type === 'variable'
            ? $product->variations->prepend($product)
            : collect([$product]);

        foreach ($targets as $target) {
            $mapping = $resolver->resolveOrCreate($target);

            $mapping->costItem->costHistory()->create([
                'unit_cost' => $data['unit_cost'],
                'qty' => $data['qty'] ?? null,
                'landed_unit_cost' => $data['unit_cost'],
                'source' => 'manual',
                'effective_at' => $data['effective_at'] ?? now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);
        }

        // Controlled recalculation: only orders blocked on missing cost re-run.
        Order::where('profit_status', 'blocked_missing_cost')
            ->whereHas('items', fn ($q) => $q->whereIn('product_mirror_id', $targets->pluck('id')))
            ->get()->each(fn ($order) => $engine->evaluate($order));

        $message = $product->type === 'variable'
            ? 'بهای تمام‌شده برای این محصول و همه تنوع‌های آن ثبت شد و سفارش‌های مسدود بازمحاسبه شدند.'
            : 'بهای تمام‌شده ثبت شد و سفارش‌های مسدود بازمحاسبه شدند.';

        return back()->with('success', $message);
    }

    /**
     * One-click cost + wholesale registration, triggered from the order page's
     * "ثبت نشده" badge so a blocked order can be unblocked without a detour to
     * the product page. Same ledger boundary as storeCost()/setWholesale() —
     * profit-discovery only. If the item ordered is a variation, the cascade
     * still runs from its parent down to every current variation (not just
     * this one), matching storeCost()'s cascade for the parent-entry case.
     */
    public function storeQuickCost(Request $request, ProductMirror $product, ProfitEngine $engine, ProductMappingResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'unit_cost' => 'required|integer|min:1',
            'wholesale_price' => 'nullable|integer|min:0',
        ]);

        $root = $product->type === 'variation' ? $product->parent : $product;
        $targets = $root->type === 'variable' ? $root->variations->prepend($root) : collect([$root]);

        foreach ($targets as $target) {
            $mapping = $resolver->resolveOrCreate($target);

            $mapping->costItem->costHistory()->create([
                'unit_cost' => $data['unit_cost'],
                'landed_unit_cost' => $data['unit_cost'],
                'source' => 'manual',
                'effective_at' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['wholesale_price'])) {
                WholesalePrice::create([
                    'cost_item_id' => $mapping->cost_item_id,
                    'price' => $data['wholesale_price'],
                    'effective_at' => now()->toDateString(),
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        Order::where('profit_status', 'blocked_missing_cost')
            ->whereHas('items', fn ($q) => $q->whereIn('product_mirror_id', $targets->pluck('id')))
            ->get()->each(fn ($order) => $engine->evaluate($order));

        $message = $root->type === 'variable'
            ? 'بهای تمام‌شده و قیمت عمده برای این محصول و همه تنوع‌های آن ثبت شد و سفارش‌های مسدود بازمحاسبه شدند.'
            : 'بهای تمام‌شده ثبت شد و سفارش‌های مسدود بازمحاسبه شدند.';

        return back()->with('success', $message);
    }

    public function storeNote(Request $request, ProductMirror $product): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'body' => 'nullable|string|max:2000',
            'multiplier' => 'nullable|numeric|min:0.001|max:1000',
        ]);

        $product->notes()->create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'یادداشت ذخیره شد.');
    }

    /** Read-only refresh of the mirror from the hub. Never writes to WooCommerce. */
    public function syncFromHub(ProductMirror $product, ProductSyncer $syncer): RedirectResponse
    {
        try {
            // Variations refresh through their parent so the whole family stays consistent.
            $syncer->sync($product->parent_hub_id ?? $product->hub_product_id, 'manual', (string) Str::uuid());
        } catch (Throwable) {
            return back()->withErrors(['sync' => 'به‌روزرسانی از هاب ناموفق بود؛ کمی بعد دوباره تلاش کنید.']);
        }

        return back()->with('success', 'اطلاعات محصول از هاب به‌روزرسانی شد.');
    }
}

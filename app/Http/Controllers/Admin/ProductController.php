<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Models\WholesalePrice;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request, CostResolver $resolver): View
    {
        $products = ProductMirror::with('costMapping')
            ->whereIn('type', ['simple', 'variation', 'variable'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('sku', 'like', '%'.$request->string('q').'%')
                ->orWhere('hub_product_id', $request->string('q'))))
            ->when($request->input('mapping') === 'unmapped', fn ($q) => $q
                ->whereIn('type', ['simple', 'variation'])
                ->whereDoesntHave('costMapping', fn ($m) => $m->where('status', 'mapped')))
            ->orderByDesc('hub_modified_at')
            ->paginate(25)->withQueryString();

        return view('pages.products.index', ['title' => 'محصولات', 'products' => $products, 'filters' => $request->only('q', 'mapping')]);
    }

    public function show(ProductMirror $product, CostResolver $resolver): View
    {
        $product->load('costMapping.costItem', 'variations', 'parent');

        $purchaseHistory = $product->costMapping?->cost_item_id
            ? $product->costMapping->costItem->costHistory()
                ->latest('effective_at')->latest('id')->limit(20)
                ->get(['id', 'unit_cost', 'landed_unit_cost', 'source', 'effective_at'])
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
                    'name' => $product->parent->name,
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
            'costItems' => CostItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'suppliers' => Party::where('type', 'supplier')->orderBy('name')->get(['id', 'name', 'shop_name']),
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

    /**
     * Landed cost entry for the mapped Cost Item, then unblock this product's orders.
     * With no supplier picked, this stays a lightweight "manual" note (no ledger effect,
     * same as before). Picking/creating a supplier instead posts a real one-line purchase
     * invoice (received immediately) so the payable to that supplier hits the books.
     */
    public function storeCost(Request $request, ProductMirror $product, ProfitEngine $engine, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        // The "+ تامین‌کننده جدید" option submits this sentinel instead of a real id;
        // new_supplier_name (not this field) is what actually drives creating it below.
        if ($request->input('supplier_party_id') === '__new__') {
            $request->merge(['supplier_party_id' => null]);
        }

        $data = $request->validate([
            'unit_cost' => 'required|integer|min:1',
            'effective_at' => 'nullable|date',
            'supplier_party_id' => ['nullable', Rule::exists('parties', 'id')->where('type', 'supplier')],
            'new_supplier_name' => 'nullable|string|max:150',
        ]);

        $mapping = $product->costMapping;
        if (! $mapping?->cost_item_id) {
            return back()->withErrors(['unit_cost' => 'ابتدا محصول را به قلم بهای تمام‌شده نگاشت کنید.']);
        }

        $date = $data['effective_at'] ?? now()->toDateString();

        if (($data['supplier_party_id'] ?? null) || ($data['new_supplier_name'] ?? null)) {
            $supplierId = $data['supplier_party_id']
                ?? Party::create(['type' => 'supplier', 'name' => $data['new_supplier_name']])->id;

            try {
                $invoice = $purchaseInvoices->create([
                    'supplier_party_id' => $supplierId,
                    'invoice_date' => $date,
                    'lines' => [
                        ['cost_item_id' => $mapping->cost_item_id, 'qty' => 1, 'unit_price' => $data['unit_cost']],
                    ],
                    'created_by' => $request->user()->id,
                ]);

                $purchaseInvoices->receive($invoice, [$invoice->lines->first()->id => 1], $request->user()->id);
            } catch (PeriodLockedException) {
                return back()->withErrors(['effective_at' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.']);
            }
        } else {
            $mapping->costItem->costHistory()->create([
                'unit_cost' => $data['unit_cost'],
                'landed_unit_cost' => $data['unit_cost'],
                'source' => 'manual',
                'effective_at' => $date,
                'created_by' => $request->user()->id,
            ]);
        }

        // Controlled recalculation: only orders blocked on missing cost re-run.
        Order::where('profit_status', 'blocked_missing_cost')
            ->whereHas('items', fn ($q) => $q->where('product_mirror_id', $product->id))
            ->get()->each(fn ($order) => $engine->evaluate($order));

        return back()->with('success', 'بهای تمام‌شده ثبت شد و سفارش‌های مسدود بازمحاسبه شدند.');
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

<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\ProductMappingResolver;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Products\Models\ProductMirror;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PurchaseInvoiceController extends Controller
{
    private const STATUS_LABELS = ['draft' => 'پیش‌نویس', 'partial' => 'دریافت جزئی', 'received' => 'دریافت‌شده', 'cancelled' => 'لغوشده'];

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: [
                'invoice_date' => 'purchase_invoices.invoice_date',
                'invoice_no' => 'purchase_invoices.invoice_no',
                'total' => 'total',
                'status' => 'purchase_invoices.status',
            ],
            filters: ['supplier_party_id', 'status'],
            defaultSort: '-invoice_date',
        );

        $search = $query->search() ?? '';

        // "total" isn't a stored column (it's lines + shipping), so it's
        // computed once here via a subquery rather than loaded per-row in the
        // view — this is also what makes it sortable through TableQuery.
        $totals = DB::table('purchase_invoice_lines')
            ->selectRaw('purchase_invoice_id, SUM(qty * unit_price) as lines_total, SUM(qty) as total_qty')
            ->groupBy('purchase_invoice_id');

        $invoices = PurchaseInvoice::query()
            ->leftJoinSub($totals, 'totals', 'totals.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->with(['supplier', 'attachments'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                ->orWhere('purchase_invoices.invoice_no', 'like', "%{$search}%")))
            ->when($request->filled('supplier_party_id'), fn ($q) => $q->where('purchase_invoices.supplier_party_id', $request->integer('supplier_party_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('purchase_invoices.status', $request->string('status')))
            ->select('purchase_invoices.*', DB::raw('COALESCE(totals.lines_total, 0) + purchase_invoices.shipping_cost as total'), DB::raw('COALESCE(totals.total_qty, 0) as total_qty'))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.purchases.index', [
            'title' => 'ثبت خرید',
            'invoices' => $invoices,
            'query' => $query,
            'filters' => $request->only('search', 'supplier_party_id', 'status'),
            'statusLabels' => self::STATUS_LABELS,
            'supplierName' => $request->filled('supplier_party_id')
                ? Party::find($request->integer('supplier_party_id'))?->name
                : null,
        ]);
    }

    public function create(): View
    {
        return view('pages.purchases.create', [
            'title' => 'خرید جدید',
            'suppliers' => Party::where('type', 'supplier')->orderBy('name')->get(['id', 'name', 'shop_name']),
        ]);
    }

    /**
     * Records a purchase invoice. "ذخیره پیش‌نویس" leaves it as a draft (no
     * journal, no cost history yet — useful when the shipping cost isn't
     * known yet). "ثبت نهایی" additionally receives it in full immediately,
     * which is the only place real purchase/accounting documents get created
     * (see CLAUDE.md's Non-Negotiable Rules).
     */
    public function store(Request $request, PurchaseInvoiceService $purchaseInvoices, ProductMappingResolver $resolver): RedirectResponse
    {
        if ($request->input('supplier_party_id') === '__new__') {
            $request->merge(['supplier_party_id' => null]);
        }

        $data = $this->validateInvoice($request);

        $supplierId = $data['supplier_party_id']
            ?? Party::create(['type' => 'supplier', 'name' => $data['new_supplier_name']])->id;

        $lines = $this->resolveLines($data['lines'], $resolver);

        try {
            $invoice = $purchaseInvoices->create([
                'supplier_party_id' => $supplierId,
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'lines' => $lines,
                'created_by' => $request->user()->id,
            ]);

            if ($request->input('action') === 'finalize') {
                $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);
            }
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.'])->withInput();
        }

        $this->storeAttachments($request, $invoice);

        $message = $request->input('action') === 'finalize'
            ? 'فاکتور خرید ثبت، دریافت و سند حسابداری آن صادر شد.'
            : 'فاکتور خرید به‌صورت پیش‌نویس ذخیره شد.';

        return redirect()->route('purchases.show', $invoice)->with('success', $message);
    }

    public function show(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'lines.costItem', 'lines.product', 'attachments', 'journalEntry']);

        return view('pages.purchases.show', [
            'title' => 'فاکتور خرید #'.$invoice->id,
            'invoice' => $invoice,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'lines.costItem', 'lines.product']);

        return view('pages.purchases.edit', [
            'title' => 'ویرایش فاکتور خرید #'.$invoice->id,
            'invoice' => $invoice,
            'suppliers' => Party::where('type', 'supplier')->orderBy('name')->get(['id', 'name', 'shop_name']),
        ]);
    }

    /**
     * Edits header fields, existing line prices/qty/notes, adds/removes
     * lines, and reallocates shipping across every remaining line (see
     * PurchaseInvoiceService::update()'s docblock for why — shipping is often
     * only known a day or two after the goods went out, and correcting it
     * after the invoice was already received reverses the old journal entry
     * and posts a corrected one rather than a silent edit). A line already
     * received can only have its qty increased and can never be removed —
     * the service enforces this and throws InvalidArgumentException, caught
     * below, if the form tries anyway.
     */
    public function update(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $purchaseInvoices, ProductMappingResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'invoice_no' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'shipping_cost' => 'nullable|integer|min:0',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'lines' => 'nullable|array',
            'lines.*.id' => 'nullable|exists:purchase_invoice_lines,id',
            'lines.*.cost_item_id' => 'nullable|exists:cost_items,id',
            'lines.*.new_item_name' => 'nullable|string|max:150',
            'lines.*.qty' => 'nullable|integer|min:1',
            'lines.*.unit_price' => 'nullable|integer|min:1',
            'lines.*.note' => 'nullable|string|max:255',
            'lines.*._remove' => 'nullable|boolean',
        ]);

        $lines = isset($data['lines']) ? $this->resolveLines($data['lines'], $resolver) : [];

        try {
            $purchaseInvoices->update($invoice, [
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'lines' => $lines,
            ], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.'])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        $this->storeAttachments($request, $invoice);

        return redirect()->route('purchases.show', $invoice)->with('success', 'فاکتور خرید به‌روزرسانی شد.');
    }

    /** Append one or more images to an already-created invoice, without resubmitting the whole edit form. */
    public function storeImages(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $request->validate(['images' => 'required|array|min:1', 'images.*' => 'image|max:10240']);

        $this->storeAttachments($request, $invoice);

        return back()->with('success', 'تصویر فاکتور اضافه شد.');
    }

    /** Remove one invoice image. "Replace" is composed from this + storeImages. */
    public function destroyImage(PurchaseInvoice $invoice, Attachment $attachment): RedirectResponse
    {
        abort_unless($attachment->attachable_type === 'purchase_invoice' && $attachment->attachable_id === $invoice->id, 404);

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return back()->with('success', 'تصویر فاکتور حذف شد.');
    }

    /** Receives a draft/partial invoice in full, posting its journal entry for the first time. */
    public function finalize(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        try {
            $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.']);
        }

        return redirect()->route('purchases.show', $invoice)->with('success', 'فاکتور خرید دریافت و سند حسابداری آن صادر شد.');
    }

    /**
     * The one approved fetch/AJAX exception in this app (see CLAUDE.md) — a
     * live combobox for picking which product a purchase line is for. Every
     * other page stays full-reload/no-fetch.
     */
    public function searchItems(Request $request): JsonResponse
    {
        $q = $request->string('q');

        $products = ProductMirror::whereIn('type', ['simple', 'variable', 'variation'])
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
            ->orderBy('name')->limit(20)
            ->get(['id', 'name', 'sku', 'type']);

        return response()->json($products);
    }

    private function validateInvoice(Request $request): array
    {
        return $request->validate([
            'supplier_party_id' => ['nullable', Rule::exists('parties', 'id')->where('type', 'supplier')],
            'new_supplier_name' => 'nullable|string|max:150|required_without:supplier_party_id',
            'invoice_no' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'shipping_cost' => 'nullable|integer|min:0',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'lines' => 'required|array|min:1',
            'lines.*.product_mirror_id' => 'nullable|exists:product_mirror,id',
            'lines.*.cost_item_id' => 'nullable|exists:cost_items,id',
            'lines.*.new_item_name' => 'nullable|string|max:150',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|integer|min:1',
            'lines.*.note' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Each NEW line (no 'id' — an existing line being edited already has one
     * and never changes which item it's for) names its item one of three
     * ways: an existing product (from the search combobox — auto-mapped to
     * its own Cost Item if it doesn't have one), an existing bare Cost Item,
     * or a brand-new Cost Item typed by hand (for things with no catalog
     * product, e.g. packaging).
     */
    private function resolveLines(array $lines, ProductMappingResolver $resolver): array
    {
        return collect($lines)->map(function ($line) use ($resolver) {
            if (! empty($line['id'])) {
                return $line;
            }

            if (! empty($line['product_mirror_id'])) {
                $product = ProductMirror::findOrFail($line['product_mirror_id']);
                $line['cost_item_id'] = $resolver->resolveOrCreate($product)->cost_item_id;
            } elseif (empty($line['cost_item_id'])) {
                $line['cost_item_id'] = CostItem::create(['name' => $line['new_item_name']])->id;
            }
            unset($line['new_item_name']);

            return $line;
        })->all();
    }

    private function storeAttachments(Request $request, PurchaseInvoice $invoice): void
    {
        foreach ($request->file('images', []) as $file) {
            Attachment::create([
                'attachable_type' => 'purchase_invoice',
                'attachable_id' => $invoice->id,
                'path' => $file->store('attachments', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }
    }
}

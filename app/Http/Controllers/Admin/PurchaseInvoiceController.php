<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Expenses\Models\Attachment;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseInvoiceController extends Controller
{
    public function index(): View
    {
        $invoices = PurchaseInvoice::with(['supplier', 'lines', 'attachments'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(25);

        return view('pages.purchases.index', [
            'title' => 'ثبت خرید',
            'invoices' => $invoices,
            'suppliers' => Party::where('type', 'supplier')->orderBy('name')->get(['id', 'name', 'shop_name']),
            'costItems' => CostItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /**
     * Records a purchase as fully received right away (no separate receiving step
     * yet), posting its journal entry immediately. This is the ONLY place real
     * purchase/accounting documents get created (see CLAUDE.md's Non-Negotiable
     * Rules) — every field here (qty, unit price, shipping, landed cost) feeds
     * both the ledger and month-over-month purchasing reports, so keep totals
     * accurate rather than approximate.
     */
    public function store(Request $request, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        // The "+ تامین‌کننده جدید" option submits this sentinel instead of a real id;
        // new_supplier_name (not this field) is what actually drives creating it below.
        if ($request->input('supplier_party_id') === '__new__') {
            $request->merge(['supplier_party_id' => null]);
        }

        $data = $request->validate([
            'supplier_party_id' => ['nullable', Rule::exists('parties', 'id')->where('type', 'supplier')],
            'new_supplier_name' => 'nullable|string|max:150|required_without:supplier_party_id',
            'invoice_no' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'shipping_cost' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:10240',
            'lines' => 'required|array|min:1',
            'lines.*.cost_item_id' => 'nullable|exists:cost_items,id',
            'lines.*.new_item_name' => 'nullable|string|max:150|required_without:lines.*.cost_item_id',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|integer|min:1',
            'lines.*.note' => 'nullable|string|max:255',
        ]);

        $supplierId = $data['supplier_party_id']
            ?? Party::create(['type' => 'supplier', 'name' => $data['new_supplier_name']])->id;

        // Each line names either an existing Cost Item or a brand-new one (typed
        // by hand right here) — resolve the new ones to real ids before handing
        // lines off to the service, which only ever deals in cost_item_id.
        $lines = collect($data['lines'])->map(function ($line) {
            $line['cost_item_id'] ??= CostItem::create(['name' => $line['new_item_name']])->id;
            unset($line['new_item_name']);

            return $line;
        })->all();

        try {
            $invoice = $purchaseInvoices->create([
                'supplier_party_id' => $supplierId,
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'lines' => $lines,
                'created_by' => $request->user()->id,
            ]);

            $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.']);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');

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

        return back()->with('success', 'فاکتور خرید ثبت، دریافت و سند حسابداری آن صادر شد.');
    }
}

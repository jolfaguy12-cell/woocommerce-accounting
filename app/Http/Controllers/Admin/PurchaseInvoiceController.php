<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseInvoiceController extends Controller
{
    public function index(): View
    {
        $invoices = PurchaseInvoice::with(['supplier', 'lines'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(25);

        return view('pages.purchases.index', [
            'title' => 'ثبت خرید',
            'invoices' => $invoices,
            'suppliers' => Party::where('type', 'supplier')->orderBy('name')->get(['id', 'name', 'shop_name']),
            'costItems' => CostItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /** Records a purchase as fully received right away (no separate receiving step yet), posting its journal entry immediately. */
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
            'lines' => 'required|array|min:1',
            'lines.*.cost_item_id' => 'required|exists:cost_items,id',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|integer|min:1',
        ]);

        $supplierId = $data['supplier_party_id']
            ?? Party::create(['type' => 'supplier', 'name' => $data['new_supplier_name']])->id;

        try {
            $invoice = $purchaseInvoices->create([
                'supplier_party_id' => $supplierId,
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'lines' => $data['lines'],
                'created_by' => $request->user()->id,
            ]);

            $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.']);
        }

        return back()->with('success', 'فاکتور خرید ثبت، دریافت و سند حسابداری آن صادر شد.');
    }
}

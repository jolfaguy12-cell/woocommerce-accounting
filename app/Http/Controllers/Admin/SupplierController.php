<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $suppliers = Party::where('type', 'supplier')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('shop_name', 'like', '%'.$request->string('q').'%')
                ->orWhere('phone', 'like', '%'.$request->string('q').'%')))
            ->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('pages.suppliers.index', [
            'title' => 'تامین‌کننده‌ها',
            'suppliers' => $suppliers,
            'filters' => $request->only('q'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'shop_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        Party::create($data + ['type' => 'supplier']);

        return back()->with('success', 'تامین‌کننده جدید ثبت شد.');
    }

    public function show(Party $supplier): View
    {
        abort_unless($supplier->type === 'supplier', 404);

        $purchases = PurchaseInvoiceLine::query()
            ->whereHas('invoice', fn ($q) => $q->where('supplier_party_id', $supplier->id))
            ->with(['costItem', 'invoice'])
            ->get()
            ->sortByDesc(fn ($line) => $line->invoice->invoice_date)
            ->values();

        return view('pages.suppliers.show', [
            'title' => 'تامین‌کننده — '.$supplier->name,
            'supplier' => $supplier,
            'purchases' => $purchases,
        ]);
    }
}

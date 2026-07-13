<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Costing\Services\OverdueReceivingService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UnreceivedGoodsController extends Controller
{
    public function index(Request $request, OverdueReceivingService $overdue): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: [
                'invoice_no' => 'purchase_invoices.invoice_no',
                'supplier_name' => 'parties.name',
                'item_name' => 'cost_items.name',
                'outstanding_qty' => 'outstanding_qty',
                'age_days' => 'age_days',
            ],
            filters: ['supplier_party_id'],
            defaultSort: '-age_days',
        );

        $search = $query->search() ?? '';

        $rows = $overdue->overdueLineRowsQuery()
            ->with('receiptLines')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('cost_items.name', 'like', "%{$search}%")
                ->orWhere('purchase_invoices.invoice_no', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")))
            ->when($request->filled('supplier_party_id'), fn ($q) => $q->where('purchase_invoices.supplier_party_id', $request->integer('supplier_party_id')))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.unreceived-goods.index', [
            'title' => 'کالاهای دریافت‌نشده',
            'rows' => $rows,
            'query' => $query,
            'filters' => $request->only('search', 'supplier_party_id'),
        ]);
    }
}

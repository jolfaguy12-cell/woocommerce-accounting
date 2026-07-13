<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use App\Domain\Costing\Services\OverdueReceivingService;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Services\PayablesService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\SupplierCreditService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    private const AP_ACCOUNT = '2000';

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: [
                'name' => 'parties.name',
                'invoices_count' => 'invoices_count',
                'payable_balance' => 'payable_balance',
            ],
            filters: [],
            defaultSort: 'name',
        );

        $search = $query->search() ?? '';

        // Payable balance per supplier, computed once here (not per row in the
        // view) — same subquery-join technique as PurchaseInvoiceController's
        // "total" column, to avoid an N+1 over journal_lines.
        $payableSub = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', self::AP_ACCOUNT)
            ->selectRaw('journal_lines.party_id, SUM(journal_lines.credit) - SUM(journal_lines.debit) as payable_balance')
            ->groupBy('journal_lines.party_id');

        $suppliers = Party::query()
            ->withRole(PartyRoleType::Supplier)
            ->with('supplierProfile', 'bankAccounts')
            ->leftJoinSub($payableSub, 'payables', 'payables.party_id', '=', 'parties.id')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('parties.name', 'like', "%{$search}%")
                ->orWhereHas('supplierProfile', fn ($p) => $p->where('shop_name', 'like', "%{$search}%"))
                ->orWhere('parties.phone', 'like', "%{$search}%")))
            ->select('parties.*', DB::raw('COALESCE(payables.payable_balance, 0) as payable_balance'))
            ->withCount('purchaseInvoices as invoices_count')
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.suppliers.index', [
            'title' => 'تامین‌کننده‌ها',
            'suppliers' => $suppliers,
            'query' => $query,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProfile($request);

        $supplier = Party::create(Arr::except($data, ['shop_name', 'bank_account_number'])
            + ['type' => PartyRoleType::Supplier->value]);

        $this->saveSupplierProfile($supplier, $data, $request->user()->id);

        return back()->with('success', 'تامین‌کننده جدید ثبت شد.');
    }

    public function update(Request $request, Party $supplier): RedirectResponse
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $data = $this->validateProfile($request);

        $supplier->update(Arr::except($data, ['shop_name', 'bank_account_number']));
        $this->saveSupplierProfile($supplier, $data, $request->user()->id);

        return back()->with('success', 'اطلاعات تامین‌کننده به‌روزرسانی شد.');
    }

    /** One-click bank payment to a supplier — debits AP, credits the paying bank account (PaymentRecorder::pay()). */
    public function pay(Request $request, Party $supplier, PaymentRecorder $recorder): RedirectResponse
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $data = $this->validatePaymentMeta($request);

        try {
            $recorder->pay($supplier, $data['amount'], $data['bank_account_id'], $request->user()->id, $data['method'] ?? null, $data['reference'] ?? null);
        } catch (PeriodLockedException) {
            return back()->withErrors(['amount' => 'دوره حسابداری این تاریخ قفل است.']);
        }

        return back()->with('success', 'پرداخت به تامین‌کننده ثبت شد.');
    }

    /** A supplier refunding money back to us (e.g. settling a credit balance in cash) — PaymentRecorder::receiveRefund(). */
    public function refund(Request $request, Party $supplier, PaymentRecorder $recorder): RedirectResponse
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $data = $this->validatePaymentMeta($request);

        try {
            $recorder->receiveRefund($supplier, $data['amount'], $data['bank_account_id'], $request->user()->id, $data['method'] ?? null, $data['reference'] ?? null);
        } catch (PeriodLockedException) {
            return back()->withErrors(['amount' => 'دوره حسابداری این تاریخ قفل است.']);
        }

        return back()->with('success', 'بازپرداخت از تامین‌کننده ثبت شد.');
    }

    /** Manual "retained balance" credit — not a return, not a cash refund (SupplierCreditService::recordManualCredit()). */
    public function storeCredit(Request $request, Party $supplier, SupplierCreditService $credits): RedirectResponse
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'description' => 'required|string|max:255',
        ]);

        try {
            $credits->recordManualCredit($supplier, $data['amount'], $data['description'], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['amount' => 'دوره حسابداری این تاریخ قفل است.']);
        }

        return back()->with('success', 'اعتبار دستی برای تامین‌کننده ثبت شد.');
    }

    /**
     * Overview dashboard: header, KPIs, account-status summary, and capped
     * previews only. The full advanced tables (invoices/purchase-history/
     * transactions) each live on their own route — CLAUDE.md's URL contract
     * is one search/sort/per_page per page, so three independent pro-tables
     * can't share this page's query string.
     */
    public function show(Party $supplier, PayablesService $payables): View
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $currentPeriod = JalaliPeriod::fromDate(now());
        $previousPeriod = JalaliPeriod::previous($currentPeriod);

        $lines = PurchaseInvoiceLine::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->where('purchase_invoices.supplier_party_id', $supplier->id)
            ->where('purchase_invoices.status', '!=', 'cancelled');

        // qty*unit_price summed plus shipping_allocated equals qty*unit_price
        // summed plus shipping_cost (shipping_allocated always sums to
        // shipping_cost per invoice by construction) — so this is each
        // invoice's true landed total, without double-counting shipping.
        $totalsFor = fn (string $period) => (clone $lines)
            ->where('purchase_invoices.jalali_period', $period)
            ->selectRaw('COALESCE(SUM(purchase_invoice_lines.qty * purchase_invoice_lines.unit_price + purchase_invoice_lines.shipping_allocated), 0) as total, COALESCE(SUM(purchase_invoice_lines.qty), 0) as qty')
            ->first();

        $thisMonth = $totalsFor($currentPeriod);
        $lastMonth = $totalsFor($previousPeriod);
        $lifetimeTotal = (int) (clone $lines)
            ->selectRaw('COALESCE(SUM(purchase_invoice_lines.qty * purchase_invoice_lines.unit_price + purchase_invoice_lines.shipping_allocated), 0) as total')
            ->value('total');

        $pctChange = fn (int $current, int $previous) => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;

        $topItems = (clone $lines)
            ->join('cost_items', 'cost_items.id', '=', 'purchase_invoice_lines.cost_item_id')
            ->selectRaw('cost_items.name as label, SUM(purchase_invoice_lines.qty * purchase_invoice_lines.unit_price + purchase_invoice_lines.shipping_allocated) as value')
            ->groupBy('cost_items.id', 'cost_items.name')
            ->orderByDesc('value')
            ->limit(5)
            ->get();
        $topItemsTotal = max(1, (int) $topItems->sum('value'));

        return view('pages.suppliers.show', [
            'title' => 'تامین‌کننده — '.$supplier->name,
            'supplier' => $supplier,
            'tabs' => $this->tabsFor($supplier),
            'kpis' => [
                'month_value' => ['value' => (int) $thisMonth->total, 'change' => $pctChange((int) $thisMonth->total, (int) $lastMonth->total)],
                'month_qty' => ['value' => (int) $thisMonth->qty, 'change' => $pctChange((int) $thisMonth->qty, (int) $lastMonth->qty)],
                'lifetime_value' => ['value' => $lifetimeTotal],
            ],
            'payableBalance' => $payables->partyPayableBalance($supplier),
            'topItems' => $topItems->map(fn ($row) => [
                'label' => $row->label,
                'value' => (int) $row->value,
                'share' => round($row->value / $topItemsTotal * 100, 1),
            ])->all(),
            'recentTransactions' => $payables->recentLines($supplier, 8),
            'recentInvoices' => $supplier->purchaseInvoices()->orderByDesc('invoice_date')->limit(5)->get(),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function purchaseHistory(Request $request, Party $supplier): View
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $query = new TableQuery(
            request: $request,
            sortable: [
                'invoice_date' => 'purchase_invoices.invoice_date',
                'qty' => 'purchase_invoice_lines.qty',
                'unit_price' => 'purchase_invoice_lines.unit_price',
                'landed_unit_cost' => 'purchase_invoice_lines.landed_unit_cost',
            ],
            filters: [],
            defaultSort: '-invoice_date',
        );

        $search = $query->search() ?? '';

        $purchases = PurchaseInvoiceLine::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->join('cost_items', 'cost_items.id', '=', 'purchase_invoice_lines.cost_item_id')
            ->where('purchase_invoices.supplier_party_id', $supplier->id)
            ->when($search !== '', fn ($q) => $q->where('cost_items.name', 'like', "%{$search}%"))
            ->with(['invoice', 'costItem'])
            ->select('purchase_invoice_lines.*')
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.suppliers.purchase-history', [
            'title' => 'سابقه خرید — '.$supplier->name,
            'supplier' => $supplier,
            'tabs' => $this->tabsFor($supplier),
            'purchases' => $purchases,
            'query' => $query,
            'filters' => $request->only('search'),
        ]);
    }

    public function transactions(Request $request, Party $supplier, PayablesService $payables): View
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $query = new TableQuery(
            request: $request,
            sortable: [
                'date' => 'journal_entries.entry_date',
                'debit' => 'journal_lines.debit',
                'credit' => 'journal_lines.credit',
            ],
            filters: [],
            defaultSort: '-date',
        );

        return view('pages.suppliers.transactions', [
            'title' => 'تراکنش‌های مالی — '.$supplier->name,
            'supplier' => $supplier,
            'tabs' => $this->tabsFor($supplier),
            'transactions' => $payables->ledger($supplier, $query),
            'balance' => $payables->partyPayableBalance($supplier),
            'query' => $query,
            'filters' => $request->only('search'),
        ]);
    }

    /** Overdue receiving for this supplier only, grouped by invoice (see OverdueReceivingService's docblock for the definition). */
    public function overdue(Request $request, Party $supplier, OverdueReceivingService $overdue): View
    {
        abort_unless($supplier->hasRole(PartyRoleType::Supplier), 404);

        $query = new TableQuery(
            request: $request,
            sortable: [
                'invoice_no' => 'purchase_invoices.invoice_no',
                'item_name' => 'cost_items.name',
                'outstanding_qty' => 'outstanding_qty',
                'age_days' => 'age_days',
            ],
            filters: [],
            defaultSort: '-age_days',
        );

        $rows = $overdue->overdueLineRowsQuery($supplier->id)
            ->with('receiptLines')
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.suppliers.overdue', [
            'title' => 'کالاهای دریافت‌نشده — '.$supplier->name,
            'supplier' => $supplier,
            'tabs' => $this->tabsFor($supplier),
            'rows' => $rows,
            'query' => $query,
        ]);
    }

    /** Same tab set on every supplier sub-page — each is its own route/TableQuery (see show()'s docblock). */
    private function tabsFor(Party $supplier): array
    {
        return [
            ['key' => 'overview', 'label' => 'خلاصه', 'url' => route('suppliers.show', $supplier)],
            ['key' => 'invoices', 'label' => 'فاکتورهای خرید', 'url' => route('purchases.index', ['supplier_party_id' => $supplier->id])],
            ['key' => 'purchases', 'label' => 'سابقه خرید', 'url' => route('suppliers.purchase-history', $supplier)],
            ['key' => 'transactions', 'label' => 'تراکنش‌های مالی', 'url' => route('suppliers.transactions', $supplier)],
            ['key' => 'overdue', 'label' => 'کالاهای دریافت‌نشده', 'url' => route('suppliers.overdue', $supplier)],
        ];
    }

    private function validatePaymentMeta(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|integer|min:1',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'method' => 'nullable|string|in:bank_transfer,cash,card,other',
            'reference' => 'nullable|string|max:100',
        ]);
    }

    /**
     * shop_name is supplier-role data (supplier_profiles) and the account number
     * is a counterparty bank account (party_bank_accounts) — neither belongs on
     * the shared Party identity any more. The form keeps its two familiar fields.
     */
    private function saveSupplierProfile(Party $supplier, array $data, int $userId): void
    {
        $supplier->profileFor(PartyRoleType::Supplier)->update([
            'shop_name' => $data['shop_name'] ?? null,
        ]);

        if (blank($data['bank_account_number'] ?? null)) {
            return;
        }

        $existing = $supplier->bankAccounts()->active()->where('is_default', true)->first()
            ?? $supplier->bankAccounts()->active()->first();

        $existing
            ? $existing->update(['account_number' => $data['bank_account_number']])
            : $supplier->bankAccounts()->create([
                'account_holder' => $supplier->name,
                'account_number' => $data['bank_account_number'],
                'is_default' => true,
                'is_active' => true,
                'created_by' => $userId,
            ]);

        $supplier->unsetRelation('bankAccounts');
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'shop_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:500',
            'bank_account_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);
    }
}

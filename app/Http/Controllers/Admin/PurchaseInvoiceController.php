<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use App\Domain\Costing\Models\PurchaseInvoiceReceiptLine;
use App\Domain\Costing\Services\ProductMappingResolver;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Costing\Services\PurchaseReturnService;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Http\Controllers\Controller;
use App\Rules\PartyHasRole;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function create(Request $request): View
    {
        return view('pages.purchases.create', [
            'title' => 'خرید جدید',
            'suppliers' => Party::withRole(PartyRoleType::Supplier)->with('supplierProfile')->orderBy('name')->get(['id', 'name']),
            // Prefilled from the supplier page's "خرید جدید از این تامین‌کننده" button.
            'preselectedSupplierId' => $request->integer('supplier_party_id') ?: null,
            // For the optional initial-payment rows — same query SupplierController::pay() uses.
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Records a purchase invoice. "ذخیره پیش‌نویس" leaves it as a draft (no
     * journal, no cost history yet — useful when the shipping cost isn't
     * known yet). "ثبت نهایی" additionally receives it in full immediately,
     * which is the only place real purchase/accounting documents get created
     * (see CLAUDE.md's Non-Negotiable Rules).
     */
    public function store(Request $request, PurchaseInvoiceService $purchaseInvoices, ProductMappingResolver $resolver, PaymentRecorder $recorder): RedirectResponse
    {
        if ($request->input('supplier_party_id') === '__new__') {
            $request->merge(['supplier_party_id' => null]);
        }

        $data = $this->validateInvoice($request);

        $supplierId = $data['supplier_party_id']
            ?? Party::createWithRole(PartyRoleType::Supplier, ['name' => $data['new_supplier_name']])->id;

        $lines = $this->resolveLines($data['lines'], $resolver);

        $invoiceData = [
            'supplier_party_id' => $supplierId,
            'invoice_no' => $data['invoice_no'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
            'shipping_cost' => $data['shipping_cost'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'lines' => $lines,
            'created_by' => $request->user()->id,
        ];

        try {
            if ($request->input('action') === 'finalize') {
                // Create, receive in full, and post any initial payments as one
                // atomic unit — a failure at any step rolls back the invoice
                // itself too, rather than leaving a half-finalized draft behind.
                // A plain draft save (below) stays its own single transaction,
                // exactly as before.
                $invoice = DB::transaction(function () use ($purchaseInvoices, $invoiceData, $data, $request, $recorder) {
                    $invoice = $purchaseInvoices->create($invoiceData);
                    $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);
                    $this->postInitialPayments($invoice, $data['payments'] ?? [], $recorder, $request->user()->id);

                    return $invoice;
                });
            } else {
                // A draft is not an accounting document: initial-payment rows are
                // kept unposted (pending_payments) so the edit form can restore
                // them; they only become real postings at finalize().
                $invoice = $purchaseInvoices->create([...$invoiceData, 'pending_payments' => $data['payments'] ?? null]);
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

    public function show(PurchaseInvoice $invoice, PartyLedgerService $ledger): View
    {
        $invoice->load([
            'supplier', 'lines.costItem', 'lines.product', 'lines.receiptLines', 'attachments', 'journalEntry',
            'receipts' => fn ($q) => $q->orderByDesc('received_at')->orderByDesc('id'),
            'receipts.lines.invoiceLine.costItem',
            'receipts.creator',
            'returns' => fn ($q) => $q->orderByDesc('id'),
            'returns.lines.invoiceLine.costItem',
            'returns.creator',
        ]);

        return view('pages.purchases.show', [
            'title' => 'فاکتور خرید #'.$invoice->id,
            'invoice' => $invoice,
            'statusLabels' => self::STATUS_LABELS,
            // Payments have no invoice_id — they're generic party payments — so
            // "made at creation of THIS invoice" is found via the correlation_id
            // postInitialPayments() tagged their JOURNAL ENTRY with (the
            // invoice's own uuid) — correlation_id lives on journal_entries,
            // not party_payments (see JournalPoster::post()).
            'paidAtCreation' => PartyPayment::whereHas('journalEntry', fn ($q) => $q->where('correlation_id', $invoice->uuid))
                ->whereNull('reversed_at')->get(),
            'supplierPayable' => $ledger->supplierPayable($invoice->supplier),
        ]);
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'lines.costItem', 'lines.product']);

        return view('pages.purchases.edit', [
            'title' => 'ویرایش فاکتور خرید #'.$invoice->id,
            'invoice' => $invoice,
            'suppliers' => Party::withRole(PartyRoleType::Supplier)->with('supplierProfile')->orderBy('name')->get(['id', 'name']),
            // Payment rows only make sense while the invoice is still a draft —
            // once finalized, pending_payments has already been posted/cleared.
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
            'notes' => 'nullable|string|max:2000',
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
            ...$this->paymentRules(),
        ]);

        $lines = isset($data['lines']) ? $this->resolveLines($data['lines'], $resolver) : [];

        try {
            $purchaseInvoices->update($invoice, [
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'notes' => $data['notes'] ?? null,
                'lines' => $lines,
                // The edit form only renders payment rows (and this marker
                // field) while the invoice is still a draft — see
                // edit.blade.php. Checking the marker rather than
                // has('payments') matters: removing every row submits no
                // `payments[]` inputs at all, and that "now empty" state must
                // still clear pending_payments, not leave it untouched.
                ...($request->boolean('payments_form') ? ['pending_payments' => $data['payments'] ?? null] : []),
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

    /**
     * Receives a draft/partial invoice in full, posting its journal entry for
     * the first time — and, atomically with that, posts any initial-payment
     * rows the operator saved on the draft (pending_payments), then clears
     * them. Re-running this after they're already cleared is a no-op, same
     * guarantee as store()'s finalize branch.
     */
    public function finalize(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $purchaseInvoices, PaymentRecorder $recorder): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $invoice, $purchaseInvoices, $recorder) {
                $purchaseInvoices->receive($invoice, $invoice->lines->pluck('qty', 'id')->all(), $request->user()->id);

                $pending = $invoice->pending_payments ?? [];
                if ($pending !== []) {
                    $this->postInitialPayments($invoice, $pending, $recorder, $request->user()->id);
                    $invoice->update(['pending_payments' => null]);
                }
            });
        } catch (PeriodLockedException) {
            return back()->withErrors(['invoice_date' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.']);
        }

        return redirect()->route('purchases.show', $invoice)->with('success', 'فاکتور خرید دریافت و سند حسابداری آن صادر شد.');
    }

    /**
     * Record one partial-receiving event: per-outstanding-line qty (+ optional
     * package count/label), one shared date/notes for the whole event. Open to
     * warehouse too — this is a physical warehouse action, unlike everything
     * else in Purchasing (see routes/web.php's role grouping).
     */
    public function storeReceipt(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        $data = $request->validate([
            'received_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.qty' => 'nullable|integer|min:1',
            'lines.*.package_count' => 'nullable|integer|min:1',
            'lines.*.package_label' => 'nullable|string|max:50',
        ]);

        try {
            $purchaseInvoices->recordReceipt($invoice, $data['lines'], [
                'received_at' => $data['received_at'],
                'notes' => $data['notes'] ?? null,
            ], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['received_at' => 'دوره حسابداری این تاریخ قفل است؛ تاریخ دیگری انتخاب کنید.'])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('purchases.show', $invoice)->with('success', 'دریافت کالا ثبت شد.');
    }

    /**
     * One-click "received"/"unreceived" for a line untouched since it was
     * ordered — see PurchaseInvoiceService::toggleReceived()'s docblock for
     * exactly when the OFF direction is (and isn't) allowed.
     */
    public function toggleReceipt(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceLine $line, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        abort_unless($line->purchase_invoice_id === $invoice->id, 404);

        $data = $request->validate(['received' => 'required|boolean']);

        try {
            $purchaseInvoices->toggleReceived($line, $data['received'], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['lines' => 'دوره حسابداری این تاریخ قفل است.']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $invoice)->with('success', $data['received'] ? 'ردیف به‌صورت کامل دریافت‌شده علامت خورد.' : 'وضعیت دریافت ردیف بازگردانده شد.');
    }

    /** In-place correction of an already-recorded delivery's quantity — see PurchaseInvoiceService::updateReceiptLine(). */
    public function updateReceiptLine(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceReceiptLine $receiptLine, PurchaseInvoiceService $purchaseInvoices): RedirectResponse
    {
        abort_unless($receiptLine->invoiceLine->purchase_invoice_id === $invoice->id, 404);

        $data = $request->validate([
            'qty' => 'required|integer|min:0',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $purchaseInvoices->updateReceiptLine($receiptLine, $data['qty'], $data['reason'], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['lines' => 'دوره حسابداری این تاریخ قفل است.']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $invoice)->with('success', 'تعداد دریافتی اصلاح شد.');
    }

    /** Goods physically returned to the supplier — only from qty already received and not already returned. */
    public function storeReturn(Request $request, PurchaseInvoice $invoice, PurchaseReturnService $returns): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.qty' => 'nullable|integer|min:1',
        ]);

        $lines = collect($data['lines'])->map(fn ($line, $lineId) => ['line_id' => $lineId, 'qty' => $line['qty'] ?? 0])->values()->all();

        try {
            $returns->create($invoice, $lines, $data['reason'], $request->user()->id);
        } catch (PeriodLockedException) {
            return back()->withErrors(['reason' => 'دوره حسابداری قفل است.']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('purchases.show', $invoice)->with('success', 'برگشت از خرید ثبت شد.');
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
            ->get(['id', 'name', 'sku', 'type', 'stock_quantity', 'stock_status', 'payload']);

        return response()->json($products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'type' => $p->type,
            'stock_quantity' => $p->stock_quantity,
            'stock_status' => $p->stock_status,
            'thumbnail_url' => $p->thumbnailUrl(),
        ]));
    }

    private function validateInvoice(Request $request): array
    {
        return $request->validate([
            'supplier_party_id' => ['nullable', 'integer', new PartyHasRole(PartyRoleType::Supplier)],
            'new_supplier_name' => 'nullable|string|max:150|required_without:supplier_party_id',
            'invoice_no' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'shipping_cost' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'lines' => 'required|array|min:1',
            'lines.*.product_mirror_id' => 'nullable|exists:product_mirror,id',
            'lines.*.cost_item_id' => 'nullable|exists:cost_items,id',
            'lines.*.new_item_name' => 'nullable|string|max:150',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|integer|min:1',
            'lines.*.note' => 'nullable|string|max:255',
            ...$this->paymentRules(),
        ]);
    }

    /**
     * A draft stores these rows unposted (PurchaseInvoice::pending_payments)
     * so the edit form can restore them; only action=finalize ever reads them
     * through postInitialPayments() to actually post money.
     */
    private function paymentRules(): array
    {
        return [
            'payments' => 'nullable|array',
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id|required_with:payments.*.amount',
            'payments.*.amount' => 'nullable|integer|min:1',
            'payments.*.method' => 'nullable|string|max:50',
            'payments.*.reference' => 'nullable|string|max:100',
            'payments.*.note' => 'nullable|string|max:255',
        ];
    }

    /**
     * Post each initial-payment row entered on the create form through the
     * existing supplier-pay flow (PaymentRecorder::pay() — same one
     * SupplierController::pay() uses), dated to the invoice and tagged with
     * the invoice's uuid as correlation_id so the invoice page can list them
     * later without a new column. Rows with no amount/bank account are
     * skipped — the form allows blank rows.
     */
    private function postInitialPayments(PurchaseInvoice $invoice, array $payments, PaymentRecorder $recorder, int $by): void
    {
        foreach ($payments as $payment) {
            $amount = (int) ($payment['amount'] ?? 0);
            $bankAccountId = $payment['bank_account_id'] ?? null;

            if ($amount < 1 || ! $bankAccountId) {
                continue;
            }

            $recorder->pay(
                $invoice->supplier,
                $amount,
                (int) $bankAccountId,
                $by,
                $payment['method'] ?? null,
                $payment['reference'] ?? null,
                $invoice->invoice_date->toDateString(),
                $payment['note'] ?? null,
                $invoice->uuid,
            );
        }
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

<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Channels\Models\Channel;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\BadDebtWriteOff;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Services\CreditOrderService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\ReceivablesService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Customer management: a read view over `parties` (type=customer) derived
 * from orders (see CustomerResolver — every order is already linked to a
 * Party, deduped by hub_customer_id or phone). Sensitive (profit, purchase
 * volume) — kept to admin|accountant in routes/web.php.
 */
class CustomerController extends Controller
{
    /** Financial states that count as a completed, valid sale. */
    private const VALID_STATES = ['valid'];

    /** Financial states rolled into the red "لغوشده" bucket on the customer list. */
    private const VOID_STATES = ['cancelled', 'void', 'refunded', 'partially_refunded'];

    public function index(Request $request): View
    {
        $channelId = $request->input('channel_id');
        $wholesale = $request->input('wholesale'); // '1' | '0' | null

        // orders_count / total_volume / last_order_at are aggregate aliases added
        // by withCount/withSum/withMax below — sortable by name, same as a column.
        $query = new TableQuery(
            request: $request,
            sortable: [
                'name' => 'name',
                'orders_count' => 'orders_count',
                'total_volume' => 'total_volume',
                'last_order_at' => 'last_order_at',
            ],
            filters: ['channel_id', 'wholesale'],
            defaultSort: '-last_order_at',
        );

        $wholesaleOnly = ($wholesale !== null && $wholesale !== '') ? (bool) $wholesale : null;

        $customers = $this->buildCustomersQuery($query, $channelId, $wholesaleOnly)
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.customers.index', [
            'title' => 'مدیریت مشتریان',
            'customers' => $customers,
            'channelsByCustomer' => $this->channelsByCustomer($customers),
            'filters' => $request->only('search', 'channel_id', 'wholesale'),
            'query' => $query,
            'channels' => Channel::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Same customer list, pre-filtered to wholesale-labeled parties only — no wholesale toggle needed since it's implied. */
    public function wholesaleIndex(Request $request): View
    {
        $channelId = $request->input('channel_id');

        $query = new TableQuery(
            request: $request,
            sortable: [
                'name' => 'name',
                'orders_count' => 'orders_count',
                'total_volume' => 'total_volume',
                'last_order_at' => 'last_order_at',
            ],
            filters: ['channel_id'],
            defaultSort: '-last_order_at',
        );

        $customers = $this->buildCustomersQuery($query, $channelId, true)
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.customers.wholesale-index', [
            'title' => 'مشتریان عمده',
            'customers' => $customers,
            'channelsByCustomer' => $this->channelsByCustomer($customers),
            'filters' => $request->only('search', 'channel_id'),
            'query' => $query,
            'channels' => Channel::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Shared aggregate query behind both the full and wholesale-only customer lists. */
    private function buildCustomersQuery(TableQuery $query, ?string $channelId, ?bool $wholesaleOnly)
    {
        return Party::query()
            ->withRole(PartyRoleType::Customer)
            ->whereHas('orders') // hide order-less duplicates left behind by acc:customers:merge-duplicates (never deleted, just emptied)
            ->when($query->search(), fn ($q, string $search) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('telegram_id', 'like', "%{$search}%")))
            ->when(filled($channelId), fn ($q) => $q->whereHas('orders', fn ($o) => $o->where('channel_id', $channelId)))
            ->when($wholesaleOnly !== null, fn ($q) => $q->where('is_wholesale', $wholesaleOnly))
            ->withCount([
                'orders as orders_count',
                'orders as paid_count' => fn ($q) => $q->whereIn('financial_state', self::VALID_STATES),
                'orders as pending_count' => fn ($q) => $q->where('financial_state', 'pending'),
                'orders as void_count' => fn ($q) => $q->whereIn('financial_state', self::VOID_STATES),
            ])
            ->withSum(['orders as total_volume' => fn ($q) => $q->whereIn('financial_state', self::VALID_STATES)], 'total')
            ->withMax('orders as last_order_at', 'order_date');
    }

    /** Channel names each listed customer has ordered through, keyed by party id. */
    private function channelsByCustomer($customers)
    {
        return Order::whereIn('customer_party_id', $customers->pluck('id'))
            ->with('channel:id,name')
            ->get(['id', 'customer_party_id', 'channel_id'])
            ->groupBy('customer_party_id')
            ->map(fn ($orders) => $orders->pluck('channel.name')->filter()->unique()->values());
    }

    public function show(Party $party, ReceivablesService $receivables): View
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $orders = $party->orders()
            ->with('channel', 'profit')
            ->orderByDesc('order_date')
            ->paginate(15, ['*'], 'orders_page');

        $validOrderIds = $party->orders()->whereIn('financial_state', self::VALID_STATES)->pluck('id');

        $profitSum = Order::whereIn('orders.id', $validOrderIds)
            ->where('orders.profit_status', 'ok')
            ->join('order_profits', 'order_profits.order_id', '=', 'orders.id')
            ->sum('order_profits.operational_profit');

        $unresolvedProfitCount = Order::whereIn('id', $validOrderIds)
            ->where('profit_status', '!=', 'ok')
            ->count();

        $totalVolume = Order::whereIn('id', $validOrderIds)->sum('total');

        $summary = [
            'orders_count' => $party->orders()->count(),
            'paid_count' => Order::whereIn('id', $validOrderIds)->count(),
            'pending_count' => $party->orders()->where('financial_state', 'pending')->count(),
            'void_count' => $party->orders()->whereIn('financial_state', self::VOID_STATES)->count(),
            'total_volume' => $totalVolume,
            'profit' => $profitSum,
            'unresolved_profit_count' => $unresolvedProfitCount,
            'last_order_at' => $party->orders()->max('order_date'),
            'channels' => $party->orders()->with('channel:id,name')->get()->pluck('channel.name')->filter()->unique()->values(),
        ];

        $payments = PartyPayment::where('party_id', $party->id)
            ->where('direction', 'in')
            ->with('bankAccount', 'settlements.creditOrder.order')
            ->get()
            ->map(fn (PartyPayment $p) => ['kind' => 'payment', 'at' => $p->created_at, 'model' => $p]);

        $writeOffs = BadDebtWriteOff::where('party_id', $party->id)
            ->with('settlements.creditOrder.order')
            ->get()
            ->map(fn (BadDebtWriteOff $w) => ['kind' => 'write_off', 'at' => $w->created_at, 'model' => $w]);

        // Manually opened credit sales (CreditOrderService::openManual) — a
        // real order's own CreditOrder is already visible on that order's
        // page, so only the ones with no linked order (a debt created by
        // hand, not by a sale) belong in this "created debt" history.
        $creditSales = CreditOrder::where('party_id', $party->id)
            ->whereNull('order_id')
            ->get()
            ->map(fn (CreditOrder $c) => ['kind' => 'credit_sale', 'at' => $c->created_at, 'model' => $c]);

        $settlementHistory = $payments->concat($writeOffs)->concat($creditSales)->sortByDesc('at')->values();

        return view('pages.customers.show', [
            'title' => 'مشتری: '.$party->name,
            'party' => $party,
            'orders' => $orders,
            'summary' => $summary,
            'balance' => [
                'open' => $receivables->partyOpenBalance($party),
                'credit' => $receivables->customerCreditBalance($party),
                'net' => $receivables->partyNetBalance($party),
            ],
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name']),
            'settlementHistory' => $settlementHistory,
        ]);
    }

    /** Toggle the "wholesale customer" label — informational/searchable only, never touches pricing or journals. */
    public function setWholesale(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate(['is_wholesale' => ['required', 'boolean']]);

        $party->update([
            'is_wholesale' => $data['is_wholesale'],
            'wholesale_labeled_at' => now(),
            'wholesale_labeled_by' => $request->user()->id,
        ]);

        return back()->with('success', $data['is_wholesale'] ? 'مشتری به‌عنوان «مشتری عمده» علامت‌گذاری شد.' : 'برچسب «مشتری عمده» حذف شد.');
    }

    /** Quick manual phone entry for guest customers CustomerResolver could only identify by name. */
    public function setPhone(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $party->update(['phone' => $data['phone']]);

        return back()->with('success', 'شماره تماس ثبت شد.');
    }

    /** Save/edit a customer's Telegram ID (chat id used by TelegramNotifier) — same validation rule as the user-facing profile field. */
    public function setTelegramId(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate(['telegram_id' => ['nullable', 'string', 'max:255']]);

        $party->update(['telegram_id' => $data['telegram_id'] ?? null]);

        return back()->with('success', 'آیدی تلگرام ثبت شد.');
    }

    /**
     * One-click "record a settlement" — allocates across the customer's open
     * orders oldest-first (CreditOrderAllocator), same action from both the
     * order page and this customer page since it's the same underlying party.
     */
    public function recordSettlement(Request $request, Party $party, PaymentRecorder $recorder): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
        ]);

        $recorder->receiveForCustomer($party, $data['amount'], $data['bank_account_id'], $request->user()->id);

        return back()->with('success', 'تسویه با مشتری ثبت شد.');
    }

    /** Manual balance increase — a real credit sale (goods/service given, payment expected later). */
    public function storeCreditSale(Request $request, Party $party, CreditOrderService $service): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $service->openManual($party, $data['amount'], $data['description'], null, $request->user()->id);

        return back()->with('success', 'فروش اعتباری ثبت شد.');
    }

    /** Manual balance decrease — forgiving/writing off part of what the customer owes, never silently. */
    public function storeWriteOff(Request $request, Party $party, CreditOrderService $service, ReceivablesService $receivables): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Customer), 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        if ($data['amount'] > $receivables->partyOpenBalance($party)) {
            return back()->withErrors(['amount' => 'بیش از مانده بدهکاری این مشتری قابل سوخت‌کردن نیست.'])->withInput();
        }

        try {
            $service->writeOff($party, $data['amount'], $data['description'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'مطالبات سوخت‌شده ثبت شد.');
    }
}

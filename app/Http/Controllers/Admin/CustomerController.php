<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Channels\Models\Channel;
use App\Domain\Orders\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $search = $request->string('search')->trim()->value();
        $channelId = $request->input('channel_id');
        $wholesale = $request->input('wholesale'); // '1' | '0' | null
        $sort = $request->string('sort', 'last_order_at')->value();
        $dir = $request->string('dir', 'desc')->value() === 'asc' ? 'asc' : 'desc';

        $sortable = ['name', 'orders_count', 'total_volume', 'last_order_at'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'last_order_at';
        }

        $customers = Party::query()
            ->where('type', 'customer')
            ->whereHas('orders') // hide order-less duplicates left behind by acc:customers:merge-duplicates (never deleted, just emptied)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(filled($channelId), fn ($q) => $q->whereHas('orders', fn ($o) => $o->where('channel_id', $channelId)))
            ->when($wholesale !== null && $wholesale !== '', fn ($q) => $q->where('is_wholesale', (bool) $wholesale))
            ->withCount([
                'orders as orders_count',
                'orders as paid_count' => fn ($q) => $q->whereIn('financial_state', self::VALID_STATES),
                'orders as pending_count' => fn ($q) => $q->where('financial_state', 'pending'),
                'orders as void_count' => fn ($q) => $q->whereIn('financial_state', self::VOID_STATES),
            ])
            ->withSum(['orders as total_volume' => fn ($q) => $q->whereIn('financial_state', self::VALID_STATES)], 'total')
            ->withMax('orders as last_order_at', 'order_date')
            ->orderBy($sort, $dir)
            ->paginate(20)
            ->withQueryString();

        $channelsByCustomer = Order::whereIn('customer_party_id', $customers->pluck('id'))
            ->with('channel:id,name')
            ->get(['id', 'customer_party_id', 'channel_id'])
            ->groupBy('customer_party_id')
            ->map(fn ($orders) => $orders->pluck('channel.name')->filter()->unique()->values());

        return view('pages.customers.index', [
            'title' => 'مدیریت مشتریان',
            'customers' => $customers,
            'channelsByCustomer' => $channelsByCustomer,
            'filters' => $request->only('search', 'channel_id', 'wholesale'),
            'sort' => $sort,
            'dir' => $dir,
            'channels' => Channel::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Party $party): View
    {
        abort_if($party->type !== 'customer', 404);

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

        return view('pages.customers.show', [
            'title' => 'مشتری: '.$party->name,
            'party' => $party,
            'orders' => $orders,
            'summary' => $summary,
        ]);
    }

    /** Toggle the "wholesale customer" label — informational/searchable only, never touches pricing or journals. */
    public function setWholesale(Request $request, Party $party): RedirectResponse
    {
        abort_if($party->type !== 'customer', 404);

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
        abort_if($party->type !== 'customer', 404);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $party->update(['phone' => $data['phone']]);

        return back()->with('success', 'شماره تماس ثبت شد.');
    }
}

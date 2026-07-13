<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Channels\Models\Channel;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLabel;
use App\Domain\Orders\Services\ProfitEngine;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        // Sort/search/page-size state lives in the URL, parsed and whitelisted in
        // one place (see App\Support\Design\TableQuery) rather than hand-rolled here.
        $query = new TableQuery(
            request: $request,
            sortable: [
                'hub_order_id' => 'orders.hub_order_id',
                'total' => 'orders.total',
                'shipping_charged' => 'orders.shipping_charged',
                'order_date' => 'orders.order_date',
                'updated_at' => 'orders.updated_at',
                // Profit lives on a joined table; the join is added below only when
                // it is actually sorted on, so the common case stays a single-table read.
                'operational_profit' => 'order_profits.operational_profit',
            ],
            searchable: ['city' => 'orders.city', 'province' => 'orders.province'],
            filters: ['profit_status', 'status', 'payment_status', 'channel_id', 'province', 'date_from', 'date_to'],
            defaultSort: '-order_date',
        );

        $sortsOnProfit = collect($query->sorts())->contains(fn ($s) => $s['key'] === 'operational_profit');

        $orders = Order::query()
            ->select('orders.*')
            ->when($sortsOnProfit, fn ($q) => $q->leftJoin('order_profits', 'order_profits.order_id', '=', 'orders.id'))
            ->with('channel', 'profit', 'customerParty')
            ->when($request->filled('profit_status'), fn ($q) => $q->where('profit_status', $request->string('profit_status')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('channel_id'), function ($q) use ($request) {
                $request->input('channel_id') === 'unmapped'
                    ? $q->whereNull('channel_id')
                    : $q->where('channel_id', $request->integer('channel_id'));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('order_date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('order_date', '<=', $request->string('date_to')))
            ->when($request->filled('province'), fn ($q) => $q->where('province', $request->string('province')))
            ->when($query->search(), function ($q, string $search) {
                // Which columns/relations "search" means is page knowledge, so it stays here.
                $q->where(function ($w) use ($search) {
                    $w->where('hub_order_id', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhereHas('customerParty', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.orders.index', [
            'title' => 'سفارش‌ها',
            'orders' => $orders,
            'query' => $query,
            'filters' => $request->only('profit_status', 'status', 'payment_status', 'channel_id', 'search', 'date_from', 'date_to', 'province'),
            'channels' => Channel::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unmappedCount' => Order::whereNull('channel_id')->count(),
            // Data-driven, never hard-coded: whatever statuses actually exist
            // (including future/unknown ones from any source) show up here.
            'statuses' => Order::select('status')->selectRaw('count(*) as count')
                ->groupBy('status')->orderByDesc('count')->get(),
            'provinces' => Order::whereNotNull('province')->distinct()->orderBy('province')->pluck('province'),
        ]);
    }

    public function show(Order $order, Request $request): View
    {
        $order->load('items.productMirror', 'channel', 'profit.journalEntry', 'shippingCost', 'packagingCost', 'refunds', 'customerParty', 'rawOrder', 'notes.author', 'notes.recipients.user', 'labels', 'creditOrder.settlements.source');

        return view('pages.orders.show', [
            'title' => 'سفارش #'.$order->hub_order_id,
            'order' => $order,
            'noteRecipientOptions' => NoteController::recipientOptions($request->user()->id),
            'availableLabels' => OrderLabel::orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    /** Manual payment-method entry for manually-created orders — WooCommerce never gives the hub one for these. */
    public function setPaymentMethod(Request $request, Order $order): RedirectResponse
    {
        abort_if($order->channel?->slug !== 'manual', 404);

        $data = $request->validate(['payment_method_title' => ['required', 'string', 'max:191']]);

        $order->update(['payment_method_title' => $data['payment_method_title']]);

        return back()->with('success', 'شیوه پرداخت ثبت شد.');
    }

    /** Attach/detach organizational labels (e.g. "سفارش عمده") — informational only, never affects profit/journal. */
    public function syncLabels(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'label_ids' => ['array'],
            'label_ids.*' => ['integer', 'exists:order_labels,id'],
            'new_label_name' => ['nullable', 'string', 'max:40'],
        ]);

        $labelIds = $data['label_ids'] ?? [];

        if (filled($data['new_label_name'] ?? null)) {
            $labelIds[] = OrderLabel::findOrCreateByName($data['new_label_name'])->id;
        }

        $order->labels()->sync($labelIds);

        return back()->with('success', 'لیبل‌های سفارش به‌روزرسانی شد.');
    }

    /**
     * Manual shipping overrides (README §13), each independently optional so
     * staff can correct just one side without touching the other:
     * - real_cost: the actual/real shipping expense (courier cost, etc).
     * - charged_cost: what was actually received from the customer, for when
     *   the hub's synced shipping_charged is wrong — e.g. a free-shipping
     *   discount deal struck with the customer that never reaches
     *   WooCommerce's own shipping_total (see Order::shipping_charged_effective).
     * A blank field is left untouched rather than cleared, so submitting one
     * side never erases a previously-recorded override on the other.
     */
    public function setShipping(Request $request, Order $order, ProfitEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'real_cost' => 'nullable|integer|min:0|required_without:charged_cost',
            'charged_cost' => 'nullable|integer|min:0|required_without:real_cost',
        ]);

        $updates = ['set_by' => $request->user()->id];
        if ($request->filled('real_cost')) {
            $updates['real_cost'] = $data['real_cost'];
        }
        if ($request->filled('charged_cost')) {
            $updates['charged_cost'] = $data['charged_cost'];
        }

        $order->shippingCost()->updateOrCreate([], $updates);

        $engine->evaluate($order->refresh());

        return back()->with('success', 'هزینه حمل ثبت و سود بازمحاسبه شد.');
    }

    /** Manual per-order packaging cost override, taking precedence over the weight-tier/default resolution. */
    public function setPackaging(Request $request, Order $order, ProfitEngine $engine): RedirectResponse
    {
        $data = $request->validate(['real_cost' => 'required|integer|min:0']);

        $order->packagingCost()->updateOrCreate([], [
            'real_cost' => $data['real_cost'],
            'set_by' => $request->user()->id,
        ]);

        $engine->evaluate($order->refresh());

        return back()->with('success', 'هزینه بسته‌بندی ثبت و سود بازمحاسبه شد.');
    }

    /** Drop the manual packaging cost override so it falls back to the weight-tier/default formula again. */
    public function resetPackaging(Order $order, ProfitEngine $engine): RedirectResponse
    {
        $order->packagingCost()->delete();

        $engine->evaluate($order->refresh());

        return back()->with('success', 'هزینه بسته‌بندی به حالت خودکار بازنشانی شد.');
    }

    public function recalc(Order $order, ProfitEngine $engine): RedirectResponse
    {
        $engine->evaluate($order);

        return back()->with('success', 'سود سفارش بازمحاسبه شد.');
    }
}

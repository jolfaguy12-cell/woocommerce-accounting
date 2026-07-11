<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Channels\Models\Channel;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLabel;
use App\Domain\Orders\Services\ProfitEngine;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $orders = Order::with('channel', 'profit', 'customerParty')
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
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->trim()->value();
                $q->where(function ($w) use ($search) {
                    $w->where('hub_order_id', 'like', "%{$search}%")
                        ->orWhereHas('customerParty', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('order_date')
            ->paginate(25)
            ->withQueryString();

        return view('pages.orders.index', [
            'title' => 'سفارش‌ها',
            'orders' => $orders,
            'filters' => $request->only('profit_status', 'status', 'payment_status', 'channel_id', 'search', 'date_from', 'date_to'),
            'channels' => Channel::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unmappedCount' => Order::whereNull('channel_id')->count(),
            // Data-driven, never hard-coded: whatever statuses actually exist
            // (including future/unknown ones from any source) show up here.
            'statuses' => Order::select('status')->selectRaw('count(*) as count')
                ->groupBy('status')->orderByDesc('count')->get(),
        ]);
    }

    public function show(Order $order, Request $request): View
    {
        $order->load('items.productMirror', 'channel', 'profit.journalEntry', 'shippingCost', 'packagingCost', 'refunds', 'customerParty', 'rawOrder', 'notes.author', 'notes.recipients.user', 'labels');

        return view('pages.orders.show', [
            'title' => 'سفارش #'.$order->hub_order_id,
            'order' => $order,
            'noteRecipientOptions' => NoteController::recipientOptions($request->user()->id),
            'availableLabels' => OrderLabel::orderBy('name')->get(),
        ]);
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

    /** Manual real shipping cost (README §13) then re-evaluate profit. */
    public function setShipping(Request $request, Order $order, ProfitEngine $engine): RedirectResponse
    {
        $data = $request->validate(['real_cost' => 'required|integer|min:0']);

        $order->shippingCost()->updateOrCreate([], [
            'real_cost' => $data['real_cost'],
            'set_by' => $request->user()->id,
        ]);

        $engine->evaluate($order->refresh());

        return back()->with('success', 'هزینه حمل واقعی ثبت و سود بازمحاسبه شد.');
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

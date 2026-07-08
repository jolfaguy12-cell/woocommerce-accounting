<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::with('channel', 'profit')
            ->when($request->filled('period'), fn ($q) => $q->where('jalali_period', $request->string('period')))
            ->when($request->filled('profit_status'), fn ($q) => $q->where('profit_status', $request->string('profit_status')))
            ->orderByDesc('order_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($order) => [
                'id' => $order->id,
                'hub_order_id' => $order->hub_order_id,
                'status' => $order->status,
                'financial_state' => $order->financial_state,
                'profit_status' => $order->profit_status,
                'jalali_period' => $order->jalali_period,
                'channel' => $order->channel?->name,
                'total' => $order->total,
                'operational_profit' => $order->profit?->operational_profit,
                'order_date' => $order->order_date->toIso8601String(),
            ]);

        return Inertia::render('orders/index', ['orders' => $orders, 'filters' => $request->only('period', 'profit_status')]);
    }

    public function show(Order $order): Response
    {
        $order->load('items.productMirror', 'channel', 'profit.journalEntry', 'shippingCost', 'refunds');

        return Inertia::render('orders/show', [
            'order' => [
                'id' => $order->id,
                'hub_order_id' => $order->hub_order_id,
                'status' => $order->status,
                'financial_state' => $order->financial_state,
                'profit_status' => $order->profit_status,
                'jalali_period' => $order->jalali_period,
                'channel' => $order->channel?->name,
                'raw_source' => $order->raw_source_value,
                'total' => $order->total,
                'discount_total' => $order->discount_total,
                'shipping_charged' => $order->shipping_charged,
                'payment_method_title' => $order->payment_method_title,
                'order_date' => $order->order_date->toIso8601String(),
                'real_shipping_cost' => $order->shippingCost?->real_cost,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name, 'qty' => $i->qty, 'unit_price' => $i->unit_price,
                    'line_total' => $i->line_total, 'mapped' => $i->product_mirror_id !== null,
                    'hub_product_id' => $i->hub_product_id,
                ]),
                'profit' => $order->profit,
                'refunds' => $order->refunds,
            ],
        ]);
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

    public function recalc(Order $order, ProfitEngine $engine): RedirectResponse
    {
        $engine->evaluate($order);

        return back()->with('success', 'سود سفارش بازمحاسبه شد.');
    }
}

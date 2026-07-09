import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');

type Item = { name: string; qty: number; unit_price: number; line_total: number; mapped: boolean; hub_product_id: number | null };
type Profit = {
    version: number;
    status: string;
    gross_sale: number;
    discounts: number;
    net_sale: number;
    product_cost: number | null;
    cost_breakdown: { item: string; qty: number; unit_cost: number; line_cost: number; source: string }[] | null;
    shipping_charged: number;
    shipping_real: number | null;
    shipping_basis: string | null;
    channel_fee: number;
    gross_profit: number | null;
    operational_profit: number | null;
} | null;

type OrderData = {
    id: number;
    hub_order_id: number;
    status: string;
    financial_state: string;
    profit_status: string;
    jalali_period: string;
    channel: string | null;
    raw_source: string | null;
    total: number;
    discount_total: number;
    shipping_charged: number;
    payment_method_title: string | null;
    order_date: string;
    real_shipping_cost: number | null;
    items: Item[];
    profit: Profit;
    refunds: { id: number; amount: number; reason: string }[];
};

export default function OrderShow({ order }: { order: OrderData }) {
    const [shipping, setShipping] = useState<string>(order.real_shipping_cost?.toString() ?? '');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'سفارش‌ها', href: '/orders' },
                { title: `#${order.hub_order_id}`, href: '#' },
            ]}
        >
            <Head title={`سفارش ${order.hub_order_id}`} />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold">سفارش #{order.hub_order_id}</h1>
                    <Badge variant="outline">{order.status}</Badge>
                    <Badge variant="secondary">{order.financial_state}</Badge>
                    <Badge variant={order.profit_status === 'ok' ? 'default' : 'destructive'}>{order.profit_status}</Badge>
                    <span className="text-muted-foreground text-sm">
                        {order.jalali_period} · کانال: {order.channel ?? order.raw_source ?? '—'} · {order.payment_method_title ?? ''}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        className="mr-auto"
                        onClick={() => router.post(`/orders/${order.id}/recalc`, {}, { preserveScroll: true })}
                    >
                        بازمحاسبه سود
                    </Button>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">اقلام سفارش</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-right">
                                        <th className="py-2 font-normal">کالا</th>
                                        <th className="font-normal">تعداد</th>
                                        <th className="font-normal">فی</th>
                                        <th className="font-normal">جمع</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {order.items.map((item, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="py-2">
                                                {item.name}{' '}
                                                {!item.mapped && (
                                                    <Badge variant="destructive" className="mr-1">
                                                        بدون نگاشت
                                                    </Badge>
                                                )}
                                            </td>
                                            <td>{fmt(item.qty)}</td>
                                            <td dir="ltr">{fmt(item.unit_price)}</td>
                                            <td dir="ltr">{fmt(item.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="text-muted-foreground mt-3 space-y-1 border-t pt-2 text-sm">
                                <div className="flex justify-between">
                                    <span>تخفیف</span>
                                    <span dir="ltr">{fmt(order.discount_total)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>حمل دریافتی از مشتری</span>
                                    <span dir="ltr">{fmt(order.shipping_charged)}</span>
                                </div>
                                <div className="text-foreground flex justify-between font-medium">
                                    <span>جمع کل</span>
                                    <span dir="ltr">{fmt(order.total)}</span>
                                </div>
                            </div>

                            <div className="mt-4 flex items-center gap-2 border-t pt-3">
                                <span className="text-sm">هزینه حمل واقعی:</span>
                                <Input
                                    className="h-9 w-36"
                                    dir="ltr"
                                    type="number"
                                    value={shipping}
                                    onChange={(e) => setShipping(e.target.value)}
                                    placeholder="تومان"
                                />
                                <Button
                                    size="sm"
                                    disabled={shipping === ''}
                                    onClick={() =>
                                        router.post(`/orders/${order.id}/shipping`, { real_cost: Number(shipping) }, { preserveScroll: true })
                                    }
                                >
                                    ثبت و بازمحاسبه
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                تفکیک سود{' '}
                                {order.profit && (
                                    <span className="text-muted-foreground text-xs font-normal">
                                        (نسخه {fmt(order.profit.version)} — {order.profit.status})
                                    </span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            {!order.profit && <p className="text-muted-foreground">سودی محاسبه نشده (سفارش هنوز معتبر نیست یا در صف است).</p>}
                            {order.profit && (
                                <>
                                    {(
                                        [
                                            ['فروش ناخالص', order.profit.gross_sale],
                                            ['تخفیف', -order.profit.discounts],
                                            ['فروش خالص', order.profit.net_sale],
                                            ['بهای تمام‌شده', order.profit.product_cost !== null ? -order.profit.product_cost : null],
                                            ['حمل دریافتی', order.profit.shipping_charged],
                                            [
                                                `حمل واقعی (${order.profit.shipping_basis ?? '—'})`,
                                                order.profit.shipping_real !== null ? -order.profit.shipping_real : null,
                                            ],
                                            ['کارمزد کانال', -order.profit.channel_fee],
                                        ] as [string, number | null][]
                                    ).map(([label, value]) => (
                                        <div key={label} className="flex justify-between border-b py-1 last:border-0">
                                            <span className="text-muted-foreground">{label}</span>
                                            <span dir="ltr" className={value !== null && value < 0 ? 'text-red-600' : ''}>
                                                {value !== null ? fmt(value) : 'نامشخص'}
                                            </span>
                                        </div>
                                    ))}
                                    <div className="flex justify-between py-2 text-base font-bold">
                                        <span>سود عملیاتی</span>
                                        <span dir="ltr" className={(order.profit.operational_profit ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600'}>
                                            {order.profit.operational_profit !== null ? fmt(order.profit.operational_profit) : 'مسدود'}
                                        </span>
                                    </div>
                                    {order.profit.cost_breakdown && order.profit.cost_breakdown.length > 0 && (
                                        <div className="bg-muted/50 mt-2 rounded-md p-2 text-xs">
                                            {order.profit.cost_breakdown.map((c, i) => (
                                                <div key={i} className="flex justify-between py-0.5">
                                                    <span>
                                                        {c.item} ×{fmt(c.qty)} <span className="text-muted-foreground">({c.source})</span>
                                                    </span>
                                                    <span dir="ltr">{fmt(c.line_cost)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </>
                            )}
                            {order.refunds.length > 0 && (
                                <div className="mt-2 border-t pt-2">
                                    {order.refunds.map((r) => (
                                        <div key={r.id} className="flex justify-between text-red-600">
                                            <span>برگشت: {r.reason}</span>
                                            <span dir="ltr">-{fmt(r.amount)}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

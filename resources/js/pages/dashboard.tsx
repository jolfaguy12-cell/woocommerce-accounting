import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'داشبورد', href: '/dashboard' }];

type TrendPoint = { date: string; label: string; net_sales: number; operational_profit: number; orders: number };
type ChannelRow = {
    name: string;
    orders: number;
    net_sales: number;
    operational_profit: number;
    period_cost: number;
    final_profitability: number;
};

type DashboardProps = {
    dashboard: {
        period: string;
        can_see_financials: boolean;
        financials: {
            kpis: {
                net_sales: number;
                operational_profit: number;
                net_period_profit: number;
                orders: number;
                average_order_value: number;
                expenses: number;
            };
            trend: TrendPoint[];
            channels: Record<string, ChannelRow>;
            balances: Record<string, number>;
        } | null;
        operations: {
            review: Record<string, number>;
            sync: { webhooks: Record<string, number>; last_order_poll: string | null; dead_events: number };
            blocked_orders: number;
            unknown_source_orders: number;
        };
    };
};

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');

const reviewLabels: Record<string, string> = {
    missing_cost: 'بدون بهای تمام‌شده',
    unmapped_product: 'محصول بدون نگاشت',
    unknown_source: 'منبع ناشناخته',
    missing_shipping: 'هزینه حمل ناقص',
    missing_commission: 'کارمزد ناموجود',
    sync_error: 'خطای همگام‌سازی',
    late_entry: 'ثبت دیرهنگام',
    low_margin: 'حاشیه سود پایین',
    credit_overdue: 'طلب سررسید گذشته',
};

const balanceLabels: Record<string, string> = {
    banks_and_cash: 'بانک و صندوق',
    receivables: 'حساب‌های دریافتنی',
    cheques_receivable: 'چک‌های دریافتنی',
    inventory: 'موجودی کالا',
    payables: 'حساب‌های پرداختنی',
    cheques_payable: 'چک‌های پرداختنی',
    loans: 'وام‌ها',
    customer_credit: 'اعتبار مشتریان',
};

function Kpi({ title, value, suffix = 'تومان', highlight = false }: { title: string; value: number; suffix?: string; highlight?: boolean }) {
    return (
        <Card>
            <CardHeader className="pb-1">
                <CardTitle className="text-sm font-normal text-muted-foreground">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className={`text-2xl font-bold ${highlight ? (value >= 0 ? 'text-emerald-600' : 'text-red-600') : ''}`} dir="ltr">
                    {fmt(value)} <span className="text-xs font-normal text-muted-foreground">{suffix}</span>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Dashboard() {
    const { dashboard } = usePage<DashboardProps>().props;
    const fin = dashboard.financials;
    const ops = dashboard.operations;
    const reviewEntries = Object.entries(ops.review);
    const channels = fin ? Object.entries(fin.channels) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="داشبورد" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4" dir="rtl">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-bold">دوره جاری: {dashboard.period}</h1>
                    {ops.sync.dead_events > 0 && <Badge variant="destructive">{fmt(ops.sync.dead_events)} رویداد ناموفق sync</Badge>}
                </div>

                {fin && (
                    <>
                        <div className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                            <Kpi title="فروش خالص دوره" value={fin.kpis.net_sales} />
                            <Kpi title="سود عملیاتی" value={fin.kpis.operational_profit} highlight />
                            <Kpi title="سود خالص دوره" value={fin.kpis.net_period_profit} highlight />
                            <Kpi title="تعداد سفارش" value={fin.kpis.orders} suffix="سفارش" />
                            <Kpi title="میانگین سفارش" value={fin.kpis.average_order_value} />
                            <Kpi title="هزینه‌های دوره" value={fin.kpis.expenses} />
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">روند فروش و سود (۳۰ روز اخیر)</CardTitle>
                            </CardHeader>
                            <CardContent className="h-72" dir="ltr">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={fin.trend} margin={{ top: 5, right: 10, left: 10, bottom: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis dataKey="label" fontSize={11} />
                                        <YAxis fontSize={11} tickFormatter={(v: number) => (v / 1_000_000).toFixed(1) + 'M'} />
                                        <Tooltip
                                            formatter={(v: number, name: string) => [
                                                fmt(v) + ' تومان',
                                                name === 'net_sales' ? 'فروش خالص' : 'سود عملیاتی',
                                            ]}
                                        />
                                        <Line type="monotone" dataKey="net_sales" stroke="#2563eb" strokeWidth={2} dot={false} name="net_sales" />
                                        <Line
                                            type="monotone"
                                            dataKey="operational_profit"
                                            stroke="#16a34a"
                                            strokeWidth={2}
                                            dot={false}
                                            name="operational_profit"
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">عملکرد کانال‌های فروش</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-right text-muted-foreground">
                                                <th className="py-2 font-normal">کانال</th>
                                                <th className="font-normal">سفارش</th>
                                                <th className="font-normal">فروش خالص</th>
                                                <th className="font-normal">هزینه دوره</th>
                                                <th className="font-normal">سودآوری نهایی</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {channels.length === 0 && (
                                                <tr>
                                                    <td colSpan={5} className="py-4 text-center text-muted-foreground">
                                                        سفارشی در این دوره نیست
                                                    </td>
                                                </tr>
                                            )}
                                            {channels.map(([slug, ch]) => (
                                                <tr key={slug} className="border-b last:border-0">
                                                    <td className="py-2">{ch.name}</td>
                                                    <td>{fmt(ch.orders)}</td>
                                                    <td>{fmt(ch.net_sales)}</td>
                                                    <td>{fmt(ch.period_cost)}</td>
                                                    <td className={ch.final_profitability >= 0 ? 'text-emerald-600' : 'text-red-600'}>
                                                        {fmt(ch.final_profitability)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">مانده حساب‌ها</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-3 text-sm">
                                        {Object.entries(fin.balances).map(([key, value]) => (
                                            <div key={key} className="flex justify-between rounded-lg border p-2">
                                                <span className="text-muted-foreground">{balanceLabels[key] ?? key}</span>
                                                <span dir="ltr">{fmt(value)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">صف بازبینی</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {reviewEntries.length === 0 && <span className="text-sm text-muted-foreground">موردی برای بازبینی نیست ✅</span>}
                            {reviewEntries.map(([type, count]) => (
                                <Badge key={type} variant="secondary" className="text-sm">
                                    {reviewLabels[type] ?? type}: {fmt(count)}
                                </Badge>
                            ))}
                            {ops.blocked_orders > 0 && <Badge variant="destructive">سفارش بلاک‌شده: {fmt(ops.blocked_orders)}</Badge>}
                            {ops.unknown_source_orders > 0 && <Badge variant="outline">سفارش با منبع ناشناخته: {fmt(ops.unknown_source_orders)}</Badge>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">سلامت همگام‌سازی</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(ops.sync.webhooks).map(([status, count]) => (
                                    <Badge key={status} variant={status === 'dead' || status === 'failed' ? 'destructive' : 'secondary'}>
                                        webhook {status}: {fmt(count)}
                                    </Badge>
                                ))}
                                {Object.keys(ops.sync.webhooks).length === 0 && (
                                    <span className="text-muted-foreground">هنوز webhook دریافت نشده</span>
                                )}
                            </div>
                            <div className="text-muted-foreground">
                                آخرین poll سفارش‌ها:{' '}
                                {ops.sync.last_order_poll ? new Date(ops.sync.last_order_poll).toLocaleString('fa-IR') : 'هنوز اجرا نشده'}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

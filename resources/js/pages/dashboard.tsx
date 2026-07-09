import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    type LucideIcon,
    PackageSearch,
    Receipt,
    RefreshCw,
    Scale,
    ShoppingCart,
    TrendingUp,
    Trophy,
    Wallet,
} from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

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
type RecentOrder = {
    id: number;
    hub_order_id: number;
    status: string;
    total: number;
    channel: string | null;
    profit_status: string;
    date_label: string;
};
type TopProduct = { name: string; qty: number; revenue: number; product_mirror_id: number | null };
type LowStockRow = { id: number; name: string; sku: string | null; stock_quantity: number };

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
            recent_orders: RecentOrder[];
            top_products: TopProduct[];
        } | null;
        operations: {
            review: Record<string, number>;
            sync: { webhooks: Record<string, number>; last_order_poll: string | null; dead_events: number };
            blocked_orders: number;
            unknown_source_orders: number;
            low_stock: LowStockRow[];
        };
    };
};

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');
const fmtCompact = (n: number) => (Math.abs(n) >= 1_000_000 ? `${(n / 1_000_000).toLocaleString('fa-IR', { maximumFractionDigits: 1 })} م` : fmt(n));

const reviewLabels: Record<string, string> = {
    missing_cost: 'بدون بهای تمام‌شده',
    unmapped_product: 'محصول بدون نگاشت',
    unknown_source: 'منبع ناشناخته',
    missing_shipping: 'هزینه حمل ناقص',
    missing_commission: 'کارمزد ناموجود',
    sync_error: 'خطای همگام‌سازی',
    late_entry: 'ثبت دیرهنگام',
    low_margin: 'حاشیه سود پایین',
    credit_overdue: 'طلب سررسیدگذشته',
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

const orderStatusLabels: Record<string, string> = {
    completed: 'تکمیل‌شده',
    processing: 'در حال انجام',
    'on-hold': 'در انتظار بررسی',
    pending: 'در انتظار پرداخت',
    cancelled: 'لغوشده',
    refunded: 'مستردشده',
    failed: 'ناموفق',
};

function Kpi({
    title,
    value,
    icon: Icon,
    suffix = 'تومان',
    highlight = false,
}: {
    title: string;
    value: number;
    icon: LucideIcon;
    suffix?: string;
    highlight?: boolean;
}) {
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="flex flex-row items-center justify-between px-4 pb-0">
                <CardTitle className="text-muted-foreground text-xs font-medium">{title}</CardTitle>
                <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
                    <Icon className="size-4" />
                </span>
            </CardHeader>
            <CardContent className="px-4">
                <div className={`text-xl font-bold lg:text-2xl ${highlight ? (value >= 0 ? 'text-success' : 'text-destructive') : ''}`}>
                    {fmt(value)} <span className="text-muted-foreground text-xs font-normal">{suffix}</span>
                </div>
            </CardContent>
        </Card>
    );
}

function SectionCard({
    title,
    icon: Icon,
    children,
    action,
}: {
    title: string;
    icon: LucideIcon;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <Card className="gap-3">
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className="text-muted-foreground size-4" />
                    {title}
                </CardTitle>
                {action}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function EmptyState({ text }: { text: string }) {
    return <p className="text-muted-foreground py-6 text-center text-sm">{text}</p>;
}

export default function Dashboard() {
    const { dashboard } = usePage<DashboardProps>().props;
    const fin = dashboard.financials;
    const ops = dashboard.operations;
    const reviewEntries = Object.entries(ops.review);
    const channels = fin ? Object.entries(fin.channels) : [];
    const totalReview = reviewEntries.reduce((sum, [, count]) => sum + count, 0);

    const alerts: { text: string; href: string }[] = [];
    if (ops.blocked_orders > 0) alerts.push({ text: `${fmt(ops.blocked_orders)} سفارش به دلیل نبود بهای تمام‌شده مسدود است`, href: '/review' });
    if (ops.sync.dead_events > 0) alerts.push({ text: `${fmt(ops.sync.dead_events)} رویداد همگام‌سازی ناموفق مانده است`, href: '/review' });
    if (ops.unknown_source_orders > 0)
        alerts.push({ text: `${fmt(ops.unknown_source_orders)} سفارش با منبع فروش ناشناخته ثبت شده است`, href: '/review' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="داشبورد" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-bold">نمای کلی کسب‌وکار</h1>
                        <p className="text-muted-foreground text-sm">دوره جاری: {dashboard.period}</p>
                    </div>
                    {totalReview > 0 && (
                        <Link href="/review">
                            <Badge variant="secondary" className="gap-1 px-3 py-1.5 text-sm">
                                <PackageSearch className="size-3.5" />
                                {fmt(totalReview)} مورد در صف بازبینی
                            </Badge>
                        </Link>
                    )}
                </div>

                {alerts.length > 0 && (
                    <div className="grid gap-2">
                        {alerts.map((alert, i) => (
                            <Link
                                key={i}
                                href={alert.href}
                                className="border-warning/40 bg-warning/10 text-foreground hover:bg-warning/20 flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm transition-colors"
                            >
                                <AlertTriangle className="text-warning size-4 shrink-0" />
                                {alert.text}
                            </Link>
                        ))}
                    </div>
                )}

                {fin && (
                    <>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <Kpi title="فروش خالص دوره" value={fin.kpis.net_sales} icon={Banknote} />
                            <Kpi title="سود عملیاتی" value={fin.kpis.operational_profit} icon={TrendingUp} highlight />
                            <Kpi title="سود خالص دوره" value={fin.kpis.net_period_profit} icon={Scale} highlight />
                            <Kpi title="تعداد سفارش" value={fin.kpis.orders} icon={ShoppingCart} suffix="سفارش" />
                            <Kpi title="میانگین سفارش" value={fin.kpis.average_order_value} icon={Receipt} />
                            <Kpi title="هزینه‌های دوره" value={fin.kpis.expenses} icon={Wallet} />
                        </div>

                        <Card className="gap-3">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                                    <TrendingUp className="text-muted-foreground size-4" />
                                    روند فروش و سود (۳۰ روز اخیر)
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="h-72" dir="ltr">
                                {fin.trend.length === 0 ? (
                                    <EmptyState text="هنوز داده‌ای برای نمایش روند ثبت نشده است" />
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={fin.trend} margin={{ top: 5, right: 10, left: 10, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="fillSales" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor="var(--chart-1)" stopOpacity={0.25} />
                                                    <stop offset="95%" stopColor="var(--chart-1)" stopOpacity={0} />
                                                </linearGradient>
                                                <linearGradient id="fillProfit" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor="var(--chart-2)" stopOpacity={0.25} />
                                                    <stop offset="95%" stopColor="var(--chart-2)" stopOpacity={0} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                                            <XAxis dataKey="label" fontSize={11} tickLine={false} axisLine={false} stroke="var(--muted-foreground)" />
                                            <YAxis
                                                fontSize={11}
                                                tickLine={false}
                                                axisLine={false}
                                                stroke="var(--muted-foreground)"
                                                tickFormatter={(v: number) => fmtCompact(v)}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'var(--popover)',
                                                    border: '1px solid var(--border)',
                                                    borderRadius: 'var(--radius)',
                                                    color: 'var(--popover-foreground)',
                                                    direction: 'rtl',
                                                    fontFamily: 'inherit',
                                                }}
                                                formatter={(v, name) => [
                                                    `${fmt(Number(v ?? 0))} تومان`,
                                                    name === 'net_sales' ? 'فروش خالص' : 'سود عملیاتی',
                                                ]}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="net_sales"
                                                stroke="var(--chart-1)"
                                                strokeWidth={2}
                                                fill="url(#fillSales)"
                                                dot={false}
                                                name="net_sales"
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="operational_profit"
                                                stroke="var(--chart-2)"
                                                strokeWidth={2}
                                                fill="url(#fillProfit)"
                                                dot={false}
                                                name="operational_profit"
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                )}
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <SectionCard
                                title="سفارش‌های اخیر"
                                icon={ShoppingCart}
                                action={
                                    <Link href="/orders" className="text-primary text-xs hover:underline">
                                        همه سفارش‌ها
                                    </Link>
                                }
                            >
                                {fin.recent_orders.length === 0 ? (
                                    <EmptyState text="هنوز سفارشی ثبت نشده است" />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="text-muted-foreground border-b text-right text-xs">
                                                    <th className="py-2 font-medium">سفارش</th>
                                                    <th className="font-medium">کانال</th>
                                                    <th className="font-medium">وضعیت</th>
                                                    <th className="font-medium">مبلغ</th>
                                                    <th className="font-medium">تاریخ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {fin.recent_orders.map((order) => (
                                                    <tr key={order.id} className="hover:bg-muted/30 border-b last:border-0">
                                                        <td className="py-2">
                                                            <Link href={`/orders/${order.id}`} className="text-primary font-medium hover:underline">
                                                                #{fmt(order.hub_order_id)}
                                                            </Link>
                                                        </td>
                                                        <td className="text-muted-foreground">{order.channel ?? '—'}</td>
                                                        <td>
                                                            <Badge
                                                                variant={order.status === 'completed' ? 'secondary' : 'outline'}
                                                                className="text-[11px]"
                                                            >
                                                                {orderStatusLabels[order.status] ?? order.status}
                                                            </Badge>
                                                        </td>
                                                        <td className="whitespace-nowrap">{fmt(order.total)}</td>
                                                        <td className="text-muted-foreground text-xs whitespace-nowrap">{order.date_label}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </SectionCard>

                            <SectionCard title="پرفروش‌ترین محصولات دوره" icon={Trophy}>
                                {fin.top_products.length === 0 ? (
                                    <EmptyState text="در این دوره فروشی ثبت نشده است" />
                                ) : (
                                    <div className="space-y-3">
                                        {fin.top_products.map((product, i) => {
                                            const max = fin.top_products[0]?.revenue || 1;
                                            return (
                                                <div key={i} className="space-y-1">
                                                    <div className="flex items-center justify-between gap-2 text-sm">
                                                        <span className="truncate">
                                                            {product.product_mirror_id ? (
                                                                <Link
                                                                    href={`/products/${product.product_mirror_id}`}
                                                                    className="hover:text-primary hover:underline"
                                                                >
                                                                    {product.name}
                                                                </Link>
                                                            ) : (
                                                                product.name
                                                            )}
                                                        </span>
                                                        <span className="text-muted-foreground shrink-0 text-xs">
                                                            {fmt(product.qty)} عدد · {fmt(product.revenue)} تومان
                                                        </span>
                                                    </div>
                                                    <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                                        <div
                                                            className="bg-chart-1 h-full rounded-full"
                                                            style={{ width: `${Math.max(4, (product.revenue / max) * 100)}%` }}
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </SectionCard>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <SectionCard title="عملکرد کانال‌های فروش" icon={TrendingUp}>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground border-b text-right text-xs">
                                                <th className="py-2 font-medium">کانال</th>
                                                <th className="font-medium">سفارش</th>
                                                <th className="font-medium">فروش خالص</th>
                                                <th className="font-medium">هزینه دوره</th>
                                                <th className="font-medium">سودآوری نهایی</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {channels.length === 0 && (
                                                <tr>
                                                    <td colSpan={5}>
                                                        <EmptyState text="سفارشی در این دوره نیست" />
                                                    </td>
                                                </tr>
                                            )}
                                            {channels.map(([slug, ch]) => (
                                                <tr key={slug} className="hover:bg-muted/30 border-b last:border-0">
                                                    <td className="py-2 font-medium">{ch.name}</td>
                                                    <td>{fmt(ch.orders)}</td>
                                                    <td className="whitespace-nowrap">{fmt(ch.net_sales)}</td>
                                                    <td className="whitespace-nowrap">{fmt(ch.period_cost)}</td>
                                                    <td
                                                        className={`font-medium whitespace-nowrap ${ch.final_profitability >= 0 ? 'text-success' : 'text-destructive'}`}
                                                    >
                                                        {fmt(ch.final_profitability)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </SectionCard>

                            <SectionCard title="مانده حساب‌ها" icon={Scale}>
                                {Object.keys(fin.balances).length === 0 ? (
                                    <EmptyState text="هنوز مانده‌ای ثبت نشده است" />
                                ) : (
                                    <div className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                        {Object.entries(fin.balances).map(([key, value]) => (
                                            <div
                                                key={key}
                                                className="bg-muted/20 flex items-center justify-between gap-2 rounded-lg border px-3 py-2"
                                            >
                                                <span className="text-muted-foreground">{balanceLabels[key] ?? key}</span>
                                                <span className={`font-medium whitespace-nowrap ${value < 0 ? 'text-destructive' : ''}`}>
                                                    {fmt(value)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </SectionCard>
                        </div>
                    </>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <SectionCard
                        title="صف بازبینی"
                        icon={PackageSearch}
                        action={
                            <Link href="/review" className="text-primary text-xs hover:underline">
                                مرکز بازبینی
                            </Link>
                        }
                    >
                        {reviewEntries.length === 0 && ops.blocked_orders === 0 && ops.unknown_source_orders === 0 ? (
                            <EmptyState text="موردی برای بازبینی نیست" />
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {reviewEntries.map(([type, count]) => (
                                    <Badge key={type} variant="secondary" className="text-xs">
                                        {reviewLabels[type] ?? type}: {fmt(count)}
                                    </Badge>
                                ))}
                                {ops.blocked_orders > 0 && <Badge variant="destructive">سفارش مسدود: {fmt(ops.blocked_orders)}</Badge>}
                                {ops.unknown_source_orders > 0 && <Badge variant="outline">منبع ناشناخته: {fmt(ops.unknown_source_orders)}</Badge>}
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard title="هشدار موجودی کم" icon={AlertTriangle}>
                        {ops.low_stock.length === 0 ? (
                            <EmptyState text="موجودی همه محصولات بالاتر از آستانه است" />
                        ) : (
                            <div className="space-y-1.5 text-sm">
                                {ops.low_stock.map((p) => (
                                    <div key={p.id} className="flex items-center justify-between gap-2 border-b py-1 last:border-0">
                                        <Link href={`/products/${p.id}`} className="hover:text-primary min-w-0 truncate hover:underline">
                                            {p.name}
                                        </Link>
                                        <Badge variant={p.stock_quantity <= 0 ? 'destructive' : 'secondary'} className="shrink-0 text-[11px]">
                                            {p.stock_quantity <= 0 ? 'ناموجود' : `${fmt(p.stock_quantity)} عدد`}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard title="سلامت همگام‌سازی" icon={RefreshCw}>
                        <div className="space-y-3 text-sm">
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(ops.sync.webhooks).map(([status, count]) => (
                                    <Badge
                                        key={status}
                                        variant={status === 'dead' || status === 'failed' ? 'destructive' : 'secondary'}
                                        className="text-xs"
                                    >
                                        وب‌هوک {status}: {fmt(count)}
                                    </Badge>
                                ))}
                                {Object.keys(ops.sync.webhooks).length === 0 && (
                                    <span className="text-muted-foreground">هنوز وب‌هوکی دریافت نشده است</span>
                                )}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                آخرین دریافت دوره‌ای سفارش‌ها:{' '}
                                {ops.sync.last_order_poll ? new Date(ops.sync.last_order_poll).toLocaleString('fa-IR') : 'هنوز اجرا نشده'}
                            </div>
                        </div>
                    </SectionCard>
                </div>
            </div>
        </AppLayout>
    );
}

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'سفارش‌ها', href: '/orders' }];

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');
const fmtDateTime = (iso: string) => new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' });

const profitStatusLabels: Record<string, string> = {
    ok: 'سود ثبت‌شده',
    blocked_missing_cost: 'مسدود — بدون بها',
    unknown_source: 'منبع ناشناخته',
    needs_review: 'نیازمند بازبینی',
    pending: 'در انتظار',
};

const paymentStatusLabels: Record<string, string> = {
    paid: 'پرداخت‌شده',
    unpaid: 'پرداخت‌نشده',
};

const profitStatusVariant = (s: string): 'default' | 'secondary' | 'destructive' | 'outline' =>
    s === 'ok' ? 'default' : s === 'blocked_missing_cost' || s === 'unknown_source' ? 'destructive' : 'secondary';

type Row = {
    id: number;
    hub_order_id: number;
    customer_name: string | null;
    status: string;
    financial_state: string;
    profit_status: string;
    payment_status: string;
    jalali_period: string;
    channel: string | null;
    total: number;
    operational_profit: number | null;
    order_date: string;
    updated_at: string;
};

type Paginated = { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
type Channel = { id: number; name: string };
type Filters = {
    period?: string;
    profit_status?: string;
    status?: string;
    payment_status?: string;
    channel_id?: string;
    search?: string;
};

export default function OrdersIndex({ orders, filters, channels }: { orders: Paginated; filters: Filters; channels: Channel[] }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilter = (next: Partial<Filters>) => {
        setError(null);
        router.get(
            '/orders',
            { ...filters, ...next },
            {
                preserveState: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
                onError: () => setError('بارگذاری سفارش‌ها با خطا مواجه شد. دوباره تلاش کنید.'),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="سفارش‌ها" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-bold">سفارش‌ها</h1>
                    {loading && (
                        <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" />
                            در حال بارگذاری…
                        </span>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="h-9 w-56 pr-8"
                            placeholder="جستجوی شماره سفارش یا نام مشتری"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilter({ search: search || undefined })}
                        />
                    </div>
                    <Input
                        className="h-9 w-36"
                        placeholder="دوره (1405-04)"
                        defaultValue={filters.period ?? ''}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilter({ period: e.currentTarget.value || undefined })}
                    />
                    <select
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={filters.channel_id ?? ''}
                        onChange={(e) => applyFilter({ channel_id: e.target.value || undefined })}
                    >
                        <option value="">همه کانال‌ها</option>
                        {channels.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={filters.payment_status ?? ''}
                        onChange={(e) => applyFilter({ payment_status: e.target.value || undefined })}
                    >
                        <option value="">وضعیت پرداخت</option>
                        <option value="paid">پرداخت‌شده</option>
                        <option value="unpaid">پرداخت‌نشده</option>
                    </select>
                    <select
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={filters.profit_status ?? ''}
                        onChange={(e) => applyFilter({ profit_status: e.target.value || undefined })}
                    >
                        <option value="">همه وضعیت‌های سود</option>
                        <option value="ok">سود ثبت‌شده</option>
                        <option value="blocked_missing_cost">مسدود — بدون بها</option>
                        <option value="unknown_source">منبع ناشناخته</option>
                        <option value="needs_review">نیازمند بازبینی</option>
                        <option value="pending">در انتظار</option>
                    </select>
                </div>

                {error && (
                    <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-2.5 text-sm text-destructive">
                        <AlertTriangle className="size-4 shrink-0" />
                        {error}
                    </div>
                )}

                <Card>
                    <CardContent className={`overflow-x-auto p-0 transition-opacity ${loading ? 'opacity-50' : ''}`}>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-right text-muted-foreground">
                                    <th className="p-3 font-normal">سفارش</th>
                                    <th className="font-normal">مشتری</th>
                                    <th className="font-normal">کانال</th>
                                    <th className="font-normal">وضعیت سفارش</th>
                                    <th className="font-normal">وضعیت پرداخت</th>
                                    <th className="font-normal">مبلغ (تومان)</th>
                                    <th className="font-normal">سود</th>
                                    <th className="font-normal">وضعیت سود</th>
                                    <th className="font-normal">تاریخ ثبت</th>
                                    <th className="font-normal">آخرین همگام‌سازی</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id} className="border-b last:border-0 hover:bg-muted/40">
                                        <td className="p-3">
                                            <Link href={`/orders/${o.id}`} className="font-medium text-primary hover:underline">
                                                #{o.hub_order_id}
                                            </Link>
                                        </td>
                                        <td className="max-w-40 truncate">{o.customer_name ?? '—'}</td>
                                        <td>{o.channel ?? '—'}</td>
                                        <td>
                                            <Badge variant="outline">{o.status}</Badge>
                                        </td>
                                        <td>
                                            <Badge variant={o.payment_status === 'paid' ? 'default' : 'secondary'}>
                                                {paymentStatusLabels[o.payment_status] ?? o.payment_status}
                                            </Badge>
                                        </td>
                                        <td dir="ltr" className="whitespace-nowrap">
                                            {fmt(o.total)}
                                        </td>
                                        <td dir="ltr" className={`whitespace-nowrap ${o.operational_profit !== null && o.operational_profit < 0 ? 'text-destructive' : ''}`}>
                                            {o.operational_profit !== null ? fmt(o.operational_profit) : '—'}
                                        </td>
                                        <td>
                                            <Badge variant={profitStatusVariant(o.profit_status)}>
                                                {profitStatusLabels[o.profit_status] ?? o.profit_status}
                                            </Badge>
                                        </td>
                                        <td className="whitespace-nowrap text-xs text-muted-foreground">{fmtDateTime(o.order_date)}</td>
                                        <td className="whitespace-nowrap text-xs text-muted-foreground">{fmtDateTime(o.updated_at)}</td>
                                    </tr>
                                ))}
                                {orders.data.length === 0 && !loading && (
                                    <tr>
                                        <td colSpan={10} className="p-8 text-center text-muted-foreground">
                                            سفارشی با این فیلترها یافت نشد
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-1" dir="ltr">
                    {orders.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                preserveState
                                className={`rounded border px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : null,
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

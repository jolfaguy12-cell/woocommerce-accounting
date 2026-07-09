import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { JalaliDateRangePicker } from '@/components/jalali-date-range-picker';
import AppLayout from '@/layouts/app-layout';
import { orderStatusLabels, orderStatusVariant, paymentStatusLabels, profitStatusLabels, profitStatusVariant } from '@/lib/order-status';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'سفارش‌ها', href: '/orders' }];

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');
const fmtDateTime = (iso: string) => new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' });

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
type StatusCount = { status: string; count: number };
type Filters = {
    profit_status?: string;
    status?: string;
    payment_status?: string;
    channel_id?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
};

const COLUMN_DEFS = [
    { key: 'order', label: 'سفارش' },
    { key: 'customer', label: 'مشتری' },
    { key: 'channel', label: 'کانال' },
    { key: 'status', label: 'وضعیت سفارش' },
    { key: 'payment_status', label: 'وضعیت پرداخت' },
    { key: 'total', label: 'مبلغ (تومان)' },
    { key: 'profit', label: 'سود' },
    { key: 'profit_status', label: 'وضعیت سود' },
    { key: 'order_date', label: 'تاریخ ثبت' },
    { key: 'updated_at', label: 'آخرین همگام‌سازی' },
] as const;

type ColumnKey = (typeof COLUMN_DEFS)[number]['key'];

const COLUMN_STORAGE_KEY = 'orders.visibleColumns';

function loadVisibleColumns(): Record<ColumnKey, boolean> {
    const all = Object.fromEntries(COLUMN_DEFS.map((c) => [c.key, true])) as Record<ColumnKey, boolean>;
    try {
        const stored = localStorage.getItem(COLUMN_STORAGE_KEY);
        return stored ? { ...all, ...JSON.parse(stored) } : all;
    } catch {
        return all;
    }
}

export default function OrdersIndex({
    orders,
    filters,
    channels,
    statuses,
    unmappedCount,
}: {
    orders: Paginated;
    filters: Filters;
    channels: Channel[];
    statuses: StatusCount[];
    unmappedCount: number;
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [columns, setColumns] = useState<Record<ColumnKey, boolean>>(loadVisibleColumns);

    const toggleColumn = (key: ColumnKey) => {
        setColumns((prev) => {
            const next = { ...prev, [key]: !prev[key] };
            localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(next));
            return next;
        });
    };
    const isVisible = (key: ColumnKey) => columns[key] !== false;
    const visibleCount = COLUMN_DEFS.filter((c) => isVisible(c.key)).length;

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

    const hasDateFilter = Boolean(filters.date_from || filters.date_to);

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

                    <div className="flex items-center gap-1">
                        <JalaliDateRangePicker
                            value={{ from: filters.date_from, to: filters.date_to }}
                            onChange={({ from, to }) => applyFilter({ date_from: from, date_to: to })}
                        />
                        {hasDateFilter && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-9"
                                title="حذف فیلتر تاریخ"
                                onClick={() => applyFilter({ date_from: undefined, date_to: undefined })}
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>

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
                        {unmappedCount > 0 && <option value="unmapped">نامشخص (بدون کانال)</option>}
                    </select>

                    <select
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilter({ status: e.target.value || undefined })}
                    >
                        <option value="">همه وضعیت‌های سفارش</option>
                        {statuses.map((s) => (
                            <option key={s.status} value={s.status}>
                                {orderStatusLabels[s.status] ?? s.status} ({fmt(s.count)})
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

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="h-9">
                                <SlidersHorizontal className="size-4" />
                                ستون‌ها ({visibleCount})
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>نمایش ستون‌ها</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {COLUMN_DEFS.map((c) => (
                                <DropdownMenuCheckboxItem key={c.key} checked={isVisible(c.key)} onCheckedChange={() => toggleColumn(c.key)}>
                                    {c.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
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
                                    {COLUMN_DEFS.filter((c) => isVisible(c.key)).map((c, i) => (
                                        <th key={c.key} className={i === 0 ? 'p-3 font-normal' : 'font-normal'}>
                                            {c.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id} className="border-b last:border-0 hover:bg-muted/40">
                                        {isVisible('order') && (
                                            <td className="p-3">
                                                <Link href={`/orders/${o.id}`} className="font-medium text-primary hover:underline">
                                                    #{o.hub_order_id}
                                                </Link>
                                            </td>
                                        )}
                                        {isVisible('customer') && <td className="max-w-40 truncate">{o.customer_name ?? '—'}</td>}
                                        {isVisible('channel') && <td>{o.channel ?? 'نامشخص'}</td>}
                                        {isVisible('status') && (
                                            <td>
                                                <Badge variant={orderStatusVariant(o.status)}>{orderStatusLabels[o.status] ?? o.status}</Badge>
                                            </td>
                                        )}
                                        {isVisible('payment_status') && (
                                            <td>
                                                <Badge variant={o.payment_status === 'paid' ? 'default' : 'secondary'}>
                                                    {paymentStatusLabels[o.payment_status] ?? o.payment_status}
                                                </Badge>
                                            </td>
                                        )}
                                        {isVisible('total') && (
                                            <td dir="ltr" className="whitespace-nowrap">
                                                {fmt(o.total)}
                                            </td>
                                        )}
                                        {isVisible('profit') && (
                                            <td
                                                dir="ltr"
                                                className={`whitespace-nowrap ${o.operational_profit !== null && o.operational_profit < 0 ? 'text-destructive' : ''}`}
                                            >
                                                {o.operational_profit !== null ? fmt(o.operational_profit) : '—'}
                                            </td>
                                        )}
                                        {isVisible('profit_status') && (
                                            <td>
                                                <Badge variant={profitStatusVariant(o.profit_status)}>
                                                    {profitStatusLabels[o.profit_status] ?? o.profit_status}
                                                </Badge>
                                            </td>
                                        )}
                                        {isVisible('order_date') && (
                                            <td className="whitespace-nowrap text-xs text-muted-foreground">{fmtDateTime(o.order_date)}</td>
                                        )}
                                        {isVisible('updated_at') && (
                                            <td className="whitespace-nowrap text-xs text-muted-foreground">{fmtDateTime(o.updated_at)}</td>
                                        )}
                                    </tr>
                                ))}
                                {orders.data.length === 0 && !loading && (
                                    <tr>
                                        <td colSpan={visibleCount} className="p-8 text-center text-muted-foreground">
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

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'سفارش‌ها', href: '/orders' }];

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');

const statusVariant = (s: string): 'default' | 'secondary' | 'destructive' | 'outline' =>
    s === 'ok' ? 'default' : s === 'blocked_missing_cost' || s === 'unknown_source' ? 'destructive' : 'secondary';

type Row = {
    id: number;
    hub_order_id: number;
    status: string;
    financial_state: string;
    profit_status: string;
    jalali_period: string;
    channel: string | null;
    total: number;
    operational_profit: number | null;
    order_date: string;
};

type Paginated = { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };

export default function OrdersIndex({ orders, filters }: { orders: Paginated; filters: { period?: string; profit_status?: string } }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="سفارش‌ها" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold">سفارش‌ها</h1>
                    <Input
                        className="h-9 w-36"
                        placeholder="دوره (1405-04)"
                        defaultValue={filters.period ?? ''}
                        onKeyDown={(e) =>
                            e.key === 'Enter' && router.get('/orders', { ...filters, period: e.currentTarget.value }, { preserveState: true })
                        }
                    />
                    <select
                        className="bg-background h-9 rounded-md border px-2 text-sm"
                        defaultValue={filters.profit_status ?? ''}
                        onChange={(e) => router.get('/orders', { ...filters, profit_status: e.target.value || undefined }, { preserveState: true })}
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option value="ok">سود ثبت‌شده</option>
                        <option value="blocked_missing_cost">مسدود — بدون بها</option>
                        <option value="unknown_source">منبع ناشناخته</option>
                        <option value="needs_review">نیازمند بازبینی</option>
                        <option value="pending">در انتظار</option>
                    </select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-right">
                                    <th className="p-3 font-normal">سفارش</th>
                                    <th className="font-normal">تاریخ</th>
                                    <th className="font-normal">کانال</th>
                                    <th className="font-normal">وضعیت</th>
                                    <th className="font-normal">مبلغ</th>
                                    <th className="font-normal">سود عملیاتی</th>
                                    <th className="font-normal">سود</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id} className="hover:bg-muted/40 border-b last:border-0">
                                        <td className="p-3">
                                            <Link href={`/orders/${o.id}`} className="text-primary font-medium hover:underline">
                                                #{o.hub_order_id}
                                            </Link>
                                        </td>
                                        <td>{new Date(o.order_date).toLocaleDateString('fa-IR')}</td>
                                        <td>{o.channel ?? '—'}</td>
                                        <td>
                                            <Badge variant="outline">{o.status}</Badge>
                                        </td>
                                        <td dir="ltr">{fmt(o.total)}</td>
                                        <td dir="ltr" className={o.operational_profit !== null && o.operational_profit < 0 ? 'text-red-600' : ''}>
                                            {o.operational_profit !== null ? fmt(o.operational_profit) : '—'}
                                        </td>
                                        <td>
                                            <Badge variant={statusVariant(o.profit_status)}>{o.profit_status}</Badge>
                                        </td>
                                    </tr>
                                ))}
                                {orders.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                            سفارشی یافت نشد
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

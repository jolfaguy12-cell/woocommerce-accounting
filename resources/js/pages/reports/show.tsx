import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');

type ReportData = {
    jalali_period: string;
    state: string;
    is_snapshot: boolean;
    finalized_at: string | null;
    readiness: { ready: boolean; issues: Record<string, number> } | null;
    data: {
        orders: Record<string, number>;
        expenses: { total_affecting_partner: number; by_category: Record<string, number> };
        payroll: number;
        channel_costs: Record<string, number>;
        channels: Record<string, { name: string; orders: number; net_sales: number; operational_profit: number; period_cost: number; final_profitability: number }>;
        net_period_profit: number;
        balances: Record<string, number>;
        built_at: string;
    };
    adjustments: { id: number; description: string; journal_entry: { uuid: string; jalali_period: string } | null }[];
};

const orderLabels: Record<string, string> = {
    count: 'تعداد سفارش', gross_sales: 'فروش ناخالص', discounts: 'تخفیف‌ها', net_sales: 'فروش خالص',
    product_cost: 'بهای تمام‌شده', shipping_charged: 'حمل دریافتی', shipping_real: 'حمل واقعی',
    channel_fees: 'کارمزد کانال‌ها', gross_profit: 'سود ناخالص', operational_profit: 'سود عملیاتی',
    average_order_value: 'میانگین سفارش', provisional_count: 'سود موقت (بازبینی)',
};

export default function ReportShow({ report, can_finalize }: { report: ReportData; can_finalize: boolean }) {
    const [acknowledge, setAcknowledge] = useState(false);
    const errors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const d = report.data;
    const notFinal = !report.is_snapshot;

    return (
        <AppLayout breadcrumbs={[{ title: 'گزارش‌ها', href: '/reports' }, { title: report.jalali_period, href: '#' }]}>
            <Head title={`گزارش ${report.jalali_period}`} />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold">گزارش دوره {report.jalali_period}</h1>
                    <Badge variant={report.is_snapshot ? 'default' : 'outline'}>
                        {report.is_snapshot ? `نهایی${report.state === 'adjusted' ? ' + تعدیل' : ''} (snapshot)` : 'پیش‌نویس زنده'}
                    </Badge>
                    {report.finalized_at && <span className="text-sm text-muted-foreground">نهایی‌شده: {new Date(report.finalized_at).toLocaleString('fa-IR')}</span>}
                </div>

                {notFinal && report.readiness && !report.readiness.ready && (
                    <Card className="border-amber-400">
                        <CardContent className="flex flex-wrap items-center gap-2 py-3 text-sm">
                            <span className="font-medium">چک‌لیست آمادگی:</span>
                            {Object.entries(report.readiness.issues).map(([k, v]) => (
                                <Badge key={k} variant="destructive">{k}: {fmt(v)}</Badge>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader><CardTitle className="text-base">عملکرد سفارش‌ها</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-2 gap-x-8 gap-y-1 text-sm md:grid-cols-3">
                            {Object.entries(d.orders).map(([key, value]) => (
                                <div key={key} className="flex justify-between border-b py-1">
                                    <span className="text-muted-foreground">{orderLabels[key] ?? key}</span>
                                    <span dir="ltr">{fmt(value)}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">جمع‌بندی دوره</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            <div className="flex justify-between"><span className="text-muted-foreground">سود عملیاتی سفارش‌ها</span><span dir="ltr">{fmt(d.orders.operational_profit)}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">هزینه‌های مؤثر بر شرکا</span><span dir="ltr" className="text-red-600">-{fmt(d.expenses.total_affecting_partner)}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">حقوق دوره</span><span dir="ltr" className="text-red-600">-{fmt(d.payroll)}</span></div>
                            {Object.entries(d.channel_costs).map(([slug, cost]) => (
                                <div key={slug} className="flex justify-between"><span className="text-muted-foreground">هزینه کانال {slug}</span><span dir="ltr" className="text-red-600">-{fmt(cost)}</span></div>
                            ))}
                            <div className="flex justify-between border-t pt-2 text-base font-bold">
                                <span>سود خالص دوره</span>
                                <span dir="ltr" className={d.net_period_profit < 0 ? 'text-red-600' : 'text-emerald-600'}>{fmt(d.net_period_profit)}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle className="text-base">کانال‌ها</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            {Object.entries(d.channels).length === 0 && <p className="text-muted-foreground">داده‌ای نیست</p>}
                            {Object.entries(d.channels).map(([slug, ch]) => (
                                <div key={slug} className="flex justify-between border-b py-1 last:border-0">
                                    <span>{ch.name} ({fmt(ch.orders)})</span>
                                    <span dir="ltr" className={ch.final_profitability < 0 ? 'text-red-600' : ''}>{fmt(ch.final_profitability)}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">هزینه‌ها به تفکیک دسته</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            {Object.entries(d.expenses.by_category).length === 0 && <p className="text-muted-foreground">هزینه‌ای ثبت نشده</p>}
                            {Object.entries(d.expenses.by_category).map(([name, total]) => (
                                <div key={name} className="flex justify-between border-b py-1 last:border-0">
                                    <span className="text-muted-foreground">{name}</span>
                                    <span dir="ltr">{fmt(total)}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                {report.adjustments.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">تعدیلات پس از نهایی‌سازی</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            {report.adjustments.map((a) => (
                                <div key={a.id} className="flex justify-between border-b py-1 last:border-0">
                                    <span>{a.description}</span>
                                    <span className="text-muted-foreground" dir="ltr">{a.journal_entry?.jalali_period} · {a.journal_entry?.uuid.slice(0, 8)}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {notFinal && can_finalize && (
                    <Card>
                        <CardContent className="flex flex-wrap items-center gap-3 py-3">
                            {errors.finalize && <p className="w-full text-sm text-red-600">{errors.finalize}</p>}
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={acknowledge} onChange={(e) => setAcknowledge(e.target.checked)} />
                                موارد باز را می‌پذیرم و آگاهانه نهایی می‌کنم
                            </label>
                            <Button
                                variant="destructive"
                                onClick={() => confirm('گزارش snapshot و دوره قفل می‌شود. ادامه؟') && router.post(`/reports/${report.jalali_period}/finalize`, { acknowledge }, { preserveScroll: true })}
                            >
                                نهایی‌سازی و قفل دوره
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

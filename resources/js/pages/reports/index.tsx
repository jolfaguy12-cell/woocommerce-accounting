import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

const fmt = (n: number | null) => (n === null ? '—' : n.toLocaleString('fa-IR'));

const stateLabels: Record<string, string> = {
    draft: 'پیش‌نویس',
    needs_review: 'نیازمند بازبینی',
    final: 'نهایی',
    adjusted: 'تعدیل‌شده',
};

type Row = { id: number; jalali_period: string; state: string; ready: boolean; net_period_profit: number | null; finalized_at: string | null };

// Planned purchasing reports (2026-07-10) — not implemented yet, just a punch
// list so the eventual Blade version has a ready-made starting point. Data
// source: purchase_invoices + purchase_invoice_lines (already indexed by
// jalali_period and carry qty/unit_price/landed_unit_cost per line), so all
// four are plain aggregate queries once someone builds the UI for them.
const plannedPurchaseReports: string[] = [
    'تعداد کل اقلام خریداری‌شده در ماه جاری (مجموع qty فاکتورهای خرید)',
    'جمع مبلغ خرید کالا در ماه جاری (qty × unit_price همه اقلام)',
    'مبلغ کل خرید در ماه جاری (جمع خرید کالا + هزینه‌های ارسال)',
    'جمع هزینه‌های ارسال کالاهای خریداری‌شده در ماه جاری',
    '(بعداً) پرفروش‌ترین کالاهای خریداری‌شده این ماه بر اساس مجموع مبلغ سفارش',
];

export default function ReportsIndex({ reports, current_period }: { reports: Row[]; current_period: string }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'گزارش‌ها', href: '/reports' }]}>
            <Head title="گزارش‌های دوره‌ای" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <h1 className="text-xl font-bold">گزارش‌های دوره‌ای شرکا</h1>
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-right">
                                    <th className="p-3 font-normal">دوره</th>
                                    <th className="font-normal">وضعیت</th>
                                    <th className="font-normal">آمادگی</th>
                                    <th className="font-normal">سود خالص دوره</th>
                                    <th className="font-normal">نهایی‌شده در</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reports.map((r) => (
                                    <tr key={r.id} className="hover:bg-muted/40 border-b last:border-0">
                                        <td className="p-3">
                                            <Link href={`/reports/${r.jalali_period}`} className="text-primary font-medium hover:underline" dir="ltr">
                                                {r.jalali_period}
                                            </Link>
                                            {r.jalali_period === current_period && (
                                                <Badge variant="outline" className="mr-2">
                                                    جاری
                                                </Badge>
                                            )}
                                        </td>
                                        <td>
                                            <Badge variant={r.state === 'final' ? 'default' : r.state === 'adjusted' ? 'secondary' : 'outline'}>
                                                {stateLabels[r.state] ?? r.state}
                                            </Badge>
                                        </td>
                                        <td>{r.state === 'final' || r.state === 'adjusted' ? '—' : r.ready ? '✅' : '⚠️ موارد باز'}</td>
                                        <td dir="ltr" className={r.net_period_profit !== null && r.net_period_profit < 0 ? 'text-red-600' : ''}>
                                            {fmt(r.net_period_profit)}
                                        </td>
                                        <td>{r.finalized_at ? new Date(r.finalized_at).toLocaleDateString('fa-IR') : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="flex flex-col gap-2 p-4">
                        <h2 className="text-sm font-bold">گزارش‌های خرید کالا (برنامه‌ریزی‌شده — TODO)</h2>
                        <ul className="text-muted-foreground list-inside list-disc space-y-1 text-sm">
                            {plannedPurchaseReports.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

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
                                <tr className="border-b text-right text-muted-foreground">
                                    <th className="p-3 font-normal">دوره</th>
                                    <th className="font-normal">وضعیت</th>
                                    <th className="font-normal">آمادگی</th>
                                    <th className="font-normal">سود خالص دوره</th>
                                    <th className="font-normal">نهایی‌شده در</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reports.map((r) => (
                                    <tr key={r.id} className="border-b last:border-0 hover:bg-muted/40">
                                        <td className="p-3">
                                            <Link href={`/reports/${r.jalali_period}`} className="font-medium text-primary hover:underline" dir="ltr">
                                                {r.jalali_period}
                                            </Link>
                                            {r.jalali_period === current_period && <Badge variant="outline" className="mr-2">جاری</Badge>}
                                        </td>
                                        <td><Badge variant={r.state === 'final' ? 'default' : r.state === 'adjusted' ? 'secondary' : 'outline'}>{stateLabels[r.state] ?? r.state}</Badge></td>
                                        <td>{r.state === 'final' || r.state === 'adjusted' ? '—' : r.ready ? '✅' : '⚠️ موارد باز'}</td>
                                        <td dir="ltr" className={r.net_period_profit !== null && r.net_period_profit < 0 ? 'text-red-600' : ''}>{fmt(r.net_period_profit)}</td>
                                        <td>{r.finalized_at ? new Date(r.finalized_at).toLocaleDateString('fa-IR') : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

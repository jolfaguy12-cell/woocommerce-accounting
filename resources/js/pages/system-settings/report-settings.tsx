import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تنظیمات گزارشات', href: '/setting/report-settings' }];

type Period = {
    id: number;
    jalali_period: string;
    status: string;
    locked_at: string | null;
};

const periodStatusLabels: Record<string, string> = {
    open: 'باز',
    soft_closed: 'بسته موقت',
    locked: 'قفل‌شده',
};

const reportStateLabels: Record<string, string> = {
    draft: 'پیش‌نویس',
    needs_review: 'نیازمند بازبینی',
    final: 'نهایی‌شده',
    adjusted: 'اصلاح‌شده',
};

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

export default function SettingReportSettings({ periods, reportCounts }: { periods: Period[]; reportCounts: Record<string, number> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تنظیمات گزارشات" />

            <div className="px-4 py-6 space-y-6">
                <Heading
                    title="تنظیمات گزارشات"
                    description="هنوز مدل مستقلی برای «تنظیمات گزارش» وجود ندارد؛ نزدیک‌ترین داده واقعی، وضعیت قفل دوره‌های حسابداری است."
                />

                <Card>
                    <CardContent className="pt-6">
                        <h3 className="mb-3 text-sm font-semibold">دوره‌های اخیر</h3>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-right text-muted-foreground">
                                        <th className="py-2 font-normal">دوره</th>
                                        <th className="py-2 font-normal">وضعیت</th>
                                        <th className="py-2 font-normal">زمان قفل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {periods.map((p) => (
                                        <tr key={p.id} className="border-b last:border-0">
                                            <td className="py-2">{p.jalali_period}</td>
                                            <td className="py-2">
                                                <Badge variant={p.status === 'locked' ? 'default' : 'secondary'}>
                                                    {periodStatusLabels[p.status] ?? p.status}
                                                </Badge>
                                            </td>
                                            <td className="py-2 text-muted-foreground">{fmtDate(p.locked_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        <h3 className="mb-3 text-sm font-semibold">تعداد گزارش‌ها بر اساس وضعیت</h3>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(reportCounts).map(([state, count]) => (
                                <Badge key={state} variant="outline">
                                    {reportStateLabels[state] ?? state}: {count.toLocaleString('fa-IR')}
                                </Badge>
                            ))}
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">
                            برای مدیریت کامل گزارش‌ها (نهایی‌سازی، تعدیل) به{' '}
                            <Link href="/reports" className="underline">
                                صفحه گزارشات
                            </Link>{' '}
                            مراجعه کنید.
                        </p>
                    </CardContent>
                </Card>

                <CapabilityList
                    available={[
                        'نمایش وضعیت قفل دوره‌های حسابداری (accounting_periods)',
                        'نمایش تعداد گزارش‌های شرکا بر اساس وضعیت (پیش‌نویس/نهایی/اصلاح‌شده)',
                    ]}
                    future={[
                        'تعریف قالب/آستانه‌های گزارش از رابط کاربری (مثلاً چه مواردی باید در گزارش شریک نمایش داده شود)',
                        'زمان‌بندی تولید خودکار گزارش‌های دوره‌ای و اعلان به مدیر',
                        'قفل/باز کردن دستی یک دوره از همین صفحه (در حال حاضر فقط از طریق نهایی‌سازی گزارش انجام می‌شود)',
                    ]}
                    missing={[
                        'هیچ مدل «ReportSetting» یا جدول تنظیمات گزارش وجود ندارد — نیاز به تصمیم درباره اینکه چه پارامترهایی باید قابل‌تنظیم باشند',
                        'قفل/باز کردن دستی دوره از UI نیازمند تصمیم حساس حسابداری است (طبق CLAUDE.md نیازمند تأیید کاربر)',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

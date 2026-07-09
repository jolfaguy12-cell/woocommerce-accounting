import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'لاگ سیستم', href: '/tools/system-logs' }];

type WebhookEventRow = {
    id: number;
    event_uuid: string;
    event_type: string;
    status: string;
    attempts: number;
    last_error: string | null;
    correlation_id: string | null;
    created_at: string;
};

type SyncRunRow = {
    id: number;
    type: string;
    status: string;
    stats: Record<string, unknown> | null;
    started_at: string | null;
    finished_at: string | null;
};

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

export default function ToolsSystemLogs({ webhookEvents, syncRuns }: { webhookEvents: WebhookEventRow[]; syncRuns: SyncRunRow[] }) {
    const { flash } = usePage<SharedData>().props;
    const [retrying, setRetrying] = useState(false);

    const retry = () => {
        setRetrying(true);
        router.post(
            '/tools/system-logs/retry',
            {},
            {
                preserveScroll: true,
                onFinish: () => setRetrying(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="لاگ سیستم" />

            <div className="px-4 py-6 space-y-6">
                <Heading title="لاگ سیستم" description="خطاهای همگام‌سازی (وبهوک‌ها) و تاریخچه اجراهای Sync؛ معادل acc:sync:errors" />

                {flash?.success && <div className="rounded-md border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm">{flash.success}</div>}

                <Card>
                    <CardContent className="pt-6">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-semibold">وبهوک‌های ناموفق/مرده ({webhookEvents.length})</h3>
                            {webhookEvents.length > 0 && (
                                <Button size="sm" variant="outline" onClick={retry} disabled={retrying}>
                                    {retrying ? 'در حال تلاش مجدد...' : 'تلاش مجدد همه'}
                                </Button>
                            )}
                        </div>
                        {webhookEvents.length === 0 ? (
                            <p className="text-sm text-muted-foreground">موردی یافت نشد.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-right text-muted-foreground">
                                            <th className="py-2 font-normal">نوع رویداد</th>
                                            <th className="py-2 font-normal">وضعیت</th>
                                            <th className="py-2 font-normal">تلاش‌ها</th>
                                            <th className="py-2 font-normal">خطا</th>
                                            <th className="py-2 font-normal">زمان</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {webhookEvents.map((e) => (
                                            <tr key={e.id} className="border-b last:border-0">
                                                <td className="py-2">{e.event_type}</td>
                                                <td className="py-2">
                                                    <Badge variant={e.status === 'dead' ? 'destructive' : 'secondary'}>{e.status}</Badge>
                                                </td>
                                                <td className="py-2">{e.attempts}</td>
                                                <td className="max-w-xs truncate py-2 text-muted-foreground" title={e.last_error ?? ''}>
                                                    {e.last_error ?? '—'}
                                                </td>
                                                <td className="py-2 text-muted-foreground">{fmtDate(e.created_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        <h3 className="mb-3 text-sm font-semibold">آخرین اجراهای Sync ({syncRuns.length})</h3>
                        {syncRuns.length === 0 ? (
                            <p className="text-sm text-muted-foreground">موردی یافت نشد.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-right text-muted-foreground">
                                            <th className="py-2 font-normal">نوع</th>
                                            <th className="py-2 font-normal">وضعیت</th>
                                            <th className="py-2 font-normal">شروع</th>
                                            <th className="py-2 font-normal">پایان</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {syncRuns.map((r) => (
                                            <tr key={r.id} className="border-b last:border-0">
                                                <td className="py-2">{r.type}</td>
                                                <td className="py-2">
                                                    <Badge variant={r.status === 'done' ? 'default' : r.status === 'failed' ? 'destructive' : 'secondary'}>
                                                        {r.status}
                                                    </Badge>
                                                </td>
                                                <td className="py-2 text-muted-foreground">{fmtDate(r.started_at)}</td>
                                                <td className="py-2 text-muted-foreground">{fmtDate(r.finished_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <CapabilityList
                    available={[
                        'مشاهده و تلاش مجدد وبهوک‌های ناموفق/مرده (معادل acc:sync:errors --retry)',
                        'تاریخچه اجراهای Poll/Backfill از جدول sync_runs',
                    ]}
                    future={[
                        'صفحه‌بندی و جست‌وجو در تاریخچه به‌جای محدودیت ثابت ۵۰/۲۰ ردیف',
                        'مشاهده جزئیات کامل payload هر رویداد وبهوک (در پنجره مجزا)',
                        'فیلتر بر اساس بازه زمانی و نوع رویداد',
                    ]}
                    missing={[
                        'نمایش لاگ عمومی اپلیکیشن (storage/logs/laravel.log) در رابط کاربری هنوز پیاده نشده — طبق سیاست پروژه، لاگ عمومی نباید داده حساس مالی نمایش دهد؛ نیازمند طراحی لاگ حسابرسی محافظت‌شده (audit log) به‌جای نمایش مستقیم فایل لاگ',
                        'جدول activity_log (spatie/laravel-activitylog) نصب شده اما هنوز به هیچ مدلی متصل نیست — نیازمند تصمیم درباره اینکه کدام تغییرات مالی باید حسابرسی شوند',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

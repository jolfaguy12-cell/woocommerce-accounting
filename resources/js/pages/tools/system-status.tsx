import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'وضعیت سیستم', href: '/tools/system-status' }];

type Status = {
    database: boolean;
    hub: boolean;
    hub_error: string | null;
    pending_jobs: number;
    failed_jobs: number;
    dead_webhook_events: number;
    open_review_items: number;
    last_order_poll: string | null;
    last_product_poll: string | null;
    last_backfill: string | null;
    ok: boolean;
};

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between border-b py-2.5 last:border-0">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span className="text-sm font-medium">{children}</span>
        </div>
    );
}

export default function ToolsSystemStatus({ status }: { status: Status }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="وضعیت سیستم" />

            <div className="px-4 py-6 space-y-6">
                <div className="flex items-center justify-between">
                    <Heading title="وضعیت سیستم" description="خروجی زنده همان بررسی که دستور acc:health انجام می‌دهد." />
                    <Badge variant={status.ok ? 'default' : 'destructive'}>{status.ok ? 'سالم' : 'نیازمند بررسی'}</Badge>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <Row label="اتصال دیتابیس">
                            <Badge variant={status.database ? 'default' : 'destructive'}>{status.database ? 'برقرار' : 'قطع'}</Badge>
                        </Row>
                        <Row label="اتصال به هاب (Hub)">
                            <Badge variant={status.hub ? 'default' : 'destructive'}>{status.hub ? 'برقرار' : 'قطع'}</Badge>
                        </Row>
                        {status.hub_error && <Row label="خطای هاب">{status.hub_error}</Row>}
                        <Row label="صف‌های در انتظار (jobs)">{status.pending_jobs.toLocaleString('fa-IR')}</Row>
                        <Row label="صف‌های ناموفق (failed jobs)">{status.failed_jobs.toLocaleString('fa-IR')}</Row>
                        <Row label="وبهوک‌های مرده (dead)">{status.dead_webhook_events.toLocaleString('fa-IR')}</Row>
                        <Row label="آیتم‌های باز در صف بازبینی">{status.open_review_items.toLocaleString('fa-IR')}</Row>
                        <Row label="آخرین Poll سفارشات">{fmtDate(status.last_order_poll)}</Row>
                        <Row label="آخرین Poll محصولات">{fmtDate(status.last_product_poll)}</Row>
                        <Row label="آخرین Backfill شبانه">{fmtDate(status.last_backfill)}</Row>
                    </CardContent>
                </Card>

                <CapabilityList
                    available={[
                        'این صفحه داده زنده از دیتابیس/هاب می‌خواند؛ معادل دقیق acc:health --json',
                        'برای جزئیات خطاهای همگام‌سازی به صفحه «لاگ سیستم» مراجعه کنید',
                    ]}
                    future={[
                        'رفرش خودکار دوره‌ای (polling) در همین صفحه بدون رفرش کامل',
                        'هشدار/اعلان (مثلاً ایمیل یا تلگرام) هنگام ناسالم شدن سیستم',
                        'نمودار روند مصرف صف و تعداد خطا در طول زمان',
                    ]}
                    missing={[]}
                />
            </div>
        </AppLayout>
    );
}

import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مدیریت وبهوک‌ها و API', href: '/setting/api-webhook-managment' }];

type Hub = {
    base_url: string;
    api_key_configured: boolean;
    webhook_secret_configured: boolean;
    webhook_max_attempts: number;
    webhook_endpoint: string;
};

const eventStatusLabels: Record<string, string> = {
    received: 'دریافت‌شده',
    processing: 'در حال پردازش',
    done: 'موفق',
    failed: 'ناموفق (در انتظار تلاش مجدد)',
    dead: 'مرده (نیازمند بررسی)',
};

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between border-b py-2.5 last:border-0">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span className="text-sm font-medium">{children}</span>
        </div>
    );
}

export default function SettingApiWebhookManagement({ hub, webhookEventCounts }: { hub: Hub; webhookEventCounts: Record<string, number> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مدیریت وبهوک‌ها و API" />

            <div className="px-4 py-6 space-y-6">
                <Heading
                    title="مدیریت وبهوک‌ها و API"
                    description="این سیستم فقط وبهوک ورودی از هاب را دریافت می‌کند؛ مدیریت اندپوینت وبهوک در سمت هاب انجام می‌شود."
                />

                <Card>
                    <CardContent className="pt-6">
                        <Row label="آدرس پایه هاب">
                            <span dir="ltr">{hub.base_url}</span>
                        </Row>
                        <Row label="کلید API هاب">
                            <Badge variant={hub.api_key_configured ? 'default' : 'destructive'}>
                                {hub.api_key_configured ? 'تنظیم‌شده' : 'تنظیم‌نشده'}
                            </Badge>
                        </Row>
                        <Row label="کلید امضای وبهوک">
                            <Badge variant={hub.webhook_secret_configured ? 'default' : 'destructive'}>
                                {hub.webhook_secret_configured ? 'تنظیم‌شده' : 'تنظیم‌نشده'}
                            </Badge>
                        </Row>
                        <Row label="حداکثر تلاش پردازش وبهوک">{hub.webhook_max_attempts}</Row>
                        <Row label="اندپوینت دریافت وبهوک (سمت این سیستم)">
                            <span dir="ltr">{hub.webhook_endpoint}</span>
                        </Row>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="pt-6">
                        <h3 className="mb-3 text-sm font-semibold">وضعیت رویدادهای وبهوک دریافتی</h3>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(webhookEventCounts).map(([status, count]) => (
                                <Badge key={status} variant="outline">
                                    {eventStatusLabels[status] ?? status}: {count.toLocaleString('fa-IR')}
                                </Badge>
                            ))}
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">
                            برای بررسی و تلاش مجدد رویدادهای ناموفق به{' '}
                            <Link href="/tools/system-logs" className="underline">
                                صفحه لاگ سیستم
                            </Link>{' '}
                            مراجعه کنید.
                        </p>
                    </CardContent>
                </Card>

                <CapabilityList
                    available={[
                        'نمایش وضعیت پیکربندی اتصال به هاب (base URL، کلید API، کلید امضای وبهوک)',
                        'شمارش رویدادهای وبهوک به تفکیک وضعیت',
                        'تلاش مجدد وبهوک‌های ناموفق از صفحه لاگ سیستم',
                    ]}
                    future={[
                        'مدیریت کلیدهای API از رابط کاربری (تولید/چرخش کلید) به‌جای ویرایش دستی .env',
                        'ثبت و مدیریت اندپوینت‌های وبهوک خروجی، در صورتی که این سیستم در آینده به سرویس دیگری وبهوک ارسال کند',
                        'نمایش تاریخچه کامل درخواست‌های API به هاب (نرخ موفقیت، تأخیر)',
                    ]}
                    missing={[
                        'این اپلیکیشن هیچ API عمومی یا وبهوک خروجی ندارد؛ صرفاً مصرف‌کننده API هاب و گیرنده وبهوک آن است — طبق CLAUDE.md، داده مالی حساس هرگز نباید از طریق API عمومی یا وبهوک منتشر شود',
                        'چرخش/تغییر کلید امضای وبهوک از UI نیازمند هماهنگی با ثبت اندپوینت در سمت هاب است (تصمیم معماری sync)',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

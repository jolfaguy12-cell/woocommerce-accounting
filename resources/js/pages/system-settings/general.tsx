import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تنظیمات کلی', href: '/setting' }];

type Config = {
    app_name: string;
    timezone: string;
    environment: string;
    currency_divisor: number;
    low_stock_threshold: number;
    queue_connection: string;
};

function Row({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="flex items-center justify-between border-b py-2.5 last:border-0">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span className="text-sm font-medium">{value}</span>
        </div>
    );
}

export default function SettingGeneral({ config }: { config: Config }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تنظیمات کلی" />

            <div className="px-4 py-6 space-y-6">
                <Heading title="تنظیمات کلی" description="مقادیر پیکربندی فعلی سیستم (فقط نمایش؛ ویرایش از این صفحه هنوز پیاده نشده)." />

                <Card>
                    <CardContent className="pt-6">
                        <Row label="نام برنامه" value={config.app_name} />
                        <Row label="منطقه زمانی" value={config.timezone} />
                        <Row label="محیط اجرا" value={config.environment} />
                        <Row label="ضریب تبدیل ارز (به تومان)" value={config.currency_divisor} />
                        <Row label="آستانه موجودی کم" value={config.low_stock_threshold} />
                        <Row label="اتصال صف (Queue)" value={config.queue_connection} />
                    </CardContent>
                </Card>

                <CapabilityList
                    available={['نمایش فقط‌خواندنی مقادیر پیکربندی فعلی از فایل‌های config']}
                    future={[
                        'فرم ویرایش نام نمایشی فروشگاه (طبق CLAUDE.md: نام نمایشی قابل‌تنظیم است، جدا از نام برنامه)',
                        'ویرایش آستانه موجودی کم محصولات از رابط کاربری',
                        'مدیریت نگاشت‌های پیکربندی‌محور (وضعیت‌ها، کانال‌ها، مراکز هزینه) به‌جای مقادیر ثابت در کد',
                    ]}
                    missing={[
                        'هیچ جدول/مدل Settings در دیتابیس وجود ندارد — تغییرات این صفحه فعلاً باید در فایل .env یا config انجام شود، نه از UI',
                        'برای ویرایش زنده تنظیمات، نیاز به تصمیم درباره محل ذخیره (دیتابیس یا فایل) و کنترل دسترسی است',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'بکاپ و بازیابی', href: '/tools/backup' }];

export default function ToolsBackup() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="بکاپ و بازیابی" />

            <div className="px-4 py-6">
                <Heading title="بکاپ و بازیابی" description="در حال حاضر هیچ ابزار بکاپ‌گیری در بک‌اند پیاده‌سازی نشده است." />

                <CapabilityList
                    future={[
                        'بکاپ دستی/زمان‌بندی‌شده از دیتابیس MySQL (مثلاً با spatie/laravel-backup) با رمزنگاری فایل خروجی',
                        'ذخیره‌سازی خارج از سرور (S3 یا مشابه) برای مقاومت در برابر خرابی دیسک',
                        'سیاست نگهداری (retention) و پاک‌سازی خودکار نسخه‌های قدیمی',
                        'بازیابی (restore) با تأیید صریح مدیر و قفل موقت سیستم حین اجرا',
                        'ثبت لاگ حسابرسی برای هر عملیات بکاپ/بازیابی (چه کسی، چه زمانی)',
                    ]}
                    missing={[
                        'انتخاب و نصب پکیج بکاپ (یا اسکریپت mysqldump سفارشی)',
                        'تصمیم درباره محل ذخیره خروجی و رمزنگاری آن — این داده شامل اطلاعات مالی حساس است',
                        'تعریف نقش/دسترسی مجاز برای اجرای بازیابی (ریسک بازنویسی داده‌های تأییدشده و دوره‌های قفل‌شده)',
                        'اندپوینت بک‌اند برای شروع بکاپ، دانلود فایل و اجرای بازیابی',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

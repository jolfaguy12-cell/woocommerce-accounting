import CapabilityList from '@/components/capability-list';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مدیریت نقش‌ها', href: '/setting/role-managment' }];

type RoleRow = { id: number; name: string; users_count: number };

const roleLabels: Record<string, string> = {
    admin: 'مدیر',
    accountant: 'حسابدار',
    warehouse: 'انباردار',
    partner_viewer: 'شریک (فقط گزارش)',
};

export default function SettingRoleManagement({ roles, totalUsers }: { roles: RoleRow[]; totalUsers: number }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مدیریت نقش‌ها" />

            <div className="px-4 py-6 space-y-6">
                <Heading title="مدیریت نقش‌ها" description="نقش‌های موجود در سیستم (Spatie Permission) و تعداد کاربران هر نقش." />

                <Card>
                    <CardContent className="pt-6">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-right text-muted-foreground">
                                        <th className="py-2 font-normal">نقش</th>
                                        <th className="py-2 font-normal">تعداد کاربران</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {roles.map((r) => (
                                        <tr key={r.id} className="border-b last:border-0">
                                            <td className="py-2">
                                                {roleLabels[r.name] ?? r.name} <span className="text-muted-foreground">({r.name})</span>
                                            </td>
                                            <td className="py-2">
                                                <Badge variant="outline">{r.users_count.toLocaleString('fa-IR')}</Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">
                            از مجموع {totalUsers.toLocaleString('fa-IR')} کاربر. برای تغییر نقش یک کاربر یا ساخت کاربر جدید به{' '}
                            <Link href="/users" className="underline">
                                صفحه کاربران
                            </Link>{' '}
                            مراجعه کنید.
                        </p>
                    </CardContent>
                </Card>

                <CapabilityList
                    available={[
                        'نمایش نقش‌های موجود و تعداد کاربران هر نقش (از spatie/laravel-permission، از پیش نصب‌شده)',
                        'اختصاص نقش به هر کاربر هم‌اکنون در صفحه کاربران (/users) پیاده‌سازی شده است',
                    ]}
                    future={[
                        'تعریف نقش جدید یا حذف نقش از رابط کاربری',
                        'مدیریت مجوزهای ریزدانه (permissions) به‌جای فقط نقش‌های ثابت فعلی — در حال حاضر مجوز مشخصی تعریف نشده و کنترل دسترسی فقط بر اساس نام نقش در route است',
                        'مشاهده اینکه هر نقش دقیقاً به کدام صفحات/اکشن‌ها دسترسی دارد',
                    ]}
                    missing={[
                        'هیچ رکورد Permission (مجوز ریزدانه) در سیستم تعریف نشده — کنترل دسترسی فعلی صرفاً role:admin|accountant|... در routes/web.php است، نه permission-based',
                        'حذف/ساخت نقش از UI نیازمند تصمیم درباره ریسک قفل‌شدن آخرین ادمین (مشابه محافظتی که برای حذف آخرین ادمین در UserController وجود دارد)',
                    ]}
                />
            </div>
        </AppLayout>
    );
}

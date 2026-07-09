import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

type Option = { id: number; name: string; is_cash?: boolean };
type Credit = { id: number; party_id: number; party: string; remaining: number; description: string | null };

type Props = {
    categories: Option[];
    cost_centers: Option[];
    banks: Option[];
    channels: Option[];
    customers: Option[];
    open_credits: Credit[];
    flash?: { success?: string };
};

const fmt = (n: number) => n.toLocaleString('fa-IR');

function Select({ value, onChange, options, placeholder }: { value: string; onChange: (v: string) => void; options: Option[]; placeholder: string }) {
    return (
        <select className="bg-background h-9 w-full rounded-md border px-2 text-sm" value={value} onChange={(e) => onChange(e.target.value)} required>
            <option value="">{placeholder}</option>
            {options.map((o) => (
                <option key={o.id} value={o.id}>
                    {o.name}
                </option>
            ))}
        </select>
    );
}

function ExpenseForm({ categories, cost_centers, banks }: Pick<Props, 'categories' | 'cost_centers' | 'banks'>) {
    const form = useForm({
        expense_category_id: '',
        cost_center_id: '',
        bank_account_id: '',
        amount: '',
        description: '',
        affects_partner_profit: true,
        is_capital: false,
    });

    return (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/fast-forms/expense', { preserveScroll: true, onSuccess: () => form.reset('amount', 'description') });
            }}
        >
            <Select
                value={form.data.expense_category_id}
                onChange={(v) => form.setData('expense_category_id', v)}
                options={categories}
                placeholder="دسته هزینه…"
            />
            <Select
                value={form.data.cost_center_id}
                onChange={(v) => form.setData('cost_center_id', v)}
                options={cost_centers}
                placeholder="مرکز هزینه (اختیاری)"
            />
            <Select value={form.data.bank_account_id} onChange={(v) => form.setData('bank_account_id', v)} options={banks} placeholder="پرداخت از…" />
            <Input
                dir="ltr"
                type="number"
                placeholder="مبلغ (تومان)"
                value={form.data.amount}
                onChange={(e) => form.setData('amount', e.target.value)}
                required
            />
            <Input placeholder="شرح" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} required />
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={form.data.affects_partner_profit}
                    onChange={(e) => form.setData('affects_partner_profit', e.target.checked)}
                />
                مؤثر بر سود شرکا
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.is_capital} onChange={(e) => form.setData('is_capital', e.target.checked)} />
                سرمایه‌ای (دارایی ثابت)
            </label>
            <Button className="w-full" disabled={form.processing}>
                ثبت هزینه
            </Button>
        </form>
    );
}

function TopupForm({ channels, banks }: Pick<Props, 'channels' | 'banks'>) {
    const form = useForm({ channel_id: '', bank_account_id: '', amount: '', note: '' });

    return (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/fast-forms/topup', { preserveScroll: true, onSuccess: () => form.reset('amount', 'note') });
            }}
        >
            <Select value={form.data.channel_id} onChange={(v) => form.setData('channel_id', v)} options={channels} placeholder="کانال…" />
            <Select value={form.data.bank_account_id} onChange={(v) => form.setData('bank_account_id', v)} options={banks} placeholder="پرداخت از…" />
            <Input
                dir="ltr"
                type="number"
                placeholder="مبلغ شارژ (تومان)"
                value={form.data.amount}
                onChange={(e) => form.setData('amount', e.target.value)}
                required
            />
            <Input placeholder="توضیح (اختیاری)" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            <Button className="w-full" disabled={form.processing || channels.length === 0}>
                ثبت شارژ / هزینه کانال
            </Button>
        </form>
    );
}

function PaymentForm({ customers, open_credits, banks }: Pick<Props, 'customers' | 'open_credits' | 'banks'>) {
    const form = useForm({ party_id: '', bank_account_id: '', amount: '', credit_order_id: '' });
    const credits = open_credits.filter((c) => c.party_id === Number(form.data.party_id));

    return (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/fast-forms/payment', { preserveScroll: true, onSuccess: () => form.reset('amount') });
            }}
        >
            <Select value={form.data.party_id} onChange={(v) => form.setData('party_id', v)} options={customers} placeholder="مشتری…" />
            {credits.length > 0 && (
                <select
                    className="bg-background h-9 w-full rounded-md border px-2 text-sm"
                    value={form.data.credit_order_id}
                    onChange={(e) => form.setData('credit_order_id', e.target.value)}
                >
                    <option value="">بدون اتصال به فروش اعتباری</option>
                    {credits.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.description ?? 'اعتباری'} — مانده {fmt(c.remaining)}
                        </option>
                    ))}
                </select>
            )}
            <Select value={form.data.bank_account_id} onChange={(v) => form.setData('bank_account_id', v)} options={banks} placeholder="واریز به…" />
            <Input
                dir="ltr"
                type="number"
                placeholder="مبلغ (تومان)"
                value={form.data.amount}
                onChange={(e) => form.setData('amount', e.target.value)}
                required
            />
            <Button className="w-full" disabled={form.processing || customers.length === 0}>
                ثبت دریافت
            </Button>
        </form>
    );
}

function BankForm() {
    const form = useForm({ name: '', bank_name: '', iban: '', is_cash: false });

    return (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/fast-forms/bank', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <Input
                placeholder="نام حساب (مثل: بانک ملت اصلی)"
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                required
            />
            <Input placeholder="نام بانک" value={form.data.bank_name} onChange={(e) => form.setData('bank_name', e.target.value)} />
            <Input dir="ltr" placeholder="شبا (اختیاری)" value={form.data.iban} onChange={(e) => form.setData('iban', e.target.value)} />
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.is_cash} onChange={(e) => form.setData('is_cash', e.target.checked)} />
                صندوق نقدی است
            </label>
            <Button className="w-full" variant="outline" disabled={form.processing}>
                ساخت حساب
            </Button>
        </form>
    );
}

export default function FastForms(props: Props) {
    const flash = (usePage().props as { flash?: { success?: string } }).flash;

    return (
        <AppLayout breadcrumbs={[{ title: 'فرم‌های سریع', href: '/fast-forms' }]}>
            <Head title="فرم‌های سریع" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <h1 className="text-xl font-bold">فرم‌های سریع</h1>
                {flash?.success && (
                    <div className="rounded-md border border-emerald-300 bg-emerald-50 p-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        {flash.success}
                    </div>
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">ثبت هزینه</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ExpenseForm {...props} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">شارژ / هزینه کانال</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TopupForm {...props} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">دریافت از مشتری</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <PaymentForm {...props} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">حساب بانکی / صندوق جدید</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BankForm />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Pencil, ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'کاربران', href: '/users' }];

type UserRow = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    created_at: string | null;
};

const roleLabels: Record<string, string> = {
    admin: 'مدیر',
    accountant: 'حسابدار',
    warehouse: 'انباردار',
    partner_viewer: 'شریک (فقط گزارش)',
};

type UserFormData = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role: string;
};

function UserForm({
    roles,
    initial,
    submitLabel,
    onSubmit,
    processing,
    errors,
    data,
    setData,
}: {
    roles: string[];
    initial?: UserRow;
    submitLabel: string;
    onSubmit: FormEventHandler;
    processing: boolean;
    errors: Partial<Record<keyof UserFormData, string>>;
    data: UserFormData;
    setData: (key: keyof UserFormData, value: string) => void;
}) {
    return (
        <form onSubmit={onSubmit} className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="name">نام و نام خانوادگی</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    dir="ltr"
                    className="text-left"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    required
                />
                <InputError message={errors.email} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="role">نقش</Label>
                <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                    <SelectTrigger id="role">
                        <SelectValue placeholder="انتخاب نقش" />
                    </SelectTrigger>
                    <SelectContent>
                        {roles.map((r) => (
                            <SelectItem key={r} value={r}>
                                {roleLabels[r] ?? r}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.role} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="password">{initial ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور'}</Label>
                <Input
                    id="password"
                    type="password"
                    dir="ltr"
                    className="text-left"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required={!initial}
                    autoComplete="new-password"
                />
                <InputError message={errors.password} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="password_confirmation">تکرار رمز عبور</Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    dir="ltr"
                    className="text-left"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    required={!initial || data.password !== ''}
                    autoComplete="new-password"
                />
            </div>
            <DialogFooter>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {submitLabel}
                </Button>
            </DialogFooter>
        </form>
    );
}

function CreateUserDialog({ roles }: { roles: string[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<UserFormData>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/users', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <UserPlus className="h-4 w-4" />
                    کاربر جدید
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>ساخت کاربر جدید</DialogTitle>
                    <DialogDescription>حساب جدید فقط از همین‌جا ساخته می‌شود؛ ثبت‌نام عمومی غیرفعال است.</DialogDescription>
                </DialogHeader>
                <UserForm
                    roles={roles}
                    submitLabel="ساخت کاربر"
                    onSubmit={submit}
                    processing={processing}
                    errors={errors}
                    data={data}
                    setData={setData}
                />
            </DialogContent>
        </Dialog>
    );
}

function EditUserDialog({ user, roles }: { user: UserRow; roles: string[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors, reset } = useForm<UserFormData>({
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        role: user.roles[0] ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/users/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('password', 'password_confirmation');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={`ویرایش ${user.name}`}>
                    <Pencil className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>ویرایش کاربر</DialogTitle>
                    <DialogDescription>{user.email}</DialogDescription>
                </DialogHeader>
                <UserForm
                    roles={roles}
                    initial={user}
                    submitLabel="ذخیره تغییرات"
                    onSubmit={submit}
                    processing={processing}
                    errors={errors}
                    data={data}
                    setData={setData}
                />
            </DialogContent>
        </Dialog>
    );
}

function DeleteUserDialog({ user }: { user: UserRow }) {
    const [open, setOpen] = useState(false);
    const { delete: destroy, processing } = useForm();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive" aria-label={`حذف ${user.name}`}>
                    <Trash2 className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>حذف کاربر</DialogTitle>
                    <DialogDescription>
                        حساب «{user.name}» ({user.email}) حذف شود؟ این عملیات قابل بازگشت نیست.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        انصراف
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={() => destroy(`/users/${user.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) })}
                    >
                        حذف کاربر
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function UsersIndex({ users, roles }: { users: UserRow[]; roles: string[] }) {
    const { auth, flash } = usePage<SharedData>().props;
    const pageErrors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="کاربران" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-bold">مدیریت کاربران</h1>
                        <p className="text-muted-foreground text-sm">ساخت و مدیریت حساب‌ها فقط توسط مدیر انجام می‌شود.</p>
                    </div>
                    <CreateUserDialog roles={roles} />
                </div>

                {flash?.success && (
                    <div className="border-success/30 bg-success/10 text-success rounded-lg border px-4 py-2 text-sm">{flash.success}</div>
                )}
                {(pageErrors?.user || pageErrors?.role) && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-2 text-sm">
                        {pageErrors.user ?? pageErrors.role}
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/40 text-muted-foreground border-b text-right">
                                        <th className="px-4 py-3 font-medium">نام</th>
                                        <th className="px-4 py-3 font-medium">ایمیل</th>
                                        <th className="px-4 py-3 font-medium">نقش</th>
                                        <th className="px-4 py-3 font-medium">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.map((user) => (
                                        <tr key={user.id} className="hover:bg-muted/30 border-b transition-colors last:border-0">
                                            <td className="px-4 py-3 font-medium">
                                                <span className="flex items-center gap-2">
                                                    {user.name}
                                                    {user.id === auth.user.id && (
                                                        <Badge variant="secondary" className="text-[11px]">
                                                            شما
                                                        </Badge>
                                                    )}
                                                </span>
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3" dir="ltr">
                                                {user.email}
                                            </td>
                                            <td className="px-4 py-3">
                                                {user.roles.map((role) => (
                                                    <Badge key={role} variant={role === 'admin' ? 'default' : 'secondary'} className="me-1 gap-1">
                                                        {role === 'admin' && <ShieldCheck className="h-3 w-3" />}
                                                        {roleLabels[role] ?? role}
                                                    </Badge>
                                                ))}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1">
                                                    <EditUserDialog user={user} roles={roles} />
                                                    {user.id !== auth.user.id && <DeleteUserDialog user={user} />}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

// Components
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout title="فراموشی رمز عبور" description="ایمیل خود را وارد کنید تا پیوند بازنشانی رمز برایتان ارسال شود">
            <Head title="فراموشی رمز عبور" />

            {status && <div className="text-success mb-4 text-center text-sm font-medium">{status}</div>}

            <div className="space-y-6">
                <form onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="email">ایمیل</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="off"
                            value={data.email}
                            autoFocus
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="email@example.com"
                            dir="ltr"
                            className="text-left"
                        />

                        <InputError message={errors.email} />
                    </div>

                    <div className="my-6 flex items-center justify-start">
                        <Button className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            ارسال پیوند بازنشانی رمز
                        </Button>
                    </div>
                </form>

                <div className="text-muted-foreground flex justify-center gap-1 text-center text-sm">
                    <span>بازگشت به</span>
                    <TextLink href={route('login')}>صفحه ورود</TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}

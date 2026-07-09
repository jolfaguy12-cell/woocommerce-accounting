// Components
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <AuthLayout title="تأیید ایمیل" description="برای تأیید ایمیل، روی پیوندی که برایتان ارسال شد کلیک کنید.">
            <Head title="تأیید ایمیل" />

            {status === 'verification-link-sent' && (
                <div className="text-success mb-4 text-center text-sm font-medium">پیوند تأیید جدید به ایمیل شما ارسال شد.</div>
            )}

            <form onSubmit={submit} className="space-y-6 text-center">
                <Button disabled={processing} variant="secondary">
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    ارسال دوباره ایمیل تأیید
                </Button>

                <TextLink href={route('logout')} method="post" className="mx-auto block text-sm">
                    خروج از حساب
                </TextLink>
            </form>
        </AuthLayout>
    );
}

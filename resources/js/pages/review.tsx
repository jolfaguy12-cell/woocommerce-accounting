import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مرکز بازبینی', href: '/review' }];

const typeLabels: Record<string, string> = {
    missing_cost: 'بدون بهای تمام‌شده',
    unmapped_product: 'محصول بدون نگاشت',
    unknown_source: 'منبع ناشناخته',
    missing_shipping: 'هزینه حمل ناقص',
    missing_commission: 'کارمزد ناموجود',
    sync_error: 'خطای همگام‌سازی',
    late_entry: 'ثبت دیرهنگام',
};

type Item = {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    subject_type: string | null;
    subject_id: number | null;
    source: { id: number; raw_value: string; order_count: number } | null;
    created_at: string;
};

type Channel = { id: number; name: string; slug: string; cost_model: string };

function MapSourceForm({ source, channels }: { source: NonNullable<Item['source']>; channels: Channel[] }) {
    const [channelId, setChannelId] = useState<string>('');
    const [newName, setNewName] = useState('');

    const submit = () => {
        router.post(
            `/review/sources/${source.id}/map`,
            channelId ? { channel_id: Number(channelId) } : { new_channel_name: newName, new_channel_cost_model: 'none' },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <select className="bg-background h-9 rounded-md border px-2 text-sm" value={channelId} onChange={(e) => setChannelId(e.target.value)}>
                <option value="">کانال جدید…</option>
                {channels.map((c) => (
                    <option key={c.id} value={c.id}>
                        {c.name}
                    </option>
                ))}
            </select>
            {!channelId && <Input className="h-9 w-40" placeholder="نام کانال جدید" value={newName} onChange={(e) => setNewName(e.target.value)} />}
            <Button size="sm" onClick={submit} disabled={!channelId && newName.trim() === ''}>
                اتصال
            </Button>
        </div>
    );
}

export default function Review({ items, channels }: { items: Item[]; channels: Channel[] }) {
    const close = (item: Item, action: 'resolved' | 'dismissed') => router.post(`/review/${item.id}/resolve`, { action }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مرکز بازبینی" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <h1 className="text-xl font-bold">مرکز بازبینی ({items.length.toLocaleString('fa-IR')} مورد باز)</h1>
                {items.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-8 text-center">همه‌چیز پاک است ✅</CardContent>
                    </Card>
                )}
                {items.map((item) => (
                    <Card key={item.id}>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Badge variant={item.type === 'sync_error' ? 'destructive' : 'secondary'}>{typeLabels[item.type] ?? item.type}</Badge>
                                <span className="text-muted-foreground text-xs font-normal">{new Date(item.created_at).toLocaleString('fa-IR')}</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-center justify-between gap-3">
                            <div className="text-sm">
                                {item.source ? (
                                    <span>
                                        منبع خام: <code className="bg-muted rounded px-1">{item.source.raw_value}</code> (
                                        {item.source.order_count.toLocaleString('fa-IR')} سفارش)
                                    </span>
                                ) : (
                                    <code className="text-muted-foreground text-xs" dir="ltr">
                                        {item.subject_type}#{item.subject_id} {item.payload ? JSON.stringify(item.payload).slice(0, 120) : ''}
                                    </code>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                {item.type === 'unknown_source' && item.source && <MapSourceForm source={item.source} channels={channels} />}
                                <Button size="sm" variant="outline" onClick={() => close(item, 'resolved')}>
                                    حل شد
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => close(item, 'dismissed')}>
                                    نادیده
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}

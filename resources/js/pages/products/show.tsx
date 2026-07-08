import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');
const pct = (n: number | null) => (n === null ? '—' : n.toLocaleString('fa-IR', { maximumFractionDigits: 1 }) + '٪');

type Pricing = {
    latest_cost: number | null; cost_source: string | null; retail_price: number | null;
    retail_profit: number | null; retail_margin: number | null;
    wholesale_price: number | null; wholesale_profit: number | null; wholesale_margin: number | null;
    mapping_status: string;
};

type ProductData = {
    id: number; hub_product_id: number; name: string; type: string; sku: string | null; gtin: string | null;
    status: string | null; price: number | null; regular_price: number | null;
    stock_quantity: number | null; stock_status: string | null; pricing: Pricing;
    mapping: { cost_item: string | null; cost_item_id: number | null; multiplier: number; status: string } | null;
    variations: { id: number; hub_product_id: number; name: string; price: number | null; stock_quantity: number | null }[];
    price_history: { old_price: number | null; new_price: number | null; source: string; changed_at: string }[];
    stock_history: { old_quantity: number | null; new_quantity: number | null; source: string; changed_at: string }[];
};

export default function ProductShow({ product, cost_items }: { product: ProductData; cost_items: { id: number; name: string; sku: string | null }[] }) {
    const [costItemId, setCostItemId] = useState<string>(product.mapping?.cost_item_id?.toString() ?? '');
    const [newItem, setNewItem] = useState('');
    const [multiplier, setMultiplier] = useState<string>(product.mapping?.multiplier?.toString() ?? '1');
    const [wholesale, setWholesale] = useState<string>(product.pricing.wholesale_price?.toString() ?? '');

    const saveMapping = () =>
        router.post(`/products/${product.id}/map`,
            costItemId ? { cost_item_id: Number(costItemId), multiplier: Number(multiplier) } : { new_item_name: newItem, multiplier: Number(multiplier) },
            { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={[{ title: 'محصولات', href: '/products' }, { title: product.name, href: '#' }]}>
            <Head title={product.name} />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold">{product.name}</h1>
                    <Badge variant="outline">{product.type}</Badge>
                    <span className="text-sm text-muted-foreground" dir="ltr">
                        #{product.hub_product_id} {product.sku ? `· ${product.sku}` : ''} {product.gtin ? `· GTIN ${product.gtin}` : ''}
                    </span>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle className="text-base">قیمت و موجودی سایت</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            <div className="flex justify-between"><span className="text-muted-foreground">قیمت فروش</span><span dir="ltr">{product.price !== null ? fmt(product.price) : '—'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">قیمت قبل تخفیف</span><span dir="ltr">{product.regular_price !== null ? fmt(product.regular_price) : '—'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">موجودی هاب</span><span>{product.stock_quantity !== null ? fmt(product.stock_quantity) : product.stock_status ?? '—'}</span></div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">سودآوری (محرمانه)</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            <div className="flex justify-between"><span className="text-muted-foreground">آخرین بهای خرید</span><span dir="ltr">{product.pricing.latest_cost !== null ? fmt(product.pricing.latest_cost) : 'ندارد'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">سود خرده‌فروشی</span><span dir="ltr">{product.pricing.retail_profit !== null ? `${fmt(product.pricing.retail_profit)} (${pct(product.pricing.retail_margin)})` : '—'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">قیمت عمده داخلی</span><span dir="ltr">{product.pricing.wholesale_price !== null ? fmt(product.pricing.wholesale_price) : '—'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">سود عمده</span><span dir="ltr">{product.pricing.wholesale_profit !== null ? `${fmt(product.pricing.wholesale_profit)} (${pct(product.pricing.wholesale_margin)})` : '—'}</span></div>
                            <div className="mt-3 flex items-center gap-2 border-t pt-2">
                                <Input className="h-8 w-32" dir="ltr" type="number" placeholder="قیمت عمده" value={wholesale} onChange={(e) => setWholesale(e.target.value)} />
                                <Button size="sm" variant="outline" disabled={wholesale === ''} onClick={() => router.post(`/products/${product.id}/wholesale`, { price: Number(wholesale) }, { preserveScroll: true })}>
                                    ثبت عمده
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                نگاشت بهای تمام‌شده{' '}
                                <Badge variant={product.pricing.mapping_status === 'mapped' ? 'default' : 'destructive'}>
                                    {product.pricing.mapping_status === 'mapped' ? 'نگاشت‌شده' : 'بدون نگاشت'}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <select className="h-9 w-full rounded-md border bg-background px-2" value={costItemId} onChange={(e) => setCostItemId(e.target.value)}>
                                <option value="">قلم جدید…</option>
                                {cost_items.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}{c.sku ? ` (${c.sku})` : ''}</option>
                                ))}
                            </select>
                            {!costItemId && <Input placeholder="نام قلم جدید" value={newItem} onChange={(e) => setNewItem(e.target.value)} />}
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground">ضریب:</span>
                                <Input className="h-8 w-24" dir="ltr" type="number" step="0.001" value={multiplier} onChange={(e) => setMultiplier(e.target.value)} />
                                <Button size="sm" onClick={saveMapping} disabled={!costItemId && newItem.trim() === ''}>ذخیره نگاشت</Button>
                            </div>
                            {product.mapping?.cost_item && <p className="text-xs text-muted-foreground">فعلی: {product.mapping.cost_item} × {product.mapping.multiplier}</p>}
                        </CardContent>
                    </Card>
                </div>

                {product.variations.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">تنوع‌ها</CardTitle></CardHeader>
                        <CardContent className="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                            {product.variations.map((v) => (
                                <Link key={v.id} href={`/products/${v.id}`} className="flex justify-between rounded-lg border p-2 text-sm hover:bg-muted/40">
                                    <span>{v.name}</span>
                                    <span dir="ltr" className="text-muted-foreground">{v.price !== null ? fmt(v.price) : '—'} · موجودی {v.stock_quantity !== null ? fmt(v.stock_quantity) : '—'}</span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {([['تاریخچه قیمت', product.price_history.map((h) => ({ ...h, old: h.old_price, new: h.new_price }))],
                        ['تاریخچه موجودی', product.stock_history.map((h) => ({ ...h, old: h.old_quantity, new: h.new_quantity }))]] as const
                    ).map(([title, rows]) => (
                        <Card key={title}>
                            <CardHeader><CardTitle className="text-base">{title}</CardTitle></CardHeader>
                            <CardContent className="space-y-1 text-sm">
                                {rows.length === 0 && <p className="text-muted-foreground">تغییری ثبت نشده</p>}
                                {rows.map((h, i) => (
                                    <div key={i} className="flex justify-between border-b py-1 last:border-0">
                                        <span className="text-muted-foreground">{new Date(h.changed_at).toLocaleString('fa-IR')} <Badge variant="outline" className="mr-1">{h.source}</Badge></span>
                                        <span dir="ltr">{h.old !== null ? fmt(h.old) : '—'} → {h.new !== null ? fmt(h.new) : '—'}</span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

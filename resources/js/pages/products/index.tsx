import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');

type Row = {
    id: number;
    hub_product_id: number;
    name: string;
    type: string;
    sku: string | null;
    price: number | null;
    stock_quantity: number | null;
    mapping_status: string;
};
type Paginated = { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };

export default function ProductsIndex({ products, filters }: { products: Paginated; filters: { q?: string; mapping?: string } }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'محصولات', href: '/products' }]}>
            <Head title="محصولات" />
            <div className="flex flex-col gap-4 p-4" dir="rtl">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold">محصولات</h1>
                    <Input
                        className="h-9 w-56"
                        placeholder="جستجو نام / SKU / شناسه"
                        defaultValue={filters.q ?? ''}
                        onKeyDown={(e) =>
                            e.key === 'Enter' && router.get('/products', { ...filters, q: e.currentTarget.value }, { preserveState: true })
                        }
                    />
                    <label className="flex items-center gap-1 text-sm">
                        <input
                            type="checkbox"
                            defaultChecked={filters.mapping === 'unmapped'}
                            onChange={(e) =>
                                router.get('/products', { ...filters, mapping: e.target.checked ? 'unmapped' : undefined }, { preserveState: true })
                            }
                        />
                        فقط بدون نگاشت
                    </label>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-right">
                                    <th className="p-3 font-normal">محصول</th>
                                    <th className="font-normal">نوع</th>
                                    <th className="font-normal">SKU</th>
                                    <th className="font-normal">قیمت سایت</th>
                                    <th className="font-normal">موجودی</th>
                                    <th className="font-normal">نگاشت بها</th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.data.map((p) => (
                                    <tr key={p.id} className="hover:bg-muted/40 border-b last:border-0">
                                        <td className="p-3">
                                            <Link href={`/products/${p.id}`} className="text-primary hover:underline">
                                                {p.name}
                                            </Link>
                                            <span className="text-muted-foreground mr-2 text-xs" dir="ltr">
                                                #{p.hub_product_id}
                                            </span>
                                        </td>
                                        <td>
                                            <Badge variant="outline">{p.type}</Badge>
                                        </td>
                                        <td dir="ltr">{p.sku ?? '—'}</td>
                                        <td dir="ltr">{p.price !== null ? fmt(p.price) : '—'}</td>
                                        <td>{p.stock_quantity !== null ? fmt(p.stock_quantity) : '—'}</td>
                                        <td>
                                            <Badge
                                                variant={
                                                    p.mapping_status === 'mapped' ? 'default' : p.type === 'variable' ? 'outline' : 'destructive'
                                                }
                                            >
                                                {p.mapping_status === 'mapped' ? 'نگاشت‌شده' : p.type === 'variable' ? '— (والد)' : 'بدون نگاشت'}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                                {products.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                            محصولی یافت نشد — با acc:sync:product همگام‌سازی کنید
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-1" dir="ltr">
                    {products.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                preserveState
                                className={`rounded border px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : null,
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

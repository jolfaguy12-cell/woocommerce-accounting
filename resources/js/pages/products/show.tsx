import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    Boxes,
    History,
    Layers,
    LoaderCircle,
    type LucideIcon,
    NotebookPen,
    PackageX,
    Pencil,
    RefreshCw,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('fa-IR');
const pct = (n: number | null) => (n === null ? '—' : n.toLocaleString('fa-IR', { maximumFractionDigits: 1 }) + '٪');
const fmtDate = (iso: string) => new Date(iso).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' });

type Pricing = {
    latest_cost: number | null;
    cost_source: string | null;
    retail_price: number | null;
    retail_profit: number | null;
    retail_margin: number | null;
    wholesale_price: number | null;
    wholesale_profit: number | null;
    wholesale_margin: number | null;
    mapping_status: string;
};

type HistoryRow = { source: string; changed_at: string; old: number | null; new: number | null };

type ProductData = {
    id: number;
    hub_product_id: number;
    parent_hub_id: number | null;
    name: string;
    type: string;
    sku: string | null;
    gtin: string | null;
    status: string | null;
    price: number | null;
    regular_price: number | null;
    stock_quantity: number | null;
    stock_status: string | null;
    pricing: Pricing;
    mapping: { cost_item: string | null; cost_item_id: number | null; multiplier: number; status: string } | null;
    variations: { id: number; hub_product_id: number; name: string; price: number | null; stock_quantity: number | null }[];
    price_history: { old_price: number | null; new_price: number | null; source: string; changed_at: string }[];
    stock_history: { old_quantity: number | null; new_quantity: number | null; source: string; changed_at: string }[];
    purchase_history: { id: number; unit_cost: number; landed_unit_cost: number; source: string; effective_at: string }[];
    notes: { id: number; title: string; body: string | null; multiplier: string | null; author: string | null; created_at: string }[];
    sync: { hub_modified_at: string | null; mirrored_at: string | null };
};

const typeLabels: Record<string, string> = {
    simple: 'محصول ساده',
    variable: 'محصول متغیر',
    variation: 'تنوع محصول',
};

const sourceLabels: Record<string, string> = {
    webhook: 'وب‌هوک',
    poll: 'دریافت دوره‌ای',
    manual: 'دستی',
    invoice: 'فاکتور خرید',
    import: 'ورود گروهی',
};

function InfoRow({ label, value, valueClass = '' }: { label: string; value: React.ReactNode; valueClass?: string }) {
    return (
        <div className="flex items-center justify-between gap-2 py-1.5">
            <span className="text-muted-foreground text-sm">{label}</span>
            <span className={`text-sm font-medium ${valueClass}`}>{value}</span>
        </div>
    );
}

function SectionCard({
    title,
    icon: Icon,
    children,
    action,
}: {
    title: string;
    icon: LucideIcon;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <Card className="gap-3">
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className="text-muted-foreground size-4" />
                    {title}
                </CardTitle>
                {action}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function WholesaleDialog({ product }: { product: ProductData }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        price: product.pricing.wholesale_price?.toString() ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/products/${product.id}/wholesale`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Banknote className="size-4" />
                    ثبت قیمت عمده
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>ثبت قیمت عمده داخلی</DialogTitle>
                    <DialogDescription>این قیمت فقط داخلی است و هرگز به ووکامرس ارسال نمی‌شود.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="wholesale-price">قیمت عمده (تومان)</Label>
                        <Input
                            id="wholesale-price"
                            type="number"
                            min={0}
                            dir="ltr"
                            className="text-left"
                            value={data.price}
                            onChange={(e) => setData('price', e.target.value)}
                            required
                        />
                        <InputError message={errors.price} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing || data.price === ''}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            ثبت قیمت عمده
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CostDialog({ product }: { product: ProductData }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ unit_cost: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/products/${product.id}/cost`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Boxes className="size-4" />
                    ثبت بهای تمام‌شده
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>ثبت بهای تمام‌شده</DialogTitle>
                    <DialogDescription>
                        {product.mapping?.cost_item
                            ? `بهای جدید برای قلم «${product.mapping.cost_item}» ثبت می‌شود.`
                            : 'ابتدا محصول را به قلم بهای تمام‌شده نگاشت کنید.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="unit-cost">بهای هر واحد (تومان)</Label>
                        <Input
                            id="unit-cost"
                            type="number"
                            min={1}
                            dir="ltr"
                            className="text-left"
                            value={data.unit_cost}
                            onChange={(e) => setData('unit_cost', e.target.value)}
                            required
                        />
                        <InputError message={errors.unit_cost} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing || data.unit_cost === '' || !product.mapping?.cost_item_id}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            ثبت بهای تمام‌شده
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditMappingDialog({ product, costItems }: { product: ProductData; costItems: { id: number; name: string; sku: string | null }[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        cost_item_id: product.mapping?.cost_item_id?.toString() ?? '',
        new_item_name: '',
        multiplier: product.mapping?.multiplier?.toString() ?? '1',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/products/${product.id}/map`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Pencil className="size-4" />
                    ویرایش محصول
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>ویرایش اطلاعات داخلی محصول</DialogTitle>
                    <DialogDescription>
                        اطلاعات فروشگاه فقط از هاب خوانده می‌شود؛ اینجا نگاشت بهای تمام‌شده و ضریب واحد ویرایش می‌شود.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="cost-item">قلم بهای تمام‌شده</Label>
                        <Select value={data.cost_item_id || 'new'} onValueChange={(v) => setData('cost_item_id', v === 'new' ? '' : v)}>
                            <SelectTrigger id="cost-item">
                                <SelectValue placeholder="انتخاب قلم" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="new">قلم جدید…</SelectItem>
                                {costItems.map((c) => (
                                    <SelectItem key={c.id} value={c.id.toString()}>
                                        {c.name}
                                        {c.sku ? ` (${c.sku})` : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cost_item_id} />
                    </div>
                    {!data.cost_item_id && (
                        <div className="grid gap-2">
                            <Label htmlFor="new-item">نام قلم جدید</Label>
                            <Input id="new-item" value={data.new_item_name} onChange={(e) => setData('new_item_name', e.target.value)} />
                            <InputError message={errors.new_item_name} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="multiplier">ضریب (تعداد واحد در هر فروش)</Label>
                        <Input
                            id="multiplier"
                            type="number"
                            step="0.001"
                            min="0.001"
                            dir="ltr"
                            className="text-left"
                            value={data.multiplier}
                            onChange={(e) => setData('multiplier', e.target.value)}
                        />
                        <InputError message={errors.multiplier} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing || (!data.cost_item_id && data.new_item_name.trim() === '')}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            ذخیره تغییرات
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function NotesCard({ product, canEdit }: { product: ProductData; canEdit: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        body: '',
        multiplier: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/products/${product.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <SectionCard title="یادداشت‌های محصول" icon={NotebookPen}>
            <div className="space-y-4">
                {canEdit && (
                    <form onSubmit={submit} className="bg-muted/20 grid gap-3 rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs font-medium">افزودن یادداشت جدید</p>
                        <div className="grid gap-3 sm:grid-cols-[1fr_8rem]">
                            <div className="grid gap-1.5">
                                <Label htmlFor="note-title" className="text-xs">
                                    عنوان یادداشت
                                </Label>
                                <Input id="note-title" value={data.title} onChange={(e) => setData('title', e.target.value)} required />
                                <InputError message={errors.title} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="note-multiplier" className="text-xs">
                                    ضریب (اختیاری)
                                </Label>
                                <Input
                                    id="note-multiplier"
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    dir="ltr"
                                    className="text-left"
                                    value={data.multiplier}
                                    onChange={(e) => setData('multiplier', e.target.value)}
                                />
                                <InputError message={errors.multiplier} />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="note-body" className="text-xs">
                                متن یادداشت
                            </Label>
                            <Textarea id="note-body" rows={2} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                            <InputError message={errors.body} />
                        </div>
                        <div>
                            <Button type="submit" size="sm" disabled={processing || data.title.trim() === ''}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                ذخیره یادداشت
                            </Button>
                        </div>
                    </form>
                )}

                {product.notes.length === 0 ? (
                    <p className="text-muted-foreground py-2 text-center text-sm">هنوز یادداشتی ثبت نشده است</p>
                ) : (
                    <ol className="relative space-y-4 border-s ps-4">
                        {product.notes.map((note) => (
                            <li key={note.id} className="relative">
                                <span className="border-background bg-primary absolute -start-[21px] top-1.5 size-2.5 rounded-full border-2" />
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium">{note.title}</span>
                                    {note.multiplier !== null && (
                                        <Badge variant="secondary" className="text-[11px]">
                                            ضریب {Number(note.multiplier).toLocaleString('fa-IR', { maximumFractionDigits: 3 })}
                                        </Badge>
                                    )}
                                </div>
                                {note.body && <p className="text-muted-foreground mt-0.5 text-sm">{note.body}</p>}
                                <p className="text-muted-foreground/70 mt-0.5 text-xs">
                                    {note.author ?? '—'} · {fmtDate(note.created_at)}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </SectionCard>
    );
}

function HistoryTable({ rows, emptyText }: { rows: HistoryRow[]; emptyText: string }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground py-4 text-center text-sm">{emptyText}</p>;
    }

    return (
        <div className="space-y-1 text-sm">
            {rows.map((h, i) => (
                <div key={i} className="flex flex-wrap items-center justify-between gap-2 border-b py-1.5 last:border-0">
                    <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                        {fmtDate(h.changed_at)}
                        <Badge variant="outline" className="text-[10px]">
                            {sourceLabels[h.source] ?? h.source}
                        </Badge>
                    </span>
                    <span className="text-sm whitespace-nowrap" dir="ltr">
                        {h.old !== null ? fmt(h.old) : '—'} ← {h.new !== null ? fmt(h.new) : '—'}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function ProductShow({
    product,
    cost_items,
}: {
    product: ProductData;
    cost_items: { id: number; name: string; sku: string | null }[];
}) {
    const { auth, flash } = usePage<SharedData>().props;
    const roles = auth.user?.roles ?? [];
    const canEdit = roles.includes('admin') || roles.includes('accountant');
    const pageErrors = usePage().props.errors as Record<string, string>;

    const { post: syncPost, processing: syncing } = useForm({});
    const syncNow = () => syncPost(`/products/${product.id}/sync`, { preserveScroll: true });

    const outdatedSync = product.sync.mirrored_at !== null && Date.now() - new Date(product.sync.mirrored_at).getTime() > 48 * 3600 * 1000;

    const warnings: { text: string; tone: 'destructive' | 'warning' }[] = [];
    if (product.pricing.mapping_status !== 'mapped') warnings.push({ text: 'محصول به قلم بهای تمام‌شده نگاشت نشده است', tone: 'destructive' });
    else if (product.pricing.latest_cost === null) warnings.push({ text: 'بهای تمام‌شده‌ای برای این محصول ثبت نشده است', tone: 'destructive' });
    if (product.pricing.retail_profit !== null && product.pricing.retail_profit < 0)
        warnings.push({ text: 'سود هر واحد منفی است؛ قیمت فروش را بازبینی کنید', tone: 'destructive' });
    if (product.stock_quantity !== null && product.stock_quantity <= 0) warnings.push({ text: 'موجودی محصول صفر است', tone: 'warning' });
    if (outdatedSync) warnings.push({ text: 'اطلاعات محصول مدتی است از هاب به‌روزرسانی نشده است', tone: 'warning' });

    const priceRows: HistoryRow[] = product.price_history.map((h) => ({
        source: h.source,
        changed_at: h.changed_at,
        old: h.old_price,
        new: h.new_price,
    }));
    const stockRows: HistoryRow[] = product.stock_history.map((h) => ({
        source: h.source,
        changed_at: h.changed_at,
        old: h.old_quantity,
        new: h.new_quantity,
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'محصولات', href: '/products' },
                { title: product.name, href: '#' },
            ]}
        >
            <Head title={`جزئیات محصول — ${product.name}`} />
            <div className="flex flex-col gap-4 p-4">
                {/* Summary header */}
                <Card className="gap-3">
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-muted-foreground text-xs">جزئیات محصول</p>
                                <h1 className="mt-1 text-lg font-bold sm:text-xl">{product.name}</h1>
                                <div className="text-muted-foreground mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <Badge variant="secondary">{typeLabels[product.type] ?? product.type}</Badge>
                                    <span>
                                        شناسه محصول: <span dir="ltr">#{product.hub_product_id}</span>
                                    </span>
                                    {product.sku && (
                                        <span>
                                            SKU: <span dir="ltr">{product.sku}</span>
                                        </span>
                                    )}
                                    {product.gtin && (
                                        <span>
                                            GTIN: <span dir="ltr">{product.gtin}</span>
                                        </span>
                                    )}
                                    {product.status && <Badge variant="outline">{product.status === 'publish' ? 'منتشرشده' : product.status}</Badge>}
                                </div>
                            </div>
                            {canEdit && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <WholesaleDialog product={product} />
                                    <CostDialog product={product} />
                                    <EditMappingDialog product={product} costItems={cost_items} />
                                    <Button size="sm" onClick={syncNow} disabled={syncing}>
                                        <RefreshCw className={`size-4 ${syncing ? 'animate-spin' : ''}`} />
                                        همگام‌سازی با ووکامرس
                                    </Button>
                                </div>
                            )}
                        </div>

                        {(warnings.length > 0 || flash?.success || pageErrors?.sync) && (
                            <div className="grid gap-1.5">
                                {flash?.success && (
                                    <div className="border-success/30 bg-success/10 text-success rounded-lg border px-3 py-2 text-sm">
                                        {flash.success}
                                    </div>
                                )}
                                {pageErrors?.sync && (
                                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-3 py-2 text-sm">
                                        {pageErrors.sync}
                                    </div>
                                )}
                                {warnings.map((warning, i) => (
                                    <div
                                        key={i}
                                        className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                                            warning.tone === 'destructive'
                                                ? 'border-destructive/30 bg-destructive/10 text-destructive'
                                                : 'border-warning/40 bg-warning/10 text-foreground'
                                        }`}
                                    >
                                        <AlertTriangle className={`size-4 shrink-0 ${warning.tone === 'warning' ? 'text-warning' : ''}`} />
                                        {warning.text}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Price / profitability / sync status */}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <SectionCard title="قیمت و موجودی" icon={Banknote}>
                        <div className="divide-y">
                            <InfoRow label="قیمت فروش" value={product.price !== null ? `${fmt(product.price)} تومان` : '—'} />
                            <InfoRow label="قیمت قبل از تخفیف" value={product.regular_price !== null ? `${fmt(product.regular_price)} تومان` : '—'} />
                            <InfoRow
                                label="موجودی فعلی"
                                value={product.stock_quantity !== null ? `${fmt(product.stock_quantity)} عدد` : (product.stock_status ?? '—')}
                                valueClass={product.stock_quantity !== null && product.stock_quantity <= 0 ? 'text-destructive' : ''}
                            />
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="سودآوری"
                        icon={product.pricing.retail_profit !== null && product.pricing.retail_profit < 0 ? TrendingDown : TrendingUp}
                        action={
                            <Badge variant={product.pricing.mapping_status === 'mapped' ? 'secondary' : 'destructive'} className="text-[11px]">
                                {product.pricing.mapping_status === 'mapped' ? 'نگاشت‌شده' : 'بدون نگاشت'}
                            </Badge>
                        }
                    >
                        <div className="divide-y">
                            <InfoRow
                                label="قیمت تمام‌شده آخر"
                                value={product.pricing.latest_cost !== null ? `${fmt(product.pricing.latest_cost)} تومان` : 'ثبت نشده'}
                                valueClass={product.pricing.latest_cost === null ? 'text-destructive' : ''}
                            />
                            <InfoRow
                                label="سود هر واحد"
                                value={product.pricing.retail_profit !== null ? `${fmt(product.pricing.retail_profit)} تومان` : '—'}
                                valueClass={
                                    product.pricing.retail_profit === null
                                        ? ''
                                        : product.pricing.retail_profit >= 0
                                          ? 'text-success'
                                          : 'text-destructive'
                                }
                            />
                            <InfoRow label="حاشیه سود" value={pct(product.pricing.retail_margin)} />
                            <InfoRow
                                label="قیمت عمده داخلی"
                                value={product.pricing.wholesale_price !== null ? `${fmt(product.pricing.wholesale_price)} تومان` : '—'}
                            />
                            <InfoRow
                                label="سود عمده"
                                value={
                                    product.pricing.wholesale_profit !== null
                                        ? `${fmt(product.pricing.wholesale_profit)} تومان (${pct(product.pricing.wholesale_margin)})`
                                        : '—'
                                }
                                valueClass={
                                    product.pricing.wholesale_profit === null
                                        ? ''
                                        : product.pricing.wholesale_profit >= 0
                                          ? 'text-success'
                                          : 'text-destructive'
                                }
                            />
                            {product.mapping?.cost_item && (
                                <InfoRow
                                    label="قلم نگاشت‌شده"
                                    value={`${product.mapping.cost_item} × ${Number(product.mapping.multiplier).toLocaleString('fa-IR', { maximumFractionDigits: 3 })}`}
                                />
                            )}
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="وضعیت همگام‌سازی با ووکامرس"
                        icon={RefreshCw}
                        action={
                            outdatedSync ? (
                                <Badge variant="destructive" className="text-[11px]">
                                    قدیمی
                                </Badge>
                            ) : (
                                <Badge variant="secondary" className="text-[11px]">
                                    به‌روز
                                </Badge>
                            )
                        }
                    >
                        <div className="divide-y">
                            <InfoRow
                                label="آخرین تغییر در فروشگاه"
                                value={product.sync.hub_modified_at ? fmtDate(product.sync.hub_modified_at) : '—'}
                            />
                            <InfoRow label="آخرین به‌روزرسانی آینه" value={product.sync.mirrored_at ? fmtDate(product.sync.mirrored_at) : '—'} />
                        </div>
                        <p className="text-muted-foreground mt-3 text-xs">
                            داده‌ها فقط از هاب خوانده می‌شود؛ این سامانه چیزی در ووکامرس تغییر نمی‌دهد.
                        </p>
                    </SectionCard>
                </div>

                {/* Variations */}
                {product.variations.length > 0 && (
                    <SectionCard title="تنوع‌های محصول" icon={Layers}>
                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {product.variations.map((v) => (
                                <Link
                                    key={v.id}
                                    href={`/products/${v.id}`}
                                    className="hover:border-primary/40 hover:bg-accent/50 flex flex-col gap-1 rounded-lg border p-3 text-sm transition-colors"
                                >
                                    <span className="truncate font-medium">{v.name}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {v.price !== null ? `${fmt(v.price)} تومان` : 'بدون قیمت'} · موجودی{' '}
                                        {v.stock_quantity !== null ? fmt(v.stock_quantity) : '—'}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </SectionCard>
                )}

                {/* Notes + purchase history */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <NotesCard product={product} canEdit={canEdit} />

                    <SectionCard title="تاریخچه خرید" icon={PackageX}>
                        {product.purchase_history.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center text-sm">هنوز خریدی برای قلم این محصول ثبت نشده است</p>
                        ) : (
                            <div className="space-y-1 text-sm">
                                {product.purchase_history.map((row) => (
                                    <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 border-b py-1.5 last:border-0">
                                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                            {new Date(row.effective_at).toLocaleDateString('fa-IR')}
                                            <Badge variant="outline" className="text-[10px]">
                                                {sourceLabels[row.source] ?? row.source}
                                            </Badge>
                                        </span>
                                        <span className="font-medium whitespace-nowrap">{fmt(row.landed_unit_cost)} تومان</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </SectionCard>
                </div>

                {/* Change history */}
                <SectionCard title="تغییرات ثبت‌شده" icon={History}>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div>
                            <h3 className="text-muted-foreground mb-2 text-sm font-medium">تاریخچه قیمت</h3>
                            <HistoryTable rows={priceRows} emptyText="هنوز تغییری ثبت نشده است" />
                        </div>
                        <div>
                            <h3 className="text-muted-foreground mb-2 text-sm font-medium">تاریخچه موجودی</h3>
                            <HistoryTable rows={stockRows} emptyText="هنوز تغییری ثبت نشده است" />
                        </div>
                    </div>
                </SectionCard>
            </div>
        </AppLayout>
    );
}

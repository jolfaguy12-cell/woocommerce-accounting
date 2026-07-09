import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCircle2, CircleDashed, TriangleAlert } from 'lucide-react';

function Section({ title, icon, items, tone }: { title: string; icon: React.ReactNode; items: string[]; tone: string }) {
    if (items.length === 0) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className={`flex items-center gap-2 text-base font-semibold ${tone}`}>
                    {icon}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="list-disc space-y-1.5 pr-5 text-sm text-muted-foreground">
                    {items.map((item, i) => (
                        <li key={i}>{item}</li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

/** Standard "what's ready / what could be added / what's missing" review layout for placeholder pages. */
export default function CapabilityList({
    available,
    future,
    missing,
}: {
    available?: string[];
    future?: string[];
    missing?: string[];
}) {
    return (
        <div className="grid gap-4">
            <Section
                title="امکانات آماده (بک‌اند موجود است)"
                icon={<CheckCircle2 className="size-4" />}
                items={available ?? []}
                tone="text-emerald-600 dark:text-emerald-400"
            />
            <Section
                title="پیشنهاد برای آینده"
                icon={<CircleDashed className="size-4" />}
                items={future ?? []}
                tone="text-muted-foreground"
            />
            <Section
                title="نیازمند بک‌اند/تصمیم قبل از پیاده‌سازی نهایی"
                icon={<TriangleAlert className="size-4" />}
                items={missing ?? []}
                tone="text-amber-600 dark:text-amber-400"
            />
        </div>
    );
}

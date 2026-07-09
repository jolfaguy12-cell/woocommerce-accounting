import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

export default function AppLogo() {
    const { name } = usePage<SharedData>().props;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <Wallet className="size-4.5" />
            </div>
            <div className="ms-1 grid flex-1 text-start text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{name}</span>
                <span className="text-sidebar-foreground/70 truncate text-[11px] leading-none">سامانه حسابداری فروش</span>
            </div>
        </>
    );
}

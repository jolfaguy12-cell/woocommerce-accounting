import { Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { LucideIcon, Monitor, Moon, Sun } from 'lucide-react';
import { HTMLAttributes } from 'react';

export default function AppearanceToggleTab({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'روشن' },
        { value: 'dark', icon: Moon, label: 'تیره' },
        { value: 'system', icon: Monitor, label: 'سیستم' },
    ];

    return (
        <div className={cn('bg-muted inline-flex gap-1 rounded-lg p-1', className)} {...props}>
            {tabs.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'flex cursor-pointer items-center rounded-md px-3.5 py-1.5 transition-colors',
                        appearance === value
                            ? 'bg-card text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                    )}
                >
                    <Icon className="-ms-1 h-4 w-4" />
                    <span className="ms-1.5 text-sm">{label}</span>
                </button>
            ))}
        </div>
    );
}

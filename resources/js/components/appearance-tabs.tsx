import type { LucideIcon } from 'lucide-react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { Button } from '@/components/ui/button';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: {
        value: Appearance;
        icon: LucideIcon;
        label: string;
        description: string;
    }[] = [
        {
            value: 'light',
            icon: Sun,
            label: 'Light',
            description: 'Warm, bright surfaces for your day.',
        },
        {
            value: 'dark',
            icon: Moon,
            label: 'Dark',
            description: 'A darker workspace for lower light.',
        },
        {
            value: 'system',
            icon: Monitor,
            label: 'System',
            description: "Follow your device's light or dark setting.",
        },
    ];

    return (
        <div
            role="group"
            aria-label="Appearance"
            className={cn('grid gap-3 sm:grid-cols-3', className)}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label, description }) => (
                <Button
                    key={value}
                    type="button"
                    variant="outline"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'h-auto min-h-32 flex-col items-start justify-start gap-3 p-4 text-left whitespace-normal',
                        appearance === value
                            ? 'border-primary bg-primary-tint text-primary'
                            : 'bg-card text-foreground',
                    )}
                >
                    <span className="flex w-full items-center justify-between gap-2">
                        <Icon className="size-5" aria-hidden="true" />
                        {appearance === value && (
                            <Check className="size-4" aria-hidden="true" />
                        )}
                    </span>
                    <span className="space-y-1">
                        <span className="block text-sm font-medium">
                            {label}
                        </span>
                        <span className="block text-xs leading-5 font-normal text-muted-foreground">
                            {description}
                        </span>
                    </span>
                </Button>
            ))}
        </div>
    );
}

import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type HousekeepingMetric = {
    label: string;
    value: number;
    icon: LucideIcon;
    tone: 'primary' | 'success' | 'warning' | 'occupied' | 'inspection';
};

const tones = {
    primary: {
        card: 'border-l-primary',
        icon: 'bg-primary-tint text-primary',
    },
    success: {
        card: 'border-l-status-success',
        icon: 'bg-status-success-tint text-status-success',
    },
    warning: {
        card: 'border-l-status-warning',
        icon: 'bg-status-warning-tint text-status-warning',
    },
    occupied: {
        card: 'border-l-status-occupied',
        icon: 'bg-status-occupied-tint text-status-occupied',
    },
    inspection: {
        card: 'border-l-status-inspection',
        icon: 'bg-status-inspection-tint text-status-inspection',
    },
} as const;

export function HousekeepingMetricCards({
    metrics,
    columns = 'four',
}: {
    metrics: HousekeepingMetric[];
    columns?: 'three' | 'four';
}) {
    return (
        <div
            className={cn(
                'grid min-w-0 grid-cols-2 gap-3',
                columns === 'three' ? 'xl:grid-cols-3' : 'xl:grid-cols-4',
            )}
        >
            {metrics.map(({ label, value, icon: Icon, tone }) => (
                <Card
                    key={label}
                    className={cn(
                        'min-w-0 flex-row items-center gap-0 border-l-4 py-0 shadow-panel',
                        tones[tone].card,
                    )}
                >
                    <CardContent className="flex min-w-0 items-center gap-3 px-3 py-3 md:px-4">
                        <span
                            className={cn(
                                'grid size-10 shrink-0 place-items-center',
                                tones[tone].icon,
                            )}
                        >
                            <Icon aria-hidden="true" className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-xs text-muted-foreground">
                                {label}
                            </p>
                            <p className="mt-0.5 text-2xl leading-none font-semibold tabular-nums">
                                {value}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

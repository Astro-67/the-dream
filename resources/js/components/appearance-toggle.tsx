import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';

const modes = {
    light: { icon: Sun, label: 'Light', next: 'dark' },
    dark: { icon: Moon, label: 'Dark', next: 'system' },
    system: { icon: Monitor, label: 'System', next: 'light' },
} satisfies Record<
    Appearance,
    { icon: typeof Sun; label: string; next: Appearance }
>;

/** One-click theme switch: cycles light, dark, system. */
export function AppearanceToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const { icon: Icon, label, next } = modes[appearance];
    const title = `Theme: ${label}. Switch to ${modes[next].label.toLowerCase()}`;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-11"
                    aria-label={title}
                    onClick={() => updateAppearance(next)}
                >
                    <Icon className="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{title}</TooltipContent>
        </Tooltip>
    );
}

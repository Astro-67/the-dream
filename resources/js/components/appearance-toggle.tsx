import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
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
        <Button
            variant="ghost"
            size="icon"
            aria-label={title}
            title={title}
            onClick={() => updateAppearance(next)}
        >
            <Icon className="size-4" />
        </Button>
    );
}

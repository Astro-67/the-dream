import BrandLogo from '@/components/brand-logo';
import { cn } from '@/lib/utils';

export default function AppLogo({
    inverted = false,
    className,
}: {
    inverted?: boolean;
    className?: string;
}) {
    return (
        <BrandLogo
            inverted={inverted}
            className={cn('h-auto w-40 shrink-0', className)}
        />
    );
}

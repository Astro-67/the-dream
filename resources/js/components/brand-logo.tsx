import { cn } from '@/lib/utils';

// The full Karafuu logo; the dark variant has its navy lettering turned white so it reads on dark surfaces.
export default function BrandLogo({
    className,
    inverted = false,
}: {
    className?: string;
    inverted?: boolean;
}) {
    const alt = 'Karafuu PMS, The Heart of Hospitality';

    if (inverted) {
        return (
            <img src="/karafuu-logo-dark.png" alt={alt} className={className} />
        );
    }

    return (
        <>
            <img
                src="/karafuu-logo.png"
                alt={alt}
                className={cn('dark:hidden', className)}
            />
            <img
                src="/karafuu-logo-dark.png"
                alt={alt}
                className={cn('hidden dark:block', className)}
            />
        </>
    );
}

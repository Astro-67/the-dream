import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

const n = (value: number | null) => (value ?? 0).toLocaleString('en');

// Sits directly below a Table. Laravel's `links` already window the page
// numbers and insert "..." gaps; the first and last entries are prev/next,
// which are rendered here as icon buttons instead.
export function Pagination({
    page,
    className,
}: {
    page: Paginated<unknown>;
    className?: string;
}) {
    if (page.last_page <= 1) {
        return null;
    }

    const numbers = page.links.slice(1, -1);

    return (
        <nav
            aria-label="Pagination"
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 pt-3 text-sm',
                className,
            )}
        >
            <p className="text-muted-foreground tabular-nums">
                Showing {n(page.from)}–{n(page.to)} of {n(page.total)}
            </p>
            <div className="flex items-center gap-1">
                <PageLink
                    url={page.prev_page_url}
                    label="Previous page"
                    icon={<ChevronLeft />}
                />
                {numbers.map((link, i) =>
                    link.url === null ? (
                        <span
                            key={`gap-${i}`}
                            aria-hidden
                            className="px-1 text-muted-foreground"
                        >
                            …
                        </span>
                    ) : (
                        <Button
                            key={link.label}
                            asChild
                            size="icon-sm"
                            variant={link.active ? 'default' : 'ghost'}
                            className="tabular-nums pointer-coarse:size-11"
                        >
                            <Link
                                href={link.url}
                                preserveState
                                aria-current={link.active ? 'page' : undefined}
                                aria-label={`Page ${link.label}`}
                            >
                                {link.label}
                            </Link>
                        </Button>
                    ),
                )}
                <PageLink
                    url={page.next_page_url}
                    label="Next page"
                    icon={<ChevronRight />}
                />
            </div>
        </nav>
    );
}

function PageLink({
    url,
    label,
    icon,
}: {
    url: string | null;
    label: string;
    icon: React.ReactNode;
}) {
    if (url === null) {
        return (
            <Button
                size="icon-sm"
                variant="ghost"
                disabled
                aria-label={label}
                className="pointer-coarse:size-11"
            >
                {icon}
            </Button>
        );
    }

    return (
        <Button
            asChild
            size="icon-sm"
            variant="ghost"
            className="pointer-coarse:size-11"
        >
            <Link href={url} preserveState aria-label={label}>
                {icon}
            </Link>
        </Button>
    );
}

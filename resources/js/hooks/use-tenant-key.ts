import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * A key for the current organization and property. When it changes (a switch
 * in this tab, or a visit after a switch in another tab), Inertia's prefetch
 * cache is flushed so no list from the previous context is served again.
 *
 * Use the key on whatever holds context-specific client state, so it is
 * remounted (and its lists and filters dropped) on a switch. The app layout
 * keys the page content with it. The server clears encrypted browser history
 * on a switch, so the back button cannot restore the previous context either.
 */
export function useTenantKey(): string {
    const { tenant } = usePage().props;
    const key = `${tenant.organization?.id ?? ''}:${tenant.property?.id ?? ''}`;
    const previous = useRef(key);

    useEffect(() => {
        if (previous.current !== key) {
            previous.current = key;
            router.flushAll();
        }
    }, [key]);

    return key;
}

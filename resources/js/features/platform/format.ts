const dateFormatters = new Map<string, Intl.DateTimeFormat>();

/** "7 Oct 2026" (design.md, Dates and times), optionally in a property timezone. */
export function formatDate(iso: string | null, timeZone?: string): string {
    if (iso === null) return '';
    if (timeZone) {
        return new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone,
        }).format(new Date(iso));
    }

    const key = '';
    let formatter = dateFormatters.get(key);
    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            ...(timeZone ? { timeZone } : {}),
        });
        dateFormatters.set(key, formatter);
    }

    return formatter.format(new Date(iso));
}

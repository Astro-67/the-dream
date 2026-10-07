const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/** "7 Oct 2026" (design.md, Dates and times). */
export function formatDate(iso: string | null): string {
    return iso === null ? '' : dateFormat.format(new Date(iso));
}

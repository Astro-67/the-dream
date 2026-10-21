import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

export function currencyDecimals(currency: string): number {
    return currency === 'TZS' ? 0 : 2;
}

/** Integer minor units shown with the currency code: TZS whole, USD and EUR to two decimals (design.md). */
export function formatMoney(minor: number, currency: string): string {
    const decimals = currencyDecimals(currency);
    return `${currency} ${new Intl.NumberFormat('en', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(minor / 10 ** decimals)}`;
}

/**
 * "7 Oct 2026": a Y-m-d date as written, without timezone shifting, or, given the
 * property's timezone, the date of an instant there.
 */
export function formatDate(date: string, timeZone?: string): string {
    const [y, m, d] = date.slice(0, 10).split('-').map(Number);

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: timeZone ?? 'UTC',
    }).format(timeZone ? new Date(date) : Date.UTC(y, m - 1, d));
}

/** "14:05" (24-hour) for an instant, in the property's timezone. */
export function formatTime(instant: string | Date, timeZone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone,
    }).format(new Date(instant));
}

/** A tax rule's or breakdown line's size: its percentage, or its flat amount per guest per night. */
export const ruleSize = (
    rule: {
        kind: string;
        rate: number | null;
        amount?: number | null;
        per_person_night?: number | null;
        currency?: string | null;
    },
    currency?: string,
) =>
    rule.kind === 'percentage'
        ? `${(rule.rate ?? 0) / 100}%`
        : `${formatMoney(rule.per_person_night ?? rule.amount ?? 0, rule.currency ?? currency ?? '')} per guest per night`;

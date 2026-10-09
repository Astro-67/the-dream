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

/** Integer minor units shown with the currency code: TZS whole, USD and EUR to two decimals (design.md). */
export function formatMoney(minor: number, currency: string): string {
    const decimals = currency === 'TZS' ? 0 : 2;

    return `${currency} ${new Intl.NumberFormat('en', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(minor / 10 ** decimals)}`;
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

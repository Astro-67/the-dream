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

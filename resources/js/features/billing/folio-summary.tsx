import { formatMoney } from '@/lib/utils';

export const paymentStatus = {
    unpaid: { label: 'Unpaid', tone: 'error' },
    partially_paid: { label: 'Partially paid', tone: 'warning' },
    paid: { label: 'Paid', tone: 'success' },
} as const;

/** Client copy of the status rule for the live preview; FolioSummary::totals() on the server owns it. */
export function statusFor(
    due: number,
    netPayments: number,
): keyof typeof paymentStatus {
    if (due <= 0) return 'paid';
    return netPayments > 0 ? 'partially_paid' : 'unpaid';
}

/** Due, or Credit once overpaid, never a negative Due. */
export function dueLine(due: number, currency: string) {
    return {
        label: due < 0 ? 'Credit' : 'Due',
        amount: formatMoney(Math.abs(due), currency),
    };
}

export type FolioSummary = {
    room: number;
    extras: number;
    vat: number;
    levy: number;
    other_taxes: number;
    unclassified_taxes: number;
    charges: number;
    paid: number;
    refunded: number;
    written_off: number;
    net_payments: number;
    balance: number;
    levy_unavailable: boolean;
};
export type FolioTotal = FolioSummary & {
    currency: string;
    estimate: { nights: number; amount: number } | null;
    /** balance plus the estimate; worked out on the server. */
    due: number;
    status: 'unpaid' | 'partially_paid' | 'paid';
};

/** The balance line: owed, credit, or settled only when nothing is left to charge. */
export function balanceLabel(
    balance: number,
    estimate: FolioTotal['estimate'],
): string {
    if (balance > 0) return 'Balance due';
    if (balance < 0) return 'Credit balance';
    return estimate ? 'Balance of posted charges' : 'Settled';
}

/** Whose tax the folio shows (ADR 0015); null where Karafuu's tax rules are the tax. */
export type TaxSource = 'estimated' | 'vfms' | null;

function taxLabel(name: string, source: TaxSource): string {
    if (source === 'estimated') return `Estimated tax: ${name}`;
    return source === 'vfms' ? `${name} (VFMS)` : name;
}

/** One currency's posted summary; amounts are never converted between currencies. */
export function FolioSummaryList({
    summary,
    currency,
    estimate = null,
    taxSource = null,
}: {
    summary: FolioSummary;
    currency: string;
    estimate?: FolioTotal['estimate'];
    taxSource?: TaxSource;
}) {
    const rows: [string, number | string, boolean?][] = [
        ['Room charges, net of tax', summary.room],
        ['Other charges, net of tax', summary.extras],
        [taxLabel('VAT', taxSource), summary.vat],
        [
            taxLabel('Infrastructure levy', taxSource),
            // A levy left out in this currency is never shown as a zero charge.
            summary.levy_unavailable && !summary.levy
                ? 'Not available in this currency'
                : summary.levy,
        ],
        ...(summary.other_taxes
            ? [
                  ['Other taxes and service charges', summary.other_taxes] as [
                      string,
                      number,
                  ],
              ]
            : []),
        ...(summary.unclassified_taxes
            ? [
                  ['Unclassified tax', summary.unclassified_taxes] as [
                      string,
                      number,
                  ],
              ]
            : []),
        ['Total charges', summary.charges, true],
        ['Payments received', summary.paid],
        ...(summary.refunded
            ? [['Money refunded', summary.refunded] as [string, number]]
            : []),
        ['Net payments', summary.net_payments],
        ...(summary.written_off
            ? [['Written off', summary.written_off] as [string, number]]
            : []),
        [
            balanceLabel(summary.balance, estimate),
            Math.abs(summary.balance),
            true,
        ],
    ];

    return (
        <div className="space-y-2">
            <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                {rows.map(([label, amount, strong]) => (
                    <div key={label} className="contents">
                        <dt
                            className={
                                strong
                                    ? 'font-semibold'
                                    : 'text-muted-foreground'
                            }
                        >
                            {label}
                        </dt>
                        <dd
                            className={`text-right tabular-nums ${strong ? 'font-semibold' : ''}`}
                        >
                            {typeof amount === 'number'
                                ? formatMoney(amount, currency)
                                : amount}
                        </dd>
                    </div>
                ))}
            </dl>
            {estimate && (
                <p className="text-sm text-muted-foreground">
                    Not yet charged: {estimate.nights}{' '}
                    {estimate.nights === 1 ? 'night' : 'nights'}, estimated{' '}
                    {formatMoney(estimate.amount, currency)} before added taxes.
                    Not included in the totals above.
                </p>
            )}
        </div>
    );
}

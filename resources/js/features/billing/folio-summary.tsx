import { formatMoney } from '@/lib/utils';

export const paymentStatus = {
    unpaid: { label: 'Unpaid', tone: 'error' },
    partially_paid: { label: 'Partially paid', tone: 'warning' },
    paid: { label: 'Paid', tone: 'success' },
} as const;

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

/** One currency's posted summary; amounts are never converted between currencies. */
export function FolioSummaryList({
    summary,
    currency,
    estimate = null,
}: {
    summary: FolioSummary;
    currency: string;
    estimate?: FolioTotal['estimate'];
}) {
    const rows: [string, number | string, boolean?][] = [
        ['Room charges, net of tax', summary.room],
        ['Other charges, net of tax', summary.extras],
        ['VAT', summary.vat],
        [
            'Infrastructure levy',
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
            <dl className="grid max-w-md grid-cols-[1fr_auto] gap-x-6 gap-y-1">
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

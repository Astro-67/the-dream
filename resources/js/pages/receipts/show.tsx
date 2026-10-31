import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { paymentMethods } from '@/features/billing/payment-form';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import {
    FolioSummaryList,
    type FolioSummary,
} from '@/features/billing/folio-summary';
import {
    index,
    show as showReservation,
    statement,
} from '@/routes/reservations';

type Receipt = {
    id: string;
    number: string;
    label: string;
    issued_at: string;
    amount: number;
    currency: string;
    deposit: boolean;
    method: string;
    reference: string | null;
    card_last_four: string | null;
    card_brand: string | null;
    cashier: string | null;
};
type Stay = {
    reservation_number: string;
    guest: string;
    room: string | null;
    room_type: string | null;
    arrival: string;
    departure: string;
};
const corrections: Record<string, string> = {
    reversal: 'Reversed',
    refund: 'Refunded',
};

export default function Show({
    receipt,
    reservation,
    booker,
    property,
    summary,
    stay,
    corrections: later,
}: {
    receipt: Receipt;
    summary:
        | (FolioSummary & {
              currency: string;
              estimate?: {
                  nights: number;
                  amount: number;
                  currency: string;
              } | null;
          })
        | null;
    stay: Stay;
    corrections: {
        id: string;
        type: string;
        amount: number;
        business_date: string;
    }[];
    reservation: { reservation_number: string; payment_collector: string };
    booker: { name: string };
    property: {
        name: string;
        address_line: string;
        city: string;
        phone: string;
        email: string;
    };
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';
    const [paper, setPaper] = useState('a4');
    const method =
        paymentMethods[receipt.method as keyof typeof paymentMethods] ??
        receipt.method;

    return (
        <>
            <Head title={`${receipt.label} ${receipt.number}`}>
                <style media="print">{`@page { size: ${paper === 'thermal' ? '80mm 200mm' : 'A4'}; margin: ${paper === 'thermal' ? '4mm' : '12mm'}; }`}</style>
            </Head>
            <div
                data-paper={paper}
                className="receipt-print flex max-w-[720px] flex-col gap-6 p-4 md:p-6"
            >
                <header className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {property.name}, {property.address_line},{' '}
                        {property.city}. {property.phone}, {property.email}
                    </p>
                    <h1 className="text-2xl font-semibold">
                        {receipt.label.toUpperCase()}
                    </h1>
                    <p>
                        <Identifier value={receipt.number} copyable />
                    </p>
                    <p className="text-sm text-muted-foreground">
                        This is not a fiscal receipt.
                    </p>
                </header>
                <dl className="grid gap-4 sm:grid-cols-2">
                    {(
                        [
                            [
                                'Issued',
                                `${formatDate(receipt.issued_at, timezone)} ${formatTime(receipt.issued_at, timezone)}`,
                            ],
                            ['Received from', booker.name],
                            ['Guest', stay.guest],
                            [
                                'Reservation',
                                <Link
                                    key="reservation"
                                    href={showReservation(
                                        reservation.reservation_number,
                                    )}
                                    className="underline underline-offset-4"
                                >
                                    <Identifier
                                        value={reservation.reservation_number}
                                    />
                                </Link>,
                            ],
                            [
                                receipt.deposit ? 'Deposit' : 'Payment',
                                <span key="amount" className="tabular-nums">
                                    {formatMoney(
                                        receipt.amount,
                                        receipt.currency,
                                    )}
                                </span>,
                            ],
                            ['Method', method],
                            ...(receipt.cashier
                                ? [['Processed by', receipt.cashier]]
                                : []),
                            [
                                'Room',
                                `${stay.room ?? 'Not assigned'}${stay.room_type ? `, ${stay.room_type}` : ''}`,
                            ],
                            [
                                'Stay',
                                `${formatDate(stay.arrival, 'UTC')} to ${formatDate(stay.departure, 'UTC')}`,
                            ],
                            ...(receipt.reference
                                ? [['Reference', receipt.reference]]
                                : []),
                            ...(receipt.card_last_four || receipt.card_brand
                                ? [
                                      [
                                          'Card',
                                          [
                                              receipt.card_brand,
                                              receipt.card_last_four &&
                                                  `ending ${receipt.card_last_four}`,
                                          ]
                                              .filter(Boolean)
                                              .join(' '),
                                      ],
                                  ]
                                : []),
                            ...(receipt.method === 'channel_collected'
                                ? [
                                      [
                                          'Payment collector',
                                          reservation.payment_collector,
                                      ],
                                  ]
                                : []),
                        ] as [string, React.ReactNode][]
                    ).map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
                <section className="space-y-2">
                    <h2 className="font-semibold">
                        Folio summary at payment time
                    </h2>
                    {summary ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                The folio this payment went to, right after it
                                was taken. Not a receipt of tax and not a
                                further payment.
                            </p>
                            <FolioSummaryList
                                summary={summary}
                                currency={summary.currency}
                            />
                            {summary.estimate && (
                                <>
                                    <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                                        <dt className="text-muted-foreground">
                                            Room charges (estimated,{' '}
                                            {summary.estimate.nights}{' '}
                                            {summary.estimate.nights === 1
                                                ? 'night'
                                                : 'nights'}
                                            )
                                        </dt>
                                        <dd className="text-right tabular-nums">
                                            {formatMoney(
                                                summary.estimate.amount,
                                                summary.estimate.currency,
                                            )}
                                        </dd>
                                    </dl>
                                    <p className="text-sm text-muted-foreground">
                                        Not yet charged, before added taxes. Not
                                        included in the posted totals above.
                                    </p>
                                </>
                            )}
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Historical folio summary unavailable.
                        </p>
                    )}
                </section>
                {later.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-semibold">Later corrections</h2>
                        {later.map((correction) => (
                            <p
                                key={`${correction.type}-${correction.business_date}`}
                                className="text-sm"
                            >
                                {corrections[correction.type] ??
                                    correction.type}{' '}
                                {formatDate(correction.business_date, 'UTC')}:{' '}
                                {formatMoney(
                                    correction.amount,
                                    receipt.currency,
                                )}
                            </p>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            This receipt is unchanged; see the folio statement
                            for the current balance.
                        </p>
                    </section>
                )}
                <Link
                    href={statement(stay.reservation_number)}
                    className="underline underline-offset-4 print:hidden"
                >
                    Folio statement
                </Link>
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <div className="space-y-2">
                        <Label htmlFor="receipt-paper">Paper size</Label>
                        <NativeSelect
                            id="receipt-paper"
                            value={paper}
                            onChange={(event) => setPaper(event.target.value)}
                        >
                            <option value="a4">A4</option>
                            <option value="thermal">80 mm thermal</option>
                        </NativeSelect>
                    </div>
                    <Button variant="outline" onClick={() => window.print()}>
                        Print receipt
                    </Button>
                </div>
            </div>
        </>
    );
}

Show.layout = ({ stay }: { stay: { reservation_number: string } }) => ({
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        {
            title: stay.reservation_number,
            href: showReservation(stay.reservation_number),
        },
        { title: 'Receipt', href: '#' },
    ],
});

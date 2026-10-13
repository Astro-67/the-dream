import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { paymentMethods } from '@/features/billing/payment-form';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import { show as showReservation } from '@/routes/reservations';

type Receipt = {
    id: string;
    number: string;
    label: string;
    vfms_receipt_number: string | null;
    issued_at: string;
    amount: number;
    currency: string;
    deposit: boolean;
    method: string;
    reference: string | null;
    card_last_four: string | null;
    card_brand: string | null;
};

export default function Show({
    receipt,
    reservation,
    booker,
    property,
}: {
    receipt: Receipt;
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
                            ...(receipt.vfms_receipt_number
                                ? [
                                      [
                                          'VFMS receipt number',
                                          receipt.vfms_receipt_number,
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

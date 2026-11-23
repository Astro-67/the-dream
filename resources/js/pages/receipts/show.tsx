import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, ExternalLink, Printer, ReceiptText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import { paymentMethods } from '@/features/billing/payment-form';
import {
    AdvanceReceipt,
    type AdvancePayment,
} from '@/features/billing/advance-receipt';
import { formatDate } from '@/features/platform/format';
import { cn, formatMoney, formatTime } from '@/lib/utils';
import {
    FolioSummaryList,
    type FolioSummary,
} from '@/features/billing/folio-summary';
import {
    index,
    show as showReservation,
    statement,
} from '@/routes/reservations';
import {
    index as accounts,
    show as showAccount,
} from '@/routes/customer-accounts';

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
type CustomerAccount = {
    id: string;
    number: string;
    customer_name: string;
    kind: string;
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
    customerAccount = null,
    corrections: later,
    advancePayment = null,
}: {
    receipt: Receipt;
    advancePayment?: AdvancePayment | null;
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
    stay: Stay | null;
    customerAccount?: CustomerAccount | null;
    corrections: {
        id: string;
        type: string;
        amount: number;
        business_date: string;
    }[];
    reservation: {
        reservation_number: string;
        payment_collector: string;
    } | null;
    booker: { name: string };
    property: {
        name: string;
        address_line: string;
        city: string;
        phone: string;
        email: string;
        logo_url: string | null;
    };
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';
    const [paper, setPaper] = useState('a4');
    const method =
        paymentMethods[receipt.method as keyof typeof paymentMethods] ??
        receipt.method;
    const details: [string, React.ReactNode][] = [
        [
            'Issued',
            `${formatDate(receipt.issued_at, timezone)} ${formatTime(receipt.issued_at, timezone)}`,
        ],
        ['Received from', booker.name],
        ...(stay ? [['Guest', stay.guest] as [string, string]] : []),
        ...(reservation
            ? [
                  [
                      'Reservation',
                      <Link
                          key="reservation"
                          href={showReservation(reservation.reservation_number)}
                          className="font-mono text-primary underline underline-offset-4 print:text-black"
                      >
                          {reservation.reservation_number}
                      </Link>,
                  ] as [string, React.ReactNode],
              ]
            : []),
        ...(customerAccount
            ? [
                  [
                      'Customer account',
                      <Link
                          key="account"
                          href={showAccount(customerAccount.id)}
                          className="font-mono text-primary underline underline-offset-4 print:text-black"
                      >
                          {customerAccount.number}
                      </Link>,
                  ] as [string, React.ReactNode],
              ]
            : []),
        ['Method', method],
        ...(receipt.cashier
            ? [['Processed by', receipt.cashier] as [string, string]]
            : []),
        ...(stay
            ? [
                  [
                      'Room',
                      `${stay.room ?? 'Not assigned'}${stay.room_type ? ` · ${stay.room_type}` : ''}`,
                  ] as [string, string],
                  [
                      'Stay',
                      `${formatDate(stay.arrival, 'UTC')} – ${formatDate(stay.departure, 'UTC')}`,
                  ] as [string, string],
              ]
            : []),
        ...(receipt.reference
            ? [['Reference', receipt.reference] as [string, string]]
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
                  ] as [string, string],
              ]
            : []),
        ...(receipt.method === 'channel_collected'
            ? [
                  [
                      'Payment collector',
                      reservation?.payment_collector ?? 'Not recorded',
                  ] as [string, string],
              ]
            : []),
    ];

    return (
        <>
            <Head title={`${receipt.label} ${receipt.number}`}>
                <style media="print">{`@page { size: ${paper === 'thermal' ? '80mm 200mm' : 'A4'}; margin: ${paper === 'thermal' ? '4mm' : '12mm'}; }`}</style>
            </Head>
            <div className="mx-auto flex w-full max-w-[840px] min-w-0 flex-col gap-4">
                <div
                    data-paper={paper}
                    className={cn(
                        'receipt-print overflow-wrap-anywhere flex w-full min-w-0 flex-col rounded-sm border border-border bg-card shadow-panel',
                        paper === 'thermal' ? 'max-w-[344px]' : 'max-w-[800px]',
                    )}
                >
                    <header className="border-b border-border bg-muted/20 px-5 py-5 text-center sm:px-8 sm:py-7 print:bg-white print:px-0 print:py-0 print:pb-4">
                        {property.logo_url ? (
                            <img
                                src={property.logo_url}
                                alt={`${property.name} logo`}
                                className="mx-auto mb-3 max-h-16 max-w-[12rem] object-contain print:max-h-14"
                            />
                        ) : (
                            <span className="mx-auto mb-3 grid size-11 place-items-center border border-primary/20 bg-primary-tint text-primary print:hidden">
                                <Building2
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </span>
                        )}
                        <p className="text-base font-semibold">
                            {property.name}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {property.address_line}, {property.city}
                        </p>
                        <p className="mt-1 flex flex-wrap justify-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            {property.phone && <span>{property.phone}</span>}
                            {property.email && <span>{property.email}</span>}
                        </p>
                        <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
                            <h1 className="font-heading text-xl font-semibold tracking-tight">
                                {receipt.label}
                            </h1>
                            <StatusChip tone="warning">Non-fiscal</StatusChip>
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Receipt number{' '}
                            <Identifier value={receipt.number} copyable />
                        </p>
                        <p className="mt-2 text-xs font-medium text-status-warning">
                            This is not a fiscal receipt.
                        </p>
                    </header>

                    <section className="border-b border-border bg-primary px-5 py-5 text-center text-primary-foreground sm:py-6 print:border-b-2 print:border-black print:bg-white print:px-0 print:py-4 print:text-black">
                        <p className="text-xs font-semibold tracking-[0.12em] uppercase opacity-85">
                            {receipt.deposit
                                ? 'Deposit received'
                                : 'Payment received'}
                        </p>
                        <p className="mt-1 font-heading text-3xl leading-tight font-semibold tabular-nums sm:text-4xl">
                            {formatMoney(receipt.amount, receipt.currency)}
                        </p>
                        <p className="mt-2 text-sm">
                            {method} <span aria-hidden="true">·</span>{' '}
                            {formatDate(receipt.issued_at, timezone)}
                        </p>
                    </section>

                    <section className="space-y-4 px-5 py-5 sm:px-8 print:px-0 print:py-4">
                        <div>
                            <h2 className="font-semibold">Payment details</h2>
                            <p className="text-sm text-muted-foreground">
                                Recorded when this payment was received.
                            </p>
                        </div>
                        <dl className="grid min-w-0 gap-x-5 sm:grid-cols-2 print:grid-cols-2">
                            {details.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="min-w-0 border-t border-border py-2.5 print:border-black print:py-2"
                                >
                                    <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {label}
                                    </dt>
                                    <dd className="mt-1 min-w-0 text-sm font-medium [overflow-wrap:anywhere]">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section className="space-y-4 border-t border-border bg-muted/10 px-5 py-5 sm:px-8 print:border-black print:bg-white print:px-0 print:py-4">
                        <div className="flex items-start gap-3">
                            <span className="grid size-9 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary print:hidden">
                                <ReceiptText
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                            <div>
                                <h2 className="font-semibold">
                                    Folio summary at payment time
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Snapshot of the folio immediately after the
                                    payment was taken.
                                </p>
                            </div>
                        </div>
                        {summary ? (
                            <>
                                <div className="border border-border bg-card p-3 sm:p-4 print:border-0 print:p-0">
                                    <FolioSummaryList
                                        summary={summary}
                                        currency={summary.currency}
                                    />
                                </div>
                                {summary.estimate && (
                                    <div className="border border-status-info/20 bg-status-info-tint p-3 print:border-black print:bg-white print:p-2">
                                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                                            <dt className="text-muted-foreground">
                                                Room charges estimated for{' '}
                                                {summary.estimate.nights}{' '}
                                                {summary.estimate.nights === 1
                                                    ? 'night'
                                                    : 'nights'}
                                            </dt>
                                            <dd className="text-right font-semibold tabular-nums">
                                                {formatMoney(
                                                    summary.estimate.amount,
                                                    summary.estimate.currency,
                                                )}
                                            </dd>
                                        </dl>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Estimate recorded at payment time;
                                            not included in posted totals.
                                        </p>
                                    </div>
                                )}
                            </>
                        ) : (
                            <p className="border border-border bg-card p-3 text-sm text-muted-foreground">
                                Historical folio summary unavailable.
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            The folio snapshot is informational. This receipt is
                            not a receipt of tax or a further payment.
                        </p>
                    </section>

                    {later.length > 0 && (
                        <section className="space-y-3 border-t border-status-warning bg-status-warning-tint px-5 py-4 sm:px-8 print:border-black print:bg-white print:px-0">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-semibold">
                                    Later corrections
                                </h2>
                                <StatusChip tone="warning">
                                    Receipt unchanged
                                </StatusChip>
                            </div>
                            <div className="grid gap-2">
                                {later.map((correction) => (
                                    <div
                                        key={`${correction.type}-${correction.business_date}`}
                                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-status-warning/20 pt-2 text-sm print:border-black"
                                    >
                                        <span>
                                            {corrections[correction.type] ??
                                                correction.type}{' '}
                                            {formatDate(
                                                correction.business_date,
                                                'UTC',
                                            )}
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {formatMoney(
                                                correction.amount,
                                                receipt.currency,
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                See the folio statement for the current balance.
                            </p>
                        </section>
                    )}
                </div>

                {advancePayment && <AdvanceReceipt advance={advancePayment} />}

                <section className="flex flex-col gap-3 border border-border bg-card p-3 shadow-panel sm:flex-row sm:items-end sm:justify-between print:hidden">
                    <div className="grid gap-2">
                        <Label htmlFor="receipt-paper">Print layout</Label>
                        <NativeSelect
                            id="receipt-paper"
                            value={paper}
                            onChange={(event) => setPaper(event.target.value)}
                            className="w-full sm:w-48"
                        >
                            <option value="a4">A4 receipt</option>
                            <option value="thermal">80 mm thermal</option>
                        </NativeSelect>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(stay || customerAccount) && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        customerAccount
                                            ? showAccount(customerAccount.id)
                                            : statement(
                                                  stay!.reservation_number,
                                              )
                                    }
                                >
                                    <ExternalLink aria-hidden="true" />
                                    {customerAccount
                                        ? 'Customer account'
                                        : 'Folio statement'}
                                </Link>
                            </Button>
                        )}
                        <Button onClick={() => window.print()}>
                            <Printer aria-hidden="true" />
                            Print receipt
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}

Show.layout = ({
    stay,
    customerAccount,
}: {
    stay: Stay | null;
    customerAccount?: CustomerAccount | null;
}) => ({
    breadcrumbs: customerAccount
        ? [
              { title: 'Day use & events', href: accounts() },
              {
                  title: customerAccount.number,
                  href: showAccount(customerAccount.id),
              },
              { title: 'Receipt', href: '#' },
          ]
        : stay
          ? [
                { title: 'Reservations', href: index() },
                {
                    title: stay.reservation_number,
                    href: showReservation(stay.reservation_number),
                },
                { title: 'Receipt', href: '#' },
            ]
          : [{ title: 'Receipts', href: '#' }],
});

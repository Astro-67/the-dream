import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { postingCategories } from '@/features/billing/folio-panel';
import { paymentMethods } from '@/features/billing/payment-form';
import { EmptyState } from '@/components/ui/empty-state';
import {
    FolioSummaryList,
    type FolioSummary,
    type FolioTotal,
} from '@/features/billing/folio-summary';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { show as showReservation } from '@/routes/reservations';

type StatementPosting = {
    id: string;
    type: string;
    category: string;
    description: string | null;
    quantity: number;
    unit_price: number;
    amount: number;
    method: string | null;
    business_date: string;
    tax_lines: {
        name: string;
        inclusive: boolean;
        amount: number;
        unavailable: boolean;
    }[];
};

const corrections: Record<string, string> = {
    reversal: 'Reversed',
    transfer_out: 'Moved to another folio',
    transfer_in: 'Moved from another folio',
};

function itemLabel(posting: StatementPosting): string {
    const item =
        posting.description ||
        (postingCategories[posting.category] ?? posting.category);
    const method = posting.method
        ? `, ${paymentMethods[posting.method as keyof typeof paymentMethods] ?? posting.method}`
        : '';
    const correction = corrections[posting.type];

    return `${correction ? `${correction}: ` : ''}${item}${method}`;
}

export default function Statement({
    reservation,
    folios,
    totals,
    property,
}: {
    reservation: {
        reservation_number: string;
        guest: string;
        room: string | null;
        room_type: string;
        arrival: string;
        departure: string;
        nights: number;
        adults: number;
        children: number;
    };
    folios: {
        number: number;
        currency: string;
        summary: FolioSummary;
        postings: StatementPosting[];
    }[];
    totals: FolioTotal[];
    property: {
        name: string;
        address_line: string;
        city: string;
        phone: string;
        email: string;
    };
}) {
    const [paper, setPaper] = useState('a4');

    return (
        <>
            <Head title={`Folio statement ${reservation.reservation_number}`}>
                <style media="print">{`@page { size: ${paper === 'thermal' ? '80mm auto' : 'A4'}; margin: ${paper === 'thermal' ? '4mm' : '12mm'}; }`}</style>
            </Head>
            <div
                data-paper={paper}
                className="receipt-print flex max-w-[960px] flex-col gap-6 p-4 md:p-6"
            >
                <header className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {property.name}, {property.address_line},{' '}
                        {property.city}. {property.phone}, {property.email}
                    </p>
                    <h1 className="text-2xl font-semibold">FOLIO STATEMENT</h1>
                    <p className="text-sm text-muted-foreground">
                        Settlement statement. This is not a fiscal receipt.
                    </p>
                </header>
                <dl className="grid gap-4 sm:grid-cols-3">
                    {(
                        [
                            ['Guest', reservation.guest],
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
                                'Room',
                                `${reservation.room ?? 'Not assigned'}, ${reservation.room_type}`,
                            ],
                            ['Arrival', formatDate(reservation.arrival, 'UTC')],
                            [
                                'Departure',
                                formatDate(reservation.departure, 'UTC'),
                            ],
                            [
                                'Nights',
                                `${reservation.nights}, ${reservation.adults} adults, ${reservation.children} children`,
                            ],
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
                {folios.map((folio) => (
                    <section key={folio.number} className="space-y-3">
                        <h2 className="text-lg font-semibold">
                            Folio {folio.number} ({folio.currency})
                        </h2>
                        {/* A printed statement lists every posting, so it is not paginated like the folio panel. */}
                        {folio.postings.length === 0 ? (
                            <EmptyState message="Nothing posted on this folio." />
                        ) : (
                            <Table
                                aria-label={`Folio ${folio.number} postings`}
                            >
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Item</TableHead>
                                        <TableHead numeric>Quantity</TableHead>
                                        <TableHead numeric>Tax</TableHead>
                                        <TableHead numeric>Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {folio.postings.map((posting) => (
                                        <TableRow key={posting.id}>
                                            <TableCell>
                                                {formatDate(
                                                    posting.business_date,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {itemLabel(posting)}
                                            </TableCell>
                                            <TableCell numeric>
                                                {posting.quantity}
                                            </TableCell>
                                            <TableCell numeric>
                                                {posting.tax_lines.map(
                                                    (line) => (
                                                        <div key={line.name}>
                                                            {line.name}
                                                            {line.inclusive
                                                                ? ', of which'
                                                                : ''}{' '}
                                                            {line.unavailable
                                                                ? 'not available in this currency'
                                                                : formatMoney(
                                                                      line.amount,
                                                                      folio.currency,
                                                                  )}
                                                        </div>
                                                    ),
                                                )}
                                            </TableCell>
                                            <TableCell numeric>
                                                {formatMoney(
                                                    posting.amount,
                                                    folio.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <FolioSummaryList
                            summary={folio.summary}
                            currency={folio.currency}
                            estimate={
                                totals.find(
                                    (total) =>
                                        total.currency === folio.currency,
                                )?.estimate
                            }
                        />
                    </section>
                ))}
                {totals.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">
                            Totals by currency
                        </h2>
                        {totals.map((total) => (
                            <div key={total.currency} className="space-y-2">
                                <h3 className="font-semibold">
                                    {total.currency}
                                </h3>
                                <FolioSummaryList
                                    summary={total}
                                    currency={total.currency}
                                    estimate={total.estimate}
                                />
                            </div>
                        ))}
                    </section>
                )}
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <div className="space-y-2">
                        <Label htmlFor="statement-paper">Paper size</Label>
                        <NativeSelect
                            id="statement-paper"
                            value={paper}
                            onChange={(event) => setPaper(event.target.value)}
                        >
                            <option value="a4">A4</option>
                            <option value="thermal">80 mm thermal</option>
                        </NativeSelect>
                    </div>
                    <Button variant="outline" onClick={() => window.print()}>
                        Print statement
                    </Button>
                </div>
            </div>
        </>
    );
}

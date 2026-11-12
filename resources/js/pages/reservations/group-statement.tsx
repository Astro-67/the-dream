import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import {
    FolioSummaryList,
    type FolioSummary,
    type FolioTotal,
} from '@/features/billing/folio-summary';
import {
    GeneralReceiptDialog,
    type GeneralReceiptPreview,
} from '@/features/fiscal-receipting/general-receipt-dialog';
import { formatDate } from '@/features/platform/format';
import { StatementFolios, type StatementProps } from './statement';
import { index, show } from '@/routes/reservations';

type GroupStatementProps = {
    reservation: StatementProps['reservation'];
    property: StatementProps['property'];
    payingInstitution: { name: string; zrbNumber: string } | null;
    group: {
        arrival: string;
        departure: string;
        rooms: number;
        guests: number;
    };
    rooms: (StatementProps & { reports: string[] })[];
    totals: (FolioSummary & {
        currency: string;
        estimate: FolioTotal['estimate'];
    })[];
    generalReceipt: GeneralReceiptPreview | null;
};

export default function GroupStatement({
    property,
    payingInstitution,
    group,
    rooms,
    totals,
    generalReceipt,
}: GroupStatementProps) {
    return (
        <>
            <Head title="Group statement">
                <style media="print">
                    {'@page { size: A4; margin: 12mm; }'}
                </style>
            </Head>
            <div
                data-paper="a4"
                className="receipt-print flex max-w-[960px] flex-col gap-6 p-4 md:p-6"
            >
                <header className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {property.name}, {property.address_line},{' '}
                        {property.city}. {property.phone}, {property.email}
                    </p>
                    <h1 className="text-2xl font-semibold">GROUP STATEMENT</h1>
                    <p className="text-sm text-muted-foreground">
                        Non-fiscal settlement statement. This is not a fiscal
                        receipt.
                    </p>
                    {payingInstitution && (
                        <dl className="space-y-2">
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    Paying institution
                                </dt>
                                <dd>{payingInstitution.name}</dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    ZRB number
                                </dt>
                                <dd>
                                    <Identifier
                                        value={payingInstitution.zrbNumber}
                                    />
                                </dd>
                            </div>
                        </dl>
                    )}
                </header>
                <dl className="grid gap-4 sm:grid-cols-4">
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Arrival
                        </dt>
                        <dd>{formatDate(group.arrival, 'UTC')}</dd>
                    </div>
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Departure
                        </dt>
                        <dd>{formatDate(group.departure, 'UTC')}</dd>
                    </div>
                    <div>
                        <dt className="text-sm text-muted-foreground">Rooms</dt>
                        <dd>{group.rooms}</dd>
                    </div>
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Guests
                        </dt>
                        <dd>{group.guests}</dd>
                    </div>
                </dl>
                {rooms.map((room) => (
                    <section
                        key={room.reservation.reservation_number}
                        className="space-y-4"
                    >
                        <h2 className="text-xl font-semibold">
                            Room {room.reservation.room ?? 'Not assigned'}{' '}
                            &mdash;{' '}
                            <Link
                                href={show(room.reservation.reservation_number)}
                                className="underline underline-offset-4"
                            >
                                <Identifier
                                    value={room.reservation.reservation_number}
                                />
                            </Link>
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {room.reservation.room_type},{' '}
                            {formatDate(room.reservation.arrival, 'UTC')}{' '}
                            &ndash;{' '}
                            {formatDate(room.reservation.departure, 'UTC')}
                        </p>
                        <StatementFolios
                            heading="h3"
                            folios={room.folios}
                            totals={room.totals}
                            taxSource={room.taxSource}
                        />
                    </section>
                ))}
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">
                        Totals by currency
                    </h2>
                    {totals.map((total) => (
                        <div key={total.currency} className="space-y-2">
                            <h3 className="font-semibold">{total.currency}</h3>
                            <FolioSummaryList
                                summary={total}
                                currency={total.currency}
                                estimate={total.estimate}
                            />
                        </div>
                    ))}
                </section>
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        ZRA fiscal reports
                    </h2>
                    {rooms.map((room) => (
                        <div key={room.reservation.reservation_number}>
                            <Identifier
                                value={room.reservation.reservation_number}
                            />
                            :{' '}
                            {room.reports.length === 0
                                ? 'Not yet filed'
                                : room.reports.map((number) => (
                                      <span key={number} className="mr-3">
                                          <Identifier value={number} />
                                      </span>
                                  ))}
                        </div>
                    ))}
                </section>
                <div className="flex flex-wrap gap-3 print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        Print group statement
                    </Button>
                    <GeneralReceiptDialog preview={generalReceipt} />
                </div>
            </div>
        </>
    );
}

GroupStatement.layout = ({ reservation }: GroupStatementProps) => ({
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        {
            title: reservation.reservation_number,
            href: show(reservation.reservation_number),
        },
        { title: 'Group statement', href: '#' },
    ],
});

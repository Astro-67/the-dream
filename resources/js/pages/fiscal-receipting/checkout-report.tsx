import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { show as showReservation } from '@/routes/reservations';

type Report = {
    number: string;
    currency: string;
    room: number;
    extras: number;
    vat: number;
    levy: number;
    total: number;
};

export default function CheckoutReport({
    status,
    report,
    portalUrl,
    qrSvg,
    stay,
    guests,
    property,
}: {
    status: 'waiting' | 'sandbox' | 'live';
    report: Report | null;
    portalUrl: string | null;
    qrSvg: string | null;
    stay: {
        reservation_number: string;
        room: string | null;
        room_type: string;
        arrival: string;
        departure: string;
        nights: number;
    };
    guests: string[];
    property: {
        name: string;
        address_line: string;
        city: string;
        phone: string;
        email: string;
    };
}) {
    return (
        <>
            <Head title={`Fiscal check-out report ${stay.reservation_number}`}>
                <style media="print">
                    {'@page { size: A4; margin: 12mm; }'}
                </style>
            </Head>
            <div className="receipt-print flex max-w-[960px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {property.name}, {property.address_line},{' '}
                        {property.city}. {property.phone}, {property.email}
                    </p>
                    <h1 className="text-2xl font-semibold">
                        FISCAL CHECK-OUT REPORT
                    </h1>
                    {status === 'sandbox' && (
                        <StatusChip tone="warning">
                            SANDBOX, NOT FISCAL
                        </StatusChip>
                    )}
                </header>
                <dl className="grid gap-4 sm:grid-cols-3">
                    {(
                        [
                            ['Guests', guests.join(', ')],
                            [
                                'Reservation',
                                <Link
                                    key="reservation"
                                    href={showReservation(
                                        stay.reservation_number,
                                    )}
                                    className="underline underline-offset-4"
                                >
                                    <Identifier
                                        value={stay.reservation_number}
                                    />
                                </Link>,
                            ],
                            [
                                'Room',
                                `${stay.room ?? 'Not assigned'}, ${stay.room_type}`,
                            ],
                            ['Arrival', formatDate(stay.arrival, 'UTC')],
                            ['Departure', formatDate(stay.departure, 'UTC')],
                            ['Nights', String(stay.nights)],
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
                {report === null ? (
                    <EmptyState message="Waiting for VFMS. The report appears once VFMS has accepted every filing of this stay." />
                ) : (
                    <section className="flex flex-wrap items-start gap-8">
                        <dl className="grid flex-1 grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                            {(
                                [
                                    [
                                        'Report number',
                                        <Identifier
                                            key="number"
                                            value={report.number}
                                            copyable
                                        />,
                                    ],
                                    [
                                        'Room charges',
                                        formatMoney(
                                            report.room,
                                            report.currency,
                                        ),
                                    ],
                                    [
                                        'Extras',
                                        formatMoney(
                                            report.extras,
                                            report.currency,
                                        ),
                                    ],
                                    [
                                        'VAT (VFMS)',
                                        formatMoney(
                                            report.vat,
                                            report.currency,
                                        ),
                                    ],
                                    [
                                        'Infrastructure levy (VFMS)',
                                        formatMoney(
                                            report.levy,
                                            report.currency,
                                        ),
                                    ],
                                    [
                                        'Total',
                                        formatMoney(
                                            report.total,
                                            report.currency,
                                        ),
                                    ],
                                ] as [string, React.ReactNode][]
                            ).map(([label, value]) => (
                                <div key={label} className="contents">
                                    <dt
                                        className={
                                            label === 'Total'
                                                ? 'font-semibold'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {label}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {qrSvg && portalUrl && (
                            <figure className="space-y-2">
                                <div
                                    role="img"
                                    aria-label={`VFMS QR code for ${portalUrl}`}
                                    className="size-40 bg-white [&_svg]:size-full"
                                    dangerouslySetInnerHTML={{ __html: qrSvg }}
                                />
                                <figcaption className="text-sm text-muted-foreground">
                                    Scan to verify with VFMS
                                </figcaption>
                            </figure>
                        )}
                    </section>
                )}
                <div className="print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        Print report
                    </Button>
                </div>
            </div>
        </>
    );
}

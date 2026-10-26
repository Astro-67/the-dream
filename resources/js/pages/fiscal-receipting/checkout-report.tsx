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
    guestReports,
    business,
    needsAttention,
    serviceReceipts,
}: {
    status: 'waiting' | 'sandbox' | 'live';
    report: Report | null;
    portalUrl: string | null;
    qrSvg: string | null;
    needsAttention: boolean;
    serviceReceipts: {
        number: string;
        description: string | null;
        amount: number;
    }[];
    guestReports: {
        number: string;
        name: string | null;
        identity: {
            documentName?: string;
            documentNo?: string;
            country?: string;
        } | null;
        departedEarly: boolean;
        portalUrl: string | null;
        qrSvg: string | null;
    }[];
    business: {
        businessName?: string;
        street?: string;
        tinNumber?: string;
        vrnNumber?: string;
        znumber?: string;
    } | null;
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
                    <img
                        src="/images/zra-logo.jpg"
                        alt="Zanzibar Revenue Authority"
                        width={80}
                        height={96}
                        className="h-24 w-20 object-contain"
                    />
                    {business && (
                        <div>
                            <p className="text-lg font-semibold">
                                {business.businessName}
                            </p>
                            <p>{business.street}</p>
                            <p className="text-sm text-muted-foreground">
                                TIN {business.tinNumber} / VRN{' '}
                                {business.vrnNumber} / Z-number{' '}
                                {business.znumber}
                            </p>
                        </div>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {property.name}, {property.address_line},{' '}
                        {property.city}. {property.phone}, {property.email}
                    </p>
                    <h1 className="text-2xl font-semibold">
                        FISCAL CHECK-OUT REPORT
                    </h1>
                    {status === 'sandbox' && (
                        <StatusChip tone="warning">TEST, NOT FISCAL</StatusChip>
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
                    <EmptyState
                        message={
                            needsAttention
                                ? 'Needs attention: ZRA returned no verifiable check-out report. Review the filing before retrying.'
                                : 'Waiting for VFMS. The report appears once VFMS has accepted every filing of this stay.'
                        }
                    />
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
                                        'Accommodation (tax inclusive)',
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
                                        'Of which VAT (VFMS)',
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
                        {guestReports.length === 0 && qrSvg && portalUrl && (
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
                {report && serviceReceipts.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">
                            Extra service receipts
                        </h2>
                        {serviceReceipts.map((receipt, index) => (
                            <div
                                key={`${receipt.number}-${index}`}
                                className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm"
                            >
                                <span>
                                    {receipt.description ?? 'Extra service'}
                                </span>
                                <Identifier value={receipt.number} />
                                <span className="tabular-nums">
                                    {formatMoney(
                                        receipt.amount,
                                        report.currency,
                                    )}
                                </span>
                            </div>
                        ))}
                    </section>
                )}
                {report && guestReports.length > 0 && (
                    <section className="grid gap-4 sm:grid-cols-2 print:grid-cols-2">
                        {guestReports.map((guest) => (
                            <article
                                key={guest.number}
                                className="flex break-inside-avoid items-start justify-between gap-4 border-t pt-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <h2 className="font-semibold">
                                        {guest.name ?? 'Guest report'}
                                    </h2>
                                    {guest.departedEarly && (
                                        <p className="text-sm text-muted-foreground">
                                            Departed before stay check-out
                                        </p>
                                    )}
                                    {guest.identity && (
                                        <p className="text-sm break-words">
                                            {guest.identity.documentName}{' '}
                                            <Identifier
                                                value={
                                                    guest.identity.documentNo ??
                                                    ''
                                                }
                                            />{' '}
                                            {guest.identity.country}
                                        </p>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        ZRA report number
                                    </p>
                                    <Identifier value={guest.number} copyable />
                                    {guest.portalUrl && (
                                        <a
                                            href={guest.portalUrl}
                                            className="block text-sm underline underline-offset-4"
                                        >
                                            Verify with ZRA
                                        </a>
                                    )}
                                </div>
                                {guest.qrSvg && guest.portalUrl && (
                                    <div
                                        role="img"
                                        aria-label={`VFMS QR code for ${guest.portalUrl}`}
                                        className="size-28 shrink-0 [&_svg]:size-full"
                                        dangerouslySetInnerHTML={{
                                            __html: guest.qrSvg,
                                        }}
                                    />
                                )}
                            </article>
                        ))}
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

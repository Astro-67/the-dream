import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { PropertyIntent } from '@/components/shared/property-intent';
import { formatMoney } from '@/lib/utils';
import { index, show as showReservation } from '@/routes/reservations';

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
    revealIdentityUrl,
    identityRevealed = false,
    branding,
}: {
    status: 'waiting' | 'sandbox' | 'live';
    report: Report | null;
    portalUrl: string | null;
    qrSvg: string | null;
    needsAttention: boolean;
    revealIdentityUrl: string | null;
    identityRevealed?: boolean;
    serviceReceipts: {
        number: string;
        description: string | null;
        amount: number;
        mode: 'sandbox' | 'live' | null;
        portalUrl: string | null;
        qrSvg: string | null;
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
    branding: { logo_url: string | null; accent_colour: string | null };
}) {
    return (
        <>
            <Head title={`Fiscal check-out report ${stay.reservation_number}`}>
                <style media="print">
                    {'@page { size: A4; margin: 12mm; }'}
                </style>
            </Head>
            <div className="receipt-print mx-auto flex w-full max-w-[960px] min-w-0 flex-col gap-8 p-4 text-sm [overflow-wrap:anywhere] md:p-6 print:gap-4 print:p-0">
                {/* The accent is decoration only, and never printed: paper stays black on white. */}
                <header
                    className="space-y-3 border-t-4 border-transparent pt-4 text-center print:break-inside-avoid print:border-t-0 print:pt-0"
                    style={
                        branding.accent_colour
                            ? { borderTopColor: branding.accent_colour }
                            : undefined
                    }
                >
                    {branding.logo_url ? (
                        <img
                            src={branding.logo_url}
                            alt={property.name}
                            className="mx-auto h-auto max-h-24 w-auto max-w-full object-contain sm:max-w-60"
                        />
                    ) : (
                        <p className="text-xl font-semibold">{property.name}</p>
                    )}
                    {business && (
                        <div className="space-y-1">
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
                    <h1 className="pt-2 text-2xl font-semibold text-balance">
                        FISCAL CHECK-OUT REPORT
                    </h1>
                    {status === 'sandbox' && (
                        <StatusChip tone="warning">TEST, NOT FISCAL</StatusChip>
                    )}
                </header>
                <dl
                    aria-label="Stay details"
                    className="grid grid-cols-2 gap-4 sm:grid-cols-3 print:grid-cols-3"
                >
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
                        <div key={label} className="min-w-0 space-y-1">
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
                    <section
                        aria-label="Financial summary"
                        className="flex min-w-0 flex-wrap items-start gap-6"
                    >
                        <dl className="min-w-0 basis-full space-y-2">
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
                                <div
                                    key={label}
                                    className="grid break-inside-avoid grid-cols-2 gap-x-4 last:mt-3 last:border-t last:pt-3"
                                >
                                    <dt
                                        className={
                                            label === 'Total'
                                                ? 'font-semibold'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {label === 'Total' ? (
                                            <strong className="font-semibold">
                                                {label}
                                            </strong>
                                        ) : (
                                            label
                                        )}
                                    </dt>
                                    <dd className="min-w-0 text-right tabular-nums">
                                        {label === 'Total' ? (
                                            <strong className="font-semibold">
                                                {value}
                                            </strong>
                                        ) : (
                                            value
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {guestReports.length === 0 && qrSvg && portalUrl && (
                            <figure className="min-w-0 break-inside-avoid space-y-2">
                                <div
                                    role="img"
                                    aria-label={`VFMS QR code for ${portalUrl}`}
                                    className="size-40 bg-white p-2 [&_svg]:size-full"
                                    dangerouslySetInnerHTML={{ __html: qrSvg }}
                                />
                                <figcaption className="space-y-1 text-sm">
                                    <a
                                        href={portalUrl}
                                        className="block underline underline-offset-4"
                                    >
                                        Scan to verify with ZRA
                                    </a>
                                    <p className="text-xs text-muted-foreground">
                                        ZRA report{' '}
                                        <Identifier value={report.number} />
                                    </p>
                                </figcaption>
                            </figure>
                        )}
                    </section>
                )}
                {report && serviceReceipts.length > 0 && (
                    <section className="grid gap-4 sm:grid-cols-2 print:grid-cols-2">
                        <h2 className="font-semibold sm:col-span-2 print:col-span-2">
                            Extra service receipts
                        </h2>
                        {serviceReceipts.map((receipt, index) => (
                            <div
                                key={`${receipt.number}-${index}`}
                                className="min-w-0 break-inside-avoid space-y-3 border-t pt-4"
                            >
                                <div className="min-w-0 space-y-1 break-words">
                                    <p>
                                        {receipt.description ?? 'Extra service'}
                                    </p>
                                    {receipt.mode === 'sandbox' && (
                                        <StatusChip tone="warning">
                                            TEST, NOT FISCAL
                                        </StatusChip>
                                    )}
                                    <p className="tabular-nums">
                                        {formatMoney(
                                            receipt.amount,
                                            report.currency,
                                        )}
                                    </p>
                                </div>
                                <figure className="min-w-0 break-inside-avoid space-y-2">
                                    {receipt.qrSvg && receipt.portalUrl && (
                                        <div
                                            role="img"
                                            aria-label={`VFMS QR code for ${receipt.portalUrl}`}
                                            className="size-28 shrink-0 bg-white p-2 [&_svg]:size-full"
                                            dangerouslySetInnerHTML={{
                                                __html: receipt.qrSvg,
                                            }}
                                        />
                                    )}
                                    <figcaption className="space-y-1 break-words">
                                        {receipt.portalUrl && (
                                            <a
                                                href={receipt.portalUrl}
                                                className="block underline underline-offset-4"
                                            >
                                                Scan to verify with ZRA
                                            </a>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            ZRA receipt{' '}
                                            <Identifier
                                                value={receipt.number}
                                            />
                                        </p>
                                    </figcaption>
                                </figure>
                            </div>
                        ))}
                    </section>
                )}
                {report && guestReports.length > 0 && (
                    <section className="grid gap-4 sm:grid-cols-2 print:grid-cols-2">
                        {guestReports.map((guest) => (
                            <article
                                key={guest.number}
                                className="min-w-0 break-inside-avoid space-y-2 border-t pt-4"
                            >
                                {guest.departedEarly && (
                                    <p className="text-sm text-muted-foreground">
                                        Departed before stay check-out
                                    </p>
                                )}
                                <figure className="break-inside-avoid space-y-2">
                                    {guest.qrSvg && guest.portalUrl && (
                                        <div
                                            role="img"
                                            aria-label={`VFMS QR code for ${guest.portalUrl}`}
                                            className="size-28 bg-white p-2 [&_svg]:size-full"
                                            dangerouslySetInnerHTML={{
                                                __html: guest.qrSvg,
                                            }}
                                        />
                                    )}
                                    <figcaption className="space-y-1 text-sm break-words">
                                        {guest.portalUrl && (
                                            <a
                                                href={guest.portalUrl}
                                                className="block underline underline-offset-4"
                                            >
                                                Scan to verify with ZRA
                                            </a>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            ZRA report{' '}
                                            <Identifier
                                                value={guest.number}
                                                copyable
                                            />
                                        </p>
                                    </figcaption>
                                </figure>
                            </article>
                        ))}
                    </section>
                )}
                {report && identityRevealed && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">
                            Revealed identity documents
                        </h2>
                        {guestReports
                            .filter((guest) => guest.identity)
                            .map((guest) => (
                                <p
                                    key={guest.number}
                                    className="text-sm break-words"
                                >
                                    {guest.name ?? 'Guest report'} /{' '}
                                    <Identifier value={guest.number} />:{' '}
                                    {guest.identity?.documentName}{' '}
                                    {guest.identity?.documentNo && (
                                        <Identifier
                                            value={guest.identity.documentNo}
                                        />
                                    )}{' '}
                                    {guest.identity?.country}
                                </p>
                            ))}
                    </section>
                )}
                <div className="flex flex-wrap gap-3 print:hidden">
                    {report && revealIdentityUrl && !identityRevealed && (
                        <Form action={revealIdentityUrl} method="post">
                            <PropertyIntent />
                            <Button variant="outline" type="submit">
                                Reveal document numbers for printing (logged)
                            </Button>
                        </Form>
                    )}
                    <Button variant="outline" onClick={() => window.print()}>
                        Print report
                    </Button>
                </div>
            </div>
        </>
    );
}

CheckoutReport.layout = ({
    stay,
}: {
    stay: { reservation_number: string };
}) => ({
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        {
            title: stay.reservation_number,
            href: showReservation(stay.reservation_number),
        },
        { title: 'Fiscal check-out report', href: '#' },
    ],
});

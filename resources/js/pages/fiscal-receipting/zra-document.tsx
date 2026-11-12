import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { fiscalDocumentTypes } from '@/features/fiscal-receipting/document-correction';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import { fiscalReport } from '@/routes/reservations';

export type PrintedDocument = {
    id: string;
    type: keyof typeof fiscalDocumentTypes;
    number: string;
    mode: 'sandbox' | 'live';
    currency: string | null;
    viewerUrl: string | null;
    qrSvg: string | null;
    issuedAt: string | null;
    business: {
        name: string;
        street: string;
        phone: string;
        tin: string | null;
        vrn: string | null;
        znumber: string | null;
    };
    customer: string | null;
    items: {
        name: string;
        quantity: number;
        price: number | null;
        discount: number | null;
        amount: number | null;
    }[];
    rooms: {
        room: string;
        days: number;
        price: number | null;
        amount: number | null;
        tax: number | null;
    }[];
    taxExclusive: number | null;
    tax: number | null;
    levy: number | null;
    total: number | null;
};

const unavailable = 'Not available';

/** ZRA's own receipt frame (portal sample OGK283), one document per sheet. Every figure is ZRA's stored answer. */
function Sheet({
    document,
    timezone,
    property,
    branding,
}: {
    document: PrintedDocument;
    timezone: string;
    property: { name: string };
    branding: { logo_url: string | null };
}) {
    const money = (minor: number | null) =>
        minor === null || document.currency === null
            ? unavailable
            : formatMoney(minor, document.currency);
    const isReport = document.type === 'checkout_report';

    return (
        <article
            id={`document-${document.type}-${document.number}`}
            className="zra-sheet break-after-page space-y-4 last:break-after-auto"
        >
            <p className="text-center text-xs tracking-widest text-muted-foreground uppercase">
                Start of a legal {isReport ? 'report' : 'receipt'}
            </p>
            <header className="space-y-1 text-center">
                {branding.logo_url && (
                    <img
                        src={branding.logo_url}
                        alt={property.name}
                        className="mx-auto h-auto max-h-20 w-auto max-w-full object-contain"
                    />
                )}
                <h1 className="text-xl font-semibold text-balance">
                    {document.business.name}
                </h1>
                <p>{document.business.street}</p>
                <p className="text-sm text-muted-foreground">
                    {[
                        document.business.znumber &&
                            `Z-number ${document.business.znumber}`,
                        document.business.tin && `TIN ${document.business.tin}`,
                        document.business.vrn && `VRN ${document.business.vrn}`,
                        document.business.phone,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                {document.mode === 'sandbox' && (
                    <p>
                        <StatusChip tone="warning">TEST, NOT FISCAL</StatusChip>
                    </p>
                )}
            </header>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                {(
                    [
                        [
                            isReport ? 'Report number' : 'Receipt number',
                            <Identifier key="n" value={document.number} />,
                        ],
                        ['Customer', document.customer ?? unavailable],
                        ['Currency', document.currency ?? unavailable],
                        [
                            'Issued',
                            document.issuedAt
                                ? `${formatDate(document.issuedAt, timezone)} ${formatTime(document.issuedAt, timezone)}`
                                : unavailable,
                        ],
                    ] as [string, React.ReactNode][]
                ).map(([label, value]) => (
                    <div key={label} className="min-w-0">
                        <dt className="text-sm text-muted-foreground">
                            {label}
                        </dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
            {document.rooms.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Room</TableHead>
                            <TableHead numeric>Nights</TableHead>
                            <TableHead numeric>Price</TableHead>
                            <TableHead numeric>Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {document.rooms.map((line, index) => (
                            <TableRow key={`${line.room}-${index}`}>
                                <TableCell>{line.room}</TableCell>
                                <TableCell numeric>{line.days}</TableCell>
                                <TableCell numeric>
                                    {money(line.price)}
                                </TableCell>
                                <TableCell numeric>
                                    {money(line.amount)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {(document.items.length > 0 || document.rooms.length === 0) && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Item</TableHead>
                            <TableHead className="zra-status">Status</TableHead>
                            <TableHead numeric>Qty</TableHead>
                            <TableHead numeric>Price</TableHead>
                            <TableHead numeric>Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {document.items.map((line, index) => (
                            <TableRow key={`${line.name}-${index}`}>
                                <TableCell className="whitespace-normal">
                                    {line.name}
                                    {line.discount ? (
                                        <span className="block text-xs text-muted-foreground">
                                            Discount {money(line.discount)}
                                        </span>
                                    ) : null}
                                </TableCell>
                                <TableCell className="zra-status">
                                    Taxable
                                </TableCell>
                                <TableCell numeric>{line.quantity}</TableCell>
                                <TableCell numeric>
                                    {money(line.price)}
                                </TableCell>
                                <TableCell numeric>
                                    {money(line.amount)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            <dl className="ml-auto grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                {(
                    [
                        ...(document.taxExclusive === null
                            ? []
                            : [['Tax exclusive', document.taxExclusive]]),
                        ['Tax', document.tax],
                        ...(isReport
                            ? [['Infrastructure levy', document.levy]]
                            : []),
                        ['Total', document.total],
                    ] as [string, number | null][]
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
                        <dd
                            className={`text-right tabular-nums ${label === 'Total' ? 'font-semibold' : ''}`}
                        >
                            {money(value)}
                        </dd>
                    </div>
                ))}
            </dl>
            {document.qrSvg && document.viewerUrl && (
                <figure className="mx-auto w-fit break-inside-avoid space-y-2 text-center">
                    <div
                        role="img"
                        aria-label={`VFMS QR code for ${document.viewerUrl}`}
                        className="mx-auto size-40 bg-qr-surface p-2 [&_svg]:size-full"
                        dangerouslySetInnerHTML={{ __html: document.qrSvg }}
                    />
                    <figcaption className="space-y-1 text-sm">
                        <a
                            href={document.viewerUrl}
                            className="inline-flex min-h-11 min-w-11 items-center underline underline-offset-4 print:block print:min-h-0 print:min-w-0"
                        >
                            Scan to verify with ZRA
                        </a>
                        <p className="text-xs text-muted-foreground">
                            ZRA {isReport ? 'report' : 'receipt'}{' '}
                            <Identifier value={document.number} />
                        </p>
                    </figcaption>
                </figure>
            )}
            <p className="text-center text-xs tracking-widest text-muted-foreground uppercase">
                End of a legal {isReport ? 'report' : 'receipt'}
            </p>
        </article>
    );
}

export default function ZraDocument({
    documents,
    sandbox,
    property,
    branding,
    reservationNumber,
}: {
    documents: PrintedDocument[];
    sandbox: boolean;
    property: { name: string };
    branding: { logo_url: string | null };
    reservationNumber: string;
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';
    const [paper, setPaper] = useState('a4');
    const title =
        documents.length === 1
            ? `${fiscalDocumentTypes[documents[0].type]} ${documents[0].number}`
            : `ZRA documents ${reservationNumber}`;

    return (
        <>
            <Head title={title}>
                <style media="print">{`@page { size: ${paper === 'thermal' ? '80mm 300mm' : 'A4'}; margin: ${paper === 'thermal' ? '4mm' : '12mm'}; }`}</style>
            </Head>
            <div
                data-paper={paper}
                className="receipt-print mx-auto flex w-full max-w-[720px] min-w-0 flex-col gap-8 p-4 text-sm [overflow-wrap:anywhere] md:p-6 print:gap-0 print:p-0"
            >
                <div className="flex flex-col gap-8 print:gap-0">
                    {documents.map((document) => (
                        <Sheet
                            key={document.id}
                            document={document}
                            timezone={timezone}
                            property={property}
                            branding={branding}
                        />
                    ))}
                </div>
                {documents.length === 0 && (
                    <p className="text-muted-foreground">
                        {property.name} has no issued ZRA documents to print.
                    </p>
                )}
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <div className="space-y-2">
                        <Label htmlFor="zra-paper">Paper size</Label>
                        <NativeSelect
                            id="zra-paper"
                            value={paper}
                            onChange={(event) => setPaper(event.target.value)}
                        >
                            <option value="a4">A4</option>
                            <option value="thermal">80 mm thermal</option>
                        </NativeSelect>
                    </div>
                    <Button
                        variant="outline"
                        disabled={documents.length === 0}
                        onClick={() => window.print()}
                    >
                        {documents.length === 1
                            ? 'Print document'
                            : 'Print all documents'}
                    </Button>
                    {sandbox && (
                        <StatusChip tone="warning">TEST, NOT FISCAL</StatusChip>
                    )}
                </div>
            </div>
        </>
    );
}

ZraDocument.layout = ({
    reservationNumber,
}: {
    reservationNumber: string;
}) => ({
    breadcrumbs: [
        { title: 'Reservations', href: '/reservations' },
        {
            title: reservationNumber,
            href: `/reservations/${reservationNumber}`,
        },
        {
            title: 'ZRA documents',
            href: fiscalReport(reservationNumber).url,
        },
        { title: 'Print', href: '#' },
    ],
});

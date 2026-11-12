import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DocumentCorrectionControl,
    fiscalDocumentTypes,
    type DocumentCorrectionView,
} from '@/features/fiscal-receipting/document-correction';
import {
    GeneralReceiptDialog,
    type GeneralReceiptPreview,
} from '@/features/fiscal-receipting/general-receipt-dialog';
import { RecoverFiling } from '@/features/fiscal-receipting/vfms-filings-panel';
import { postingCategories } from '@/features/billing/folio-panel';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';

type Mode = 'sandbox' | 'live' | null;
type RowState = {
    status: 'issued' | 'sending' | 'attention';
    reason: string | null;
    retryUrl: string | null;
    refileUrl: string | null;
    printUrl: string | null;
    issuedAt: string | null;
    mode: Mode;
    portalUrl: string | null;
    qrSvg: string | null;
};
type Money = { amount: number | null; currency: string | null };
type ServiceReceipt = RowState &
    Money & {
        number: string | null;
        description: string | null;
        guest: string | null;
        room: string | null;
    };
type GeneralReceipt = RowState &
    Money & { number: string | null; customer: string; items: number };
type GuestReport = RowState &
    Money & {
        number: string | null;
        name: string | null;
        room: string | null;
        levy: number | null;
        identity: {
            documentName?: string;
            documentNo?: string;
            country?: string;
        } | null;
        departedEarly: boolean;
    };
type Held = {
    id: string;
    description: string | null;
    category: string;
    quantity: number;
    unit_price: number;
    discount: number;
    amount: number;
    currency: string;
    room: string | null;
    postedAt: string;
};
type Summary = {
    currency: string;
    room: number;
    extras: number;
    vat: number;
    levy: number;
    charges: number;
    held: number;
};

const states = {
    issued: ['success', 'Issued'],
    sending: ['info', 'Sending to ZRA'],
    attention: ['error', 'Needs attention'],
} as const;

function Chip({ row }: { row: RowState }) {
    const [tone, label] = states[row.status];

    return (
        <div className="space-y-1">
            <StatusChip tone={tone}>{label}</StatusChip>
            {row.reason && (
                <p className="max-w-64 text-xs whitespace-normal text-muted-foreground">
                    {row.reason}
                </p>
            )}
        </div>
    );
}

/** Print, the QR as a popover, and ZRA recovery. Retry and Re-file reuse the VFMS filing dialogs. */
function Actions({ row, name }: { row: RowState; name: string }) {
    const popover = `qr-${name}`;

    return (
        <div className="flex flex-wrap items-center gap-2 print:hidden">
            {row.printUrl && (
                <Button asChild variant="outline" size="sm">
                    <Link href={row.printUrl}>Print</Link>
                </Button>
            )}
            {row.qrSvg && row.portalUrl && (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        popoverTarget={popover}
                    >
                        QR
                    </Button>
                    <div
                        id={popover}
                        popover="auto"
                        className="m-auto rounded-lg border bg-popover p-4 text-popover-foreground shadow-panel"
                    >
                        <figure className="space-y-2">
                            <div
                                role="img"
                                aria-label={`VFMS QR code for ${row.portalUrl}`}
                                className="size-40 bg-qr-surface p-2 [&_svg]:size-full"
                                dangerouslySetInnerHTML={{ __html: row.qrSvg }}
                            />
                            <figcaption className="text-sm">
                                <a
                                    href={row.portalUrl}
                                    className="inline-flex min-h-11 min-w-11 items-center underline underline-offset-4"
                                >
                                    Scan to verify with ZRA
                                </a>
                            </figcaption>
                        </figure>
                    </div>
                </>
            )}
            {row.portalUrl && !row.qrSvg && (
                <a
                    href={row.portalUrl}
                    className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                >
                    Scan to verify with ZRA
                </a>
            )}
            {row.retryUrl && (
                <RecoverFiling
                    id={`${name}-retry`}
                    call="document"
                    url={row.retryUrl}
                    action="Retry"
                />
            )}
            {row.refileUrl && (
                <RecoverFiling
                    id={`${name}-refile`}
                    call="document"
                    url={row.refileUrl}
                    action="Re-file"
                />
            )}
        </div>
    );
}

function Section({
    title,
    count,
    action,
    children,
}: {
    title: string;
    count: number;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="min-w-0 space-y-3 print:break-inside-avoid">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-lg font-semibold">
                    {title} ({count})
                </h2>
                {action}
            </div>
            {children}
        </section>
    );
}

export default function CheckoutReport({
    embedded = false,
    status,
    stay,
    guests,
    sandbox,
    rooms,
    payingInstitution,
    summary,
    held,
    generalReceipts,
    serviceReceipts,
    guestReports,
    generalReceipt,
    printAllUrl,
    revealIdentityUrl,
    identityRevealed = false,
    reservationUrl,
    documentCorrections = {},
}: {
    embedded?: boolean;
    documentCorrections?: Record<string, DocumentCorrectionView>;
    status: 'waiting' | 'sandbox' | 'live';
    sandbox: boolean;
    rooms: number;
    payingInstitution: { name: string; zrbNumber: string | null } | null;
    summary: Summary[];
    held: Held[];
    generalReceipts: GeneralReceipt[];
    serviceReceipts: ServiceReceipt[];
    guestReports: GuestReport[];
    generalReceipt: GeneralReceiptPreview | null;
    printAllUrl: string;
    revealIdentityUrl: string | null;
    reservationUrl: string | null;
    identityRevealed?: boolean;
    stay: {
        reservation_number: string;
        room: string | null;
        room_type: string;
        arrival: string;
        departure: string;
        nights: number;
    };
    guests: string[];
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';
    const when = (instant: string | null) =>
        instant
            ? `${formatDate(instant, timezone)} ${formatTime(instant, timezone)}`
            : '';
    const money = ({ amount, currency }: Money) =>
        amount === null || currency === null
            ? 'Issue amount unavailable'
            : formatMoney(amount, currency);
    const corrections = (
        type: keyof typeof fiscalDocumentTypes,
        row: { number: string | null; mode: Mode },
    ) =>
        Object.values(documentCorrections)
            .filter(
                (view) =>
                    view.document.type === type &&
                    view.document.number === row.number &&
                    view.document.mode === row.mode,
            )
            .map((view) => (
                <DocumentCorrectionControl key={view.document.id} view={view} />
            ));
    const guestGroups = Object.entries(
        Object.groupBy(
            serviceReceipts,
            (receipt) =>
                `${receipt.guest ?? 'Guest'}${receipt.room ? ` · Room ${receipt.room}` : ''}`,
        ),
    );
    const issuedReports = guestReports.filter(
        (report) => report.status === 'issued',
    ).length;
    const waiting = held.length;

    return (
        <>
            {!embedded && (
                <Head title={`ZRA documents ${stay.reservation_number}`} />
            )}
            <div
                className={
                    embedded
                        ? 'flex min-w-0 flex-col gap-6 text-sm'
                        : 'receipt-print flex w-full min-w-0 flex-col gap-8 p-4 text-sm [overflow-wrap:anywhere] md:p-6'
                }
            >
                {!embedded && (
                    <header className="space-y-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0 space-y-1">
                                <h1 className="text-2xl font-semibold text-balance">
                                    ZRA documents ·{' '}
                                    {reservationUrl ? (
                                        <Link
                                            href={reservationUrl}
                                            className="underline underline-offset-4"
                                        >
                                            <Identifier
                                                value={stay.reservation_number}
                                            />
                                        </Link>
                                    ) : (
                                        <Identifier
                                            value={stay.reservation_number}
                                        />
                                    )}
                                    {rooms > 1 && ` (group of ${rooms} rooms)`}
                                </h1>
                                <p className="text-muted-foreground">
                                    {guests.join(', ')}
                                    {payingInstitution &&
                                        ` · Paid by ${payingInstitution.name}${payingInstitution.zrbNumber ? ` (${payingInstitution.zrbNumber})` : ''}`}
                                </p>
                                <p className="text-muted-foreground">
                                    Room {stay.room ?? 'not assigned'},{' '}
                                    {stay.room_type} ·{' '}
                                    {formatDate(stay.arrival, 'UTC')} to{' '}
                                    {formatDate(stay.departure, 'UTC')} ·{' '}
                                    {stay.nights}{' '}
                                    {stay.nights === 1 ? 'night' : 'nights'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-3 print:hidden">
                                <Button asChild variant="outline">
                                    <Link href={printAllUrl}>Print all</Link>
                                </Button>
                                <GeneralReceiptDialog
                                    preview={generalReceipt}
                                />
                            </div>
                        </div>
                        {sandbox && (
                            <div
                                role="status"
                                className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3"
                            >
                                <StatusChip tone="warning">
                                    TEST, NOT FISCAL
                                </StatusChip>
                                <span>
                                    This property files with ZRA's test server.
                                    None of these documents is fiscal.
                                </span>
                            </div>
                        )}
                    </header>
                )}

                {embedded && (
                    <Button asChild variant="outline">
                        <Link href={printAllUrl}>Print all</Link>
                    </Button>
                )}
                <section aria-label="Summary" className="space-y-3">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Currency</TableHead>
                                <TableHead numeric>Rooms</TableHead>
                                <TableHead numeric>Extras</TableHead>
                                <TableHead numeric>VAT</TableHead>
                                <TableHead numeric>Levy</TableHead>
                                <TableHead numeric>Total</TableHead>
                                <TableHead numeric>Not yet receipted</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {summary.map((line) => (
                                <TableRow key={line.currency}>
                                    <TableCell>{line.currency}</TableCell>
                                    <TableCell numeric>
                                        {formatMoney(line.room, line.currency)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            line.extras,
                                            line.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(line.vat, line.currency)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(line.levy, line.currency)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(
                                            line.charges,
                                            line.currency,
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(line.held, line.currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <p className="text-muted-foreground">
                        {issuedReports} check-out{' '}
                        {issuedReports === 1 ? 'report' : 'reports'} ·{' '}
                        {generalReceipts.length} general{' '}
                        {generalReceipts.length === 1 ? 'receipt' : 'receipts'}{' '}
                        · {serviceReceipts.length} guest{' '}
                        {serviceReceipts.length === 1 ? 'receipt' : 'receipts'}
                        {status === 'waiting' &&
                            (embedded
                                ? ''
                                : ' · The final check-out report is not complete.')}
                    </p>
                </section>

                {embedded && sandbox && (
                    <StatusChip tone="warning">TEST, NOT FISCAL</StatusChip>
                )}
                <Section
                    title="Not yet receipted"
                    count={waiting}
                    action={<GeneralReceiptDialog preview={generalReceipt} />}
                >
                    {waiting === 0 ? (
                        <EmptyState message="Everything is receipted" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Item</TableHead>
                                    <TableHead>Room</TableHead>
                                    <TableHead numeric>Qty</TableHead>
                                    <TableHead numeric>Price</TableHead>
                                    <TableHead numeric>Discount</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                    <TableHead>Posted</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {held.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="whitespace-normal">
                                            {item.description ??
                                                postingCategories[
                                                    item.category
                                                ] ??
                                                item.category}
                                        </TableCell>
                                        <TableCell>{item.room}</TableCell>
                                        <TableCell numeric>
                                            {item.quantity}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                item.unit_price,
                                                item.currency,
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                item.discount,
                                                item.currency,
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                item.amount,
                                                item.currency,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {when(item.postedAt)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip tone="warning">
                                                Not yet receipted
                                            </StatusChip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Section>

                {generalReceipts.length > 0 && (
                    <Section
                        title="General receipts"
                        count={generalReceipts.length}
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Receipt</TableHead>
                                    <TableHead>Customer</TableHead>
                                    <TableHead numeric>Items</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                    <TableHead>Issued</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {generalReceipts.map((row, position) => (
                                    <TableRow
                                        key={`${row.number}-${position}`}
                                        id={`document-general_receipt-${row.number}`}
                                    >
                                        <TableCell>
                                            {row.number && (
                                                <Identifier
                                                    value={row.number}
                                                    copyable
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            {row.customer}
                                        </TableCell>
                                        <TableCell numeric>
                                            {row.items}
                                        </TableCell>
                                        <TableCell numeric>
                                            {money(row)}
                                        </TableCell>
                                        <TableCell>
                                            {when(row.issuedAt)}
                                        </TableCell>
                                        <TableCell>
                                            <Chip row={row} />
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <Actions
                                                row={row}
                                                name={`general-${position}`}
                                            />
                                            {corrections(
                                                'general_receipt',
                                                row,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Section>
                )}

                {serviceReceipts.length > 0 && (
                    <Section
                        title="Guest receipts"
                        count={serviceReceipts.length}
                    >
                        {guestGroups.map(([guest, group = []]) => (
                            <div key={guest} className="space-y-2">
                                <h3 className="font-medium">{guest}</h3>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Receipt</TableHead>
                                            <TableHead>Item</TableHead>
                                            <TableHead numeric>
                                                Amount
                                            </TableHead>
                                            <TableHead>Issued</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {group.map((row, position) => (
                                            <TableRow
                                                key={`${row.number}-${position}`}
                                                id={`document-services_receipt-${row.number}`}
                                            >
                                                <TableCell>
                                                    {row.number && (
                                                        <Identifier
                                                            value={row.number}
                                                            copyable
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {row.description ??
                                                        'Extra service'}
                                                </TableCell>
                                                <TableCell numeric>
                                                    {money(row)}
                                                </TableCell>
                                                <TableCell>
                                                    {when(row.issuedAt)}
                                                </TableCell>
                                                <TableCell>
                                                    <Chip row={row} />
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    <Actions
                                                        row={row}
                                                        name={`guest-${guest}-${position}`.replace(
                                                            /\W/g,
                                                            '',
                                                        )}
                                                    />
                                                    {corrections(
                                                        'services_receipt',
                                                        row,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ))}
                    </Section>
                )}

                {guestReports.length > 0 && (
                    <Section
                        title="Check-out reports"
                        count={guestReports.length}
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Guest</TableHead>
                                    <TableHead>Room</TableHead>
                                    <TableHead>Report</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                    <TableHead numeric>Levy</TableHead>
                                    <TableHead>Issued</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {guestReports.map((row, position) => (
                                    <TableRow
                                        key={`${row.number}-${position}`}
                                        id={`document-checkout_report-${row.number}`}
                                    >
                                        <TableCell className="whitespace-normal">
                                            {row.name ?? 'Guest'}
                                            {row.departedEarly && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Departed before stay
                                                    check-out
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{row.room}</TableCell>
                                        <TableCell>
                                            {row.number && (
                                                <Identifier
                                                    value={row.number}
                                                    copyable
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {row.amount === null
                                                ? ''
                                                : money(row)}
                                        </TableCell>
                                        <TableCell numeric>
                                            {row.levy === null ||
                                            row.currency === null
                                                ? ''
                                                : formatMoney(
                                                      row.levy,
                                                      row.currency,
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            {when(row.issuedAt)}
                                        </TableCell>
                                        <TableCell>
                                            <Chip row={row} />
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <Actions
                                                row={row}
                                                name={`report-${position}`}
                                            />
                                            {corrections(
                                                'checkout_report',
                                                row,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Section>
                )}

                {identityRevealed && (
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
                                    {guest.name ?? 'Guest report'}
                                    {guest.number && (
                                        <>
                                            {' / '}
                                            <Identifier value={guest.number} />
                                        </>
                                    )}
                                    : {guest.identity?.documentName}{' '}
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
                {revealIdentityUrl && !identityRevealed && (
                    <div className="print:hidden">
                        <Form action={revealIdentityUrl} method="post">
                            <PropertyIntent />
                            <Button variant="outline" type="submit">
                                Reveal document numbers for printing (logged)
                            </Button>
                        </Form>
                    </div>
                )}
            </div>
        </>
    );
}

export type ZraDocumentsProps = React.ComponentProps<typeof CheckoutReport>;

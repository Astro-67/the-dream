import { ChevronDown } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Form, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import { PaymentForm, paymentMethods } from '@/features/billing/payment-form';
import { AdjustmentForms } from '@/features/billing/adjustment-form';
import { store } from '@/routes/folios/charges';
import {
    OpenFolioForm,
    PostingActions,
    PostingLink,
} from './folio-corrections';
import { show as showReceipt } from '@/routes/receipts';
import type { Paginated } from '@/types';
import {
    dueLine,
    FolioSummaryList,
    paymentStatus,
    type FolioTotal,
} from './folio-summary';
import { statement } from '@/routes/reservations';
import { PaymentsReceived, type ReceivedPayment } from './payments-received';

export type Posting = {
    id: string;
    type: string;
    category: string;
    description: string | null;
    reason: string | null;
    related: {
        id: string;
        type: string;
        category: string;
        folio_id: string;
    } | null;
    cancelled_by: {
        id: string;
        type: string;
        folio_id: string;
        destination_folio_id: string | null;
    } | null;
    quantity: number;
    unit_price: number;
    net: number;
    amount: number;
    tax_lines: { name: string; amount: number; unavailable?: boolean }[];
    posted_at: string;
    actor: string | null;
    method?: string | null;
    reference?: string | null;
    receipt?: { id: string; number: string } | null;
    vfms_receipt_number?: string | null;
};
export type Folio = {
    id: string;
    currency: string;
    balance: number;
    number: number;
    postings: Paginated<Posting>;
    payments: Paginated<ReceivedPayment>;
};
export type { FolioTotal } from './folio-summary';
export type FolioStay = {
    reservation_number: string;
    guest: string;
    room: string | null;
    room_type: string | null;
    nights: number;
};

/** Total charges, Paid and Due for one currency; Due counts unposted nights as an estimate. */
function FolioDue({ total }: { total: FolioTotal }) {
    const status = paymentStatus[total.status];
    const money = (amount: number) => formatMoney(amount, total.currency);
    const due = dueLine(total.due, total.currency);

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-semibold">{total.currency}</h3>
                <StatusChip tone={status.tone}>{status.label}</StatusChip>
            </div>
            <dl className="grid grid-cols-[1fr_1fr_auto] gap-3">
                <div>
                    <dt className="text-sm text-muted-foreground">
                        Total charges
                    </dt>
                    <dd className="tabular-nums">{money(total.charges)}</dd>
                </div>
                <div>
                    <dt className="text-sm text-muted-foreground">Paid</dt>
                    <dd className="tabular-nums">
                        {money(total.net_payments)}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-muted-foreground">
                        {due.label}
                    </dt>
                    <dd className="text-base font-semibold tabular-nums sm:text-lg">
                        {due.amount}
                    </dd>
                </div>
            </dl>
            {total.estimate && (
                <p className="text-sm text-muted-foreground">
                    Due includes an estimate of {money(total.estimate.amount)}{' '}
                    for {total.estimate.nights}{' '}
                    {total.estimate.nights === 1 ? 'night' : 'nights'} not yet
                    charged, before added taxes.
                </p>
            )}
        </div>
    );
}

// Labels only: the server owns which extras may be posted (PostExtraCharge::CATEGORIES).
const categories: Record<string, string> = {
    restaurant: 'Restaurant',
    bar: 'Bar',
    minibar: 'Minibar',
    laundry: 'Laundry',
    spa: 'Spa',
    transport: 'Transport',
    other: 'Other',
};

export const postingCategories: Record<string, string> = {
    ...categories,
    room: 'Room charge',
    deposit: 'Deposit',
    payment: 'Payment',
    refund: 'Refund',
    write_off: 'Write-off',
};

export function FolioPanel({
    folios: folioPage,
    totals,
    canPostCharge,
    canRecordPayment = false,
    channelCollectedAmount = null,
    acceptedPaymentMethods,
    extraCategories,
    stay,
    paymentsReportUrl,
}: {
    stay: FolioStay;
    paymentsReportUrl?: string | null;
    folios: Paginated<Folio>;
    totals: FolioTotal[];
    canPostCharge: boolean;
    canRecordPayment?: boolean;
    channelCollectedAmount?: number | null;
    acceptedPaymentMethods: string[];
    extraCategories: string[];
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';
    const folios = folioPage.data;
    const [selectedFolioId, setSelectedFolioId] = useState<string | null>(null);
    const folio =
        folios.find((item) => item.id === selectedFolioId) ?? folios[0];
    const total = totals.find((item) => item.currency === folio?.currency);
    const due = total && dueLine(total.due, total.currency);

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">Folio</h2>
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                <span>{stay.guest}</span>
                <span aria-hidden="true">·</span>
                <Identifier value={stay.reservation_number} />
                <span aria-hidden="true">·</span>
                <span>
                    {stay.room ? `Room ${stay.room}` : 'No room assigned'}
                </span>
                <span aria-hidden="true">·</span>
                <span>{stay.room_type ?? '—'}</span>
                <span aria-hidden="true">·</span>
                <span>
                    {stay.nights} {stay.nights === 1 ? 'night' : 'nights'}
                </span>
            </p>
            <div className="grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                {totals.map((total) => (
                    <Card key={total.currency} className="gap-4 py-4 md:py-6">
                        <CardContent className="space-y-4 px-4 md:px-6">
                            <FolioDue total={total} />
                            <Collapsible>
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="group -mx-3 min-h-11"
                                        aria-label={`Show breakdown for ${total.currency}`}
                                    >
                                        Show breakdown
                                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                    </Button>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="pt-4">
                                    <FolioSummaryList
                                        summary={total}
                                        currency={total.currency}
                                        estimate={total.estimate}
                                    />
                                </CollapsibleContent>
                            </Collapsible>
                        </CardContent>
                    </Card>
                ))}
            </div>
            {folioPage.total > 1 && (
                <Table aria-label="Folios">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Folio</TableHead>
                            <TableHead>Currency</TableHead>
                            <TableHead numeric>Balance</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {folios.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell>
                                    Folio {item.number}
                                    <div>
                                        <Identifier value={item.id} copyable />
                                    </div>
                                </TableCell>
                                <TableCell>{item.currency}</TableCell>
                                <TableCell numeric>
                                    {formatMoney(item.balance, item.currency)}
                                </TableCell>
                                <TableCell numeric>
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <a
                                            href="#folio-detail"
                                            aria-label={`View folio ${item.number}`}
                                            aria-current={
                                                item.id === folio?.id
                                                    ? 'true'
                                                    : undefined
                                            }
                                            onClick={() =>
                                                setSelectedFolioId(item.id)
                                            }
                                        >
                                            View
                                        </a>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            <Pagination page={folioPage} />
            {folio ? (
                <div key={folio.id} id="folio-detail" className="space-y-3">
                    {folioPage.total > 1 && (
                        <h3 className="font-semibold">
                            Folio {folio.number} ({folio.currency}){' '}
                            <Identifier
                                value={folio.id}
                                copyable
                                className="text-sm"
                            />
                        </h3>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {canRecordPayment && total && (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button className="h-auto min-h-11 text-left whitespace-normal">
                                        Record payment for this {folio.currency}{' '}
                                        folio
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                                    <DialogHeader>
                                        <DialogTitle>
                                            Record payment for this{' '}
                                            {folio.currency} folio
                                        </DialogTitle>
                                        <DialogDescription>
                                            {due?.label}: {due?.amount}
                                            {total.estimate &&
                                                ' (includes estimated nights before added taxes)'}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <PaymentForm
                                        folio={folio}
                                        total={total}
                                        channelCollectedAmount={
                                            channelCollectedAmount
                                        }
                                        acceptedMethods={acceptedPaymentMethods}
                                    />
                                </DialogContent>
                            </Dialog>
                        )}

                        {canPostCharge && (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="secondary"
                                        className="min-h-11"
                                    >
                                        Post an extra
                                    </Button>
                                </DialogTrigger>
                                <DialogContent
                                    className="max-h-[90dvh] overflow-y-auto"
                                    aria-describedby={undefined}
                                >
                                    <DialogHeader>
                                        <DialogTitle>Post an extra</DialogTitle>
                                    </DialogHeader>
                                    <ExtraChargeForm
                                        folio={folio}
                                        categories={extraCategories}
                                    />
                                </DialogContent>
                            </Dialog>
                        )}
                        <AdjustmentForms folio={folio} />
                        <Button
                            asChild
                            variant="link"
                            className="h-auto min-h-11 text-left whitespace-normal"
                        >
                            <Link href={statement(stay.reservation_number)}>
                                Folio statement
                            </Link>
                        </Button>
                        {paymentsReportUrl && (
                            <Button
                                asChild
                                variant="link"
                                className="h-auto min-h-11 text-left whitespace-normal"
                            >
                                <Link href={paymentsReportUrl}>
                                    View payments report for this reservation
                                </Link>
                            </Button>
                        )}
                    </div>
                    {folio.postings.data.length === 0 ? (
                        <EmptyState message="Nothing posted yet. Post an extra when the guest uses one." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Posted</TableHead>
                                    <TableHead>Item</TableHead>
                                    <TableHead numeric>Quantity</TableHead>
                                    <TableHead numeric>Unit price</TableHead>
                                    <TableHead numeric>Tax</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {folio.postings.data.map((posting) => (
                                    <TableRow key={posting.id}>
                                        <TableCell>
                                            {formatDate(
                                                posting.posted_at,
                                                timezone,
                                            )}{' '}
                                            {formatTime(
                                                posting.posted_at,
                                                timezone,
                                            )}
                                            {posting.actor && (
                                                <div className="text-sm text-muted-foreground">
                                                    {posting.actor}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {posting.description ||
                                                (postingCategories[
                                                    posting.category as keyof typeof postingCategories
                                                ] ??
                                                    posting.category)}
                                            {posting.vfms_receipt_number && (
                                                <div className="text-sm wrap-anywhere text-muted-foreground">
                                                    VFMS receipt:{' '}
                                                    {
                                                        posting.vfms_receipt_number
                                                    }
                                                </div>
                                            )}
                                            {posting.description &&
                                                categories[
                                                    posting.category
                                                ] && (
                                                    <div className="text-sm text-muted-foreground">
                                                        {
                                                            categories[
                                                                posting.category
                                                            ]
                                                        }
                                                    </div>
                                                )}
                                            {posting.method && (
                                                <div className="text-sm text-muted-foreground">
                                                    {paymentMethods[
                                                        posting.method as keyof typeof paymentMethods
                                                    ] ?? posting.method}
                                                    {posting.reference &&
                                                        `, ${posting.reference}`}
                                                </div>
                                            )}
                                            {posting.receipt && (
                                                <Link
                                                    href={showReceipt(
                                                        posting.receipt.id,
                                                    )}
                                                    className="text-sm underline underline-offset-4"
                                                >
                                                    Receipt{' '}
                                                    <Identifier
                                                        value={
                                                            posting.receipt
                                                                .number
                                                        }
                                                    />
                                                </Link>
                                            )}
                                            <PostingLink posting={posting} />
                                        </TableCell>
                                        <TableCell numeric>
                                            {posting.quantity}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                posting.unit_price,
                                                folio.currency,
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {posting.tax_lines.map((line) => (
                                                <div key={line.name}>
                                                    {line.name}{' '}
                                                    {line.unavailable
                                                        ? 'not available in this currency'
                                                        : formatMoney(
                                                              line.amount,
                                                              folio.currency,
                                                          )}
                                                </div>
                                            ))}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                posting.amount,
                                                folio.currency,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <PostingActions
                                                posting={posting}
                                                folio={folio}
                                                folios={folios}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={folio.postings} />
                    {folio.payments.total > 0 && (
                        <Collapsible>
                            <h3>
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="group -mx-3 min-h-11 font-semibold"
                                    >
                                        Payments received (
                                        {folio.payments.total})
                                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                    </Button>
                                </CollapsibleTrigger>
                            </h3>
                            <CollapsibleContent className="pt-4">
                                <PaymentsReceived
                                    payments={folio.payments}
                                    currency={folio.currency}
                                    timezone={timezone}
                                />
                            </CollapsibleContent>
                        </Collapsible>
                    )}
                </div>
            ) : (
                <div className="space-y-3">
                    <Link
                        href={statement(stay.reservation_number)}
                        className="text-primary underline underline-offset-4"
                    >
                        Folio statement
                    </Link>
                    <EmptyState message="No folios on this page. Choose another page or open a folio." />
                </div>
            )}
            <OpenFolioForm />
        </section>
    );
}

export function ExtraChargeForm({
    folio,
    categories: allowed,
}: {
    folio: Folio;
    categories: string[];
}) {
    // One key per filled-in form, so a resubmission of the same form posts once.
    const [key, setKey] = useState(() => crypto.randomUUID());
    const id = (field: string) => `charge-${folio.id}-${field}`;

    return (
        <Form
            {...store.form(folio.id)}
            resetOnSuccess
            onSuccess={() => setKey(crypto.randomUUID())}
            options={{ preserveScroll: true }}
            className="grid max-w-[720px] gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <input type="hidden" name="idempotency_key" value={key} />
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor={id('description')}>Item name</Label>
                        <Input
                            id={id('description')}
                            name="description"
                            maxLength={255}
                            required
                            aria-invalid={Boolean(errors.description)}
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor={id('category')}>Category</Label>
                        <NativeSelect
                            id={id('category')}
                            name="category"
                            required
                            defaultValue=""
                            aria-invalid={Boolean(errors.category)}
                        >
                            <option value="" disabled>
                                Choose a category
                            </option>
                            {allowed.map((value) => (
                                <option key={value} value={value}>
                                    {categories[value] ?? value}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.category} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={id('quantity')}>Quantity</Label>
                        <Input
                            id={id('quantity')}
                            name="quantity"
                            type="number"
                            min={1}
                            max={1000}
                            step={1}
                            defaultValue={1}
                            required
                            aria-invalid={Boolean(errors.quantity)}
                        />
                        <InputError message={errors.quantity} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={id('unit_price')}>
                            VAT-inclusive unit price in {folio.currency}
                        </Label>
                        <MoneyInput
                            id={id('unit_price')}
                            name="unit_price"
                            currency={folio.currency}
                            min={0}
                            required
                            aria-invalid={Boolean(errors.unit_price)}
                        />
                        <InputError message={errors.unit_price} />
                    </div>
                    <InputError
                        message={errors.idempotency_key}
                        className="sm:col-span-2"
                    />
                    <div className="sm:col-span-2">
                        <Button disabled={processing}>
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Post charge
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

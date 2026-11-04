import { Form, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import { Spinner } from '@/components/ui/spinner';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';

export const fiscalDocumentTypes = {
    services_receipt: 'ZRA services receipt',
    checkout_report: 'Guest check-out report',
};

type FiscalDocument = {
    id: string;
    number: string;
    type: keyof typeof fiscalDocumentTypes;
    issued_at: string | null;
    amount: number | null;
    currency: string | null;
    mode: 'live' | 'sandbox';
};

export type CorrectionNote = {
    label: string;
    number: string;
    url: string | null;
    delivery: string | null;
    decision: string | null;
    refreshUrl: string | null;
};

export type DocumentCorrectionView = {
    document: FiscalDocument;
    submitUrl: string | null;
    replacements: FiscalDocument[];
    business: {
        name?: string;
        email?: string;
        phone?: string;
        znumber?: string;
        unitId?: number;
    };
    refusal: string | null;
    annotations: CorrectionNote[];
};

const decisions: Record<string, string> = {
    awaiting: 'Awaiting ZRA status',
    pending: 'Pending',
    verification: 'Under verification',
    approved: 'Approved',
    rejected: 'Correction rejected',
};
const deliveryLabels: Record<string, string> = {
    pending: 'Queued for delivery',
    in_flight: 'Sending submission',
    delivered: 'Submission delivered',
    rejected: 'Submission refused',
    blocked: 'Submission blocked',
    exhausted: 'Submission stopped',
    unknown: 'Delivery unknown - reconcile before any further submission',
    exhausted_unknown:
        'Delivery unknown - reconcile before any further submission',
};
const deliveryTones: Record<string, StatusTone> = {
    pending: 'info',
    in_flight: 'info',
    delivered: 'success',
    rejected: 'error',
    blocked: 'error',
    exhausted: 'error',
};

export function CorrectionAnnotation({ note }: { note: CorrectionNote }) {
    const { correctionRefresh, tenant } = usePage<{
        correctionRefresh?: { refreshing: boolean; observedAt: string | null };
    }>().props;
    return (
        <div className="space-y-2 text-sm break-words print:break-inside-avoid">
            <p>
                {note.label}{' '}
                {note.url ? (
                    <Link
                        href={note.url}
                        className="underline underline-offset-4"
                    >
                        <Identifier value={note.number} />
                    </Link>
                ) : (
                    <Identifier value={note.number} />
                )}
            </p>
            <div className="flex flex-wrap gap-2">
                <StatusChip
                    tone={deliveryTones[note.delivery ?? ''] ?? 'warning'}
                    className="whitespace-normal"
                >
                    {deliveryLabels[note.delivery ?? ''] ??
                        'Submission status unavailable'}
                </StatusChip>
                {note.decision && (
                    <StatusChip
                        tone={
                            note.decision === 'approved'
                                ? 'success'
                                : note.decision === 'rejected'
                                  ? 'error'
                                  : 'warning'
                        }
                        className="whitespace-normal"
                    >
                        {decisions[note.decision] ?? 'ZRA status unresolved'}
                    </StatusChip>
                )}
            </div>
            {note.refreshUrl && (
                <Form
                    action={note.refreshUrl}
                    method="post"
                    className="space-y-2 print:hidden"
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <Button
                                variant="outline"
                                size="sm"
                                type="submit"
                                disabled={
                                    processing || correctionRefresh?.refreshing
                                }
                            >
                                {(processing ||
                                    correctionRefresh?.refreshing) && (
                                    <Spinner />
                                )}
                                {processing || correctionRefresh?.refreshing
                                    ? 'Refreshing ZRA status...'
                                    : 'Refresh ZRA status'}
                            </Button>
                            <InputError message={errors.refresh} />
                            {correctionRefresh?.observedAt && (
                                <p className="text-xs text-muted-foreground">
                                    Last observed{' '}
                                    {formatDate(
                                        correctionRefresh.observedAt,
                                        tenant.property?.timezone,
                                    )}
                                    {' / '}
                                    {formatTime(
                                        correctionRefresh.observedAt,
                                        tenant.property?.timezone ?? 'UTC',
                                    )}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function DocumentFacts({ document }: { document: FiscalDocument }) {
    const { tenant } = usePage().props;
    return (
        <div className="space-y-1 break-words">
            <Identifier value={document.number} copyable />
            <p className="text-sm text-muted-foreground">
                {fiscalDocumentTypes[document.type]}
                {' / '}
                {document.issued_at === null
                    ? 'Issue date unavailable'
                    : formatDate(document.issued_at, tenant.property?.timezone)}
                {' / '}
                {document.mode === 'live' ? 'Live' : 'TEST, NOT FISCAL'}
            </p>
            <p className="tabular-nums">
                {document.amount === null || document.currency === null
                    ? 'Issue amount unavailable'
                    : formatMoney(document.amount, document.currency)}
            </p>
        </div>
    );
}

export function DocumentCorrectionControl({
    view,
}: {
    view: DocumentCorrectionView;
}) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState('');
    const replacement = view.replacements.find(
        (document) => document.id === selected,
    );
    const prefix = `correction-${view.document.id}`;
    return (
        <div className="space-y-3">
            {view.annotations.map((note, index) => (
                <CorrectionAnnotation key={index} note={note} />
            ))}
            {view.submitUrl && (
                <div className="print:hidden">
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                Correct this document
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
                            <DialogTitle>Correct this document</DialogTitle>
                            <DialogDescription>
                                Select an already-issued corrected document.
                                This does not issue a document, change the folio
                                or mean ZRA approval.
                            </DialogDescription>
                            <section className="space-y-2">
                                <h3 className="font-semibold">
                                    Original document
                                </h3>
                                <DocumentFacts document={view.document} />
                            </section>
                            {view.refusal ? (
                                <p
                                    role="alert"
                                    className="text-sm text-status-warning"
                                >
                                    {view.refusal}
                                </p>
                            ) : view.replacements.length === 0 ? (
                                <EmptyState message="Corrected document required: an accepted corrected document for this same service or guest must already exist before you can submit a correction. Do not repeat a charge or check-out to create one." />
                            ) : (
                                <Form
                                    action={view.submitUrl}
                                    method="post"
                                    className="space-y-4"
                                    options={{ preserveScroll: true }}
                                    onSuccess={() => setOpen(false)}
                                    onError={() =>
                                        requestAnimationFrame(() =>
                                            document
                                                .getElementById(
                                                    `${prefix}-errors`,
                                                )
                                                ?.focus(),
                                        )
                                    }
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <PropertyIntent />
                                            <div
                                                id={`${prefix}-errors`}
                                                role="alert"
                                                tabIndex={-1}
                                            >
                                                {Object.entries(errors).map(
                                                    ([field, message]) => (
                                                        <InputError
                                                            key={field}
                                                            message={message}
                                                        />
                                                    ),
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`${prefix}-replacement`}
                                                >
                                                    Already-issued corrected
                                                    document
                                                </Label>
                                                <NativeSelect
                                                    id={`${prefix}-replacement`}
                                                    name="replacement_id"
                                                    value={selected}
                                                    onChange={(event) =>
                                                        setSelected(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                    aria-invalid={
                                                        !!errors.replacement_id
                                                    }
                                                >
                                                    <option value="">
                                                        Select corrected
                                                        document
                                                    </option>
                                                    {view.replacements.map(
                                                        (document) => (
                                                            <option
                                                                key={
                                                                    document.id
                                                                }
                                                                value={
                                                                    document.id
                                                                }
                                                            >
                                                                {
                                                                    document.number
                                                                }
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                                {replacement && (
                                                    <DocumentFacts
                                                        document={replacement}
                                                    />
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`${prefix}-reason`}
                                                >
                                                    Reason for correction
                                                </Label>
                                                <Input
                                                    id={`${prefix}-reason`}
                                                    name="reason"
                                                    required
                                                    maxLength={500}
                                                    aria-invalid={
                                                        !!errors.reason
                                                    }
                                                />
                                            </div>
                                            <section className="space-y-1 text-sm">
                                                <h3 className="font-semibold">
                                                    Business and contact details
                                                </h3>
                                                <p>{view.business.name}</p>
                                                <p>
                                                    {view.business.email} /{' '}
                                                    {view.business.phone}
                                                </p>
                                                <p>
                                                    Z-number{' '}
                                                    {view.business.znumber} /
                                                    Unit {view.business.unitId}
                                                </p>
                                            </section>
                                            <div className="flex items-start gap-3">
                                                <Checkbox
                                                    id={`${prefix}-confirmed`}
                                                    name="confirmed"
                                                    value="1"
                                                    required
                                                />
                                                <Label
                                                    htmlFor={`${prefix}-confirmed`}
                                                    className="leading-relaxed"
                                                >
                                                    I have reviewed both issued
                                                    numbers, the reason and the
                                                    business/contact details.
                                                </Label>
                                            </div>
                                            <DialogFooter>
                                                <DialogClose asChild>
                                                    <Button
                                                        variant="outline"
                                                        type="button"
                                                    >
                                                        Cancel
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        processing ||
                                                        !replacement
                                                    }
                                                >
                                                    {processing && <Spinner />}
                                                    {processing
                                                        ? 'Submitting...'
                                                        : 'Confirm correction'}
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            )}
                            {(view.refusal ||
                                view.replacements.length === 0) && (
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="outline">Close</Button>
                                    </DialogClose>
                                </DialogFooter>
                            )}
                        </DialogContent>
                    </Dialog>
                </div>
            )}
        </div>
    );
}

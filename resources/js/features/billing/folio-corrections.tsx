import { ChevronDown } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Identifier } from '@/components/ui/identifier';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/utils';
import { reverse, transfer } from '@/routes/postings';
import { store as openFolio } from '@/routes/reservations/folios';
import type { Folio, Posting } from './folio-panel';

type CorrectionProps = {
    reservation: { reservation_number: string };
    canReverse: boolean;
    canTransfer: boolean;
    canOpenFolio: boolean;
    folioCurrencies: string[];
};

const linkLabels: Record<string, string> = {
    reversal: 'Reversal of',
    transfer_out: 'Moved out',
    transfer_in: 'Moved in from',
};

/** Names a folio the way the panel heads it: its position and currency. */
export function folioName(folios: Folio[], id: string) {
    const index = folios.findIndex((folio) => folio.id === id);

    return index === -1
        ? `folio ${id}`
        : `folio ${folios[index].number} (${folios[index].currency})`;
}

/** The reversal or transfer this posting belongs to, read from both ends. */
export function PostingLink({ posting }: { posting: Posting }) {
    const lines: React.ReactNode[] = [];
    if (posting.related) {
        lines.push(
            posting.type === 'transfer_in' ? (
                <>
                    {linkLabels.transfer_in} folio{' '}
                    <Identifier value={posting.related.folio_id} copyable />
                </>
            ) : posting.type === 'refund' ? (
                `Refund of the ${posting.related.category}`
            ) : (
                `${linkLabels[posting.type] ?? 'Linked to'} the ${posting.related.category} charge`
            ),
        );
    }
    if (posting.cancelled_by) {
        lines.push(
            posting.cancelled_by.type === 'reversal' ? (
                'Reversed'
            ) : posting.cancelled_by.type === 'refund' ? (
                'Refunded'
            ) : posting.cancelled_by.destination_folio_id === null ? (
                linkLabels.transfer_out
            ) : (
                <>
                    Moved to folio{' '}
                    <Identifier
                        value={posting.cancelled_by.destination_folio_id}
                        copyable
                    />
                </>
            ),
        );
    }
    if (posting.reason) {
        lines.push(`Reason: ${posting.reason}`);
    }

    return lines.map((line, index) => (
        <div key={index} className="text-sm text-muted-foreground">
            {line}
        </div>
    ));
}

/** Only charges and moved-in charges that nothing has cancelled yet can be corrected. */
function correctable(posting: Posting) {
    return (
        posting.cancelled_by === null &&
        !['reversal', 'transfer_out'].includes(posting.type)
    );
}

export function PostingActions({
    posting,
    folio,
    folios,
}: {
    posting: Posting;
    folio: Folio;
    folios: Folio[];
}) {
    const { canReverse, canTransfer } = usePage<CorrectionProps>().props;
    const targets = folios.filter(
        (other) => other.id !== folio.id && other.currency === folio.currency,
    );
    if (!correctable(posting)) {
        return null;
    }

    return (
        <div className="flex justify-end gap-2">
            {canTransfer && (
                <CorrectionDialog
                    action={transfer.form(posting.id)}
                    trigger="Move"
                    title={
                        <>
                            Move posting <Identifier value={posting.id} /> to
                            another folio?
                        </>
                    }
                    description={`${formatMoney(posting.amount, folio.currency)} leaves this folio and is posted to the folio you choose. Both postings stay on record.`}
                    submit="Move charge"
                    target={targets.map((other) => ({
                        id: other.id,
                        label: folioName(folios, other.id),
                    }))}
                    idPrefix={`transfer-${posting.id}`}
                />
            )}
            {canReverse && (
                <CorrectionDialog
                    action={reverse.form(posting.id)}
                    trigger="Reverse"
                    title={
                        <>
                            Reverse posting <Identifier value={posting.id} />?
                        </>
                    }
                    description={`A reversal of ${formatMoney(-posting.amount, folio.currency)} is posted and linked to it. The original stays on the folio; post a new charge afterwards if one is needed.`}
                    submit="Post reversal"
                    destructive
                    idPrefix={`reverse-${posting.id}`}
                />
            )}
        </div>
    );
}

function CorrectionDialog({
    action,
    trigger,
    title,
    description,
    submit,
    destructive = false,
    target,
    idPrefix,
}: {
    action: { action: string; method: 'post' };
    trigger: string;
    title: React.ReactNode;
    description: string;
    submit: string;
    destructive?: boolean;
    target?: { id: string; label: string }[];
    idPrefix: string;
}) {
    const [targetId, setTargetId] = useState(target?.[0]?.id ?? 'other');
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="secondary">
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <Form
                    {...action}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            {target && (
                                <div className="space-y-2">
                                    <Label htmlFor={`${idPrefix}-folio`}>
                                        To folio
                                    </Label>
                                    <NativeSelect
                                        id={`${idPrefix}-folio`}
                                        name={
                                            targetId === 'other'
                                                ? undefined
                                                : 'folio_id'
                                        }
                                        value={targetId}
                                        onChange={(event) =>
                                            setTargetId(event.target.value)
                                        }
                                        required
                                        aria-invalid={Boolean(errors.folio_id)}
                                    >
                                        {target.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                        <option value="other">
                                            Another folio by ID
                                        </option>
                                    </NativeSelect>
                                    {targetId === 'other' && (
                                        <>
                                            <Label
                                                htmlFor={`${idPrefix}-folio-id`}
                                            >
                                                Folio ID
                                            </Label>
                                            <Input
                                                id={`${idPrefix}-folio-id`}
                                                name="folio_id"
                                                required
                                                maxLength={26}
                                                aria-invalid={Boolean(
                                                    errors.folio_id,
                                                )}
                                                aria-describedby={`${idPrefix}-folio-help`}
                                            />
                                            <p
                                                id={`${idPrefix}-folio-help`}
                                                className="text-sm text-muted-foreground"
                                            >
                                                Copy the ID from the destination
                                                folio, including one on another
                                                page. It must use the same
                                                currency.
                                            </p>
                                        </>
                                    )}
                                    <InputError message={errors.folio_id} />
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor={`${idPrefix}-reason`}>
                                    Reason
                                </Label>
                                <Input
                                    id={`${idPrefix}-reason`}
                                    name="reason"
                                    required
                                    maxLength={255}
                                    aria-invalid={Boolean(errors.reason)}
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <InputError message={errors.posting} />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                    type="submit"
                                >
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    {submit}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function OpenFolioForm() {
    const { reservation, canOpenFolio, folioCurrencies } =
        usePage<CorrectionProps>().props;
    if (!canOpenFolio) {
        return null;
    }

    return (
        <Collapsible>
            <CollapsibleTrigger asChild>
                <Button variant="secondary" className="group min-h-11">
                    Open another folio
                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-4">
                <Form
                    {...openFolio.form(reservation.reservation_number)}
                    options={{ preserveScroll: true }}
                    className="flex max-w-[720px] flex-wrap items-end gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <div className="space-y-2">
                                <Label htmlFor="open-folio-currency">
                                    Open another folio in
                                </Label>
                                <NativeSelect
                                    id="open-folio-currency"
                                    name="currency"
                                    required
                                    aria-invalid={Boolean(errors.currency)}
                                >
                                    {folioCurrencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <Button variant="secondary" disabled={processing}>
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                Open folio
                            </Button>
                            <InputError
                                message={errors.currency}
                                className="w-full"
                            />
                        </>
                    )}
                </Form>
            </CollapsibleContent>
        </Collapsible>
    );
}

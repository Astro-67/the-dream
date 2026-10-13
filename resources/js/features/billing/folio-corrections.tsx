import { Form, usePage } from '@inertiajs/react';
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
        ? 'another folio'
        : `folio ${index + 1} (${folios[index].currency})`;
}

/** The reversal or transfer this posting belongs to, read from both ends. */
export function PostingLink({
    posting,
    folios,
}: {
    posting: Posting;
    folios: Folio[];
}) {
    const lines: string[] = [];
    if (posting.related) {
        lines.push(
            posting.type === 'transfer_in'
                ? `${linkLabels.transfer_in} ${folioName(folios, posting.related.folio_id)}`
                : `${linkLabels[posting.type] ?? 'Linked to'} the ${posting.related.category} charge`,
        );
    }
    if (posting.cancelled_by) {
        lines.push(
            posting.cancelled_by.type === 'reversal'
                ? 'Reversed'
                : `Moved to ${folioName(folios, posting.cancelled_by.folio_id)}`,
        );
    }
    if (posting.reason) {
        lines.push(`Reason: ${posting.reason}`);
    }

    return lines.map((line) => (
        <div key={line} className="text-sm text-muted-foreground">
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
            {canTransfer && targets.length > 0 && (
                <CorrectionDialog
                    action={transfer.form(posting.id)}
                    trigger="Move"
                    title="Move this charge to another folio?"
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
                    title="Reverse this posting?"
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
    title: string;
    description: string;
    submit: string;
    destructive?: boolean;
    target?: { id: string; label: string }[];
    idPrefix: string;
}) {
    return (
        <Dialog>
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
                                        name="folio_id"
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
                                    </NativeSelect>
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
                        Open folio
                    </Button>
                    <InputError message={errors.currency} className="w-full" />
                </>
            )}
        </Form>
    );
}

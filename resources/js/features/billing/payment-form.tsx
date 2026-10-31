import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Input } from '@/components/ui/input';
import { MoneyInput, toMinorUnits } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { store } from '@/routes/folios/payments';
import {
    dueLine,
    paymentStatus,
    statusFor,
    type FolioTotal,
} from './folio-summary';
import { StatusChip } from '@/components/ui/status-chip';

// Cash needs the cashier's open shift; the server refuses it otherwise.
export const paymentMethods = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    card: 'Card charged outside Thedream',
    channel_collected: 'Channel-collected',
    other: 'Other',
};

export const paymentMethodLabel = (method: string) =>
    paymentMethods[method as keyof typeof paymentMethods] ?? method;

export function PaymentForm({
    folio,
    total,
    channelCollectedAmount,
    acceptedMethods,
    onSuccess,
}: {
    folio: { id: string; currency: string };
    total: FolioTotal;
    channelCollectedAmount: number | null;
    acceptedMethods: string[];
    onSuccess?: () => void;
}) {
    // One key per filled-in form, so a resubmission of the same form posts once.
    const [key, setKey] = useState(() => crypto.randomUUID());
    const id = (field: string) => `payment-${folio.id}-${field}`;
    const methods = Object.entries(paymentMethods).filter(
        ([value]) =>
            acceptedMethods.includes(value) &&
            (channelCollectedAmount !== null || value !== 'channel_collected'),
    );
    const [selectedMethod, setMethod] = useState('bank_transfer');
    const method = methods.some(([value]) => value === selectedMethod)
        ? selectedMethod
        : (methods[0]?.[0] ?? '');
    const [amount, setAmount] = useState<number | ''>(
        total.due > 0 ? total.due : '',
    );
    const paymentAmount =
        method === 'channel_collected' ? channelCollectedAmount : amount;
    const afterDue = total.due - Number(paymentAmount);
    const afterStatus =
        paymentStatus[
            statusFor(afterDue, total.net_payments + Number(paymentAmount))
        ];
    const after = dueLine(afterDue, folio.currency);

    return (
        <Form
            {...store.form(folio.id)}
            onSuccess={() => {
                setKey(crypto.randomUUID());
                onSuccess?.();
            }}
            className="grid max-w-[720px] gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <input type="hidden" name="idempotency_key" value={key} />
                    <div className="space-y-2">
                        <Label htmlFor={id('method')}>Method</Label>
                        <NativeSelect
                            id={id('method')}
                            name="method"
                            value={method}
                            onChange={(event) => setMethod(event.target.value)}
                            required
                            aria-invalid={Boolean(errors.method)}
                        >
                            {methods.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.method} />
                    </div>
                    {method === 'channel_collected' ? (
                        <p className="self-end text-sm text-muted-foreground">
                            Posts the channel-collected amount recorded on the
                            reservation.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            <Label htmlFor={id('amount')}>
                                Amount in {folio.currency}
                            </Label>
                            <MoneyInput
                                id={id('amount')}
                                name="amount"
                                currency={folio.currency}
                                defaultValue={amount}
                                onChange={(event) =>
                                    setAmount(
                                        toMinorUnits(
                                            event.target.value,
                                            folio.currency,
                                        ),
                                    )
                                }
                                min={1}
                                required
                                aria-invalid={Boolean(errors.amount)}
                            />
                            <InputError message={errors.amount} />
                        </div>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor={id('reference')}>Reference</Label>
                        <Input
                            id={id('reference')}
                            name="reference"
                            maxLength={255}
                            aria-invalid={Boolean(errors.reference)}
                        />
                        <InputError message={errors.reference} />
                    </div>
                    {method === 'card' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor={id('card_last_four')}>
                                    Card last four digits
                                </Label>
                                <Input
                                    id={id('card_last_four')}
                                    name="card_last_four"
                                    inputMode="numeric"
                                    pattern="[0-9]{4}"
                                    maxLength={4}
                                    autoComplete="off"
                                    aria-invalid={Boolean(
                                        errors.card_last_four,
                                    )}
                                />
                                <InputError message={errors.card_last_four} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={id('card_brand')}>
                                    Card brand
                                </Label>
                                <Input
                                    id={id('card_brand')}
                                    name="card_brand"
                                    maxLength={20}
                                    autoComplete="off"
                                    aria-invalid={Boolean(errors.card_brand)}
                                />
                                <InputError message={errors.card_brand} />
                            </div>
                        </>
                    )}
                    <InputError
                        message={errors.idempotency_key ?? errors.currency}
                        className="sm:col-span-2"
                    />
                    {paymentAmount !== null &&
                        paymentAmount !== '' &&
                        paymentAmount > 0 && (
                            <div
                                aria-live="polite"
                                className="space-y-2 sm:col-span-2"
                            >
                                <p className="text-sm font-medium">
                                    After this payment
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="tabular-nums">
                                        {after.label}: {after.amount}
                                    </span>
                                    <StatusChip tone={afterStatus.tone}>
                                        {afterStatus.label}
                                    </StatusChip>
                                </div>
                                {paymentAmount > total.due && (
                                    <p className="text-sm text-status-warning">
                                        This exceeds Due. Saving will leave a
                                        credit on this folio.
                                    </p>
                                )}
                            </div>
                        )}
                    <div className="sm:col-span-2">
                        <Button disabled={processing || methods.length === 0}>
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Record payment
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

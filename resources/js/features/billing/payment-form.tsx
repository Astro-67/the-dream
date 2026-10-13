import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { store } from '@/routes/folios/payments';

// Cash needs the cashier's open shift; the server refuses it otherwise.
export const paymentMethods = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    card: 'Card charged outside Thedream',
    channel_collected: 'Channel-collected',
};

export function PaymentForm({
    folio,
    channelCollected,
}: {
    folio: { id: string; currency: string };
    channelCollected: boolean;
}) {
    // One key per filled-in form, so a resubmission of the same form posts once.
    const [key, setKey] = useState(() => crypto.randomUUID());
    const [method, setMethod] = useState('bank_transfer');
    const id = (field: string) => `payment-${folio.id}-${field}`;
    const methods = Object.entries(paymentMethods).filter(
        ([value]) => channelCollected || value !== 'channel_collected',
    );

    return (
        <Form
            {...store.form(folio.id)}
            onSuccess={() => setKey(crypto.randomUUID())}
            className="grid max-w-[720px] gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <input type="hidden" name="idempotency_key" value={key} />
                    <h3 className="font-semibold sm:col-span-2">
                        Record a payment
                    </h3>
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
                                Amount in {folio.currency} minor units
                            </Label>
                            <Input
                                id={id('amount')}
                                name="amount"
                                type="number"
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
                    <div className="sm:col-span-2">
                        <Button disabled={processing}>Record payment</Button>
                    </div>
                </>
            )}
        </Form>
    );
}

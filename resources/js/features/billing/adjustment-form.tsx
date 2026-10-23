import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { store as refund } from '@/routes/folios/refunds';
import { store as writeOff } from '@/routes/folios/write-offs';
import { paymentMethods } from './payment-form';
import type { Folio } from './folio-panel';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/utils';

export function AdjustmentForms({ folio }: { folio: Folio }) {
    const { canRefund, canWriteOff } = usePage<{
        canRefund: boolean;
        canWriteOff: boolean;
    }>().props;

    if (!canRefund && !(canWriteOff && folio.balance > 0)) return null;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="secondary" className="min-h-11">
                    Refunds and write-offs
                </Button>
            </DialogTrigger>
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-[720px]"
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>Refunds and write-offs</DialogTitle>
                </DialogHeader>
                <div className="space-y-6 md:space-y-8">
                    {canRefund && (
                        <AdjustmentForm folio={folio} type="refund" />
                    )}
                    {canWriteOff && folio.balance > 0 && (
                        <AdjustmentForm folio={folio} type="write_off" />
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function AdjustmentForm({
    folio,
    type,
}: {
    folio: Folio;
    type: 'refund' | 'write_off';
}) {
    const [key, setKey] = useState(() => crypto.randomUUID());
    const id = (field: string) => `${type}-${folio.id}-${field}`;
    const label = type === 'refund' ? 'Refund' : 'Write-off';
    // shortcut: lists payments on the folio's loaded postings page only; page through payments if folios outgrow 50 postings.
    const payments = folio.postings.data.filter(
        (posting) =>
            posting.type === 'payment' && posting.cancelled_by === null,
    );

    return (
        <Form
            {...(type === 'refund' ? refund : writeOff).form(folio.id)}
            // Remount on success so the amount clears with the rotated key.
            key={key}
            onSuccess={() => setKey(crypto.randomUUID())}
            resetOnSuccess={['amount', 'reason', 'reference']}
            className="grid max-w-[720px] gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <input type="hidden" name="idempotency_key" value={key} />
                    <h3 className="font-semibold sm:col-span-2">{label}</h3>
                    <p className="text-sm text-muted-foreground sm:col-span-2">
                        A reason is required. Above the currency threshold, this
                        request waits for a different approver and does not
                        change the balance.
                    </p>
                    <div className="space-y-2">
                        <Label htmlFor={id('amount')}>
                            Amount in {folio.currency}
                        </Label>
                        <MoneyInput
                            id={id('amount')}
                            name="amount"
                            currency={folio.currency}
                            min={1}
                            max={
                                type === 'write_off'
                                    ? folio.balance
                                    : 1000000000000
                            }
                            required
                            aria-invalid={Boolean(errors.amount)}
                        />
                        <InputError message={errors.amount} />
                    </div>
                    {type === 'refund' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor={id('method')}>
                                    Refund method
                                </Label>
                                <NativeSelect
                                    id={id('method')}
                                    name="method"
                                    defaultValue="bank_transfer"
                                    required
                                    aria-invalid={Boolean(errors.method)}
                                >
                                    {Object.entries(paymentMethods).map(
                                        ([value, name]) => (
                                            <option key={value} value={value}>
                                                {name}
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                                <InputError message={errors.method} />
                            </div>
                            {payments.length > 0 && (
                                <div className="space-y-2">
                                    <Label htmlFor={id('payment')}>
                                        Payment refunded (optional)
                                    </Label>
                                    <NativeSelect
                                        id={id('payment')}
                                        name="payment_posting_id"
                                        defaultValue=""
                                        aria-invalid={Boolean(
                                            errors.payment_posting_id,
                                        )}
                                    >
                                        <option value="">Not linked</option>
                                        {payments.map((payment) => (
                                            <option
                                                key={payment.id}
                                                value={payment.id}
                                            >
                                                {payment.receipt?.number ??
                                                    'Payment'}
                                                ,{' '}
                                                {formatMoney(
                                                    -payment.amount,
                                                    folio.currency,
                                                )}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError
                                        message={errors.payment_posting_id}
                                    />
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor={id('reference')}>
                                    Reference (optional)
                                </Label>
                                <Input
                                    id={id('reference')}
                                    name="reference"
                                    maxLength={255}
                                />
                                <InputError message={errors.reference} />
                            </div>
                        </>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor={id('reason')}>Reason</Label>
                        <Input
                            id={id('reason')}
                            name="reason"
                            required
                            maxLength={255}
                            aria-invalid={Boolean(errors.reason)}
                        />
                        <InputError message={errors.reason} />
                    </div>
                    <div className="sm:col-span-2">
                        <InputError
                            message={errors.idempotency_key ?? errors._property}
                        />
                        <Button variant="outline" disabled={processing}>
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Request {label.toLowerCase()}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

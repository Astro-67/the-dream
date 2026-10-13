import { Form, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
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
    cancelled_by: { id: string; type: string; folio_id: string } | null;
    quantity: number;
    unit_price: number;
    net: number;
    amount: number;
    tax_lines: { name: string; amount: number }[];
    posted_at: string;
    actor: string | null;
    method?: string | null;
    reference?: string | null;
    receipt?: { id: string; number: string } | null;
};
export type Folio = {
    id: string;
    currency: string;
    balance: number;
    postings: Posting[];
};
export type FolioTotal = { currency: string; charges: number; balance: number };

const categories = {
    minibar: 'Minibar',
    laundry: 'Laundry',
    other: 'Other',
    deposit: 'Deposit',
    payment: 'Payment',
    refund: 'Refund',
    write_off: 'Write-off',
};

export function FolioPanel({
    folios,
    totals,
    canPostCharge,
    canRecordPayment = false,
    channelCollected = false,
}: {
    folios: Folio[];
    totals: FolioTotal[];
    canPostCharge: boolean;
    canRecordPayment?: boolean;
    channelCollected?: boolean;
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">Folio</h2>
            <dl className="flex flex-wrap gap-6">
                {totals.map((total) => (
                    <div key={total.currency}>
                        <dt className="text-sm text-muted-foreground">
                            Balance {total.currency}
                        </dt>
                        <dd className="text-xl font-semibold tabular-nums">
                            {formatMoney(total.balance, total.currency)}
                        </dd>
                        <dd className="text-sm text-muted-foreground tabular-nums">
                            Charges {formatMoney(total.charges, total.currency)}
                        </dd>
                    </div>
                ))}
            </dl>
            {folios.map((folio, index) => (
                <div key={folio.id} className="space-y-3">
                    <AdjustmentForms folio={folio} />
                    {folios.length > 1 && (
                        <h3 className="font-semibold">
                            Folio {index + 1} ({folio.currency})
                        </h3>
                    )}
                    {folio.postings.length === 0 ? (
                        <EmptyState message="Nothing posted yet. Post an extra below when the guest uses one." />
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
                                {folio.postings.map((posting) => (
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
                                            {categories[
                                                posting.category as keyof typeof categories
                                            ] ?? posting.category}
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
                                                    {posting.receipt.number}
                                                </Link>
                                            )}
                                            {posting.description && (
                                                <div className="text-sm text-muted-foreground">
                                                    {posting.description}
                                                </div>
                                            )}
                                            <PostingLink
                                                posting={posting}
                                                folios={folios}
                                            />
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
                                                    {formatMoney(
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
                    {canPostCharge && <ExtraChargeForm folio={folio} />}
                    {canRecordPayment && (
                        <PaymentForm
                            folio={folio}
                            channelCollected={channelCollected}
                        />
                    )}
                </div>
            ))}
            <OpenFolioForm />
        </section>
    );
}

function ExtraChargeForm({ folio }: { folio: Folio }) {
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
                    <h3 className="font-semibold sm:col-span-2">
                        Post an extra
                    </h3>
                    <div className="space-y-2">
                        <Label htmlFor={id('category')}>Item</Label>
                        <NativeSelect
                            id={id('category')}
                            name="category"
                            required
                            aria-invalid={Boolean(errors.category)}
                        >
                            {Object.entries(categories).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </NativeSelect>
                        <InputError message={errors.category} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={id('description')}>Description</Label>
                        <Input
                            id={id('description')}
                            name="description"
                            maxLength={255}
                            aria-invalid={Boolean(errors.description)}
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={id('quantity')}>Quantity</Label>
                        <Input
                            id={id('quantity')}
                            name="quantity"
                            type="number"
                            min={1}
                            max={1000}
                            defaultValue={1}
                            required
                            aria-invalid={Boolean(errors.quantity)}
                        />
                        <InputError message={errors.quantity} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={id('unit_price')}>
                            Unit price in {folio.currency} minor units
                        </Label>
                        <Input
                            id={id('unit_price')}
                            name="unit_price"
                            type="number"
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
                        <Button disabled={processing}>Post charge</Button>
                    </div>
                </>
            )}
        </Form>
    );
}

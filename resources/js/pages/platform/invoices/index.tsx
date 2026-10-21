import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { SaasInvoiceStatus } from '@/features/subscriptions/saas-invoice-status';
import type { SaasInvoice } from '@/features/subscriptions/saas-invoice-status';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { index, store } from '@/routes/platform/invoices';
import { store as storePayment } from '@/routes/platform/invoices/payments';
import type { Paginated, SelectOption } from '@/types';

type Invoice = SaasInvoice & { organization: string };

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

function PaymentForm({
    invoice,
    methods,
    onDone,
}: {
    invoice: Invoice;
    methods: SelectOption[];
    onDone: () => void;
}) {
    const id = (name: string) => `${invoice.id}-${name}`;

    return (
        <Form
            {...storePayment.form(invoice.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-4 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={id('amount')}>
                            Amount ({invoice.currency})
                        </Label>
                        <MoneyInput
                            id={id('amount')}
                            name="amount"
                            currency={invoice.currency}
                            min={1}
                            max={invoice.balance}
                            required
                            defaultValue={invoice.balance}
                            aria-invalid={!!errors.amount}
                        />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={id('method')}>Method</Label>
                        <select
                            id={id('method')}
                            name="method"
                            className={selectClass}
                        >
                            {methods.map((method) => (
                                <option key={method.value} value={method.value}>
                                    {method.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.method} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={id('reference')}>Reference</Label>
                        <Input
                            id={id('reference')}
                            name="reference"
                            required
                            maxLength={100}
                            className="font-mono"
                            aria-invalid={!!errors.reference}
                        />
                        <InputError message={errors.reference} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={id('received_on')}>Received on</Label>
                        <Input
                            id={id('received_on')}
                            name="received_on"
                            type="date"
                            required
                            defaultValue={new Date().toISOString().slice(0, 10)}
                            aria-invalid={!!errors.received_on}
                        />
                        <InputError message={errors.received_on} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Record payment
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function PlatformInvoices({
    invoices,
    organizations,
    methods,
}: {
    invoices: Paginated<Invoice>;
    organizations: { id: string; name: string }[];
    methods: SelectOption[];
}) {
    const [paying, setPaying] = useState<string | null>(null);

    return (
        <>
            <Head title="SaaS invoices" />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        SaaS invoices
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Thedream's invoices to organizations, one per billing
                        period. Separate from guest folios and receipts.
                        Settling an organization's open invoice makes its
                        subscription active.
                    </p>
                </header>

                {invoices.data.length === 0 ? (
                    <EmptyState message="No invoices yet. Issue one below." />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Organization</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Due</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                    <TableHead numeric>Balance</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoices.data.map((invoice) => (
                                    <TableRow key={invoice.id}>
                                        <TableCell>
                                            <Identifier
                                                value={invoice.number}
                                                copyable
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {invoice.organization}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(invoice.periodStartsAt)}{' '}
                                            – {formatDate(invoice.periodEndsAt)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(invoice.dueAt)}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                invoice.amount,
                                                invoice.currency,
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                invoice.balance,
                                                invoice.currency,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <SaasInvoiceStatus
                                                invoice={invoice}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {invoice.status === 'open' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setPaying(
                                                            paying ===
                                                                invoice.id
                                                                ? null
                                                                : invoice.id,
                                                        )
                                                    }
                                                >
                                                    {paying === invoice.id
                                                        ? 'Close'
                                                        : 'Record payment'}
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={invoices} />
                    </div>
                )}

                {invoices.data
                    .filter((invoice) => invoice.id === paying)
                    .map((invoice) => (
                        <section
                            key={invoice.id}
                            className="max-w-[720px] space-y-4 rounded-xl border bg-card p-5"
                        >
                            <h2 className="text-lg font-semibold tracking-tight">
                                Payment for {invoice.number} ·{' '}
                                {invoice.organization}
                            </h2>
                            <PaymentForm
                                invoice={invoice}
                                methods={methods}
                                onDone={() => setPaying(null)}
                            />
                        </section>
                    ))}

                <section className="max-w-[720px] space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="text-lg font-semibold tracking-tight">
                        Issue the next invoice
                    </h2>
                    <Form
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid min-w-64 flex-1 gap-2">
                                    <Label htmlFor="invoice-organization">
                                        Organization
                                    </Label>
                                    <select
                                        id="invoice-organization"
                                        name="organization_id"
                                        required
                                        className={selectClass}
                                    >
                                        {organizations.map((organization) => (
                                            <option
                                                key={organization.id}
                                                value={organization.id}
                                            >
                                                {organization.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.organization_id}
                                    />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Issue invoice
                                </Button>
                            </>
                        )}
                    </Form>
                    <p className="text-sm text-muted-foreground">
                        Covers the period after the last one invoiced, at the
                        plan's current price, due when the period starts.
                    </p>
                </section>
            </div>
        </>
    );
}

PlatformInvoices.layout = {
    breadcrumbs: [{ title: 'SaaS invoices', href: index() }],
};

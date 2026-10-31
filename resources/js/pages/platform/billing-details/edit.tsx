import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/platform/billing-details';
import type { BillingAccount as Account } from '@/types';

type Details = {
    business_name: string;
    address: string;
    phone: string;
    email: string;
    tin: string;
    vrn: string | null;
    accounts: Account[];
};

const fields: [keyof Details, string, boolean][] = [
    ['business_name', 'Business name', true],
    ['address', 'Address', true],
    ['phone', 'Phone', true],
    ['email', 'Email', true],
    ['tin', 'TIN', true],
    ['vrn', 'VRN', false],
];

const accountFields: Record<
    Account['type'],
    [keyof Account, string, boolean][]
> = {
    bank: [
        ['bank', 'Bank', true],
        ['branch', 'Branch', true],
        ['swift', 'SWIFT', false],
        ['account_name', 'Account name', true],
        ['account_number', 'Account number', true],
    ],
    mobile_money: [
        ['provider', 'Provider', true],
        ['account_name', 'Account name', true],
        ['account_number', 'Number', true],
    ],
};

export default function BillingDetailsEdit({
    details,
}: {
    details: Details | null;
}) {
    // Keys keep each row's inputs attached to it when an earlier row is removed.
    const [accounts, setAccounts] = useState(() =>
        (details?.accounts ?? []).map((account, key) => ({ key, account })),
    );
    const add = (type: Account['type']) =>
        setAccounts((rows) => [
            ...rows,
            {
                key: Math.max(-1, ...rows.map((row) => row.key)) + 1,
                account: { type, account_name: '', account_number: '' },
            },
        ]);

    return (
        <>
            <Head title="Billing details" />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Billing details
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Printed on every new SaaS invoice. Invoices already
                        issued keep the details they were issued with. Invoices
                        can't be issued until these details and at least one
                        payment account are saved.
                    </p>
                </header>

                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="flex max-w-[720px] flex-col gap-8"
                >
                    {({ processing, errors }) => (
                        <>
                            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                                <h2 className="text-lg font-semibold tracking-tight sm:col-span-2">
                                    Business
                                </h2>
                                {fields.map(([name, label, required]) => (
                                    <div key={name} className="grid gap-2">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            required={required}
                                            type={
                                                name === 'email'
                                                    ? 'email'
                                                    : 'text'
                                            }
                                            defaultValue={
                                                (details?.[name] as string) ??
                                                ''
                                            }
                                            aria-invalid={!!errors[name]}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </section>

                            <section className="space-y-4 rounded-xl border bg-card p-5">
                                <h2 className="text-lg font-semibold tracking-tight">
                                    Payment accounts
                                </h2>
                                {accounts.length === 0 && (
                                    <EmptyState message="No payment accounts yet. Add a bank or mobile-money account." />
                                )}
                                <InputError message={errors.accounts} />
                                {accounts.map(({ key, account }, index) => (
                                    <fieldset
                                        key={key}
                                        className="grid gap-4 rounded-md border p-4 sm:grid-cols-2"
                                    >
                                        <legend className="px-1 text-sm font-medium">
                                            {account.type === 'bank'
                                                ? 'Bank account'
                                                : 'Mobile-money account'}
                                        </legend>
                                        <input
                                            type="hidden"
                                            name={`accounts[${index}][type]`}
                                            value={account.type}
                                        />
                                        {accountFields[account.type].map(
                                            ([name, label, required]) => {
                                                const id = `account-${key}-${name}`;
                                                const error =
                                                    errors[
                                                        `accounts.${index}.${name}`
                                                    ];

                                                return (
                                                    <div
                                                        key={name}
                                                        className="grid gap-2"
                                                    >
                                                        <Label htmlFor={id}>
                                                            {label}
                                                        </Label>
                                                        <Input
                                                            id={id}
                                                            name={`accounts[${index}][${name}]`}
                                                            required={required}
                                                            className={
                                                                name ===
                                                                    'account_number' ||
                                                                name === 'swift'
                                                                    ? 'font-mono'
                                                                    : undefined
                                                            }
                                                            defaultValue={
                                                                account[name] ??
                                                                ''
                                                            }
                                                            aria-invalid={
                                                                !!error
                                                            }
                                                        />
                                                        <InputError
                                                            message={error}
                                                        />
                                                    </div>
                                                );
                                            },
                                        )}
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setAccounts((rows) =>
                                                        rows.filter(
                                                            (row) =>
                                                                row.key !== key,
                                                        ),
                                                    )
                                                }
                                            >
                                                Remove account
                                            </Button>
                                        </div>
                                    </fieldset>
                                ))}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => add('bank')}
                                    >
                                        Add bank account
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => add('mobile_money')}
                                    >
                                        Add mobile-money account
                                    </Button>
                                </div>
                            </section>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save billing details
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

BillingDetailsEdit.layout = {
    breadcrumbs: [{ title: 'Billing details', href: edit() }],
};

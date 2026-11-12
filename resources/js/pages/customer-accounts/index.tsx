import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip, Tag } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { postingCategories } from '@/features/billing/folio-panel';
import { formatDate, formatMoney } from '@/lib/utils';
import { index, show, store } from '@/routes/customer-accounts';
import type { Paginated } from '@/types';

export const accountKinds = { day_use: 'Day use', event: 'Event' };
type Account = {
    id: string;
    number: string;
    kind: keyof typeof accountKinds;
    customer_name: string;
    starts_on: string | null;
    ends_on: string | null;
    status: 'open' | 'closed';
    balances: { currency: string; amount: number }[];
    receiptStatus: string;
};

export default function Index({
    accounts,
    filters,
    canCreate,
    currencies,
    extraCategories,
}: {
    accounts: Paginated<Account>;
    filters: { kind?: string; status?: string };
    canCreate: boolean;
    currencies: string[];
    extraCategories: string[];
}) {
    return (
        <>
            <Head title="Day use & events" />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Day use & events"
                        description="Sales without a room: add items, take payment, then close and issue the receipt."
                    />
                    {canCreate && (
                        <div className="flex flex-wrap gap-2">
                            <NewAccount
                                kind="day_use"
                                currencies={currencies}
                                extraCategories={extraCategories}
                            />
                            <NewAccount
                                kind="event"
                                currencies={currencies}
                                extraCategories={extraCategories}
                            />
                        </div>
                    )}
                </div>
                <Form
                    {...index.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="space-y-2">
                        <Label htmlFor="kind-filter">Kind</Label>
                        <NativeSelect
                            id="kind-filter"
                            name="kind"
                            defaultValue={filters.kind ?? ''}
                        >
                            <option value="">All kinds</option>
                            <option value="day_use">Day use</option>
                            <option value="event">Event</option>
                        </NativeSelect>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="status-filter">Status</Label>
                        <NativeSelect
                            id="status-filter"
                            name="status"
                            defaultValue={filters.status ?? ''}
                        >
                            <option value="">All statuses</option>
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                        </NativeSelect>
                    </div>
                    <Button variant="outline">Apply filters</Button>
                </Form>
                {accounts.data.length === 0 ? (
                    <EmptyState message="No customer accounts match these filters. Open a day-use sale or event to start." />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead>Kind</TableHead>
                                    <TableHead>Customer</TableHead>
                                    <TableHead>Dates</TableHead>
                                    <TableHead numeric>Balance</TableHead>
                                    <TableHead>ZRA receipt</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accounts.data.map((account) => (
                                    <TableRow key={account.id}>
                                        <TableCell>
                                            <Link
                                                href={show(account.id)}
                                                className="underline underline-offset-4"
                                            >
                                                <Identifier
                                                    value={account.number}
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Tag>
                                                {accountKinds[account.kind]}
                                            </Tag>
                                        </TableCell>
                                        <TableCell>
                                            {account.customer_name}
                                        </TableCell>
                                        <TableCell>
                                            {account.starts_on
                                                ? `${formatDate(account.starts_on, 'UTC')}${account.ends_on ? ` to ${formatDate(account.ends_on, 'UTC')}` : ''}`
                                                : 'Not set'}
                                        </TableCell>
                                        <TableCell numeric>
                                            {account.balances.map((balance) => (
                                                <div key={balance.currency}>
                                                    {formatMoney(
                                                        balance.amount,
                                                        balance.currency,
                                                    )}
                                                </div>
                                            ))}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    account.receiptStatus ===
                                                    'Needs attention'
                                                        ? 'warning'
                                                        : 'info'
                                                }
                                            >
                                                {account.receiptStatus}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    account.status === 'open'
                                                        ? 'info'
                                                        : 'success'
                                                }
                                            >
                                                {account.status === 'open'
                                                    ? 'Open'
                                                    : 'Closed'}
                                            </StatusChip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={accounts} />
                    </>
                )}
            </div>
        </>
    );
}

function NewAccount({
    kind,
    currencies,
    extraCategories,
}: {
    kind: keyof typeof accountKinds;
    currencies: string[];
    extraCategories: string[];
}) {
    const [open, setOpen] = useState(false);
    const [currency, setCurrency] = useState(currencies[0] ?? 'USD');
    const [institution, setInstitution] = useState('');
    const [withItem, setWithItem] = useState(kind === 'day_use');
    const [itemKey, setItemKey] = useState(() => crypto.randomUUID());
    const title = kind === 'day_use' ? 'New day-use sale' : 'New event';
    const id = (field: string) => `${kind}-${field}`;
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant={kind === 'day_use' ? 'default' : 'outline'}>
                    {title}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        Open a customer account without reserving a room.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    onSuccess={() => {
                        setOpen(false);
                        setItemKey(crypto.randomUUID());
                    }}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <input type="hidden" name="kind" value={kind} />
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor={id('customer')}>
                                    Customer name
                                </Label>
                                <Input
                                    id={id('customer')}
                                    name="customer_name"
                                    defaultValue={
                                        kind === 'day_use'
                                            ? 'Cash customer'
                                            : ''
                                    }
                                    required
                                    maxLength={255}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={id('currency')}>Currency</Label>
                                <NativeSelect
                                    id={id('currency')}
                                    name="currency"
                                    value={currency}
                                    onChange={(event) =>
                                        setCurrency(event.target.value)
                                    }
                                >
                                    {currencies.map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={id('payer')}>
                                    Paying institution (optional)
                                </Label>
                                <NativeSelect
                                    id={id('payer')}
                                    name="paying_institution_type"
                                    value={institution}
                                    onChange={(event) =>
                                        setInstitution(event.target.value)
                                    }
                                >
                                    <option value="">Customer pays</option>
                                    <option value="government">
                                        Government
                                    </option>
                                    <option value="business">Business</option>
                                </NativeSelect>
                            </div>
                            {institution && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('institution-name')}>
                                            Institution name
                                        </Label>
                                        <Input
                                            id={id('institution-name')}
                                            name="paying_institution_name"
                                            required
                                            maxLength={255}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('zrb')}>
                                            ZRB number (optional)
                                        </Label>
                                        <Input
                                            id={id('zrb')}
                                            name="paying_institution_zrb_number"
                                            maxLength={100}
                                        />
                                    </div>
                                </>
                            )}
                            {kind === 'event' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('start')}>
                                            Start date (optional)
                                        </Label>
                                        <Input
                                            id={id('start')}
                                            type="date"
                                            name="starts_on"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('end')}>
                                            End date (optional)
                                        </Label>
                                        <Input
                                            id={id('end')}
                                            type="date"
                                            name="ends_on"
                                        />
                                    </div>
                                </>
                            )}
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor={id('note')}>
                                    Note (optional)
                                </Label>
                                <Input
                                    id={id('note')}
                                    name="note"
                                    maxLength={5000}
                                />
                            </div>
                            <div className="flex items-center gap-2 sm:col-span-2">
                                <Checkbox
                                    id={id('with-item')}
                                    checked={withItem}
                                    onCheckedChange={(value) =>
                                        setWithItem(value === true)
                                    }
                                />
                                <Label htmlFor={id('with-item')}>
                                    Add the first item now
                                </Label>
                            </div>
                            {withItem && (
                                <>
                                    <input
                                        type="hidden"
                                        name="items[0][idempotency_key]"
                                        value={itemKey}
                                    />
                                    <div className="space-y-2">
                                        <Label htmlFor={id('item')}>
                                            Item name
                                        </Label>
                                        <Input
                                            id={id('item')}
                                            name="items[0][description]"
                                            required
                                            maxLength={255}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('category')}>
                                            Category
                                        </Label>
                                        <NativeSelect
                                            id={id('category')}
                                            name="items[0][category]"
                                            defaultValue="other"
                                        >
                                            {extraCategories.map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {postingCategories[value] ??
                                                        value}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('quantity')}>
                                            Quantity
                                        </Label>
                                        <Input
                                            id={id('quantity')}
                                            name="items[0][quantity]"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            step={1}
                                            defaultValue={1}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('price')}>
                                            VAT-inclusive price in {currency}
                                        </Label>
                                        <MoneyInput
                                            id={id('price')}
                                            name="items[0][unit_price]"
                                            currency={currency}
                                            min={0}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={id('discount')}>
                                            Line discount in {currency}{' '}
                                            (optional)
                                        </Label>
                                        <MoneyInput
                                            id={id('discount')}
                                            name="items[0][discount]"
                                            currency={currency}
                                            min={0}
                                        />
                                    </div>
                                </>
                            )}
                            <div
                                className="space-y-2 sm:col-span-2"
                                role="alert"
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
                            <div className="sm:col-span-2">
                                <Button disabled={processing}>
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    Open account
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

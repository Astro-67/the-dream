import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { Form, Head, Link } from '@inertiajs/react';
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
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { ruleSize } from '@/lib/utils';
import { change, end, preview, store } from '@/routes/tax-rules';
import { Spinner } from '@/components/ui/spinner';

type Rule = {
    zra_tax_category: string | null;
    id: string;
    name: string;
    kind: 'percentage' | 'flat_per_person_night';
    rate: number | null;
    amount: number | null;
    currency: string | null;
    inclusive: boolean;
    applies_to: 'room' | 'extras' | 'both';
    rounding: 'half_up' | 'up' | 'down';
    effective_from: string;
    effective_to: string | null;
};

const appliesTo = {
    room: 'Room charges',
    extras: 'Extras',
    both: 'Rooms and extras',
};
const rounding = { half_up: 'Half up', up: 'Up', down: 'Down' };
const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3';

function Select({
    name,
    label,
    options,
    defaultValue,
    error,
    onChange,
}: {
    name: string;
    label: string;
    options: Record<string, string>;
    defaultValue?: string;
    error?: string;
    onChange?: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={name}>{label}</Label>
            <select
                id={name}
                name={name}
                defaultValue={defaultValue}
                onChange={(e) => onChange?.(e.target.value)}
                className={selectClass}
                aria-invalid={!!error}
            >
                {Object.entries(options).map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

/** The terms of a rule; a change keeps the name of the rule it changes. */
function RuleFields({
    rule,
    currencies,
    errors,
}: {
    rule?: Rule;
    currencies: string[];
    errors: Record<string, string | undefined>;
}) {
    const [kind, setKind] = useState(rule?.kind ?? 'percentage');
    const [currency, setCurrency] = useState(rule?.currency ?? currencies[0]);
    return (
        <>
            <PropertyIntent />
            <InputError message={errors._property} />
            {rule ? (
                <input type="hidden" name="name" value={rule.name} />
            ) : (
                <div className="space-y-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        maxLength={255}
                        aria-invalid={!!errors.name}
                    />
                    <InputError message={errors.name} />
                </div>
            )}
            <Select
                name="zra_tax_category"
                label="ZRA tax category"
                options={{
                    '': 'Not specified',
                    vat: 'VAT',
                    hotel_levy: 'Hotel levy',
                    tour_operator: 'Tour operator',
                    exempt: 'Exempt',
                }}
                defaultValue={rule?.zra_tax_category ?? ''}
                error={errors.zra_tax_category}
            />
            <Select
                name="kind"
                label="Kind"
                options={{
                    percentage: 'Percentage of the charge',
                    flat_per_person_night: 'Flat amount per guest per night',
                }}
                defaultValue={kind}
                onChange={(value) => setKind(value as Rule['kind'])}
                error={errors.kind}
            />
            {kind === 'percentage' ? (
                <div className="space-y-2">
                    <Label htmlFor="rate">Rate (%)</Label>
                    <Input
                        id="rate"
                        name="rate"
                        type="number"
                        step="0.01"
                        min={0}
                        max={100}
                        required
                        defaultValue={
                            rule?.rate != null ? rule.rate / 100 : undefined
                        }
                        className="tabular-nums"
                        aria-invalid={!!errors.rate}
                    />
                    <InputError message={errors.rate} />
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Select
                        name="currency"
                        label="Currency"
                        options={Object.fromEntries(
                            currencies.map((c) => [c, c]),
                        )}
                        defaultValue={rule?.currency ?? undefined}
                        onChange={setCurrency}
                        error={errors.currency}
                    />
                    <div className="space-y-2">
                        <Label htmlFor="amount">Amount in {currency}</Label>
                        <MoneyInput
                            id="amount"
                            name="amount"
                            currency={currency}
                            min={0}
                            required
                            defaultValue={rule?.amount ?? undefined}
                            className="tabular-nums"
                            aria-invalid={!!errors.amount}
                        />
                        <InputError message={errors.amount} />
                    </div>
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-3">
                <Select
                    name="inclusive"
                    label="Treatment"
                    options={{
                        0: 'Added to the price',
                        1: 'Included in the price',
                    }}
                    defaultValue={rule?.inclusive ? '1' : '0'}
                    error={errors.inclusive}
                />
                <Select
                    name="applies_to"
                    label="Applies to"
                    options={appliesTo}
                    defaultValue={rule?.applies_to ?? 'both'}
                    error={errors.applies_to}
                />
                <Select
                    name="rounding"
                    label="Rounding"
                    options={rounding}
                    defaultValue={rule?.rounding ?? 'half_up'}
                    error={errors.rounding}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="effective_from">
                        {rule ? 'New terms from' : 'In force from'}
                    </Label>
                    <Input
                        id="effective_from"
                        name="effective_from"
                        type="date"
                        required
                        aria-invalid={!!errors.effective_from}
                    />
                    <InputError message={errors.effective_from} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="effective_to">Until (optional)</Label>
                    <Input
                        id="effective_to"
                        name="effective_to"
                        type="date"
                        defaultValue={rule?.effective_to ?? undefined}
                        aria-invalid={!!errors.effective_to}
                    />
                    <InputError message={errors.effective_to} />
                </div>
            </div>
        </>
    );
}

function AddRule({ currencies }: { currencies: string[] }) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>Add tax rule</Button>
            </DialogTrigger>
            <DialogContent className="max-w-[720px]">
                <DialogTitle>Add tax rule</DialogTitle>
                <DialogDescription>
                    A tax or service charge in force from a date. Rules with the
                    same name cannot overlap.
                </DialogDescription>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <RuleFields
                                currencies={currencies}
                                errors={errors}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    Add tax rule
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ChangeRule({
    rule,
    currencies,
}: {
    rule: Rule;
    currencies: string[];
}) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Change
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-[720px]">
                <DialogTitle>Change {rule.name}</DialogTitle>
                <DialogDescription>
                    The current terms end the day before the new ones start.
                    Earlier dates keep their tax.
                </DialogDescription>
                <Form
                    {...change.form(rule.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <RuleFields
                                rule={rule}
                                currencies={currencies}
                                errors={errors}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    Save change
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EndRule({ rule }: { rule: Rule }) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    End
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>End {rule.name}?</DialogTitle>
                <DialogDescription>
                    It stops applying after the date you choose. Dates before it
                    keep their tax.
                </DialogDescription>
                <Form
                    {...end.form(rule.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <div className="space-y-2">
                                <Label htmlFor={`end-${rule.id}`}>
                                    Last day in force
                                </Label>
                                <Input
                                    id={`end-${rule.id}`}
                                    name="effective_to"
                                    type="date"
                                    required
                                    min={formatDate(rule.effective_from, 'UTC')}
                                    defaultValue={
                                        rule.effective_to ?? undefined
                                    }
                                    aria-invalid={!!errors.effective_to}
                                />
                                <InputError message={errors.effective_to} />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Keep it</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                >
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    End rule
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function TaxRules({
    taxRules,
    currencies,
    canManage,
}: {
    taxRules: Rule[];
    currencies: string[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Taxes and service charges" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Taxes and service charges
                    </h1>
                    <div className="flex flex-wrap gap-3">
                        <Button variant="outline" asChild>
                            <Link href={preview()}>Preview a breakdown</Link>
                        </Button>
                        {canManage && <AddRule currencies={currencies} />}
                    </div>
                </header>
                {taxRules.length === 0 ? (
                    <p className="text-muted-foreground">
                        No tax rules yet.{' '}
                        {canManage
                            ? 'Add a tax rule so charges carry their taxes.'
                            : ''}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {[
                                    'Name',
                                    'Amount',
                                    'Treatment',
                                    'Applies to',
                                    'Rounding',
                                    'In force',
                                ].map((label) => (
                                    <TableHead
                                        numeric={label === 'Amount'}
                                        key={label}
                                    >
                                        {label}
                                    </TableHead>
                                ))}
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {taxRules.map((rule) => (
                                <TableRow key={rule.id}>
                                    <TableCell>{rule.name}</TableCell>
                                    <TableCell numeric>
                                        {ruleSize(rule)}
                                    </TableCell>
                                    <TableCell>
                                        {rule.inclusive ? 'Included' : 'Added'}
                                    </TableCell>
                                    <TableCell>
                                        {appliesTo[rule.applies_to]}
                                    </TableCell>
                                    <TableCell>
                                        {rounding[rule.rounding]}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(rule.effective_from, 'UTC')}{' '}
                                        to{' '}
                                        {rule.effective_to
                                            ? formatDate(
                                                  rule.effective_to,
                                                  'UTC',
                                              )
                                            : 'no end date'}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap">
                                        {canManage && (
                                            <>
                                                <ChangeRule
                                                    rule={rule}
                                                    currencies={currencies}
                                                />
                                                <EndRule rule={rule} />
                                            </>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}

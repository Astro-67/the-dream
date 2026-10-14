import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tag } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/utils';
import { index, store, update } from '@/routes/platform/plans';
import type { SelectOption } from '@/types';

type Plan = {
    id: string;
    name: string;
    code: string;
    property_limit: number;
    room_limit: number;
    trial_days: number;
    grace_days: number;
    billing_period: string;
    billingPeriodLabel: string;
    price: number;
    currency: string;
    is_default: boolean;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

function PlanForm({
    plan,
    periods,
    currencies,
    onDone,
}: {
    plan?: Plan;
    periods: SelectOption[];
    currencies: string[];
    onDone?: () => void;
}) {
    const action = plan ? update.form(plan.id) : store.form();
    const number = (
        name: keyof Plan,
        label: string,
        errors: Record<string, string>,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`${plan?.id ?? 'new'}-${name}`}>{label}</Label>
            <Input
                id={`${plan?.id ?? 'new'}-${name}`}
                name={name}
                type="number"
                min={0}
                required
                defaultValue={plan ? String(plan[name]) : undefined}
                aria-invalid={!!errors[name]}
            />
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            resetOnSuccess={!plan}
            onSuccess={onDone}
            className="grid gap-4 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`${plan?.id ?? 'new'}-name`}>
                            Name
                        </Label>
                        <Input
                            id={`${plan?.id ?? 'new'}-name`}
                            name="name"
                            required
                            defaultValue={plan?.name}
                            aria-invalid={!!errors.name}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${plan?.id ?? 'new'}-code`}>
                            Code
                        </Label>
                        <Input
                            id={`${plan?.id ?? 'new'}-code`}
                            name="code"
                            required
                            className="font-mono"
                            defaultValue={plan?.code}
                            aria-invalid={!!errors.code}
                        />
                        <InputError message={errors.code} />
                    </div>
                    {number('property_limit', 'Property limit', errors)}
                    {number('room_limit', 'Room limit', errors)}
                    {number('trial_days', 'Trial length (days)', errors)}
                    {number('grace_days', 'Grace period (days)', errors)}
                    <div className="grid gap-2">
                        <Label htmlFor={`${plan?.id ?? 'new'}-billing_period`}>
                            Billing period
                        </Label>
                        <select
                            id={`${plan?.id ?? 'new'}-billing_period`}
                            name="billing_period"
                            defaultValue={plan?.billing_period ?? 'monthly'}
                            className={selectClass}
                        >
                            {periods.map((period) => (
                                <option key={period.value} value={period.value}>
                                    {period.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.billing_period} />
                    </div>
                    <div className="grid grid-cols-[1fr_6rem] gap-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`${plan?.id ?? 'new'}-price`}>
                                Price per period (minor units)
                            </Label>
                            <Input
                                id={`${plan?.id ?? 'new'}-price`}
                                name="price"
                                type="number"
                                min={0}
                                required
                                defaultValue={plan?.price}
                                aria-invalid={!!errors.price}
                            />
                            <InputError message={errors.price} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${plan?.id ?? 'new'}-currency`}>
                                Currency
                            </Label>
                            <select
                                id={`${plan?.id ?? 'new'}-currency`}
                                name="currency"
                                defaultValue={plan?.currency ?? 'USD'}
                                className={selectClass}
                            >
                                {currencies.map((currency) => (
                                    <option key={currency} value={currency}>
                                        {currency}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 sm:col-span-2">
                        <input type="hidden" name="is_default" value="0" />
                        <Checkbox
                            id={`${plan?.id ?? 'new'}-is_default`}
                            name="is_default"
                            value="1"
                            defaultChecked={plan?.is_default}
                        />
                        <Label htmlFor={`${plan?.id ?? 'new'}-is_default`}>
                            New organizations start their trial on this plan
                        </Label>
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {plan ? 'Save plan' : 'Add plan'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function PlatformPlans({
    plans,
    periods,
    currencies,
}: {
    plans: Plan[];
    periods: SelectOption[];
    currencies: string[];
}) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Plans" />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Plans
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Limits, trial length and billing for each plan. Lowering
                        a limit never removes anyone's properties or rooms; it
                        only stops them adding more.
                    </p>
                </header>

                {plans.length === 0 ? (
                    <EmptyState message="No plans yet. Add one below." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Plan</TableHead>
                                <TableHead numeric>Properties</TableHead>
                                <TableHead numeric>Rooms</TableHead>
                                <TableHead numeric>Trial days</TableHead>
                                <TableHead numeric>Grace days</TableHead>
                                <TableHead numeric>Price</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {plans.map((plan) => (
                                <TableRow key={plan.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-2 font-medium">
                                            {plan.name}
                                            {plan.is_default && (
                                                <Tag>Default</Tag>
                                            )}
                                        </div>
                                        <Identifier value={plan.code} />
                                    </TableCell>
                                    <TableCell numeric>
                                        {plan.property_limit}
                                    </TableCell>
                                    <TableCell numeric>
                                        {plan.room_limit}
                                    </TableCell>
                                    <TableCell numeric>
                                        {plan.trial_days}
                                    </TableCell>
                                    <TableCell numeric>
                                        {plan.grace_days}
                                    </TableCell>
                                    <TableCell numeric>
                                        {formatMoney(plan.price, plan.currency)}{' '}
                                        <span className="text-muted-foreground">
                                            {plan.billingPeriodLabel}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(
                                                    editing === plan.id
                                                        ? null
                                                        : plan.id,
                                                )
                                            }
                                        >
                                            {editing === plan.id
                                                ? 'Close'
                                                : 'Edit'}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                {plans
                    .filter((plan) => plan.id === editing)
                    .map((plan) => (
                        <section
                            key={plan.id}
                            className="max-w-[720px] space-y-4 rounded-xl border bg-card p-5"
                        >
                            <h2 className="text-lg font-semibold tracking-tight">
                                Edit {plan.name}
                            </h2>
                            <PlanForm
                                plan={plan}
                                periods={periods}
                                currencies={currencies}
                                onDone={() => setEditing(null)}
                            />
                        </section>
                    ))}

                <section className="max-w-[720px] space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="text-lg font-semibold tracking-tight">
                        Add a plan
                    </h2>
                    <PlanForm periods={periods} currencies={currencies} />
                </section>
            </div>
        </>
    );
}

PlatformPlans.layout = {
    breadcrumbs: [{ title: 'Plans', href: index() }],
};

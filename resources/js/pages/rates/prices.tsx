import { EmptyState } from '@/components/ui/empty-state';
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
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyInput, toMinorUnits } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, ruleSize } from '@/lib/utils';
import { useState } from 'react';
import {
    index,
    prices as pricesOf,
    quote,
    restrictions,
} from '@/routes/rate-plans';
import { update } from '@/routes/rate-plans/prices';
type Price = {
    room_type_id: string;
    date: string;
    base_price: number;
    extra_adult: number;
    extra_child: number;
    guest_pays: number | null;
};
type TaxLine = {
    name: string;
    kind: string;
    inclusive: boolean;
    rate: number | null;
    per_person_night: number | null;
    unavailable?: boolean;
    converted_from?: {
        currency: string;
        per_person_night: number;
        usd_tzs: string | null;
        eur_tzs: string | null;
        rate_date: string | null;
    };
};

/** The guest's night, as the server's tax calculator splits it: included taxes stay inside the price, the rest are added. */
function guestNight(price: number, guests: number, taxes: TaxLine[]) {
    const applying = taxes.filter((t) => !t.unavailable);
    const flat = (t: TaxLine) => (t.per_person_night ?? 0) * guests;
    const included = applying.filter((t) => t.inclusive);
    const base =
        price -
        included
            .filter((t) => t.kind !== 'percentage')
            .reduce((sum, t) => sum + flat(t), 0);
    const rate = included
        .filter((t) => t.kind === 'percentage')
        .reduce((sum, t) => sum + (t.rate ?? 0), 0);
    const lines = applying.map((t) => ({
        ...t,
        amount:
            t.kind === 'percentage'
                ? Math.round((base * (t.rate ?? 0)) / (10000 + rate))
                : flat(t),
    }));
    return {
        lines,
        levyPending: taxes.some((t) => t.unavailable),
        total:
            price +
            lines.filter((l) => !l.inclusive).reduce((s, l) => s + l.amount, 0),
    };
}

export default function Prices({
    plan,
    base,
    roomTypes,
    prices,
    taxes,
    canManage,
}: {
    plan: { id: string; name: string; currency: string };
    base: { id: string; name: string } | null;
    roomTypes: { id: string; name: string; base_occupancy: number }[];
    prices: Price[];
    taxes: TaxLine[];
    canManage: boolean;
}) {
    const money = (amount: number) => formatMoney(amount, plan.currency);
    const [entered, setEntered] = useState<number | ''>('');
    const [roomTypeId, setRoomTypeId] = useState(roomTypes[0]?.id ?? '');
    const guests =
        roomTypes.find((room) => room.id === roomTypeId)?.base_occupancy ?? 1;
    const night = entered === '' ? null : guestNight(entered, guests, taxes);
    return (
        <>
            <Head title={`${plan.name} prices`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {plan.name} prices
                    </h1>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={restrictions(plan.id)}>
                                Restrictions
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={quote(plan.id)}>Preview a quote</Link>
                        </Button>
                    </div>
                </header>
                {base && (
                    <p className="text-sm text-muted-foreground">
                        Derived from{' '}
                        <Link
                            href={pricesOf(base.id)}
                            className="underline underline-offset-4"
                        >
                            {base.name}
                        </Link>
                        . These prices follow its prices; edit them there.
                    </p>
                )}
                {canManage && (
                    <Form
                        {...update.form(plan.id)}
                        disableWhileProcessing
                        className="flex max-w-[720px] flex-col gap-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <PropertyIntent />
                                <InputError message={errors._property} />
                                <div className="space-y-2">
                                    <Label htmlFor="room_type_id">
                                        Room type
                                    </Label>
                                    <select
                                        id="room_type_id"
                                        name="room_type_id"
                                        value={roomTypeId}
                                        onChange={(event) =>
                                            setRoomTypeId(event.target.value)
                                        }
                                        className="h-9 w-full rounded-md border border-input bg-background px-3"
                                    >
                                        {roomTypes.map((room) => (
                                            <option
                                                key={room.id}
                                                value={room.id}
                                            >
                                                {room.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.room_type_id} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {[
                                        ['from', 'From (inclusive)'],
                                        ['to', 'To (inclusive)'],
                                    ].map(([name, label]) => (
                                        <div key={name} className="space-y-2">
                                            <Label htmlFor={name}>
                                                {label}
                                            </Label>
                                            <Input
                                                id={name}
                                                name={name}
                                                type="date"
                                                required
                                                aria-invalid={!!errors[name]}
                                            />
                                            <InputError
                                                message={errors[name]}
                                            />
                                        </div>
                                    ))}
                                </div>
                                <fieldset>
                                    <legend className="mb-2 text-sm font-medium">
                                        Weekdays
                                    </legend>
                                    <div className="flex flex-wrap gap-3">
                                        {[
                                            'Monday',
                                            'Tuesday',
                                            'Wednesday',
                                            'Thursday',
                                            'Friday',
                                            'Saturday',
                                            'Sunday',
                                        ].map((day, i) => (
                                            <label
                                                key={day}
                                                className="flex min-h-11 items-center gap-2"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="weekdays[]"
                                                    value={i + 1}
                                                    defaultChecked
                                                    className="size-4 accent-primary"
                                                />
                                                {day}
                                            </label>
                                        ))}
                                    </div>
                                    <InputError message={errors.weekdays} />
                                </fieldset>
                                <p className="text-sm text-muted-foreground">
                                    Amounts in {plan.currency}. Existing prices
                                    on selected dates are replaced.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    {[
                                        ['base_price', 'Base occupancy price'],
                                        ['extra_adult', 'Extra adult'],
                                        ['extra_child', 'Extra child'],
                                    ].map(([name, label]) => (
                                        <div key={name} className="space-y-2">
                                            <Label htmlFor={name}>
                                                {label}
                                            </Label>
                                            <MoneyInput
                                                id={name}
                                                name={name}
                                                currency={plan.currency}
                                                min={0}
                                                max={1000000000}
                                                required
                                                aria-invalid={!!errors[name]}
                                                onChange={
                                                    name === 'base_price'
                                                        ? (event) =>
                                                              setEntered(
                                                                  toMinorUnits(
                                                                      event
                                                                          .target
                                                                          .value,
                                                                      plan.currency,
                                                                  ),
                                                              )
                                                        : undefined
                                                }
                                            />
                                            <InputError
                                                message={errors[name]}
                                            />
                                        </div>
                                    ))}
                                </div>
                                {night && entered !== '' && (
                                    <PriceBreakdown
                                        price={entered}
                                        guests={guests}
                                        night={night}
                                        currency={plan.currency}
                                    />
                                )}
                                <Button type="submit" className="self-end">
                                    {processing && <Spinner />}Save prices
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {prices.length === 0 ? (
                    <EmptyState message="No prices set. Nights without prices cannot be quoted." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {[
                                    'Date',
                                    'Room type',
                                    'Base occupancy',
                                    'Extra adult',
                                    'Extra child',
                                    'Guest pays',
                                ].map((label) => (
                                    <TableHead
                                        numeric={
                                            !['Date', 'Room type'].includes(
                                                label,
                                            )
                                        }
                                        key={label}
                                    >
                                        {label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {prices.map((price) => (
                                <TableRow key={price.date + price.room_type_id}>
                                    <TableCell className="tabular-nums">
                                        {formatDate(price.date, 'UTC')}
                                    </TableCell>
                                    <TableCell>
                                        {
                                            roomTypes.find(
                                                (room) =>
                                                    room.id ===
                                                    price.room_type_id,
                                            )?.name
                                        }
                                    </TableCell>
                                    {[
                                        price.base_price,
                                        price.extra_adult,
                                        price.extra_child,
                                    ].map((amount, i) => (
                                        <TableCell numeric key={i}>
                                            {money(amount)}
                                        </TableCell>
                                    ))}
                                    <TableCell numeric>
                                        {price.guest_pays === null
                                            ? 'Not available'
                                            : money(price.guest_pays)}
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

Prices.layout = {
    breadcrumbs: [
        { title: 'Rate plans', href: index() },
        { title: 'Prices', href: '#' },
    ],
};

function PriceBreakdown({
    price,
    guests,
    night,
    currency,
}: {
    price: number;
    guests: number;
    night: ReturnType<typeof guestNight>;
    currency: string;
}) {
    const money = (amount: number) => formatMoney(amount, currency);
    return (
        <section
            aria-live="polite"
            aria-label="What the guest pays"
            className="space-y-2 rounded-xl border p-4"
        >
            <h2 className="font-semibold">
                What the guest pays per night, {guests}{' '}
                {guests === 1 ? 'guest' : 'guests'}
            </h2>
            <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
                <dt>Room price you entered</dt>
                <dd className="text-right tabular-nums">{money(price)}</dd>
                {night.lines.map((line) => (
                    <div key={line.name} className="contents">
                        <dt className="text-muted-foreground">
                            {line.inclusive ? 'of which ' : '+ '}
                            {line.name} {ruleSize(line, currency)}
                            {line.converted_from &&
                                ` (${formatMoney(line.converted_from.per_person_night, line.converted_from.currency)} at ZRA's rate of ${line.converted_from.rate_date}${currency === 'TZS' ? `, ${line.converted_from.usd_tzs} TZS per USD` : ''})`}
                            {line.inclusive ? ', included' : ''}
                        </dt>
                        <dd className="text-right text-muted-foreground tabular-nums">
                            {money(line.amount)}
                        </dd>
                    </div>
                ))}
                <dt className="font-semibold">Guest pays</dt>
                <dd className="text-right font-semibold tabular-nums">
                    {money(night.total)}
                </dd>
            </dl>
            {night.levyPending && (
                <p className="text-sm text-muted-foreground">
                    The infrastructure levy is added at check-out once ZRA's
                    exchange rate is available.
                </p>
            )}
            <p className="text-sm text-muted-foreground">
                An estimate: ZRA assesses the final tax and levy at check-out.
            </p>
        </section>
    );
}

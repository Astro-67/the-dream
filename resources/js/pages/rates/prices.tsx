import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/utils';
import { prices as pricesOf, quote } from '@/routes/rate-plans';
import { update } from '@/routes/rate-plans/prices';
type Price = {
    room_type_id: string;
    date: string;
    base_price: number;
    extra_adult: number;
    extra_child: number;
};
export default function Prices({
    plan,
    base,
    roomTypes,
    prices,
    canManage,
}: {
    plan: { id: string; name: string; currency: string };
    base: { id: string; name: string } | null;
    roomTypes: { id: string; name: string }[];
    prices: Price[];
    canManage: boolean;
}) {
    const money = (amount: number) => formatMoney(amount, plan.currency);
    return (
        <>
            <Head title={`${plan.name} prices`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {plan.name} prices
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={quote(plan.id)}>Preview a quote</Link>
                    </Button>
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
                                    Enter integer minor units in {plan.currency}
                                    :{' '}
                                    {plan.currency === 'TZS'
                                        ? '1 = TZS 1'
                                        : `100 = ${plan.currency} 1.00`}
                                    . Existing prices on selected dates are
                                    replaced.
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
                                            <Input
                                                id={name}
                                                name={name}
                                                type="number"
                                                min={0}
                                                max={1000000000}
                                                step={1}
                                                required
                                                aria-invalid={!!errors[name]}
                                            />
                                            <InputError
                                                message={errors[name]}
                                            />
                                        </div>
                                    ))}
                                </div>
                                <Button type="submit" className="self-end">
                                    {processing && <Spinner />}Save prices
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {prices.length === 0 ? (
                    <p className="text-muted-foreground">
                        No prices set. Nights without prices cannot be quoted.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    {[
                                        'Date',
                                        'Room type',
                                        'Base occupancy',
                                        'Extra adult',
                                        'Extra child',
                                    ].map((label) => (
                                        <th key={label} className="p-3">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {prices.map((price) => (
                                    <tr
                                        key={price.date + price.room_type_id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 tabular-nums">
                                            {price.date}
                                        </td>
                                        <td className="p-3">
                                            {
                                                roomTypes.find(
                                                    (room) =>
                                                        room.id ===
                                                        price.room_type_id,
                                                )?.name
                                            }
                                        </td>
                                        {[
                                            price.base_price,
                                            price.extra_adult,
                                            price.extra_child,
                                        ].map((amount, i) => (
                                            <td
                                                key={i}
                                                className="p-3 text-right tabular-nums"
                                            >
                                                {money(amount)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

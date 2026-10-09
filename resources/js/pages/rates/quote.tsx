import { formatDate } from '@/features/platform/format';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/utils';
import { prices, quote as quoteRoute } from '@/routes/rate-plans';

type Night = {
    date: string;
    base_price: number;
    extra_adults: number;
    extra_children: number;
    total: number;
};
type Quote = {
    currency: string;
    nights: Night[];
    total: number;
    unquotable: string | null;
};
type Stay = Partial<
    Record<
        'room_type_id' | 'arrival' | 'departure' | 'adults' | 'children',
        string
    >
>;

export default function QuotePreview({
    plan,
    roomTypes,
    stay,
    quote,
}: {
    plan: { id: string; name: string; currency: string };
    roomTypes: { id: string; name: string }[];
    stay: Stay;
    quote: Quote | null;
}) {
    const money = (amount: number) => formatMoney(amount, plan.currency);

    return (
        <>
            <Head title={`${plan.name} quote`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Quote preview: {plan.name}
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={prices(plan.id)}>Prices</Link>
                    </Button>
                </header>
                <Form
                    {...quoteRoute.form(plan.id)}
                    className="flex max-w-[720px] flex-col gap-6"
                >
                    {({ errors }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="room_type_id">Room type</Label>
                                <select
                                    id="room_type_id"
                                    name="room_type_id"
                                    defaultValue={stay.room_type_id}
                                    className="h-9 w-full rounded-md border border-input bg-background px-3"
                                >
                                    {roomTypes.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.room_type_id} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {(
                                    [
                                        ['arrival', 'Arrival', 'date'],
                                        ['departure', 'Departure', 'date'],
                                        ['adults', 'Adults', 'number'],
                                        ['children', 'Children', 'number'],
                                    ] as const
                                ).map(([name, label, type]) => (
                                    <div key={name} className="space-y-2">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            type={type}
                                            min={
                                                type === 'number'
                                                    ? name === 'adults'
                                                        ? 1
                                                        : 0
                                                    : undefined
                                            }
                                            defaultValue={
                                                stay[name] ??
                                                (name === 'adults'
                                                    ? '2'
                                                    : name === 'children'
                                                      ? '0'
                                                      : '')
                                            }
                                            required
                                            aria-invalid={!!errors[name]}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </div>
                            <Button type="submit" className="self-end">
                                Show prices
                            </Button>
                        </>
                    )}
                </Form>
                {quote?.unquotable && (
                    <p role="status" className="font-medium">
                        Cannot quote this stay: {quote.unquotable}
                    </p>
                )}
                {quote && !quote.unquotable && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    {[
                                        'Night',
                                        'Base occupancy',
                                        'Extra adults',
                                        'Extra children',
                                        'Night total',
                                    ].map((label) => (
                                        <th key={label} className="p-3">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {quote.nights.map((night) => (
                                    <tr
                                        key={formatDate(night.date, 'UTC')}
                                        className="border-b"
                                    >
                                        <td className="p-3 tabular-nums">
                                            {formatDate(night.date, 'UTC')}
                                        </td>
                                        {[
                                            night.base_price,
                                            night.extra_adults,
                                            night.extra_children,
                                            night.total,
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
                            <tfoot>
                                <tr className="font-semibold">
                                    <td className="p-3" colSpan={4}>
                                        Stay total ({quote.nights.length}{' '}
                                        nights)
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {money(quote.total)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

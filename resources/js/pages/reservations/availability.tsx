import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { availability, create } from '@/routes/reservations';

type Stay = Partial<
    Record<
        'arrival' | 'departure' | 'adults' | 'children' | 'room_type_id',
        string | number | null
    >
>;
type RoomType = { id: string; name: string };
type Plan = {
    id: string;
    name: string;
    currency: string;
    total: number;
    nights: { date: string; total: number }[];
};
type Result = RoomType & { available: number; plans: Plan[] };

export default function Availability({
    stay,
    roomTypes,
    results,
}: {
    stay: Stay;
    roomTypes: RoomType[];
    results: Result[] | null;
}) {
    return (
        <>
            <Head title="Availability" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Search availability
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Quote one room for the full stay. Searching does not
                        book or hold inventory.
                    </p>
                </header>
                <Form
                    {...availability.form()}
                    className="flex max-w-[720px] flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
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
                                            required
                                            min={
                                                type === 'number'
                                                    ? name === 'adults'
                                                        ? 1
                                                        : 0
                                                    : undefined
                                            }
                                            max={
                                                type === 'number'
                                                    ? 20
                                                    : undefined
                                            }
                                            defaultValue={
                                                stay[name] ??
                                                (name === 'adults'
                                                    ? 2
                                                    : name === 'children'
                                                      ? 0
                                                      : '')
                                            }
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={
                                                errors[name]
                                                    ? `${name}-error`
                                                    : undefined
                                            }
                                        />
                                        <div id={`${name}-error`}>
                                            <InputError
                                                message={errors[name]}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="room_type_id">Room type</Label>
                                <select
                                    id="room_type_id"
                                    name="room_type_id"
                                    defaultValue={stay.room_type_id ?? ''}
                                    aria-invalid={!!errors.room_type_id}
                                    aria-describedby="room_type_id-error"
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option value="">All room types</option>
                                    {roomTypes.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name}
                                        </option>
                                    ))}
                                </select>
                                <div id="room_type_id-error">
                                    <InputError message={errors.room_type_id} />
                                </div>
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="self-end"
                            >
                                {processing && <Spinner />}Search availability
                            </Button>
                        </>
                    )}
                </Form>
                {results === null && (
                    <EmptyState message="Choose dates and guest counts to see available room types and prices." />
                )}
                {results?.length === 0 && (
                    <EmptyState
                        message="No priced room types are available for this stay and guest count."
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    document.getElementById('arrival')?.focus()
                                }
                            >
                                Change search
                            </Button>
                        }
                    />
                )}
                {results?.map((type) => (
                    <section key={type.id} className="space-y-4">
                        <h2 className="text-lg font-semibold">{type.name}</h2>
                        <p className="text-sm text-muted-foreground">
                            {type.available} rooms available on every night
                        </p>
                        {type.plans.map((plan) => (
                            <div key={plan.id} className="space-y-2">
                                <h3 className="font-medium">
                                    {plan.name} · {plan.currency}
                                </h3>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Night</TableHead>
                                            <TableHead numeric>
                                                Nightly price
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {plan.nights.map((night) => (
                                            <TableRow key={night.date}>
                                                <TableCell>
                                                    {formatDate(
                                                        night.date,
                                                        'UTC',
                                                    )}
                                                </TableCell>
                                                <TableCell numeric>
                                                    {formatMoney(
                                                        night.total,
                                                        plan.currency,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <tfoot>
                                        <TableRow className="font-semibold">
                                            <TableCell>
                                                Stay total ({plan.nights.length}{' '}
                                                nights)
                                            </TableCell>
                                            <TableCell numeric>
                                                {formatMoney(
                                                    plan.total,
                                                    plan.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    </tfoot>
                                </Table>
                                <Button asChild variant="outline">
                                    <Link
                                        href={create({
                                            query: {
                                                arrival: stay.arrival,
                                                departure: stay.departure,
                                                adults: stay.adults,
                                                children: stay.children,
                                                room_type_id: type.id,
                                                rate_plan_id: plan.id,
                                            },
                                        })}
                                    >
                                        Book this room
                                    </Link>
                                </Button>
                            </div>
                        ))}
                    </section>
                ))}
            </div>
        </>
    );
}

import { NativeSelect } from '@/components/ui/select';
import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BedDouble,
    CalendarDays,
    CircleCheck,
    Search,
} from 'lucide-react';
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
            <div className="flex min-w-0 flex-col gap-6">
                <header className="flex items-center gap-4 border-b border-border pb-6">
                    <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel">
                        <CalendarDays aria-hidden="true" className="size-6" />
                    </span>
                    <div className="space-y-1">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Plan a stay
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Search availability
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Quote one room for the full stay. Searching does not
                            book or hold inventory.
                        </p>
                    </div>
                </header>
                <Form
                    {...availability.form()}
                    className="flex max-w-5xl flex-col gap-5 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex items-center gap-2 border-b border-border pb-3 text-sm font-semibold">
                                <CalendarDays
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                Stay details
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
                                <NativeSelect
                                    id="room_type_id"
                                    name="room_type_id"
                                    defaultValue={stay.room_type_id ?? ''}
                                    aria-invalid={!!errors.room_type_id}
                                    aria-describedby="room_type_id-error"
                                >
                                    <option value="">All room types</option>
                                    {roomTypes.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <div id="room_type_id-error">
                                    <InputError message={errors.room_type_id} />
                                </div>
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="self-start"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Search aria-hidden="true" />
                                )}
                                Search availability
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
                    <section
                        key={type.id}
                        className="space-y-4 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                    <BedDouble
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">
                                        Room type
                                    </p>
                                    <h2 className="text-lg font-semibold break-words">
                                        {type.name}
                                    </h2>
                                </div>
                            </div>
                            <span className="inline-flex items-center gap-2 border border-status-success/20 bg-status-success-tint px-3 py-1.5 text-sm font-medium text-status-success">
                                <CircleCheck
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                {type.available} rooms available every night
                            </span>
                        </div>
                        <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                            {type.plans.map((plan) => (
                                <article
                                    key={plan.id}
                                    className="flex min-w-0 flex-col gap-3 border border-border bg-background p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                                        <h3 className="text-base font-semibold">
                                            {plan.name}
                                        </h3>
                                        <span className="border border-border bg-card px-2 py-1 text-xs font-medium text-muted-foreground">
                                            {plan.currency}
                                        </span>
                                    </div>
                                    <div className="min-w-0 overflow-x-auto">
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
                                        </Table>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted-foreground">
                                                Stay total ·{' '}
                                                {plan.nights.length}{' '}
                                                {plan.nights.length === 1
                                                    ? 'night'
                                                    : 'nights'}
                                            </p>
                                            <p className="text-lg font-semibold tabular-nums">
                                                {formatMoney(
                                                    plan.total,
                                                    plan.currency,
                                                )}
                                            </p>
                                        </div>
                                        <Button asChild variant="outline">
                                            <Link
                                                href={create({
                                                    query: {
                                                        arrival: stay.arrival,
                                                        departure:
                                                            stay.departure,
                                                        adults: stay.adults,
                                                        children: stay.children,
                                                        room_type_id: type.id,
                                                        rate_plan_id: plan.id,
                                                    },
                                                })}
                                            >
                                                Book this room
                                                <ArrowRight aria-hidden="true" />
                                            </Link>
                                        </Button>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

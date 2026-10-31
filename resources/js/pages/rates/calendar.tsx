import { NativeSelect } from '@/components/ui/select';
import { RestrictionFields } from '@/features/rates/restriction-fields';
import {
    CalendarRoomRows,
    type CalendarRoom,
} from '@/features/reservations/calendar-rows';
import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/utils';
import { rateCalendar } from '@/routes';
import {
    prices as pricesRoute,
    restrictions as restrictionsRoute,
} from '@/routes/rate-calendar';

type Restriction = {
    min_stay: number;
    closed_to_arrival: boolean;
    stop_sell: boolean;
} | null;
type Plan = {
    id: string;
    name: string;
    code: string;
    currency: string;
    derived: boolean;
    prices: Record<string, number | null>;
    restrictions: Record<string, Restriction>;
};
type RoomType = { id: string; name: string; code: string };
type Row = RoomType & {
    sellable: Record<string, number>;
    available: Record<string, number>;
    outOfService: Record<string, number>;
    plans: Plan[];
    rooms: CalendarRoom[];
    unassigned: Record<string, number>;
};
type Editing = {
    kind: 'prices' | 'restrictions' | 'release-stop-sell';
    plan: Plan;
    roomType: RoomType;
};

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const cell = 'border-b border-l px-2 py-1.5 text-right tabular-nums';
const label =
    'sticky left-0 z-10 border-b bg-card px-3 py-1.5 text-left font-normal whitespace-nowrap';

function restrictionText(restriction: Restriction): string {
    if (restriction === null) {
        return 'Open';
    }

    return (
        [
            restriction.stop_sell && 'Stop-sell',
            restriction.closed_to_arrival && 'CTA',
            restriction.min_stay > 1 && `Min ${restriction.min_stay}`,
        ]
            .filter(Boolean)
            .join(' · ') || 'Open'
    );
}

export default function RateCalendar({
    from,
    to,
    dates,
    allRoomTypes,
    roomTypes,
    canManage,
    filters,
    businessDate,
}: {
    from: string;
    to: string;
    dates: string[];
    allRoomTypes: RoomType[];
    roomTypes: Row[];
    canManage: boolean;
    filters: { list: string; search: string };
    businessDate: string;
}) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [rangeFrom, setRangeFrom] = useState(from);
    const [rangeTo, setRangeTo] = useState(to);
    const rangeDays =
        Math.round((Date.parse(rangeTo) - Date.parse(rangeFrom)) / 86400000) +
        1;
    const selected = new Set(roomTypes.map((room) => room.id));
    const day = (date: string) =>
        new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            timeZone: 'UTC',
        });
    const editButton = (editing: Editing, name: string) =>
        canManage && (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="ml-2 min-h-11 md:min-h-8"
                onClick={() => setEditing(editing)}
                aria-label={
                    editing.kind === 'release-stop-sell'
                        ? `Release stop-sell for ${name}`
                        : `Edit ${name}`
                }
            >
                {editing.kind === 'release-stop-sell'
                    ? 'Release stop-sell'
                    : 'Edit'}
            </Button>
        );

    return (
        <>
            <Head title="Rate calendar" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Rate calendar
                </h1>
                <p className="text-sm text-muted-foreground">
                    Business date: {day(businessDate)}
                </p>
                <Form
                    {...rateCalendar.form()}
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ errors }) => (
                        <>
                            {(
                                [
                                    ['from', 'From'],
                                    ['to', 'To'],
                                ] as const
                            ).map(([name, text]) => (
                                <div key={name} className="space-y-2">
                                    <Label htmlFor={`range-${name}`}>
                                        {text}
                                    </Label>
                                    <Input
                                        id={`range-${name}`}
                                        name={name}
                                        type="date"
                                        value={
                                            name === 'from'
                                                ? rangeFrom
                                                : rangeTo
                                        }
                                        onChange={(event) =>
                                            name === 'from'
                                                ? setRangeFrom(
                                                      event.target.value,
                                                  )
                                                : setRangeTo(event.target.value)
                                        }
                                        required
                                        aria-invalid={!!errors[name]}
                                    />
                                    <InputError message={errors[name]} />
                                </div>
                            ))}
                            <div className="space-y-2">
                                <Label htmlFor="calendar-view">View</Label>
                                <NativeSelect
                                    id="calendar-view"
                                    value={
                                        [7, 15, 30, 60].includes(rangeDays)
                                            ? String(rangeDays)
                                            : ''
                                    }
                                    onChange={(event) => {
                                        const end = new Date(
                                            `${rangeFrom}T00:00:00Z`,
                                        );
                                        if (!Number.isNaN(end.getTime())) {
                                            end.setUTCDate(
                                                end.getUTCDate() +
                                                    Number(event.target.value) -
                                                    1,
                                            );
                                            setRangeTo(
                                                end.toISOString().slice(0, 10),
                                            );
                                        }
                                    }}
                                >
                                    <option value="" hidden>
                                        Custom range
                                    </option>
                                    {[7, 15, 30, 60].map((days) => (
                                        <option key={days} value={days}>
                                            {days} days
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="calendar-list">Stays</Label>
                                <NativeSelect
                                    id="calendar-list"
                                    name="list"
                                    defaultValue={filters.list}
                                >
                                    <option value="all">
                                        All reservations
                                    </option>
                                    <option value="arrivals">Arrivals</option>
                                    <option value="departures">
                                        Departures
                                    </option>
                                    <option value="in-house">In-house</option>
                                    <option value="overstays">Overstays</option>
                                    <option value="balance-owing">
                                        Balance owing
                                    </option>
                                    <option value="no-id-document">
                                        No ID document
                                    </option>
                                </NativeSelect>
                                <InputError message={errors.list} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="calendar-search">Search</Label>
                                <Input
                                    id="calendar-search"
                                    name="search"
                                    defaultValue={filters.search ?? ''}
                                    maxLength={255}
                                    placeholder="Room, guest, phone, reservation or channel reference"
                                />
                                <InputError message={errors.search} />
                            </div>
                            <fieldset>
                                <legend className="mb-2 text-sm font-medium">
                                    Room types
                                </legend>
                                <div className="flex flex-wrap gap-x-4">
                                    {allRoomTypes.map((room) => (
                                        <label
                                            key={room.id}
                                            className="flex min-h-11 items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                name="room_type_ids[]"
                                                value={room.id}
                                                defaultChecked={selected.has(
                                                    room.id,
                                                )}
                                                className="size-4 accent-primary"
                                            />
                                            {room.name}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <Button type="submit" variant="outline">
                                Show
                            </Button>
                        </>
                    )}
                </Form>
                {roomTypes.length === 0 ? (
                    <p className="text-muted-foreground">
                        No room types selected. Choose at least one above.
                    </p>
                ) : (
                    <div className="max-h-[calc(100svh-9rem)] overflow-auto rounded-xl border bg-card">
                        <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr>
                                    <th
                                        className={`${label} top-0 z-30 font-medium`}
                                    >
                                        <span className="sr-only">Row</span>
                                    </th>
                                    {dates.map((date) => (
                                        <th
                                            key={date}
                                            scope="col"
                                            className="sticky top-0 z-20 min-w-24 border-b border-l bg-card px-2 py-2 text-right font-medium whitespace-nowrap"
                                        >
                                            {day(date)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            {roomTypes.map((room) => (
                                <tbody key={room.id}>
                                    <tr>
                                        <th
                                            scope="rowgroup"
                                            colSpan={dates.length + 1}
                                            className="border-b bg-muted px-3 py-2 text-left font-medium"
                                        >
                                            <span className="sticky left-3">
                                                {room.name}{' '}
                                                <span className="font-mono text-muted-foreground">
                                                    {room.code}
                                                </span>
                                            </span>
                                        </th>
                                    </tr>
                                    <CalendarRoomRows
                                        rooms={room.rooms}
                                        dates={dates}
                                    />
                                    <tr>
                                        <th scope="row" className={label}>
                                            Unassigned reservations
                                        </th>
                                        {dates.map((date) => (
                                            <td key={date} className={cell}>
                                                {room.unassigned[date]}
                                            </td>
                                        ))}
                                    </tr>
                                    <tr>
                                        <th scope="row" className={label}>
                                            Sellable
                                        </th>
                                        {dates.map((date) => (
                                            <td key={date} className={cell}>
                                                {room.sellable[date]}
                                            </td>
                                        ))}
                                    </tr>
                                    <tr>
                                        <th scope="row" className={label}>
                                            Available rooms
                                        </th>
                                        {dates.map((date) => (
                                            <td key={date} className={cell}>
                                                {room.available[date]}
                                            </td>
                                        ))}
                                    </tr>
                                    <tr>
                                        <th scope="row" className={label}>
                                            Out of service
                                        </th>
                                        {dates.map((date) => (
                                            <td
                                                key={date}
                                                className={`${cell} text-muted-foreground`}
                                            >
                                                {room.outOfService[date]}
                                            </td>
                                        ))}
                                    </tr>
                                    {room.plans.map((plan) => (
                                        <PlanRows
                                            key={plan.id}
                                            plan={plan}
                                            dates={dates}
                                            priceEdit={
                                                !plan.derived &&
                                                editButton(
                                                    {
                                                        kind: 'prices',
                                                        plan,
                                                        roomType: room,
                                                    },
                                                    `${plan.name} prices for ${room.name}`,
                                                )
                                            }
                                            restrictionEdit={
                                                <>
                                                    {editButton(
                                                        {
                                                            kind: 'restrictions',
                                                            plan,
                                                            roomType: room,
                                                        },
                                                        `${plan.name} restrictions for ${room.name}`,
                                                    )}
                                                    {editButton(
                                                        {
                                                            kind: 'release-stop-sell',
                                                            plan,
                                                            roomType: room,
                                                        },
                                                        `${plan.name}, ${room.name}`,
                                                    )}
                                                </>
                                            }
                                        />
                                    ))}
                                </tbody>
                            ))}
                        </table>
                    </div>
                )}
            </div>
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent>
                    {editing && (
                        <BulkEdit
                            editing={editing}
                            from={from}
                            to={to}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function PlanRows({
    plan,
    dates,
    priceEdit,
    restrictionEdit,
}: {
    plan: Plan;
    dates: string[];
    priceEdit: React.ReactNode;
    restrictionEdit: React.ReactNode;
}) {
    return (
        <>
            <tr>
                <th scope="row" className={label}>
                    <span className="font-mono">{plan.code}</span> {plan.name}
                    {plan.derived && (
                        <span className="ml-1 text-muted-foreground">
                            (derived)
                        </span>
                    )}
                    {priceEdit}
                </th>
                {dates.map((date) => {
                    const price = plan.prices[date];

                    return (
                        <td key={date} className={cell}>
                            {price === null ? (
                                <span className="text-muted-foreground">
                                    No price
                                </span>
                            ) : (
                                formatMoney(price, plan.currency)
                            )}
                        </td>
                    );
                })}
            </tr>
            <tr>
                <th
                    scope="row"
                    className={`${label} pl-6 text-muted-foreground`}
                >
                    {plan.code} restrictions
                    {restrictionEdit}
                </th>
                {dates.map((date) => (
                    <td
                        key={date}
                        className={`${cell} text-xs ${plan.restrictions[date] === null ? 'text-muted-foreground' : ''}`}
                    >
                        {restrictionText(plan.restrictions[date])}
                    </td>
                ))}
            </tr>
        </>
    );
}

function BulkEdit({
    editing,
    from,
    to,
    onDone,
}: {
    editing: Editing;
    from: string;
    to: string;
    onDone: () => void;
}) {
    const { kind, plan, roomType } = editing;
    const route = kind === 'prices' ? pricesRoute : restrictionsRoute;
    const releasing = kind === 'release-stop-sell';

    return (
        <>
            <DialogTitle>
                {kind === 'prices'
                    ? 'Set prices'
                    : releasing
                      ? 'Release stop-sell'
                      : 'Set restrictions'}
                : {plan.name}, {roomType.name}
            </DialogTitle>
            <DialogDescription>
                {kind === 'prices'
                    ? `Amounts in ${plan.currency}. Existing prices on selected dates are replaced.`
                    : releasing
                      ? 'Stop-sell is released on selected dates. Minimum stay and closed-to-arrival are unchanged.'
                      : 'Existing restrictions on selected dates are replaced.'}
            </DialogDescription>
            <Form
                {...route.form(plan.id)}
                options={{ preserveScroll: true }}
                onSuccess={onDone}
                disableWhileProcessing
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <PropertyIntent />
                        <InputError message={errors._property} />
                        <input
                            type="hidden"
                            name="room_type_id"
                            value={roomType.id}
                        />
                        <InputError message={errors.room_type_id} />
                        <div className="grid grid-cols-2 gap-4">
                            {(
                                [
                                    ['from', 'From (inclusive)', from],
                                    ['to', 'To (inclusive)', to],
                                ] as const
                            ).map(([name, text, value]) => (
                                <div key={name} className="space-y-2">
                                    <Label htmlFor={`edit-${name}`}>
                                        {text}
                                    </Label>
                                    <Input
                                        id={`edit-${name}`}
                                        name={name}
                                        type="date"
                                        defaultValue={value}
                                        required
                                        aria-invalid={!!errors[name]}
                                    />
                                    <InputError message={errors[name]} />
                                </div>
                            ))}
                        </div>
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">
                                Weekdays
                            </legend>
                            <div className="flex flex-wrap gap-x-3">
                                {weekdays.map((name, i) => (
                                    <label
                                        key={name}
                                        className="flex min-h-11 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="weekdays[]"
                                            value={i + 1}
                                            defaultChecked
                                            className="size-4 accent-primary"
                                        />
                                        {name}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.weekdays} />
                        </fieldset>
                        {kind === 'prices' ? (
                            <div className="grid grid-cols-3 gap-4">
                                {(
                                    [
                                        ['base_price', 'Base price'],
                                        ['extra_adult', 'Extra adult'],
                                        ['extra_child', 'Extra child'],
                                    ] as const
                                ).map(([name, text]) => (
                                    <div key={name} className="space-y-2">
                                        <Label htmlFor={name}>{text}</Label>
                                        <MoneyInput
                                            id={name}
                                            name={name}
                                            currency={plan.currency}
                                            min={0}
                                            defaultValue={0}
                                            required
                                            aria-invalid={!!errors[name]}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </div>
                        ) : releasing ? (
                            <>
                                <input
                                    type="hidden"
                                    name="release_stop_sell"
                                    value="1"
                                />
                                <input
                                    type="hidden"
                                    name="stop_sell"
                                    value="0"
                                />
                            </>
                        ) : (
                            <RestrictionFields errors={errors} />
                        )}
                        <Button type="submit" className="min-h-11 self-end">
                            {processing && <Spinner />}
                            {releasing ? 'Release stop-sell' : 'Save'}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

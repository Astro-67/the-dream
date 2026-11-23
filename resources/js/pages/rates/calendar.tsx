import { NativeSelect } from '@/components/ui/select';
import { RestrictionFields } from '@/features/rates/restriction-fields';
import {
    CalendarOutOfOrder,
    type OutOfOrderEditing,
} from '@/features/rates/calendar-out-of-order';
import {
    CalendarRoomRows,
    type CalendarRoom,
} from '@/features/reservations/calendar-rows';
import { Form, Head } from '@inertiajs/react';
import {
    BedDouble,
    CalendarCheck,
    ChevronDown,
    LogOut,
    Search,
    TriangleAlert,
    UserX,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { StatusChip } from '@/components/ui/status-chip';
import { cn, formatMoney } from '@/lib/utils';
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
    'sticky left-0 z-10 border-b bg-card px-3 py-1.5 text-left font-normal break-words whitespace-normal';
const reservationLegend = [
    { label: 'Confirmed', icon: CalendarCheck, tone: 'info' },
    { label: 'Checked in', icon: BedDouble, tone: 'occupied' },
    { label: 'Checked out', icon: LogOut, tone: 'success' },
    { label: 'No-show', icon: UserX, tone: 'warning' },
    { label: 'Overstay', icon: TriangleAlert, tone: 'warning' },
    { label: 'Out of order', icon: Wrench, tone: 'unavailable' },
] as const;
const reservationOriginLegend = [
    { label: 'Direct', swatch: 'bg-reservation-origin-direct' },
    { label: 'External / OTA', swatch: 'bg-reservation-origin-external' },
    { label: 'Tour operator', swatch: 'bg-reservation-origin-operator' },
] as const;

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
    const [outOfOrder, setOutOfOrder] = useState<OutOfOrderEditing | null>(
        null,
    );
    const [rangeFrom, setRangeFrom] = useState(from);
    const [rangeTo, setRangeTo] = useState(to);
    const rangeDays =
        Math.round((Date.parse(rangeTo) - Date.parse(rangeFrom)) / 86400000) +
        1;
    const selectedIdsFromProps = roomTypes
        .map((room) => room.id)
        .join('\u0000');
    const [selectedRoomTypeIds, setSelectedRoomTypeIds] = useState(() =>
        roomTypes.map((room) => room.id),
    );
    useEffect(() => {
        setSelectedRoomTypeIds(
            selectedIdsFromProps.split('\u0000').filter(Boolean),
        );
    }, [selectedIdsFromProps]);
    const selected = new Set(selectedRoomTypeIds);
    const selectedRoomTypeLabel =
        allRoomTypes.length === 0
            ? 'No room types available'
            : selected.size === allRoomTypes.length
              ? 'All room types'
              : selected.size === 0
                ? 'No room types selected'
                : selected.size === 1
                  ? (allRoomTypes.find((room) => selected.has(room.id))?.name ??
                    '1 room type selected')
                  : `${selected.size} room types selected`;
    const roomTypeFilter = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    aria-label={`Filter by room type: ${selectedRoomTypeLabel}`}
                    className="min-h-11 w-full min-w-0 justify-between gap-2 px-2 text-left text-xs"
                >
                    <span className="flex min-w-0 flex-col items-start">
                        <span className="text-[10px] font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                            Room type
                        </span>
                        <span className="w-full truncate font-semibold">
                            {selectedRoomTypeLabel}
                        </span>
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-72 w-64 overflow-y-auto"
            >
                {allRoomTypes.map((room) => (
                    <DropdownMenuCheckboxItem
                        key={room.id}
                        checked={selected.has(room.id)}
                        className="min-h-11 whitespace-normal"
                        onSelect={(event) => event.preventDefault()}
                        onCheckedChange={(checked) =>
                            setSelectedRoomTypeIds((ids) =>
                                checked
                                    ? [...new Set([...ids, room.id])]
                                    : ids.filter((id) => id !== room.id),
                            )
                        }
                    >
                        {room.name}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
    const dayHeading = (date: string) => {
        const value = new Date(`${date}T00:00:00Z`);

        return {
            weekday: value.toLocaleDateString('en-GB', {
                weekday: 'short',
                timeZone: 'UTC',
            }),
            date: value.toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                timeZone: 'UTC',
            }),
        };
    };
    const dateCell = (date: string, extraClass = '') =>
        cn(cell, date === businessDate && 'bg-primary-tint/60', extraClass);
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
            <Head title="Calendar" />
            <h1 className="sr-only">Calendar</h1>
            <div className="flex min-w-0 flex-col">
                <section className="min-w-0 overflow-hidden border border-border bg-card shadow-panel">
                    <Form
                        {...rateCalendar.form()}
                        className="grid min-w-0 items-end gap-2 border-b border-border bg-muted/20 p-3 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1.7fr)_repeat(2,minmax(140px,1fr))_minmax(125px,.8fr)_minmax(160px,1.1fr)_auto]"
                    >
                        {({ errors }) => (
                            <>
                                {selectedRoomTypeIds.map((roomTypeId) => (
                                    <input
                                        key={roomTypeId}
                                        type="hidden"
                                        name="room_type_ids[]"
                                        value={roomTypeId}
                                    />
                                ))}
                                <div className="min-w-0 space-y-1.5 sm:col-span-2 xl:col-span-1">
                                    <Label
                                        htmlFor="calendar-search"
                                        className="inline-flex items-center gap-1.5 text-xs"
                                    >
                                        <Search
                                            aria-hidden="true"
                                            className="size-4 text-primary"
                                        />
                                        Search reservations and rooms
                                    </Label>
                                    <Input
                                        id="calendar-search"
                                        name="search"
                                        defaultValue={filters.search ?? ''}
                                        maxLength={255}
                                        placeholder="Guest name, room, phone, reservation or channel reference"
                                        className="h-10"
                                        aria-invalid={!!errors.search}
                                    />
                                    <InputError message={errors.search} />
                                </div>

                                {(
                                    [
                                        ['from', 'From'],
                                        ['to', 'To'],
                                    ] as const
                                ).map(([name, text]) => (
                                    <div
                                        key={name}
                                        className="min-w-0 space-y-1.5"
                                    >
                                        <Label
                                            htmlFor={`range-${name}`}
                                            className="text-xs"
                                        >
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
                                                    : setRangeTo(
                                                          event.target.value,
                                                      )
                                            }
                                            className="h-10"
                                            required
                                            aria-invalid={!!errors[name]}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                                <div className="min-w-0 space-y-1.5">
                                    <Label
                                        htmlFor="calendar-view"
                                        className="text-xs"
                                    >
                                        View
                                    </Label>
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
                                                        Number(
                                                            event.target.value,
                                                        ) -
                                                        1,
                                                );
                                                setRangeTo(
                                                    end
                                                        .toISOString()
                                                        .slice(0, 10),
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
                                <div className="min-w-0 space-y-1.5">
                                    <Label
                                        htmlFor="calendar-list"
                                        className="text-xs"
                                    >
                                        Stays
                                    </Label>
                                    <NativeSelect
                                        id="calendar-list"
                                        name="list"
                                        defaultValue={filters.list}
                                    >
                                        <option value="all">
                                            All reservations
                                        </option>
                                        <option value="arrivals">
                                            Arrivals
                                        </option>
                                        <option value="departures">
                                            Departures
                                        </option>
                                        <option value="in-house">
                                            In-house
                                        </option>
                                        <option value="overstays">
                                            Overstays
                                        </option>
                                        <option value="balance-owing">
                                            Balance owing
                                        </option>
                                        <option value="no-id-document">
                                            No ID document
                                        </option>
                                    </NativeSelect>
                                    <InputError message={errors.list} />
                                </div>
                                <Button
                                    type="submit"
                                    className="min-h-10 sm:col-span-2 xl:col-span-1"
                                >
                                    <Search aria-hidden="true" />
                                    Update
                                </Button>
                            </>
                        )}
                    </Form>
                    {roomTypes.length === 0 ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                            <p className="text-sm text-muted-foreground">
                                {allRoomTypes.length === 0
                                    ? 'No room types are available for this property.'
                                    : 'No room types selected. Choose one from the filter.'}
                            </p>
                            <div className="w-full min-w-0 sm:max-w-64">
                                {roomTypeFilter}
                            </div>
                        </div>
                    ) : (
                        <div className="min-w-0">
                            <nav
                                aria-label="Calendar reservation status and source legend"
                                className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border bg-muted/20 px-3 py-2"
                            >
                                <span className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                    Key
                                </span>
                                {reservationLegend.map(
                                    ({ label, icon: Icon, tone }) => (
                                        <span
                                            key={label}
                                            className="inline-flex items-center gap-1.5"
                                        >
                                            <Icon
                                                aria-hidden="true"
                                                className="size-4 text-muted-foreground"
                                            />
                                            <StatusChip tone={tone}>
                                                {label}
                                            </StatusChip>
                                        </span>
                                    ),
                                )}
                                <span
                                    aria-hidden="true"
                                    className="mx-1 h-4 border-l border-border"
                                />
                                {reservationOriginLegend.map(
                                    ({ label, swatch }) => (
                                        <span
                                            key={label}
                                            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-3 rounded-sm',
                                                    swatch,
                                                )}
                                            />
                                            {label}
                                        </span>
                                    ),
                                )}
                            </nav>
                            <div
                                role="region"
                                aria-label="Room availability and rate calendar"
                                tabIndex={0}
                                className="max-h-[calc(100svh-9rem)] min-w-0 overflow-auto bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <table
                                    className="min-w-full table-fixed border-separate border-spacing-0 text-sm"
                                    style={{
                                        width: `${10 + dates.length * 7}rem`,
                                    }}
                                >
                                    <caption className="sr-only">
                                        Room availability, prices and
                                        restrictions. Scroll to view all dates
                                        and rooms.
                                    </caption>
                                    <colgroup>
                                        <col className="w-40" />
                                        {dates.map((date) => (
                                            <col key={date} className="w-28" />
                                        ))}
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th
                                                scope="col"
                                                className={`${label} top-0 z-30 font-medium`}
                                            >
                                                {roomTypeFilter}
                                            </th>
                                            {dates.map((date) => {
                                                const heading =
                                                    dayHeading(date);

                                                return (
                                                    <th
                                                        key={date}
                                                        scope="col"
                                                        aria-current={
                                                            date ===
                                                            businessDate
                                                                ? 'date'
                                                                : undefined
                                                        }
                                                        className={cn(
                                                            'sticky top-0 z-20 border-b border-l px-2 py-2 text-center whitespace-nowrap',
                                                            date ===
                                                                businessDate
                                                                ? 'bg-primary-tint'
                                                                : 'bg-card',
                                                        )}
                                                    >
                                                        <span className="flex min-h-12 flex-col items-center justify-center gap-0.5">
                                                            <span className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                                {
                                                                    heading.weekday
                                                                }
                                                            </span>
                                                            <span className="text-sm font-semibold tabular-nums">
                                                                {heading.date}
                                                            </span>
                                                            {date ===
                                                                businessDate && (
                                                                <span className="text-[10px] font-semibold text-primary">
                                                                    Business
                                                                    date
                                                                </span>
                                                            )}
                                                        </span>
                                                    </th>
                                                );
                                            })}
                                        </tr>
                                    </thead>
                                    {roomTypes.map((room) => (
                                        <tbody key={room.id}>
                                            <tr>
                                                <th
                                                    scope="rowgroup"
                                                    colSpan={dates.length + 1}
                                                    className="border-t-2 border-b border-t-border bg-muted/70 px-3 py-2.5 text-left text-sm font-semibold"
                                                >
                                                    <span className="sticky left-3 inline-flex max-w-[calc(100vw-4rem)] items-center gap-2 break-words whitespace-normal md:max-w-none">
                                                        <BedDouble
                                                            aria-hidden="true"
                                                            className="size-4 shrink-0 text-primary"
                                                        />
                                                        {room.name}{' '}
                                                        <span className="border border-border bg-card px-1.5 py-0.5 font-mono text-xs font-medium text-muted-foreground">
                                                            {room.code}
                                                        </span>
                                                    </span>
                                                </th>
                                            </tr>
                                            <CalendarRoomRows
                                                rooms={room.rooms}
                                                dates={dates}
                                                businessDate={businessDate}
                                                onOutOfOrder={(
                                                    room,
                                                    date,
                                                    period,
                                                ) =>
                                                    setOutOfOrder({
                                                        room,
                                                        date,
                                                        period,
                                                    })
                                                }
                                            />
                                            <tr>
                                                <th
                                                    scope="row"
                                                    className={label}
                                                >
                                                    Unassigned reservations
                                                </th>
                                                {dates.map((date) => (
                                                    <td
                                                        key={date}
                                                        className={dateCell(
                                                            date,
                                                        )}
                                                    >
                                                        {room.unassigned[date]}
                                                    </td>
                                                ))}
                                            </tr>
                                            <tr>
                                                <th
                                                    scope="row"
                                                    className={cn(
                                                        label,
                                                        'text-muted-foreground',
                                                    )}
                                                >
                                                    Sellable
                                                </th>
                                                {dates.map((date) => (
                                                    <td
                                                        key={date}
                                                        className={dateCell(
                                                            date,
                                                        )}
                                                    >
                                                        {room.sellable[date]}
                                                    </td>
                                                ))}
                                            </tr>
                                            <tr>
                                                <th
                                                    scope="row"
                                                    className={cn(
                                                        label,
                                                        'font-semibold text-status-success',
                                                    )}
                                                >
                                                    Available rooms
                                                </th>
                                                {dates.map((date) => (
                                                    <td
                                                        key={date}
                                                        className={dateCell(
                                                            date,
                                                            'font-semibold text-status-success',
                                                        )}
                                                    >
                                                        {room.available[date]}
                                                    </td>
                                                ))}
                                            </tr>
                                            <tr>
                                                <th
                                                    scope="row"
                                                    className={label}
                                                >
                                                    Out of order
                                                </th>
                                                {dates.map((date) => (
                                                    <td
                                                        key={date}
                                                        className={dateCell(
                                                            date,
                                                            'text-status-unavailable',
                                                        )}
                                                    >
                                                        {
                                                            room.outOfService[
                                                                date
                                                            ]
                                                        }
                                                    </td>
                                                ))}
                                            </tr>
                                            {room.plans.map((plan) => (
                                                <PlanRows
                                                    key={plan.id}
                                                    plan={plan}
                                                    dates={dates}
                                                    businessDate={businessDate}
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
                                                                    roomType:
                                                                        room,
                                                                },
                                                                `${plan.name} restrictions for ${room.name}`,
                                                            )}
                                                            {editButton(
                                                                {
                                                                    kind: 'release-stop-sell',
                                                                    plan,
                                                                    roomType:
                                                                        room,
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
                        </div>
                    )}
                </section>
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
            <Dialog
                open={outOfOrder !== null}
                onOpenChange={(open) => !open && setOutOfOrder(null)}
            >
                <DialogContent>
                    {outOfOrder && (
                        <CalendarOutOfOrder
                            editing={outOfOrder}
                            businessDate={businessDate}
                            onDone={() => setOutOfOrder(null)}
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
    businessDate,
    priceEdit,
    restrictionEdit,
}: {
    plan: Plan;
    dates: string[];
    businessDate: string;
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
                        <td
                            key={date}
                            className={cn(
                                cell,
                                date === businessDate && 'bg-primary-tint/60',
                            )}
                        >
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
                        className={cn(
                            cell,
                            date === businessDate && 'bg-primary-tint/60',
                            'text-xs',
                            plan.restrictions[date] === null &&
                                'text-muted-foreground',
                        )}
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
                        <div className="grid gap-4 sm:grid-cols-2">
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
                            <div className="grid gap-4 sm:grid-cols-3">
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

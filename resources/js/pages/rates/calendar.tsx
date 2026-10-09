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
    outOfService: Record<string, number>;
    plans: Plan[];
};
type Editing = {
    kind: 'prices' | 'restrictions';
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
}: {
    from: string;
    to: string;
    dates: string[];
    allRoomTypes: RoomType[];
    roomTypes: Row[];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<Editing | null>(null);
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
                aria-label={`Edit ${name}`}
            >
                Edit
            </Button>
        );

    return (
        <>
            <Head title="Rate calendar" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Rate calendar
                </h1>
                <Form
                    {...rateCalendar.form()}
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ errors }) => (
                        <>
                            {(
                                [
                                    ['from', 'From', from],
                                    ['to', 'To', to],
                                ] as const
                            ).map(([name, text, value]) => (
                                <div key={name} className="space-y-2">
                                    <Label htmlFor={`range-${name}`}>
                                        {text}
                                    </Label>
                                    <Input
                                        id={`range-${name}`}
                                        name={name}
                                        type="date"
                                        defaultValue={value}
                                        required
                                        aria-invalid={!!errors[name]}
                                    />
                                    <InputError message={errors[name]} />
                                </div>
                            ))}
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
                    <div className="max-h-[70vh] overflow-auto rounded-lg border bg-card">
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
                                            restrictionEdit={editButton(
                                                {
                                                    kind: 'restrictions',
                                                    plan,
                                                    roomType: room,
                                                },
                                                `${plan.name} restrictions for ${room.name}`,
                                            )}
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

    return (
        <>
            <DialogTitle>
                {kind === 'prices' ? 'Set prices' : 'Set restrictions'}:{' '}
                {plan.name}, {roomType.name}
            </DialogTitle>
            <DialogDescription>
                {kind === 'prices'
                    ? `Amounts in ${plan.currency} minor units. Existing prices on selected dates are replaced.`
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
                                        <Input
                                            id={name}
                                            name={name}
                                            type="number"
                                            min={0}
                                            step={1}
                                            defaultValue={0}
                                            required
                                            aria-invalid={!!errors[name]}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="min_stay">
                                        Minimum stay (nights)
                                    </Label>
                                    <Input
                                        id="min_stay"
                                        name="min_stay"
                                        type="number"
                                        min={1}
                                        max={365}
                                        step={1}
                                        defaultValue={1}
                                        required
                                        className="max-w-40"
                                        aria-invalid={!!errors.min_stay}
                                    />
                                    <InputError message={errors.min_stay} />
                                </div>
                                <div className="flex flex-wrap gap-6">
                                    {(
                                        [
                                            [
                                                'closed_to_arrival',
                                                'Closed to arrival',
                                            ],
                                            ['stop_sell', 'Stop-sell'],
                                        ] as const
                                    ).map(([name, text]) => (
                                        <label
                                            key={name}
                                            className="flex min-h-11 items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                name={name}
                                                value="1"
                                                className="size-4 accent-primary"
                                            />
                                            {text}
                                        </label>
                                    ))}
                                </div>
                            </>
                        )}
                        <Button type="submit" className="min-h-11 self-end">
                            {processing && <Spinner />}Save
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

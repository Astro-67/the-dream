import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { prices } from '@/routes/rate-plans';
import { update } from '@/routes/rate-plans/restrictions';

type Restriction = {
    room_type_id: string;
    date: string;
    min_stay: number;
    closed_to_arrival: boolean;
    stop_sell: boolean;
};

const weekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

export default function Restrictions({
    plan,
    roomTypes,
    restrictions,
    canManage,
}: {
    plan: { id: string; name: string };
    roomTypes: { id: string; name: string }[];
    restrictions: Restriction[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title={`${plan.name} restrictions`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {plan.name} restrictions
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={prices(plan.id)}>Prices</Link>
                    </Button>
                </header>
                <p className="text-sm text-muted-foreground">
                    Restrictions are recorded here and applied when booking:
                    binding for channels, overridable by staff with a reason.
                </p>
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
                                        {weekdays.map((day, i) => (
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
                                    {[
                                        ['closed_to_arrival', 'Closed to arrival'],
                                        ['stop_sell', 'Stop-sell'],
                                    ].map(([name, label]) => (
                                        <label
                                            key={name}
                                            className="flex min-h-11 items-center gap-2"
                                        >
                                            <input
                                                type="checkbox"
                                                name={name}
                                                value="1"
                                                className="size-4 accent-primary"
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Existing restrictions on selected dates are
                                    replaced.
                                </p>
                                <Button type="submit" className="self-end">
                                    {processing && <Spinner />}Save
                                    restrictions
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {restrictions.length === 0 ? (
                    <p className="text-muted-foreground">
                        No restrictions set.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    {[
                                        'Date',
                                        'Room type',
                                        'Minimum stay',
                                        'Closed to arrival',
                                        'Stop-sell',
                                    ].map((label) => (
                                        <th key={label} className="p-3">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {restrictions.map((restriction) => (
                                    <tr
                                        key={
                                            restriction.date +
                                            restriction.room_type_id
                                        }
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 tabular-nums">
                                            {restriction.date}
                                        </td>
                                        <td className="p-3">
                                            {
                                                roomTypes.find(
                                                    (room) =>
                                                        room.id ===
                                                        restriction.room_type_id,
                                                )?.name
                                            }
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {restriction.min_stay}
                                        </td>
                                        <td className="p-3">
                                            {restriction.closed_to_arrival
                                                ? 'Closed'
                                                : 'Open'}
                                        </td>
                                        <td className="p-3">
                                            {restriction.stop_sell
                                                ? 'Stopped'
                                                : 'Selling'}
                                        </td>
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

import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
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
import { create as createBooker } from '@/routes/bookers';
import { store } from '@/routes/reservations';
import type { Paginated } from '@/types';

type Named = { id: string; name: string };
type Stay = {
    room_type_id: string;
    rate_plan_id: string;
    arrival: string;
    departure: string;
    adults: number;
    children: number;
    booker_id?: string;
    source?: string;
};

export default function Create({
    stay,
    roomType,
    ratePlan,
    bookers,
    rooms,
    restrictionWarnings,
}: {
    stay: Stay;
    roomType: Named;
    ratePlan: Named;
    bookers: Paginated<Named>;
    rooms: { id: string; number: string }[];
    restrictionWarnings: string[];
}) {
    return (
        <>
            <Head title="Book one room" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold">Book one room</h1>
                    <p className="text-sm text-muted-foreground">
                        {roomType.name} · {ratePlan.name} ·{' '}
                        {formatDate(stay.arrival, 'UTC')}–
                        {formatDate(stay.departure, 'UTC')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {stay.adults} adults, {stay.children} children. Prices
                        and availability are checked again when you confirm.
                    </p>
                </header>
                <Form {...store.form()} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            {(
                                [
                                    'room_type_id',
                                    'rate_plan_id',
                                    'arrival',
                                    'departure',
                                    'adults',
                                    'children',
                                ] as const
                            ).map((name) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={stay[name]}
                                />
                            ))}
                            {Object.entries(errors)
                                .filter(([name]) => name !== 'booker_id')
                                .map(([name, message]) => (
                                    <InputError key={name} message={message} />
                                ))}
                            {(restrictionWarnings.length > 0 ||
                                errors.restriction_override_reason) && (
                                <div className="max-w-[720px] space-y-3">
                                    <Alert className="border-status-warning bg-status-warning-tint">
                                        <AlertTitle>
                                            Restriction warnings
                                        </AlertTitle>
                                        <AlertDescription>
                                            <ul className="list-disc space-y-1 pl-4">
                                                {restrictionWarnings.map(
                                                    (warning) => (
                                                        <li key={warning}>
                                                            {warning}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                            <p>
                                                Give a reason to proceed.
                                                Exhausted inventory cannot be
                                                overridden.
                                            </p>
                                        </AlertDescription>
                                    </Alert>
                                    <Label htmlFor="restriction_override_reason">
                                        Restriction override reason
                                    </Label>
                                    <Input
                                        id="restriction_override_reason"
                                        name="restriction_override_reason"
                                        required
                                        maxLength={2000}
                                        aria-invalid={
                                            !!errors.restriction_override_reason
                                        }
                                        aria-describedby="restriction-override-error"
                                    />
                                    <div id="restriction-override-error">
                                        <InputError
                                            message={
                                                errors.restriction_override_reason
                                            }
                                        />
                                    </div>
                                </div>
                            )}
                            <fieldset className="space-y-3">
                                <legend className="font-medium">
                                    Choose the booker
                                </legend>
                                {bookers.data.length === 0 ? (
                                    <EmptyState
                                        message="Create a booker before confirming this reservation."
                                        action={
                                            <Button variant="outline" asChild>
                                                <Link href={createBooker()}>
                                                    Create booker
                                                </Link>
                                            </Button>
                                        }
                                    />
                                ) : (
                                    <>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Booker
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {bookers.data.map((booker) => (
                                                    <TableRow key={booker.id}>
                                                        <TableCell>
                                                            <label className="flex items-center gap-3">
                                                                <input
                                                                    type="radio"
                                                                    name="booker_id"
                                                                    value={
                                                                        booker.id
                                                                    }
                                                                    defaultChecked={
                                                                        stay.booker_id ===
                                                                        booker.id
                                                                    }
                                                                    required
                                                                    aria-describedby="booker-error"
                                                                />
                                                                {booker.name}
                                                            </label>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                        <Pagination page={bookers} />
                                    </>
                                )}
                                <div id="booker-error">
                                    <InputError message={errors.booker_id} />
                                </div>
                            </fieldset>
                            <div className="max-w-[720px] space-y-2">
                                <Label htmlFor="source">Source</Label>
                                <select
                                    id="source"
                                    name="source"
                                    defaultValue={stay.source ?? 'direct'}
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option value="direct">Direct</option>
                                    <option value="walk-in">Walk-in</option>
                                    <option value="phone">Phone</option>
                                </select>
                            </div>
                            <div className="max-w-[720px] space-y-2">
                                <Label htmlFor="room_id">
                                    Room assignment (optional)
                                </Label>
                                <select
                                    id="room_id"
                                    name="room_id"
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option value="">Leave unassigned</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Payment collector: property.
                            </p>
                            <Button
                                type="submit"
                                disabled={
                                    processing || bookers.data.length === 0
                                }
                                className="self-end"
                            >
                                {processing && <Spinner />}Confirm reservation
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

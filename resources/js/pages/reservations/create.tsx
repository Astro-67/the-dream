import { NativeSelect } from '@/components/ui/select';
import { useState } from 'react';
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
    rooms?: number;
    booker_id?: string;
    source?: string;
    channel_reference?: string;
    outside_integration_reason?: string;
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
    rooms: { id: string; number: string; isReady: boolean }[];
    restrictionWarnings: string[];
}) {
    const [source, setSource] = useState(stay.source ?? 'direct');
    const [collector, setCollector] = useState('property');
    return (
        <>
            <Head title="Book rooms" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold">Book rooms</h1>
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
                            <div className="max-w-[720px] space-y-2">
                                <Label htmlFor="rooms">Rooms</Label>
                                <Input
                                    id="rooms"
                                    name="rooms"
                                    type="number"
                                    min={1}
                                    max={20}
                                    defaultValue={stay.rooms ?? 1}
                                    required
                                    aria-invalid={!!errors.rooms}
                                    aria-describedby="rooms-help"
                                />
                                <p
                                    id="rooms-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    Guest counts and nightly prices apply to
                                    each room. Several rooms create a booking
                                    group with one reservation per room. An
                                    optional room assignment applies to the
                                    first reservation; assign the others from
                                    their detail pages.
                                </p>
                            </div>
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
                                <NativeSelect
                                    id="source"
                                    name="source"
                                    value={source}
                                    onChange={(event) =>
                                        setSource(event.target.value)
                                    }
                                >
                                    <option value="direct">Direct</option>
                                    <option value="walk-in">Walk-in</option>
                                    <option value="phone">Phone</option>
                                    <option value="outside-integration">
                                        Booking received outside integration
                                    </option>
                                </NativeSelect>
                            </div>
                            <div className="max-w-[720px] space-y-2">
                                <Label htmlFor="room_id">
                                    Room assignment (optional)
                                </Label>
                                <NativeSelect id="room_id" name="room_id">
                                    <option value="">Leave unassigned</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number} —{' '}
                                            {room.isReady
                                                ? 'Ready'
                                                : 'Not ready'}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            {source === 'outside-integration' && (
                                <fieldset className="max-w-[720px] space-y-3">
                                    <legend className="font-medium">
                                        Reconciliation details
                                    </legend>
                                    <p className="text-sm text-muted-foreground">
                                        This booking will be marked for
                                        reconciliation.
                                    </p>
                                    <Label htmlFor="channel_reference">
                                        Channel reference
                                    </Label>
                                    <Input
                                        id="channel_reference"
                                        name="channel_reference"
                                        defaultValue={stay.channel_reference}
                                        required
                                        maxLength={255}
                                        aria-invalid={
                                            !!errors.channel_reference
                                        }
                                        aria-describedby="channel-reference-error"
                                    />
                                    <div id="channel-reference-error">
                                        <InputError
                                            message={errors.channel_reference}
                                        />
                                    </div>
                                    <Label htmlFor="outside_integration_reason">
                                        Reason received outside integration
                                    </Label>
                                    <Input
                                        id="outside_integration_reason"
                                        name="outside_integration_reason"
                                        defaultValue={
                                            stay.outside_integration_reason
                                        }
                                        required
                                        maxLength={2000}
                                        aria-invalid={
                                            !!errors.outside_integration_reason
                                        }
                                        aria-describedby="outside-integration-error"
                                    />
                                    <div id="outside-integration-error">
                                        <InputError
                                            message={
                                                errors.outside_integration_reason
                                            }
                                        />
                                    </div>
                                </fieldset>
                            )}
                            <div className="max-w-[720px] space-y-2">
                                <Label htmlFor="payment_collector">
                                    Payment collector
                                </Label>
                                <NativeSelect
                                    id="payment_collector"
                                    name="payment_collector"
                                    value={collector}
                                    onChange={(event) =>
                                        setCollector(event.target.value)
                                    }
                                >
                                    <option value="property">Property</option>
                                    <option value="channel">Channel</option>
                                </NativeSelect>
                                <InputError
                                    message={errors.payment_collector}
                                />
                            </div>
                            {collector === 'channel' && (
                                <div className="max-w-[720px] space-y-2">
                                    <Label htmlFor="channel_collected_amount">
                                        Channel-collected amount in minor units
                                    </Label>
                                    <Input
                                        id="channel_collected_amount"
                                        name="channel_collected_amount"
                                        type="number"
                                        min={0}
                                        required
                                        aria-invalid={
                                            !!errors.channel_collected_amount
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors.channel_collected_amount
                                        }
                                    />
                                </div>
                            )}
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

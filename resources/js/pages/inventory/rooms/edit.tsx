import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    OutOfService,
    type OutOfServicePeriod,
} from '@/features/inventory/out-of-service';
import { RoomFields, RoomTypeSelect } from '@/features/inventory/room-fields';
import { index, move, update } from '@/routes/rooms';
import type { SelectOption } from '@/types';

type RoomValues = {
    id: string;
    number: string;
    floor: string | null;
    room_type_id: string;
};

export default function EditRoom({
    room,
    roomTypes,
    outOfService,
    today,
}: {
    room: RoomValues;
    roomTypes: SelectOption[];
    outOfService: OutOfServicePeriod[];
    today: string;
}) {
    return (
        <>
            <Head title={`Edit room ${room.number}`} />
            <div className="mx-auto flex w-full max-w-[720px] min-w-0 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight break-words">
                        Room {room.number}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Room details and the room type it is sold as.
                    </p>
                </header>

                <Form
                    {...update.form(room.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <div className="grid gap-6 sm:grid-cols-2">
                                <RoomFields values={room} errors={errors} />
                            </div>
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <section
                    aria-labelledby="move-room"
                    className="flex flex-col gap-4 border-t pt-6"
                >
                    <div className="space-y-1">
                        <h2
                            id="move-room"
                            className="text-lg font-semibold tracking-tight"
                        >
                            Room type
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Move the room to another room type to correct a
                            setup mistake.
                        </p>
                    </div>
                    <Form
                        {...move.form(room.id)}
                        disableWhileProcessing
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <PropertyIntent />
                                <InputError
                                    message={
                                        errors._property ?? errors.inventory
                                    }
                                />
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <RoomTypeSelect
                                        options={roomTypes}
                                        value={room.room_type_id}
                                        error={errors.room_type_id}
                                    />
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" variant="secondary">
                                        {processing && <Spinner />}
                                        Move room
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <OutOfService
                    roomId={room.id}
                    periods={outOfService}
                    today={today}
                />
            </div>
        </>
    );
}

EditRoom.layout = {
    breadcrumbs: [
        { title: 'Rooms', href: index() },
        { title: 'Edit room', href: index() },
    ],
};

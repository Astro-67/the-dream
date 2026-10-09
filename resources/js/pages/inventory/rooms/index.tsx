import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { index as roomTypesIndex } from '@/routes/room-types';
import { create, deactivate, edit, index } from '@/routes/rooms';
import type { SelectOption } from '@/types';

type RoomRow = {
    id: string;
    number: string;
    floor: string | null;
    roomType: { id: string; code: string; name: string };
    deactivatedAt: string | null;
};

function DeactivateRoom({ room }: { room: RoomRow }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Deactivate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Deactivate room {room.number}?</DialogTitle>
                <DialogDescription>
                    It is no longer sold and leaves the active lists. The room
                    and its history are kept.
                </DialogDescription>
                <Form
                    {...deactivate.form(room.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError
                                message={errors._property ?? errors.inventory}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Keep it</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    type="submit"
                                >
                                    Deactivate room
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeactivatedChip() {
    return (
        <span className="ml-2 inline-flex items-center gap-1.5 rounded-full bg-status-unavailable-tint px-2 py-0.5 text-xs font-medium text-status-unavailable">
            <span
                aria-hidden
                className="size-1.5 rounded-full bg-status-unavailable"
            />
            Deactivated
        </span>
    );
}

function RoomsTable({
    rooms,
    actions,
}: {
    rooms: RoomRow[];
    actions?: (room: RoomRow) => React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="sticky top-0 border-b bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th className="h-10 px-4 font-medium">Room</th>
                        <th className="h-10 px-4 font-medium">Floor</th>
                        <th className="h-10 px-4 font-medium">Room type</th>
                        {actions && (
                            <th className="h-10 px-4">
                                <span className="sr-only">Actions</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rooms.map((room) => (
                        <tr key={room.id} className="h-10 hover:bg-muted/40">
                            <td className="px-4 py-2 font-medium tabular-nums">
                                {room.number}
                                {room.deactivatedAt && <DeactivatedChip />}
                            </td>
                            <td className="px-4 py-2">{room.floor ?? '—'}</td>
                            <td className="px-4 py-2">
                                <span className="font-mono">
                                    {room.roomType.code}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {room.roomType.name}
                                </span>
                            </td>
                            {actions && (
                                <td className="px-4 py-2">
                                    <div className="flex justify-end gap-2">
                                        {actions(room)}
                                    </div>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function RoomsIndex({
    rooms,
    deactivatedRooms,
    roomTypes,
    canManage,
}: {
    rooms: RoomRow[];
    deactivatedRooms: RoomRow[];
    roomTypes: SelectOption[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Rooms" />
            <div className="flex flex-col gap-6 p-4 md:gap-8 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Rooms
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The physical rooms of this property, by{' '}
                            <Link
                                href={roomTypesIndex()}
                                className="font-medium text-primary underline-offset-4 hover:underline"
                            >
                                room type
                            </Link>
                            .
                        </p>
                    </div>
                    {canManage && roomTypes.length > 0 && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus aria-hidden />
                                Add room
                            </Link>
                        </Button>
                    )}
                </header>

                {rooms.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No active rooms.
                    </p>
                ) : (
                    <RoomsTable
                        rooms={rooms}
                        actions={
                            canManage
                                ? (room) => (
                                      <>
                                          <Button
                                              variant="ghost"
                                              size="sm"
                                              asChild
                                          >
                                              <Link
                                                  href={edit(room.id)}
                                                  aria-label={`Edit room ${room.number}`}
                                              >
                                                  Edit
                                              </Link>
                                          </Button>
                                          <DeactivateRoom room={room} />
                                      </>
                                  )
                                : undefined
                        }
                    />
                )}

                {deactivatedRooms.length > 0 && (
                    <section
                        aria-labelledby="deactivated-rooms"
                        className="flex flex-col gap-3"
                    >
                        <div className="space-y-1">
                            <h2
                                id="deactivated-rooms"
                                className="text-lg font-semibold tracking-tight"
                            >
                                Deactivated
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                No longer sold. Kept with their history.
                            </p>
                        </div>
                        <RoomsTable rooms={deactivatedRooms} />
                    </section>
                )}
            </div>
        </>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'Rooms', href: index() }],
};

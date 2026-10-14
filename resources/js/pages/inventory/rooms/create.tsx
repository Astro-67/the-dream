import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { RoomFields, RoomTypeSelect } from '@/features/inventory/room-fields';
import { create as createRoomType } from '@/routes/room-types';
import { create, index, store } from '@/routes/rooms';
import type { SelectOption } from '@/types';

export default function CreateRoom({
    roomTypes,
}: {
    roomTypes: SelectOption[];
}) {
    return (
        <>
            <Head title="Add room" />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Add room
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        A physical room of this property and the room type it is
                        sold as.
                    </p>
                </header>

                {roomTypes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Rooms belong to a room type.{' '}
                        <Link
                            href={createRoomType()}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Add a room type
                        </Link>{' '}
                        first.
                    </p>
                ) : (
                    <Form
                        {...store.form()}
                        disableWhileProcessing
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <PropertyIntent />
                                <InputError message={errors._property} />
                                <InputError
                                    message={errors.subscription ?? errors.room}
                                    role="alert"
                                />
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <RoomFields errors={errors} />
                                    <RoomTypeSelect
                                        options={roomTypes}
                                        error={errors.room_type_id}
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-end gap-3">
                                    <Button variant="ghost" asChild>
                                        <Link href={index()}>Cancel</Link>
                                    </Button>
                                    <Button type="submit">
                                        {processing && <Spinner />}
                                        Add room
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

CreateRoom.layout = {
    breadcrumbs: [
        { title: 'Rooms', href: index() },
        { title: 'Add room', href: create() },
    ],
};

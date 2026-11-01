import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { RoomTypeValues } from '@/features/inventory/fields';
import { RoomTypeFields } from '@/features/inventory/fields';
import { index, update } from '@/routes/room-types';

export default function EditRoomType({
    roomType,
}: {
    roomType: RoomTypeValues;
}) {
    return (
        <>
            <Head title={`Edit ${roomType.name}`} />
            <div className="mx-auto flex w-full max-w-[720px] min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight break-words">
                        {roomType.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Room type details and occupancy limits.
                    </p>
                </header>

                <Form
                    {...update.form(roomType.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <RoomTypeFields values={roomType} errors={errors} />
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
            </div>
        </>
    );
}

EditRoomType.layout = {
    breadcrumbs: [
        { title: 'Room types', href: index() },
        { title: 'Edit room type', href: index() },
    ],
};

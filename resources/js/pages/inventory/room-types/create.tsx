import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { RoomTypeFields } from '@/features/inventory/fields';
import { create, index, store } from '@/routes/room-types';

export default function CreateRoomType() {
    return (
        <>
            <Head title="Add room type" />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Add room type
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        A kind of room this property sells, with how many guests
                        it holds.
                    </p>
                </header>

                <Form
                    {...store.form()}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <RoomTypeFields errors={errors} />
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Add room type
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateRoomType.layout = {
    breadcrumbs: [
        { title: 'Room types', href: index() },
        { title: 'Add room type', href: create() },
    ],
};

import { Form } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/** Changes an organization's subscribed rooms: the owner's own, or any organization's on the platform page. */
export function SubscribedRoomsForm({
    action,
    rooms,
    note,
}: {
    action: Pick<ComponentProps<typeof Form>, 'action' | 'method'>;
    rooms: number;
    note: string;
}) {
    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-end gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="subscribed-rooms">
                            Subscribed rooms
                        </Label>
                        <Input
                            id="subscribed-rooms"
                            name="rooms"
                            type="number"
                            min={1}
                            required
                            defaultValue={rooms}
                            className="w-32 tabular-nums"
                            aria-invalid={!!errors.rooms}
                            aria-describedby="subscribed-rooms-note"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Update rooms
                    </Button>
                    <p
                        id="subscribed-rooms-note"
                        className="w-full text-sm text-muted-foreground"
                    >
                        {note}
                    </p>
                    <InputError className="w-full" message={errors.rooms} />
                </>
            )}
        </Form>
    );
}

import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { roomMove } from '@/routes/reservations';
import { Spinner } from '@/components/ui/spinner';

export type MoveRoom = {
    id: string;
    number: string;
    roomType: string;
    isReady: boolean;
};

export function RoomMoveForm({
    number,
    rooms,
}: {
    number: string;
    rooms: MoveRoom[];
}) {
    return (
        <Form
            {...roomMove.form(number)}
            className="max-w-[720px] space-y-3"
            resetOnSuccess
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <h2 className="text-lg font-semibold">Move room</h2>
                    <div className="space-y-2">
                        <Label htmlFor="move-room_id">New room</Label>
                        <NativeSelect
                            id="move-room_id"
                            name="room_id"
                            defaultValue=""
                            required
                            aria-describedby="move-room-error"
                        >
                            <option value="" disabled>
                                Choose a room
                            </option>
                            {rooms.map((room) => (
                                <option
                                    key={room.id}
                                    value={room.id}
                                    disabled={!room.isReady}
                                >
                                    {room.number} — {room.roomType} —{' '}
                                    {room.isReady ? 'Ready' : 'Not ready'}
                                </option>
                            ))}
                        </NativeSelect>
                        <div id="move-room-error">
                            <InputError
                                message={
                                    errors.room_id ??
                                    errors.room_type_id ??
                                    errors.reservation
                                }
                            />
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="move-pricing">Nightly prices</Label>
                        <NativeSelect
                            id="move-pricing"
                            name="pricing"
                            defaultValue="keep"
                            aria-describedby="move-pricing-error"
                        >
                            <option value="keep">Keep original prices</option>
                            <option value="reprice">
                                Re-price remaining nights
                            </option>
                        </NativeSelect>
                        <div id="move-pricing-error">
                            <InputError message={errors.pricing} />
                        </div>
                    </div>
                    <Button disabled={processing}>
                        <span className="size-4">
                            {processing && <Spinner />}
                        </span>
                        Move guest
                    </Button>
                </>
            )}
        </Form>
    );
}

import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    PeriodFields,
    type OutOfServicePeriod,
} from '@/features/inventory/out-of-service';
import type { CalendarRoom } from '@/features/reservations/calendar-rows';
import { formatDate } from '@/lib/utils';
import { store, update } from '@/routes/out-of-service';

export type OutOfOrderEditing = {
    room: CalendarRoom;
    date: string;
    period?: OutOfServicePeriod;
};

export function CalendarOutOfOrder({
    editing: { room, date, period },
    businessDate,
    onDone,
}: {
    editing: OutOfOrderEditing;
    businessDate: string;
    onDone: () => void;
}) {
    const nextDay = new Date(`${date}T00:00:00Z`);
    nextDay.setUTCDate(nextDay.getUTCDate() + 1);

    return (
        <>
            <DialogTitle>
                {period
                    ? `Return room ${room.number} to service`
                    : `Take room ${room.number} out of order`}
            </DialogTitle>
            <DialogDescription>
                {period
                    ? `${formatDate(period.startsOn)} to ${formatDate(period.returnsOn)}: ${period.reason}. This releases the remaining nights; past nights stay unchanged.`
                    : 'Choose the nights and a reason. The back-in-service date is the first night the room can be sold again.'}
            </DialogDescription>
            <Form
                {...(period ? update.form(period.id) : store.form(room.id))}
                options={{ preserveScroll: true }}
                onSuccess={onDone}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ errors, processing }) => (
                    <>
                        <PropertyIntent />
                        <input type="hidden" name="calendar" value="1" />
                        {period ? (
                            <>
                                <input
                                    type="hidden"
                                    name="starts_on"
                                    value={period.startsOn}
                                />
                                <input
                                    type="hidden"
                                    name="returns_on"
                                    value={
                                        period.startsOn > businessDate
                                            ? period.startsOn
                                            : businessDate
                                    }
                                />
                                <input
                                    type="hidden"
                                    name="reason"
                                    value={period.reason}
                                />
                                {Object.entries(errors).map(
                                    ([field, message]) => (
                                        <InputError
                                            key={field}
                                            message={message}
                                        />
                                    ),
                                )}
                            </>
                        ) : (
                            <PeriodFields
                                today={businessDate}
                                initialDates={{
                                    startsOn: date,
                                    returnsOn: nextDay
                                        .toISOString()
                                        .slice(0, 10),
                                }}
                                errors={errors}
                            />
                        )}
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit">
                                {processing && <Spinner />}
                                {period
                                    ? 'Return to service'
                                    : 'Take out of order'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </Form>
        </>
    );
}

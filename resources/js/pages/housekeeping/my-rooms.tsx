import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/lib/utils';
import { myRooms } from '@/routes/housekeeping';
import { complete } from '@/routes/housekeeping/assignments';

export default function MyRooms({
    date,
    assignments,
}: {
    date: string;
    assignments: {
        id: string;
        room: { number: string; floor: string | null };
        completed: boolean;
    }[];
}) {
    return (
        <>
            <Head title="My rooms" />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="My rooms"
                    description={`Cleaning assignments · business date ${formatDate(date)}`}
                />
                <Form
                    {...myRooms.form()}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="date">Business date</Label>
                        <Input
                            id="date"
                            name="date"
                            type="date"
                            defaultValue={date}
                            required
                        />
                    </div>
                    <Button type="submit" variant="secondary" size="lg">
                        View date
                    </Button>
                </Form>
                {assignments.length === 0 ? (
                    <EmptyState message="No rooms assigned for this business date. Ask your supervisor for assignments." />
                ) : (
                    <ul className="grid gap-4">
                        {assignments.map((assignment) => (
                            <li
                                key={assignment.id}
                                className="grid gap-4 rounded-xl border bg-card p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-lg tabular-nums">
                                            Room {assignment.room.number}
                                        </h2>
                                        <p className="text-muted-foreground">
                                            {assignment.room.floor === null
                                                ? 'No floor'
                                                : `Floor ${assignment.room.floor}`}
                                        </p>
                                    </div>
                                    <StatusChip
                                        tone={
                                            assignment.completed
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {assignment.completed
                                            ? 'Done'
                                            : 'Assigned'}
                                    </StatusChip>
                                </div>
                                {!assignment.completed && (
                                    <Form
                                        {...complete.form(assignment.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing, errors }) => (
                                            <div className="grid gap-2">
                                                <PropertyIntent />
                                                <Button
                                                    type="submit"
                                                    size="lg"
                                                    className="w-full whitespace-normal"
                                                    disabled={
                                                        processing ||
                                                        !navigator.onLine
                                                    }
                                                    aria-label={`Mark room ${assignment.room.number} done`}
                                                >
                                                    {processing
                                                        ? 'Saving…'
                                                        : 'Mark done'}
                                                </Button>
                                                <InputError
                                                    message={
                                                        errors.status ??
                                                        errors._property
                                                    }
                                                />
                                            </div>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

MyRooms.layout = { breadcrumbs: [{ title: 'My rooms', href: myRooms() }] };

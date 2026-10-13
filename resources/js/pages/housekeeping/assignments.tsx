import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index } from '@/routes/housekeeping';
import {
    destroy,
    index as assignmentsIndex,
    store,
} from '@/routes/housekeeping/assignments';
import { Spinner } from '@/components/ui/spinner';

export default function Assignments({
    date,
    rooms,
    housekeepers,
    assignments,
}: {
    date: string;
    rooms: { id: string; number: string }[];
    housekeepers: { id: string; name: string }[];
    assignments: {
        id: string;
        room: { number: string };
        housekeeper: { name: string };
        completed: boolean;
    }[];
}) {
    return (
        <>
            <Head title="Housekeeping assignments" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Housekeeping assignments"
                    description="Assign rooms to housekeepers for a business date."
                />
                <Form
                    {...assignmentsIndex.form()}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="date">Business date</Label>
                        <Input
                            id="date"
                            type="date"
                            name="date"
                            defaultValue={date}
                            required
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        View date
                    </Button>
                </Form>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    className="flex max-w-[720px] flex-wrap items-end gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <input
                                type="hidden"
                                name="business_date"
                                value={date}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="room">Room</Label>
                                <NativeSelect id="room" name="room_id" required>
                                    <option value="">Choose room</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.room_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="housekeeper">Housekeeper</Label>
                                <NativeSelect
                                    id="housekeeper"
                                    name="housekeeper_id"
                                    required
                                >
                                    <option value="">Choose housekeeper</option>
                                    {housekeepers.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.housekeeper_id} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing || !navigator.onLine}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                Assign room
                            </Button>
                            <InputError
                                message={
                                    errors.business_date ?? errors._property
                                }
                            />
                        </>
                    )}
                </Form>
                {assignments.length === 0 ? (
                    <EmptyState message="No assignments for this business date. Choose a room and housekeeper to assign it." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Room</TableHead>
                                <TableHead>Housekeeper</TableHead>
                                <TableHead>Cleaning</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {assignments.map((assignment) => (
                                <TableRow key={assignment.id}>
                                    <TableCell className="tabular-nums">
                                        {assignment.room.number}
                                    </TableCell>
                                    <TableCell>
                                        {assignment.housekeeper.name}
                                    </TableCell>
                                    <TableCell>
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
                                    </TableCell>
                                    <TableCell>
                                        <Form
                                            {...destroy.form(assignment.id)}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <PropertyIntent />
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        className="min-h-11"
                                                        disabled={
                                                            processing ||
                                                            !navigator.onLine
                                                        }
                                                        aria-label={`Unassign room ${assignment.room.number} from ${assignment.housekeeper.name}`}
                                                    >
                                                        <span className="size-4">
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                        </span>
                                                        Unassign
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            errors._property
                                                        }
                                                    />
                                                </>
                                            )}
                                        </Form>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}

Assignments.layout = {
    breadcrumbs: [
        { title: 'Housekeeping', href: index() },
        { title: 'Assignments', href: assignmentsIndex() },
    ],
};

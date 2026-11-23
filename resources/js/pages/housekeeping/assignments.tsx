import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Clock3, DoorOpen } from 'lucide-react';
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
import { HousekeepingMetricCards } from '@/features/housekeeping/metric-cards';

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
    const completedCount = assignments.filter(
        (assignment) => assignment.completed,
    ).length;

    return (
        <>
            <Head title="Housekeeping assignments" />
            <div className="flex min-w-0 flex-col gap-6">
                <header className="flex items-center gap-4 border-b border-border pb-6">
                    <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel">
                        <DoorOpen aria-hidden="true" className="size-6" />
                    </span>
                    <div className="space-y-1">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Rooms and service
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Housekeeping assignments
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Assign rooms to housekeepers for a business date.
                        </p>
                    </div>
                </header>
                <HousekeepingMetricCards
                    columns="three"
                    metrics={[
                        {
                            label: 'Rooms assigned',
                            value: assignments.length,
                            icon: ClipboardList,
                            tone: 'primary',
                        },
                        {
                            label: 'Completed',
                            value: completedCount,
                            icon: CheckCircle2,
                            tone: 'success',
                        },
                        {
                            label: 'Still to clean',
                            value: assignments.length - completedCount,
                            icon: Clock3,
                            tone: 'warning',
                        },
                    ]}
                />
                <Form
                    {...assignmentsIndex.form()}
                    className="grid min-w-0 items-end gap-3 border border-border bg-card p-3 shadow-panel sm:grid-cols-[minmax(200px,260px)_auto] md:p-4"
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
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="grid max-w-5xl min-w-0 items-end gap-3 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:p-5 [&>div]:max-w-full [&>div]:min-w-0"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <h2 className="text-base font-semibold md:col-span-3">
                                Create a room assignment
                            </h2>
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
                                className="md:col-span-3"
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

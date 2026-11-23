import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, DoorOpen, Sparkles } from 'lucide-react';
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
import { index as maintenance } from '@/routes/maintenance';
import { useCan } from '@/hooks/use-can';
import { Spinner } from '@/components/ui/spinner';
import { HousekeepingMetricCards } from '@/features/housekeeping/metric-cards';
import { cn } from '@/lib/utils';

export default function MyRooms({
    date,
    assignments,
}: {
    date: string;
    assignments: {
        id: string;
        room: { id: string; number: string; floor: string | null };
        completed: boolean;
    }[];
}) {
    const can = useCan();
    const completedCount = assignments.filter(
        (assignment) => assignment.completed,
    ).length;

    return (
        <>
            <Head title="My rooms" />
            <div className="flex w-full min-w-0 flex-col gap-6">
                <header className="flex items-center gap-4 border-b border-border pb-6">
                    <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel">
                        <DoorOpen aria-hidden="true" className="size-6" />
                    </span>
                    <div className="space-y-1">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Rooms and service
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            My rooms
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Cleaning assignments · business date{' '}
                            {formatDate(date)}
                        </p>
                    </div>
                </header>
                <HousekeepingMetricCards
                    columns="three"
                    metrics={[
                        {
                            label: 'Assigned to you',
                            value: assignments.length,
                            icon: DoorOpen,
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
                            icon: Sparkles,
                            tone: 'warning',
                        },
                    ]}
                />
                <Form
                    {...myRooms.form()}
                    className="grid min-w-0 items-end gap-3 border border-border bg-card p-3 shadow-panel sm:grid-cols-[minmax(200px,260px)_auto] md:p-4"
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
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {assignments.map((assignment) => (
                            <li
                                key={assignment.id}
                                className={cn(
                                    'grid min-w-0 content-start gap-4 border border-t-4 border-border bg-card p-4 shadow-panel md:p-5',
                                    assignment.completed
                                        ? 'border-t-status-success'
                                        : 'border-t-status-warning',
                                )}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-xl font-semibold break-words tabular-nums">
                                            Room {assignment.room.number}
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
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
                                {can('maintenance.report') && (
                                    <Button
                                        asChild
                                        variant="secondary"
                                        size="lg"
                                        className="w-full whitespace-normal"
                                    >
                                        <Link
                                            href={maintenance({
                                                query: {
                                                    room: assignment.room.id,
                                                },
                                            })}
                                            aria-label={`Report a problem in room ${assignment.room.number}`}
                                        >
                                            Report a problem
                                        </Link>
                                    </Button>
                                )}
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
                                                    <span className="size-4">
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                    </span>
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

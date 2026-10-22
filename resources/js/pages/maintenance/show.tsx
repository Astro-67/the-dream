import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip, Tag } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatTime } from '@/lib/utils';
import { assign, complete, index } from '@/routes/maintenance';
import { PriorityChip, type TicketSummary } from './index';

const events: Record<string, string> = {
    reported: 'Reported',
    assigned: 'Assigned',
    reassigned: 'Reassigned',
    completed: 'Completed',
};

export default function MaintenanceTicket({
    ticket,
    canAssign,
    canComplete,
    assignees,
    history,
    timezone,
}: {
    ticket: TicketSummary & { description: string };
    canAssign: boolean;
    canComplete: boolean;
    assignees: { id: string; name: string }[];
    history: {
        id: string;
        event: string;
        actor: string;
        from: string | null;
        to: string | null;
        recordedAt: string;
    }[];
    timezone: string;
}) {
    return (
        <>
            <Head title={ticket.title} />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={ticket.title}
                    description={`${ticket.place ?? 'No room or area given'} · reported by ${ticket.reportedBy}`}
                />
                <div className="flex flex-wrap items-center gap-2">
                    <StatusChip tone={ticket.done ? 'success' : 'info'}>
                        {ticket.done ? 'Done' : 'Open'}
                    </StatusChip>
                    <PriorityChip ticket={ticket} />
                    <Tag>{ticket.category}</Tag>
                    <span className="text-sm text-muted-foreground">
                        Assigned to {ticket.assignee ?? 'nobody yet'}
                    </span>
                </div>
                <p className="whitespace-pre-line">{ticket.description}</p>
                <p className="text-sm text-muted-foreground">
                    Finishing a ticket does not change the room's status or
                    availability. Use an out-of-service period to block a room.
                </p>
                {canAssign && (
                    <Form
                        {...assign.form(ticket.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <PropertyIntent />
                                <div className="grid gap-2">
                                    <Label htmlFor="assignee">Assign to</Label>
                                    <NativeSelect
                                        id="assignee"
                                        name="assignee_id"
                                        required
                                    >
                                        <option value="">Choose member</option>
                                        {assignees.map((user) => (
                                            <option
                                                key={user.id}
                                                value={user.id}
                                            >
                                                {user.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError
                                        message={
                                            errors.assignee_id ??
                                            errors._property
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    size="lg"
                                    disabled={processing || !navigator.onLine}
                                >
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    {ticket.assignee ? 'Reassign' : 'Assign'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {canComplete && (
                    <Form
                        {...complete.form(ticket.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <div className="grid gap-2">
                                <PropertyIntent />
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="justify-self-start"
                                    disabled={processing || !navigator.onLine}
                                >
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    Mark done
                                </Button>
                                <InputError message={errors._property} />
                            </div>
                        )}
                    </Form>
                )}
                <section className="grid gap-3">
                    <h2 className="text-lg">History</h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {history.map((entry) => (
                                <TableRow key={entry.id}>
                                    <TableCell className="tabular-nums">
                                        {formatDate(entry.recordedAt, timezone)}{' '}
                                        {formatTime(entry.recordedAt, timezone)}
                                    </TableCell>
                                    <TableCell>
                                        {events[entry.event] ?? entry.event}
                                    </TableCell>
                                    <TableCell>{entry.actor}</TableCell>
                                    <TableCell className="whitespace-normal">
                                        {entry.to &&
                                            (entry.from
                                                ? `${entry.from} → ${entry.to}`
                                                : entry.to)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

MaintenanceTicket.layout = {
    breadcrumbs: [
        { title: 'Maintenance', href: index() },
        { title: 'Ticket', href: '#' },
    ],
};

import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
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
import { formatDate } from '@/lib/utils';
import { index, show, store } from '@/routes/maintenance';
import type { Paginated } from '@/types';

type Option = { value: string; label: string };
export type TicketSummary = {
    id: string;
    title: string;
    category: string;
    priority: string;
    priorityValue: string;
    place: string | null;
    assignee: string | null;
    reportedBy: string;
    reportedAt: string;
    completedAt: string | null;
    done: boolean;
};

const views = [
    { value: 'open', label: 'Open' },
    { value: 'mine', label: 'Mine' },
    { value: 'done', label: 'Done' },
] as const;

export default function MaintenanceIndex({
    view,
    tickets,
    rooms,
    room,
    categories,
    priorities,
}: {
    view: string;
    tickets: Paginated<TicketSummary>;
    rooms: { id: string; number: string }[];
    room: string | null;
    categories: Option[];
    priorities: Option[];
}) {
    // A room link from housekeeping opens the form for that room.
    const [reporting, setReporting] = useState(room !== null);
    return (
        <>
            <Head title="Maintenance" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Maintenance"
                        description="Report problems and follow repair work in this property."
                    />
                    <Dialog open={reporting} onOpenChange={setReporting}>
                        <DialogTrigger asChild>
                            <Button>Report a problem</Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-[720px]">
                            <DialogTitle>Report a problem</DialogTitle>
                            <DialogDescription>
                                The ticket joins the open board for this
                                property.
                            </DialogDescription>
                            <Form
                                {...store.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setReporting(false)}
                                className="grid gap-4 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <PropertyIntent />
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="title">Title</Label>
                                            <Input
                                                id="title"
                                                name="title"
                                                maxLength={255}
                                                required
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                        </div>
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="description">
                                                Description
                                            </Label>
                                            <textarea
                                                id="description"
                                                name="description"
                                                rows={3}
                                                maxLength={5000}
                                                required
                                                aria-invalid={
                                                    !!errors.description
                                                }
                                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                            />
                                            <InputError
                                                message={errors.description}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="category">
                                                Category
                                            </Label>
                                            <NativeSelect
                                                id="category"
                                                name="category"
                                                required
                                            >
                                                {categories.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError
                                                message={errors.category}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="priority">
                                                Priority
                                            </Label>
                                            <NativeSelect
                                                id="priority"
                                                name="priority"
                                                defaultValue="medium"
                                                required
                                            >
                                                {priorities.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError
                                                message={errors.priority}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="room">
                                                Room (optional)
                                            </Label>
                                            <NativeSelect
                                                id="room"
                                                name="room_id"
                                                defaultValue={room ?? ''}
                                            >
                                                <option value="">
                                                    No room
                                                </option>
                                                {rooms.map((option) => (
                                                    <option
                                                        key={option.id}
                                                        value={option.id}
                                                    >
                                                        {option.number}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError
                                                message={errors.room_id}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="location">
                                                Common area (optional)
                                            </Label>
                                            <Input
                                                id="location"
                                                name="location"
                                                maxLength={255}
                                            />
                                            <InputError
                                                message={errors.location}
                                            />
                                        </div>
                                        <InputError
                                            message={errors._property}
                                            className="sm:col-span-2"
                                        />
                                        <DialogFooter className="gap-2 sm:col-span-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    processing ||
                                                    !navigator.onLine
                                                }
                                            >
                                                <span className="size-4">
                                                    {processing && <Spinner />}
                                                </span>
                                                Report problem
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
                <nav aria-label="Ticket views" className="flex gap-2">
                    {views.map((option) => (
                        <Link
                            key={option.value}
                            href={index({ query: { view: option.value } })}
                            aria-current={
                                view === option.value ? 'page' : undefined
                            }
                            className="inline-flex min-h-11 items-center"
                        >
                            <Tag
                                active={view === option.value}
                                className="px-3 py-1.5 text-sm"
                            >
                                {option.label}
                            </Tag>
                        </Link>
                    ))}
                </nav>
                {tickets.data.length === 0 ? (
                    <EmptyState
                        message={
                            view === 'done'
                                ? 'No finished tickets yet.'
                                : 'No open tickets.'
                        }
                    />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Ticket</TableHead>
                                    <TableHead>Where</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Assigned to</TableHead>
                                    <TableHead>Reported</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tickets.data.map((ticket) => (
                                    <TableRow key={ticket.id}>
                                        <TableCell className="whitespace-normal">
                                            <Link
                                                href={show(ticket.id)}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                {ticket.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {ticket.place ?? 'Not given'}
                                        </TableCell>
                                        <TableCell>
                                            <Tag>{ticket.category}</Tag>
                                        </TableCell>
                                        <TableCell>
                                            <PriorityChip ticket={ticket} />
                                        </TableCell>
                                        <TableCell>
                                            {ticket.assignee ?? 'Unassigned'}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDate(
                                                ticket.reportedAt,
                                                'UTC',
                                            )}{' '}
                                            · {ticket.reportedBy}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    ticket.done
                                                        ? 'success'
                                                        : 'info'
                                                }
                                            >
                                                {ticket.done ? 'Done' : 'Open'}
                                            </StatusChip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={tickets} />
                    </div>
                )}
            </div>
        </>
    );
}

export function PriorityChip({ ticket }: { ticket: TicketSummary }) {
    return (
        <StatusChip
            tone={
                ticket.priorityValue === 'high'
                    ? 'error'
                    : ticket.priorityValue === 'medium'
                      ? 'warning'
                      : 'unavailable'
            }
        >
            {ticket.priority}
        </StatusChip>
    );
}

MaintenanceIndex.layout = {
    breadcrumbs: [{ title: 'Maintenance', href: index() }],
};

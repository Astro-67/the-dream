import { Head } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import { EmptyState } from '@/components/ui/empty-state';
import { Pagination } from '@/components/ui/pagination';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { formatDate, formatTime } from '@/lib/utils';
import { index } from '@/routes/housekeeping';
import type { Paginated } from '@/types';

type Change = {
    id: string;
    kind: string;
    businessDate: string | null;
    from: string;
    to: string;
    fromTone: StatusTone;
    toTone: StatusTone;
    reason: string;
    actor: string;
    changedAt: string;
    changedOn: string;
};

export default function RoomHistory({
    room,
    changes,
    timezone,
    businessDate,
}: {
    room: { id: string; number: string };
    changes: Paginated<Change>;
    timezone: string;
    businessDate: string;
}) {
    return (
        <>
            <Head title={`Room ${room.number} history`} />
            <div className="flex min-w-0 flex-col gap-6">
                <header className="flex items-center gap-4 border-b border-border pb-6">
                    <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel">
                        <DoorOpen aria-hidden="true" className="size-6" />
                    </span>
                    <div className="min-w-0 space-y-1">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Rooms and service
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight break-words">
                            Room {room.number} history
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Status changes and readiness overrides, newest
                            first. Times in {timezone}. Business date{' '}
                            {formatDate(businessDate)}.
                        </p>
                    </div>
                </header>
                {changes.data.length === 0 ? (
                    <EmptyState message="No room history yet." />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Event</TableHead>
                                    <TableHead>From</TableHead>
                                    <TableHead>To</TableHead>
                                    <TableHead>Changed by</TableHead>
                                    <TableHead>Reason</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {changes.data.map((change) => (
                                    <TableRow key={change.id}>
                                        <TableCell className="tabular-nums">
                                            {formatDate(change.changedOn)}{' '}
                                            {formatTime(
                                                change.changedAt,
                                                timezone,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {change.kind === 'ready_override'
                                                ? `Ready override · Business date ${formatDate(change.businessDate!)}`
                                                : 'Status change'}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip tone={change.fromTone}>
                                                {change.from}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip tone={change.toTone}>
                                                {change.to}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>{change.actor}</TableCell>
                                        <TableCell className="whitespace-normal">
                                            {change.reason}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={changes} />
                    </div>
                )}
            </div>
        </>
    );
}

RoomHistory.layout = {
    breadcrumbs: [
        { title: 'Housekeeping', href: index() },
        { title: 'Room history', href: '#' },
    ],
};

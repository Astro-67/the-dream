import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { BedDouble, CheckCircle2, DoorOpen, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import type { SelectOption } from '@/types';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { history, index, status, overrideReady } from '@/routes/housekeeping';
import { index as maintenance } from '@/routes/maintenance';
import { useCan } from '@/hooks/use-can';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { HousekeepingMetricCards } from '@/features/housekeeping/metric-cards';

type RoomRow = {
    id: string;
    number: string;
    floor: string | null;
    roomType: { code: string; name: string };
    status: string;
    statusLabel: string;
    stayoverService: boolean;
    isReady: boolean;
    readyOverride: { reason: string; business_date: string } | null;
};

function ChangeStatus({
    room,
    to,
    label,
}: {
    room: RoomRow;
    to: string;
    label: string;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="secondary" className="pointer-coarse:min-h-11">
                    {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {label}: room {room.number}
                </DialogTitle>
                <DialogDescription>
                    Record why the housekeeping status changed.
                </DialogDescription>
                <Form
                    {...status.form(room.id)}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-4">
                            <PropertyIntent />
                            <input type="hidden" name="status" value={to} />
                            <Label htmlFor={`reason-${room.id}-${to}`}>
                                Reason
                            </Label>
                            <Input
                                id={`reason-${room.id}-${to}`}
                                name="reason"
                                required
                                maxLength={1000}
                                aria-invalid={!!errors.reason}
                            />
                            <InputError
                                message={
                                    errors.reason ??
                                    errors.status ??
                                    errors._property
                                }
                            />
                            <Button
                                type="submit"
                                disabled={processing || !navigator.onLine}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                {processing ? 'Saving…' : label}
                            </Button>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ReadyOverride({ room }: { room: RoomRow }) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="secondary" className="pointer-coarse:min-h-11">
                    Override ready
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Override readiness: room {room.number}
                </DialogTitle>
                <DialogDescription>
                    This makes the room ready for the current business date
                    only. Its housekeeping status will not change. Record a
                    reason.
                </DialogDescription>
                <Form
                    {...overrideReady.form(room.id)}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-4">
                            <PropertyIntent />
                            <Label htmlFor={`override-reason-${room.id}`}>
                                Reason
                            </Label>
                            <Input
                                id={`override-reason-${room.id}`}
                                name="reason"
                                required
                                maxLength={1000}
                                aria-invalid={!!errors.reason}
                            />
                            <InputError
                                message={errors.reason ?? errors._property}
                            />
                            <Button
                                type="submit"
                                disabled={processing || !navigator.onLine}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                {processing ? 'Saving…' : 'Override ready'}
                            </Button>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function HousekeepingBoard({
    rooms,
    businessDate,
    filters,
    statuses,
    floors,
    canManage,
    canSupervise,
    inspectionRequired,
    stayoverServiceLabel,
    canOverrideReady,
}: {
    rooms: RoomRow[];
    businessDate: string;
    filters: { status: string; floor: string };
    statuses: SelectOption[];
    floors: SelectOption[];
    canManage: boolean;
    canSupervise: boolean;
    inspectionRequired: boolean;
    stayoverServiceLabel: string;
    canOverrideReady: boolean;
}) {
    const can = useCan();
    const metrics = [
        {
            label: 'Rooms in view',
            value: rooms.length,
            icon: DoorOpen,
            tone: 'primary',
        },
        {
            label: 'Ready for guests',
            value: rooms.filter((room) => room.isReady).length,
            icon: CheckCircle2,
            tone: 'success',
        },
        {
            label: 'Needs service',
            value: rooms.filter((room) =>
                ['dirty', 'inspection_required'].includes(room.status),
            ).length,
            icon: Sparkles,
            tone: 'warning',
        },
        {
            label: 'Occupied',
            value: rooms.filter((room) => room.status === 'occupied').length,
            icon: BedDouble,
            tone: 'occupied',
        },
    ] as const;

    return (
        <>
            <Head title="Housekeeping" />
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
                            Housekeeping
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Room status across this property. Business date{' '}
                            {formatDate(businessDate)}.
                        </p>
                    </div>
                </header>
                <HousekeepingMetricCards metrics={[...metrics]} />
                <Form
                    {...index.form()}
                    className="grid min-w-0 items-end gap-3 border border-border bg-card p-3 shadow-panel sm:grid-cols-[minmax(180px,240px)_minmax(180px,240px)_auto] md:p-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                        >
                            <option value="">All statuses</option>
                            {statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="floor">Floor</Label>
                        <NativeSelect
                            id="floor"
                            name="floor"
                            defaultValue={filters.floor}
                        >
                            <option value="">All floors</option>
                            {floors.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <Button type="submit" className="min-h-10">
                        Filter rooms
                    </Button>
                </Form>
                {rooms.length === 0 ? (
                    <EmptyState message="No rooms." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Room</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Readiness</TableHead>
                                <TableHead>Room type</TableHead>
                                <TableHead>Floor</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rooms.map((room) => (
                                <TableRow key={room.id}>
                                    <TableCell className="font-semibold text-primary tabular-nums">
                                        {room.number}
                                    </TableCell>
                                    <TableCell>
                                        <StatusChip
                                            tone={
                                                room.status === 'occupied'
                                                    ? 'occupied'
                                                    : room.status === 'clean'
                                                      ? 'success'
                                                      : 'warning'
                                            }
                                        >
                                            {room.statusLabel}
                                        </StatusChip>
                                        {room.stayoverService && (
                                            <div className="mt-1">
                                                <StatusChip tone="warning">
                                                    {stayoverServiceLabel}
                                                </StatusChip>
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        <StatusChip
                                            tone={
                                                room.isReady
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {room.readyOverride
                                                ? 'Ready · Override'
                                                : room.isReady
                                                  ? 'Ready'
                                                  : 'Not ready'}
                                        </StatusChip>
                                        {room.readyOverride && (
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Business date{' '}
                                                {formatDate(
                                                    room.readyOverride.business_date.slice(
                                                        0,
                                                        10,
                                                    ),
                                                )}
                                                : {room.readyOverride.reason}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Identifier
                                            value={room.roomType.code}
                                        />{' '}
                                        {room.roomType.name}
                                    </TableCell>
                                    <TableCell>{room.floor ?? '—'}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Button
                                                variant="ghost"
                                                asChild
                                                className="pointer-coarse:min-h-11"
                                            >
                                                <Link
                                                    href={history(room.id)}
                                                    aria-label={`History for room ${room.number}`}
                                                >
                                                    History
                                                </Link>
                                            </Button>
                                            {can('maintenance.report') && (
                                                <Button
                                                    variant="ghost"
                                                    asChild
                                                    className="pointer-coarse:min-h-11"
                                                >
                                                    <Link
                                                        href={maintenance({
                                                            query: {
                                                                room: room.id,
                                                            },
                                                        })}
                                                        aria-label={`Report a problem in room ${room.number}`}
                                                    >
                                                        Report a problem
                                                    </Link>
                                                </Button>
                                            )}
                                            {canOverrideReady &&
                                                !room.isReady && (
                                                    <ReadyOverride
                                                        room={room}
                                                    />
                                                )}
                                            {canManage &&
                                                ['dirty', 'clean'].includes(
                                                    room.status,
                                                ) && (
                                                    <ChangeStatus
                                                        room={room}
                                                        to={
                                                            room.status ===
                                                            'dirty'
                                                                ? 'cleaned'
                                                                : 'dirty'
                                                        }
                                                        label={
                                                            room.status ===
                                                            'dirty'
                                                                ? inspectionRequired
                                                                    ? 'Send for inspection'
                                                                    : 'Mark cleaned'
                                                                : 'Mark dirty'
                                                        }
                                                    />
                                                )}
                                            {canSupervise &&
                                                room.status ===
                                                    'inspection_required' && (
                                                    <>
                                                        <ChangeStatus
                                                            room={room}
                                                            to="clean"
                                                            label="Pass inspection"
                                                        />
                                                        <ChangeStatus
                                                            room={room}
                                                            to="dirty"
                                                            label="Fail inspection"
                                                        />
                                                    </>
                                                )}
                                        </div>
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

HousekeepingBoard.layout = {
    breadcrumbs: [{ title: 'Housekeeping', href: index() }],
};

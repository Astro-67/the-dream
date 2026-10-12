import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import type { SelectOption } from '@/types';
import Heading from '@/components/heading';
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
import { history, index, status } from '@/routes/housekeeping';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type RoomRow = {
    id: string;
    number: string;
    floor: string | null;
    roomType: { code: string; name: string };
    status: string;
    statusLabel: string;
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
    return (
        <Dialog>
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
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors, wasSuccessful }) => (
                        <div className="grid gap-4">
                            <PropertyIntent />
                            <input type="hidden" name="status" value={to} />
                            <Label htmlFor={`reason-${room.id}`}>Reason</Label>
                            <Input
                                id={`reason-${room.id}`}
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
                            {wasSuccessful && (
                                <p role="status">
                                    Status updated. Close this dialog to return
                                    to the board.
                                </p>
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    wasSuccessful ||
                                    !navigator.onLine
                                }
                            >
                                {processing ? 'Saving…' : label}
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
    filters,
    statuses,
    floors,
    canManage,
    canSupervise,
    inspectionRequired,
}: {
    rooms: RoomRow[];
    filters: { status: string; floor: string };
    statuses: SelectOption[];
    floors: SelectOption[];
    canManage: boolean;
    canSupervise: boolean;
    inspectionRequired: boolean;
}) {
    return (
        <>
            <Head title="Housekeeping" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Housekeeping"
                    description="Room status across this property."
                />
                <Form
                    {...index.form()}
                    className="flex flex-wrap items-end gap-4"
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
                    <Button type="submit">Filter</Button>
                </Form>
                {rooms.length === 0 ? (
                    <EmptyState message="No rooms." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Room</TableHead>
                                <TableHead>Status</TableHead>
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
                                    <TableCell className="font-medium tabular-nums">
                                        {room.number}
                                    </TableCell>
                                    <TableCell>
                                        <StatusChip
                                            tone={
                                                room.status === 'clean'
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {room.statusLabel}
                                        </StatusChip>
                                    </TableCell>
                                    <TableCell>
                                        <Identifier
                                            value={room.roomType.code}
                                        />{' '}
                                        {room.roomType.name}
                                    </TableCell>
                                    <TableCell>{room.floor ?? '—'}</TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-2">
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

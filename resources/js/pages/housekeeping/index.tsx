import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import type { SelectOption } from '@/types';
import Heading from '@/components/heading';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { index } from '@/routes/housekeeping';

type RoomRow = {
    id: string;
    number: string;
    floor: string | null;
    roomType: { code: string; name: string };
    status: string;
    statusLabel: string;
};

export default function HousekeepingBoard({ rooms, filters, statuses, floors }: { rooms: RoomRow[]; filters: { status: string; floor: string }; statuses: SelectOption[]; floors: SelectOption[] }) {
    return <>
        <Head title="Housekeeping" />
        <div className="flex flex-col gap-6 p-4 md:p-6">
            <Heading title="Housekeeping" description="Room status across this property." />
            <Form {...index.form()} className="flex flex-wrap items-end gap-4">
                <div className="grid gap-2"><Label htmlFor="status">Status</Label><NativeSelect id="status" name="status" defaultValue={filters.status}><option value="">All statuses</option>{statuses.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</NativeSelect></div>
                <div className="grid gap-2"><Label htmlFor="floor">Floor</Label><NativeSelect id="floor" name="floor" defaultValue={filters.floor}><option value="">All floors</option>{floors.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</NativeSelect></div>
                <Button type="submit">Filter</Button>
            </Form>
            {rooms.length === 0 ? <EmptyState message="No rooms." /> : <Table>
                <TableHeader><TableRow><TableHead>Room</TableHead><TableHead>Status</TableHead><TableHead>Room type</TableHead><TableHead>Floor</TableHead></TableRow></TableHeader>
                <TableBody>{rooms.map(room => <TableRow key={room.id}>
                    <TableCell className="font-medium tabular-nums">{room.number}</TableCell>
                    <TableCell><StatusChip tone={room.status === 'clean' ? 'success' : 'warning'}>{room.statusLabel}</StatusChip></TableCell>
                    <TableCell><Identifier value={room.roomType.code} /> {room.roomType.name}</TableCell>
                    <TableCell>{room.floor ?? '—'}</TableCell>
                </TableRow>)}</TableBody>
            </Table>}
        </div>
    </>;
}

HousekeepingBoard.layout = { breadcrumbs: [{ title: 'Housekeeping', href: index() }] };

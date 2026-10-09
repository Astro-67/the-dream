import { Identifier } from '@/components/ui/identifier';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/room-types';

type RoomTypeRow = {
    id: string;
    name: string;
    code: string;
    baseOccupancy: number;
    maxAdults: number;
    maxChildren: number;
    maxOccupancy: number;
    sellableToday: number;
};

export default function RoomTypesIndex({
    roomTypes,
    canManage,
}: {
    roomTypes: RoomTypeRow[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Room types" />
            <div className="flex flex-col gap-6 p-4 md:gap-8 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Room types
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            What this property sells.
                        </p>
                    </div>
                    {canManage && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus aria-hidden />
                                Add room type
                            </Link>
                        </Button>
                    )}
                </header>

                {roomTypes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No room types yet.
                        {canManage &&
                            ' Add one to describe what this property sells.'}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead numeric>Base</TableHead>
                                <TableHead numeric>Max adults</TableHead>
                                <TableHead numeric>Max children</TableHead>
                                <TableHead numeric>Max guests</TableHead>
                                <TableHead numeric>Sellable today</TableHead>
                                {canManage && (
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roomTypes.map((roomType) => (
                                <TableRow key={roomType.id}>
                                    <TableCell>
                                        <Identifier value={roomType.code} />
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {roomType.name}
                                    </TableCell>
                                    <TableCell numeric>
                                        {roomType.baseOccupancy}
                                    </TableCell>
                                    <TableCell numeric>
                                        {roomType.maxAdults}
                                    </TableCell>
                                    <TableCell numeric>
                                        {roomType.maxChildren}
                                    </TableCell>
                                    <TableCell numeric>
                                        {roomType.maxOccupancy}
                                    </TableCell>
                                    <TableCell numeric>
                                        {roomType.sellableToday}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell>
                                            <div className="flex justify-end">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(roomType.id)}
                                                        aria-label={`Edit ${roomType.name}`}
                                                    >
                                                        Edit
                                                    </Link>
                                                </Button>
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}

RoomTypesIndex.layout = {
    breadcrumbs: [{ title: 'Room types', href: index() }],
};

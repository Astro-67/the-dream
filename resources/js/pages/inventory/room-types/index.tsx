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
    activeRooms: number;
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
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 border-b bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="h-10 px-4 font-medium">
                                        Code
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Name
                                    </th>
                                    <th className="h-10 px-4 text-right font-medium">
                                        Base
                                    </th>
                                    <th className="h-10 px-4 text-right font-medium">
                                        Max adults
                                    </th>
                                    <th className="h-10 px-4 text-right font-medium">
                                        Max children
                                    </th>
                                    <th className="h-10 px-4 text-right font-medium">
                                        Max guests
                                    </th>
                                    <th className="h-10 px-4 text-right font-medium">
                                        Active rooms
                                    </th>
                                    {canManage && (
                                        <th className="h-10 px-4">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {roomTypes.map((roomType) => (
                                    <tr
                                        key={roomType.id}
                                        className="h-10 hover:bg-muted/40"
                                    >
                                        <td className="px-4 py-2 font-mono">
                                            {roomType.code}
                                        </td>
                                        <td className="px-4 py-2 font-medium">
                                            {roomType.name}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {roomType.baseOccupancy}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {roomType.maxAdults}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {roomType.maxChildren}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {roomType.maxOccupancy}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {roomType.activeRooms}
                                        </td>
                                        {canManage && (
                                            <td className="px-4 py-2">
                                                <div className="flex justify-end">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                roomType.id,
                                                            )}
                                                            aria-label={`Edit ${roomType.name}`}
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

RoomTypesIndex.layout = {
    breadcrumbs: [{ title: 'Room types', href: index() }],
};

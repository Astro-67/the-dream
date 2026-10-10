import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export type CalendarReservation = {
    reservation_number: string;
    arrival: string;
    departure: string;
    guest_name: string;
    source: string;
    status: string;
    url: string;
};
export type CalendarRoom = {
    id: string;
    number: string;
    reservations: CalendarReservation[];
};

const sources: Record<string, string> = {
    direct: 'Direct',
    'walk-in': 'Walk-in',
    'outside-integration': 'Outside integration',
    phone: 'Phone',
};

export function CalendarRoomRows({
    rooms,
    dates,
}: {
    rooms: CalendarRoom[];
    dates: string[];
}) {
    return rooms.map((room) => {
        const cells: React.ReactNode[] = [];
        for (let index = 0; index < dates.length;) {
            const date = dates[index];
            const reservation = room.reservations.find(
                (reservation) =>
                    reservation.arrival <= date && reservation.departure > date,
            );
            if (!reservation) {
                cells.push(
                    <TableCell
                        key={date}
                        aria-label={`Room ${room.number}, ${date}: unassigned`}
                        className="border-b border-l px-2 py-2"
                    />,
                );
                index++;
                continue;
            }
            let end = index + 1;
            while (end < dates.length && dates[end] < reservation.departure)
                end++;
            const description = `${reservation.guest_name}, ${reservation.reservation_number}, ${reservation.arrival} to ${reservation.departure}, ${reservation.status.replaceAll('_', ' ')}`;
            cells.push(
                <TableCell
                    key={date}
                    colSpan={end - index}
                    className="border-b border-l p-1"
                >
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <a
                                href={reservation.url}
                                aria-label={description}
                                className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-primary-tint px-3 py-2 text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <span>{reservation.guest_name}</span>
                                <Identifier
                                    value={reservation.reservation_number}
                                />
                                <span className="text-xs">
                                    {sources[reservation.source] ??
                                        reservation.source}
                                </span>
                            </a>
                        </TooltipTrigger>
                        <TooltipContent className="bg-popover text-popover-foreground">
                            <StatusChip
                                tone={
                                    reservation.status === 'checked_in'
                                        ? 'occupied'
                                        : 'info'
                                }
                            >
                                {reservation.status.replaceAll('_', ' ')}
                            </StatusChip>
                            <p className="mt-1">
                                {reservation.arrival} – {reservation.departure}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TableCell>,
            );
            index = end;
        }
        return (
            <TableRow key={room.id}>
                <TableHead
                    scope="row"
                    className="sticky left-0 z-10 border-b bg-card px-3 py-2 text-left font-normal whitespace-nowrap"
                >
                    Room <Identifier value={room.number} />
                </TableHead>
                {cells}
            </TableRow>
        );
    });
}

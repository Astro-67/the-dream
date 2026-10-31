import { formatDate } from '@/lib/utils';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { Button } from '@/components/ui/button';
import type { OutOfServicePeriod } from '@/features/inventory/out-of-service';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export type CalendarReservation = {
    reservation_number: string;
    arrival: string;
    departure: string;
    display_departure?: string;
    is_overstay?: boolean;
    guest_name: string;
    source: string;
    status: string;
    url: string;
};
export type CalendarRoom = {
    id: string;
    number: string;
    reservations: CalendarReservation[];
    outOfServicePeriods?: OutOfServicePeriod[];
    canManageOutOfOrder?: boolean;
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
    businessDate = '',
    onOutOfOrder,
}: {
    rooms: CalendarRoom[];
    dates: string[];
    businessDate?: string;
    onOutOfOrder?: (
        room: CalendarRoom,
        date: string,
        period?: OutOfServicePeriod,
    ) => void;
}) {
    return rooms.flatMap((room) => {
        const lanes: CalendarReservation[][] = [[]];
        for (const reservation of room.reservations) {
            const lane = lanes.find((lane) =>
                lane.every(
                    (existing) =>
                        (existing.display_departure ?? existing.departure) <=
                            reservation.arrival ||
                        (reservation.display_departure ??
                            reservation.departure) <= existing.arrival,
                ),
            );
            if (lane) lane.push(reservation);
            else lanes.push([reservation]);
        }
        const periodLane = room.outOfServicePeriods?.length ? lanes.length : -1;
        if (periodLane !== -1) lanes.push([]);
        const canManage = room.canManageOutOfOrder && onOutOfOrder;
        return lanes.map((reservations, laneIndex) => {
            const cells: React.ReactNode[] = [];
            for (let index = 0; index < dates.length;) {
                const date = dates[index];
                const period =
                    laneIndex === periodLane
                        ? room.outOfServicePeriods?.find(
                              (period) =>
                                  period.startsOn <= date &&
                                  period.returnsOn > date,
                          )
                        : undefined;
                if (period) {
                    let end = index + 1;
                    while (end < dates.length && dates[end] < period.returnsOn)
                        end++;
                    const description = `Room ${room.number}: Out of order, ${formatDate(period.startsOn)} to ${formatDate(period.returnsOn)}, ${period.reason}`;
                    cells.push(
                        <TableCell
                            key={date}
                            colSpan={end - index}
                            className="border-b border-l bg-status-unavailable-tint p-1"
                            style={{
                                backgroundImage:
                                    'repeating-linear-gradient(135deg, transparent 0 6px, color-mix(in srgb, var(--status-unavailable) 15%, transparent) 6px 8px)',
                            }}
                        >
                            {canManage && period.returnsOn > businessDate ? (
                                <Button
                                    variant="ghost"
                                    className="h-auto min-h-11 w-full justify-start gap-2 whitespace-normal"
                                    aria-label={`Return room ${room.number} to service: ${formatDate(period.startsOn)} to ${formatDate(period.returnsOn)}, ${period.reason}`}
                                    onClick={() =>
                                        onOutOfOrder?.(room, date, period)
                                    }
                                >
                                    <StatusChip tone="unavailable">
                                        Out of order
                                    </StatusChip>
                                    <span>{period.reason}</span>
                                </Button>
                            ) : (
                                <div
                                    aria-label={description}
                                    className="flex min-h-11 items-center gap-2 px-3 py-2"
                                >
                                    <StatusChip tone="unavailable">
                                        Out of order
                                    </StatusChip>
                                    <span>{period.reason}</span>
                                </div>
                            )}
                        </TableCell>,
                    );
                    index = end;
                    continue;
                }
                const departures = reservations
                    .filter(
                        (reservation) =>
                            !reservation.is_overstay &&
                            reservation.departure === date,
                    )
                    .map((reservation) => (
                        <a
                            key={reservation.reservation_number}
                            href={reservation.url}
                            className="block rounded-md px-3 py-2 text-xs text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Departure: {reservation.guest_name}{' '}
                            <Identifier
                                value={reservation.reservation_number}
                            />
                        </a>
                    ));
                const reservation = reservations.find(
                    (reservation) =>
                        reservation.arrival <= date &&
                        (reservation.display_departure ??
                            reservation.departure) > date,
                );
                if (!reservation) {
                    cells.push(
                        <TableCell
                            key={date}
                            aria-label={`Room ${room.number}, ${formatDate(date)}: ${laneIndex === periodLane ? 'in service' : 'unassigned'}`}
                            className="border-b border-l px-2 py-2"
                        >
                            {departures}
                        </TableCell>,
                    );
                    index++;
                    continue;
                }
                let end = index + 1;
                while (
                    end < dates.length &&
                    dates[end] <
                        (reservation.display_departure ??
                            reservation.departure) &&
                    !reservations.some(
                        (departure) =>
                            !departure.is_overstay &&
                            departure.departure === dates[end],
                    )
                )
                    end++;
                const description = `${reservation.guest_name}, ${reservation.reservation_number}, ${formatDate(reservation.arrival)} to ${formatDate(reservation.departure)}, ${reservation.is_overstay ? 'Overstay, ' : ''}${reservation.status.replaceAll('_', ' ')}`;
                cells.push(
                    <TableCell
                        key={date}
                        colSpan={end - index}
                        className="border-b border-l p-1"
                    >
                        {departures}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <a
                                    href={reservation.url}
                                    aria-label={description}
                                    className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-primary-tint px-3 py-2 text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                >
                                    <span>{reservation.guest_name}</span>
                                    {reservation.is_overstay && (
                                        <StatusChip tone="warning">
                                            Overstay
                                        </StatusChip>
                                    )}
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
                                    <time dateTime={reservation.arrival}>
                                        {formatDate(reservation.arrival)}
                                    </time>{' '}
                                    –{' '}
                                    <time dateTime={reservation.departure}>
                                        {formatDate(reservation.departure)}
                                    </time>
                                </p>
                            </TooltipContent>
                        </Tooltip>
                    </TableCell>,
                );
                index = end;
            }
            return (
                <TableRow key={`${room.id}-${laneIndex}`}>
                    {laneIndex === 0 && (
                        <TableHead
                            scope="row"
                            rowSpan={lanes.length}
                            className="sticky left-0 z-10 border-b bg-card px-3 py-2 text-left font-normal whitespace-nowrap"
                        >
                            Room <Identifier value={room.number} />
                            {canManage && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="ml-2 min-h-11 md:min-h-8"
                                    aria-label={`Take room ${room.number} out of order`}
                                    onClick={() =>
                                        onOutOfOrder?.(
                                            room,
                                            dates.find(
                                                (date) => date >= businessDate,
                                            ) ?? businessDate,
                                        )
                                    }
                                >
                                    Take out of order
                                </Button>
                            )}
                        </TableHead>
                    )}
                    {cells}
                </TableRow>
            );
        });
    });
}

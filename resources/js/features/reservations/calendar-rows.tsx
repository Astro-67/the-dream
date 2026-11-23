import { formatDate } from '@/lib/utils';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { Button } from '@/components/ui/button';
import {
    Briefcase,
    BedDouble,
    CalendarCheck,
    Globe,
    Home,
    LogOut,
    TriangleAlert,
    UserX,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
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
    booked_through?: string | null;
    tour_operator_name?: string | null;
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
    'outside-integration': 'External / OTA',
    phone: 'Phone',
    whatsapp: 'WhatsApp',
    email: 'Email',
    website: 'Website',
};

function reservationOrigin(reservation: CalendarReservation) {
    if (reservation.booked_through === 'tour-operator') {
        return {
            label: reservation.tour_operator_name
                ? `Tour operator: ${reservation.tour_operator_name}`
                : 'Tour operator',
            bar: 'border-reservation-origin-operator bg-reservation-origin-operator-tint',
            text: 'text-reservation-origin-operator',
            icon: Briefcase,
        };
    }

    if (reservation.source === 'outside-integration') {
        return {
            label: sources[reservation.source],
            bar: 'border-reservation-origin-external bg-reservation-origin-external-tint',
            text: 'text-reservation-origin-external',
            icon: Globe,
        };
    }

    return {
        label: sources[reservation.source] ?? reservation.source,
        bar: 'border-reservation-origin-direct bg-reservation-origin-direct-tint',
        text: 'text-reservation-origin-direct',
        icon: Home,
    };
}

const reservationVisuals: Record<
    string,
    {
        icon: LucideIcon;
        tone: 'info' | 'occupied' | 'success' | 'warning';
        label: string;
    }
> = {
    confirmed: {
        icon: CalendarCheck,
        tone: 'info',
        label: 'Confirmed',
    },
    checked_in: {
        icon: BedDouble,
        tone: 'occupied',
        label: 'Checked in',
    },
    checked_out: {
        icon: LogOut,
        tone: 'success',
        label: 'Checked out',
    },
    no_show: {
        icon: UserX,
        tone: 'warning',
        label: 'No-show',
    },
};

const defaultReservationVisual = reservationVisuals.confirmed;

function reservationVisual(reservation: CalendarReservation) {
    if (reservation.is_overstay) {
        return {
            icon: TriangleAlert,
            tone: 'warning' as const,
            label: 'Overstay',
        };
    }

    return (
        reservationVisuals[reservation.status] ?? {
            ...defaultReservationVisual,
            label: reservation.status.replaceAll('_', ' '),
        }
    );
}

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
                            className={cn(
                                'border-b border-l bg-status-unavailable-tint p-1',
                                date === businessDate && 'bg-primary-tint/30',
                            )}
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
                                    <Wrench
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-status-unavailable"
                                    />
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
                                    <Wrench
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-status-unavailable"
                                    />
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
                            className="inline-flex min-h-9 items-start gap-2 px-3 py-2 text-xs break-words whitespace-normal text-muted-foreground hover:text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <LogOut
                                aria-hidden="true"
                                className="mt-0.5 size-3.5 shrink-0"
                            />
                            <span>
                                Departure: {reservation.guest_name}{' '}
                                <Identifier
                                    value={reservation.reservation_number}
                                />
                            </span>
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
                            className={cn(
                                'border-b border-l px-2 py-2',
                                date === businessDate && 'bg-primary-tint/50',
                            )}
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
                const origin = reservationOrigin(reservation);
                const OriginIcon = origin.icon;
                const description = `${reservation.guest_name}, ${reservation.reservation_number}, ${origin.label}, ${formatDate(reservation.arrival)} to ${formatDate(reservation.departure)}, ${reservation.is_overstay ? 'Overstay, ' : ''}${reservation.status.replaceAll('_', ' ')}`;
                const visual = reservationVisual(reservation);
                const ReservationIcon = visual.icon;
                cells.push(
                    <TableCell
                        key={date}
                        colSpan={end - index}
                        className={cn(
                            'border-b border-l p-1',
                            date === businessDate && 'bg-primary-tint/40',
                        )}
                    >
                        {departures}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <a
                                    href={reservation.url}
                                    aria-label={description}
                                    className={cn(
                                        'flex min-h-14 min-w-0 flex-col items-stretch gap-1.5 border-l-4 px-3 py-2 break-words whitespace-normal transition-colors hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                        origin.bar,
                                    )}
                                >
                                    <span className="flex min-w-0 items-start gap-2">
                                        <ReservationIcon
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        <span className="min-w-0 text-sm leading-5 font-semibold">
                                            {reservation.guest_name}
                                        </span>
                                    </span>
                                    <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 pl-6">
                                        <StatusChip
                                            tone={visual.tone}
                                            className="border border-current/15 bg-card/70 px-1.5 py-0.5"
                                        >
                                            {visual.label}
                                        </StatusChip>
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1 rounded-sm border border-current/20 bg-card/80 px-1.5 py-0.5 text-[11px] font-medium',
                                                origin.text,
                                            )}
                                        >
                                            <OriginIcon
                                                aria-hidden="true"
                                                className="size-3.5 shrink-0"
                                            />
                                            {origin.label}
                                        </span>
                                        <Identifier
                                            value={
                                                reservation.reservation_number
                                            }
                                            className="text-[10px] text-muted-foreground"
                                        />
                                    </span>
                                </a>
                            </TooltipTrigger>
                            <TooltipContent className="bg-popover text-popover-foreground">
                                <StatusChip tone={visual.tone}>
                                    {visual.label}
                                </StatusChip>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Reservation status:{' '}
                                    {reservation.status.replaceAll('_', ' ')}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Reservation source: {origin.label}
                                </p>
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
                            className="sticky left-0 z-10 border-b bg-card px-3 py-2 text-left font-normal break-words whitespace-normal"
                        >
                            <span className="inline-flex items-center gap-2">
                                <BedDouble
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                                Room <Identifier value={room.number} />
                            </span>
                            {canManage && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2 flex min-h-11 md:min-h-8"
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

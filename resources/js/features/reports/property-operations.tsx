import { EmptyState } from '@/components/ui/empty-state';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/utils';

export type PropertyOperationsData = {
    daily: {
        date: string;
        arrivals: number;
        departures: number;
        inHouse: number;
        plannedArrivals: number;
        plannedDepartures: number;
    }[];
    historyUnavailable: number;
    housekeeping: {
        status: string;
        label: string;
        tone: StatusTone;
        rooms: number;
    }[];
};

export default function PropertyOperations({
    operations,
    group = false,
}: {
    operations: PropertyOperationsData;
    group?: boolean;
}) {
    const hasActivity = operations.daily.some(
        (day) =>
            day.arrivals ||
            day.departures ||
            day.inHouse ||
            day.plannedArrivals ||
            day.plannedDepartures,
    );
    const hasRooms = operations.housekeeping.some((status) => status.rooms > 0);

    return (
        <>
            <section aria-labelledby="daily-title" className="grid gap-4">
                <div>
                    <h2 id="daily-title" className="text-lg font-semibold">
                        Daily operations
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Counts use recorded lifecycle business dates, including
                        both range endpoints. These are PMS operations, not
                        evidence of physical arrival or departure. Planned
                        counts use current reservation dates and are shown
                        separately.
                    </p>
                </div>
                <dl className="grid gap-3 text-sm">
                    <div>
                        <dt className="font-medium">
                            Recorded arrivals and departures
                        </dt>
                        <dd className="text-muted-foreground">
                            Recorded check-ins and check-outs on this property
                            business date, not their scheduled reservation dates
                            or the calendar date of the recording timestamp.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-medium">
                            Planned arrivals and departures
                        </dt>
                        <dd className="text-muted-foreground">
                            Uncompleted arrivals of confirmed reservations and
                            departures of confirmed or checked-in reservations,
                            on or after each property's current business date.
                            Overdue plans are not reported as completed
                            operations.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-medium">In-house</dt>
                        <dd className="text-muted-foreground">
                            The recorded check-in business date is included and
                            check-out business date is excluded. An open stay
                            continues through the property's current business
                            date, even past scheduled departure. No future
                            in-house nights are inferred. Confirmed, cancelled,
                            no-show and void reservations are not in-house;
                            cancelled, no-show and void reservations are
                            excluded from all daily counts.
                        </dd>
                    </div>
                </dl>
                {operations.historyUnavailable > 0 && (
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Stays with incomplete recorded history:{' '}
                        {operations.historyUnavailable}. Imported or legacy
                        checked-in/out reservations may lack lifecycle events.
                        Missing boundaries are unknown, not replaced by
                        scheduled dates. Available recorded arrivals or
                        departures still count; in-house counts omit stays
                        without a recorded check-in or completed stays without a
                        recorded check-out. Counts may therefore be incomplete.
                    </p>
                )}
                {hasActivity ? (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Business date</TableHead>
                                <TableHead numeric>Recorded arrivals</TableHead>
                                <TableHead numeric>
                                    Recorded departures
                                </TableHead>
                                <TableHead numeric>In-house</TableHead>
                                <TableHead numeric>Planned arrivals</TableHead>
                                <TableHead numeric>
                                    Planned departures
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {operations.daily.map((day) => (
                                <TableRow key={day.date}>
                                    <TableCell>
                                        {formatDate(day.date)}
                                    </TableCell>
                                    <TableCell numeric>
                                        {day.arrivals}
                                    </TableCell>
                                    <TableCell numeric>
                                        {day.departures}
                                    </TableCell>
                                    <TableCell numeric>{day.inHouse}</TableCell>
                                    <TableCell numeric>
                                        {day.plannedArrivals}
                                    </TableCell>
                                    <TableCell numeric>
                                        {day.plannedDepartures}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                ) : (
                    <EmptyState
                        message="No arrivals, departures or in-house stays in this period."
                        action="Change the business date range to review another period."
                    />
                )}
            </section>
            <section
                aria-labelledby="housekeeping-title"
                className="grid gap-4"
            >
                <div>
                    <h2
                        id="housekeeping-title"
                        className="text-lg font-semibold"
                    >
                        Current housekeeping
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Active rooms grouped by their current housekeeping
                        status at the refresh time above. This is not a
                        historical snapshot and does not change with the
                        selected business dates. Deactivated rooms are excluded.
                    </p>
                </div>
                {hasRooms ? (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Current status</TableHead>
                                <TableHead numeric>Rooms</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {operations.housekeeping.map((status) => (
                                <TableRow key={status.status}>
                                    <TableCell>
                                        <StatusChip tone={status.tone}>
                                            {status.label}
                                        </StatusChip>
                                    </TableCell>
                                    <TableCell numeric>
                                        {status.rooms}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                ) : (
                    <EmptyState
                        message={
                            group
                                ? 'No active rooms in these properties.'
                                : 'No active rooms in this property.'
                        }
                        action="Ask a property manager to set up rooms in Inventory."
                    />
                )}
            </section>
        </>
    );
}

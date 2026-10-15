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
    }[];
    housekeeping: {
        status: string;
        label: string;
        tone: StatusTone;
        rooms: number;
    }[];
};

export default function PropertyOperations({
    operations,
}: {
    operations: PropertyOperationsData;
}) {
    const hasActivity = operations.daily.some(
        (day) => day.arrivals || day.departures || day.inHouse,
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
                        Reservation counts for each selected business date,
                        including both endpoints. Computed from current
                        reservation dates and statuses, matching front-desk
                        lists; not reconstructed historical snapshots.
                    </p>
                </div>
                <dl className="grid gap-3 text-sm">
                    <div>
                        <dt className="font-medium">Arrivals</dt>
                        <dd className="text-muted-foreground">
                            Confirmed or checked-in reservations with this
                            arrival date.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-medium">Departures</dt>
                        <dd className="text-muted-foreground">
                            Confirmed, checked-in or checked-out reservations
                            with this departure date.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-medium">In-house</dt>
                        <dd className="text-muted-foreground">
                            Currently checked-in reservations spanning this
                            date: arrival date is included and departure date is
                            excluded. Cancelled, no-show and void reservations
                            are excluded from all daily counts.
                        </dd>
                    </div>
                </dl>
                {hasActivity ? (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Business date</TableHead>
                                <TableHead numeric>Arrivals</TableHead>
                                <TableHead numeric>Departures</TableHead>
                                <TableHead numeric>In-house</TableHead>
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
                        message="No active rooms in this property."
                        action="Ask a property manager to set up rooms in Inventory."
                    />
                )}
            </section>
        </>
    );
}

import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    OnboardingChecklist,
    type Checklist,
} from '@/features/tenancy/onboarding-checklist';
import {
    CollectionsTable,
    periodLabels,
    type Received,
} from '@/features/reports/collections-table';
import { useCan } from '@/hooks/use-can';
import { formatDate } from '@/lib/utils';
import { dashboard } from '@/routes';
import { create as createProperty } from '@/routes/properties';
import { create as createReservation } from '@/routes/reservations';
import type { Paginated } from '@/types';

type Today = {
    businessDate: string;
    lists: { key: string; count: number; href: string }[];
};

type RoomBoard = {
    businessDate: string;
    housekeepingHref: string | null;
    calendarHref: string;
    rooms: Paginated<{
        id: string;
        number: string;
        roomType: string;
        status: string;
        statusLabel: string;
        tone: StatusTone;
        readinessLabel: string | null;
        readinessTone: StatusTone;
    }>;
};

const listLabels: Record<string, string> = {
    arrivals: 'Arrivals',
    departures: 'Departures',
    'in-house': 'In house',
};

export default function Dashboard({
    onboarding,
    today,
    collections,
    roomBoard,
}: {
    onboarding: Checklist | null;
    today: Today | null;
    roomBoard: RoomBoard | null;
    collections: {
        period: string;
        from: string;
        to: string;
        received: Received;
        href: string;
    } | null;
}) {
    const { auth, tenant } = usePage().props;
    const can = useCan();

    if (onboarding) {
        return (
            <>
                <Head title={`Set up ${onboarding.propertyName}`} />
                <OnboardingChecklist checklist={onboarding} />
            </>
        );
    }

    if (!tenant.property) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {tenant.organization?.name ?? 'Dashboard'}
                    </h1>
                    <div className="rounded-xl border">
                        <EmptyState
                            message={
                                can('properties.manage')
                                    ? 'There is no active property to work in yet. Add one to start setting it up.'
                                    : 'You have not been given access to a property yet. Ask an owner of this organization to add you to one.'
                            }
                            action={
                                can('properties.manage') && (
                                    <Button asChild>
                                        <Link href={createProperty()}>
                                            Add property
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Welcome back, {auth.user.name.split(' ')[0]}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {tenant.property.name}
                            {today &&
                                ` · Business date ${formatDate(today.businessDate)}`}
                        </p>
                    </div>
                    {can('reservations.manage') && (
                        <Button asChild>
                            <Link href={createReservation()}>New booking</Link>
                        </Button>
                    )}
                </header>

                {collections && (
                    <section
                        aria-labelledby="collections-title"
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <h2
                                id="collections-title"
                                className="text-lg font-semibold"
                            >
                                Collections
                            </h2>
                            <Link
                                className="text-sm text-primary underline"
                                href={collections.href}
                            >
                                View payments
                            </Link>
                        </div>
                        <nav
                            aria-label="Collections period"
                            className="flex flex-wrap gap-2"
                        >
                            {['today', 'this-month', 'this-year'].map(
                                (period) => (
                                    <Button
                                        key={period}
                                        variant={
                                            collections.period === period
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={dashboard({
                                                query: { period },
                                            })}
                                            aria-current={
                                                collections.period === period
                                                    ? 'page'
                                                    : undefined
                                            }
                                            preserveScroll
                                        >
                                            {periodLabels[period]}
                                        </Link>
                                    </Button>
                                ),
                            )}
                        </nav>
                        <p className="text-sm text-muted-foreground">
                            {formatDate(collections.from)} –{' '}
                            {formatDate(collections.to)} · Business dates. Net
                            received includes refunds and reversals.
                        </p>
                        <CollectionsTable received={collections.received} />
                    </section>
                )}

                {today ? (
                    <section aria-labelledby="today" className="space-y-3">
                        <h2 id="today" className="text-lg font-semibold">
                            Today
                        </h2>
                        <ul className="grid gap-4 sm:grid-cols-3">
                            {today.lists.map((list) => (
                                <li key={list.key}>
                                    <Link
                                        href={list.href}
                                        className="flex flex-col gap-1 rounded-xl border p-4 transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="text-sm text-muted-foreground">
                                            {listLabels[list.key] ?? list.key}
                                        </span>
                                        <span className="text-3xl font-semibold tabular-nums">
                                            {list.count}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : !roomBoard ? (
                    <div className="rounded-xl border">
                        <EmptyState message="Use the menu to open the parts of the system you work in." />
                    </div>
                ) : null}

                {roomBoard && (
                    <section
                        aria-labelledby="room-status"
                        className="space-y-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2
                                    id="room-status"
                                    className="text-lg font-semibold"
                                >
                                    Room status
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Business date{' '}
                                    {formatDate(roomBoard.businessDate)}
                                </p>
                            </div>
                            <nav
                                aria-label="Room operations"
                                className="flex flex-wrap gap-2"
                            >
                                {roomBoard.housekeepingHref && (
                                    <Button variant="outline" size="lg" asChild>
                                        <Link href={roomBoard.housekeepingHref}>
                                            Housekeeping
                                        </Link>
                                    </Button>
                                )}
                                <Button variant="outline" size="lg" asChild>
                                    <Link href={roomBoard.calendarHref}>
                                        Calendar
                                    </Link>
                                </Button>
                            </nav>
                        </div>
                        {roomBoard.rooms.data.length === 0 ? (
                            <div className="rounded-xl border">
                                <EmptyState message="No active rooms. Ask a property manager to add rooms." />
                            </div>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Room</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {roomBoard.rooms.data.map((room) => (
                                            <TableRow key={room.id}>
                                                <TableCell>
                                                    <Identifier
                                                        value={room.number}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {room.roomType}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-2">
                                                        <StatusChip
                                                            tone={room.tone}
                                                        >
                                                            {room.statusLabel}
                                                        </StatusChip>
                                                        {room.readinessLabel && (
                                                            <StatusChip
                                                                tone={
                                                                    room.readinessTone
                                                                }
                                                            >
                                                                {
                                                                    room.readinessLabel
                                                                }
                                                            </StatusChip>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                <Pagination page={roomBoard.rooms} />
                            </>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};

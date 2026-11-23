import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Banknote,
    CalendarDays,
    ConciergeBell,
    DoorOpen,
    LogOut,
    Plus,
    Users,
    type LucideIcon,
} from 'lucide-react';
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
    GuestQueue,
    type GuestQueue as GuestQueueData,
} from '@/features/front-desk/guest-queue';
import {
    CollectionsTable,
    periodLabels,
    type Received,
} from '@/features/reports/collections-table';
import { useCan } from '@/hooks/use-can';
import { cn, formatDate, formatMoney } from '@/lib/utils';
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

const listCards: Record<
    string,
    {
        label: string;
        icon: LucideIcon;
        surface: string;
        iconTone: string;
    }
> = {
    arrivals: {
        label: 'Arrivals',
        icon: ConciergeBell,
        surface:
            'bg-status-info text-white dark:bg-status-info-tint dark:text-status-info',
        iconTone: 'text-white/15 dark:text-status-info/20',
    },
    departures: {
        label: 'Departures',
        icon: LogOut,
        surface:
            'bg-status-warning text-white dark:bg-status-warning-tint dark:text-status-warning',
        iconTone: 'text-white/15 dark:text-status-warning/20',
    },
    'in-house': {
        label: 'In house',
        icon: Users,
        surface:
            'bg-status-occupied text-white dark:bg-status-occupied-tint dark:text-status-occupied',
        iconTone: 'text-white/15 dark:text-status-occupied/20',
    },
};

export default function Dashboard({
    onboarding,
    today,
    collections,
    roomBoard,
    guestQueue,
}: {
    onboarding: Checklist | null;
    today: Today | null;
    roomBoard: RoomBoard | null;
    guestQueue: GuestQueueData | null;
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
                    <div className="border-y">
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
            <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(21rem,1fr)]">
                <header className="flex flex-wrap items-center justify-between gap-5 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5 xl:col-span-2">
                    <div className="flex min-w-0 items-center gap-4">
                        <span className="hidden size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel sm:grid">
                            <ConciergeBell
                                aria-hidden="true"
                                className="size-6"
                            />
                        </span>
                        <div className="min-w-0 space-y-1">
                            <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                Property operations
                            </p>
                            <h1 className="text-3xl leading-9 font-semibold tracking-tight">
                                Hi, {auth.user.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Today&apos;s guests, room status, and
                                collections at a glance.
                            </p>
                            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    {tenant.property.name}
                                </span>
                                {today && (
                                    <span className="inline-flex items-center gap-1.5 border border-primary/15 bg-primary-tint px-2.5 py-1 text-xs font-medium text-primary">
                                        <CalendarDays
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Business date{' '}
                                        {formatDate(today.businessDate)}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                    {can('reservations.manage') && (
                        <Button className="shadow-primary" asChild>
                            <Link href={createReservation()}>
                                <Plus aria-hidden="true" />
                                New reservation
                            </Link>
                        </Button>
                    )}
                </header>

                {today ? (
                    <section
                        aria-labelledby="today"
                        className="min-w-0 space-y-5 xl:col-span-2"
                    >
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <p className="text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                    Today at a glance
                                </p>
                                <h2
                                    id="today"
                                    className="mt-0.5 text-lg font-semibold"
                                >
                                    Guest lists
                                </h2>
                            </div>
                            <span className="inline-flex items-center border border-primary/15 bg-primary-tint px-2.5 py-1 text-xs font-semibold text-primary">
                                Today
                            </span>
                        </div>
                        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:gap-4 xl:grid-cols-3">
                            {today.lists.map((list) => {
                                const card = listCards[list.key];
                                const Icon = card?.icon ?? Users;
                                const isCurrentList =
                                    list.key ===
                                    (guestQueue?.filters.list ?? 'arrivals');

                                return (
                                    <li key={list.key}>
                                        <Link
                                            href={list.href}
                                            aria-current={
                                                isCurrentList
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={cn(
                                                'group relative flex min-h-40 min-w-0 cursor-pointer touch-manipulation flex-col justify-between overflow-hidden border border-border p-4 text-left shadow-panel transition-shadow duration-150 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:shadow-panel md:p-5',
                                                card?.surface ??
                                                    'bg-card text-foreground',
                                                isCurrentList &&
                                                    'ring-2 ring-foreground/25 ring-offset-2',
                                            )}
                                        >
                                            <span className="relative z-10 flex min-w-0 items-center gap-2 text-xs font-semibold tracking-[0.1em] uppercase opacity-90">
                                                <span className="size-1.5 shrink-0 rounded-full bg-current" />
                                                <span>
                                                    {card?.label ?? list.key}
                                                </span>
                                            </span>
                                            <Icon
                                                aria-hidden="true"
                                                className={cn(
                                                    'pointer-events-none absolute top-1/2 -right-2 size-28 -translate-y-1/2 transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100',
                                                    card?.iconTone ??
                                                        'text-foreground/10',
                                                )}
                                            />
                                            <span className="relative z-10 mt-2 text-4xl leading-none font-semibold tracking-tight tabular-nums md:text-5xl">
                                                {list.count}
                                            </span>
                                            <span className="relative z-10 mt-4 flex min-h-9 w-full items-center justify-between gap-2 border-t border-current/25 pt-2 text-xs font-semibold">
                                                <span>More info</span>
                                                <ArrowUpRight
                                                    aria-hidden="true"
                                                    className="size-4 shrink-0"
                                                />
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ) : !roomBoard && !guestQueue && !collections ? (
                    <div className="border-y xl:col-span-2">
                        <EmptyState message="Use the menu to open the parts of the system you work in." />
                    </div>
                ) : null}

                {collections && (
                    <section
                        aria-labelledby="collections-overview"
                        className="min-w-0 overflow-hidden border border-border bg-card shadow-panel xl:col-span-2"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                    <Banknote
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
                                <div>
                                    <h2
                                        id="collections-overview"
                                        className="text-base font-semibold"
                                    >
                                        Collections
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {periodLabels[collections.period]} · Net
                                        received
                                    </p>
                                </div>
                            </div>
                            <Link
                                className="inline-flex min-h-9 items-center gap-1.5 border border-border bg-card px-3 text-xs font-semibold text-foreground transition-colors hover:border-primary/40 hover:bg-primary-tint hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                href={collections.href}
                            >
                                View payments
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </Link>
                        </div>
                        <div className="grid gap-4 p-4 md:p-5">
                            {collections.received.totals.length > 0 ? (
                                <dl className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
                                    {collections.received.totals.map(
                                        (total) => (
                                            <div
                                                className="flex min-h-24 flex-col justify-between gap-3 border border-l-4 border-border border-l-primary bg-primary-tint/40 px-4 py-3"
                                                key={total.currency}
                                            >
                                                <dt className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                    {total.currency}
                                                </dt>
                                                <dd className="text-2xl leading-tight font-semibold tracking-tight tabular-nums">
                                                    {formatMoney(
                                                        total.net,
                                                        total.currency,
                                                    )}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            ) : (
                                <div className="flex min-h-28 items-center gap-3 border border-dashed border-border bg-muted/30 px-4 py-4">
                                    <span className="grid size-9 shrink-0 place-items-center bg-card text-muted-foreground">
                                        <Banknote
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                    <p className="text-sm font-medium text-muted-foreground">
                                        No collections for this period.
                                    </p>
                                </div>
                            )}
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                Business dates {formatDate(collections.from)} to{' '}
                                {formatDate(collections.to)}. Net received
                                includes refunds and reversals.
                            </p>
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
                                                    query: {
                                                        period,
                                                        ...guestQueue?.filters,
                                                    },
                                                })}
                                                aria-current={
                                                    collections.period ===
                                                    period
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
                        </div>
                        {collections.received.rows.length > 0 && (
                            <div className="dashboard-collections-table border-t border-border px-4 py-4 md:px-5 md:py-5">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold">
                                        Payment breakdown
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        By method and currency
                                    </p>
                                </div>
                                <CollectionsTable
                                    received={collections.received}
                                />
                            </div>
                        )}
                    </section>
                )}

                {guestQueue && (
                    <div
                        className={cn(
                            'min-w-0 border border-border bg-card p-4 shadow-panel md:p-5',
                            roomBoard ? 'xl:col-start-1' : 'xl:col-span-2',
                        )}
                    >
                        <GuestQueue queue={guestQueue} />
                    </div>
                )}

                {roomBoard && (
                    <section
                        aria-labelledby="room-status"
                        className={cn(
                            'min-w-0 space-y-4 border border-border bg-card p-4 shadow-panel md:p-5',
                            guestQueue ? 'xl:col-start-2' : 'xl:col-span-2',
                        )}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                    <DoorOpen
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
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
                            </div>
                            <nav
                                aria-label="Room operations"
                                className="flex flex-wrap gap-2"
                            >
                                {roomBoard.housekeepingHref && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={roomBoard.housekeepingHref}>
                                            <DoorOpen aria-hidden="true" />
                                            Housekeeping
                                        </Link>
                                    </Button>
                                )}
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={roomBoard.calendarHref}>
                                        <CalendarDays aria-hidden="true" />
                                        Calendar
                                    </Link>
                                </Button>
                            </nav>
                        </div>
                        {roomBoard.rooms.data.length === 0 ? (
                            <div className="border-y">
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

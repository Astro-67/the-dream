import { Form, Link } from '@inertiajs/react';
import { Search, Users } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import {
    paymentState,
    type ReservationListItem,
} from '@/features/reservations/reservation-list';
import { formatDate, formatMoney } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show } from '@/routes/reservations';
import type { Paginated } from '@/types';

export type GuestQueue = {
    filters: { list: string; search: string };
    period: string;
    reservations: Paginated<
        ReservationListItem & { front_desk_priority: number }
    >;
};

const priorities = [
    {
        label: 'Overdue departures',
        className: 'border-t-status-error bg-status-error-tint/15',
        labelClassName: 'text-status-error',
        dotClassName: 'bg-status-error',
    },
    {
        label: 'Needs attention',
        className: 'border-t-status-warning bg-status-warning-tint/15',
        labelClassName: 'text-status-warning',
        dotClassName: 'bg-status-warning',
    },
    {
        label: 'In progress',
        className: 'border-t-status-info bg-status-info-tint/15',
        labelClassName: 'text-status-info',
        dotClassName: 'bg-status-info',
    },
    {
        label: 'Completed',
        className: 'border-t-status-success bg-status-success-tint/15',
        labelClassName: 'text-status-success',
        dotClassName: 'bg-status-success',
    },
];

export function GuestQueueSearch({ queue }: { queue: GuestQueue }) {
    return (
        <Form
            {...dashboard.form()}
            className="grid min-w-0 gap-3 border border-l-4 border-border border-l-primary bg-muted/20 p-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end md:p-4"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="list"
                        value={queue.filters.list}
                    />
                    <input type="hidden" name="period" value={queue.period} />
                    <div className="min-w-0 space-y-2">
                        <Label htmlFor="guest-search">
                            Search guests and reservations
                        </Label>
                        <div className="relative">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="guest-search"
                                name="search"
                                type="search"
                                maxLength={255}
                                defaultValue={queue.filters.search}
                                placeholder="Name, room, phone, or reservation number"
                                className="pl-10"
                                aria-describedby={
                                    errors.search
                                        ? 'guest-search-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(errors.search)}
                            />
                        </div>
                        <InputError
                            id="guest-search-error"
                            message={errors.search}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-w-28"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Search aria-hidden="true" />
                            )}
                            Search
                        </Button>
                        {queue.filters.search && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={dashboard({
                                        query: {
                                            list: queue.filters.list,
                                            period: queue.period,
                                        },
                                    })}
                                >
                                    Clear search
                                </Link>
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

export function GuestQueue({
    queue,
    showSearch = true,
}: {
    queue: GuestQueue;
    showSearch?: boolean;
}) {
    return (
        <section aria-labelledby="guest-queue-title" className="space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
                <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                    <Users aria-hidden="true" className="size-5" />
                </span>
                <div>
                    <p className="text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Front desk actions
                    </p>
                    <h2
                        id="guest-queue-title"
                        className="text-lg font-semibold"
                    >
                        Guest queue
                    </h2>
                </div>
            </div>
            {showSearch && <GuestQueueSearch queue={queue} />}
            {queue.reservations.data.length === 0 ? (
                <EmptyState
                    message={
                        queue.filters.search
                            ? 'No reservations match this search.'
                            : 'No reservations in this guest list for the business date.'
                    }
                    className="border-y"
                />
            ) : (
                <div
                    className="max-h-[42rem] space-y-6 overflow-auto"
                    tabIndex={0}
                    role="region"
                    aria-label="Scrollable guest queue"
                >
                    {priorities.map((priority, index) => {
                        const reservations = queue.reservations.data.filter(
                            (reservation) =>
                                reservation.front_desk_priority === index,
                        );
                        if (reservations.length === 0) return null;
                        return (
                            <section
                                key={index}
                                aria-labelledby={`guest-priority-${index}`}
                                className={`space-y-2 border border-t-4 border-border p-3 ${priority.className}`}
                            >
                                <h3
                                    id={`guest-priority-${index}`}
                                    className={`flex flex-wrap items-center justify-between gap-2 text-sm font-semibold ${priority.labelClassName}`}
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <span
                                            aria-hidden="true"
                                            className={`size-2 rounded-full ${priority.dotClassName}`}
                                        />
                                        {priority.label}
                                    </span>
                                    <span className="font-normal text-muted-foreground">
                                        {reservations.length} on this page
                                    </span>
                                </h3>
                                <ul className="divide-y divide-border">
                                    {reservations.map((reservation) => (
                                        <li
                                            key={reservation.id}
                                            className="grid min-w-0 gap-4 py-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]"
                                        >
                                            <div className="min-w-0 space-y-2">
                                                <Link
                                                    href={show(
                                                        reservation.reservation_number,
                                                    )}
                                                    className="inline-flex min-h-11 items-center font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                >
                                                    <span className="wrap-anywhere">
                                                        {reservation.guest_name ??
                                                            'Guest not yet added'}
                                                    </span>
                                                </Link>
                                                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                                                    <Identifier
                                                        value={
                                                            reservation.reservation_number
                                                        }
                                                    />
                                                    <span className="text-muted-foreground">
                                                        {reservation.room_number
                                                            ? `Room ${reservation.room_number}`
                                                            : 'Room unassigned'}
                                                    </span>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {formatDate(
                                                        reservation.arrival,
                                                    )}{' '}
                                                    -{' '}
                                                    {formatDate(
                                                        reservation.departure,
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap content-start items-start gap-2">
                                                <StatusChip tone="info">
                                                    {reservation.status.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </StatusChip>
                                                {reservation.is_overstay && (
                                                    <StatusChip tone="warning">
                                                        Overstay
                                                    </StatusChip>
                                                )}
                                                {reservation.requires_reconciliation && (
                                                    <StatusChip tone="warning">
                                                        Requires reconciliation
                                                    </StatusChip>
                                                )}
                                            </div>
                                            <div className="space-y-2 text-sm tabular-nums md:text-right">
                                                {reservation.balances.length ===
                                                0 ? (
                                                    <p className="text-muted-foreground">
                                                        No folio balance
                                                    </p>
                                                ) : (
                                                    reservation.balances.map(
                                                        (balance) => {
                                                            const state =
                                                                paymentState(
                                                                    balance,
                                                                );
                                                            return (
                                                                <div
                                                                    key={
                                                                        balance.currency
                                                                    }
                                                                    className="flex flex-wrap items-center gap-2 md:justify-end"
                                                                >
                                                                    <StatusChip
                                                                        tone={
                                                                            state.tone
                                                                        }
                                                                    >
                                                                        {
                                                                            state.label
                                                                        }
                                                                    </StatusChip>
                                                                    <span>
                                                                        {balance.balance ===
                                                                        0
                                                                            ? balance.currency
                                                                            : formatMoney(
                                                                                  balance.balance,
                                                                                  balance.currency,
                                                                              )}
                                                                    </span>
                                                                </div>
                                                            );
                                                        },
                                                    )
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            )}
            <Pagination page={queue.reservations} />
        </section>
    );
}

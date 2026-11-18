import { Form, Link } from '@inertiajs/react';
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
    'Overdue departures',
    'Needs attention',
    'In progress',
    'Completed',
];

export function GuestQueue({ queue }: { queue: GuestQueue }) {
    return (
        <section aria-labelledby="guest-queue-title" className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <h2 id="guest-queue-title" className="text-lg font-semibold">
                    Guest queue
                </h2>
                <Form
                    {...dashboard.form()}
                    className="flex w-full flex-wrap items-end gap-3 sm:w-auto"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="list"
                                value={queue.filters.list}
                            />
                            <input
                                type="hidden"
                                name="period"
                                value={queue.period}
                            />
                            <div className="min-w-0 flex-1 space-y-2">
                                <Label htmlFor="guest-search">
                                    Search guests and reservations
                                </Label>
                                <Input
                                    id="guest-search"
                                    name="search"
                                    type="search"
                                    maxLength={255}
                                    defaultValue={queue.filters.search}
                                    aria-describedby={
                                        errors.search
                                            ? 'guest-search-error'
                                            : undefined
                                    }
                                    aria-invalid={Boolean(errors.search)}
                                />
                                <InputError
                                    id="guest-search-error"
                                    message={errors.search}
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                <span className="inline-flex size-4 items-center justify-center">
                                    {processing && <Spinner />}
                                </span>
                                Search
                            </Button>
                            {queue.filters.search && (
                                <Button variant="ghost" asChild>
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
                        </>
                    )}
                </Form>
            </div>
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
                    {priorities.map((label, priority) => {
                        const reservations = queue.reservations.data.filter(
                            (reservation) =>
                                reservation.front_desk_priority === priority,
                        );
                        if (reservations.length === 0) return null;
                        return (
                            <section
                                key={priority}
                                aria-labelledby={`guest-priority-${priority}`}
                                className="space-y-2"
                            >
                                <h3
                                    id={`guest-priority-${priority}`}
                                    className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold"
                                >
                                    {label}
                                    <span className="font-normal text-muted-foreground">
                                        {reservations.length} on this page
                                    </span>
                                </h3>
                                <ul className="divide-y border-y">
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

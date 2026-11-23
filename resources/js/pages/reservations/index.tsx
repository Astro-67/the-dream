import { Form, Head, Link } from '@inertiajs/react';
import { CalendarDays, FileUp, Plus, Search } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip, Tag, type StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CheckOutDialog } from '@/features/front-desk/check-out-dialog';
import {
    paymentState,
    type ReservationListItem as Reservation,
} from '@/features/reservations/reservation-list';
import { formatDate } from '@/features/platform/format';
import { cn, formatMoney } from '@/lib/utils';
import { CheckInForm } from '@/features/front-desk/check-in-form';
import { availability, create, index, show } from '@/routes/reservations';
import { index as importsIndex } from '@/routes/imports';
import { show as showGuest } from '@/routes/guests';
import type { Paginated } from '@/types';

type Filters = { date: string; list: string; search: string };

const reservationStatusTones: Record<string, StatusTone> = {
    confirmed: 'info',
    checked_in: 'occupied',
    checked_out: 'success',
    cancelled: 'error',
    no_show: 'warning',
    void: 'unavailable',
};

const reservationStatusBorders: Record<string, string> = {
    confirmed: 'border-l-status-info',
    checked_in: 'border-l-status-occupied',
    checked_out: 'border-l-status-success',
    cancelled: 'border-l-status-error',
    no_show: 'border-l-status-warning',
    void: 'border-l-status-unavailable',
};

const reservationListColors: Record<string, { dot: string; active: string }> = {
    upcoming: {
        dot: 'bg-status-info',
        active: 'aria-[current=page]:bg-status-info-tint aria-[current=page]:text-status-info',
    },
    today: {
        dot: 'bg-status-warning',
        active: 'aria-[current=page]:bg-status-warning-tint aria-[current=page]:text-status-warning',
    },
    in_house: {
        dot: 'bg-status-occupied',
        active: 'aria-[current=page]:bg-status-occupied-tint aria-[current=page]:text-status-occupied',
    },
    active: {
        dot: 'bg-primary',
        active: 'aria-[current=page]:bg-primary-tint aria-[current=page]:text-primary',
    },
    cancelled: {
        dot: 'bg-status-error',
        active: 'aria-[current=page]:bg-status-error-tint aria-[current=page]:text-status-error',
    },
    past: {
        dot: 'bg-status-unavailable',
        active: 'aria-[current=page]:bg-status-unavailable-tint aria-[current=page]:text-status-unavailable',
    },
    all: {
        dot: 'bg-primary',
        active: 'aria-[current=page]:bg-primary-tint aria-[current=page]:text-primary',
    },
    departures: {
        dot: 'bg-status-warning',
        active: 'aria-[current=page]:bg-status-warning-tint aria-[current=page]:text-status-warning',
    },
    overstays: {
        dot: 'bg-status-error',
        active: 'aria-[current=page]:bg-status-error-tint aria-[current=page]:text-status-error',
    },
    'balance-owing': {
        dot: 'bg-status-warning',
        active: 'aria-[current=page]:bg-status-warning-tint aria-[current=page]:text-status-warning',
    },
    'no-id-document': {
        dot: 'bg-status-info',
        active: 'aria-[current=page]:bg-status-info-tint aria-[current=page]:text-status-info',
    },
};

export default function Index({
    reservations,
    filters,
    canImport,
    canCreate,
    canViewGuestProfiles,
    importLabel,
}: {
    reservations: Paginated<Reservation>;
    filters: Filters;
    canImport: boolean;
    canCreate: boolean;
    canViewGuestProfiles: boolean;
    importLabel: string;
}) {
    return (
        <>
            <Head title="Reservations" />
            <div className="flex min-w-0 flex-col gap-6">
                <header className="overflow-hidden border border-border bg-card shadow-panel">
                    <div className="flex flex-wrap items-center justify-between gap-5 border-l-4 border-l-primary p-4 md:p-5">
                        <div className="flex min-w-0 items-center gap-4">
                            <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary shadow-panel">
                                <CalendarDays
                                    aria-hidden="true"
                                    className="size-6"
                                />
                            </span>
                            <div className="space-y-1">
                                <p className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                    Front desk
                                </p>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    Reservations
                                </h1>
                                <p className="inline-flex items-center gap-1.5 border border-primary/15 bg-primary-tint px-2.5 py-1 text-xs font-medium text-primary">
                                    <CalendarDays
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    Business date:{' '}
                                    {formatDate(filters.date, 'UTC')}
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {canCreate && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus aria-hidden="true" />
                                        New booking
                                    </Link>
                                </Button>
                            )}
                            {canImport && (
                                <Button asChild variant="outline">
                                    <Link href={importsIndex()}>
                                        <FileUp aria-hidden="true" />
                                        {importLabel}
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <Link href={availability()}>
                                    <CalendarDays aria-hidden="true" />
                                    Find availability
                                </Link>
                            </Button>
                        </div>
                    </div>
                </header>
                <div className="space-y-3 border border-l-4 border-border border-l-primary bg-card p-3 shadow-panel md:p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <CalendarDays
                                aria-hidden="true"
                                className="size-4 text-primary"
                            />
                            <p className="text-xs font-semibold tracking-[0.1em] text-muted-foreground uppercase">
                                Reservation lists
                            </p>
                        </div>
                        <p className="border border-border bg-muted/30 px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums">
                            {reservations.total} matching reservations
                        </p>
                    </div>
                    <nav
                        aria-label="Reservation lists"
                        className="flex max-w-full flex-wrap gap-2"
                    >
                        {[
                            ['upcoming', 'Upcoming'],
                            ['today', 'Today'],
                            ['in_house', 'In house'],
                            ['active', 'Active'],
                            ['cancelled', 'Cancelled'],
                            ['past', 'Past'],
                            ['all', 'All'],
                        ].map(([list, label]) => {
                            const colors = reservationListColors[list];
                            return (
                                <Button
                                    key={list}
                                    asChild
                                    variant="outline"
                                    className={cn(
                                        'min-h-10 border-border bg-background text-muted-foreground aria-[current=page]:border-transparent',
                                        colors?.active ??
                                            'aria-[current=page]:bg-primary-tint aria-[current=page]:text-primary',
                                    )}
                                >
                                    <Link
                                        href={index({
                                            query: { ...filters, list },
                                        })}
                                        aria-current={
                                            filters.list === list
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'size-1.5 rounded-full',
                                                colors?.dot ?? 'bg-primary',
                                            )}
                                        />
                                        {label}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                    <nav
                        aria-label="Additional reservation lists"
                        className="flex flex-wrap gap-2 border-t border-border pt-3"
                    >
                        {[
                            ['departures', 'Departures'],
                            ['overstays', 'Overstays'],
                            ['balance-owing', 'Balance owing'],
                            ['no-id-document', 'No ID document'],
                        ].map(([list, label]) => {
                            const colors = reservationListColors[list];
                            return (
                                <Button
                                    key={list}
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className={cn(
                                        'border-border bg-background text-muted-foreground aria-[current=page]:border-transparent',
                                        colors?.active ??
                                            'aria-[current=page]:bg-primary-tint aria-[current=page]:text-primary',
                                    )}
                                >
                                    <Link
                                        href={index({
                                            query: { ...filters, list },
                                        })}
                                        aria-current={
                                            filters.list === list
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'size-1.5 rounded-full',
                                                colors?.dot ?? 'bg-primary',
                                            )}
                                        />
                                        {label}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </div>
                <Form
                    {...index.form()}
                    className="grid min-w-0 gap-3 border border-border bg-primary-tint/30 p-4 shadow-panel md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end md:p-5"
                >
                    {({ errors }) => (
                        <>
                            <div className="min-w-0 space-y-2">
                                <Label htmlFor="date">Business date</Label>
                                <Input
                                    id="date"
                                    name="date"
                                    type="date"
                                    defaultValue={filters.date}
                                    required
                                />
                                <InputError message={errors.date} />
                            </div>
                            <input
                                type="hidden"
                                name="list"
                                value={filters.list}
                            />
                            <InputError message={errors.list} />
                            <div className="min-w-0 space-y-2">
                                <Label htmlFor="search">
                                    Find a reservation
                                </Label>
                                <div className="relative">
                                    <Search
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        className="pl-9"
                                        id="search"
                                        name="search"
                                        defaultValue={filters.search}
                                        maxLength={255}
                                        placeholder="Room, guest, phone, reservation or channel reference"
                                    />
                                </div>
                                <InputError message={errors.search} />
                            </div>
                            <Button
                                type="submit"
                                className="min-h-11 md:min-h-9"
                            >
                                <Search aria-hidden="true" />
                                Search
                            </Button>
                        </>
                    )}
                </Form>
                {reservations.data.length === 0 ? (
                    <EmptyState
                        message="No reservations match these filters."
                        action={
                            <Button variant="outline" asChild>
                                <Link href={availability()}>
                                    Find availability
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-3 px-1">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-9 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                        <CalendarDays
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                    <div>
                                        <h2 className="font-semibold">
                                            Reservations
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            Guest stays and payment status
                                        </p>
                                    </div>
                                </div>
                                <p className="border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground shadow-panel">
                                    Showing {reservations.data.length} of{' '}
                                    {reservations.total}
                                </p>
                            </div>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Guest</TableHead>
                                        <TableHead>Reservation</TableHead>
                                        <TableHead>Room</TableHead>
                                        <TableHead>Check-in</TableHead>
                                        <TableHead>Check-out</TableHead>
                                        <TableHead numeric>Payment</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reservations.data.map((reservation) => (
                                        <TableRow key={reservation.id}>
                                            <TableCell
                                                className={cn(
                                                    'min-w-52 border-l-4',
                                                    reservationStatusBorders[
                                                        reservation.status
                                                    ] ?? 'border-l-border',
                                                )}
                                            >
                                                {canViewGuestProfiles &&
                                                reservation.guest_id ? (
                                                    <Link
                                                        href={showGuest(
                                                            reservation.guest_id,
                                                        )}
                                                        className="inline-flex min-h-11 items-center font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                    >
                                                        {reservation.guest_name ??
                                                            'Guest profile'}
                                                    </Link>
                                                ) : (
                                                    <span className="font-semibold">
                                                        {reservation.guest_name ??
                                                            'No guest added'}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                <Link
                                                    href={show(
                                                        reservation.reservation_number,
                                                    )}
                                                    className="text-primary underline"
                                                >
                                                    <Identifier
                                                        value={
                                                            reservation.reservation_number
                                                        }
                                                    />
                                                </Link>
                                                {reservation.channel_reference && (
                                                    <div className="text-sm text-muted-foreground">
                                                        <Identifier
                                                            value={
                                                                reservation.channel_reference
                                                            }
                                                        />
                                                    </div>
                                                )}
                                                {reservation.booking_group_id && (
                                                    <div className="mt-1">
                                                        <Tag>Booking group</Tag>
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {reservation.room_number ? (
                                                    <Tag active>
                                                        Room{' '}
                                                        {
                                                            reservation.room_number
                                                        }
                                                    </Tag>
                                                ) : (
                                                    <StatusChip tone="warning">
                                                        Unassigned
                                                    </StatusChip>
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {formatDate(
                                                    reservation.arrival,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {formatDate(
                                                    reservation.departure,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                            <TableCell numeric>
                                                {reservation.balances.length ===
                                                0
                                                    ? '—'
                                                    : reservation.balances.map(
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
                                                                      className="flex items-center justify-end gap-2 tabular-nums"
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
                                                                      {balance.balance ===
                                                                      0
                                                                          ? balance.currency
                                                                          : formatMoney(
                                                                                balance.balance,
                                                                                balance.currency,
                                                                            )}
                                                                  </div>
                                                              );
                                                          },
                                                      )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex min-w-32 flex-col items-start gap-1.5">
                                                    <StatusChip
                                                        tone={
                                                            reservationStatusTones[
                                                                reservation
                                                                    .status
                                                            ] ?? 'info'
                                                        }
                                                    >
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
                                                            Requires
                                                            reconciliation
                                                        </StatusChip>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex min-w-32 flex-wrap justify-end gap-1.5">
                                                    {canCreate &&
                                                        reservation.status ===
                                                            'cancelled' && (
                                                            <Button
                                                                asChild
                                                                size="sm"
                                                                variant="outline"
                                                            >
                                                                <Link
                                                                    href={create(
                                                                        {
                                                                            query: {
                                                                                from: reservation.reservation_number,
                                                                            },
                                                                        },
                                                                    )}
                                                                >
                                                                    Book again
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    {reservation.can_check_in && (
                                                        <CheckInForm
                                                            number={
                                                                reservation.reservation_number
                                                            }
                                                            guestProfileId={
                                                                reservation.guest_id
                                                            }
                                                        />
                                                    )}
                                                    {reservation.can_check_out && (
                                                        <CheckOutDialog
                                                            number={
                                                                reservation.reservation_number
                                                            }
                                                            balances={
                                                                reservation.checkout_balances
                                                            }
                                                            canOverride={
                                                                reservation.can_override_balance ??
                                                                false
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination page={reservations} />
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip, Tag } from '@/components/ui/status-chip';
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
import { formatMoney } from '@/lib/utils';
import { PropertyIntent } from '@/components/shared/property-intent';
import {
    availability,
    checkIn,
    create,
    index,
    show,
} from '@/routes/reservations';
import { index as importsIndex } from '@/routes/imports';
import type { Paginated } from '@/types';

type Filters = { date: string; list: string; search: string };

export default function Index({
    reservations,
    filters,
    canImport,
    canCreate,
    importLabel,
}: {
    reservations: Paginated<Reservation>;
    filters: Filters;
    canImport: boolean;
    canCreate: boolean;
    importLabel: string;
}) {
    return (
        <>
            <Head title="Reservations" />
            <div className="flex min-w-0 flex-col gap-6">
                <header className="flex flex-wrap items-center justify-between gap-4 border-b pb-6">
                    <div>
                        <h1 className="text-2xl font-semibold">Reservations</h1>
                        <p className="text-sm text-muted-foreground">
                            Business date: {formatDate(filters.date, 'UTC')}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canCreate && (
                            <Button asChild>
                                <Link href={create()}>New booking</Link>
                            </Button>
                        )}
                        {canImport && (
                            <Button asChild variant="outline">
                                <Link href={importsIndex()}>{importLabel}</Link>
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link href={availability()}>Find availability</Link>
                        </Button>
                    </div>
                </header>
                <nav
                    aria-label="Reservation lists"
                    className="flex flex-wrap gap-2"
                >
                    {[
                        ['upcoming', 'Upcoming'],
                        ['today', 'Today'],
                        ['in_house', 'In house'],
                        ['active', 'Active'],
                        ['cancelled', 'Cancelled'],
                        ['past', 'Past'],
                        ['all', 'All'],
                    ].map(([list, label]) => (
                        <Button
                            key={list}
                            asChild
                            variant={
                                filters.list === list ? 'default' : 'outline'
                            }
                        >
                            <Link
                                href={index({ query: { ...filters, list } })}
                                aria-current={
                                    filters.list === list ? 'page' : undefined
                                }
                            >
                                {label}
                            </Link>
                        </Button>
                    ))}
                </nav>
                <nav
                    aria-label="Additional reservation lists"
                    className="flex flex-wrap gap-2"
                >
                    {[
                        ['departures', 'Departures'],
                        ['overstays', 'Overstays'],
                        ['balance-owing', 'Balance owing'],
                        ['no-id-document', 'No ID document'],
                    ].map(([list, label]) => (
                        <Button
                            key={list}
                            asChild
                            size="sm"
                            variant={
                                filters.list === list ? 'secondary' : 'ghost'
                            }
                        >
                            <Link
                                href={index({ query: { ...filters, list } })}
                                aria-current={
                                    filters.list === list ? 'page' : undefined
                                }
                            >
                                {label}
                            </Link>
                        </Button>
                    ))}
                </nav>
                <Form
                    {...index.form()}
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ errors }) => (
                        <>
                            <div className="space-y-2">
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
                            <div className="space-y-2">
                                <Label htmlFor="search">Search</Label>
                                <Input
                                    id="search"
                                    name="search"
                                    defaultValue={filters.search}
                                    maxLength={255}
                                    placeholder="Room, guest, phone, reservation or channel reference"
                                />
                                <InputError message={errors.search} />
                            </div>
                            <Button type="submit">Apply filters</Button>
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
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reservations.data.map((reservation) => (
                                    <TableRow key={reservation.id}>
                                        <TableCell className="font-semibold">
                                            {reservation.guest_name ??
                                                'No guest added'}
                                        </TableCell>
                                        <TableCell>
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
                                            {reservation.room_number ??
                                                'Unassigned'}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(
                                                reservation.arrival,
                                                'UTC',
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(
                                                reservation.departure,
                                                'UTC',
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {reservation.balances.length === 0
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
                                        </TableCell>
                                        <TableCell>
                                            {canCreate &&
                                                reservation.status ===
                                                    'cancelled' && (
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={create({
                                                                query: {
                                                                    from: reservation.reservation_number,
                                                                },
                                                            })}
                                                        >
                                                            Book again
                                                        </Link>
                                                    </Button>
                                                )}
                                            {reservation.can_check_in && (
                                                <Form
                                                    {...checkIn.form(
                                                        reservation.reservation_number,
                                                    )}
                                                    disableWhileProcessing
                                                    errorBag={`check-in-${reservation.reservation_number}`}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    className="flex flex-col items-start gap-1"
                                                >
                                                    {({ errors }) => (
                                                        <>
                                                            <PropertyIntent />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                            >
                                                                Check in
                                                            </Button>
                                                            {Object.values(
                                                                errors,
                                                            ).map((error) => (
                                                                <InputError
                                                                    key={error}
                                                                    message={
                                                                        error
                                                                    }
                                                                />
                                                            ))}
                                                        </>
                                                    )}
                                                </Form>
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
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={reservations} />
                    </>
                )}
            </div>
        </>
    );
}

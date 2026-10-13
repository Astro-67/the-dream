import { NativeSelect } from '@/components/ui/select';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CheckOutDialog } from '@/features/front-desk/check-out-dialog';
import { formatDate } from '@/features/platform/format';
import { PropertyIntent } from '@/components/shared/property-intent';
import { availability, checkIn, index, show } from '@/routes/reservations';
import type { Paginated } from '@/types';

type Reservation = {
    id: string;
    reservation_number: string;
    arrival: string;
    departure: string;
    status: string;
    channel_reference: string | null;
    requires_reconciliation: boolean;
    can_check_in: boolean;
    can_check_out: boolean;
    can_override_balance?: boolean;
    balances?: { currency: string; balance: number }[];
};
type Filters = { date: string; list: string; search: string };

export default function Index({
    reservations,
    filters,
}: {
    reservations: Paginated<Reservation>;
    filters: Filters;
}) {
    return (
        <>
            <Head title="Reservations" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Reservations</h1>
                        <p className="text-sm text-muted-foreground">
                            Business date: {formatDate(filters.date, 'UTC')}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={availability()}>Find availability</Link>
                    </Button>
                </header>
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
                            <div className="space-y-2">
                                <Label htmlFor="list">List</Label>
                                <NativeSelect
                                    id="list"
                                    name="list"
                                    defaultValue={filters.list}
                                >
                                    <option value="arrivals">Arrivals</option>
                                    <option value="departures">
                                        Departures
                                    </option>
                                    <option value="in-house">In-house</option>
                                    <option value="all">
                                        All reservations
                                    </option>
                                </NativeSelect>
                                <InputError message={errors.list} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="search">Search</Label>
                                <Input
                                    id="search"
                                    name="search"
                                    defaultValue={filters.search}
                                    maxLength={255}
                                    placeholder="Reservation number, guest name or channel reference"
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
                                    <TableHead>Reservation number</TableHead>
                                    <TableHead>Arrival</TableHead>
                                    <TableHead>Departure</TableHead>
                                    <TableHead>Channel reference</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reservations.data.map((reservation) => (
                                    <TableRow key={reservation.id}>
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
                                        <TableCell>
                                            {reservation.channel_reference ? (
                                                <Identifier
                                                    value={
                                                        reservation.channel_reference
                                                    }
                                                />
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip tone="info">
                                                {reservation.status.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </StatusChip>
                                            {reservation.requires_reconciliation && (
                                                <StatusChip tone="warning">
                                                    Requires reconciliation
                                                </StatusChip>
                                            )}
                                        </TableCell>
                                        <TableCell>
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
                                                        reservation.balances ??
                                                        []
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

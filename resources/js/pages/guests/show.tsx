import { Link } from '@inertiajs/react';
import { CalendarDays, UsersRound } from 'lucide-react';
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
import { ContactShow, type Contact } from '@/features/guests/contact-pages';
import {
    IdentityDetails,
    type IdentityDocument,
} from '@/features/guests/identity-documents';
import { formatDate } from '@/features/platform/format';
import { index } from '@/routes/guests';
import type { Paginated } from '@/types';

type Stay = {
    reservation_number: string;
    arrival: string;
    departure: string;
    status: string;
};
const stayTones: Record<string, StatusTone> = {
    confirmed: 'info',
    checked_in: 'occupied',
    checked_out: 'success',
    cancelled: 'unavailable',
    no_show: 'warning',
    void: 'unavailable',
};
const stayLabels: Record<string, string> = {
    confirmed: 'Confirmed',
    checked_in: 'Checked in',
    checked_out: 'Checked out',
    cancelled: 'Cancelled',
    no_show: 'No show',
    void: 'Void',
};
export default function Show({
    record,
    canManage,
    documents,
    canViewIdentity,
    canMerge,
    stays,
}: {
    record: Contact;
    canManage: boolean;
    documents: IdentityDocument[];
    canViewIdentity: boolean;
    canMerge: boolean;
    stays: Paginated<Stay>;
}) {
    return (
        <ContactShow
            record={record}
            canManage={canManage}
            base="/guests"
            title="guest"
            extraActions={
                canMerge && (
                    <Button variant="outline" asChild>
                        <Link href={`/guests/${record.id}/merge`}>
                            <UsersRound aria-hidden="true" />
                            Merge duplicate
                        </Link>
                    </Button>
                )
            }
        >
            <IdentityDetails
                documents={documents}
                canViewIdentity={canViewIdentity}
            />
            <section
                aria-labelledby="stay-history-title"
                className="overflow-hidden border border-border bg-card shadow-panel"
            >
                <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <CalendarDays
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <div>
                            <h2
                                id="stay-history-title"
                                className="text-base font-semibold"
                            >
                                Stay history
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Reservations linked to this guest
                            </p>
                        </div>
                    </div>
                    <span className="border border-border bg-card px-2.5 py-1 text-xs font-semibold text-muted-foreground tabular-nums">
                        {stays.total}
                    </span>
                </div>
                {stays.data.length === 0 ? (
                    <div className="p-4 md:p-5">
                        <EmptyState message="No reservations linked to this guest. Attach them from a reservation." />
                    </div>
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Reservation</TableHead>
                                    <TableHead>Arrival</TableHead>
                                    <TableHead>Departure</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stays.data.map((stay) => (
                                    <TableRow key={stay.reservation_number}>
                                        <TableCell>
                                            <Link
                                                className="text-primary underline underline-offset-4"
                                                href={`/reservations/${encodeURIComponent(stay.reservation_number)}`}
                                            >
                                                <Identifier
                                                    value={
                                                        stay.reservation_number
                                                    }
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(stay.arrival, 'UTC')}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(stay.departure, 'UTC')}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    stayTones[stay.status] ??
                                                    'unavailable'
                                                }
                                            >
                                                {stayLabels[stay.status] ??
                                                    stay.status}
                                            </StatusChip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="border-t border-border px-4 py-3 md:px-5">
                            <Pagination page={stays} />
                        </div>
                    </>
                )}
            </section>
        </ContactShow>
    );
}
Show.layout = {
    breadcrumbs: [
        { title: 'Guests', href: index() },
        { title: 'Guest details', href: '#' },
    ],
};

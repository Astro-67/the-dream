import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
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
import { ContactShow, type Contact } from '@/features/guests/contact-pages';
import {
    IdentityDetails,
    type IdentityDocument,
} from '@/features/guests/identity-documents';
import { formatDate } from '@/features/platform/format';
import type { Paginated } from '@/types';

type Stay = {
    reservation_number: string;
    arrival: string;
    departure: string;
    status: string;
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
        >
            <IdentityDetails
                documents={documents}
                canViewIdentity={canViewIdentity}
            />
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Stay history</h2>
                {stays.data.length === 0 ? (
                    <EmptyState message="No reservations linked to this guest. Attach them from a reservation." />
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
                                                    stay.status ===
                                                        'confirmed' ||
                                                    stay.status === 'checked_in'
                                                        ? 'success'
                                                        : 'unavailable'
                                                }
                                            >
                                                {stay.status.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </StatusChip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={stays} />
                    </>
                )}
            </section>
            {canMerge && (
                <Button variant="outline" asChild>
                    <Link href={`/guests/${record.id}/merge`}>
                        Merge duplicate guest
                    </Link>
                </Button>
            )}
        </ContactShow>
    );
}
Show.layout = {
    breadcrumbs: [
        { title: 'Guests', href: '/guests' },
        { title: 'Guest details', href: '/guests' },
    ],
};

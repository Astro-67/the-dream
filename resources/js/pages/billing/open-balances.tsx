import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney } from '@/lib/utils';
import { show } from '@/routes/reservations';
import type { Paginated } from '@/types';

type OpenBalance = {
    id: string;
    currency: string;
    balance: number;
    reservation_number: string;
    departure: string;
};

/** Folios of checked-out stays still owing or owed money, until paid or written off. */
export default function OpenBalances({
    folios,
}: {
    folios: Paginated<OpenBalance>;
}) {
    return (
        <>
            <Head title="Open balances after departure" />
            <div className="flex min-w-0 flex-col gap-6 md:gap-8">
                <header className="space-y-2 border-b pb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Open balances after departure
                    </h1>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Guests checked out with a balance left on a folio. Each
                        stays here until its balance is zero.
                    </p>
                </header>
                {folios.data.length === 0 ? (
                    <EmptyState message="No open balances after departure." />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Reservation number</TableHead>
                                    <TableHead>Departure</TableHead>
                                    <TableHead numeric>Balance</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {folios.data.map((folio) => (
                                    <TableRow key={folio.id}>
                                        <TableCell>
                                            <Link
                                                href={show(
                                                    folio.reservation_number,
                                                )}
                                                className="text-primary underline"
                                            >
                                                <Identifier
                                                    value={
                                                        folio.reservation_number
                                                    }
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(folio.departure)}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                folio.balance,
                                                folio.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={folios} />
                    </>
                )}
            </div>
        </>
    );
}

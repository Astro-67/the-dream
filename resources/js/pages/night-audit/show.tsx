import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { index } from '@/routes/night-audit';
import { show as reservation } from '@/routes/reservations';
import type { Paginated } from '@/types';

type Charge = {
    id: string;
    reservation_number: string;
    unit_price: number;
    amount: number;
    currency: string;
    tax_lines: { name: string; amount: number }[];
};

export default function Show({
    close,
    charges,
    labels,
}: {
    close: { business_date: string; actor: string };
    charges: Paginated<Charge>;
    labels: Record<string, string>;
}) {
    return (
        <>
            <Head title={`${labels.title} - ${close.business_date}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">{labels.charges}</h1>
                    <p className="text-sm text-muted-foreground">
                        {labels.businessDate}:{' '}
                        {formatDate(close.business_date, 'UTC')}
                        {' · '}
                        {labels.actor}: {close.actor}
                    </p>
                </header>
                <Button variant="outline" asChild className="self-start">
                    <Link href={index()}>{labels.back}</Link>
                </Button>
                {charges.data.length === 0 ? (
                    <EmptyState message={labels.noCharges} />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{labels.reservation}</TableHead>
                                    <TableHead numeric>
                                        {labels.price}
                                    </TableHead>
                                    <TableHead numeric>
                                        {labels.taxes}
                                    </TableHead>
                                    <TableHead numeric>
                                        {labels.amount}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {charges.data.map((charge) => (
                                    <TableRow key={charge.id}>
                                        <TableCell>
                                            <Link
                                                href={reservation(
                                                    charge.reservation_number,
                                                )}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                <Identifier
                                                    value={
                                                        charge.reservation_number
                                                    }
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                charge.unit_price,
                                                charge.currency,
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {charge.tax_lines.map(
                                                (tax, taxIndex) => (
                                                    <div key={taxIndex}>
                                                        {tax.name}:{' '}
                                                        {formatMoney(
                                                            tax.amount,
                                                            charge.currency,
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                charge.amount,
                                                charge.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={charges} />
                    </div>
                )}
            </div>
        </>
    );
}

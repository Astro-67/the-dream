import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import type { StatusTone } from '@/components/ui/status-chip';
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
import { resolve } from '@/routes/no-show-proposals';
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

type NoShow = {
    id: string;
    reservation_number: string;
    status: 'proposed' | 'confirmed' | 'rejected';
    can_resolve: boolean;
};

const tones: Record<NoShow['status'], StatusTone> = {
    proposed: 'warning',
    confirmed: 'unavailable',
    rejected: 'info',
};

export default function Show({
    close,
    charges,
    noShows = [],
    noShowsProposed = 0,
    labels,
}: {
    close: { business_date: string; actor: string };
    charges: Paginated<Charge>;
    noShows?: NoShow[];
    noShowsProposed?: number;
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
                {noShowsProposed > 0 && (
                    <section className="space-y-4">
                        <header>
                            <h2 className="text-lg font-semibold">
                                {labels.noShows}: {noShowsProposed}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {labels.noShowsHelp}
                            </p>
                        </header>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{labels.reservation}</TableHead>
                                    <TableHead>{labels.status}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {noShows.map((noShow) => (
                                    <TableRow key={noShow.id}>
                                        <TableCell>
                                            <Link
                                                href={reservation(
                                                    noShow.reservation_number,
                                                )}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                <Identifier
                                                    value={
                                                        noShow.reservation_number
                                                    }
                                                />
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={tones[noShow.status]}
                                            >
                                                {labels[noShow.status]}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>
                                            {noShow.can_resolve && (
                                                <ResolveNoShow
                                                    id={noShow.id}
                                                    labels={labels}
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}
            </div>
        </>
    );
}

function ResolveNoShow({
    id,
    labels,
}: {
    id: string;
    labels: Record<string, string>;
}) {
    return (
        <Form {...resolve.form(id)} options={{ preserveScroll: true }}>
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            name="decision"
                            value="confirm"
                            size="sm"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {labels.confirm}
                        </Button>
                        <Button
                            type="submit"
                            name="decision"
                            value="reject"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {labels.reject}
                        </Button>
                    </div>
                    <InputError
                        message={
                            errors.no_show ??
                            errors.reservation ??
                            errors.decision
                        }
                    />
                </>
            )}
        </Form>
    );
}

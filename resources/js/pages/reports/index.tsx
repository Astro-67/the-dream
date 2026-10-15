import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import FinanceReport from '@/features/reports/finance-report';
import type { PropertyFinance } from '@/features/reports/finance-report';
import ReportLayout from '@/features/reports/report-layout';
import type { ReportFilters } from '@/features/reports/report-layout';
import { formatMoney } from '@/lib/utils';
import { index } from '@/routes/reports';

type PropertyKpis = {
    soldRoomNights: number;
    sellableRoomNights: number;
    occupancy: number | null;
    currencies: {
        currency: string;
        bookedRoomValue: number;
        bookedAdr: number | null;
        bookedRevpar: number | null;
    }[];
};

const money = (amount: number | null, currency: string) =>
    amount === null ? 'Not applicable' : formatMoney(amount, currency);

export default function PropertyReport({
    filters,
    kpis,
    finance,
    refreshedAt,
}: {
    filters: ReportFilters;
    kpis: PropertyKpis;
    finance: PropertyFinance;
    refreshedAt: string;
}) {
    return (
        <ReportLayout
            title="Property reports"
            filters={filters}
            refreshedAt={refreshedAt}
            action={index.url()}
        >
            <section aria-labelledby="occupancy-title" className="grid gap-4">
                <div>
                    <h2 id="occupancy-title" className="text-lg font-semibold">
                        Occupancy
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Sold room-nights divided by sellable room-nights, from
                        inventory records.
                    </p>
                </div>
                <dl className="flex flex-wrap gap-x-12 gap-y-4 rounded-xl border bg-card p-4">
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Occupancy
                        </dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {kpis.occupancy === null
                                ? 'Not applicable'
                                : new Intl.NumberFormat('en', {
                                      style: 'percent',
                                      maximumFractionDigits: 2,
                                  }).format(kpis.occupancy)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Sold room-nights
                        </dt>
                        <dd className="text-2xl tabular-nums">
                            {kpis.soldRoomNights}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            Sellable room-nights
                        </dt>
                        <dd className="text-2xl tabular-nums">
                            {kpis.sellableRoomNights}
                        </dd>
                    </div>
                </dl>
            </section>
            <section aria-labelledby="booked-title" className="grid gap-4">
                <div>
                    <h2 id="booked-title" className="text-lg font-semibold">
                        Booked room value
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Reservation price lines allocated to the selected
                        nights. Cancelled, no-show and void reservations are
                        excluded. This is not posted charges or payments.
                    </p>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Currency</TableHead>
                            <TableHead numeric>Booked room value</TableHead>
                            <TableHead numeric>Booked ADR</TableHead>
                            <TableHead numeric>Booked RevPAR</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {kpis.currencies.map((row) => (
                            <TableRow key={row.currency}>
                                <TableCell>{row.currency}</TableCell>
                                <TableCell numeric>
                                    {money(row.bookedRoomValue, row.currency)}
                                </TableCell>
                                <TableCell numeric>
                                    {money(row.bookedAdr, row.currency)}
                                </TableCell>
                                <TableCell numeric>
                                    {money(row.bookedRevpar, row.currency)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
                <dl className="grid gap-3 text-sm">
                    <div>
                        <dt className="font-medium">Booked ADR</dt>
                        <dd className="text-muted-foreground">
                            Booked room value in each currency divided by all
                            sold room-nights.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-medium">Booked RevPAR</dt>
                        <dd className="text-muted-foreground">
                            Booked room value in each currency divided by all
                            sellable room-nights.
                        </dd>
                    </div>
                </dl>
                <p className="text-sm text-muted-foreground">
                    Currencies are never added together. Money is rounded to the
                    nearest minor unit. A zero denominator is not applicable.
                </p>
            </section>
            <FinanceReport finance={finance} />
        </ReportLayout>
    );
}

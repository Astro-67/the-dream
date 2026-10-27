import { Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show } from '@/routes/reservations';
import FinanceReport, {
    ShowSettledField,
} from '@/features/reports/finance-report';
import KpiReport from '@/features/reports/kpi-report';
import type { Kpis } from '@/features/reports/kpi-report';
import type { PropertyFinance } from '@/features/reports/finance-report';
import ReportLayout from '@/features/reports/report-layout';
import type { ReportFilters } from '@/features/reports/report-layout';
import PropertyOperations from '@/features/reports/property-operations';
import type { PropertyOperationsData } from '@/features/reports/property-operations';
import { index } from '@/routes/reports';

export default function PropertyReport({
    filters,
    kpis,
    operations,
    finance,
    refreshedAt,
    fiscalExceptions,
}: {
    filters: ReportFilters & { settled: boolean };
    kpis: Kpis;
    operations: PropertyOperationsData;
    finance: PropertyFinance;
    refreshedAt: string;
    fiscalExceptions?:
        | { reservationNumber: string; kind: string; reason: string | null }[]
        | null;
}) {
    return (
        <ReportLayout
            title="Property reports"
            filters={filters}
            refreshedAt={refreshedAt}
            action={index.url()}
            fields={<ShowSettledField checked={filters.settled} />}
        >
            <KpiReport kpis={kpis} />
            <PropertyOperations operations={operations} />
            <FinanceReport finance={finance} />
            {fiscalExceptions && (
                <section
                    className="space-y-4"
                    aria-labelledby="fiscal-exceptions-heading"
                >
                    <h2
                        id="fiscal-exceptions-heading"
                        className="text-lg font-semibold"
                    >
                        Fiscal exceptions
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        All recorded fiscal exceptions for this property,
                        regardless of the date filter. The accountant must
                        include unfiled stays in the property's VAT return.
                    </p>
                    {fiscalExceptions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No fiscal exceptions recorded.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Reservation</TableHead>
                                    <TableHead>Exception</TableHead>
                                    <TableHead>Reason</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {fiscalExceptions.map((exception, index) => (
                                    <TableRow key={index}>
                                        <TableCell>
                                            <Link
                                                className="text-primary underline-offset-4 hover:underline"
                                                href={show.url(
                                                    exception.reservationNumber,
                                                )}
                                            >
                                                {exception.reservationNumber}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{exception.kind}</TableCell>
                                        <TableCell className="whitespace-normal">
                                            {exception.reason}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            )}
        </ReportLayout>
    );
}

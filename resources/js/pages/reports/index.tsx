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
import { Button } from '@/components/ui/button';
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
import ZraFilings from '@/features/reports/zra-filings';
import type { ZraFilingsData } from '@/features/reports/zra-filings';
import { index } from '@/routes/reports';

const views = { overview: 'Overview', filings: 'ZRA filings' } as const;

export default function PropertyReport({
    filters,
    filingsAvailable,
    kpis,
    operations,
    finance,
    filings,
    refreshedAt,
    fiscalExceptions,
}: {
    filters: ReportFilters & { settled: boolean; view: keyof typeof views };
    filingsAvailable: boolean;
    kpis: Kpis | null;
    operations: PropertyOperationsData | null;
    finance: PropertyFinance | null;
    filings: ZraFilingsData | null;
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
            fields={
                <>
                    <input type="hidden" name="view" value={filters.view} />
                    {filters.view === 'overview' && (
                        <ShowSettledField checked={filters.settled} />
                    )}
                </>
            }
        >
            {(filingsAvailable || filters.view === 'filings') && (
                <nav aria-label="Report views" className="flex gap-2">
                    {Object.entries(views).map(([view, label]) => (
                        <Button
                            key={view}
                            variant={
                                filters.view === view ? 'default' : 'outline'
                            }
                            asChild
                        >
                            <Link
                                href={index({
                                    query: {
                                        from: filters.from,
                                        to: filters.to,
                                        view,
                                    },
                                })}
                                aria-current={
                                    filters.view === view ? 'page' : undefined
                                }
                            >
                                {label}
                            </Link>
                        </Button>
                    ))}
                </nav>
            )}
            {kpis && <KpiReport kpis={kpis} />}
            {operations && <PropertyOperations operations={operations} />}
            {finance && <FinanceReport finance={finance} />}
            {filings && <ZraFilings filings={filings} range={filters} />}
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

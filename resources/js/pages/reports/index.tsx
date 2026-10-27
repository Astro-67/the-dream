import { Link } from '@inertiajs/react';
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
}: {
    filters: ReportFilters & { settled: boolean; view: keyof typeof views };
    filingsAvailable: boolean;
    kpis: Kpis | null;
    operations: PropertyOperationsData | null;
    finance: PropertyFinance | null;
    filings: ZraFilingsData | null;
    refreshedAt: string;
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
        </ReportLayout>
    );
}

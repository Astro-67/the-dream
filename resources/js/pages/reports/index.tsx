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
}: {
    filters: ReportFilters & { settled: boolean };
    kpis: Kpis;
    operations: PropertyOperationsData;
    finance: PropertyFinance;
    refreshedAt: string;
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
        </ReportLayout>
    );
}

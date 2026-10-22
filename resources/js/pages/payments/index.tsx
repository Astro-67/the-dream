import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    CollectionsTable,
    PaymentsTable,
    periodLabels,
    type PaymentRow,
    type Received,
} from '@/features/reports/collections-table';
import {
    FolioBalances,
    ShowSettledField,
    type PropertyFinance,
} from '@/features/reports/finance-report';
import ReportLayout from '@/features/reports/report-layout';
import { index } from '@/routes/payments';
import type { Paginated } from '@/types';

type Filters = {
    from: string;
    to: string;
    period: string;
    view: string;
    settled: boolean;
};

export default function Payments({
    filters,
    received,
    payments,
    folios,
    refreshedAt,
}: {
    filters: Filters;
    received: Received | null;
    payments: Paginated<PaymentRow> | null;
    folios: PropertyFinance['folios'] | null;
    refreshedAt: string;
}) {
    return (
        <ReportLayout
            key={`${filters.from}-${filters.to}`}
            title="Payments"
            filters={filters}
            refreshedAt={refreshedAt}
            action={index.url()}
            description="Money received by method and currency on the selected business dates. Use the date fields for a custom range. Outstanding shows current folio balances, regardless of those dates; Show settled adds settled folios departing in them."
            fields={
                <>
                    <input type="hidden" name="view" value={filters.view} />
                    <input type="hidden" name="period" value="custom" />
                    {filters.view === 'outstanding' && (
                        <ShowSettledField checked={filters.settled} />
                    )}
                </>
            }
        >
            <nav aria-label="Payment period" className="flex flex-wrap gap-2">
                {Object.entries(periodLabels)
                    .filter(([period]) => period !== 'custom')
                    .map(([period, label]) => (
                        <Button
                            key={period}
                            variant={
                                filters.period === period
                                    ? 'default'
                                    : 'outline'
                            }
                            size="sm"
                            asChild
                        >
                            <Link
                                href={index({
                                    query: { period, view: filters.view },
                                })}
                                aria-current={
                                    filters.period === period
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {label}
                            </Link>
                        </Button>
                    ))}
                {filters.period === 'custom' && (
                    <span className="self-center text-sm text-muted-foreground">
                        Custom range
                    </span>
                )}
            </nav>
            <nav aria-label="Payment views" className="flex gap-2">
                {['received', 'outstanding'].map((view) => (
                    <Button
                        key={view}
                        variant={filters.view === view ? 'default' : 'outline'}
                        asChild
                    >
                        <Link
                            href={index({ query: { ...filters, view } })}
                            aria-current={
                                filters.view === view ? 'page' : undefined
                            }
                        >
                            {view === 'received' ? 'Received' : 'Outstanding'}
                        </Link>
                    </Button>
                ))}
            </nav>
            <section
                aria-labelledby="payment-view-title"
                className="grid gap-4"
            >
                <h2 id="payment-view-title" className="text-lg font-semibold">
                    {filters.view === 'received' ? 'Received' : 'Outstanding'}
                </h2>
                {received && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Net received is payments less refunds plus
                            reversals, counted on the business date each was
                            posted. Currencies are never combined.
                        </p>
                        <CollectionsTable received={received} />
                    </>
                )}
                {payments && payments.total > 0 && (
                    <>
                        <h3 className="font-semibold">Individual payments</h3>
                        <PaymentsTable payments={payments} />
                    </>
                )}
                {folios && <FolioBalances folios={folios} />}
            </section>
        </ReportLayout>
    );
}

Payments.layout = { breadcrumbs: [{ title: 'Payments', href: index() }] };

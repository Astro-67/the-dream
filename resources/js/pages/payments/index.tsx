import { Link } from '@inertiajs/react';
import { Banknote, CreditCard, RotateCcw, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Identifier } from '@/components/ui/identifier';
import { Tag } from '@/components/ui/status-chip';
import { formatMoney } from '@/lib/utils';
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
    reservation?: string;
};

export default function Payments({
    filters,
    received,
    payments,
    folios,
    refreshedAt,
    cashierShiftHistoryUrl,
    reservationNumber,
}: {
    filters: Filters;
    received: Received | null;
    payments: Paginated<PaymentRow> | null;
    folios: PropertyFinance['folios'] | null;
    refreshedAt: string;
    cashierShiftHistoryUrl: string | null;
    reservationNumber: string | null;
}) {
    return (
        <ReportLayout
            key={`${filters.from}-${filters.to}-${filters.reservation ?? ''}`}
            title="Payments"
            filters={filters}
            refreshedAt={refreshedAt}
            action={index.url()}
            filterStyle="panel"
            description="Money received by method and currency on the selected business dates. Use the date fields for a custom range. Outstanding shows current folio balances, regardless of those dates; Show settled adds settled folios departing in them."
            fields={
                <>
                    <input type="hidden" name="view" value={filters.view} />
                    <input type="hidden" name="period" value="custom" />
                    {filters.reservation && (
                        <input
                            type="hidden"
                            name="reservation"
                            value={filters.reservation}
                        />
                    )}
                    {filters.view === 'outstanding' && (
                        <ShowSettledField checked={filters.settled} />
                    )}
                </>
            }
        >
            {reservationNumber && (
                <div className="flex flex-wrap items-center gap-3 border border-primary/20 bg-primary-tint p-3">
                    <Tag active>
                        Reservation <Identifier value={reservationNumber} />
                    </Tag>
                    <Link
                        href={index({
                            query: { ...filters, reservation: undefined },
                        })}
                        className="text-primary underline underline-offset-4"
                    >
                        Clear reservation filter
                    </Link>
                </div>
            )}
            <div className="grid min-w-0 gap-4 border border-border bg-card p-3 shadow-panel md:p-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                <div className="grid min-w-0 gap-2">
                    <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                        Payment period
                    </p>
                    <nav
                        aria-label="Payment period"
                        className="flex flex-wrap gap-2"
                    >
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
                                            query: {
                                                period,
                                                view: filters.view,
                                                reservation:
                                                    filters.reservation,
                                            },
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
                            <Tag active>Custom range</Tag>
                        )}
                    </nav>
                </div>
                <div className="grid min-w-0 content-start gap-2 lg:justify-items-end">
                    <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                        Payment view
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <nav
                            aria-label="Payment views"
                            className="flex flex-wrap gap-2"
                        >
                            {(['received', 'outstanding'] as const).map(
                                (view) => (
                                    <Button
                                        key={view}
                                        variant={
                                            filters.view === view
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={index({
                                                query: { ...filters, view },
                                            })}
                                            aria-current={
                                                filters.view === view
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            {view === 'received'
                                                ? 'Received'
                                                : 'Outstanding'}
                                        </Link>
                                    </Button>
                                ),
                            )}
                        </nav>
                        {cashierShiftHistoryUrl && (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={cashierShiftHistoryUrl}>
                                    Cashier shifts
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
            <section
                aria-labelledby="payment-view-title"
                className="grid min-w-0 gap-5"
            >
                <div className="flex flex-wrap items-end justify-between gap-3 border-b border-border pb-3">
                    <div>
                        <h2
                            id="payment-view-title"
                            className="text-xl font-semibold"
                        >
                            {filters.view === 'received'
                                ? 'Payment overview'
                                : 'Outstanding balances'}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {filters.view === 'received'
                                ? 'Track collections by currency and payment method.'
                                : 'Review open folio balances and departed stays.'}
                        </p>
                    </div>
                    {filters.view === 'received' && (
                        <Wallet
                            aria-hidden="true"
                            className="size-6 text-primary"
                        />
                    )}
                </div>
                {received && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Net received is payments less refunds plus
                            reversals, counted on the business date each was
                            posted. Currencies are never combined.
                        </p>
                        {received.totals.length > 0 && (
                            <div className="grid min-w-0 gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                                {received.totals.map((total) => (
                                    <Card
                                        key={total.currency}
                                        className="min-w-0 gap-0 overflow-hidden border-t-4 border-t-primary py-0 shadow-panel"
                                    >
                                        <CardHeader className="flex-row items-center justify-between gap-3 border-b border-border bg-muted/30 px-4 py-3">
                                            <div className="min-w-0">
                                                <CardTitle className="text-xs tracking-[0.08em] text-muted-foreground uppercase">
                                                    Net received ·{' '}
                                                    {total.currency}
                                                </CardTitle>
                                                <p className="mt-1 truncate text-2xl font-semibold tabular-nums">
                                                    {formatMoney(
                                                        total.net,
                                                        total.currency,
                                                    )}
                                                </p>
                                            </div>
                                            <Banknote
                                                aria-hidden="true"
                                                className="size-6 shrink-0 text-primary"
                                            />
                                        </CardHeader>
                                        <CardContent className="grid grid-cols-3 gap-2 px-4 py-3">
                                            {[
                                                {
                                                    label: 'Payments',
                                                    amount: total.payments,
                                                    icon: CreditCard,
                                                },
                                                {
                                                    label: 'Refunds',
                                                    amount: total.refunds,
                                                    icon: RotateCcw,
                                                },
                                                {
                                                    label: 'Reversals',
                                                    amount: total.reversals,
                                                    icon: RotateCcw,
                                                },
                                            ].map(
                                                ({
                                                    label,
                                                    amount,
                                                    icon: Icon,
                                                }) => (
                                                    <div
                                                        key={label}
                                                        className="min-w-0"
                                                    >
                                                        <p className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                                            <Icon
                                                                aria-hidden="true"
                                                                className="size-3.5 shrink-0"
                                                            />
                                                            <span className="truncate">
                                                                {label}
                                                            </span>
                                                        </p>
                                                        <p className="mt-1 truncate text-xs font-semibold tabular-nums">
                                                            {formatMoney(
                                                                amount,
                                                                total.currency,
                                                            )}
                                                        </p>
                                                    </div>
                                                ),
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                        <section
                            aria-labelledby="collections-title"
                            className="grid min-w-0 gap-3"
                        >
                            <div>
                                <h3
                                    id="collections-title"
                                    className="font-semibold"
                                >
                                    Collections by method
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Payments, refunds, reversals and net by
                                    currency.
                                </p>
                            </div>
                            <CollectionsTable received={received} />
                        </section>
                    </>
                )}
                {payments && payments.total > 0 && (
                    <section
                        aria-labelledby="individual-payments-title"
                        className="grid min-w-0 gap-3"
                    >
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <h3
                                    id="individual-payments-title"
                                    className="font-semibold"
                                >
                                    Individual payments
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Receipt-level activity for this period.
                                </p>
                            </div>
                            <span className="text-sm font-medium text-muted-foreground tabular-nums">
                                {payments.total} records
                            </span>
                        </div>
                        <PaymentsTable payments={payments} />
                    </section>
                )}
                {folios && (
                    <section
                        aria-labelledby="open-folios-title"
                        className="grid min-w-0 gap-3"
                    >
                        <div>
                            <h3
                                id="open-folios-title"
                                className="font-semibold"
                            >
                                Open folios
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Current balances, kept separate by currency.
                            </p>
                        </div>
                        <FolioBalances folios={folios} />
                    </section>
                )}
            </section>
        </ReportLayout>
    );
}

Payments.layout = { breadcrumbs: [{ title: 'Payments', href: index() }] };

import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import KpiReport from '@/features/reports/kpi-report';
import type { Kpis } from '@/features/reports/kpi-report';
import PropertyOperations from '@/features/reports/property-operations';
import type { PropertyOperationsData } from '@/features/reports/property-operations';
import ReportLayout from '@/features/reports/report-layout';
import type { ReportFilters } from '@/features/reports/report-layout';
import { formatMoney, formatTime } from '@/lib/utils';
import { group } from '@/routes/reports';

type Amounts = {
    currency: string;
    payments: number;
    refunds: number;
    reversals: number;
    net: number;
};

type GroupFinance = {
    openAfterDeparture: { currency: string; folios: number; balance: number }[];
    payments: {
        rows: (Amounts & {
            propertyId: string;
            propertyName: string;
            timezone: string;
            method: string;
            shiftId: string | null;
            cashier: string | null;
            shiftOpenedAt: string | null;
        })[];
        totals: Amounts[];
    };
};

const methods: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    card: 'Card',
    channel_collected: 'Channel collected',
};

const openedOn = (instant: string, timeZone: string) =>
    new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone,
    }).format(new Date(instant));

const AmountCell = ({
    amount,
    currency,
}: {
    amount: number;
    currency: string;
}) => (
    <TableCell numeric className="whitespace-nowrap">
        {formatMoney(amount, currency)}
    </TableCell>
);

export default function GroupReport({
    filters,
    available,
    properties,
    kpis,
    operations,
    finance,
    refreshedAt,
}: {
    filters: ReportFilters & { properties: string[] };
    available: { id: string; name: string }[];
    properties: {
        id: string;
        name: string;
        currency: string;
        timezone: string;
        unsettledFolios: number;
    }[];
    kpis: Kpis;
    operations: PropertyOperationsData;
    finance: GroupFinance;
    refreshedAt: string;
}) {
    const chosen =
        filters.properties.length === 0
            ? available.map((property) => property.id)
            : filters.properties;

    return (
        <ReportLayout
            title="Group reports"
            filters={filters}
            refreshedAt={refreshedAt}
            action={group.url()}
            description="Read-only reports across the properties you may view reports for. Demo properties are never included. Dates include both the first and last night."
            fields={
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Properties
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        Leave every property unticked to include them all.
                    </p>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        {available.map((property) => (
                            <div
                                key={property.id}
                                className="flex min-h-11 items-center gap-2 md:min-h-9"
                            >
                                <Checkbox
                                    id={`property-${property.id}`}
                                    name="properties[]"
                                    value={property.id}
                                    defaultChecked={chosen.includes(
                                        property.id,
                                    )}
                                />
                                <Label htmlFor={`property-${property.id}`}>
                                    {property.name}
                                </Label>
                            </div>
                        ))}
                    </div>
                </fieldset>
            }
        >
            <section aria-labelledby="properties-title" className="grid gap-4">
                <div>
                    <h2 id="properties-title" className="text-lg font-semibold">
                        Included properties
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Each property reports on its own business dates and in
                        its own timezone. Unsettled folios are every folio with
                        a balance that is not zero, at the refresh time.
                    </p>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Property</TableHead>
                            <TableHead>Currency</TableHead>
                            <TableHead>Timezone</TableHead>
                            <TableHead numeric>Unsettled folios</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {properties.map((property) => (
                            <TableRow key={property.id}>
                                <TableCell>{property.name}</TableCell>
                                <TableCell>{property.currency}</TableCell>
                                <TableCell>{property.timezone}</TableCell>
                                <TableCell numeric>
                                    {property.unsettledFolios}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
                <p className="text-sm text-muted-foreground">
                    Switch to a property to see its folios and reservations.
                </p>
            </section>
            <KpiReport kpis={kpis} group />
            <PropertyOperations operations={operations} group />
            <section aria-labelledby="balances-title" className="grid gap-4">
                <div>
                    <h2 id="balances-title" className="text-lg font-semibold">
                        Open balances after departure
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        A snapshot at the refresh time, not limited to the
                        selected dates: departed guests&apos; folios with a
                        balance that is not zero, totalled in each currency
                        across every included property.
                    </p>
                </div>
                {finance.openAfterDeparture.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No departed guest has an open balance.
                    </p>
                ) : (
                    <dl className="flex flex-wrap gap-x-12 gap-y-4 rounded-xl border bg-card p-4">
                        {finance.openAfterDeparture.map((row) => (
                            <div key={row.currency}>
                                <dt className="text-sm text-muted-foreground">
                                    {row.currency}, {row.folios}{' '}
                                    {row.folios === 1 ? 'folio' : 'folios'}
                                </dt>
                                <dd className="text-2xl font-semibold tabular-nums">
                                    {formatMoney(row.balance, row.currency)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </section>
            <section aria-labelledby="payments-title" className="grid gap-4">
                <div>
                    <h2 id="payments-title" className="text-lg font-semibold">
                        Payments
                    </h2>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Money received on each property&apos;s selected business
                        dates, by property, method, currency and cashier shift.
                        Refunds are money paid back. A reversal cancels an
                        earlier payment or refund and counts on the business
                        date it was posted. Net is payments less refunds plus
                        reversals. Shift times are in each property&apos;s
                        timezone.
                    </p>
                </div>
                {finance.payments.rows.length === 0 ? (
                    <EmptyState message="No payments, refunds or reversals on these dates." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Property</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Cashier shift</TableHead>
                                <TableHead>Currency</TableHead>
                                <TableHead numeric>Payments</TableHead>
                                <TableHead numeric>Refunds</TableHead>
                                <TableHead numeric>Reversals</TableHead>
                                <TableHead numeric>Net</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {finance.payments.rows.map((row) => (
                                <TableRow
                                    key={`${row.propertyId}-${row.method}-${row.currency}-${row.shiftId}`}
                                >
                                    <TableCell>{row.propertyName}</TableCell>
                                    <TableCell>
                                        {methods[row.method] ?? row.method}
                                    </TableCell>
                                    <TableCell>
                                        {row.shiftOpenedAt === null
                                            ? 'No shift'
                                            : `${row.cashier}, opened ${openedOn(row.shiftOpenedAt, row.timezone)} ${formatTime(row.shiftOpenedAt, row.timezone)}`}
                                    </TableCell>
                                    <TableCell>{row.currency}</TableCell>
                                    <AmountCell
                                        amount={row.payments}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.refunds}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.reversals}
                                        currency={row.currency}
                                    />
                                    <AmountCell
                                        amount={row.net}
                                        currency={row.currency}
                                    />
                                </TableRow>
                            ))}
                            {finance.payments.totals.map((total) => (
                                <TableRow
                                    key={`total-${total.currency}`}
                                    className="font-medium"
                                >
                                    <TableCell colSpan={3}>
                                        Group total in {total.currency}
                                    </TableCell>
                                    <TableCell>{total.currency}</TableCell>
                                    <AmountCell
                                        amount={total.payments}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.refunds}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.reversals}
                                        currency={total.currency}
                                    />
                                    <AmountCell
                                        amount={total.net}
                                        currency={total.currency}
                                    />
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>
        </ReportLayout>
    );
}

import { Form, Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { formatMoney, formatTime } from '@/lib/utils';
import { close, index, store } from '@/routes/cashier-shifts';
import type { Paginated } from '@/types';

type CurrencyLine = {
    currency: string;
    float: number;
    expected: number;
    counted: number | null;
    variance: number | null;
};

type Shift = {
    id: string;
    cashier: string | null;
    status: 'open' | 'closed';
    opened_at: string;
    closed_at: string | null;
    variance_reason: string | null;
    currencies: CurrencyLine[];
};

function When({ instant, timezone }: { instant: string; timezone: string }) {
    return (
        <>
            {formatDate(instant, timezone)} {formatTime(instant, timezone)}
        </>
    );
}

function OpenShiftForm({ currencies }: { currencies: string[] }) {
    return (
        <Form {...store.form()} className="flex max-w-[720px] flex-col gap-3">
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <h2 className="font-semibold">Open a shift</h2>
                    <p className="text-sm text-muted-foreground">
                        Count the float you start with in each currency.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {currencies.map((currency) => (
                            <div key={currency} className="space-y-2">
                                <Label htmlFor={`float-${currency}`}>
                                    Float in {currency}
                                </Label>
                                <MoneyInput
                                    id={`float-${currency}`}
                                    name={`floats[${currency}]`}
                                    currency={currency}
                                    min={0}
                                    defaultValue={0}
                                    required
                                />
                                <InputError
                                    message={errors[`floats.${currency}`]}
                                />
                            </div>
                        ))}
                    </div>
                    <InputError message={errors.shift ?? errors.floats} />
                    <div>
                        <Button disabled={processing}>
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Open shift
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function CloseShiftForm({ shift }: { shift: Shift }) {
    return (
        <Form
            {...close.form(shift.id)}
            className="flex max-w-[720px] flex-col gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <h3 className="font-semibold">Close the shift</h3>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {shift.currencies.map((line) => (
                            <div key={line.currency} className="space-y-2">
                                <Label htmlFor={`counted-${line.currency}`}>
                                    Counted {line.currency}
                                </Label>
                                <MoneyInput
                                    id={`counted-${line.currency}`}
                                    name={`counted[${line.currency}]`}
                                    currency={line.currency}
                                    min={0}
                                    required
                                />
                                <InputError
                                    message={errors[`counted.${line.currency}`]}
                                />
                            </div>
                        ))}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="variance_reason">
                            Reason for any difference
                        </Label>
                        <Input
                            id="variance_reason"
                            name="variance_reason"
                            maxLength={500}
                            aria-invalid={Boolean(errors.variance_reason)}
                        />
                        <InputError
                            message={
                                errors.variance_reason ??
                                errors.counted ??
                                errors.shift
                            }
                        />
                    </div>
                    <div>
                        <Button disabled={processing}>
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Close shift
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Amount({
    minor,
    currency,
}: {
    minor: number | null;
    currency: string;
}) {
    return minor === null ? <>—</> : <>{formatMoney(minor, currency)}</>;
}

export default function CashierShifts({
    canOpenShift,
    openShift,
    shifts,
    currencies,
}: {
    canOpenShift: boolean;
    openShift: Shift | null;
    shifts: Paginated<Shift> | null;
    currencies: string[];
}) {
    const { tenant } = usePage().props;
    const timezone = tenant.property?.timezone ?? 'UTC';

    return (
        <>
            <Head title="Cashier shifts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Cashier shifts"
                    description={`Cash taken and paid out is counted against the cashier's open shift. Times in ${timezone}.`}
                />
                {canOpenShift &&
                    (openShift ? (
                        <section className="flex flex-col gap-4">
                            <h2 className="font-semibold">
                                Your open shift, since{' '}
                                <When
                                    instant={openShift.opened_at}
                                    timezone={timezone}
                                />
                            </h2>
                            <ul className="flex flex-wrap gap-6 text-sm">
                                {openShift.currencies.map((line) => (
                                    <li key={line.currency}>
                                        <span className="text-muted-foreground">
                                            Expected {line.currency}{' '}
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                line.expected,
                                                line.currency,
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <CloseShiftForm shift={openShift} />
                        </section>
                    ) : (
                        <OpenShiftForm currencies={currencies} />
                    ))}
                {shifts &&
                    (shifts.data.length === 0 ? (
                        <EmptyState message="No cashier shifts yet." />
                    ) : (
                        <div>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Cashier</TableHead>
                                        <TableHead>Opened</TableHead>
                                        <TableHead>Closed</TableHead>
                                        <TableHead>Currency</TableHead>
                                        <TableHead numeric>Float</TableHead>
                                        <TableHead numeric>Expected</TableHead>
                                        <TableHead numeric>Counted</TableHead>
                                        <TableHead numeric>Variance</TableHead>
                                        <TableHead>Reason</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shifts.data.flatMap((shift) =>
                                        shift.currencies.map((line, i) => (
                                            <TableRow
                                                key={`${shift.id}-${line.currency}`}
                                            >
                                                <TableCell>
                                                    {i === 0 && shift.cashier}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {i === 0 && (
                                                        <When
                                                            instant={
                                                                shift.opened_at
                                                            }
                                                            timezone={timezone}
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {i === 0 &&
                                                        (shift.closed_at ? (
                                                            <When
                                                                instant={
                                                                    shift.closed_at
                                                                }
                                                                timezone={
                                                                    timezone
                                                                }
                                                            />
                                                        ) : (
                                                            <StatusChip tone="info">
                                                                Open
                                                            </StatusChip>
                                                        ))}
                                                </TableCell>
                                                <TableCell>
                                                    {line.currency}
                                                </TableCell>
                                                <TableCell numeric>
                                                    <Amount
                                                        minor={line.float}
                                                        currency={line.currency}
                                                    />
                                                </TableCell>
                                                <TableCell numeric>
                                                    <Amount
                                                        minor={line.expected}
                                                        currency={line.currency}
                                                    />
                                                </TableCell>
                                                <TableCell numeric>
                                                    <Amount
                                                        minor={line.counted}
                                                        currency={line.currency}
                                                    />
                                                </TableCell>
                                                <TableCell numeric>
                                                    {line.variance !== null &&
                                                    line.variance !== 0 ? (
                                                        <StatusChip
                                                            tone={
                                                                line.variance <
                                                                0
                                                                    ? 'error'
                                                                    : 'warning'
                                                            }
                                                        >
                                                            {line.variance < 0
                                                                ? 'Short '
                                                                : 'Over '}
                                                            {formatMoney(
                                                                line.variance,
                                                                line.currency,
                                                            )}
                                                        </StatusChip>
                                                    ) : (
                                                        <Amount
                                                            minor={
                                                                line.variance
                                                            }
                                                            currency={
                                                                line.currency
                                                            }
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {i === 0 &&
                                                        shift.variance_reason}
                                                </TableCell>
                                            </TableRow>
                                        )),
                                    )}
                                </TableBody>
                            </Table>
                            <Pagination page={shifts} />
                        </div>
                    ))}
            </div>
        </>
    );
}

CashierShifts.layout = {
    breadcrumbs: [{ title: 'Cashier shifts', href: index() }],
};

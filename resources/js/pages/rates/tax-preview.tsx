import { Table, TableBody, TableRow, TableCell } from '@/components/ui/table';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, ruleSize } from '@/lib/utils';
import { index, preview } from '@/routes/tax-rules';

type Line = {
    name: string;
    kind: string;
    inclusive: boolean;
    rate: number | null;
    per_person_night: number | null;
    amount: number;
};
type Breakdown = {
    currency: string;
    net: number;
    tax: number;
    gross: number;
    lines: Line[];
    unavailable: string[];
};
type Charge = Partial<
    Record<'amount' | 'currency' | 'date' | 'guests' | 'charge', string>
>;

export default function TaxPreview({
    charge,
    currencies,
    breakdown,
    unpreviewable,
}: {
    charge: Charge;
    currencies: string[];
    breakdown: Breakdown | null;
    unpreviewable: string | null;
}) {
    const [currency, setCurrency] = useState(charge.currency ?? currencies[0]);
    const money = (amount: number) =>
        formatMoney(amount, breakdown?.currency ?? '');

    return (
        <>
            <Head title="Tax preview" />
            <div className="flex min-w-0 flex-col gap-6 md:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b pb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Tax preview
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={index()}>Tax rules</Link>
                    </Button>
                </header>
                <Form
                    {...preview.form()}
                    className="flex w-full max-w-[720px] min-w-0 flex-col gap-6 border-b pb-6"
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="amount">
                                        Price in {currency}
                                    </Label>
                                    <MoneyInput
                                        id="amount"
                                        name="amount"
                                        currency={currency}
                                        min={0}
                                        required
                                        defaultValue={charge.amount}
                                        className="tabular-nums"
                                        aria-invalid={!!errors.amount}
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="currency">Currency</Label>
                                    <NativeSelect
                                        id="currency"
                                        name="currency"
                                        defaultValue={charge.currency}
                                        onChange={(event) =>
                                            setCurrency(event.target.value)
                                        }
                                        aria-invalid={!!errors.currency}
                                    >
                                        {currencies.map((currency) => (
                                            <option key={currency}>
                                                {currency}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.currency} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="date">Date</Label>
                                    <Input
                                        id="date"
                                        name="date"
                                        type="date"
                                        required
                                        defaultValue={charge.date}
                                        aria-invalid={!!errors.date}
                                    />
                                    <InputError message={errors.date} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="guests">Guests</Label>
                                    <Input
                                        id="guests"
                                        name="guests"
                                        type="number"
                                        min={1}
                                        max={40}
                                        required
                                        defaultValue={charge.guests ?? '2'}
                                        aria-invalid={!!errors.guests}
                                    />
                                    <InputError message={errors.guests} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="charge">Charge</Label>
                                    <NativeSelect
                                        id="charge"
                                        name="charge"
                                        defaultValue={charge.charge}
                                        aria-invalid={!!errors.charge}
                                    >
                                        <option value="room">
                                            Room charge
                                        </option>
                                        <option value="extra">Extra</option>
                                    </NativeSelect>
                                    <InputError message={errors.charge} />
                                </div>
                            </div>
                            <Button
                                type="submit"
                                className="self-end"
                                disabled={processing}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                Show breakdown
                            </Button>
                        </>
                    )}
                </Form>
                {unpreviewable && (
                    <p role="status" className="font-medium">
                        Cannot preview this charge: {unpreviewable}
                    </p>
                )}
                {breakdown && (
                    <section
                        className="w-full max-w-[720px] min-w-0 space-y-4"
                        aria-labelledby="tax-breakdown"
                    >
                        <h2
                            id="tax-breakdown"
                            className="text-lg font-semibold tracking-tight"
                        >
                            Tax breakdown
                        </h2>
                        <Table>
                            <TableBody>
                                <TableRow>
                                    <TableCell>Net</TableCell>
                                    <TableCell />
                                    <TableCell numeric>
                                        {money(breakdown.net)}
                                    </TableCell>
                                </TableRow>
                                {breakdown.lines.map((line) => (
                                    <TableRow key={line.name}>
                                        <TableCell>{line.name}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {ruleSize(line, breakdown.currency)}
                                            ,{' '}
                                            {line.inclusive
                                                ? 'included'
                                                : 'added'}
                                        </TableCell>
                                        <TableCell numeric>
                                            {money(line.amount)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {breakdown.unavailable.map((name) => (
                                    <TableRow key={name}>
                                        <TableCell>{name}</TableCell>
                                        <TableCell
                                            className="text-muted-foreground"
                                            colSpan={2}
                                        >
                                            Not available in this currency
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {breakdown.lines.length === 0 &&
                                    breakdown.unavailable.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                className="text-muted-foreground"
                                                colSpan={3}
                                            >
                                                No tax rule applies to this
                                                charge on this date.
                                            </TableCell>
                                        </TableRow>
                                    )}
                            </TableBody>
                            <tfoot>
                                <TableRow className="font-semibold">
                                    <TableCell colSpan={2}>
                                        Total charged
                                    </TableCell>
                                    <TableCell numeric>
                                        {money(breakdown.gross)}
                                    </TableCell>
                                </TableRow>
                            </tfoot>
                        </Table>
                    </section>
                )}
            </div>
        </>
    );
}

TaxPreview.layout = {
    breadcrumbs: [
        { title: 'Tax rules', href: index() },
        { title: 'Tax preview', href: '#' },
    ],
};

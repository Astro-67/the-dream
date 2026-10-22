import { Table, TableBody, TableRow, TableCell } from '@/components/ui/table';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Label } from '@/components/ui/label';
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
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Tax preview
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={index()}>Tax rules</Link>
                    </Button>
                </header>
                <Form
                    {...preview.form()}
                    className="flex max-w-[720px] flex-col gap-6"
                >
                    {({ errors }) => (
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
                                    <select
                                        id="currency"
                                        name="currency"
                                        defaultValue={charge.currency}
                                        onChange={(event) =>
                                            setCurrency(event.target.value)
                                        }
                                        className="h-9 w-full rounded-md border border-input bg-background px-3"
                                    >
                                        {currencies.map((currency) => (
                                            <option key={currency}>
                                                {currency}
                                            </option>
                                        ))}
                                    </select>
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
                                    <select
                                        id="charge"
                                        name="charge"
                                        defaultValue={charge.charge}
                                        className="h-9 w-full rounded-md border border-input bg-background px-3"
                                    >
                                        <option value="room">
                                            Room charge
                                        </option>
                                        <option value="extra">Extra</option>
                                    </select>
                                    <InputError message={errors.charge} />
                                </div>
                            </div>
                            <Button type="submit" className="self-end">
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
                    <div className="max-w-[720px]">
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
                    </div>
                )}
            </div>
        </>
    );
}

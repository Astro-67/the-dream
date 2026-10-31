import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { NativeSelect } from '@/components/ui/select';

export function AdjustmentFields({
    currency,
    adjustmentKind = 'percent',
    adjustment,
    errors,
}: {
    currency: string;
    adjustmentKind?: string | null;
    adjustment?: number | null;
    errors: Partial<Record<string, string>>;
}) {
    const [kind, setKind] = useState(adjustmentKind ?? 'percent');

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
                <Label htmlFor="adjustment_kind">Adjustment</Label>
                <NativeSelect
                    id="adjustment_kind"
                    name="adjustment_kind"
                    value={kind}
                    onChange={(event) => setKind(event.target.value)}
                    aria-invalid={!!errors.adjustment_kind}
                >
                    <option value="percent">Percentage of every amount</option>
                    <option value="amount">
                        Amount on the base occupancy price
                    </option>
                </NativeSelect>
                <InputError message={errors.adjustment_kind} />
            </div>
            <div className="space-y-2">
                <Label htmlFor="adjustment">
                    {kind === 'percent'
                        ? 'Percent (−10 is 10% off)'
                        : `Amount in ${currency} (negative is off)`}
                </Label>
                {kind === 'percent' ? (
                    <Input
                        id="adjustment"
                        name="adjustment"
                        type="number"
                        step={1}
                        required
                        defaultValue={adjustment ?? ''}
                        className="tabular-nums"
                        aria-invalid={!!errors.adjustment}
                    />
                ) : (
                    <MoneyInput
                        id="adjustment"
                        name="adjustment"
                        currency={currency}
                        required
                        defaultValue={adjustment ?? ''}
                        className="tabular-nums"
                        aria-invalid={!!errors.adjustment}
                    />
                )}
                <InputError message={errors.adjustment} />
            </div>
        </div>
    );
}

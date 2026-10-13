import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';

type Charge = 'percent' | 'first_night' | 'full_stay';

export type CancellationPolicy = {
    cancellation_free_days: number | null;
    cancellation_charge: Charge | null;
    cancellation_percent: number | null;
    no_show_charge: Charge | null;
    no_show_percent: number | null;
};

const charges: Record<Charge, string> = {
    percent: 'A percentage of the stay',
    first_night: 'The first night',
    full_stay: 'The full stay',
};

/** The rate plan's cancellation policy and no-show fee. */
export function CancellationPolicyFields({
    plan,
    errors,
}: {
    plan: CancellationPolicy;
    errors: Partial<Record<string, string>>;
}) {
    const [cancellation, setCancellation] = useState(
        plan.cancellation_charge ?? '',
    );
    const [noShow, setNoShow] = useState(plan.no_show_charge ?? '');

    return (
        <fieldset className="space-y-4">
            <legend className="mb-2 text-sm font-medium">
                Cancellation policy
            </legend>
            <div className="space-y-2">
                <Label htmlFor="cancellation_free_days">
                    Free until this many days before arrival
                </Label>
                <Input
                    id="cancellation_free_days"
                    name="cancellation_free_days"
                    type="number"
                    min={0}
                    max={365}
                    defaultValue={plan.cancellation_free_days ?? ''}
                    aria-invalid={Boolean(errors.cancellation_free_days)}
                />
                <p className="text-sm text-muted-foreground">
                    Leave empty if cancelling is never free.
                </p>
                <InputError message={errors.cancellation_free_days} />
            </div>
            <ChargeFields
                name="cancellation"
                label="Then charge"
                value={cancellation}
                onChange={setCancellation}
                percent={plan.cancellation_percent}
                errors={errors}
            />
            <ChargeFields
                name="no_show"
                label="No-show fee"
                value={noShow}
                onChange={setNoShow}
                percent={plan.no_show_percent}
                errors={errors}
            />
        </fieldset>
    );
}

function ChargeFields({
    name,
    label,
    value,
    onChange,
    percent,
    errors,
}: {
    name: 'cancellation' | 'no_show';
    label: string;
    value: string;
    onChange: (value: string) => void;
    percent: number | null;
    errors: Partial<Record<string, string>>;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-2">
                <Label htmlFor={`${name}_charge`}>{label}</Label>
                <NativeSelect
                    id={`${name}_charge`}
                    name={`${name}_charge`}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={Boolean(errors[`${name}_charge`])}
                >
                    <option value="">Nothing</option>
                    {Object.entries(charges).map(([charge, text]) => (
                        <option key={charge} value={charge}>
                            {text}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors[`${name}_charge`]} />
            </div>
            {value === 'percent' && (
                <div className="space-y-2">
                    <Label htmlFor={`${name}_percent`}>Percentage</Label>
                    <Input
                        id={`${name}_percent`}
                        name={`${name}_percent`}
                        type="number"
                        min={1}
                        max={100}
                        required
                        defaultValue={percent ?? ''}
                        aria-invalid={Boolean(errors[`${name}_percent`])}
                    />
                    <InputError message={errors[`${name}_percent`]} />
                </div>
            )}
        </div>
    );
}

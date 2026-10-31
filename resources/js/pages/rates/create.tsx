import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AdjustmentFields } from '@/features/rates/adjustment-fields';
import { CancellationPolicyFields } from '@/features/rates/cancellation-policy-fields';
import type { CancellationPolicy } from '@/features/rates/cancellation-policy-fields';
import { index, store } from '@/routes/rate-plans';

export default function CreateRatePlan({
    roomTypes,
    currencies,
    basePlans,
    policyDefaults,
}: {
    roomTypes: { id: string; name: string; code: string }[];
    currencies: string[];
    basePlans: { id: string; name: string; code: string; currency: string }[];
    policyDefaults: CancellationPolicy;
}) {
    const [baseId, setBaseId] = useState('');
    const base = basePlans.find((plan) => plan.id === baseId);
    return (
        <>
            <Head title="Add rate plan" />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Add rate plan
                </h1>
                <Form
                    {...store.form()}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={255}
                                    aria-invalid={!!errors.name}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    name="code"
                                    required
                                    maxLength={16}
                                    className="font-mono"
                                    aria-invalid={!!errors.code}
                                />
                                <InputError message={errors.code} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="base_rate_plan_id">
                                    Prices
                                </Label>
                                <select
                                    id="base_rate_plan_id"
                                    name="base_rate_plan_id"
                                    value={baseId}
                                    onChange={(e) => setBaseId(e.target.value)}
                                    className="h-9 w-full rounded-md border border-input bg-background px-3"
                                    aria-invalid={!!errors.base_rate_plan_id}
                                >
                                    <option value="">Own prices</option>
                                    {basePlans.map((plan) => (
                                        <option key={plan.id} value={plan.id}>
                                            Derived from {plan.code} ·{' '}
                                            {plan.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.base_rate_plan_id}
                                />
                            </div>
                            {base ? (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        Uses {base.name}'s currency (
                                        {base.currency}), room types and prices,
                                        adjusted. Changes to {base.name}'s
                                        prices apply here too.
                                    </p>
                                    <AdjustmentFields
                                        currency={base.currency}
                                        errors={errors}
                                    />
                                </>
                            ) : (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="currency">
                                            Currency
                                        </Label>
                                        <select
                                            id="currency"
                                            name="currency"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3"
                                            aria-invalid={!!errors.currency}
                                        >
                                            {currencies.map((currency) => (
                                                <option key={currency}>
                                                    {currency}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.currency} />
                                    </div>
                                    <fieldset className="space-y-3">
                                        <legend className="mb-2 text-sm font-medium">
                                            Room types
                                        </legend>
                                        {roomTypes.length === 0 && (
                                            <p className="text-sm text-muted-foreground">
                                                Add a room type before creating
                                                a rate plan.
                                            </p>
                                        )}
                                        {roomTypes.map((room) => (
                                            <label
                                                key={room.id}
                                                className="flex min-h-11 items-center gap-3"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="room_type_ids[]"
                                                    value={room.id}
                                                    className="size-4 accent-primary"
                                                />
                                                {room.code} · {room.name}
                                            </label>
                                        ))}
                                        <InputError
                                            message={errors.room_type_ids}
                                        />
                                    </fieldset>
                                </>
                            )}
                            <CancellationPolicyFields
                                plan={policyDefaults}
                                errors={errors}
                            />
                            <div className="flex flex-wrap justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={roomTypes.length === 0}
                                >
                                    {processing && <Spinner />}Add rate plan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateRatePlan.layout = {
    breadcrumbs: [
        { title: 'Rate plans', href: index() },
        { title: 'Add rate plan', href: '#' },
    ],
};

import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, update } from '@/routes/rate-plans';

export default function EditRatePlan({
    plan,
    roomTypes,
}: {
    plan: {
        id: string;
        name: string;
        code: string;
        currency: string;
        room_type_ids: string[];
        baseName: string | null;
    };
    roomTypes: { id: string; name: string; code: string }[];
}) {
    return (
        <>
            <Head title={`Edit ${plan.name}`} />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Edit {plan.name}
                </h1>
                <Form
                    {...update.form(plan.id)}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            {(['name', 'code'] as const).map((field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={field}>
                                        {field === 'name' ? 'Name' : 'Code'}
                                    </Label>
                                    <Input
                                        id={field}
                                        name={field}
                                        defaultValue={plan[field]}
                                        required
                                        maxLength={field === 'name' ? 255 : 16}
                                        aria-invalid={!!errors[field]}
                                    />
                                    <InputError message={errors[field]} />
                                </div>
                            ))}
                            <p className="text-sm text-muted-foreground">
                                Currency: {plan.currency}.{' '}
                                {plan.baseName &&
                                    `Room types and prices come from ${plan.baseName}.`}
                            </p>
                            {!plan.baseName && (
                                <fieldset className="space-y-3">
                                    <legend className="mb-2 text-sm font-medium">
                                        Room types
                                    </legend>
                                    {roomTypes.map((room) => (
                                        <label
                                            key={room.id}
                                            className="flex min-h-11 items-center gap-3"
                                        >
                                            <input
                                                type="checkbox"
                                                name="room_type_ids[]"
                                                value={room.id}
                                                defaultChecked={plan.room_type_ids.includes(
                                                    room.id,
                                                )}
                                                aria-invalid={
                                                    !!errors.room_type_ids
                                                }
                                                className="size-4 accent-primary"
                                            />
                                            {room.code} · {room.name}
                                        </label>
                                    ))}
                                    <InputError
                                        message={
                                            errors.room_type_ids ??
                                            Object.entries(errors).find(
                                                ([key]) =>
                                                    key.startsWith(
                                                        'room_type_ids.',
                                                    ),
                                            )?.[1]
                                        }
                                    />
                                </fieldset>
                            )}
                            <div className="flex flex-wrap justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

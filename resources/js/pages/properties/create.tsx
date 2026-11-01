import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { PropertyFormOptions } from '@/features/tenancy/property-fields';
import { PropertyFields } from '@/features/tenancy/property-fields';
import { create, index, store } from '@/routes/properties';
import type { Currency } from '@/types';

type Props = PropertyFormOptions & {
    defaults: { timezone: string; currency: Currency };
};

export default function CreateProperty({ defaults, ...options }: Props) {
    return (
        <>
            <Head title="Add property" />
            <div className="mx-auto flex w-full max-w-[720px] min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Add property
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Another property in your organization, with its own
                        local settings.
                    </p>
                </header>

                <Form
                    {...store.form()}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <InputError
                                message={errors.subscription ?? errors.property}
                                role="alert"
                            />
                            <PropertyFields
                                options={options}
                                values={{
                                    timezone: defaults.timezone,
                                    currency: defaults.currency,
                                }}
                                errors={errors}
                            />
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    data-test="create-property-button"
                                >
                                    {processing && <Spinner />}
                                    Add property
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateProperty.layout = {
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: 'Add property', href: create() },
    ],
};

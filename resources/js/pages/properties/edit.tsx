import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type {
    PropertyFormOptions,
    PropertyValues,
} from '@/features/tenancy/property-fields';
import { PropertyFields } from '@/features/tenancy/property-fields';
import { index, update } from '@/routes/properties';

type Props = PropertyFormOptions & { property: PropertyValues };

export default function EditProperty({ property, ...options }: Props) {
    return (
        <>
            <Head title={`Edit ${property.name}`} />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight break-words">
                        {property.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Property details, local settings and jurisdiction
                        profile.
                    </p>
                </header>

                <Form
                    {...update.form(property.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {/* This form is about this property, wherever the member is working. */}
                            <PropertyIntent propertyId={property.id} />
                            <InputError message={errors._property} />
                            <PropertyFields
                                options={options}
                                values={property}
                                errors={errors}
                            />
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    data-test="update-property-button"
                                >
                                    {processing && <Spinner />}
                                    Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

EditProperty.layout = {
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: 'Edit property', href: index() },
    ],
};

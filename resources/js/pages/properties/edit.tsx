import { PropertySettingsFields } from '@/features/tenancy/property-settings-fields';
import type { PropertySettings } from '@/features/tenancy/property-settings-fields';
import { Form, Head, Link } from '@inertiajs/react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
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

type Props = PropertyFormOptions & {
    property: PropertyValues &
        PropertySettings & {
            registration_fields?: string[];
            receipt_prefix: string;
            close_time: string;
            stayover_service_on_close: boolean;
        };
    registrationFieldOptions: string[];
};

const registrationFieldLabels: Record<string, string> = {
    type: 'Document type',
    number: 'Document number',
    nationality: 'Nationality',
    issuing_country: 'Issuing country',
    expiry: 'Expiry date',
};

export default function EditProperty({
    property,
    registrationFieldOptions,
    ...options
}: Props) {
    const configuresRegistration = property.registration_fields !== undefined;

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
                    transform={(data) =>
                        configuresRegistration
                            ? {
                                  ...data,
                                  payment_settings: {
                                      ...data.payment_settings,
                                      accepted_methods:
                                          data.payment_settings
                                              ?.accepted_methods ?? [],
                                  },
                                  registration_fields:
                                      data.registration_fields ?? [],
                              }
                            : {
                                  ...data,
                                  payment_settings: {
                                      ...data.payment_settings,
                                      accepted_methods:
                                          data.payment_settings
                                              ?.accepted_methods ?? [],
                                  },
                              }
                    }
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
                            <div className="grid gap-2">
                                <Label htmlFor="housekeeping_inspection_required">
                                    Room inspection
                                </Label>
                                <NativeSelect
                                    id="housekeeping_inspection_required"
                                    name="housekeeping_inspection_required"
                                    defaultValue={
                                        property.housekeeping_inspection_required
                                            ? '1'
                                            : '0'
                                    }
                                    aria-invalid={
                                        !!errors.housekeeping_inspection_required
                                    }
                                >
                                    <option value="0">
                                        No inspection required
                                    </option>
                                    <option value="1">
                                        Inspect cleaned rooms before they are
                                        ready
                                    </option>
                                </NativeSelect>
                                <InputError
                                    message={
                                        errors.housekeeping_inspection_required
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="stayover_service_on_close">
                                    Stayover service at night audit
                                </Label>
                                <NativeSelect
                                    id="stayover_service_on_close"
                                    name="stayover_service_on_close"
                                    defaultValue={
                                        property.stayover_service_on_close
                                            ? '1'
                                            : '0'
                                    }
                                    aria-invalid={
                                        !!errors.stayover_service_on_close
                                    }
                                >
                                    <option value="0">
                                        Mark stayover rooms by hand
                                    </option>
                                    <option value="1">
                                        Mark every occupied room for service
                                        when the day closes
                                    </option>
                                </NativeSelect>
                                <InputError
                                    message={errors.stayover_service_on_close}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="receipt_prefix">
                                    Receipt number prefix
                                </Label>
                                <Input
                                    id="receipt_prefix"
                                    name="receipt_prefix"
                                    defaultValue={property.receipt_prefix}
                                    maxLength={10}
                                    pattern="[A-Za-z0-9/\-]+"
                                    aria-invalid={!!errors.receipt_prefix}
                                />
                                <InputError message={errors.receipt_prefix} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="close_time">Close time</Label>
                                <Input
                                    id="close_time"
                                    name="close_time"
                                    type="time"
                                    defaultValue={property.close_time}
                                    aria-invalid={!!errors.close_time}
                                    aria-describedby="close_time_hint"
                                />
                                <p
                                    id="close_time_hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Night audit closes the business date at this
                                    local time.
                                </p>
                                <InputError message={errors.close_time} />
                            </div>
                            {configuresRegistration && (
                                <fieldset className="grid gap-3">
                                    <legend className="mb-1 text-sm font-medium">
                                        Guest registration at check-in
                                    </legend>
                                    <p className="text-sm text-muted-foreground">
                                        Identity document fields every guest
                                        needs before check-in.
                                    </p>
                                    {registrationFieldOptions.map((field) => (
                                        <div
                                            key={field}
                                            className="flex items-center gap-3"
                                        >
                                            <Checkbox
                                                id={`registration-${field}`}
                                                name="registration_fields[]"
                                                value={field}
                                                defaultChecked={property.registration_fields?.includes(
                                                    field,
                                                )}
                                            />
                                            <Label
                                                htmlFor={`registration-${field}`}
                                            >
                                                {registrationFieldLabels[
                                                    field
                                                ] ?? field}
                                            </Label>
                                        </div>
                                    ))}
                                    <InputError
                                        message={
                                            errors.registration_fields ??
                                            errors['registration_fields.0']
                                        }
                                    />
                                </fieldset>
                            )}
                            <PropertySettingsFields
                                values={{
                                    front_desk_settings:
                                        property.front_desk_settings,
                                    tax_settings: property.tax_settings,
                                    payment_settings: property.payment_settings,
                                }}
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

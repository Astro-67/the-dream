import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';
import {
    BrandingFields,
    PropertySettingsFields,
    TaxInclusionFields,
    VfmsFields,
    propertySettingsTabs,
    propertySettingsTabForErrors,
} from '@/features/tenancy/property-settings-fields';
import type {
    PropertySettings,
    TaxInclusionControl,
    VfmsSettings,
} from '@/features/tenancy/property-settings-fields';
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
import { index, show, update } from '@/routes/properties';
import {
    ArrowLeft,
    Building2,
    Clock3,
    Coins,
    Globe2,
    MapPin,
} from 'lucide-react';

type Props = PropertyFormOptions & {
    property: PropertyValues &
        PropertySettings & {
            registration_fields?: string[];
            receipt_prefix: string;
            close_time: string;
            stayover_service_on_close: boolean;
        };
    registrationFieldOptions: string[];
    taxInclusion: Record<string, TaxInclusionControl>;
    canManageTaxes: boolean;
    canManageCashierShifts: boolean;
    vfms: VfmsSettings | null;
    branding: { logo_url: string | null; accent_colour: string | null };
    canManageBranding: boolean;
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
    taxInclusion,
    canManageTaxes,
    canManageCashierShifts,
    vfms,
    branding,
    canManageBranding,
    ...options
}: Props) {
    const configuresRegistration = property.registration_fields !== undefined;
    const [activeTab, setActiveTab] = useState('hotel');
    const tabs = Object.entries(propertySettingsTabs).filter(
        ([tab]) => tab !== 'registration_fields' || configuresRegistration,
    );
    const propertyOverview = [
        {
            label: 'Property type',
            value:
                options.propertyTypes.find(
                    (option) => option.value === property.property_type,
                )?.label ?? property.property_type,
            icon: Building2,
        },
        {
            label: 'Location',
            value: `${property.city}, ${property.region}`,
            icon: MapPin,
        },
        {
            label: 'Jurisdiction',
            value:
                options.jurisdictionProfiles.find(
                    (option) => option.value === property.jurisdiction_profile,
                )?.label ?? property.jurisdiction_profile.replaceAll('_', ' '),
            icon: Globe2,
        },
        {
            label: 'Timezone',
            value: property.timezone.replaceAll('_', ' '),
            icon: Clock3,
        },
        { label: 'Currency', value: property.currency, icon: Coins },
    ];
    useEffect(() => {
        document.getElementById(window.location.hash.slice(1))?.focus();
    }, []);

    return (
        <>
            <Head title={`Edit ${property.name}`} />
            <div className="mx-auto grid w-full max-w-6xl min-w-0 gap-5 p-4 md:gap-6 md:p-6 xl:grid-cols-[minmax(0,720px)_minmax(18rem,1fr)]">
                <div className="flex min-w-0 flex-col gap-5 xl:col-span-2">
                    <Link
                        href={show(property.id)}
                        className="inline-flex min-h-9 w-fit items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft aria-hidden className="size-4" />
                        Back to property
                    </Link>
                    <header className="flex items-center gap-4 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5">
                        <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <Building2 aria-hidden className="size-6" />
                        </span>
                        <div className="min-w-0 space-y-1">
                            <p className="text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                                Property settings
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight break-words">
                                {property.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Property details, local settings and
                                jurisdiction profile.
                            </p>
                        </div>
                    </header>
                </div>

                <Form
                    {...update.form(property.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    onError={(errors) =>
                        setActiveTab(propertySettingsTabForErrors(errors))
                    }
                    onInvalidCapture={(event) => {
                        const input =
                            event.currentTarget.querySelector<HTMLElement>(
                                'input:invalid, select:invalid, textarea:invalid',
                            );
                        const panel = input?.closest('[role="tabpanel"]');
                        if (panel) {
                            flushSync(() => setActiveTab(panel.id));
                            input?.focus();
                        }
                    }}
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
                    className="flex min-w-0 flex-col gap-6 border border-border bg-card p-4 shadow-panel md:p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {/* This form is about this property, wherever the member is working. */}
                            <PropertyIntent propertyId={property.id} />
                            <InputError message={errors._property} />
                            <div
                                role="tablist"
                                aria-label="Property settings"
                                className="flex flex-wrap gap-2 border-b pb-2"
                            >
                                {tabs.map(([tab, label], position) => (
                                    <Button
                                        key={tab}
                                        id={`tab-${tab}`}
                                        type="button"
                                        role="tab"
                                        aria-selected={activeTab === tab}
                                        aria-controls={tab}
                                        tabIndex={activeTab === tab ? 0 : -1}
                                        className="min-h-11 whitespace-normal aria-selected:bg-primary-tint aria-selected:text-primary"
                                        variant={
                                            activeTab === tab
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() => setActiveTab(tab)}
                                        onKeyDown={(event) => {
                                            const next = {
                                                ArrowRight:
                                                    (position + 1) %
                                                    tabs.length,
                                                ArrowLeft:
                                                    (position +
                                                        tabs.length -
                                                        1) %
                                                    tabs.length,
                                                Home: 0,
                                                End: tabs.length - 1,
                                            }[event.key];
                                            if (next !== undefined) {
                                                event.preventDefault();
                                                setActiveTab(tabs[next][0]);
                                                document
                                                    .getElementById(
                                                        `tab-${tabs[next][0]}`,
                                                    )
                                                    ?.focus();
                                            }
                                        }}
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </div>
                            <div
                                id="hotel"
                                role="tabpanel"
                                aria-labelledby="tab-hotel"
                                hidden={activeTab !== 'hotel'}
                                tabIndex={0}
                            >
                                <PropertyFields
                                    options={options}
                                    values={property}
                                    errors={errors}
                                />
                            </div>
                            <div
                                id="front_desk_settings"
                                role="tabpanel"
                                aria-labelledby="tab-front_desk_settings"
                                hidden={activeTab !== 'front_desk_settings'}
                                tabIndex={0}
                                className="space-y-6"
                            >
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
                                            Inspect cleaned rooms before they
                                            are ready
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
                                        message={
                                            errors.stayover_service_on_close
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="close_time">
                                        Close time
                                    </Label>
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
                                        Night audit closes the business date at
                                        this local time.
                                    </p>
                                    <InputError message={errors.close_time} />
                                </div>
                                <PropertySettingsFields
                                    values={{
                                        front_desk_settings:
                                            property.front_desk_settings,
                                    }}
                                    errors={errors}
                                />
                            </div>
                            <div
                                id="tax_settings"
                                role="tabpanel"
                                aria-labelledby="tab-tax_settings"
                                hidden={activeTab !== 'tax_settings'}
                                tabIndex={0}
                            >
                                <PropertySettingsFields
                                    values={{
                                        tax_settings: property.tax_settings,
                                    }}
                                    errors={errors}
                                />
                                <TaxInclusionFields
                                    propertyId={property.id}
                                    controls={taxInclusion}
                                    canManage={canManageTaxes}
                                    errors={errors}
                                />
                            </div>
                            <div
                                id="payment_settings"
                                role="tabpanel"
                                aria-labelledby="tab-payment_settings"
                                hidden={activeTab !== 'payment_settings'}
                                tabIndex={0}
                                className="space-y-6"
                            >
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
                                    <InputError
                                        message={errors.receipt_prefix}
                                    />
                                </div>
                                <PropertySettingsFields
                                    values={{
                                        payment_settings:
                                            property.payment_settings,
                                    }}
                                    errors={errors}
                                    canManageCashierShifts={
                                        canManageCashierShifts
                                    }
                                />
                            </div>
                            {configuresRegistration && (
                                <div
                                    id="registration_fields"
                                    role="tabpanel"
                                    aria-labelledby="tab-registration_fields"
                                    hidden={activeTab !== 'registration_fields'}
                                    tabIndex={0}
                                >
                                    <fieldset className="grid gap-3">
                                        <legend className="mb-1 text-sm font-medium">
                                            Guest registration at check-in
                                        </legend>
                                        <p className="text-sm text-muted-foreground">
                                            Identity document fields every guest
                                            needs before check-in.
                                        </p>
                                        {registrationFieldOptions.map(
                                            (field) => (
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
                                            ),
                                        )}
                                        <InputError
                                            message={
                                                errors.registration_fields ??
                                                errors['registration_fields.0']
                                            }
                                        />
                                    </fieldset>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={show(property.id)}>Cancel</Link>
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
                <aside className="min-w-0 self-start overflow-hidden border border-border bg-card shadow-panel xl:sticky xl:top-6">
                    <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3 md:px-5">
                        <span className="grid size-9 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <Building2 aria-hidden className="size-4" />
                        </span>
                        <div>
                            <h2 className="font-semibold">Property overview</h2>
                            <p className="text-xs text-muted-foreground">
                                Current saved details
                            </p>
                        </div>
                    </div>
                    <dl className="divide-y divide-border px-4 md:px-5">
                        {propertyOverview.map(
                            ({ label, value, icon: Icon }) => (
                                <div
                                    key={label}
                                    className="flex items-start gap-3 py-3"
                                >
                                    <Icon
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0 text-primary"
                                    />
                                    <div className="min-w-0">
                                        <dt className="text-xs text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="mt-0.5 text-sm font-medium wrap-anywhere">
                                            {value}
                                        </dd>
                                    </div>
                                </div>
                            ),
                        )}
                    </dl>
                </aside>
                {canManageBranding && activeTab === 'hotel' && (
                    <BrandingFields
                        propertyId={property.id}
                        propertyName={property.name}
                        branding={branding}
                    />
                )}
                {vfms && activeTab === 'tax_settings' && (
                    <VfmsFields
                        propertyId={property.id}
                        vfms={vfms}
                        timeZone={property.timezone}
                    />
                )}
            </div>
        </>
    );
}

EditProperty.layout = ({ property }: Props) => ({
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: property.name, href: show(property.id) },
        { title: 'Edit property', href: '#' },
    ],
});

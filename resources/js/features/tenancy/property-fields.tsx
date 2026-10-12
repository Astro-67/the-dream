import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Currency, JurisdictionProfile, SelectOption } from '@/types';

/** Choices for the property form, sent by the server. */
export type PropertyFormOptions = {
    propertyTypes: SelectOption[];
    currencies: SelectOption[];
    jurisdictionProfiles: SelectOption[];
    timezones: string[];
};

/** A property's stored values, as the edit form receives them. */
export type PropertyValues = {
    housekeeping_inspection_required: boolean;
    id: string;
    name: string;
    property_type: string;
    address_line: string;
    city: string;
    region: string;
    postal_code: string | null;
    phone: string;
    email: string;
    timezone: string;
    currency: Currency;
    jurisdiction_profile: JurisdictionProfile;
    /** Sent only for Zanzibar properties. */
    zra_hotel_code?: string | null;
};

const jurisdictionHints: Record<JurisdictionProfile, string> = {
    zanzibar:
        'ZRA rules apply: booking reports to ZAN Gateway and fiscal receipts through VFMS.',
    mainland_tanzania:
        'No ZRA reporting. Thedream issues non-fiscal receipts for mainland properties.',
};

function Field({
    id,
    label,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function OptionSelect({
    id,
    name,
    options,
    defaultValue,
    placeholder,
}: {
    id: string;
    name: string;
    options: SelectOption[];
    defaultValue?: string;
    placeholder: string;
}) {
    return (
        <Select name={name} defaultValue={defaultValue} required>
            <SelectTrigger id={id} className="w-full">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * The property form's fields: name, type, jurisdiction profile, address,
 * contact details, timezone and currency. Zanzibar-only fields appear only
 * while the Zanzibar profile is chosen.
 */
export function PropertyFields({
    options,
    values,
    errors,
    nameField = 'name',
    defaultJurisdiction = null,
    autoFocusName = true,
}: {
    options: PropertyFormOptions;
    values?: Partial<PropertyValues>;
    errors: Partial<Record<string, string>>;
    nameField?: string;
    defaultJurisdiction?: JurisdictionProfile | null;
    autoFocusName?: boolean;
}) {
    const [jurisdiction, setJurisdiction] =
        useState<JurisdictionProfile | null>(
            values?.jurisdiction_profile ?? defaultJurisdiction,
        );
    const propertyNameField = nameField;

    return (
        <div className="grid gap-6 sm:grid-cols-2">
            <Field
                id={propertyNameField}
                label="Property name"
                error={errors[nameField] ?? errors.name}
            >
                <Input
                    id={propertyNameField}
                    name={propertyNameField}
                    required
                    autoFocus={autoFocusName}
                    defaultValue={values?.name}
                />
            </Field>
            <Field
                id="property_type"
                label="Property type"
                error={errors.property_type}
            >
                <OptionSelect
                    id="property_type"
                    name="property_type"
                    options={options.propertyTypes}
                    defaultValue={values?.property_type}
                    placeholder="Choose a type"
                />
            </Field>

            <fieldset className="grid gap-3 sm:col-span-2">
                <legend className="mb-3 text-sm leading-none font-medium">
                    Jurisdiction profile
                </legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    {options.jurisdictionProfiles.map((profile) => {
                        const value = profile.value as JurisdictionProfile;
                        const checked = jurisdiction === value;

                        return (
                            <label
                                key={value}
                                className={cn(
                                    'flex cursor-pointer gap-3 rounded-lg border border-input p-4 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2',
                                    checked && 'border-primary bg-primary/5',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="jurisdiction_profile"
                                    value={value}
                                    required
                                    checked={checked}
                                    onChange={() => setJurisdiction(value)}
                                    className="mt-0.5 size-4 accent-primary"
                                />
                                <span className="grid gap-1">
                                    <span className="text-sm font-medium">
                                        {profile.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {jurisdictionHints[value]}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
                <InputError message={errors.jurisdiction_profile} />
            </fieldset>

            {jurisdiction === 'zanzibar' && (
                <Field
                    id="zra_hotel_code"
                    label="ZRA hotel code (optional)"
                    hint="Issued when ZRA registers the property. You can add it later."
                    error={errors.zra_hotel_code}
                    className="sm:col-span-2"
                >
                    <Input
                        id="zra_hotel_code"
                        name="zra_hotel_code"
                        className="font-mono"
                        defaultValue={values?.zra_hotel_code ?? undefined}
                    />
                </Field>
            )}

            <Field
                id="address_line"
                label="Street address"
                error={errors.address_line}
                className="sm:col-span-2"
            >
                <Input
                    id="address_line"
                    name="address_line"
                    required
                    autoComplete="address-line1"
                    defaultValue={values?.address_line}
                />
            </Field>
            <Field id="city" label="City or town" error={errors.city}>
                <Input
                    id="city"
                    name="city"
                    required
                    autoComplete="address-level2"
                    defaultValue={values?.city}
                />
            </Field>
            <Field id="region" label="Region" error={errors.region}>
                <Input
                    id="region"
                    name="region"
                    required
                    autoComplete="address-level1"
                    defaultValue={values?.region}
                />
            </Field>
            <Field
                id="postal_code"
                label="Postal code (optional)"
                error={errors.postal_code}
            >
                <Input
                    id="postal_code"
                    name="postal_code"
                    autoComplete="postal-code"
                    defaultValue={values?.postal_code ?? undefined}
                />
            </Field>
            <Field id="phone" label="Phone" error={errors.phone}>
                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    required
                    autoComplete="tel"
                    defaultValue={values?.phone}
                />
            </Field>
            <Field
                id="email"
                label="Property email"
                error={errors.email}
                className="sm:col-span-2"
            >
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autoComplete="email"
                    defaultValue={values?.email}
                />
            </Field>
            <Field id="timezone" label="Timezone" error={errors.timezone}>
                <OptionSelect
                    id="timezone"
                    name="timezone"
                    options={options.timezones.map((zone) => ({
                        value: zone,
                        label: zone.replaceAll('_', ' '),
                    }))}
                    defaultValue={values?.timezone}
                    placeholder="Choose a timezone"
                />
            </Field>
            <Field
                id="currency"
                label="Operational currency"
                error={errors.currency}
            >
                <OptionSelect
                    id="currency"
                    name="currency"
                    options={options.currencies}
                    defaultValue={values?.currency}
                    placeholder="Choose a currency"
                />
            </Field>
        </div>
    );
}

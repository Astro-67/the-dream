import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
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
    star_rating?: number | null;
    tin?: string | null;
    z_number?: string | null;
    vrn?: string | null;
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
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
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
    const registrationHint =
        jurisdiction === 'mainland_tanzania'
            ? 'Optional for mainland properties.'
            : 'Optional during setup. Required before go-live in Zanzibar.';

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

            <Field
                id="star_rating"
                label="Star rating"
                error={errors.star_rating}
                hint={`${registrationHint} Zanzibar infrastructure levy: not yet classified or 1 star $2, 2–3 stars $4, 4–5 stars $5 per guest per night. Assessed by ZRA at check-out.`}
            >
                <NativeSelect
                    id="star_rating"
                    name="star_rating"
                    defaultValue={values?.star_rating ?? ''}
                    aria-invalid={!!errors.star_rating}
                    aria-describedby="star_rating-hint"
                >
                    <option value="">Not yet classified</option>
                    {[1, 2, 3, 4, 5].map((stars) => (
                        <option key={stars} value={stars}>
                            {stars} {stars === 1 ? 'star' : 'stars'}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <fieldset className="sm:col-span-2">
                <legend className="text-sm font-medium">
                    Tax &amp; regulatory information
                </legend>
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    {(['tin', 'z_number', 'vrn'] as const).map((field) => (
                        <Field
                            key={field}
                            id={field}
                            label={
                                {
                                    tin: 'TIN',
                                    z_number:
                                        'Z-number (ZTN, including any location suffix)',
                                    vrn: 'VRN',
                                }[field]
                            }
                            error={errors[field]}
                            hint={
                                field === 'vrn' &&
                                jurisdiction !== 'mainland_tanzania'
                                    ? 'Optional during setup. Required before go-live in Zanzibar for VAT-category properties only. Not required for exempt or non-VAT properties.'
                                    : registrationHint
                            }
                        >
                            <Input
                                id={field}
                                name={field}
                                defaultValue={values?.[field] ?? ''}
                                maxLength={64}
                                aria-invalid={!!errors[field]}
                                aria-describedby={`${field}-hint`}
                            />
                        </Field>
                    ))}
                    {jurisdiction === 'zanzibar' && (
                        <Field
                            id="zra_hotel_code"
                            label="ZRA hotel code"
                            hint={`${registrationHint} Issued by ZRA to identify the property, separate from its tax registration numbers.`}
                            error={errors.zra_hotel_code}
                        >
                            <Input
                                id="zra_hotel_code"
                                name="zra_hotel_code"
                                className="font-mono"
                                defaultValue={values?.zra_hotel_code ?? ''}
                                maxLength={64}
                                aria-invalid={!!errors.zra_hotel_code}
                                aria-describedby="zra_hotel_code-hint"
                            />
                        </Field>
                    )}
                </div>
            </fieldset>

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

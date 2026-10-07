import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { store } from '@/routes/organizations';
import type { JurisdictionProfile, SelectOption } from '@/types';

type Props = {
    countries: SelectOption[];
    propertyTypes: SelectOption[];
    currencies: SelectOption[];
    jurisdictionProfiles: SelectOption[];
    timezones: string[];
    defaults: {
        country: string;
        timezone: string;
        currency: string;
    };
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

export default function CreateOrganization({
    countries,
    propertyTypes,
    currencies,
    jurisdictionProfiles,
    timezones,
    defaults,
}: Props) {
    const [jurisdiction, setJurisdiction] =
        useState<JurisdictionProfile | null>(null);

    return (
        <>
            <Head title="Set up your organization" />
            <div className="min-h-svh bg-background px-4 py-10 md:px-6 md:py-16">
                <div className="mx-auto flex w-full max-w-[720px] flex-col gap-8">
                    <header className="flex flex-col gap-4">
                        <AppLogoIcon className="size-9 fill-current text-foreground" />
                        <div className="space-y-2">
                            <h1 className="text-3xl font-semibold tracking-tight">
                                Set up your organization
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Tell us about your business and your first
                                property. You can add more properties later.
                            </p>
                        </div>
                    </header>

                    <Form
                        {...store.form()}
                        disableWhileProcessing
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-lg">
                                            Your business
                                        </CardTitle>
                                        <CardDescription>
                                            The organization that owns your
                                            properties.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-6 sm:grid-cols-2">
                                        <Field
                                            id="organization_name"
                                            label="Business name"
                                            error={errors.organization_name}
                                        >
                                            <Input
                                                id="organization_name"
                                                name="organization_name"
                                                required
                                                autoFocus
                                                autoComplete="organization"
                                            />
                                        </Field>
                                        <Field
                                            id="country"
                                            label="Country"
                                            error={errors.country}
                                        >
                                            <OptionSelect
                                                id="country"
                                                name="country"
                                                options={countries}
                                                defaultValue={defaults.country}
                                                placeholder="Choose a country"
                                            />
                                        </Field>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-lg">
                                            Your first property
                                        </CardTitle>
                                        <CardDescription>
                                            The hotel you'll start working in.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-6 sm:grid-cols-2">
                                        <Field
                                            id="property_name"
                                            label="Property name"
                                            error={errors.property_name}
                                        >
                                            <Input
                                                id="property_name"
                                                name="property_name"
                                                required
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
                                                options={propertyTypes}
                                                placeholder="Choose a type"
                                            />
                                        </Field>

                                        <fieldset className="grid gap-3 sm:col-span-2">
                                            <legend className="mb-3 text-sm leading-none font-medium">
                                                Jurisdiction profile
                                            </legend>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {jurisdictionProfiles.map(
                                                    (profile) => {
                                                        const value =
                                                            profile.value as JurisdictionProfile;
                                                        const checked =
                                                            jurisdiction ===
                                                            value;

                                                        return (
                                                            <label
                                                                key={value}
                                                                className={cn(
                                                                    'flex cursor-pointer gap-3 rounded-lg border border-input p-4 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2',
                                                                    checked &&
                                                                        'border-primary bg-primary/5',
                                                                )}
                                                            >
                                                                <input
                                                                    type="radio"
                                                                    name="jurisdiction_profile"
                                                                    value={
                                                                        value
                                                                    }
                                                                    required
                                                                    checked={
                                                                        checked
                                                                    }
                                                                    onChange={() =>
                                                                        setJurisdiction(
                                                                            value,
                                                                        )
                                                                    }
                                                                    className="mt-0.5 size-4 accent-primary"
                                                                />
                                                                <span className="grid gap-1">
                                                                    <span className="text-sm font-medium">
                                                                        {
                                                                            profile.label
                                                                        }
                                                                    </span>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {
                                                                            jurisdictionHints[
                                                                                value
                                                                            ]
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </label>
                                                        );
                                                    },
                                                )}
                                            </div>
                                            <InputError
                                                message={
                                                    errors.jurisdiction_profile
                                                }
                                            />
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
                                            />
                                        </Field>
                                        <Field
                                            id="city"
                                            label="City or town"
                                            error={errors.city}
                                        >
                                            <Input
                                                id="city"
                                                name="city"
                                                required
                                                autoComplete="address-level2"
                                            />
                                        </Field>
                                        <Field
                                            id="region"
                                            label="Region"
                                            error={errors.region}
                                        >
                                            <Input
                                                id="region"
                                                name="region"
                                                required
                                                autoComplete="address-level1"
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
                                            />
                                        </Field>
                                        <Field
                                            id="phone"
                                            label="Phone"
                                            error={errors.phone}
                                        >
                                            <Input
                                                id="phone"
                                                name="phone"
                                                type="tel"
                                                required
                                                autoComplete="tel"
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
                                            />
                                        </Field>
                                        <Field
                                            id="timezone"
                                            label="Timezone"
                                            error={errors.timezone}
                                        >
                                            <OptionSelect
                                                id="timezone"
                                                name="timezone"
                                                options={timezones.map(
                                                    (zone) => ({
                                                        value: zone,
                                                        label: zone.replaceAll(
                                                            '_',
                                                            ' ',
                                                        ),
                                                    }),
                                                )}
                                                defaultValue={defaults.timezone}
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
                                                options={currencies}
                                                defaultValue={defaults.currency}
                                                placeholder="Choose a currency"
                                            />
                                        </Field>
                                    </CardContent>
                                </Card>

                                <div className="flex flex-col-reverse items-center justify-between gap-4 sm:flex-row">
                                    <TextLink
                                        href={logout()}
                                        as="button"
                                        className="text-sm"
                                    >
                                        Log out
                                    </TextLink>
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full sm:w-auto"
                                        data-test="create-organization-button"
                                    >
                                        {processing && <Spinner />}
                                        Create organization
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

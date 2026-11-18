import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { PropertyFormOptions } from '@/features/tenancy/property-fields';
import { PropertyFields } from '@/features/tenancy/property-fields';
import { preventOfflineWrite, useOnline } from '@/hooks/use-online';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';
import { logout } from '@/routes';
import { store } from '@/routes/organizations';
import type { Currency, SelectOption } from '@/types';

type Props = {
    countries: SelectOption[];
} & PropertyFormOptions & {
        defaults: {
            country: string;
            timezone: string;
            currency: Currency;
        };
    };

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function OptionSelect({
    id,
    name,
    options,
    defaultValue,
    placeholder,
    error,
}: {
    id: string;
    name: string;
    options: SelectOption[];
    defaultValue?: string;
    placeholder: string;
    error?: string;
}) {
    return (
        <Select name={name} defaultValue={defaultValue} required>
            <SelectTrigger
                id={id}
                className="w-full"
                aria-invalid={Boolean(error)}
                aria-describedby={error ? `${id}-error` : undefined}
            >
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
    const [step, setStep] = useState<2 | 3>(2);
    const online = useOnline();

    useEffect(() => router.on('before', preventOfflineWrite), []);

    useEffect(() => {
        document.getElementById('auth-title')?.focus();
    }, [step]);

    return (
        <AuthSimpleLayout
            setupStep={step}
            title={
                step === 2
                    ? 'A home for your business.'
                    : 'Meet your first property.'
            }
            description={
                step === 2
                    ? 'Your organization owns the subscription and brings your properties together.'
                    : 'Tell us about the property you will start working in. You can add more properties later.'
            }
        >
            <Head title="Set up your organization" />
            {!online && (
                <Alert className="mb-6 border-status-warning bg-status-warning-tint text-status-warning">
                    <AlertDescription className="text-status-warning">
                        Offline: changes can&apos;t be saved. Your setup details
                        stay here until you reconnect.
                    </AlertDescription>
                </Alert>
            )}
            <Form
                {...store.form()}
                disableWhileProcessing
                noValidate
                onSubmitCapture={(event) => {
                    const invalid = event.currentTarget.querySelector<
                        HTMLInputElement | HTMLSelectElement
                    >(
                        step === 2
                            ? '[data-business] input:invalid, [data-business] select:invalid'
                            : 'input:invalid, select:invalid',
                    );
                    if (invalid) {
                        event.preventDefault();
                        event.stopPropagation();
                        setStep(invalid.closest('[data-business]') ? 2 : 3);
                        requestAnimationFrame(() => invalid.reportValidity());
                    } else if (step === 2) {
                        event.preventDefault();
                        event.stopPropagation();
                        setStep(3);
                    }
                }}
                onError={(errors) => {
                    setStep(
                        ['organization_name', 'country', 'rooms'].some(
                            (field) => field in errors,
                        )
                            ? 2
                            : 3,
                    );
                }}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset
                            data-business
                            hidden={step !== 2}
                            className="grid gap-6"
                        >
                            <legend className="sr-only">Your business</legend>
                            <Field
                                id="organization_name"
                                label="Business name"
                                error={errors.organization_name}
                            >
                                <Input
                                    id="organization_name"
                                    name="organization_name"
                                    required
                                    autoComplete="organization"
                                    aria-invalid={Boolean(
                                        errors.organization_name,
                                    )}
                                    aria-describedby={
                                        errors.organization_name
                                            ? 'organization_name-error'
                                            : undefined
                                    }
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
                                    error={errors.country}
                                />
                            </Field>
                            <Field
                                id="rooms"
                                label="Rooms to subscribe"
                                error={errors.rooms}
                            >
                                <Input
                                    id="rooms"
                                    name="rooms"
                                    type="number"
                                    min={1}
                                    required
                                    defaultValue={1}
                                    aria-invalid={Boolean(errors.rooms)}
                                    aria-describedby={
                                        errors.rooms
                                            ? 'rooms-note rooms-error'
                                            : 'rooms-note'
                                    }
                                />
                                <p
                                    id="rooms-note"
                                    className="text-sm text-muted-foreground"
                                >
                                    Across all your properties. You pay per room
                                    and can change this later.
                                </p>
                            </Field>
                        </fieldset>

                        <fieldset hidden={step !== 3} className="min-w-0">
                            <legend className="sr-only">
                                Your first property
                            </legend>
                            <PropertyFields
                                options={{
                                    propertyTypes,
                                    currencies,
                                    jurisdictionProfiles,
                                    timezones,
                                }}
                                values={{
                                    timezone: defaults.timezone,
                                    currency: defaults.currency,
                                }}
                                errors={errors}
                                nameField="property_name"
                                defaultJurisdiction="zanzibar"
                                autoFocusName={false}
                            />
                        </fieldset>

                        <p className="text-sm text-muted-foreground">
                            {step === 2
                                ? 'These details remain a draft until you finish the property step.'
                                : 'Your organization and first property are created together. Room configuration, rates and go-live checks come next.'}
                        </p>

                        <div className="flex flex-col-reverse items-center justify-between gap-4 sm:flex-row">
                            {step === 2 ? (
                                <TextLink
                                    href={logout()}
                                    as="button"
                                    className="inline-flex min-h-11 items-center text-sm"
                                >
                                    Log out
                                </TextLink>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                    onClick={() => setStep(2)}
                                >
                                    Back to business
                                </Button>
                            )}
                            <Button
                                type="submit"
                                size="lg"
                                className="w-full sm:w-auto"
                                disabled={processing || (step === 3 && !online)}
                                data-test={
                                    step === 3
                                        ? 'create-organization-button'
                                        : undefined
                                }
                            >
                                {processing && <Spinner />}
                                {step === 2
                                    ? 'Continue to your property'
                                    : 'Create organization'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AuthSimpleLayout>
    );
}

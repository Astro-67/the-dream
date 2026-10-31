import { Form, Head } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
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
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { PropertyFormOptions } from '@/features/tenancy/property-fields';
import { PropertyFields } from '@/features/tenancy/property-fields';
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
        <div className={cn('grid content-start gap-2')}>
            <Label htmlFor={id}>{label}</Label>
            {children}
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
    return (
        <>
            <Head title="Set up your organization" />
            <div className="min-h-svh bg-background px-4 py-10 md:px-6 md:py-16">
                <div className="mx-auto flex w-full max-w-[720px] flex-col gap-8">
                    <header className="flex flex-col gap-4">
                        <AppLogoIcon className="size-9 fill-current text-foreground" />
                        <div className="space-y-2">
                            <p className="text-sm font-medium text-muted-foreground">
                                Last step before your setup checklist
                            </p>
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
                                                aria-describedby="rooms-note"
                                            />
                                            <p
                                                id="rooms-note"
                                                className="text-sm text-muted-foreground"
                                            >
                                                Across all your properties. You
                                                pay per room and can change this
                                                later.
                                            </p>
                                        </Field>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-lg">
                                            Your first property
                                        </CardTitle>
                                        <CardDescription>
                                            The property you'll start working
                                            in.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
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

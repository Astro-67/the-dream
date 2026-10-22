import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { create, store } from '@/routes/invitations';
import { index } from '@/routes/members';
import type { SelectOption } from '@/types';

type RoleOption = SelectOption & {
    summary: string;
    mayHaveAllProperties: boolean;
};

type Props = {
    roles: RoleOption[];
    properties: SelectOption[];
    defaultExpiryDays: number;
};

const expiryChoices = [1, 3, 7, 14, 30];

export default function CreateInvitation({
    roles,
    properties,
    defaultExpiryDays,
}: Props) {
    const { tenant } = usePage().props;
    const [role, setRole] = useState<RoleOption | null>(null);
    const [allProperties, setAllProperties] = useState(false);
    const grantsAll = allProperties && (role?.mayHaveAllProperties ?? false);

    return (
        <>
            <Head title="Add staff" />
            <div className="w-full max-w-[720px] p-4 md:p-6">
                <Heading
                    title="Add staff"
                    description="They'll get an expiring invitation by email and accept by signing in or setting their own password. You never see or set their password."
                />

                <Form
                    {...store.form()}
                    disableWhileProcessing
                    transform={(data) => ({
                        ...data,
                        all_properties: grantsAll,
                    })}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <Select
                                    name="role"
                                    required
                                    onValueChange={(value) => {
                                        const next =
                                            roles.find(
                                                (option) =>
                                                    option.value === value,
                                            ) ?? null;
                                        setRole(next);

                                        if (!next?.mayHaveAllProperties) {
                                            setAllProperties(false);
                                        }
                                    }}
                                >
                                    <SelectTrigger id="role" className="w-full">
                                        <SelectValue placeholder="Choose a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                                <div className="grid gap-2 text-sm text-muted-foreground">
                                    {roles.map((option) => (
                                        <p key={option.value}>
                                            <span className="font-medium">
                                                {option.label}:{' '}
                                            </span>
                                            {option.summary}
                                        </p>
                                    ))}
                                </div>
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Properties
                                </legend>
                                {role?.mayHaveAllProperties && (
                                    <div className="flex items-center gap-3">
                                        <Checkbox
                                            id="all_properties"
                                            checked={allProperties}
                                            onCheckedChange={(checked) =>
                                                setAllProperties(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <Label htmlFor="all_properties">
                                            All properties, including ones added
                                            later
                                        </Label>
                                    </div>
                                )}
                                {!grantsAll &&
                                    properties.map((property) => (
                                        <div
                                            key={property.value}
                                            className="flex items-center gap-3"
                                        >
                                            <Checkbox
                                                id={`property-${property.value}`}
                                                name="property_ids[]"
                                                value={property.value}
                                                defaultChecked={
                                                    properties.length === 1 ||
                                                    property.value ===
                                                        tenant.property?.id
                                                }
                                            />
                                            <Label
                                                htmlFor={`property-${property.value}`}
                                            >
                                                {property.label}
                                            </Label>
                                        </div>
                                    ))}
                                <InputError
                                    message={
                                        errors.property_ids ??
                                        errors.all_properties
                                    }
                                />
                            </fieldset>

                            <details
                                className="grid gap-2"
                                open={!!errors.expires_in_days}
                            >
                                <summary className="cursor-pointer text-sm font-medium">
                                    Advanced invitation options
                                </summary>
                                <div className="mt-3 grid gap-2">
                                    <Label htmlFor="expires_in_days">
                                        Expires after
                                    </Label>
                                    <Select
                                        name="expires_in_days"
                                        defaultValue={String(defaultExpiryDays)}
                                    >
                                        <SelectTrigger
                                            id="expires_in_days"
                                            className="w-full sm:w-48"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {expiryChoices.map((days) => (
                                                <SelectItem
                                                    key={days}
                                                    value={String(days)}
                                                >
                                                    {days === 1
                                                        ? '1 day'
                                                        : `${days} days`}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.expires_in_days}
                                    />
                                </div>
                            </details>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Send invitation
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateInvitation.layout = {
    breadcrumbs: [
        { title: 'Staff', href: index() },
        { title: 'Add staff', href: create() },
    ],
};

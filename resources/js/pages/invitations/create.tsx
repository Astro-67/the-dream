import { Form, Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
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
import { MembershipGrantFields } from '@/features/tenancy/membership-grant-fields';
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

                            <MembershipGrantFields
                                idPrefix="invite"
                                roles={roles}
                                properties={properties}
                                defaultPropertyIds={properties
                                    .filter(
                                        (property) =>
                                            property.value ===
                                            tenant.property?.id,
                                    )
                                    .map((property) => property.value)}
                                errors={errors}
                            />
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

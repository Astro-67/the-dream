import { Form, Head, Link } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import type { RoleOption } from '@/features/tenancy/membership-grant-fields';
import { MembershipGrantFields } from '@/features/tenancy/membership-grant-fields';
import { RemoveMember } from '@/features/tenancy/remove-member';
import { index, transferOwnership, update } from '@/routes/members';
import type { SelectOption } from '@/types';

type Member = {
    id: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    allProperties: boolean;
    propertyIds: string[];
    isYou: boolean;
    canTransferOwnership: boolean;
};

type Props = {
    member: Member;
    roles: RoleOption[];
    transferRoles: RoleOption[];
    properties: SelectOption[];
};

function TransferOwnership({
    member,
    roles,
    properties,
}: {
    member: Member;
    roles: RoleOption[];
    properties: SelectOption[];
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="secondary">Transfer ownership</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Make {member.name} an owner?</DialogTitle>
                <DialogDescription>
                    {member.name} becomes an owner with all properties,
                    including member management and owner-only settings, once
                    they turn on two-factor authentication. Choose what you
                    keep: you can stay an owner, or take another role.
                </DialogDescription>
                <Form
                    {...transferOwnership.form(member.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            {(errors.member || errors._property) && (
                                <p
                                    role="alert"
                                    className="flex gap-2 text-[13px] text-status-error"
                                >
                                    <AlertCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {errors.member ?? errors._property}
                                </p>
                            )}
                            <MembershipGrantFields
                                idPrefix="transfer"
                                roleLabel="Your role after the transfer"
                                roles={roles}
                                properties={properties}
                                defaultRole="owner"
                                defaultAllProperties
                                errors={errors}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button disabled={processing} type="submit">
                                    {processing && <Spinner />}
                                    Transfer ownership
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function EditMember({
    member,
    roles,
    transferRoles,
    properties,
}: Props) {
    return (
        <>
            <Head title={`Access for ${member.name}`} />
            <div className="flex w-full max-w-[720px] flex-col gap-8 p-4 md:p-6">
                <Heading
                    title={member.name}
                    description={`${member.email} · ${member.roleLabel}. Changes apply on their next request, including pages they have open.`}
                />

                <Form
                    {...update.form(member.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <MembershipGrantFields
                                idPrefix="member"
                                roles={roles}
                                properties={properties}
                                defaultRole={member.role}
                                defaultAllProperties={member.allProperties}
                                defaultPropertyIds={member.propertyIds}
                                errors={errors}
                            />
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save access
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {member.canTransferOwnership && (
                    <>
                        <Separator />
                        <section className="flex flex-col gap-4">
                            <Heading
                                variant="small"
                                title="Transfer ownership"
                                description={`Make ${member.name} an owner of the organization. You choose the role you keep.`}
                            />
                            <div>
                                <TransferOwnership
                                    member={member}
                                    roles={transferRoles}
                                    properties={properties}
                                />
                            </div>
                        </section>
                    </>
                )}

                <Separator />
                <section className="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title={
                            member.isYou
                                ? 'Leave the organization'
                                : 'Remove from the organization'
                        }
                        description="Access to every property ends at once. The organization always keeps at least one owner."
                    />
                    <div>
                        <RemoveMember
                            member={member}
                            trigger={
                                <Button variant="destructive">
                                    {member.isYou
                                        ? 'Leave organization'
                                        : 'Remove member'}
                                </Button>
                            }
                        />
                    </div>
                </section>
            </div>
        </>
    );
}

EditMember.layout = {
    breadcrumbs: [
        { title: 'Staff', href: index() },
        { title: 'Edit access', href: index() },
    ],
};

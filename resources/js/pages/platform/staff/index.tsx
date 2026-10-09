import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Form, Head } from '@inertiajs/react';
import { X } from 'lucide-react';
import StaffController from '@/actions/App/Http/Controllers/Web/Platform/StaffController';
import InputError from '@/components/input-error';
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
import { Tag } from '@/components/ui/status-chip';
import { TwoFactorStatus } from '@/features/platform/two-factor-status';
import { index } from '@/routes/platform/staff';
import type { PlatformRole, PlatformStaffMember, SelectOption } from '@/types';

type Props = {
    staff: PlatformStaffMember[];
    roles: SelectOption[];
};

function RemoveRole({
    member,
    role,
    label,
}: {
    member: PlatformStaffMember;
    role: PlatformRole;
    label: string;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="inline-flex size-6 items-center justify-center rounded-sm text-muted-foreground hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    aria-label={`Remove ${label} role from ${member.name}`}
                >
                    <X className="size-3.5" aria-hidden />
                </button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Remove the {label.toLowerCase()} role from {member.name}?
                </DialogTitle>
                <DialogDescription>
                    {member.name} loses access to platform pages for this role
                    at once, including pages already open.
                </DialogDescription>
                <Form
                    {...StaffController.destroy.form({ user: member.id, role })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.role} />
                            <DialogFooter className="mt-4 gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Remove role
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function PlatformStaff({ staff, roles }: Props) {
    const roleLabel = (value: string) =>
        roles.find((role) => role.value === value)?.label ?? value;

    return (
        <>
            <Head title="Platform staff" />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Platform staff
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        People with platform roles. These roles sit outside
                        every organization and can't be given through
                        organization membership or invitations.
                    </p>
                </header>

                <section className="max-w-[720px] space-y-4 rounded-xl border bg-card p-5">
                    <div className="space-y-1">
                        <h2 className="text-lg font-semibold tracking-tight">
                            Give a platform role
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            The person must already have an account. They need
                            two-factor authentication turned on before they can
                            open platform pages.
                        </p>
                    </div>
                    <Form
                        {...StaffController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-start"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        autoComplete="off"
                                        required
                                        aria-invalid={!!errors.email}
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="role">Role</Label>
                                    <Select
                                        name="role"
                                        defaultValue={
                                            roles[roles.length - 1]?.value
                                        }
                                        required
                                    >
                                        <SelectTrigger
                                            id="role"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {roles.map((role) => (
                                                <SelectItem
                                                    key={role.value}
                                                    value={role.value}
                                                >
                                                    {role.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="sm:mt-[1.375rem]"
                                >
                                    {processing && <Spinner />}
                                    Give role
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Person</TableHead>
                            <TableHead scope="col">Platform roles</TableHead>
                            <TableHead scope="col">Two-factor</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {staff.map((member) => (
                            <TableRow key={member.id} className="align-top">
                                <TableCell>
                                    <div className="font-medium">
                                        {member.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {member.email}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <ul className="flex flex-wrap gap-2">
                                        {member.roles.map((role) => (
                                            <li key={role}>
                                                <Tag className="gap-1 pr-0.5 pl-2">
                                                    {roleLabel(role)}
                                                    <RemoveRole
                                                        member={member}
                                                        role={role}
                                                        label={roleLabel(role)}
                                                    />
                                                </Tag>
                                            </li>
                                        ))}
                                    </ul>
                                </TableCell>
                                <TableCell>
                                    <TwoFactorStatus
                                        enabled={member.twoFactorEnabled}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}

PlatformStaff.layout = {
    breadcrumbs: [
        {
            title: 'Platform staff',
            href: index(),
        },
    ],
};

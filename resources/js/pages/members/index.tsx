import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Head, Link } from '@inertiajs/react';
import { MailPlus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RemoveMember } from '@/features/tenancy/remove-member';
import {
    PendingInvitations,
    type PendingInvitation,
} from '@/features/tenancy/pending-invitations';
import { create as invite } from '@/routes/invitations';
import { edit, index } from '@/routes/members';

type MemberRow = {
    id: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    allProperties: boolean;
    properties: string[];
    isYou: boolean;
    canManage: boolean;
};

type Props = {
    members: MemberRow[];
    invitations: PendingInvitation[];
    canInvite: boolean;
};

export default function Members({ members, invitations, canInvite }: Props) {
    return (
        <>
            <Head title="Staff" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Staff"
                        description="The people in your organization, their roles and the properties they work in."
                    />
                    {canInvite && (
                        <Button asChild>
                            <Link href={invite()}>
                                <MailPlus aria-hidden />
                                Add staff
                            </Link>
                        </Button>
                    )}
                </div>

                {members.length === 0 ? (
                    <EmptyState message="No members in your properties yet." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Properties</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-2 font-medium">
                                            {member.name}
                                            {member.isYou && (
                                                <Badge variant="outline">
                                                    You
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {member.email}
                                        </div>
                                    </TableCell>
                                    <TableCell>{member.roleLabel}</TableCell>
                                    <TableCell>
                                        {member.allProperties ? (
                                            <Badge variant="secondary">
                                                All properties
                                            </Badge>
                                        ) : (
                                            member.properties.join(', ')
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap">
                                        {member.canManage && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(member.id)}
                                                        aria-label={`Edit access for ${member.name}`}
                                                    >
                                                        Edit access
                                                    </Link>
                                                </Button>
                                                <RemoveMember
                                                    member={member}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Remove ${member.name}`}
                                                        >
                                                            Remove
                                                        </Button>
                                                    }
                                                />
                                            </>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {(canInvite || invitations.length > 0) && (
                    <section
                        className="grid gap-4"
                        aria-labelledby="pending-invitations"
                    >
                        <h2 id="pending-invitations" className="text-lg">
                            Pending invitations
                        </h2>
                        <PendingInvitations invitations={invitations} />
                    </section>
                )}
            </div>
        </>
    );
}

Members.layout = {
    breadcrumbs: [
        {
            title: 'Staff',
            href: index(),
        },
    ],
};

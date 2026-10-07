import { Head, Link } from '@inertiajs/react';
import { MailPlus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RemoveMember } from '@/features/tenancy/remove-member';
import { useCan } from '@/hooks/use-can';
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
};

export default function Members({ members }: Props) {
    const can = useCan();

    return (
        <>
            <Head title="Members" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Members"
                        description="The people in your organization, their roles and the properties they work in."
                    />
                    {can('members.manage', 'members.invite') && (
                        <Button asChild>
                            <Link href={invite()}>
                                <MailPlus aria-hidden />
                                Invite person
                            </Link>
                        </Button>
                    )}
                </div>

                {members.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No members in your properties yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-sm">
                            <thead className="border-b text-left text-muted-foreground">
                                <tr>
                                    <th className="h-10 px-4 font-medium">
                                        Name
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Role
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Properties
                                    </th>
                                    <th className="h-10 px-4">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <tr
                                        key={member.id}
                                        className="border-b last:border-0 hover:bg-background"
                                    >
                                        <td className="px-4 py-2">
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
                                        </td>
                                        <td className="px-4 py-2">
                                            {member.roleLabel}
                                        </td>
                                        <td className="px-4 py-2">
                                            {member.allProperties ? (
                                                <Badge variant="secondary">
                                                    All properties
                                                </Badge>
                                            ) : (
                                                member.properties.join(', ')
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-right whitespace-nowrap">
                                            {member.canManage && (
                                                <>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                member.id,
                                                            )}
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
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Members.layout = {
    breadcrumbs: [
        {
            title: 'Members',
            href: index(),
        },
    ],
};

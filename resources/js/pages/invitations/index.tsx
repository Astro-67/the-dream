import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Form, Head, Link } from '@inertiajs/react';
import { MailPlus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
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
import { create, destroy, index } from '@/routes/invitations';
import { formatDate } from '@/features/platform/format';

type PendingInvitation = {
    id: string;
    email: string;
    role: string;
    roleLabel: string;
    allProperties: boolean;
    properties: string[];
    invitedBy: string | null;
    expiresAt: string;
    canRevoke: boolean;
};

type Props = {
    invitations: PendingInvitation[];
    canInvite: boolean;
};

function RevokeInvitation({ invitation }: { invitation: PendingInvitation }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Revoke
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Revoke the invitation for {invitation.email}?
                </DialogTitle>
                <DialogDescription>
                    The link in their email stops working at once. You can send
                    a new invitation later.
                </DialogDescription>
                <Form
                    {...destroy.form(invitation.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Keep it</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                type="submit"
                            >
                                Revoke invitation
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Invitations({ invitations, canInvite }: Props) {
    return (
        <>
            <Head title="Invitations" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Invitations"
                        description="People invited to join your organization who haven't accepted yet."
                    />
                    {canInvite && (
                        <Button asChild>
                            <Link href={create()}>
                                <MailPlus aria-hidden />
                                Invite person
                            </Link>
                        </Button>
                    )}
                </div>

                {invitations.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No pending invitations.
                        {canInvite && (
                            <>
                                {' '}
                                <Link
                                    href={create()}
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    Invite a person
                                </Link>
                                .
                            </>
                        )}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Properties</TableHead>
                                <TableHead>Invited by</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invitations.map((invitation) => (
                                <TableRow key={invitation.id}>
                                    <TableCell>{invitation.email}</TableCell>
                                    <TableCell>
                                        {invitation.roleLabel}
                                    </TableCell>
                                    <TableCell>
                                        {invitation.allProperties ? (
                                            <Badge variant="secondary">
                                                All properties
                                            </Badge>
                                        ) : (
                                            invitation.properties.join(', ')
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {invitation.invitedBy ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {formatDate(invitation.expiresAt)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {invitation.canRevoke && (
                                            <RevokeInvitation
                                                invitation={invitation}
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}

Invitations.layout = {
    breadcrumbs: [
        {
            title: 'Invitations',
            href: index(),
        },
    ],
};

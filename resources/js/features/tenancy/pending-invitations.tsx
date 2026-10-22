import { Form } from '@inertiajs/react';
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
import { EmptyState } from '@/components/ui/empty-state';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { destroy } from '@/routes/invitations';

export type PendingInvitation = {
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

function RevokeInvitation({ invitation }: { invitation: PendingInvitation }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Revoke invitation for ${invitation.email}`}
                >
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
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                Revoke invitation
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function PendingInvitations({
    invitations,
}: {
    invitations: PendingInvitation[];
}) {
    if (invitations.length === 0) {
        return <EmptyState message="No pending invitations." />;
    }

    return (
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
                        <TableCell>{invitation.roleLabel}</TableCell>
                        <TableCell>
                            {invitation.allProperties ? (
                                <Badge variant="secondary">
                                    All properties
                                </Badge>
                            ) : (
                                invitation.properties.join(', ')
                            )}
                        </TableCell>
                        <TableCell>{invitation.invitedBy ?? '—'}</TableCell>
                        <TableCell>
                            {formatDate(invitation.expiresAt)}
                        </TableCell>
                        <TableCell className="text-right">
                            {invitation.canRevoke && (
                                <RevokeInvitation invitation={invitation} />
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

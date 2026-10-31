import { Form, Head, Link } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard, login, logout, register } from '@/routes';
import { accept } from '@/routes/invitations';
import { formatDate } from '@/features/platform/format';

type Props = {
    invitation: {
        organization: string;
        role: string;
        email: string;
        invitedBy: string | null;
        expiresAt: string;
    };
    refusal: string | null;
    signedInAs: string | null;
    link: { organization: string; token: string };
};

export default function Invitation({
    invitation,
    refusal,
    signedInAs,
    link,
}: Props) {
    return (
        <>
            <Head title={`Join ${invitation.organization}`} />

            <div className="flex flex-col gap-6">
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-xl border bg-card p-4 text-sm">
                    <dt className="text-muted-foreground">Organization</dt>
                    <dd className="font-medium">{invitation.organization}</dd>
                    <dt className="text-muted-foreground">Role</dt>
                    <dd>{invitation.role}</dd>
                    <dt className="text-muted-foreground">Sent to</dt>
                    <dd className="break-all">{invitation.email}</dd>
                    {invitation.invitedBy && (
                        <>
                            <dt className="text-muted-foreground">
                                Invited by
                            </dt>
                            <dd>{invitation.invitedBy}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Expires</dt>
                    <dd className="tabular-nums">
                        {formatDate(invitation.expiresAt)}
                    </dd>
                </dl>

                {refusal ? (
                    <>
                        <Alert variant="destructive">
                            <CircleAlert aria-hidden />
                            <AlertDescription>{refusal}</AlertDescription>
                        </Alert>
                        {signedInAs && (
                            <p className="text-center text-sm text-muted-foreground">
                                Signed in as {signedInAs}.{' '}
                                <TextLink href={dashboard()}>
                                    Go to your dashboard
                                </TextLink>{' '}
                                or <TextLink href={logout()}>log out</TextLink>.
                            </p>
                        )}
                    </>
                ) : signedInAs ? (
                    <Form
                        {...accept.form([link.organization, link.token])}
                        disableWhileProcessing
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Accept and join {invitation.organization}
                            </Button>
                        )}
                    </Form>
                ) : (
                    <div className="flex flex-col gap-3">
                        <p className="text-center text-sm text-muted-foreground">
                            Sign in or create an account with{' '}
                            <span className="font-medium text-foreground">
                                {invitation.email}
                            </span>{' '}
                            to accept.
                        </p>
                        <Button asChild className="w-full">
                            <Link href={login()}>Sign in</Link>
                        </Button>
                        <Button asChild variant="secondary" className="w-full">
                            <Link href={register()}>Create an account</Link>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Invitation.layout = {
    title: "You're invited",
    description: 'Join an organization on Karafuu.',
};

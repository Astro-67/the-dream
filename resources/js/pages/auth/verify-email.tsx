// Components
import { Form, Head } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({
    status,
    email,
}: {
    status?: string;
    email?: string;
}) {
    return (
        <>
            <Head title="Email verification" />
            <Mail className="mb-6 size-10 text-muted-foreground" aria-hidden />

            {status === 'verification-link-sent' && (
                <div
                    role="status"
                    className="mb-4 text-center text-sm font-medium text-status-success"
                >
                    A new verification link has been sent to your email address.
                </div>
            )}

            <div className="mb-6 space-y-4 text-sm text-muted-foreground">
                {email && (
                    <p>
                        We sent a link to{' '}
                        <span className="font-medium break-all text-foreground">
                            {email}
                        </span>
                        .
                    </p>
                )}
                <p>
                    Open it to confirm your email before setting up your
                    organization. Check your spam folder if it hasn't arrived
                    within a few minutes.
                </p>
            </div>

            <Form {...send.form()} disableWhileProcessing className="space-y-6">
                {({ processing, errors }) => (
                    <>
                        <Button
                            disabled={processing}
                            variant="outline"
                            className="w-full"
                        >
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>
                        <InputError
                            role="alert"
                            message={Object.values(errors)[0]}
                        />

                        <TextLink
                            href={logout()}
                            as="button"
                            className="mx-auto block text-sm"
                        >
                            Wrong email? Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Check your inbox.',
    description: 'Verify your email address to continue.',
};

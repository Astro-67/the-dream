// Components
import { Form, Head } from '@inertiajs/react';
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

            {status === 'verification-link-sent' && (
                <div
                    role="status"
                    className="mb-4 text-center text-sm font-medium text-status-success"
                >
                    A new verification link has been sent to your email address.
                </div>
            )}

            <div className="space-y-2 text-center text-sm text-muted-foreground">
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
                    Open it to continue to your hotel setup. Check your spam
                    folder if it hasn't arrived within a few minutes.
                </p>
            </div>

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing, errors }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
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
    title: 'Check your email',
    description: 'Verify your email address to continue.',
};

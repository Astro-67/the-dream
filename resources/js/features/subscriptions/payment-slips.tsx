import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type PaymentSlip = {
    id: string;
    name: string;
    reference: string | null;
    url: string;
};

export function PaymentSlips({
    slips,
    uploadUrl,
}: {
    slips: PaymentSlip[];
    uploadUrl?: string | null;
}) {
    return (
        <div className="space-y-4">
            {slips.length === 0 ? (
                <EmptyState message="No payment slips uploaded." />
            ) : (
                <ul className="space-y-2 text-sm">
                    {slips.map((slip) => (
                        <li key={slip.id} className="space-y-1">
                            <a
                                className="break-all text-primary underline underline-offset-4"
                                href={slip.url}
                            >
                                {slip.name}
                            </a>
                            {slip.reference && (
                                <div>
                                    <Identifier value={slip.reference} />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {uploadUrl && (
                <Form
                    action={uploadUrl}
                    method="post"
                    encType="multipart/form-data"
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ processing, errors, progress }) => {
                        const fileError = Object.entries(errors)
                            .filter(
                                ([key]) =>
                                    key === 'files' || key.startsWith('files.'),
                            )
                            .map(([, message]) => message)
                            .join(' ');

                        return (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Uploading proof of payment does not mark the
                                    invoice paid. Thedream verifies the payment
                                    separately.
                                </p>
                                <div className="space-y-2">
                                    <Label htmlFor="payment-slips">
                                        Payment slips
                                    </Label>
                                    <Input
                                        id="payment-slips"
                                        name="files[]"
                                        type="file"
                                        multiple
                                        required
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                        aria-describedby="payment-slip-limits payment-slip-error"
                                        aria-invalid={!!fileError}
                                        disabled={processing}
                                    />
                                    <p
                                        id="payment-slip-limits"
                                        className="text-sm text-muted-foreground"
                                    >
                                        PDF, JPG or PNG, up to 5 MB each.
                                    </p>
                                    <InputError
                                        id="payment-slip-error"
                                        message={fileError}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="payment-reference">
                                        Transaction reference (optional)
                                    </Label>
                                    <Input
                                        id="payment-reference"
                                        name="reference"
                                        maxLength={255}
                                        disabled={processing}
                                        aria-invalid={!!errors.reference}
                                        aria-describedby="payment-reference-error"
                                    />
                                    <InputError
                                        id="payment-reference-error"
                                        message={errors.reference}
                                    />
                                </div>
                                {progress && (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Uploading {progress.percentage}%
                                    </p>
                                )}
                                <Button disabled={processing}>
                                    <span className="inline-flex size-4 items-center justify-center">
                                        {processing && <Spinner />}
                                    </span>
                                    Upload payment slips
                                </Button>
                            </>
                        );
                    }}
                </Form>
            )}
        </div>
    );
}

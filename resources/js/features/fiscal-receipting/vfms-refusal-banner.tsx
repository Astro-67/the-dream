import { Form } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

export type VfmsRefusal = {
    message: string;
    refileUrl: string | null;
};

export function VfmsRefusalBanner({
    refusal,
}: {
    refusal: VfmsRefusal | null;
}) {
    if (!refusal) return null;

    return (
        <section className="space-y-3" aria-label="ZRA filing needs attention">
            <Alert className="bg-status-error-tint text-status-error">
                <AlertCircle />
                <AlertTitle>Not filed with ZRA</AlertTitle>
                <AlertDescription className="text-status-error">
                    {refusal.message}
                </AlertDescription>
            </Alert>
            {refusal.refileUrl && (
                <Form
                    action={refusal.refileUrl}
                    method="post"
                    className="max-w-[720px] space-y-3"
                    resetOnSuccess
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <p className="text-sm text-muted-foreground">
                                This sends corrected stay details to ZRA. Only a
                                confirmed rejection can be re-filed; an unknown
                                outcome needs reconciliation.
                            </p>
                            <div className="space-y-2">
                                <Label htmlFor="vfms-refile-reason">
                                    Reason for re-filing
                                </Label>
                                <Input
                                    id="vfms-refile-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    aria-describedby="vfms-refile-error"
                                    aria-invalid={Boolean(errors.reason)}
                                />
                                <InputError
                                    id="vfms-refile-error"
                                    message={errors.reason}
                                />
                            </div>
                            <InputError message={errors.vfms} />
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                {processing
                                    ? 'Queuing filing...'
                                    : 'Re-file with ZRA'}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

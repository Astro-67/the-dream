import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { AdultGuestFields } from '@/features/guests/adult-guest-fields';
import { checkIn } from '@/routes/reservations';
import { store as storeGuest } from '@/routes/reservations/guests';

/**
 * Check-in. While adults are unregistered (missingGuests), the button opens a
 * dialog to add them one by one; adding the last one checks the stay in.
 */
export function CheckInForm({
    number,
    missingGuests = 0,
    canAddGuests = false,
}: {
    number: string;
    missingGuests?: number;
    canAddGuests?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [checkInErrors, setCheckInErrors] = useState<string[]>([]);
    const bag = `check-in-${number}`;
    if (missingGuests > 0 && canAddGuests)
        return (
            <>
                <Button onClick={() => setOpen(true)}>Check in</Button>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-h-[90dvh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>
                                Register guests to check in
                            </DialogTitle>
                            <DialogDescription>
                                {missingGuests === 1
                                    ? '1 adult on this booking is not registered yet.'
                                    : `${missingGuests} adults on this booking are not registered yet.`}{' '}
                                ZRA registers every adult by ID. Children do not
                                need to be added.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...storeGuest.form(number)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            disableWhileProcessing
                            onSuccess={() => {
                                if (missingGuests > 1) return;
                                router.post(
                                    checkIn.url(number),
                                    {},
                                    {
                                        errorBag: bag,
                                        preserveScroll: true,
                                        onSuccess: () => setOpen(false),
                                        onError: (errors) =>
                                            setCheckInErrors(
                                                Object.values(errors),
                                            ),
                                    },
                                );
                            }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <PropertyIntent />
                                    <AdultGuestFields
                                        id="check-in-guest"
                                        required
                                        names={{
                                            name: 'name',
                                            // The document carries the country VFMS files.
                                            nationality:
                                                'documents[0][nationality]',
                                            document_type: 'documents[0][type]',
                                            document_number:
                                                'documents[0][number]',
                                        }}
                                        errors={{
                                            name: errors.name,
                                            nationality:
                                                errors[
                                                    'documents.0.nationality'
                                                ],
                                            document_type:
                                                errors['documents.0.type'],
                                            document_number:
                                                errors['documents.0.number'],
                                        }}
                                    />
                                    {[
                                        errors._property,
                                        errors.reservation,
                                        errors.documents,
                                        ...checkInErrors,
                                    ].map(
                                        (error) =>
                                            error && (
                                                <InputError
                                                    key={error}
                                                    message={error}
                                                />
                                            ),
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {missingGuests > 1
                                            ? 'Add guest'
                                            : 'Add guest and check in'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </>
        );
    return (
        <Form
            {...checkIn.form(number)}
            disableWhileProcessing
            errorBag={bag}
            options={{ preserveScroll: true }}
            className="flex flex-col items-start gap-1"
        >
            {({ errors }) => (
                <>
                    <PropertyIntent />
                    <Button type="submit">Check in</Button>
                    {Object.values(errors).map((error) => (
                        <InputError key={error} message={error} />
                    ))}
                </>
            )}
        </Form>
    );
}

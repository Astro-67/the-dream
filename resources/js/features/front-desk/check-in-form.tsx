import { Form, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { IdCard, PencilLine, ShieldCheck } from 'lucide-react';
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
import { checkIn, show as showReservation } from '@/routes/reservations';
import { edit as editGuest, show as showGuest } from '@/routes/guests';
import { store as storeGuest } from '@/routes/reservations/guests';

type IncompleteGuest = {
    id: string;
    name: string;
    canView: boolean;
    canEdit: boolean;
};

function IncompleteGuestList({ guests }: { guests: IncompleteGuest[] }) {
    if (guests.length === 0) return null;

    return (
        <section className="space-y-3 border border-status-warning/25 bg-status-warning-tint/50 p-4">
            <div className="flex items-start gap-2">
                <IdCard
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-status-warning"
                />
                <div>
                    <h3 className="text-sm font-semibold">
                        Guest details need attention
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Complete the identity details required by this hotel
                        before check-in. You can also update phone and email on
                        each guest profile.
                    </p>
                </div>
            </div>
            <ul className="divide-y divide-border border-y border-border">
                {guests.map((guest) => (
                    <li
                        key={guest.id}
                        className="flex flex-wrap items-center justify-between gap-2 py-2.5"
                    >
                        {guest.canView ? (
                            <Link
                                href={showGuest(guest.id)}
                                className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            >
                                {guest.name}
                            </Link>
                        ) : (
                            <span className="font-medium">{guest.name}</span>
                        )}
                        {guest.canEdit ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={editGuest(guest.id)}>
                                    <PencilLine aria-hidden="true" />
                                    Complete profile
                                </Link>
                            </Button>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Ask a guest-registration teammate to update it
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Check-in opens registration help when required adult or identity details need attention. */
export function CheckInForm({
    number,
    guestProfileId = null,
    missingGuests = 0,
    canAddGuests = false,
    registrationBlocked = false,
    incompleteGuests = [],
}: {
    number: string;
    guestProfileId?: string | null;
    missingGuests?: number;
    canAddGuests?: boolean;
    registrationBlocked?: boolean;
    incompleteGuests?: IncompleteGuest[];
}) {
    const [open, setOpen] = useState(false);
    const [checkInErrors, setCheckInErrors] = useState<string[]>([]);
    const bag = `check-in-${number}`;
    const requirementsNeeded =
        registrationBlocked || missingGuests > 0 || incompleteGuests.length > 0;

    if (missingGuests > 0 && canAddGuests) {
        return (
            <>
                <Button onClick={() => setOpen(true)}>Check in</Button>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>
                                Complete guest registration
                            </DialogTitle>
                            <DialogDescription>
                                {missingGuests === 1
                                    ? 'One adult on this booking still needs a guest profile.'
                                    : `${missingGuests} adults on this booking still need guest profiles.`}{' '}
                                Every adult must have the ID details required by
                                this hotel before check-in. Children do not need
                                to be registered.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <IncompleteGuestList guests={incompleteGuests} />
                            <Form
                                {...storeGuest.form(number)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                disableWhileProcessing
                                onSuccess={() => {
                                    if (
                                        missingGuests > 1 ||
                                        incompleteGuests.length > 0
                                    )
                                        return;
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
                                className="space-y-4 border-t border-border pt-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div>
                                            <h3 className="font-semibold">
                                                Register the next adult
                                            </h3>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Phone and email are optional and
                                                can also be added later.
                                            </p>
                                        </div>
                                        <PropertyIntent />
                                        <AdultGuestFields
                                            id="check-in-guest"
                                            required
                                            names={{
                                                name: 'name',
                                                nationality:
                                                    'documents[0][nationality]',
                                                document_type:
                                                    'documents[0][type]',
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
                                                    errors[
                                                        'documents.0.number'
                                                    ],
                                            }}
                                            contactNames={{
                                                phone: 'phone',
                                                email: 'email',
                                            }}
                                            contactErrors={{
                                                phone: errors.phone,
                                                email: errors.email,
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
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {missingGuests > 1
                                                ? 'Save guest and continue'
                                                : incompleteGuests.length > 0
                                                  ? 'Save guest'
                                                  : 'Save guest and check in'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    </DialogContent>
                </Dialog>
            </>
        );
    }

    if (requirementsNeeded) {
        return (
            <>
                <Button onClick={() => setOpen(true)}>Check in</Button>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>
                                Complete details before check-in
                            </DialogTitle>
                            <DialogDescription>
                                {incompleteGuests.length > 0
                                    ? 'Some registered adults are missing identity details required by this hotel.'
                                    : missingGuests > 0
                                      ? `${missingGuests} adult${missingGuests === 1 ? '' : 's'} still need registration.`
                                      : 'Guest registration details need attention before this stay can be checked in.'}{' '}
                                Phone and email can be updated on the guest
                                profile too.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <IncompleteGuestList guests={incompleteGuests} />
                            {missingGuests > 0 && (
                                <p className="flex items-start gap-2 border border-status-info/20 bg-status-info-tint p-3 text-sm text-status-info">
                                    <ShieldCheck
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    <span>
                                        A teammate with guest-registration and
                                        identity permission must register the
                                        remaining adults before check-in.
                                    </span>
                                </p>
                            )}
                            {registrationBlocked &&
                                incompleteGuests.length === 0 &&
                                missingGuests === 0 && (
                                    <p className="flex items-start gap-2 border border-status-info/20 bg-status-info-tint p-3 text-sm text-status-info">
                                        <ShieldCheck
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        <span>
                                            Some guest details are restricted.
                                            Ask a teammate with identity
                                            permission to review this booking.
                                        </span>
                                    </p>
                                )}
                        </div>
                    </DialogContent>
                </Dialog>
            </>
        );
    }

    return (
        <>
            <Form
                {...checkIn.form(number)}
                disableWhileProcessing
                errorBag={bag}
                options={{ preserveScroll: true }}
                onError={(errors) => {
                    setCheckInErrors(Object.values(errors));
                    setOpen(true);
                }}
                className="flex flex-col items-start gap-1"
            >
                {() => (
                    <>
                        <PropertyIntent />
                        <Button type="submit">Check in</Button>
                    </>
                )}
            </Form>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Complete check-in requirements
                        </DialogTitle>
                        <DialogDescription>
                            This reservation needs attention before check-in.
                            Review its room and guest details, then try again.
                        </DialogDescription>
                    </DialogHeader>
                    {checkInErrors.length > 0 && (
                        <div className="space-y-2 border border-status-warning/25 bg-status-warning-tint/50 p-3">
                            {checkInErrors.map((error) => (
                                <p
                                    className="text-sm text-status-warning"
                                    key={error}
                                >
                                    {error}
                                </p>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {guestProfileId && (
                            <Button variant="outline" asChild>
                                <Link href={showGuest(guestProfileId)}>
                                    Open guest profile
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={showReservation(number)}>
                                Review reservation
                            </Link>
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

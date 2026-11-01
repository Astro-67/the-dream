import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { Table, TableBody, TableRow, TableCell } from '@/components/ui/table';
import type { Paginated } from '@/types';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    PropertyIntent,
    usePropertyIntent,
} from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import { IdentityFields } from '@/features/guests/identity-documents';
import type { Contact } from '@/features/guests/contact-pages';
import { index, show } from '@/routes/reservations';

type Match = Pick<Contact, 'id' | 'name' | 'email' | 'phone'>;

export default function Guests({
    reservationNumber,
    expectedGuests,
    guests,
    canAttach,
    inHouse,
    canViewIdentity,
    leadGuestId,
    departedGuestIds,
    canRecordDeparture,
}: {
    reservationNumber: string;
    expectedGuests: number;
    guests: Paginated<Contact>;
    canAttach: boolean;
    inHouse: boolean;
    canViewIdentity: boolean;
    leadGuestId: string | null;
    departedGuestIds: string[];
    canRecordDeparture: boolean;
}) {
    const base = `/reservations/${encodeURIComponent(reservationNumber)}`;
    const action = inHouse ? `${base}/add-guest` : `${base}/guests`;
    const intent = usePropertyIntent();
    const [matches, setMatches] = useState<Match[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [details, setDetails] = useState({
        name: '',
        email: '',
        phone: '',
        document_number: '',
    });

    async function findMatches() {
        setSearching(true);
        setSearchError('');
        setMatches(null);
        try {
            const token = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice('XSRF-TOKEN='.length);
            const response = await fetch('/guests/matches', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                },
                body: JSON.stringify({ ...intent, ...details }),
            });
            if (!response.ok)
                throw new Error(
                    'Matching failed. Check the name and contact details, then try again.',
                );
            const result: { matches: Match[] } = await response.json();
            setMatches(result.matches);
        } catch {
            setSearchError(
                'Matching failed. Check the name and contact details, then try again.',
            );
        } finally {
            setSearching(false);
        }
    }

    return (
        <>
            <Head title={`Guests · ${reservationNumber}`} />
            <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold">
                        Guests · <Identifier value={reservationNumber} />
                    </h1>
                </header>
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Staying guests</h2>
                    {!inHouse && (
                        <p className="text-sm text-muted-foreground">
                            {guests.total} guests added · {expectedGuests}{' '}
                            adults booked. Every adult must be added with ID
                            before check-in; children do not need to be added.
                        </p>
                    )}
                    {guests.data.length === 0 ? (
                        <EmptyState message="No guests attached yet." />
                    ) : (
                        <Table>
                            <TableBody>
                                {guests.data.map((guest) => (
                                    <TableRow key={guest.id}>
                                        <TableCell>
                                            <Link
                                                className="text-primary underline underline-offset-4"
                                                href={`/guests/${guest.id}`}
                                            >
                                                {guest.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {guest.email ??
                                                guest.phone ??
                                                'No contact details'}
                                        </TableCell>
                                        <TableCell>
                                            <GuestStayStatus
                                                base={base}
                                                guest={guest}
                                                isLead={
                                                    guest.id === leadGuestId
                                                }
                                                departed={departedGuestIds.includes(
                                                    guest.id,
                                                )}
                                                canRecordDeparture={
                                                    canRecordDeparture
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={guests} />
                </section>
                {canAttach && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">Add a guest</h2>
                        <p className="text-sm text-muted-foreground">
                            Check for a returning guest, then choose to link a
                            record or create a new one. Nothing is linked or
                            merged automatically.
                            {inHouse &&
                                ' This stay is in house, so the guest must meet the registration rules.'}
                        </p>
                        <Form
                            action={action}
                            method="post"
                            disableWhileProcessing
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <PropertyIntent />
                                    <InputError message={errors._property} />
                                    <InputError message={errors.guest_id} />
                                    <InputError message={errors.reservation} />
                                    <InputError message={errors.guests} />
                                    {(['name', 'email', 'phone'] as const).map(
                                        (field) => (
                                            <div
                                                className="space-y-2"
                                                key={field}
                                            >
                                                <Label htmlFor={field}>
                                                    {field === 'name'
                                                        ? 'Name'
                                                        : field === 'email'
                                                          ? 'Email'
                                                          : 'Phone'}
                                                </Label>
                                                <Input
                                                    id={field}
                                                    name={field}
                                                    value={details[field]}
                                                    required={field === 'name'}
                                                    type={
                                                        field === 'email'
                                                            ? 'email'
                                                            : field === 'phone'
                                                              ? 'tel'
                                                              : 'text'
                                                    }
                                                    maxLength={
                                                        field === 'phone'
                                                            ? 50
                                                            : 255
                                                    }
                                                    onChange={(event) => {
                                                        setDetails({
                                                            ...details,
                                                            [field]:
                                                                event.target
                                                                    .value,
                                                        });
                                                        setMatches(null);
                                                    }}
                                                    aria-invalid={Boolean(
                                                        errors[field],
                                                    )}
                                                    aria-describedby={
                                                        errors[field]
                                                            ? `${field}-error`
                                                            : undefined
                                                    }
                                                />
                                                <InputError
                                                    id={`${field}-error`}
                                                    message={errors[field]}
                                                />
                                            </div>
                                        ),
                                    )}
                                    <div className="space-y-2">
                                        <Label htmlFor="document_number">
                                            Document number for matching only
                                        </Label>
                                        <Input
                                            id="document_number"
                                            value={details.document_number}
                                            autoComplete="off"
                                            maxLength={255}
                                            onChange={(event) => {
                                                setDetails({
                                                    ...details,
                                                    document_number:
                                                        event.target.value,
                                                });
                                                setMatches(null);
                                            }}
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Document numbers are never shown in
                                            match results.
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            searching || !details.name.trim()
                                        }
                                        onClick={findMatches}
                                    >
                                        {searching && <Spinner />}Find existing
                                        guests
                                    </Button>
                                    <div role="status" aria-live="polite">
                                        <InputError message={searchError} />
                                        {matches && (
                                            <p>
                                                {matches.length === 0
                                                    ? 'No matching guests. You can create a new guest.'
                                                    : `${matches.length} matching guests. Choose below to link one, or create a new guest.`}
                                            </p>
                                        )}
                                    </div>
                                    {canViewIdentity && (
                                        <IdentityFields errors={errors} />
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}Create new
                                        guest and attach
                                    </Button>
                                </>
                            )}
                        </Form>
                        {matches && matches.length > 0 && (
                            <ul className="space-y-3">
                                {matches.map((match) => (
                                    <li
                                        key={match.id}
                                        className="space-y-2 rounded-xl border p-4"
                                    >
                                        <p>
                                            {match.name} ·{' '}
                                            {match.email ?? 'No email'} ·{' '}
                                            {match.phone ?? 'No phone'}
                                        </p>
                                        <Form
                                            action={action}
                                            method="post"
                                            disableWhileProcessing
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <PropertyIntent />
                                                    <input
                                                        type="hidden"
                                                        name="guest_id"
                                                        value={match.id}
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.guest_id ??
                                                            errors.guests ??
                                                            errors.reservation ??
                                                            errors._property
                                                        }
                                                    />
                                                    <Button
                                                        variant="outline"
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        Link {match.name}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Guests.layout = ({ reservationNumber }: { reservationNumber: string }) => ({
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        { title: reservationNumber, href: show(reservationNumber) },
        { title: 'Guests', href: '#' },
    ],
});

/** Lead guest and departed marks, or the Guest leaves action for anyone else still in-house. */
function GuestStayStatus({
    base,
    guest,
    isLead,
    departed,
    canRecordDeparture,
}: {
    base: string;
    guest: Contact;
    isLead: boolean;
    departed: boolean;
    canRecordDeparture: boolean;
}) {
    if (isLead) return <StatusChip tone="info">Lead guest</StatusChip>;
    if (departed) return <StatusChip tone="unavailable">Left</StatusChip>;
    if (!canRecordDeparture) return null;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" className="min-h-11">
                    Guest leaves
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{guest.name} leaves?</DialogTitle>
                    <DialogDescription>
                        The stay and the room stay in-house for the other
                        guests. At a Zanzibar property this sends a check-out
                        for this guest to VFMS, which stops their infrastructure
                        levy. It cannot be undone here.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={`${base}/guest-leaves`}
                    method="post"
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <input
                                type="hidden"
                                name="guest_id"
                                value={guest.id}
                            />
                            <InputError
                                message={
                                    errors.guest_id ??
                                    errors.reservation ??
                                    errors._property
                                }
                            />
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    className="min-h-11"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Record that {guest.name} left
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    PropertyIntent,
    usePropertyIntent,
} from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { IdentityFields } from '@/features/guests/identity-documents';
import type { Contact } from '@/features/guests/contact-pages';

type Match = Pick<Contact, 'id' | 'name' | 'email' | 'phone'>;

export default function Guests({
    reservationNumber,
    guests,
    canAttach,
    canViewIdentity,
}: {
    reservationNumber: string;
    guests: Contact[];
    canAttach: boolean;
    canViewIdentity: boolean;
}) {
    const base = `/reservations/${encodeURIComponent(reservationNumber)}`;
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
                        Guests · {reservationNumber}
                    </h1>
                    <Link
                        className="text-primary underline underline-offset-4"
                        href={base}
                    >
                        Back to reservation
                    </Link>
                </header>
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Staying guests</h2>
                    <p className="text-sm text-muted-foreground">
                        The booker is separate and is not added as a staying
                        guest automatically.
                    </p>
                    {guests.length === 0 ? (
                        <EmptyState message="No guests attached yet." />
                    ) : (
                        <ul className="space-y-2">
                            {guests.map((guest) => (
                                <li key={guest.id}>
                                    <Link
                                        className="text-primary underline underline-offset-4"
                                        href={`/guests/${guest.id}`}
                                    >
                                        {guest.name}
                                    </Link>{' '}
                                    ·{' '}
                                    {guest.email ??
                                        guest.phone ??
                                        'No contact details'}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                {canAttach && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">Add a guest</h2>
                        <p className="text-sm text-muted-foreground">
                            Check for a returning guest, then choose to link a
                            record or create a new one. Nothing is linked or
                            merged automatically.
                        </p>
                        <Form
                            action={`${base}/guests`}
                            method="post"
                            disableWhileProcessing
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <PropertyIntent />
                                    <InputError message={errors._property} />
                                    <InputError message={errors.guest_id} />
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
                                            action={`${base}/guests`}
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

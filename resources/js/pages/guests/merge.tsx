import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Pagination } from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Paginated } from '@/types';
import { Spinner } from '@/components/ui/spinner';
import { show } from '@/routes/guests';

type Candidate = {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
};

export default function Merge({
    guest,
    candidates,
}: {
    guest: { id: string; name: string };
    candidates: Paginated<Candidate>;
}) {
    return (
        <>
            <Head title={`Merge duplicate guests into ${guest.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold">
                        Merge duplicate guests
                    </h1>
                    <p className="text-muted-foreground">
                        Keep {guest.name}. Choose the duplicate to remove.
                    </p>
                </header>
                {candidates.data.length === 0 ? (
                    <EmptyState
                        message="No other guests at this property."
                        action={
                            <Button variant="outline" asChild>
                                <Link href={`/guests/${guest.id}`}>
                                    Back to guest
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Phone</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {candidates.data.map((candidate) => (
                                    <TableRow key={candidate.id}>
                                        <TableCell>{candidate.name}</TableCell>
                                        <TableCell>
                                            {candidate.email ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {candidate.phone ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        Merge {candidate.name}
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Merge{' '}
                                                            {candidate.name}{' '}
                                                            into {guest.name}?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Reservations and
                                                            identity documents
                                                            will move to{' '}
                                                            {guest.name}. The
                                                            duplicate guest will
                                                            be removed. The
                                                            surviving profile’s
                                                            contact details,
                                                            preferences and
                                                            notes remain
                                                            unchanged. This
                                                            cannot be undone.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <Form
                                                        action={`/guests/${guest.id}/merge`}
                                                        method="post"
                                                    >
                                                        {({
                                                            errors,
                                                            processing,
                                                        }) => (
                                                            <div className="space-y-4">
                                                                <PropertyIntent />
                                                                <input
                                                                    type="hidden"
                                                                    name="duplicate_id"
                                                                    value={
                                                                        candidate.id
                                                                    }
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors.duplicate_id
                                                                    }
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors._property
                                                                    }
                                                                />
                                                                <DialogFooter>
                                                                    <DialogClose
                                                                        asChild
                                                                    >
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            <span className="size-4">
                                                                                {processing && (
                                                                                    <Spinner />
                                                                                )}
                                                                            </span>
                                                                            Keep
                                                                            both
                                                                            guests
                                                                        </Button>
                                                                    </DialogClose>
                                                                    <Button
                                                                        type="submit"
                                                                        variant="destructive"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        {processing && (
                                                                            <Spinner />
                                                                        )}
                                                                        Merge
                                                                        guests
                                                                    </Button>
                                                                </DialogFooter>
                                                            </div>
                                                        )}
                                                    </Form>
                                                </DialogContent>
                                            </Dialog>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={candidates} />
                    </div>
                )}
            </div>
        </>
    );
}
Merge.layout = ({ guest }: { guest: { id: string; name: string } }) => ({
    breadcrumbs: [
        { title: 'Guests', href: '/guests' },
        { title: guest.name, href: show(guest.id) },
        { title: 'Merge duplicate guests', href: '/guests' },
    ],
});

import { CountrySelect } from '@/components/shared/country-select';
import { Form, Head, Link } from '@inertiajs/react';
import {
    FileText,
    Globe,
    Heart,
    Mail,
    Pencil,
    Phone,
    UserRound,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import type { Paginated } from '@/types';

export type Contact = {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    nationality: string | null;
    preferences: string | null;
    notes: string | null;
};
const fields = [
    ['name', 'Name'],
    ['email', 'Email'],
    ['phone', 'Phone'],
    ['nationality', 'Country'],
    ['preferences', 'Preferences'],
    ['notes', 'Notes'],
] as const;
const fieldIcons: Record<(typeof fields)[number][0], LucideIcon> = {
    name: UserRound,
    email: Mail,
    phone: Phone,
    nationality: Globe,
    preferences: Heart,
    notes: FileText,
};
export function ContactIndex({
    records,
    canManage,
    base,
    title,
}: {
    records: Paginated<Contact>;
    canManage: boolean;
    base: string;
    title: string;
}) {
    return (
        <>
            <Head title={title} />
            <div className="flex min-w-0 flex-col gap-6">
                <header className="flex flex-wrap items-center justify-between gap-4 border-b pb-6">
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    {canManage && (
                        <Button asChild>
                            <Link href={`${base}/create`}>
                                Add {title.toLowerCase().slice(0, -1)}
                            </Link>
                        </Button>
                    )}
                </header>
                {records.data.length === 0 ? (
                    <EmptyState
                        message={
                            canManage
                                ? `No ${title.toLowerCase()} yet. Add a record for this property.`
                                : `No ${title.toLowerCase()} recorded for this property.`
                        }
                        action={
                            canManage && (
                                <Button asChild>
                                    <Link href={`${base}/create`}>
                                        Add a record
                                    </Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Phone</TableHead>
                                    <TableHead>Country</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {records.data.map((record) => (
                                    <TableRow key={record.id}>
                                        <TableCell>
                                            <Link
                                                className="inline-flex min-h-11 items-center text-primary underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                href={`${base}/${record.id}`}
                                            >
                                                {record.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {record.email ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {record.phone ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {record.nationality ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={records} />
                    </>
                )}
            </div>
        </>
    );
}
export function ContactForm({
    record,
    base,
    title,
    children,
    identityEditable = false,
}: {
    record?: Contact;
    base: string;
    title: string;
    children?: (errors: Record<string, string>) => React.ReactNode;
    identityEditable?: boolean;
}) {
    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex w-full max-w-[720px] min-w-0 flex-col gap-6">
                <h1 className="border-b pb-6 text-2xl font-semibold">
                    {title}
                </h1>
                <Form
                    transform={(data) =>
                        identityEditable ? { documents: [], ...data } : data
                    }
                    action={record ? `${base}/${record.id}` : base}
                    method={record ? 'put' : 'post'}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <fieldset className="grid gap-6 sm:grid-cols-2">
                                <legend className="sr-only">
                                    Contact details
                                </legend>
                                {fields.map(([field, label]) => (
                                    <div
                                        key={field}
                                        className={
                                            field === 'name' ||
                                            field === 'preferences' ||
                                            field === 'notes'
                                                ? 'space-y-2 sm:col-span-2'
                                                : 'space-y-2'
                                        }
                                    >
                                        <Label htmlFor={field}>{label}</Label>
                                        {field === 'nationality' ? (
                                            <CountrySelect
                                                id={field}
                                                name={field}
                                                defaultValue={
                                                    record?.[field] ?? ''
                                                }
                                                aria-invalid={Boolean(
                                                    errors[field],
                                                )}
                                                aria-describedby={
                                                    errors[field]
                                                        ? `${field}-error`
                                                        : undefined
                                                }
                                            />
                                        ) : field === 'preferences' ||
                                          field === 'notes' ? (
                                            <textarea
                                                rows={3}
                                                className="w-full border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 aria-invalid:border-destructive"
                                                id={field}
                                                name={field}
                                                defaultValue={
                                                    record?.[field] ?? ''
                                                }
                                                maxLength={5000}
                                                aria-invalid={Boolean(
                                                    errors[field],
                                                )}
                                                aria-describedby={
                                                    errors[field]
                                                        ? `${field}-error`
                                                        : undefined
                                                }
                                            />
                                        ) : (
                                            <Input
                                                id={field}
                                                name={field}
                                                type={
                                                    field === 'email'
                                                        ? 'email'
                                                        : field === 'phone'
                                                          ? 'tel'
                                                          : 'text'
                                                }
                                                defaultValue={
                                                    record?.[field] ?? ''
                                                }
                                                required={field === 'name'}
                                                maxLength={
                                                    field === 'phone' ? 50 : 255
                                                }
                                                aria-invalid={Boolean(
                                                    errors[field],
                                                )}
                                                aria-describedby={
                                                    errors[field]
                                                        ? `${field}-error`
                                                        : undefined
                                                }
                                            />
                                        )}
                                        <InputError
                                            id={`${field}-error`}
                                            message={errors[field]}
                                        />
                                    </div>
                                ))}
                            </fieldset>
                            {children?.(errors)}
                            <div className="flex flex-wrap justify-end gap-3 border-t pt-6">
                                <Button variant="ghost" asChild>
                                    <Link href={base}>Cancel</Link>
                                </Button>
                                <Button type="submit">
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
export function ContactShow({
    record,
    canManage,
    base,
    title,
    children,
    extraActions,
}: {
    record: Contact;
    canManage: boolean;
    base: string;
    title: string;
    children?: React.ReactNode;
    extraActions?: React.ReactNode;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Head title={record.name} />
            <div className="flex w-full min-w-0 flex-col gap-6">
                <header className="flex flex-wrap items-center justify-between gap-5 border border-border bg-card p-4 shadow-panel md:p-6">
                    <div className="flex min-w-0 items-center gap-4">
                        <Avatar className="size-16 border border-primary/20 bg-primary-tint">
                            <AvatarFallback className="bg-primary-tint font-heading text-xl font-semibold text-primary">
                                {getInitials(record.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 space-y-1">
                            <p className="text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                {title === 'guest'
                                    ? 'Guest profile'
                                    : 'Booker profile'}
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere md:text-3xl">
                                {record.name}
                            </h1>
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                {record.email && (
                                    <span className="inline-flex min-w-0 items-center gap-1.5 wrap-anywhere">
                                        <Mail
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                        {record.email}
                                    </span>
                                )}
                                {record.phone && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Phone
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                        {record.phone}
                                    </span>
                                )}
                                {!record.email && !record.phone && (
                                    <span>No contact details recorded</span>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {extraActions}
                        {canManage && (
                            <Button variant="outline" asChild>
                                <Link href={`${base}/${record.id}/edit`}>
                                    <Pencil aria-hidden="true" />
                                    Edit {title}
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>
                <section
                    aria-labelledby="contact-details-title"
                    className="overflow-hidden border border-border bg-card shadow-panel"
                >
                    <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                        <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <UsersRound aria-hidden="true" className="size-5" />
                        </span>
                        <div>
                            <h2
                                id="contact-details-title"
                                className="text-base font-semibold"
                            >
                                Contact details
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                {title === 'guest'
                                    ? 'Guest information and preferences'
                                    : 'Booker contact information'}
                            </p>
                        </div>
                    </div>
                    <dl className="grid min-w-0 gap-px bg-border sm:grid-cols-2">
                        {fields
                            .filter(([field]) => field !== 'name')
                            .map(([field, label]) => {
                                const Icon = fieldIcons[field];
                                const longField =
                                    field === 'preferences' ||
                                    field === 'notes';

                                return (
                                    <div
                                        key={field}
                                        className={`flex min-w-0 gap-3 bg-card p-4 md:p-5 ${longField ? 'sm:col-span-2' : ''}`}
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center border border-primary/10 bg-primary-tint text-primary">
                                            <Icon
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <dt className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 leading-relaxed wrap-anywhere whitespace-pre-wrap">
                                                {record[field] ?? '\u2014'}
                                            </dd>
                                        </div>
                                    </div>
                                );
                            })}
                    </dl>
                </section>
                {children}
            </div>
        </>
    );
}
